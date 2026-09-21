#!/usr/bin/env bash
#
# Encrypted logical base backup.
# Runs inside the DR toolbox container, invoked by Ofelia (job-exec).
#
# One script, two callers:
#
#   backup.sh                                    # wiki set: daily/weekly/monthly
#   backup.sh --name coasterpedia-analytics \    # Matomo: weekly/monthly only
#             --databases "$DB_NAME_ANALYTICS" \
#             --tiers weekly,monthly --no-binlog-coord
#
# Matomo is split out because it dominated the artifact. Its archive blobs are
# already gzip-compressed inside the database, so they pass through the `gzip`
# below essentially uncompressed -- roughly 700MB of a 1.18GB daily dump that
# no amount of compression was ever going to shrink.
#
# It is also outside the PITR story: drill.sh replays with
# `mariadb-binlog --database=coasterpedia`, which takes a single database, so
# everything else can only ever be restored as far as its last base dump. A
# daily Matomo dump was buying a 24h RPO on data that binlog replay cannot roll
# forward anyway. Weekly is the honest trade -- Matomo's RPO becomes 7 days,
# and the daily artifact carries only what replay can actually use.
set -euo pipefail

# Required (supplied by compose / .env):
: "${DB_HOST:?set DB_HOST}"
: "${AGE_RECIPIENT:?set AGE_RECIPIENT}"     # public key; private key is NOT on this box
: "${R2_REMOTE:?set R2_REMOTE}"             # e.g. r2:coasterpedia-backups/db

# Optional (sane defaults):
DEFAULTS_FILE="${DEFAULTS_FILE:-/secrets/backup.cnf}"   # mounted DB creds
WORKDIR="${WORKDIR:-/work}"
MIN_SIZE="${MIN_SIZE:-100000}"                          # refuse-to-ship floor (bytes)

# Defaults reproduce the pre-split wiki backup exactly, so a bare `backup.sh`
# keeps doing what it always did.
ARTIFACT_NAME="coasterpedia-db"
DATABASES="${DB_NAME:-}"
TIERS="daily,weekly,monthly"
BINLOG_COORD=1
HC="${HC_URL:-}"

while [ $# -gt 0 ]; do
  case "$1" in
    --name)             ARTIFACT_NAME="$2"; shift 2 ;;
    --databases)        DATABASES="$2";     shift 2 ;;
    --tiers)            TIERS="$2";         shift 2 ;;
    --hc-url)           HC="$2";            shift 2 ;;
    --no-binlog-coord)  BINLOG_COORD=0;     shift   ;;
    *) echo "Unknown argument: $1" >&2; exit 2 ;;
  esac
done

[ -n "$DATABASES" ] || { echo "No databases given (--databases or DB_NAME)" >&2; exit 2; }
[ -n "$HC" ]        || { echo "No healthcheck URL given (--hc-url or HC_URL)" >&2; exit 2; }

ping_hc() { curl -fsS -m 10 --retry 3 "${HC}${1:-}" >/dev/null 2>&1 || true; }

# Which tiers does today qualify for? Validate the whole list before dumping so
# a typo fails fast and loudly rather than silently shipping nowhere.
SHIP_TO=()
IFS=',' read -r -a WANTED <<<"$TIERS"
for tier in "${WANTED[@]}"; do
  case "$tier" in
    daily)   SHIP_TO+=("daily") ;;
    weekly)  [ "$(date -u +%u)" = "7"  ] && SHIP_TO+=("weekly") ;;
    monthly) [ "$(date -u +%d)" = "01" ] && SHIP_TO+=("monthly") ;;
    *) echo "Unknown tier '${tier}' in --tiers" >&2; exit 2 ;;
  esac
done

# Nothing to ship today. Dumping ~1.4GB to delete it again helps nobody, but the
# job *did* run and decide correctly, so the check stays green.
if [ ${#SHIP_TO[@]} -eq 0 ]; then
  ping_hc
  echo "OK: no tier due today for ${ARTIFACT_NAME}; nothing dumped"
  exit 0
fi

TS="$(date -u +%Y%m%dT%H%M%SZ)"
ARTIFACT="${ARTIFACT_NAME}-${TS}.sql.gz.age"
TMP="${WORKDIR}/.${ARTIFACT}.partial"
mkdir -p "$WORKDIR"

# Any failure (including inside the pipe) -> alert + clean up.
fail() {
  local rc=$?
  ping_hc /fail
  rm -f "$TMP"
  exit "$rc"
}
trap fail ERR

ping_hc /start

# --master-data=2 --gtid record the resume coordinate; --flush-logs starts a
# clean binlog for stage 2 to stream from. Only the wiki dump needs these: it is
# the only database binlog replay is scoped to, and a second job rotating logs
# on its own schedule just churns stream-binlogs.sh for nothing.
COORD_ARGS=()
if [ "$BINLOG_COORD" = "1" ]; then
  COORD_ARGS=( --master-data=2 --gtid --flush-logs )
fi
# Word splitting on $DATABASES is deliberate -- it is a space-separated list.
read -r -a DB_LIST <<<"$DATABASES"

# Consistent, non-locking dump. The +"..." guard on COORD_ARGS is because
# expanding an empty array under `set -u` is only safe from bash 4.4; the image
# has 5.x, but the empty case is the weekly path -- it would fail unwatched.
# pipefail (set -o above) means a mariadb-dump failure fails the whole pipe,
# so a truncated-but-valid-looking encrypted file can never be shipped.
mariadb-dump \
  --defaults-extra-file="$DEFAULTS_FILE" \
  -h "$DB_HOST" \
  --single-transaction --quick \
  --routines --triggers --events \
  ${COORD_ARGS[@]+"${COORD_ARGS[@]}"} \
  --databases "${DB_LIST[@]}" \
| gzip -6 \
| age -r "$AGE_RECIPIENT" \
> "$TMP"

if [ "$(stat -c%s "$TMP")" -lt "$MIN_SIZE" ]; then
  echo "Refusing to ship: artifact implausibly small ($(stat -c%s "$TMP") bytes)" >&2
  exit 1
fi
mv "$TMP" "${WORKDIR}/${ARTIFACT}"

# R2 lifecycle rules expire each prefix (8d daily / 40d weekly / 400d monthly)
# -> grandfather-father-son retention, no pruning logic here.
# An explicit loop (not && ||) so a real rclone failure still trips the alert.
for tier in "${SHIP_TO[@]}"; do
  rclone copyto "${WORKDIR}/${ARTIFACT}" "${R2_REMOTE}/${tier}/${ARTIFACT}"
done

# Keep newest 3 local copies *of this artifact*; R2 is the system of record.
# Scoped to the prefix so the two jobs cannot prune each other's dumps.
ls -1t "${WORKDIR}/${ARTIFACT_NAME}"-*.sql.gz.age 2>/dev/null | tail -n +4 | xargs -r rm -f

ping_hc
echo "OK: ${ARTIFACT} -> ${SHIP_TO[*]}"
