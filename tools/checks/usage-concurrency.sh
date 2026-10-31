#!/usr/bin/env bash
# ADR-0074 §Vérification — real concurrency proof for the quota authority.
#
# The Pest suite runs inside one transaction, so it cannot race two
# connections. This script does: N separate PHP processes spend the last
# unit of a limit-1 plan at the time, against the test database.
# Expected: exactly one `admitted`, N-1 `quota_exceeded`, counter = 1.
#
#   tools/checks/usage-concurrency.sh [processes=50] [parallel=25]
#
# Two runners, one proof:
#
#   - from the developer's host, the commands run inside the `app`
#     container (the project is Docker-first, ADR-0019);
#   - inside the CI container the application is already right there, so
#     `IN_APP_CONTAINER=1` runs `php artisan` locally — the CI job has no
#     Docker daemon inside its container.
set -euo pipefail

N="${1:-50}"
PAR="${2:-25}"
BOOT="require 'tools/checks/usage-concurrency.php';"
export BOOT

# boot MODE [NAME=VALUE]… — runs the helper with its environment.
boot() {
  local mode="$1"; shift
  local pairs=("MODE=$mode" "$@")

  if [ "${IN_APP_CONTAINER:-0}" = "1" ]; then
    env "${pairs[@]}" php artisan tinker --execute="$BOOT"
    return
  fi

  local flags=(-T -e DB_DATABASE=core_testing)
  for pair in "${pairs[@]}"; do flags+=(-e "$pair"); done
  docker compose exec "${flags[@]}" app php artisan tinker --execute="$BOOT"
}
export -f boot

OUT=$(boot setup)
PROJECT=$(echo "$OUT" | grep -o 'PROJECT=[0-9a-f-]*' | cut -d= -f2)
[ -n "$PROJECT" ] || { echo "setup failed: $OUT"; exit 1; }
export PROJECT

RESULTS=$(seq 1 "$N" | xargs -P "$PAR" -I{} bash -c 'boot run "PROJECT=$PROJECT" "K={}"' 2>&1 | grep -o 'RESULT=[a-z_]*' | sort | uniq -c)
echo "$RESULTS"

ADMITTED=$(echo "$RESULTS" | awk '/admitted/ {print $1}')
if [ "${ADMITTED:-0}" != "1" ]; then
  echo "FAIL: expected exactly 1 admitted call, got ${ADMITTED:-0}"; exit 1
fi
echo "OK: exactly one call spent the last unit."
