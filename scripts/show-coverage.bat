@echo off
setlocal

echo ========================================
echo Generating HTML Coverage Report...
echo ========================================

php artisan test --coverage-html=coverage-report

if %errorlevel% neq 0 (
    echo.
    echo [ERROR] Failed to generate coverage report.
    exit /b 1
)

if not exist coverage-report\index.html (
    echo.
    echo [ERROR] coverage-report\index.html not found.
    exit /b 1
)

echo.
echo [OK] Coverage report generated: coverage-report\index.html
echo Opening report in default browser...
start "" coverage-report\index.html

endlocal

