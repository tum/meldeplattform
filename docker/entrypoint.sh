#!/usr/bin/env bash
set -e

cd /var/www/html

# Ensure writable paths exist.
mkdir -p storage/framework/{cache,data,sessions,testing,views} \
         storage/logs \
         storage/app/uploads \
         bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Copy .env if missing.
if [ ! -f .env ] && [ -f .env.example ]; then
    cp .env.example .env
fi

# Generate APP_KEY if empty.
if ! grep -qE '^APP_KEY=base64:' .env 2>/dev/null; then
    php artisan key:generate --force || true
fi

# Wait for database.
if [ -n "${DB_HOST:-}" ]; then
    echo "Waiting for DB ${DB_HOST}:${DB_PORT:-3306}…"
    for _ in $(seq 1 60); do
        if php -r "try { new PDO('mysql:host=${DB_HOST};port=${DB_PORT:-3306};dbname=${DB_DATABASE}', '${DB_USERNAME}', getenv('DB_PASSWORD')); exit(0); } catch (Throwable \$e) { exit(1); }"; then
            break
        fi
        sleep 1
    done
fi

# Run migrations (idempotent).
php artisan migrate --force || true
php artisan storage:link || true

# Only cache in production – the dev login routes are conditional on
# app()->environment() and must not be baked into a cached route list.
if [ "${APP_ENV:-production}" = "production" ]; then
    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || true
else
    php artisan config:clear || true
    php artisan route:clear || true
    php artisan view:clear || true
fi

exec "$@"
