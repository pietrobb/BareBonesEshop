#!/bin/bash
# killrestore.sh <shop> <backup path> <glob to wait for> [extra restore args]: kill -9 the restore once the glob matches
SH=$1; B=$2; G=$3; shift 3
cd $SH; php cli/restore.php "$B" --yes --i-stopped-writers "$@" > $TMPDIR/killrestore.out 2>&1 &
P=$!
for i in $(seq 1 20000); do if compgen -G "$G" > /dev/null; then kill -9 $P; echo "killed at poll $i: $(compgen -G "$G" | head -3 | tr '\n' ' ')"; break; fi; if ! kill -0 $P 2>/dev/null; then echo "restore finished before match"; break; fi; done
wait $P 2>/dev/null; tail -3 $TMPDIR/killrestore.out
