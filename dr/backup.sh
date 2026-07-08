#!/usr/bin/env bash
#
# Daily encrypted logical base backup.
# Runs inside the DR toolbox container, invoked by Ofelia (job-exec).
set -euo pipefail

# Required (supplied by compose / .env):
: "${DB_HOST:?set DB_HOST}"
: "${DB_NAME:?set DB_NAME}"
: "${AGE_RECIPIENT:?set AGE_RECIPIENT}"     # public key; private key is NOT on this box
: "${R2_REMOTE:?set R2_REMOTE}"             # e.g. r2:coasterpedia-backups/db
: "${HC_URL:?set HC_URL}"                   # healthchecks.io ping URL for this job

# Optional (sane defaults):
DEFAULTS_FILE="${DEFAULTS_FILE:-/secrets/backup.cnf}"   # mounted DB creds
WORKDIR="${WORKDIR:-/work}"
MIN_SIZE="${MIN_SIZE:-100000}"                          # refuse-to-ship floor (bytes)

TS="$(date -u +%Y%m%dT%H%M%SZ)"
ARTIFACT="coasterpedia-db-${TS}.sql.gz.age"
TMP="${WORKDIR}/.${ARTIFACT}.partial"
mkdir -p "$WORKDIR"

# Any failure (including inside the pipe) -> alert + clean up.
fail() {
  local rc=$?
  curl -fsS -m 10 --retry 3 "${HC_URL}/fail" >/dev/null 2>&1 || true
  rm -f "$TMP"
  exit "$rc"
}
trap fail ERR

curl -fsS -m 10 --retry 3 "${HC_URL}/start" >/dev/null 2>&1 || true

# Consistent, non-locking dump. --master-data=2 --gtid record the resume
# coordinate; --flush-logs starts a clean binlog for stage 2 to stream from.
# pipefail (set -o above) means a mariadb-dump failure fails the whole pipe,
# so a truncated-but-valid-looking encrypted file can never be shipped.
mariadb-dump \
  --defaults-extra-file="$DEFAULTS_FILE" \
  -h "$DB_HOST" \
  --single-transaction --quick --hex-blob \
  --routines --triggers --events \
  --master-data=2 --gtid --flush-logs \
  --databases $DB_NAME \
| gzip -6 \
| age -r "$AGE_RECIPIENT" \
> "$TMP"

if [ "$(stat -c%s "$TMP")" -lt "$MIN_SIZE" ]; then
  echo "Refusing to ship: artifact implausibly small ($(stat -c%s "$TMP") bytes)" >&2
  exit 1
fi
mv "$TMP" "${WORKDIR}/${ARTIFACT}"

# daily/ always; weekly/ on Sundays; monthly/ on the 1st. R2 lifecycle rules
# expire each prefix -> grandfather-father-son retention, no pruning logic here.
# Explicit ifs (not && ||) so a real rclone failure still trips the alert.
rclone copyto "${WORKDIR}/${ARTIFACT}" "${R2_REMOTE}/daily/${ARTIFACT}"
if [ "$(date -u +%u)" = "7" ]; then
  rclone copyto "${WORKDIR}/${ARTIFACT}" "${R2_REMOTE}/weekly/${ARTIFACT}"
fi
if [ "$(date -u +%d)" = "01" ]; then
  rclone copyto "${WORKDIR}/${ARTIFACT}" "${R2_REMOTE}/monthly/${ARTIFACT}"
fi

# Keep newest 3 local copies; R2 is the system of record.
ls -1t "${WORKDIR}"/coasterpedia-db-*.sql.gz.age 2>/dev/null | tail -n +4 | xargs -r rm -f

curl -fsS -m 10 --retry 3 "${HC_URL}" >/dev/null 2>&1 || true
echo "OK: ${ARTIFACT}"