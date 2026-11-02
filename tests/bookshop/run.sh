#!/bin/bash
# Bookshop package acceptance (#240): build bareboneseshop-bookshop.zip from REF (default HEAD), unpack it into a clean
# php:<PHP_IMAGE>-apache container the way a hosting customer would (no shell inside the app, no cron), then drive the
# shop through tests/bookshop/bookshop.spec.js in Chromium. Needs docker, php (to build), node with @playwright/test.
# Usage: tests/bookshop/run.sh [--keep | --prepare-only]   env: REF, PHP_IMAGE (8.4), PORT (8740), OUT (zip + screenshots directory)
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
REF=${REF:-HEAD}; PHP_IMAGE=${PHP_IMAGE:-8.4}; PORT=${PORT:-8740}; NAME=bbe-bookshop-acceptance
OUT=${OUT:-$ROOT/test-results/bookshop-package}
rm -rf "$OUT" && mkdir -p "$OUT/unpacked"
php "$ROOT/demo/bookshop/package.php" --out="$OUT" --ref="$REF"
php -r '$z = new ZipArchive; if ($z->open($argv[1]) !== true || !$z->extractTo($argv[2])) exit(1);' "$OUT/bareboneseshop-bookshop.zip" "$OUT/unpacked"

docker rm -f "$NAME" >/dev/null 2>&1 || true
docker run -d --name "$NAME" -p "127.0.0.1:$PORT:80" "php:$PHP_IMAGE-apache" >/dev/null
KEEP=${1:-}
trap '[ "$KEEP" = "--keep" ] || docker rm -f "$NAME" >/dev/null 2>&1 || true' EXIT
# What a typical Apache host offers: mod_rewrite/headers and .htaccess overrides. The private data folder lives
# outside the web root and is writable by the web server user (the README's step for php:apache).
docker exec "$NAME" sh -c 'a2enmod rewrite headers expires >/dev/null && sed -i "s/AllowOverride None/AllowOverride All/" /etc/apache2/apache2.conf \
  && mkdir -p /srv/bookshop-data && chown www-data:www-data /srv/bookshop-data && apache2ctl -k graceful'
docker cp "$OUT/unpacked/bareboneseshop-bookshop/." "$NAME:/var/www/html/"
docker exec "$NAME" chown -R www-data:www-data /var/www/html
for _ in $(seq 1 30); do curl -fs -o /dev/null "http://127.0.0.1:$PORT/install.php" && break; sleep 1; done
if [ "$KEEP" = "--prepare-only" ]; then trap - EXIT; echo "Ready: http://127.0.0.1:$PORT (container $NAME)"; exit 0; fi

cd "$ROOT"
BOOKSHOP_URL="http://127.0.0.1:$PORT" BOOKSHOP_SHOTS="$OUT/screenshots" BOOKSHOP_PRIVATE_PARENT=/srv/bookshop-data \
  npx playwright test -c tests/bookshop/playwright.config.js
docker exec "$NAME" sh -c 'ls /srv/bookshop-data/bareboneseshop-private/jobs; tail -n 5 /var/log/apache2/error.log' || true
echo "Package: $OUT/bareboneseshop-bookshop.zip  Screenshots: $OUT/screenshots"
