#!/bin/sh
# runs inside: unshare -m --propagation private
set -u
W="$1"; MODE="${2:-full}"; DUMP="${3:-maria}"
mount --bind "$W/suite" /media
mount -t tmpfs -o mode=1777,size=4g tmpfs /media/tmp
export TMPDIR=/media/tmp GIT_CONFIG_GLOBAL=/dev/null
cd /media/app
P=18601
export BAREBONES_REQUIRE_UPDATE_SIGNATURE_TEST=1 BAREBONES_REQUIRE_MYSQL_UPDATE_TEST=1 SMOKE_URL=http://127.0.0.1:$P
export "TEST_MYSQL_DSN=mysql:host=127.0.0.1;port=3306;dbname=bbe_r60_sec_parity;charset=utf8mb4" TEST_MYSQL_USER=bbe_ci TEST_MYSQL_PASS=bbe-ci-pass
export TEST_MYSQL_UPDATE_DATABASE=bbe_r60_sec_update_test TEST_MYSQL_UPDATE_HOST=127.0.0.1 TEST_MYSQL_UPDATE_PORT=3306 TEST_MYSQL_UPDATE_USER=bbe_ci TEST_MYSQL_UPDATE_PASS=bbe-ci-pass
if [ "$DUMP" = maria ]; then
  export TEST_MYSQL_DUMP_BINARY=/media/bin/mariadb-dump TEST_MYSQL_CLIENT_BINARY=/media/bin/mariadb
else
  export TEST_MYSQL_DUMP_BINARY=/media/bin/dockmysqldump TEST_MYSQL_CLIENT_BINARY=/media/bin/dockmysql
fi
export BBE_ADMIN_IDENTITY_KEY=RUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUU=
export E2E_DB_DRIVER=mysql E2E_DB_HOST=127.0.0.1 E2E_DB_NAME=bbe_r60_sec_e2e_test E2E_DB_USER=bbe_ci E2E_DB_PASSWORD=bbe-ci-pass
export E2E_RUNTIME_DIR=/media/tmp/barebones-e2e-suite E2E_STATE_PATH=/media/tmp/e2e-state.json E2E_BASE_URL=http://127.0.0.1:$P
OUT="$W/out-$MODE-$DUMP"; mkdir -p "$OUT"
rm -f config.php; php tests/e2e/prepare.php > "$OUT/prepare.log" 2>&1 || { echo prepare failed; cat "$OUT/prepare.log"; }
php cli/migrate.php > "$OUT/migrate.log" 2>&1 || { echo migrate failed; cat "$OUT/migrate.log"; }
php cli/seed.php --count=30 > "$OUT/seed.log" 2>&1 || echo seed failed
php tests/e2e/seed.php > "$OUT/e2eseed.log" 2>&1 || { echo e2eseed failed; cat "$OUT/e2eseed.log"; }
php -S 127.0.0.1:$P router.php > "$OUT/http.log" 2>&1 &
SP=$!
sleep 2
if [ "$MODE" = full ]; then
  php cli/test.php > "$OUT/suite.out" 2> "$OUT/suite.err"; echo "exit=$?" >> "$OUT/suite.out"
else
  shift 3
  php cli/test.php "$@" > "$OUT/suite.out" 2> "$OUT/suite.err"; echo "exit=$?" >> "$OUT/suite.out"
fi
kill $SP
