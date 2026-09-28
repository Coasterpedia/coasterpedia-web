#!/usr/bin/env python3
"""Email new MediaWiki CVEs that touch core or anything this wiki loads.

A CVE is relevant when its description names something LocalSettings.php loads
with wfLoadExtension()/wfLoadSkin(), or names no extension or skin at all (a
core issue). NVD can't say whether our version is affected; the email links
the details to check.

IDs already handled are kept in a small JSON state file between runs (the
workflow keeps it in the Actions cache), so each CVE is emailed once. The repo
is public, so nothing CVE-specific is printed to the log outside --dry-run.
"""
import argparse
import datetime as dt
import json
import os
import re
import smtplib
import sys
import time
import urllib.parse
import urllib.request
from email.message import EmailMessage
from pathlib import Path

NVD_URL = "https://services.nvd.nist.gov/rest/json/cves/2.0"

# Other names CVEs use for things we load, mapped to the name we load them by.
ALIASES = {
    "SyntaxHighlight": "SyntaxHighlight_GeSHi",
    # DPL4 is a fork of DynamicPageList3, and DPL3's bugs usually carry over.
    "DynamicPageList": "DynamicPageList4",
    "DynamicPageList3": "DynamicPageList4",
}

MENTIONS_EXTENSION = re.compile(r"\b(?:extension|skin)s?\b", re.IGNORECASE)


def loaded_components(localsettings: Path) -> set[str]:
    """Names passed to wfLoadExtension(s)/wfLoadSkin(s), ignoring commented lines."""
    names = set()
    for line in localsettings.read_text().splitlines():
        line = line.strip()
        if line.startswith(("#", "//", "/*", "*")):
            continue
        call = re.search(r"wfLoad(?:Extension|Skin)s?\s*\((.*)\)", line)
        if call:
            for name in re.findall(r"['\"]([^'\"]+)['\"]", call.group(1)):
                names.update(name.split("/"))  # ConfirmEdit/Turnstile
    return names


def fetch_cves(start: dt.datetime, end: dt.datetime, api_key: str | None) -> list[dict]:
    fmt = "%Y-%m-%dT%H:%M:%S.000Z"
    params = {
        "keywordSearch": "MediaWiki",
        "pubStartDate": start.strftime(fmt),
        "pubEndDate": end.strftime(fmt),
        "resultsPerPage": 2000,
    }
    headers = {"User-Agent": "coasterpedia-cve-watch (https://github.com/Coasterpedia/coasterpedia-web)"}
    if api_key:
        headers["apiKey"] = api_key

    cves, index = [], 0
    while True:
        url = f"{NVD_URL}?{urllib.parse.urlencode({**params, 'startIndex': index})}"
        for attempt in range(4):
            try:
                with urllib.request.urlopen(urllib.request.Request(url, headers=headers), timeout=60) as r:
                    page = json.load(r)
                break
            except OSError as e:  # NVD is often slow or rate-limits; back off and retry
                if attempt == 3:
                    raise
                print(f"NVD request failed ({e}), retrying", file=sys.stderr)
                time.sleep(10 * (attempt + 1))
        cves += [v["cve"] for v in page["vulnerabilities"]]
        index += page["resultsPerPage"]
        if index >= page["totalResults"] or not page["resultsPerPage"]:
            return cves


def description(cve: dict) -> str:
    return next((d["value"] for d in cve["descriptions"] if d["lang"] == "en"), "").strip()


def top_score(cve: dict) -> str:
    scores = [
        (m["cvssData"]["baseScore"], m["cvssData"].get("baseSeverity", ""), m["cvssData"]["version"])
        for metrics in cve.get("metrics", {}).values()
        for m in metrics
        if "cvssData" in m
    ]
    if not scores:
        return "not scored yet"
    score, severity, version = max(scores)
    return f"{score} {severity} (CVSS {version})"


def affected(desc: str, names: set[str]) -> list[str] | None:
    """What the CVE hits that we load, ['core'], or None if it isn't ours."""
    candidates = {n: n for n in names} | {a: n for a, n in ALIASES.items() if n in names}
    hits = sorted({
        loaded for mentioned, loaded in candidates.items()
        # Case-sensitive whole-word match: names like Cite or Thanks are also words.
        if re.search(rf"(?<![\w-]){re.escape(mentioned)}(?![\w-])", desc)
    })
    if hits:
        return hits
    if not MENTIONS_EXTENSION.search(desc):
        return ["core"]
    return None


def build_email(relevant: list[tuple[dict, list[str]]], sender: str, recipient: str) -> EmailMessage:
    parts = []
    for cve, hits in relevant:
        text = re.sub(r"\n{3,}", "\n\n", description(cve))
        parts.append(
            f"{cve['id']}  [{', '.join(hits)}]  {top_score(cve)}\n"
            f"Published {cve['published'][:10]}  https://nvd.nist.gov/vuln/detail/{cve['id']}\n\n"
            f"{text}\n"
            + "".join(f"  {ref['url']}\n" for ref in cve.get("references", [])[:5])
        )
    msg = EmailMessage()
    msg["Subject"] = f"[Coasterpedia] {len(relevant)} new MediaWiki CVE(s) matching what we run"
    msg["From"] = sender
    msg["To"] = recipient
    msg.set_content(
        "NVD has published CVEs that name MediaWiki core or something LocalSettings.php loads.\n"
        "Check whether the version in Dockerfile-mediawiki is affected (REL1_46 clones pick up\n"
        "backports on the weekly rebuild; tagged and bundled ones don't).\n\n"
        + ("\n" + "-" * 72 + "\n\n").join(parts)
    )
    return msg


def send(msg: EmailMessage) -> None:
    # Same account and port as $wgSMTP. MediaWiki allows a tls:// or ssl:// prefix
    # on the host; smtplib wants the bare name.
    host = re.sub(r"^\w+://", "", os.environ["SMTP_HOST"])
    with smtplib.SMTP(host, 587, timeout=60) as smtp:
        smtp.starttls()
        smtp.login(os.environ["SMTP_USER"], os.environ["SMTP_PASSWORD"])
        smtp.send_message(msg)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--days", type=int, default=4,
                        help="how far back to look (overlaps earlier runs; the state file dedupes)")
    parser.add_argument("--state", type=Path, default=Path(".cve-watch-seen.json"))
    parser.add_argument("--localsettings", type=Path, default=Path("config/mediawiki/LocalSettings.php"))
    parser.add_argument("--dry-run", action="store_true",
                        help="print what would be emailed; don't send or update the state file")
    args = parser.parse_args()

    names = loaded_components(args.localsettings)
    seen = json.loads(args.state.read_text()) if args.state.exists() else {}
    now = dt.datetime.now(dt.timezone.utc)

    cves = fetch_cves(now - dt.timedelta(days=args.days), now, os.environ.get("NVD_API_KEY"))
    new = [c for c in cves if c["id"] not in seen]
    relevant = [(c, hits) for c in new if (hits := affected(description(c), names))]
    print(f"{len(names)} components loaded; {len(cves)} MediaWiki CVEs in the last {args.days} days, "
          f"{len(new)} new, {len(relevant)} relevant")

    if args.dry_run:
        for c in new:
            hits = affected(description(c), names)
            print(f"  {'MATCH' if hits else '     '} {c['id']} {hits or ''} {description(c)[:110]!r}")
        return 0

    if relevant:
        send(build_email(relevant, "wiki@coasterpedia.net", os.environ["SECURITY_ALERT_EMAIL"]))

    # Only after the email went out, so a failed send is retried next run.
    today = now.date().isoformat()
    seen.update({c["id"]: today for c in new})
    cutoff = (now - dt.timedelta(days=args.days + 30)).date().isoformat()
    args.state.write_text(json.dumps({k: v for k, v in seen.items() if v >= cutoff}, indent=0))
    return 0


if __name__ == "__main__":
    sys.exit(main())
