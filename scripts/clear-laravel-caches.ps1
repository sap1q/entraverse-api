#!/usr/bin/env pwsh

# Clear all Laravel caches and regenerate compiled files.
php artisan optimize:clear
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan view:clear
php artisan event:clear

Write-Host "Laravel caches cleared." -ForegroundColor Green
