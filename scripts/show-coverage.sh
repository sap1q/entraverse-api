#!/usr/bin/env bash
set -euo pipefail

echo "========================================"
echo "Generating HTML Coverage Report..."
echo "========================================"

php artisan test --coverage-html=coverage-report

if [[ ! -f "coverage-report/index.html" ]]; then
  echo ""
  echo "[ERROR] coverage-report/index.html not found."
  exit 1
fi

echo ""
echo "[OK] Coverage report generated: coverage-report/index.html"
echo "Opening report in default browser..."

if command -v open >/dev/null 2>&1; then
  open coverage-report/index.html
elif command -v xdg-open >/dev/null 2>&1; then
  xdg-open coverage-report/index.html
else
  echo "[WARN] Could not detect browser opener command. Open this file manually:"
  echo "  coverage-report/index.html"
fi

