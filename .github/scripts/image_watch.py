#!/usr/bin/env python3
"""Email critical and high vulnerabilities in the deployed images that a fix exists for.

Scans each image digest in versions.env (what the last deploy recorded) with
Trivy, keeping only critical and high findings whose package already has a
fixed version. A finding with no fix yet is left out: nothing we do changes it,
and it would be emailed every run until Alpine or the upstream project ships one.

Each finding is emailed once. The state file holds the findings already emailed
and is rewritten to the current scan each run, so a fixed finding drops out and
is emailed again if it ever comes back. The repo and its logs are public, so the
log gives counts only; --dry-run prints everything.

Production is arm64, so each image is scanned as linux/arm64.
"""
import argparse
import datetime as dt
import json
import os
import subprocess
import sys
from collections import defaultdict
from email.message import EmailMessage
from pathlib import Path

from cve_watch import send

REGISTRY = "ghcr.io/coasterpedia/coasterpedia-web"
SEVERITIES = ("CRITICAL", "HIGH")
# Trivy's result classes: packages the OS package manager installed, and
# libraries a language package manager installed (Composer, npm, Go, Java).
OS_PACKAGES = "os-pkgs"


def deployed(versions: Path) -> dict[str, str]:
    """{image name: digest} from versions.env, e.g. MEDIAWIKI_IMAGE=sha256:…"""
    images = {}
    for line in versions.read_text().splitlines():
        name, sep, digest = line.strip().partition("=")
        if sep and name.endswith("_IMAGE") and digest.startswith("sha256:"):
            images[name.removesuffix("_IMAGE").lower()] = digest
    return images


def scan(ref: str) -> dict:
    # Output stays out of the log: stdout is captured, and --quiet drops progress.
    run = subprocess.run(
        ["trivy", "image", "--quiet", "--format", "json", "--scanners", "vuln",
         "--platform", "linux/arm64", "--ignore-unfixed", "--severity", ",".join(SEVERITIES), ref],
        capture_output=True, text=True, timeout=900)
    if run.returncode != 0:
        # Trivy's stderr names the image and the error, not findings.
        raise RuntimeError(run.stderr.strip()[-500:] or f"exit {run.returncode}")
    return json.loads(run.stdout)


def findings(report: dict) -> list[dict]:
    found = []
    for result in report.get("Results") or []:
        for vuln in result.get("Vulnerabilities") or []:
            if vuln.get("Severity") in SEVERITIES and vuln.get("FixedVersion"):
                found.append({
                    "id": vuln["VulnerabilityID"],
                    "severity": vuln["Severity"],
                    "package": vuln["PkgName"],
                    "installed": vuln.get("InstalledVersion", ""),
                    "fixed": vuln["FixedVersion"],
                    "title": vuln.get("Title", ""),
                    "url": vuln.get("PrimaryURL", ""),
                    "os": result.get("Class") == OS_PACKAGES,
                    "target": result.get("Target", ""),
                })
    return found


def key(image: str, f: dict) -> str:
    return f"{image}:{f['id']}:{f['package']}"


def todo(image: str, f: dict) -> str:
    if f["os"]:
        return ("Alpine has the fix. Run the Docker workflow: a manual run builds without the cache, "
                "so apk picks it up. If it's still here afterwards, the fix isn't in the Alpine branch "
                "the base image uses yet.")
    if image in ("mediawiki", "jobrunner"):
        return (f"From {f['target']}. If it's a Composer package, bump it in composer.json or wait "
                "for the MediaWiki release that does.")
    return f"From {f['target']}, inside the upstream {image} image. It needs that image's next release."


def build_email(new: dict[str, list[dict]], sender: str, recipient: str) -> EmailMessage:
    count = sum(len(v) for v in new.values())
    parts = []
    for image, found in sorted(new.items()):
        lines = [f"{image} ({REGISTRY}/{image})\n"]
        for f in sorted(found, key=lambda f: (f["severity"] != "CRITICAL", f["id"])):
            lines.append(f"{f['id']}  {f['severity'].lower()}  {f['package']} {f['installed']}, "
                         f"fixed in {f['fixed']}")
            lines += [f"  {f[k]}" for k in ("title", "url") if f[k]]
            lines.append(f"  {todo(image, f)}\n")
        parts.append("\n".join(lines))
    msg = EmailMessage()
    msg["Subject"] = f"[Coasterpedia] {count} fixable image vulnerabilit{'y' if count == 1 else 'ies'}"
    msg["From"] = sender
    msg["To"] = recipient
    msg.set_content(
        "Trivy found these critical or high vulnerabilities in the deployed images, and each\n"
        "has a fixed version. Each says what's left to do.\n\n"
        + ("-" * 72 + "\n\n").join(parts)
    )
    return msg


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--versions", type=Path, default=Path("versions.env"))
    parser.add_argument("--state", type=Path, default=Path(".image-watch-seen.json"))
    parser.add_argument("--report", type=Path, action="append", default=[],
                        help="IMAGE=PATH: read a saved Trivy JSON report instead of scanning (for testing)")
    parser.add_argument("--dry-run", action="store_true",
                        help="print every finding; don't send or update the state file")
    args = parser.parse_args()

    saved = dict(r.split("=", 1) for r in map(str, args.report))
    images = {name: "" for name in saved} if saved else deployed(args.versions)
    seen = json.loads(args.state.read_text()) if args.state.exists() else {}

    current, new, failed = {}, defaultdict(list), []
    for image, digest in sorted(images.items()):
        try:
            report = (json.loads(Path(saved[image]).read_text()) if image in saved
                      else scan(f"{REGISTRY}/{image}@{digest}"))
        except (RuntimeError, OSError, ValueError, subprocess.TimeoutExpired) as e:
            print(f"{image}: couldn't scan ({e})", file=sys.stderr)
            failed.append(image)
            continue
        found = findings(report)
        fresh = [f for f in found if key(image, f) not in seen]
        new[image] += fresh
        current.update({key(image, f): seen.get(key(image, f), "") for f in found})
        print(f"{image}: {len(found)} fixable critical/high, {len(fresh)} not emailed before")
    new = {image: found for image, found in new.items() if found}

    if args.dry_run:
        for image, found in new.items():
            for f in found:
                print(f"  NEW  {image}  {f['id']}  {f['severity']}  {f['package']} "
                      f"{f['installed']} -> {f['fixed']}  ({'os' if f['os'] else f['target']})")
        return 1 if failed else 0

    if new:
        send(build_email(new, "wiki@coasterpedia.net", os.environ["SECURITY_ALERT_EMAIL"]))

    # Only after the email went out, so a failed send is retried next run. Images
    # that failed to scan keep their old entries rather than re-alerting later.
    today = dt.date.today().isoformat()
    kept = {k: v for k, v in seen.items() if k.split(":", 1)[0] in failed}
    args.state.write_text(json.dumps(
        kept | {k: v or today for k, v in current.items()}, indent=1, sort_keys=True))
    # A failed scan fails the run, so GitHub's failed-workflow email says so.
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
