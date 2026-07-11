## Multi-stage build for the Laravel app (RoadRunner + Octane)
FROM node:20-alpine AS frontend
WORKDIR /var/www/html

# Install JS deps and build assets
COPY package*.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

# Base PHP image with required extensions (including sockets)
FROM php:8.4-fpm-alpine AS php-base
WORKDIR /var/www/html
RUN apk add --no-cache icu libzip oniguruma libxml2 sqlite-libs git bash curl \
    && apk add --no-cache --virtual .build-deps icu-dev libzip-dev oniguruma-dev libxml2-dev sqlite-dev linux-headers \
    # Added pdo_mysql here \/
    && docker-php-ext-install pdo pdo_sqlite pdo_mysql intl zip opcache sockets pcntl \
    && apk del .build-deps \
    # Install Composer in this image
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install PHP deps with scripts enabled (artisan available after copy)
FROM php-base AS vendor
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Final runtime
FROM php-base AS runtime
WORKDIR /var/www/html

# Copy app code and built assets
COPY --from=vendor /var/www/html /var/www/html
COPY --from=frontend /var/www/html/public/build /var/www/html/public/build

# Download RoadRunner binary for Octane
RUN php ./vendor/bin/rr get-binary --no-interaction --stability=stable \
    && chmod +x rr

# Ensure writable dirs
RUN mkdir -p storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

EXPOSE 8000
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--port=8000"]
