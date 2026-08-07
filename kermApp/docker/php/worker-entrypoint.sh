#!/bin/bash
set -e

if [ ! -f /var/www/html/.env ]; then
    cp /var/www/html/.env.example /var/www/html/.env
fi

mkdir -p storage/framework/{sessions,views,cache} storage/logs storage/app/public bootstrap/cache

# The app container creates and migrates the database; the workers only need
# the directory to exist before they attach to the same file.
DB_FILE="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ] && [ "$DB_FILE" != ":memory:" ]; then
    mkdir -p "$(dirname "$DB_FILE")"
fi

chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

exec "$@"
