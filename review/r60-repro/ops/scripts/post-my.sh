#!/bin/bash
W=/tmp/claude-0/-home-user-BareBonesEshop/0d748840-4251-5787-841e-a11f82aad9b2/scratchpad/r60/ops; S=$W/upd/shop-mysql; cd $S; export TMPDIR=$W/tmp
echo "== state after update"; php -r '$p=new PDO("mysql:host=127.0.0.1;dbname=bbe_r60_ops_upd","bbe_ci","bbe-ci-pass"); print_r($p->query("SELECT * FROM sales_daily_state")->fetchAll(PDO::FETCH_KEY_PAIR)); echo "sales_daily rows=", $p->query("SELECT COUNT(*) FROM sales_daily")->fetchColumn(), " oss_net NULL=", $p->query("SELECT COUNT(*) FROM orders WHERE oss_net IS NULL")->fetchColumn(), "\n";'
ls -R $W/upd/priv-mysql/jobs | head
echo "== first dashboard requests"; for i in 1 2 3 4 5 6 7 8; do php $W/scripts/perf-dash.php $S dash; done
