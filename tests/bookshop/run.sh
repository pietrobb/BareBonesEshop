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
# R15: every entry carries Unix modes, none group/world-writable (an entry without them unpacks as 0777 on many hosts).
php -r '$z = new ZipArchive; $z->open($argv[1]); $bad = [];
  for ($i = 0; $i < $z->numFiles; $i++) { $z->getExternalAttributesIndex($i, $os, $attr); $mode = ($attr >> 16) & 0777;
    if ($os !== ZipArchive::OPSYS_UNIX || $mode === 0 || ($mode & 0022) !== 0) $bad[] = sprintf("%s %o", $z->getNameIndex($i), $mode); }
  if ($bad) { fwrite(STDERR, count($bad) . " zip entries without safe Unix modes, e.g. " . implode(", ", array_slice($bad, 0, 5)) . "\n"); exit(1); }
  echo "zip modes: " . $z->numFiles . " entries, none writable by group or others\n";' "$OUT/bareboneseshop-bookshop.zip"

docker rm -f "$NAME" >/dev/null 2>&1 || true
docker run -d --name "$NAME" -p "127.0.0.1:$PORT:80" "php:$PHP_IMAGE-apache" >/dev/null
KEEP=${1:-}
trap '[ "$KEEP" = "--keep" ] || docker rm -f "$NAME" >/dev/null 2>&1 || true' EXIT
# What a typical Apache host offers: mod_rewrite/headers and .htaccess overrides. The private data folder lives
# outside the web root and is writable by the web server user (the README's step for php:apache).
docker exec "$NAME" sh -c 'a2enmod rewrite headers expires >/dev/null && sed -i "s/AllowOverride None/AllowOverride All/" /etc/apache2/apache2.conf \
  && mkdir -p /srv/bookshop-data && chown www-data:www-data /srv/bookshop-data && apache2ctl -k graceful'
docker cp "$OUT/unpacked/bareboneseshop-bookshop/." "$NAME:/var/www/html/"
# Uploaded by an FTP account that is neither www-data nor in its group; the web root is group-writable for PHP.
docker exec "$NAME" sh -c 'useradd -M uploader && chown -R uploader:www-data /var/www/html && chmod -R g+w /var/www/html'
for _ in $(seq 1 30); do curl -fs -o /dev/null "http://127.0.0.1:$PORT/install.php" && break; sleep 1; done
# The first visit wrote install-key.php (as www-data); the owner reads it with the uploading account.
KEY=$(docker exec -u uploader "$NAME" php -r 'echo require "/var/www/html/install-key.php";')
if [ "$KEEP" = "--prepare-only" ]; then trap - EXIT; echo "Ready: http://127.0.0.1:$PORT (container $NAME), installation key $KEY"; exit 0; fi

cd "$ROOT"
BOOKSHOP_URL="http://127.0.0.1:$PORT" BOOKSHOP_CONTAINER="$NAME" BOOKSHOP_SHOTS="$OUT/screenshots" BOOKSHOP_PRIVATE_PARENT=/srv/bookshop-data BOOKSHOP_INSTALL_KEY="$KEY" \
  npx playwright test -c tests/bookshop/playwright.config.js
# The installation removes its key and the starter source (database, paid eBooks) from the web space.
docker exec "$NAME" sh -c 'test ! -e /var/www/html/install-key.php && test ! -e /var/www/html/demo/bookshop/dist' \
  || { echo "install-key.php or demo/bookshop/dist is still in the web space"; exit 1; }
# php:apache links /var/log/apache2/error.log to /dev/stderr (tail would block on it); its log is `docker logs`.
docker exec "$NAME" ls /srv/bookshop-data/bareboneseshop-private/jobs || true
docker logs --tail 5 "$NAME" 2>&1 || true
# R17: the screenshots release asset is packed here, every entry 0644 (the hand-packed v2.1.2 asset unpacked as 0777).
php -r '$z = new ZipArchive; if ($z->open($argv[2], ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) exit(1);
  foreach (glob($argv[1] . "/*.png") as $f) { $n = "bookshop-screenshots/" . basename($f); $z->addFile($f, $n);
    $z->setExternalAttributesName($n, ZipArchive::OPSYS_UNIX, (0100644 << 16)); }
  if (!$z->close()) exit(1);' "$OUT/screenshots" "$OUT/bookshop-screenshots.zip"
echo "Package: $OUT/bareboneseshop-bookshop.zip  Screenshots: $OUT/screenshots, $OUT/bookshop-screenshots.zip"
