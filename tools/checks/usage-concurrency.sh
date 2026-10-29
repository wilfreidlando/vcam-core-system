#!/usr/bin/env bash
# ADR-0074 §Vérification — real concurrency proof for the quota authority.
#
# The Pest suite runs inside one transaction, so it cannot race two
# connections. This script does: N separate PHP processes spend the last
# unit of a limit-1 plan at the same time, against the test database.
# Expected: exactly one `admitted`, N-1 `quota_exceeded`, counter = 1.
#
#   tools/checks/usage-concurrency.sh [processes=50] [parallel=25]
set -euo pipefail

N="${1:-50}"
PAR="${2:-25}"
RUN=(docker compose exec -T -e DB_DATABASE=core_testing)
BOOT="require 'tools/checks/usage-concurrency.php';"

OUT=$("${RUN[@]}" -e MODE=setup app php artisan tinker --execute="$BOOT" 2>&1)
PROJECT=$(echo "$OUT" | grep -o 'PROJECT=[0-9a-f-]*' | cut -d= -f2)
[ -n "$PROJECT" ] || { echo "setup failed: $OUT"; exit 1; }

RESULTS=$(seq 1 "$N" | xargs -P "$PAR" -I{} "${RUN[@]}" -e MODE=run -e PROJECT="$PROJECT" -e K={} app \
  php artisan tinker --execute="$BOOT" 2>&1 | grep -o 'RESULT=[a-z_]*' | sort | uniq -c)
echo "$RESULTS"

ADMITTED=$(echo "$RESULTS" | awk '/admitted/ {print $1}')
if [ "${ADMITTED:-0}" != "1" ]; then
  echo "FAIL: expected exactly 1 admitted call, got ${ADMITTED:-0}"; exit 1
fi
echo "OK: exactly one call spent the last unit."
