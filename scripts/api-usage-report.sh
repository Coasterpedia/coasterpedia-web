#!/usr/bin/env bash
# Sums the API usage counts LocalSettings.php writes to Redis (db 2, one hash
# per UTC day) into a TSV, busiest first. Run on the docker host.
#
#   scripts/api-usage-report.sh                # every day still held
#   scripts/api-usage-report.sh '2026-10-1*'   # a glob over the dates
#
# docker exec, not docker compose exec: compose would need deploy.yml's env.
set -euo pipefail

days="${1:-*}"
redis=(docker exec coasterpedia-redis-1 redis-cli -n 2 --raw)

printf 'count\tentry\ttier\tsource\tclient\tcall\n'
"${redis[@]}" --scan --pattern "cp:apiusage:${days}" |
	while read -r key; do
		"${redis[@]}" HGETALL "$key"
	done |
	awk 'NR % 2 { field = $0; next } { sum[field] += $0 }
		END { for ( f in sum ) printf "%d\t%s\n", sum[f], f }' |
	sort -t $'\t' -k1,1nr
