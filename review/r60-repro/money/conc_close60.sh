#!/bin/bash
cd "$(dirname "$0")"; T=$1; N=$2
export R50_JOBDIR=$PWD/tmp/cc60_jobs_$T; rm -rf "$R50_JOBDIR"; mkdir -p "$R50_JOBDIR"
php conc_close60.php setup $T $N || exit 1
for k in $(seq 0 $((N-1))); do
  start=$(php -r 'echo microtime(true)+0.8;')
  for i in 0 1 2 3 4; do php conc_close60.php child $T $k $i $start > tmp/cc_$k_$i.out 2>&1 & done; wait
  cat tmp/cc_$k_*.out | sort | tr '\n' ' '; echo; rm -f tmp/cc_$k_*.out
done | sort | uniq -c
php conc_close60.php check $T
