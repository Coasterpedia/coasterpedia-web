#!/usr/bin/env python3
"""Email new MediaWiki CVEs that hit something this wiki runs and aren't fixed yet.

A CVE is a candidate when its description names something LocalSettings.php
loads with wfLoadExtension()/wfLoadSkin(), or names no extension or skin at all
(a core issue). Each candidate is then checked against what is deployed:

- the fix commits come from the CVE's Gerrit and GitHub references;
- the live wiki's siteinfo gives the deployed commit of every extension and
  skin Dockerfile-mediawiki clones (GitInfo reads the clone's .git), and core's
  version;
- GitHub's compare API says whether a fix commit is in what's deployed, or on
  the branch the next rebuild clones.

Where the fix commits don't settle it, the CNA's affected-version ranges from
CVE.org do. Only CVEs that are still open, waiting on a rebuild, or couldn't be
checked are emailed. The rest are cleared silently and listed in the job log.

IDs already handled are kept in a small JSON state file between runs (the
workflow keeps it in the Actions cache), so each CVE is looked at once. The repo
is public, so the log names only CVEs that were cleared; anything still open
goes only to the email. --dry-run prints everything.
"""
import argparse
import datetime as dt
import functools
import json
import os
import re
import smtplib
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from email.message import EmailMessage
from pathlib import Path

NVD_URL = "https://services.nvd.nist.gov/rest/json/cves/2.0"
CVE_ORG_URL = "https://cveawg.mitre.org/api/cve/"
GERRIT_URL = "https://gerrit.wikimedia.org/r/"
GITHUB_API = "https://api.github.com/"
WIKI_API = "https://coasterpedia.net/w/api.php"
USER_AGENT = "coasterpedia-cve-watch (https://github.com/Coasterpedia/coasterpedia-web)"

# Other names CVEs use for things we load, mapped to the name we load them by.
ALIASES = {
    "SyntaxHighlight": "SyntaxHighlight_GeSHi",
    # DPL4 is a fork of DynamicPageList3, and DPL3's bugs usually carry over.
    "DynamicPageList": "DynamicPageList4",
    "DynamicPageList3": "DynamicPageList4",
}

# CVEs fixed by something the checks below can't see: a hotfix patch or a file
# we override. Drop an entry once the upstream fix is deployed.
MITIGATED = {
    "CVE-2026-96873": "hotfixed by includes/CirrusSearch/explainprinter-xss.patch",
}

MENTIONS_EXTENSION = re.compile(r"\b(?:extension|skin)s?\b", re.IGNORECASE)
CLONE = re.compile(r'git clone .*?-b (\S+) "https://github\.com/([^/"]+/[^/"]+?)\.git" (?:extensions|skins)/(\S+)')
GERRIT_CHANGE = re.compile(r"gerrit\.wikimedia\.org/r/(?:c/.+?/\+/|#/c/)?(\d+)")
GERRIT_CHANGE_ID = re.compile(r"gerrit\.wikimedia\.org/r/.*\b(I[0-9a-f]{40})\b")
GITILES_COMMIT = re.compile(r"gerrit\.wikimedia\.org/r/plugins/gitiles/(.+?)/\+/([0-9a-f]{40})")
GITHUB_COMMIT = re.compile(r"github\.com/([^/]+/[^/]+)/commit/([0-9a-f]{7,40})")
GITHUB_PULL = re.compile(r"github\.com/([^/]+/[^/]+)/pull/(\d+)")

# Verdicts that need Alex; the rest are cleared without an email.
ACTION = ("open", "unverified", "pending")


def get_json(url: str, headers: dict | None = None) -> dict | list:
    request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT, **(headers or {})})
    for attempt in range(4):
        try:
            with urllib.request.urlopen(request, timeout=60) as r:
                body = r.read().decode()
            break
        except urllib.error.HTTPError as e:
            if e.code < 500 and e.code != 429:
                raise
            error = e
        except OSError as e:  # NVD in particular is often slow or rate-limits
            error = e
        if attempt == 3:
            raise error
        print(f"Request failed ({error}), retrying", file=sys.stderr)
        time.sleep(10 * (attempt + 1))
    return json.loads(body.removeprefix(")]}'"))  # Gerrit's XSSI guard


def github(path: str) -> dict:
    headers = {"Accept": "application/vnd.github+json"}
    if token := os.environ.get("GITHUB_TOKEN"):
        headers["Authorization"] = f"Bearer {token}"
    return get_json(GITHUB_API + path, headers)


def loaded_components(localsettings: Path) -> dict[str, str]:
    """{name: "extensions" or "skins"} for each wfLoadExtension(s)/wfLoadSkin(s), ignoring commented lines."""
    names = {}
    for line in localsettings.read_text().splitlines():
        line = line.strip()
        if line.startswith(("#", "//", "/*", "*")):
            continue
        call = re.search(r"wfLoad(Extension|Skin)s?\s*\((.*)\)", line)
        if call:
            for name in re.findall(r"['\"]([^'\"]+)['\"]", call.group(2)):
                for part in name.split("/"):  # ConfirmEdit/Turnstile
                    names[part] = call.group(1).lower() + "s"
    return names


@dataclass
class Setup:
    """What's deployed, from Dockerfile-mediawiki and the live wiki."""
    names: dict[str, str]                 # from loaded_components()
    branch: str                           # MEDIAWIKI_BRANCH
    clones: dict[str, tuple[str, str]]    # {name: (GitHub owner/repo, branch or tag)}
    core: str                             # core version, e.g. "1.46.0"
    live: dict[str, tuple[str, str]]      # {owner/repo lowercased: (deployed commit, extension.json version)}


def read_setup(names: dict[str, str], dockerfile: Path, wiki_api: str) -> Setup:
    text = dockerfile.read_text()
    branch = re.search(r"^ENV MEDIAWIKI_BRANCH (\S+)", text, re.MULTILINE).group(1)
    clones = {name: (repo, branch if ref == "$MEDIAWIKI_BRANCH" else ref) for ref, repo, name in CLONE.findall(text)}
    core, live = re.search(r"^FROM mediawiki:([\d.]+)", text, re.MULTILINE).group(1), {}
    try:
        query = get_json(f"{wiki_api}?action=query&meta=siteinfo&siprop=general|extensions"
                         "&format=json&formatversion=2")["query"]
        core = query["general"]["generator"].split()[-1]
        for ext in query["extensions"]:
            if m := re.match(r"https://github\.com/([^/]+/[^/]+)/commit/([0-9a-f]{40})", ext.get("vcs-url", "")):
                live[m[1].lower()] = (m[2], ext.get("version", ""))
    except (OSError, KeyError, ValueError) as e:
        # Tag clones still get checked against the tag; branch clones end up unverified.
        print(f"Couldn't read deployed versions from the wiki ({e})", file=sys.stderr)
    return Setup(names, branch, clones, core, live)


@dataclass
class Component:
    name: str
    kind: str              # "core", "bundled" (in the tarball), "branch" or "tag" (cloned)
    repo: str              # GitHub owner/repo the deployed code comes from
    deployed: str | None   # commit or tag that's live, if known
    branch: str            # branch a fix has to land on to reach us
    version: str           # to compare with the CNA's version ranges
    source: str            # how we install it, for the email


def component(name: str, setup: Setup) -> Component:
    if name == "core":
        return Component(name, "core", "wikimedia/mediawiki", setup.core, setup.branch, setup.core,
                         f"{setup.core} tarball in the base image")
    if name in setup.clones:
        repo, ref = setup.clones[name]
        commit, version = setup.live.get(repo.lower(), (None, ""))
        if ref == setup.branch:  # Wikimedia's own; CNAs version these with core
            return Component(name, "branch", repo, commit, ref, setup.core, f"{ref} clone")
        if ref in ("main", "master"):
            return Component(name, "branch", repo, commit, ref, version or ref, f"{ref} clone")
        return Component(name, "tag", repo, commit or ref, ref, version or ref, f"{ref} tag")
    # Gerrit's mediawiki/extensions/X is mirrored to GitHub under this name.
    return Component(name, "bundled", f"wikimedia/mediawiki-{setup.names[name]}-{name}", None,
                     setup.branch, setup.core, f"copy bundled in the {setup.core} tarball")


def mirror(project: str) -> str:
    """GitHub mirror of a Gerrit project."""
    return "wikimedia/mediawiki" if project == "mediawiki/core" else "wikimedia/" + project.replace("/", "-")


@functools.cache
def gerrit_fixes(change: str) -> tuple[tuple[str, str, str], ...]:
    """(repo, branch, commit) for every merged copy of a Gerrit change (a number or Change-Id)."""
    change_id = change if change.startswith("I") else get_json(f"{GERRIT_URL}changes/{change}")["change_id"]
    changes = get_json(f"{GERRIT_URL}changes/?q=change:{change_id}&o=CURRENT_REVISION")
    return tuple((mirror(c["project"]).lower(), c["branch"], c["current_revision"])
                 for c in changes if c["status"] == "MERGED")


def fix_commits(urls: list[str]) -> set[tuple[str, str, str]]:
    """(repo, branch or "", commit) for each merged fix the CVE's references point at."""
    fixes = set()
    for url in urls:
        if m := GITILES_COMMIT.search(url):
            fixes.add((mirror(m[1]).lower(), "", m[2]))
        elif m := GERRIT_CHANGE_ID.search(url) or GERRIT_CHANGE.search(url):
            fixes.update(gerrit_fixes(m[1]))
        elif m := GITHUB_COMMIT.search(url):
            fixes.add((m[1].lower(), "", m[2]))
        elif m := GITHUB_PULL.search(url):
            pull = github(f"repos/{m[1]}/pulls/{m[2]}")
            if pull.get("merged_at"):
                fixes.add((m[1].lower(), pull["base"]["ref"], pull["merge_commit_sha"]))
    return fixes


@functools.cache
def contains(repo: str, commit: str, ref: str) -> bool:
    """Whether ref (a commit, branch or tag) has commit in its history."""
    try:
        status = github(f"repos/{repo}/compare/{commit}...{ref}?per_page=1")["status"]
    except urllib.error.HTTPError as e:
        if e.code in (404, 422):  # a commit or ref this repo doesn't have
            return False
        raise
    return status in ("ahead", "identical")


def parse_version(text: str) -> tuple[int, ...] | None:
    m = re.search(r"\d+(?:\.\d+)*", text)
    return (tuple(int(p) for p in m.group().split(".")) + (0, 0, 0))[:4] if m else None


def in_range(entry: dict, ours: tuple[int, ...]) -> bool | None:
    """Whether one CVE 5 `versions` entry covers our version; None if it can't be read."""
    start, end, inclusive = entry.get("version", "").strip(), None, False
    if "lessThan" in entry:
        end = entry["lessThan"]
    elif "lessThanOrEqual" in entry:
        end, inclusive = entry["lessThanOrEqual"], True
    elif start.startswith("<"):  # e.g. "< 4.1.0"
        end, inclusive, start = start, start.startswith("<="), "0"
    if end is None:
        exact = parse_version(start)
        # A different major version means a different numbering scheme, so it tells us nothing.
        return None if not exact or exact[0] != ours[0] else exact == ours
    # Bounds are sometimes one per release branch: "1.43.10/1.45.5/1.46.1".
    bounds = [b for b in map(parse_version, re.findall(r"\d+(?:\.\d+)+", end)) if b]
    same_branch = [b for b in bounds if b[:2] == ours[:2]]
    if same_branch:
        bound = same_branch[0]
    elif len(bounds) == 1 and bounds[0][0] == ours[0]:
        bound = bounds[0]
    else:
        return None
    lowest = parse_version(start) if start not in ("", "0", "*") else None
    return (lowest is None or ours >= lowest) and (ours <= bound if inclusive else ours < bound)


def cna_says(record: dict | None, name: str, version: str) -> bool | None:
    """Whether the CNA lists this version of `name` as affected; None if it doesn't say clearly."""
    ours = parse_version(version)
    if not record or not ours:
        return None
    products = record.get("containers", {}).get("cna", {}).get("affected", [])
    norm = lambda s: re.sub(r"[^a-z0-9]", "", s.lower())
    if name == "core":
        mine = [p for p in products if norm(p.get("product", "")) in ("mediawiki", "mediawikicore")]
    else:
        # A lone product is ours even under another name (an alias, or "Mediawiki - Foo" for a fork).
        mine = [p for p in products if norm(name) in norm(p.get("product", ""))]
        mine = mine or (products if len(products) == 1 else [])
    results = []
    for product in mine:
        entries = [e for e in product.get("versions", []) if e.get("status") == "affected"]
        results += [in_range(e, ours) for e in entries] or [None]
        if product.get("defaultStatus") == "affected":
            results.append(None)  # versions outside the listed ranges count as affected too
    if not results:
        return None
    if True in results:
        return True
    return None if None in results else False


def triage(cve_id: str, fixes: set, record: dict | None, comp: Component) -> tuple[str, str]:
    """(verdict, explanation) for one component a CVE names."""
    if cve_id in MITIGATED:
        return "mitigated", MITIGATED[cve_id]
    commits = [sha for repo, _, sha in fixes if repo == comp.repo.lower()]
    if comp.deployed:
        for sha in commits:
            if contains(comp.repo, sha, comp.deployed):
                return "fixed", f"fix {sha[:10]} is in the deployed {comp.source} ({comp.deployed[:10]})"
    if cna_says(record, comp.name, comp.version) is False:
        return "not affected", f"the CNA's affected versions don't include {comp.version}"

    on_branch = comp.kind != "tag" and any(contains(comp.repo, sha, comp.branch) for sha in commits)
    if comp.kind == "branch" and on_branch:
        if not comp.deployed:
            return "unverified", (f"The fix is on {comp.branch}, but the wiki didn't report which {comp.name} "
                                  "commit is deployed. Check Special:Version, or run the Docker workflow.")
        return "pending", (f"The fix is on {comp.branch}, but the deployed {comp.name} ({comp.deployed[:10]}) "
                           "predates it. Monday's rebuild deploys it; run the Docker workflow to deploy it sooner.")
    if commits:
        branches = ", ".join(sorted({b for r, b, _ in fixes if r == comp.repo.lower() and b}))
        todo = {
            "branch": f"It isn't on {comp.branch} yet. Apply it as a hotfix patch, as done for CirrusSearch.",
            "tag": "Bump to a release that has it (Renovate opens a PR once one is tagged) or apply it as a hotfix patch.",
            "core": f"Apply it as a patch until a mediawiki:{comp.version} image or newer ships it.",
            "bundled": f"It's on {comp.branch}, so swap in a {comp.branch} clone, as done for Thanks." if on_branch
                       else f"It isn't on {comp.branch} yet. Apply it as a patch.",
        }[comp.kind]
        return "open", f"Fixed {'on ' + branches if branches else 'upstream'}, but not in our {comp.source}. {todo}"
    if cna_says(record, comp.name, comp.version):
        return "open", (f"No fix commit in the references, and the CNA lists {comp.version} ({comp.source}) "
                        "as affected. Check whether a fixed version exists.")
    return "unverified", "The references and the CNA's version ranges don't settle it. Check by hand."


def fetch_cves(start: dt.datetime, end: dt.datetime, api_key: str | None) -> list[dict]:
    fmt = "%Y-%m-%dT%H:%M:%S.000Z"
    params = {
        "keywordSearch": "MediaWiki",
        "pubStartDate": start.strftime(fmt),
        "pubEndDate": end.strftime(fmt),
        "resultsPerPage": 2000,
    }
    headers = {"apiKey": api_key} if api_key else {}
    cves, index = [], 0
    while True:
        page = get_json(f"{NVD_URL}?{urllib.parse.urlencode({**params, 'startIndex': index})}", headers)
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


def affected(desc: str, names) -> list[str] | None:
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


def check(cve: dict, hits: list[str], setup: Setup) -> list[tuple[str, str, str]]:
    """(component, verdict, explanation) for each thing the CVE names."""
    try:
        record = get_json(CVE_ORG_URL + cve["id"])
    except (OSError, ValueError):
        record = None  # only the fix commits to go on
    try:
        fixes = fix_commits([ref["url"] for ref in cve.get("references", [])])
    except (OSError, KeyError, ValueError) as e:
        return [(name, "unverified", f"Couldn't look up the fix commits ({e}). Check by hand.") for name in hits]
    results = []
    for name in hits:
        try:
            results.append((name, *triage(cve["id"], fixes, record, component(name, setup))))
        except (OSError, KeyError, ValueError) as e:
            results.append((name, "unverified", f"The automatic check failed ({e}). Check by hand."))
    return results


def build_email(to_act: list, cleared: list, sender: str, recipient: str) -> EmailMessage:
    parts = []
    for cve, results in to_act:
        text = re.sub(r"\n{3,}", "\n\n", description(cve))
        parts.append(
            f"{cve['id']}  [{', '.join(n for n, _, _ in results)}]  {top_score(cve)}\n"
            f"Published {cve['published'][:10]}  https://nvd.nist.gov/vuln/detail/{cve['id']}\n\n"
            + "".join(f"{name} ({verdict}): {why}\n" for name, verdict, why in results)
            + f"\n{text}\n"
            + "".join(f"  {ref['url']}\n" for ref in cve.get("references", [])[:5])
        )
    verdicts = {v for _, results in to_act for _, v, _ in results if v in ACTION}
    msg = EmailMessage()
    if verdicts == {"pending"}:
        msg["Subject"] = f"[Coasterpedia] {len(to_act)} CVE fix(es) waiting for the weekly rebuild"
    else:
        msg["Subject"] = f"[Coasterpedia] {len(to_act)} MediaWiki CVE(s) need a look"
    msg["From"] = sender
    msg["To"] = recipient
    footer = ""
    if cleared:
        footer = "\n\nAlso checked this run, nothing to do:\n" + "".join(
            f"  {cve['id']}  " + "; ".join(f"{n}: {why}" for n, _, why in results) + "\n"
            for cve, results in cleared)
    msg.set_content(
        "These new CVEs name something we run, and checking them against what's deployed\n"
        "didn't clear them. Each says what's left to do.\n\n"
        + ("\n" + "-" * 72 + "\n\n").join(parts)
        + footer
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
    parser.add_argument("--dockerfile", type=Path, default=Path("Dockerfile-mediawiki"))
    parser.add_argument("--wiki-api", default=WIKI_API)
    parser.add_argument("--dry-run", action="store_true",
                        help="print every verdict; don't send or update the state file")
    args = parser.parse_args()

    names = loaded_components(args.localsettings)
    seen = json.loads(args.state.read_text()) if args.state.exists() else {}
    now = dt.datetime.now(dt.timezone.utc)

    cves = fetch_cves(now - dt.timedelta(days=args.days), now, os.environ.get("NVD_API_KEY"))
    new = [c for c in cves if c["id"] not in seen]
    candidates = [(c, hits) for c in new if (hits := affected(description(c), names))]
    setup = read_setup(names, args.dockerfile, args.wiki_api) if candidates else None
    checked = [(c, check(c, hits, setup)) for c, hits in candidates]
    to_act = [(c, r) for c, r in checked if any(v in ACTION for _, v, _ in r)]
    cleared = [(c, r) for c, r in checked if not any(v in ACTION for _, v, _ in r)]
    print(f"{len(names)} components loaded; {len(cves)} MediaWiki CVEs in the last {args.days} days, "
          f"{len(new)} new, {len(candidates)} name something we run, {len(to_act)} need a look")
    for cve, results in cleared:  # safe to name: fixed, mitigated or not affected
        print(f"  cleared {cve['id']}: " + "; ".join(f"{n} {v}, {why}" for n, v, why in results))

    if args.dry_run:
        for cve, results in to_act:
            print(f"  ACTION  {cve['id']}: " + "; ".join(f"{n} {v}, {why}" for n, v, why in results))
        return 0

    if to_act:
        send(build_email(to_act, cleared, "wiki@coasterpedia.net", os.environ["SECURITY_ALERT_EMAIL"]))

    # Only after the email went out, so a failed send is retried next run.
    today = now.date().isoformat()
    seen.update({c["id"]: today for c in new})
    cutoff = (now - dt.timedelta(days=args.days + 30)).date().isoformat()
    args.state.write_text(json.dumps({k: v for k, v in seen.items() if v >= cutoff}, indent=0))
    return 0


if __name__ == "__main__":
    sys.exit(main())
