<?php
// php -d memory_limit=X sd_changes.php sqlite big.db N : N change rows (as N late order changes leave when no report is opened), then one report.
require __DIR__ . '/lib50.php';
$db = r50_boot($argv[1], $argv[2], false); r36_store('default'); $n = (int)$argv[3];
if ($n > 0) {
    $ids = $db->query('SELECT created_at FROM orders ORDER BY id LIMIT 100000')->fetchAll(PDO::FETCH_COLUMN);
    $db->beginTransaction(); $st = $db->prepare('INSERT INTO sales_daily_changes (store_id, created_at) VALUES (?, ?)');
    for ($i = 0; $i < $n; $i++) $st->execute(['default', $ids[$i % count($ids)]]);
    $db->commit(); echo "inserted $n change rows\n"; exit;
}
$t = microtime(true);
register_shutdown_function(function () use ($db, $t) { echo "  exit after ", round(microtime(true) - $t, 1), " s, peak ", round(memory_get_peak_usage() / 1048576), " MB, change rows left ", $db->query('SELECT COUNT(*) FROM sales_daily_changes')->fetchColumn(), "\n"; });
echo json_encode(SalesReport::totals('default', SalesReport::sinceUtc(7))), "\n";
