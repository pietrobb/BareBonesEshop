<?php
// php sd-reset.php <shopRoot> : forget the built daily sales (as right after the migration), drop queued jobs
$root = $argv[1]; chdir($root); require $root . '/src/autoload.php';
$db = Db::get();
foreach (['sales_daily', 'sales_daily_products', 'sales_monthly_products', 'sales_daily_changes'] as $t) $db->exec("DELETE FROM $t");
$db->exec("DELETE FROM sales_daily_state WHERE state_key <> 'lock'");
$cfg = require $root . '/config.php';
foreach (glob(($cfg['jobs_dir'] ?? '') . '/*/*') ?: [] as $f) if (is_file($f)) unlink($f);
echo "reset\n";
