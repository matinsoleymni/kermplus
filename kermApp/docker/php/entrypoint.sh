#!/bin/bash
set -e

if [ ! -f /var/www/html/.env ]; then
    cp /var/www/html/.env.example /var/www/html/.env
fi

mkdir -p storage/framework/{sessions,views,cache} storage/logs storage/app/public bootstrap/cache

# DB_DATABASE lives inside the storage volume, so the file survives rebuilds
# while the image keeps ownership of database/ (migrations, seeders, factories).
DB_FILE="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ] && [ "$DB_FILE" != ":memory:" ]; then
    mkdir -p "$(dirname "$DB_FILE")"
    touch "$DB_FILE"
    chown www-data:www-data "$DB_FILE"
    chmod 664 "$DB_FILE"
fi

chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

if ! grep -q "^APP_KEY=.\+" /var/www/html/.env; then
    php artisan key:generate --force
fi

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
