# BareBones Shop — sample PHP-FPM image (used by deploy/docker-compose.yml).
# The official image already ships pdo_sqlite, mbstring, dom, libxml and sodium.
FROM php:8.4-fpm

RUN docker-php-ext-install pdo_mysql \
    && mkdir -p /var/www/bareboneseshop-private \
    && chown www-data:www-data /var/www/bareboneseshop-private

WORKDIR /var/www/html
