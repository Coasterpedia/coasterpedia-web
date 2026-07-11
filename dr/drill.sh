#!/usr/bin/env bash
#
# THE DRILL. Nightly, automated, from-scratch restore + point-in-time replay +
# real MediaWiki render, asserted.
#
# This script IS the recovery runbook. The only differences between a drill run
# and a real "Oracle is gone" recovery are:
#   1. it uses throwaway volumes instead of persistent ones, and
#   2. it doesn't flip DNS.
# Everything else - decrypt, restore, replay, boot MediaWiki, verify - is the
# real procedure, executed every night on the hardware you'd actually recover to.
#
# It restores from the LOCAL MIRROR of the encrypted artifact, so a pass
# transitively proves: the R2 objects are good, the rclone pull works, the age
# key decrypts, the dump loads, the binlogs replay, and MediaWiki boots on it.
#
# Runs inside the `dr` container, which on the Pi (and ONLY on the Pi) has the
# docker socket mounted so it can orchestrate the drill-profile containers.

set -euo pipefail

: "${COMPOSE_FILE:?set COMPOSE_FILE}"       # path to docker-compose.dr.yml (mounted)
: "${DUMP_DIR:?set DUMP_DIR}"               # mirrored encrypted dumps
: "${BINLOG_DIR:?set BINLOG_DIR}"           # streamed raw binlogs
: "${AGE_KEY_FILE:?set AGE_KEY_FILE}"       # age PRIVATE key (Pi only)
: "${HC_URL:?set HC_URL}"

DRILL_DB_HOST="${DRILL_DB_HOST:-mariadb-drill}"
DRILL_MW_URL="${DRILL_MW_URL:-http://mediawiki-drill}"
MW_SCRIPT_PATH="${MW_SCRIPT_PATH:-/w}"
MW_ARTICLE_PATH="${MW_ARTICLE_PATH:-/wiki}"
MAIN_PAGE="${MAIN_PAGE:-Main_Page}"
STATE_FILE="${STATE_FILE:-/state/drill-last.env}"
HEARTBEAT_MAX_LAG="${HEARTBEAT_MAX_LAG:-1200}"   # 20 min
ROOT_PW="${DRILL_MYSQL_ROOT_PASSWORD:-drill}"

PROJECT="${COMPOSE_PROJECT:-coasterpedia-web}"

COMPOSE=(docker compose -p "$PROJECT" --env-file .env --env-file versions.env -f "$COMPOSE_FILE" --profile drill)
MYSQL=(mariadb -h "$DRILL_DB_HOST" -u root -p"$ROOT_PW" -N -B)

cd /mnt/ssd/repo

log() { echo "[drill $(date -u +%H:%M:%S)] $*"; }

teardown() {
  "${COMPOSE[@]}" rm -fsv mariadb-drill redis-drill mediawiki-drill >/dev/null 2>&1 || true
  docker volume rm -f "${PROJECT}_drill-mariadb" >/dev/null 2>&1 || true
}

fail() {
  log "FAILED: $1"
  curl -fsS -m 10 --retry 3 "${HC_URL}/fail" -d "$1" >/dev/null 2>&1 || true
  teardown
  exit 1
}
trap 'fail "unexpected error on line $LINENO"' ERR

curl -fsS -m 10 --retry 3 "${HC_URL}/start" >/dev/null 2>&1 || true

# ---------------------------------------------------------------------------
# 1. Wipe. From scratch, every time - a drill that reuses state proves nothing.
# ---------------------------------------------------------------------------
log "Tearing down any previous drill and removing its volumes"
teardown

# ---------------------------------------------------------------------------
# 2. Locate the newest encrypted base dump and extract its binlog coordinate.
#    --master-data=2 wrote it as a commented CHANGE MASTER line in the header.
# ---------------------------------------------------------------------------
LATEST="$(ls -1t "${DUMP_DIR}"/daily/*.sql.gz.age 2>/dev/null | head -1 || true)"
[ -n "$LATEST" ] || fail "No encrypted dump found in ${DUMP_DIR}/daily"
log "Using base dump: $(basename "$LATEST")"

# Decrypt only the header to read the coordinate. SIGPIPE from `head` closing
# the pipe early is expected, hence the guarded subshell.
HEADER="$(set +o pipefail; age -d -i "$AGE_KEY_FILE" "$LATEST" | gunzip | head -c 65536 || true)"
LOG_FILE="$(sed -n "s/.*MASTER_LOG_FILE='\([^']*\)'.*/\1/p" <<<"$HEADER" | head -1)"
LOG_POS="$( sed -n "s/.*MASTER_LOG_POS=\([0-9]*\).*/\1/p"    <<<"$HEADER" | head -1)"
[ -n "$LOG_FILE" ] && [ -n "$LOG_POS" ] || fail "Could not read binlog coordinate from dump header"
log "Dump coordinate: ${LOG_FILE}:${LOG_POS}"

[ -f "${BINLOG_DIR}/${LOG_FILE}" ] || fail "Binlog ${LOG_FILE} missing locally - stream has a gap; replay impossible"

# ---------------------------------------------------------------------------
# 3. Boot a throwaway MariaDB with durability disabled. It's disposable, so
#    fsync-per-commit buys us nothing and costs a lot on a Pi.
# ---------------------------------------------------------------------------
log "Starting drill MariaDB"
"${COMPOSE[@]}" up -d mariadb-drill
for i in $(seq 1 60); do
  if "${MYSQL[@]}" -e 'SELECT 1' >/dev/null 2>&1; then break; fi
  [ "$i" -eq 60 ] && fail "drill MariaDB never became ready"
  sleep 5
done

# ---------------------------------------------------------------------------
# 4. Restore the base dump.
# ---------------------------------------------------------------------------
log "Restoring base dump (this is the slow part; ~2.4GB)"
{
  echo "SET SESSION unique_checks=0;"
  echo "SET SESSION foreign_key_checks=0;"
  echo "SET SESSION sql_log_bin=0;"
  age -d -i "$AGE_KEY_FILE" "$LATEST" | gunzip
} | mariadb -h "$DRILL_DB_HOST" -u root -p"$ROOT_PW" \
  || fail "base dump failed to restore"

# ---------------------------------------------------------------------------
# 5. Replay binlogs from the dump's coordinate to head.
#
#    --database=coasterpedia scopes replay to the wiki. The binlogs also carry
#    coasterpedia_analytics (Matomo) events, whose tables don't exist here;
#    unscoped replay would die on them. --start-position applies to the FIRST
#    file listed only, which is exactly the semantics we want.
# ---------------------------------------------------------------------------
mapfile -t REPLAY_FILES < <(
  find "$BINLOG_DIR" -maxdepth 1 -name 'mariadb-bin.[0-9]*' -printf '%f\n' \
    | sort | awk -v start="$LOG_FILE" '$0 >= start'
)
[ "${#REPLAY_FILES[@]}" -gt 0 ] || fail "no binlogs at/after ${LOG_FILE}"
log "Replaying ${#REPLAY_FILES[@]} binlog(s) from ${LOG_FILE}:${LOG_POS}"

( cd "$BINLOG_DIR" && mariadb-binlog \
    --database=coasterpedia \
    --start-position="$LOG_POS" \
    "${REPLAY_FILES[@]}" ) \
| mariadb -h "$DRILL_DB_HOST" -u root -p"$ROOT_PW" \
  || fail "binlog replay failed"

# ---------------------------------------------------------------------------
# 6. ASSERTIONS. Ordered by what they actually prove.
# ---------------------------------------------------------------------------

# 6a. THE RPO CHECK. The only assertion that proves replay ran AND reached head.
#     Everything below would pass identically on a week-old dump with a broken
#     binlog stream. This is the one that would catch that.
LAG="$("${MYSQL[@]}" -e "SELECT TIMESTAMPDIFF(SECOND, ts, UTC_TIMESTAMP()) FROM coasterpedia.dr_heartbeat WHERE id=1;")"
[ -n "$LAG" ] || fail "dr_heartbeat row absent - heartbeat writer not running on the VM?"
[ "$LAG" -lt "$HEARTBEAT_MAX_LAG" ] || fail "heartbeat lag ${LAG}s exceeds ${HEARTBEAT_MAX_LAG}s - replay did not reach head"
log "PASS: heartbeat lag ${LAG}s (restore is current)"

# 6b. Integrity: counts non-zero and monotonic vs the last passing drill.
#     Catches a truncated restore that still renders a cached-looking page.
PAGES="$("${MYSQL[@]}"     -e 'SELECT COUNT(*) FROM coasterpedia.mw_page;')"
REVISIONS="$("${MYSQL[@]}" -e 'SELECT COUNT(*) FROM coasterpedia.mw_revision;')"
[ "$PAGES" -gt 0 ] && [ "$REVISIONS" -gt 0 ] || fail "empty page/revision tables"

if [ -f "$STATE_FILE" ]; then
  # shellcheck disable=SC1090
  . "$STATE_FILE"
  [ "$PAGES" -ge "${LAST_PAGES:-0}" ] \
    || fail "page count went BACKWARDS: ${LAST_PAGES} -> ${PAGES}"
  [ "$REVISIONS" -ge "${LAST_REVISIONS:-0}" ] \
    || fail "revision count went BACKWARDS: ${LAST_REVISIONS} -> ${REVISIONS}"
fi
log "PASS: ${PAGES} pages, ${REVISIONS} revisions"

# 6c. Does MediaWiki actually boot on this database and render?
log "Starting drill Redis + MediaWiki"
"${COMPOSE[@]}" up -d redis-drill mediawiki-drill
for i in $(seq 1 40); do
  curl -fsS -m 5 -o /dev/null "${DRILL_MW_URL}${MW_ARTICLE_PATH}/${MAIN_PAGE}" 2>/dev/null && break
  [ "$i" -eq 40 ] && fail "drill MediaWiki never served a response"
  sleep 5
done

BODY="$(curl -fsS -m 30 "${DRILL_MW_URL}${MW_ARTICLE_PATH}/${MAIN_PAGE}")" \
  || fail "Main Page request failed"

# A 200 is NOT proof: MediaWiki serves DB error pages with a 200 in some paths.
# Check for a positive marker AND the absence of known failure strings.
grep -qi 'coasterpedia' <<<"$BODY" || fail "Main Page body lacks expected content marker"
for bad in 'Database error' 'technical difficulties' 'Internal error' 'MWException'; do
  grep -qi "$bad" <<<"$BODY" && fail "Main Page contains error string: ${bad}"
done
log "PASS: Main Page rendered ($(wc -c <<<"$BODY") bytes)"

# 6d. API reads real tables and returns machine-checkable totals.
STATS="$(curl -fsS -m 30 "${DRILL_MW_URL}${MW_SCRIPT_PATH}/api.php?action=query&meta=siteinfo&siprop=statistics&format=json")" \
  || fail "api.php siteinfo failed"
grep -q '"articles"' <<<"$STATS" || fail "siteinfo returned no statistics"
log "PASS: api.php siteinfo OK"

# ---------------------------------------------------------------------------
# 7. Record state, tear down, report success.
# ---------------------------------------------------------------------------
mkdir -p "$(dirname "$STATE_FILE")"
cat > "$STATE_FILE" <<EOF
LAST_PAGES=${PAGES}
LAST_REVISIONS=${REVISIONS}
LAST_LAG=${LAG}
LAST_RUN=$(date -u +%Y-%m-%dT%H:%M:%SZ)
EOF

trap - ERR
teardown

curl -fsS -m 10 --retry 3 "${HC_URL}" \
  -d "pages=${PAGES} revisions=${REVISIONS} heartbeat_lag=${LAG}s" >/dev/null 2>&1 || true
log "DRILL PASSED - restore is proven, RPO is ${LAG}s"