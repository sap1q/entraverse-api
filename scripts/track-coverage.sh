#!/usr/bin/env bash
set -euo pipefail

TRACKER_FILE="coverage-tracker.txt"

if [[ ! -f "$TRACKER_FILE" ]]; then
  {
    echo "# Coverage history"
    echo "# Format: timestamp	value"
  } > "$TRACKER_FILE"
fi

timestamp="$(date '+%Y-%m-%d %H:%M:%S')"
coverage_line="$(php artisan test --coverage | grep -E 'Total Coverage|Total:' | tail -n 1 | xargs)"

echo -e "${timestamp}\t${coverage_line}" >> "$TRACKER_FILE"
echo "[OK] Coverage entry appended:"
tail -n 1 "$TRACKER_FILE"

