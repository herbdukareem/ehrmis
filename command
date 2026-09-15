php artisan operational-imports:retry-staff-list 2
php artisan legacy:generate-staff-numbers 2 --user=1 --dry-run
php artisan legacy:generate-staff-numbers 2 --user=1
php -d memory_limit=512M artisan queue:work database --queue=default --sleep=1 --rest=1 --tries=1 --timeout=1800 --memory=512
