<?php
// php -d max_execution_time=N sd_timeout.php sqlite big.db [reset]: first report with the build left to it, killed by the time limit.
require __DIR__ . '/lib50.php';
$db = r50_boot($argv[1], $argv[2], false); r36_store('default');
if (($argv[3] ?? '') === 'reset') { $db->exec('DROP TABLE IF EXISTS sales_daily_state'); Db::forgetSchema($db); (require R36_ROOT . '/migrations/20261121120000_sales_daily.php')->up($db, true); $db->exec("DELETE FROM sales_daily_state WHERE state_key LIKE 'built_until:%' OR state_key LIKE 'timezone:%'"); $db->exec('DELETE FROM sales_daily'); $db->exec('DELETE FROM sales_daily_products'); echo "state reset (as after a timezone change / a migration that left the build)\n"; exit; }
register_shutdown_function(function () use ($db) {
    $st = $db->query("SELECT state_key, value FROM sales_daily_state WHERE state_key LIKE 'built_until:%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    echo "  at exit: built days in sales_daily=", $db->query('SELECT COUNT(DISTINCT day) FROM sales_daily')->fetchColumn(), " built_until=", json_encode($st), " elapsed=", round(microtime(true) - $GLOBALS['t0'], 1), "s\n";
});
$GLOBALS['t0'] = microtime(true);
echo "report request (limit ", ini_get('max_execution_time'), " s): ";
echo json_encode(SalesReport::totals('default', SalesReport::sinceUtc(30))), "\n";
