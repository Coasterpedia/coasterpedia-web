#!/usr/bin/env bash
#
# Heartbeat writer. Runs on the VM (Ofelia job-exec into the dr container),
# once a minute.
#
# Why: the drill needs to prove that binlog replay reached HEAD. Asserting on
# max(rev_timestamp) can't do that - a quiet Tuesday with no edits looks
# identical to a broken replay. A row we write every minute, unconditionally,
# turns "is the restore fresh?" from an inference into an exact lag number.
#
# It lives in the `coasterpedia` database (NOT coasterpedia_tools) because
# replay is scoped with `mariadb-binlog --database=coasterpedia`, which can only
# be given once. Anything outside that database is never replayed, so a
# heartbeat elsewhere would sit frozen at dump time and prove nothing.
#
# The `dr_` prefix (vs MediaWiki's `mw_`) keeps it invisible to MediaWiki.
#
# UTC_TIMESTAMP throughout so the drill's comparison is timezone-independent.

set -euo pipefail

: "${DB_HOST:?set DB_HOST}"
DEFAULTS_FILE="${DEFAULTS_FILE:-/secrets/backup.cnf}"
DB_PORT="${DB_PORT:-3306}"

exec mariadb --defaults-extra-file="$DEFAULTS_FILE" -h "$DB_HOST" -P "$DB_PORT" \
  -e "REPLACE INTO coasterpedia.dr_heartbeat (id, ts) VALUES (1, UTC_TIMESTAMP(6));"