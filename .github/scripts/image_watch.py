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
import re
import subprocess
import sys
import textwrap
from collections import defaultdict
from dataclasses import dataclass
from email.message import EmailMessage
from pathlib import Path

from cve_watch import send

REGISTRY = "ghcr.io/coasterpedia/coasterpedia-web"
SEVERITIES = ("CRITICAL", "HIGH")
# Trivy's result classes: packages the OS package manager installed, and
# libraries a language package manager installed (Composer, npm, Go, Java).
OS_PACKAGES = "os-pkgs"
DISTROS = {"alpine": "Alpine", "amazon": "Amazon Linux", "debian": "Debian",
           "redhat": "Red Hat", "ubuntu": "Ubuntu"}


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
                    "os": result.get("Class") == OS_PACKAGES,
                    # The distribution for OS packages (alpine, amazon, ubuntu), else
                    # the language package type (composer, gobinary, jar).
                    "type": result.get("Type", ""),
                    # Java results all have the Target "Java"; PkgPath names the jar.
                    "target": vuln.get("PkgPath") or result.get("Target", ""),
                })
    return found


def key(image: str, f: dict) -> str:
    return f"{image}:{f['id']}:{f['package']}"


@dataclass
class Build:
    base: str        # what the image is built on, for the email
    upgrades: bool   # whether its build runs apk upgrade


def build_of(image: str, repo: Path) -> Build:
    if image == "opensearch":
        # deploy.yml builds Wikimedia's image at a pinned tag; we have no Dockerfile.
        tag = re.search(r"IMAGE_NAME: \S+/opensearch\s+TAG: (\S+)",
                        (repo / ".github/workflows/deploy.yml").read_text())
        return Build(f"Wikimedia's OpenSearch image {tag[1]}" if tag else "Wikimedia's OpenSearch image", False)
    dockerfile = repo / f"Dockerfile-{'mediawiki' if image == 'jobrunner' else image}"
    text = dockerfile.read_text() if dockerfile.exists() else ""
    base = re.search(r"^FROM (\S+)", text, re.MULTILINE)
    return Build(base[1] if base else "its base image", "apk upgrade" in text)


def group(found: list[dict]) -> list[dict]:
    """One entry per package version, with its CVEs, fixed versions and files."""
    groups: dict[tuple, dict] = {}
    for f in found:
        g = groups.setdefault((f["os"], f["type"], f["package"], f["installed"]), {
            **{k: f[k] for k in ("os", "type", "package", "installed")},
            "cves": {}, "fixed": [], "targets": []})
        g["cves"][f["id"]] = f["severity"]
        for k, v in (("fixed", f["fixed"]), ("targets", f["target"])):
            if v and v not in g[k]:
                g[k].append(v)
    return sorted(groups.values(), key=lambda g: (
        -sum(s == "CRITICAL" for s in g["cves"].values()), -len(g["cves"]), g["package"]))


def todo(image: str, g: dict, build: Build) -> str:
    if g["os"]:
        distro = DISTROS.get(g["type"], g["type"].title())
        if g["type"] == "alpine" and build.upgrades:
            return ("Alpine has the fix. Run the Docker workflow manually: it builds without the cache, "
                    "and the build's apk upgrade picks it up. If it's still here afterwards, the fix "
                    f"isn't in the Alpine branch {build.base} uses yet.")
        return (f"{distro} has the fix, but our build doesn't upgrade {distro} packages. "
                f"It's fixed when {build.base} ships a rebuild.")
    where = g["targets"][0] + (f" and {len(g['targets']) - 1} more" if len(g["targets"]) > 1 else "")
    if g["type"] == "composer" and image in ("mediawiki", "jobrunner"):
        return f"A Composer package ({where}). Bump it in composer.json, or wait for the MediaWiki release that does."
    if g["type"] == "gobinary":
        return (f"A Go program ({where}) built with an old Go. It's fixed when {build.base} "
                "ships a rebuild with a newer Go.")
    if g["type"] in ("jar", "pom", "gradle"):
        return f"A Java library ({where}) that comes with {build.base}. It's fixed by a newer version of that image."
    return f"A {g['type']} package ({where}) from {build.base}. It's fixed when that ships a newer one."


def build_email(new: dict[str, list[dict]], builds: dict[str, Build],
                sender: str, recipient: str) -> EmailMessage:
    count = sum(len(v) for v in new.values())
    parts, packages = [], 0
    for image, found in sorted(new.items()):
        lines = [f"{image}, built on {builds[image].base}\n"]
        for g in group(found):
            packages += 1
            lines.append(f"{g['package']} {g['installed']}, fixed in {'; '.join(g['fixed'])}")
            for severity in SEVERITIES:
                ids = sorted(i for i, s in g["cves"].items() if s == severity)
                if ids:
                    lines += textwrap.wrap(f"{len(ids)} {severity.lower()}: {', '.join(ids)}",
                                           width=76, initial_indent="  ", subsequent_indent="    ")
            lines += textwrap.wrap(todo(image, g, builds[image]), width=76,
                                   initial_indent="  ", subsequent_indent="  ")
            lines.append("")
        parts.append("\n".join(lines))
    msg = EmailMessage()
    msg["Subject"] = (f"[Coasterpedia] {count} fixable image vulnerabilit{'y' if count == 1 else 'ies'} "
                      f"in {packages} package{'' if packages == 1 else 's'}")
    msg["From"] = sender
    msg["To"] = recipient
    msg.set_content(
        "Trivy found these critical or high vulnerabilities in the deployed images, and each\n"
        "has a fixed version. Each package says what's left to do. Look a CVE up at\n"
        "https://avd.aquasec.com/nvd/<cve-id>.\n\n"
        + ("\n" + "-" * 72 + "\n\n").join(parts)
    )
    return msg


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--versions", type=Path, default=Path("versions.env"))
    parser.add_argument("--repo", type=Path, default=Path("."),
                        help="checkout to read the Dockerfiles and deploy.yml from")
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
        builds = {image: build_of(image, args.repo) for image in new}
        send(build_email(new, builds, "wiki@coasterpedia.net", os.environ["SECURITY_ALERT_EMAIL"]))

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
