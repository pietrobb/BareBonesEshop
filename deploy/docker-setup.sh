#!/bin/sh
# One-off installer of the Docker sample (`setup` service only; runs as root, the app never does).
# The checkout belongs to the host user, so www-data cannot create config.php in it: run the
# command, then hand the writable directories and the private volume to www-data (uid 33) and
# make config.php readable by its group.
set -u
cd /var/www/html
mkdir -p cache uploads import logs
# PHP steps started as root run as the owner of the checkout (cli/cli-only.php, review round 27): let it write the
# directories the command fills; they go to www-data below.
chown -R -h "$(stat -c %u:%g .)" cache uploads import logs /var/www/bareboneseshop-private
"$@"
status=$?
chown -R www-data:www-data cache uploads import logs /var/www/bareboneseshop-private
if [ -f config.php ]; then
    chown "$(stat -c %u .)":www-data config.php
    chmod 0640 config.php
fi
exit "$status"
