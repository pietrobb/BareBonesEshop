#!/bin/bash
# mig.sh <sqlite|mysql> <N> : fresh 2.1.2 DB + N legacy open orders, then 68ad982f cli/migrate.php (timings)
set -e
W=/tmp/claude-0/-home-user-BareBonesEshop/0d748840-4251-5787-841e-a11f82aad9b2/scratchpad/r60/ops
D=$1; N=$2; P=$W/p-$D-$N; DB=bbe_r60_ops_m$N
rm -rf $P; mkdir -p $P
php $W/scripts/mkconfig.php $W/v212 $P $D $DB - 18501 >/dev/null
php $W/scripts/mkconfig.php $W/tree $P $D $DB - 18501 >/dev/null
if [ $D = mysql ]; then php -r '$p=new PDO("mysql:host=127.0.0.1","bbe_ci","bbe-ci-pass");$p->exec("DROP DATABASE IF EXISTS '$DB'");$p->exec("CREATE DATABASE '$DB' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");'; T=mysql:$DB; else T=$P/db/shop.sqlite; fi
php $W/v212/cli/migrate.php --skip-backup > $P/v212-migrate.log
php $W/scripts/legacy-orders2.php $T $N
S0=$(date +%s.%N); php $W/tree/cli/migrate.php --skip-backup > $P/new-migrate.log 2>&1 || { tail -5 $P/new-migrate.log; exit 1; }; echo "wall=$(echo "$(date +%s.%N) - $S0" | bc)s"
grep -E "ms\)" $P/new-migrate.log | sed -E 's/.*\] ([0-9_a-z]+) \(([0-9]+) ms\)/\2 \1/' | sort -rn | head -6
tail -1 $P/new-migrate.log
