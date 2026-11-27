#!/bin/bash
# updsetup.sh <sqlite|mysql> <seed: legacy:N | big> : install 2.1.2 shop at upd/shop-<d>, seed, baseline
set -e
W=/tmp/claude-0/-home-user-BareBonesEshop/0d748840-4251-5787-841e-a11f82aad9b2/scratchpad/r60/ops
D=$1; SEED=$2; V=${3:-}; SH=$W/upd/shop-$D$V; P=$W/upd/priv-$D$V; DB=bbe_r60_ops_upd$V
rm -rf $SH $P; cp -r $W/upd/from $SH; mkdir -p $P
php $W/scripts/mkconfig.php $SH $P $D $DB - 18521 >/dev/null
php -r '$f="'$SH'/config.php"; $c=require $f; $c["update_public_key"]="'$W'/upd/pub.key"; $c["update_mysql_probe_database"]="'$DB'_update_probe"; file_put_contents($f, "<?php\nreturn ".var_export($c,true).";\n");'
if [ $D = mysql ]; then php -r '$p=new PDO("mysql:host=127.0.0.1","bbe_ci","bbe-ci-pass");$p->exec("DROP DATABASE IF EXISTS '$DB'");$p->exec("DROP DATABASE IF EXISTS '$DB'_update_probe");$p->exec("CREATE DATABASE '$DB' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");'; T=mysql:$DB; else T=$P/db/shop.sqlite; fi
(cd $SH && php cli/migrate.php --skip-backup | tail -1)
if [ "$SEED" = big ]; then
  if [ $D = mysql ]; then S0=$(date +%s); docker exec -i bbe-mysql mysql -ubbe_ci -pbbe-ci-pass $DB < $W/scripts/${SEEDSQL:-seed200k.sql} 2>&1 | grep -v Warning | tail -3; echo "seed $(( $(date +%s)-S0 ))s"; else php $W/scripts/seedsq.php $T ${NSQ:-200000}; fi
fi
(cd $SH && php cli/update.php baseline --version=2.1.2 | tail -2)
