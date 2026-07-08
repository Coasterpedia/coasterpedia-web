#!/usr/bin/env bash
#
# On-prem mirror encrypted dumps and binlogs from R2 to the local SSD. 

set -euo pipefail

: "${R2_REMOTE:?set R2_REMOTE}"
: "${LOCAL_DIR:?set LOCAL_DIR}"
: "${HC_URL:?set HC_URL}"

curl -fsS -m 10 --retry 3 "${HC_URL}/start" >/dev/null 2>&1 || true

mkdir -p "$LOCAL_DIR"
# sync mirrors deletions too, so local retention tracks R2's lifecycle rules.
rclone sync "$R2_REMOTE" "$LOCAL_DIR" --fast-list

curl -fsS -m 10 --retry 3 "${HC_URL}" >/dev/null 2>&1 || true
echo "OK: mirrored ${R2_REMOTE} -> ${LOCAL_DIR}"