# Production image for the Yemeni Smart Teacher API (Laravel 13 / PHP 8.4).
FROM php:8.4-cli

# System deps + PHP extensions used by the app (PDF parsing, mbstring, intl, gd, db).
# pdo_pgsql is the production driver: the database is Supabase PostgreSQL.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libicu-dev libpng-dev libonig-dev \
        postgresql-client \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pdo_sqlite mbstring bcmath gd intl zip xml \
    && rm -rf /var/lib/apt/lists/*

# Composer (from the official image).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP dependencies first for better layer caching.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader || \
    composer install --no-dev --no-scripts --no-interaction --prefer-dist

# Application code.
COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Default to SQLite so the image also runs with zero external services; in
# production set DB_CONNECTION=pgsql + the Supabase connection variables.
ENV APP_ENV=production \
    APP_DEBUG=false \
    DB_CONNECTION=sqlite \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=sync \
    PORT=8080

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
