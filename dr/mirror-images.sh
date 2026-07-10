#!/usr/bin/env bash
#
# Mirror image ORIGINALS from R2 to the DR host's SSD
#
# Originals live at the bucket root; thumb/ and temp/ are sub-prefixes.
# Thumbnails regenerate from originals, and temp/ is transient, so both are
# excluded
#
# --fast-list keeps R2 class-A operations (and cost) down on a large bucket.

set -euo pipefail

: "${R2_IMAGES_REMOTE:?set R2_IMAGES_REMOTE}"   # e.g. r2:coasterpedia-images
: "${LOCAL_IMAGE_DIR:?set LOCAL_IMAGE_DIR}"
: "${HC_URL:?set HC_URL}"

fail() { curl -fsS -m 10 --retry 3 "${HC_URL}/fail" >/dev/null 2>&1 || true; exit 1; }
trap fail ERR

curl -fsS -m 10 --retry 3 "${HC_URL}/start" >/dev/null 2>&1 || true

mkdir -p "$LOCAL_IMAGE_DIR"
rclone sync "$R2_IMAGES_REMOTE" "$LOCAL_IMAGE_DIR" \
  --exclude 'thumb/**' \
  --exclude 'temp/**' \
  --fast-list \
  --transfers 4 \
  --checkers 8 \
  --stats-one-line

curl -fsS -m 10 --retry 3 "${HC_URL}" >/dev/null 2>&1 || true
echo "OK: image originals mirrored to ${LOCAL_IMAGE_DIR}"