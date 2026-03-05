$ErrorActionPreference = "Stop"

Write-Host "========================================"
Write-Host "Generating HTML Coverage Report..."
Write-Host "========================================"

php artisan test --coverage-html=coverage-report

if ($LASTEXITCODE -ne 0) {
    throw "Failed to generate coverage report."
}

$reportPath = Join-Path (Get-Location) "coverage-report\index.html"
if (-not (Test-Path $reportPath)) {
    throw "coverage-report\index.html not found."
}

Write-Host ""
Write-Host "[OK] Coverage report generated: $reportPath"
Write-Host "Opening report in default browser..."
Start-Process $reportPath

