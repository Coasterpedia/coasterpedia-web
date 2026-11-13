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
#
# Stall watchdog: if the network path dies under an open connection (Tailscale
# or Docker restarting during apt upgrade), the server's FIN never arrives and
# mariadb-binlog blocks on read forever without exiting, so `restart: always`
# never fires. The VM's heartbeat job writes an event every minute, so a live
# stream always touches the newest file at least that often. When nothing has
# landed for STALL_SECONDS, we kill the stream and exit non-zero, and Docker
# starts a fresh one. Keep STALL_SECONDS under binlog-staleness.sh's 600s so a
# stall heals before it pages.

set -euo pipefail

: "${DB_HOST:?set DB_HOST}"
: "${BINLOG_DIR:?set BINLOG_DIR}"
DEFAULTS_FILE="${DEFAULTS_FILE:-/secrets/backup.cnf}"
DB_PORT="${DB_PORT:-3306}"
# Must be unique across the replication topology and != the source's server_id (1).
STREAM_SERVER_ID="${STREAM_SERVER_ID:-99}"
STALL_SECONDS="${STALL_SECONDS:-300}"
WATCHDOG_INTERVAL="${WATCHDOG_INTERVAL:-30}"

log() { echo "$(date -u '+%Y-%m-%d %H:%M:%S') $*"; }

mkdir -p "$BINLOG_DIR"

# Newest local binlog, if any. Sort is lexical, which is correct for the
# zero-padded mariadb-bin.NNNNNN naming.
START_FILE="$(ls -1 "$BINLOG_DIR"/mariadb-bin.[0-9]* 2>/dev/null | sort | tail -1 | xargs -r basename || true)"

if [ -z "$START_FILE" ]; then
  log "No local binlogs; starting from the oldest binlog on the server."
  START_FILE="$(mariadb --defaults-extra-file="$DEFAULTS_FILE" \
      -h "$DB_HOST" -P "$DB_PORT" -N -B -e 'SHOW BINARY LOGS;' \
    | head -1 | awk '{print $1}')"
  [ -n "$START_FILE" ] || { log "FATAL: server reports no binary logs" >&2; exit 1; }
fi

log "Streaming from ${START_FILE} -> ${BINLOG_DIR} (stall limit ${STALL_SECONDS}s)"

# --raw          write binary files, not SQL text (exact, replayable)
# --stop-never   follow the log forever, like a replica
# --result-file  MUST end in / to be treated as an output directory prefix
mariadb-binlog \
  --defaults-extra-file="$DEFAULTS_FILE" \
  --read-from-remote-server \
  -h "$DB_HOST" -P "$DB_PORT" \
  --raw \
  --stop-never \
  --stop-never-slave-server-id="$STREAM_SERVER_ID" \
  --result-file="${BINLOG_DIR}/" \
  "$START_FILE" &
STREAM_PID=$!

# tini (init: true) signals only this script, so pass `docker stop` on.
trap 'kill -TERM "$STREAM_PID" 2>/dev/null || true; wait "$STREAM_PID" 2>/dev/null || true; exit 143' TERM INT

STARTED_AT=$(date +%s)

# Seconds since anything landed. Counted from our own start too, so the old
# mtime of a file we are about to re-download doesn't trip the limit at once.
stall_age() {
  local newest mtime now
  now=$(date +%s)
  newest="$(ls -1t "$BINLOG_DIR"/mariadb-bin.[0-9]* 2>/dev/null | head -1 || true)"
  mtime=0
  [ -n "$newest" ] && mtime=$(stat -c %Y "$newest" 2>/dev/null || echo 0)
  [ "$mtime" -lt "$STARTED_AT" ] && mtime=$STARTED_AT
  echo $(( now - mtime ))
}

# `sleep & wait` rather than plain sleep, so the trap above fires at once.
while kill -0 "$STREAM_PID" 2>/dev/null; do
  sleep "$WATCHDOG_INTERVAL" & wait $! || true
  kill -0 "$STREAM_PID" 2>/dev/null || break

  AGE=$(stall_age)
  if [ "$AGE" -gt "$STALL_SECONDS" ]; then
    log "Stream stalled: nothing written for ${AGE}s. Killing it so Docker restarts a fresh connection." >&2
    kill -TERM "$STREAM_PID" 2>/dev/null || true
    sleep 5
    kill -KILL "$STREAM_PID" 2>/dev/null || true
    wait "$STREAM_PID" 2>/dev/null || true
    exit 75
  fi
done

RC=0
wait "$STREAM_PID" || RC=$?
log "mariadb-binlog exited with status ${RC}" >&2
exit "$RC"
