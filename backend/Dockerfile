# Production image for the Yemeni Smart Teacher API (Laravel 13 / PHP 8.4).
FROM php:8.4-cli

# System deps + PHP extensions used by the app (PDF parsing, OCR, mbstring, intl, gd, db).
# pdo_pgsql is the production driver: the database is Supabase PostgreSQL.
# poppler-utils/ghostscript/tesseract are required at runtime to render textbook
# pages and OCR scanned books, so they are installed in the image (not dev-only).
# python3 is for the optional Crawlee source-discovery helper (tools/crawlee_service).
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip pkg-config \
        python3 python3-pip python3-venv \
        libzip-dev libicu-dev libpng-dev libjpeg-dev libfreetype6-dev libonig-dev \
        libpq-dev libsqlite3-dev libxml2-dev \
        postgresql-client poppler-utils ghostscript \
        tesseract-ocr tesseract-ocr-ara \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pdo_sqlite mbstring bcmath gd intl zip xml \
    && rm -rf /var/lib/apt/lists/*

# Optional Crawlee source-discovery helper in an isolated venv. Installed with
# `|| true`: discovery is a convenience, and a failed install (e.g. no network
# at build time) must never break the image - the app simply skips discovery.
RUN python3 -m venv /opt/crawlee \
    && (/opt/crawlee/bin/pip install --no-cache-dir 'crawlee[beautifulsoup]>=1.11,<2' || true)
ENV CRAWLEE_PYTHON=/opt/crawlee/bin/python

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
