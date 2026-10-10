#!/usr/bin/env bash
# Drives cpCargoRebuild --populate on the docker host until the table is done,
# so a long rebuild survives memory limits and container restarts unattended.
#
#   scripts/cargo-rebuild.sh Water_Slides                 # populate, resuming
#   scripts/cargo-rebuild.sh Water_Slides --sleep=200     # gentler on the DB
#   nohup scripts/cargo-rebuild.sh Rides > rides.log 2>&1 &
#
# Resumes on exit 75 (the script stopped cleanly) and when the MediaWiki
# container was replaced mid-run (a deploy). Anything else stops it: read the
# log, fix, and run it again; progress is kept.
#
# The other steps are run directly:
#   docker exec -u www-data coasterpedia-mediawiki-1 php /var/www/html/w/maintenance/run.php \
#     cpCargoRebuild --table=Water_Slides --create | --status | --retry-failed | --compare | --resync
set -uo pipefail

table=${1:?usage: $0 Table [--sleep=ms] [--shard=i/n] [--retries=n]}
shift
container=coasterpedia-mediawiki-1

started() { docker inspect -f '{{.State.Running}} {{.State.StartedAt}}' "$container" 2>/dev/null; }

while true; do
	before=$(started)
	docker exec -u www-data "$container" \
		php /var/www/html/w/maintenance/run.php cpCargoRebuild --table="$table" --populate "$@"
	code=$?
	if [ "$code" -eq 0 ]; then
		echo "Populate finished for $table."
		exit 0
	fi
	if [ "$code" -eq 75 ]; then
		echo "Stopped cleanly; resuming."
		continue
	fi
	# Container gone or restarted under us (a deploy): wait for it, then resume.
	after=$(started)
	if [ "$after" != "$before" ] || [ "${after%% *}" != "true" ]; then
		echo "Container changed (exit $code); waiting for it to come back."
		until [ "$(started | cut -d' ' -f1)" = "true" ]; do sleep 15; done
		sleep 30
		continue
	fi
	echo "Stopped with exit $code; progress is saved. Fix and rerun." >&2
	exit "$code"
done
