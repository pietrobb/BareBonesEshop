#!/bin/bash
# scen2.sh SPEC_JSON [restore opts]
R=/tmp/claude-0/-home-user-BareBonesEshop/0d748840-4251-5787-841e-a11f82aad9b2/scratchpad/r60/sec
B=$R/bareboneseshop-private/backups/2026/11/27/eshop-20261127-101928.db; T=$B.files.tar
export TMPDIR=$R/tmp
cp $R/orig.files.tar $T; cp $R/orig.files.meta.json $T.meta.json
python3 -I $R/scripts/craft2.py $R/orig.files.tar $T $T.meta.json "$1" || exit 1
shift
cd $R/shop && php cli/restore.php 2026/11/27/eshop-20261127-101928.db --files-only --yes "$@" 2>&1 | grep -v '^$' | tail -14
cp $R/orig.files.tar $T; cp $R/orig.files.meta.json $T.meta.json
