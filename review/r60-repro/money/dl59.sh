#!/bin/bash
# dl59.sh db dur "mode:count ..."
cd "$(dirname "$0")"; T=$1; DUR=$2; shift 2
export R50_JOBDIR=$PWD/tmp/dl_jobs_$T; rm -rf "$R50_JOBDIR"; mkdir -p "$R50_JOBDIR"
php dl59.php setup $T || exit 1
start=$(php -r 'echo microtime(true)+2.0;'); i=0
for spec in "$@"; do m=${spec%%:*}; c=${spec##*:}; for k in $(seq 1 $c); do i=$((i+1)); php dl59.php child $T $m $start $DUR $i > tmp/dl_$i.out 2>&1 & done; done
wait; cat tmp/dl_*.out | cut -c1-700; rm -f tmp/dl_*.out
php dl59.php check $T
