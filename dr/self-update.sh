#!/usr/bin/env bash
#
# Self-update. Runs on the HOST (systemd timer)
#
# Pulls the repo (which now carries versions.env, committed by CI), pulls the
# pinned images, and reconciles the running stack. --profile is deliberately
# NOT passed, so the drill-profile services stay down; only the persistent
# services (ofelia, dr, binlog-streamer) are (re)started.
#
# Healthchecks: pings /start, then success or /fail. A self-update that quietly
# stops running is the nastiest silent failure here - the Pi drifts away from
# production while every other alarm stays green - so it gets a dead-man's
# switch like everything else. Set HC_SELFUPDATE_URL in the environment
# (systemd unit) below.

set -euo pipefail

# ---- config ----------------------------------------------------------------
REPO_DIR="${REPO_DIR:-/home/ubuntu/coasterpedia-web}"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.dr.yml}"
ENV_FILE="${ENV_FILE:-.env}"
VERSIONS_FILE="${VERSIONS_FILE:-versions.env}"
BRANCH="${BRANCH:-develop}"
HC_URL="${HC_SELFUPDATE_URL:-}"
# ----------------------------------------------------------------------------

ping() { [ -n "$HC_URL" ] && curl -fsS -m 10 --retry 3 "${HC_URL}${1:-}" >/dev/null 2>&1 || true; }

fail() {
  local msg="$1"
  echo "self-update FAILED: ${msg}" >&2
  [ -n "$HC_URL" ] && curl -fsS -m 10 --retry 3 "${HC_URL}/fail" --data-raw "$msg" >/dev/null 2>&1 || true
  exit 1
}
trap 'fail "error on line $LINENO"' ERR

ping "/start"

cd "$REPO_DIR" || fail "repo dir ${REPO_DIR} missing"

compose() {
  docker compose --env-file "$ENV_FILE" --env-file "$VERSIONS_FILE" -f "$COMPOSE_FILE" "$@"
}

# --ff-only: never create a merge commit. If the remote was
# force-pushed/rebased, this fails loudly rather than silently diverging - which
# is what you want, since it should be a faithful mirror of the branch.
echo "Pulling ${BRANCH}..."
git fetch --prune origin "$BRANCH"
git pull --ff-only origin "$BRANCH" || fail "git pull not fast-forward - Pi has diverged from ${BRANCH}"

[ -f "$VERSIONS_FILE" ] || fail "${VERSIONS_FILE} not present after pull - did CI commit it?"

echo "Pulling pinned images..."
compose pull

echo "Reconciling stack (persistent services only; drill profile stays down)..."
compose up -d --remove-orphans

# Optional tidy: reclaim space from images the pull superseded. Safe because it
# only removes dangling images, not the pinned ones in use.
docker image prune -f >/dev/null 2>&1 || true

ping ""   # success
echo "self-update OK: $(git rev-parse --short HEAD)"