$ErrorActionPreference = "Stop"

$trackerPath = Join-Path (Get-Location) "coverage-tracker.txt"

if (-not (Test-Path $trackerPath)) {
    Set-Content -Path $trackerPath -Value "# Coverage history`n# Format: timestamp`tvalue"
}

$timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
$coverageLine = (php artisan test --coverage | Select-String -Pattern "Total Coverage|Total:" | Select-Object -Last 1).ToString().Trim()

Add-Content -Path $trackerPath -Value "$timestamp`t$coverageLine"

Write-Host "[OK] Coverage entry appended:"
Get-Content $trackerPath -Tail 1

