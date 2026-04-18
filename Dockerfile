# syntax=docker/dockerfile:1.7

# --- Runtime image (PHP-FPM + nginx) ---
FROM php:8.3-fpm-alpine AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

RUN apk add --no-cache \
        nginx supervisor \
        libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev \
        libxml2-dev libzip-dev oniguruma-dev \
        icu-dev mariadb-connector-c-dev tzdata bash \
        curl git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo pdo_mysql mbstring gd exif bcmath zip intl opcache

# Install composer (for runtime + one-off commands)
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

# Copy application sources.
COPY . /var/www/html

# Install PHP dependencies and set writable paths.
RUN composer install \
        --no-dev --optimize-autoloader --no-interaction --no-progress --no-security-blocking \
    && mkdir -p storage/framework/cache/data \
               storage/framework/sessions \
               storage/framework/testing \
               storage/framework/views \
               storage/logs \
               storage/app/uploads \
               storage/app/public \
               bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# nginx + supervisor config
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
