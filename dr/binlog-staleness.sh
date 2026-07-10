#!/usr/bin/env bash
#
# RPO alarm - checks if newest binlog was modified recently
#
# Two independent failure modes are covered:
#   - stream broken  -> file stops advancing -> we ping /fail
#   - this job dead  -> no ping at all       -> healthchecks period alarm fires
#
# Note: mtime advances whenever ANY event lands. The heartbeat writer on the VM
# fires every minute, so a healthy stream always has a fresh mtime even when
# nobody is editing. That is precisely why the heartbeat exists.

set -euo pipefail

: "${BINLOG_DIR:?set BINLOG_DIR}"
: "${HC_URL:?set HC_URL}"
MAX_AGE_SECONDS="${MAX_AGE_SECONDS:-600}"   # 10 min; well inside the 15-min RPO target

fail() {
  echo "$1" >&2
  curl -fsS -m 10 --retry 3 "${HC_URL}/fail" -d "$1" >/dev/null 2>&1 || true
  exit 1
}

NEWEST="$(ls -1t "$BINLOG_DIR"/mariadb-bin.[0-9]* 2>/dev/null | head -1 || true)"
[ -n "$NEWEST" ] || fail "No binlogs present in ${BINLOG_DIR} - streamer has never run?"

AGE=$(( $(date +%s) - $(stat -c %Y "$NEWEST") ))
if [ "$AGE" -gt "$MAX_AGE_SECONDS" ]; then
  fail "Binlog stream is STALE: $(basename "$NEWEST") last written ${AGE}s ago (limit ${MAX_AGE_SECONDS}s). RPO is degraded."
fi

curl -fsS -m 10 --retry 3 "${HC_URL}" >/dev/null 2>&1 || true
echo "OK: binlog stream fresh (${AGE}s)"