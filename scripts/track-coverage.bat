@echo off
setlocal

if not exist coverage-tracker.txt (
  echo # Coverage history> coverage-tracker.txt
  echo # Format: timestamp	value>> coverage-tracker.txt
)

for /f "usebackq delims=" %%L in (`powershell -NoProfile -Command "(php artisan test --coverage | Select-String -Pattern 'Total Coverage|Total:' | Select-Object -Last 1).ToString().Trim()"`) do set COVERAGE_LINE=%%L
for /f "usebackq delims=" %%T in (`powershell -NoProfile -Command "Get-Date -Format 'yyyy-MM-dd HH:mm:ss'"`) do set NOW_TS=%%T

echo %NOW_TS%	%COVERAGE_LINE%>> coverage-tracker.txt
echo [OK] Coverage entry appended:
powershell -NoProfile -Command "Get-Content coverage-tracker.txt -Tail 1"

endlocal

