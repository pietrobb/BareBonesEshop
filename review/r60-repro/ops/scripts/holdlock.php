<?php
// php holdlock.php <db> <seconds>: hold the sales_daily_state 'lock' row (a long rebuild) on MySQL
$p = new PDO('mysql:host=127.0.0.1;dbname=' . $argv[1], 'bbe_ci', 'bbe-ci-pass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$p->beginTransaction(); $p->query("SELECT value FROM sales_daily_state WHERE state_key = 'lock' FOR UPDATE")->fetchAll();
fwrite(STDERR, "locked\n"); sleep((int)$argv[2]); $p->rollBack();
