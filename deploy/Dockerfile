# BareBones Shop — sample PHP-FPM image (used by deploy/docker-compose.yml).
# The official image already ships pdo_sqlite, mbstring, dom, libxml and sodium.
# GD (JPEG/PNG/WebP) builds product image variants; the MariaDB client provides
# mysqldump/mariadb-dump for MySQL backups and update snapshots.
FROM php:8.4-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev mariadb-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd pdo_mysql \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /var/www/bareboneseshop-private \
    && chown www-data:www-data /var/www/bareboneseshop-private \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Production error handling: nothing is displayed to visitors, everything is logged.
COPY php.ini "$PHP_INI_DIR/conf.d/zz-bareboneseshop.ini"

# PHP-FPM listens on 9000, so neither the master nor the workers need root.
USER www-data
WORKDIR /var/www/html
