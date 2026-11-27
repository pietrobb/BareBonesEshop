#!/bin/bash
cd "$(dirname "$0")"; export TMPDIR=$PWD/tmp; drv=$1; shift
for s in "$@"; do if [ $drv = sqlite ]; then t=$PWD/tmp/cnp60_$s.db; else t=bbe_r60_money_cnp_$s; fi
php cn_prop60.php $drv $t $s 250 1 > out/cnp60_${drv}_$s.txt 2>&1; rm -f tmp/cnp60_$s.db*; done
