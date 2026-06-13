#!/usr/bin/env bash
set -euo pipefail

pids=()

cleanup() {
    for pid in "${pids[@]}"; do
        kill "$pid" 2>/dev/null || true
    done

    wait "${pids[@]}" 2>/dev/null || true
}

trap cleanup EXIT INT TERM

php artisan serve &
pids+=("$!")

php artisan queue:listen --tries=1 --timeout=0 &
pids+=("$!")

php artisan pail --timeout=0 &
pids+=("$!")

npm run dev &
pids+=("$!")

set +e
wait -n "${pids[@]}"
status=$?
set -e

exit "$status"
