#!/usr/bin/env bash
#
# Continuous binlog capture: long-lived `restart: always` service, pulling binlog
# events off the VM
#
# Resume logic: mariadb-binlog --raw rewrites whichever file you name from its
# beginning, so restarting from the newest local file is idempotent and cannot
# leave a hole. If we have nothing locally, we start from the oldest binlog the
# server still has (expire_logs_days=7 guarantees that predates the last dump).
#
# If the server has purged past our newest local file (Pi offline > 7 days),
# mariadb-binlog exits with "Could not find first log file name". That is a
# REAL GAP, not a transient error - we log it loudly and exit non-zero. The
# staleness alarm is what tells you.

set -euo pipefail

: "${DB_HOST:?set DB_HOST}"
: "${BINLOG_DIR:?set BINLOG_DIR}"
DEFAULTS_FILE="${DEFAULTS_FILE:-/secrets/backup.cnf}"
DB_PORT="${DB_PORT:-3306}"
# Must be unique across the replication topology and != the source's server_id (1).
STREAM_SERVER_ID="${STREAM_SERVER_ID:-99}"

mkdir -p "$BINLOG_DIR"

# Newest local binlog, if any. Sort is lexical, which is correct for the
# zero-padded mariadb-bin.NNNNNN naming.
START_FILE="$(ls -1 "$BINLOG_DIR"/mariadb-bin.[0-9]* 2>/dev/null | sort | tail -1 | xargs -r basename || true)"

if [ -z "$START_FILE" ]; then
  echo "No local binlogs; starting from the oldest binlog on the server."
  START_FILE="$(mariadb --defaults-extra-file="$DEFAULTS_FILE" \
      -h "$DB_HOST" -P "$DB_PORT" -N -B -e 'SHOW BINARY LOGS;' \
    | head -1 | awk '{print $1}')"
  [ -n "$START_FILE" ] || { echo "FATAL: server reports no binary logs" >&2; exit 1; }
fi

echo "Streaming from ${START_FILE} -> ${BINLOG_DIR}"

# --raw          write binary files, not SQL text (exact, replayable)
# --stop-never   follow the log forever, like a replica
# --result-file  MUST end in / to be treated as an output directory prefix
exec mariadb-binlog \
  --defaults-extra-file="$DEFAULTS_FILE" \
  --read-from-remote-server \
  -h "$DB_HOST" -P "$DB_PORT" \
  --raw \
  --stop-never \
  --stop-never-slave-server-id="$STREAM_SERVER_ID" \
  --result-file="${BINLOG_DIR}/" \
  "$START_FILE"