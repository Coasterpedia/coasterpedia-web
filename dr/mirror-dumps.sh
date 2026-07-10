#!/usr/bin/env bash
#
# Pull the encrypted base dumps down from R2 to the DR host's disk.
#
# `sync` mirrors deletions, so local retention tracks R2's lifecycle rules
# automatically (8d daily / 40d weekly / 400d monthly).

set -euo pipefail

: "${R2_REMOTE:?set R2_REMOTE}"
: "${LOCAL_DIR:?set LOCAL_DIR}"
: "${HC_URL:?set HC_URL}"

fail() { curl -fsS -m 10 --retry 3 "${HC_URL}/fail" >/dev/null 2>&1 || true; exit 1; }
trap fail ERR

curl -fsS -m 10 --retry 3 "${HC_URL}/start" >/dev/null 2>&1 || true

mkdir -p "$LOCAL_DIR"
rclone sync "$R2_REMOTE" "$LOCAL_DIR" --fast-list --stats-one-line

curl -fsS -m 10 --retry 3 "${HC_URL}" >/dev/null 2>&1 || true
echo "OK: mirrored ${R2_REMOTE} -> ${LOCAL_DIR}"