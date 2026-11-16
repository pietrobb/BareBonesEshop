<?php

echo "\n--- R38 OSS activation lapses after two years under the threshold (art. 59c) ---\n";
$r38aDir = test_fixture_path('oss-activation-r38');
mkdir($r38aDir);
$r38aWorker = $r38aDir . '/activation.php';
file_put_contents($r38aWorker, <<<'PHP'
<?php
[, $root, $dir, $driver] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql' ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]) : new ShopPDO('sqlite:' . $dir . '/shop-' . $driver . '.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$target = 'bbe_r38a_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
if ($driver === 'mysql') { $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4"); $db->exec("USE `{$target}`"); }
Db::init($db, $driver);
try {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '.lock'))->apply();
    $stores = Bootstrap::stores();
    $stores['stores']['default']['oss_enabled'] = false;
    $stores['stores']['default']['legal']['country'] = 'SK';
    (new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
    Bootstrap::initStoreContext('/', '', 'default');
    $year = (int)ShopTime::date('Y');
    $order = $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, net_total, currency, status, payment_status, payment_method, store_id, vat_scenario, created_at)
        VALUES (?, 'R38A', 'r38a@example.test', '{\"country\":\"AT\"}', ?, ?, ?, 'eur', 'delivered', 'paid', 'bank_transfer', 'default', ?, ?)");
    $invoice = $db->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, vat_scenario, type)
        VALUES (?, 'default', ?, ?, '', ?, 0, ?, 'eur', 'issued', ?, ?, 'invoice')");
    // As Orders::create() and InvoiceService do: the order is recorded when placed, its invoice when issued.
    $sell = static function(int $y, int $net, string $scenario, string $suffix, bool $invoiced = true) use ($db, $order, $invoice): void {
        ShopTime::setNowForTesting(strtotime($y . '-03-01 12:00:00 UTC'));
        $db->beginTransaction();
        $order->execute(['R38A-' . $suffix, $net, $net, $net, $scenario, $y . '-03-01 12:00:00']);
        $id = (int)$db->lastInsertId();
        OssThreshold::recordOrder($db, $id);
        if ($invoiced) {
            $invoice->execute([$id, 'R38A-' . $suffix, $y . '-03-02', $net, $net, 'r38a-' . $suffix, $scenario]);
            OssThreshold::record($db, (int)$db->lastInsertId());
        }
        $db->commit();
    };
    $abroadIn = static function(int $y): bool {
        ShopTime::setNowForTesting(strtotime($y . '-07-01 12:00:00 UTC'));
        OssThreshold::reset();
        return OssThreshold::exceeded();
    };
    $years = static fn(): array => array_map('intval', $db->query('SELECT year FROM oss_threshold_activations ORDER BY year')->fetchAll(PDO::FETCH_COLUMN));
    $checks = [];
    $sell($year, 900000, 'domestic_subthreshold', 'Y1');
    $sell($year, 200000, 'oss', 'Y2');
    $checks[] = ['year Y crosses 10 000 EUR and is recorded', $years() === [$year]];
    $checks[] = ['Y+1 charges the consumer VAT (previous year over)', $abroadIn($year + 1)];
    // Y+1: sales charged abroad only because Y crossed; Y+1 itself stays at 3 000 EUR.
    $sell($year + 1, 150000, 'oss', 'N1');
    $sell($year + 1, 150000, 'oss', 'N2', false);
    $checks[] = ['Y+1 sales under the threshold do not record an activation for Y+1', $years() === [$year]];
    $checks[] = ['Y+2 returns to the seller VAT after Y+1 stayed under (art. 59c)', !$abroadIn($year + 2)];
    // The migration's rebuild replays the same history: no Y+1 activation from sales taxed abroad because of Y.
    ShopTime::setNowForTesting(strtotime(($year + 1) . '-12-31 12:00:00 UTC'));
    $db->exec('DELETE FROM oss_threshold_activations');
    OssThreshold::rebuildActivations($db);
    $checks[] = ['rebuild records Y but not Y+1', $years() === [$year]];
    $checks[] = ['after the rebuild Y+2 is still at the seller VAT', !$abroadIn($year + 2)];
    // Upgrade from c1fe7a9: its recordOrder() activated Y+1 for every N1/N2 sale charged abroad because of Y. The
    // R38 migration on that database (no table cleared) must drop the carried row, upgraded in Y+1 or in Y+2.
    $key = (string)$db->query('SELECT seller_key FROM oss_threshold_activations WHERE year = ' . $year)->fetchColumn();
    $upgrade = static function(string $at) use ($db, $root, $driver, $key, $year): void {
        $db->prepare(Db::insertIgnore() . ' INTO oss_threshold_activations (seller_key, year) VALUES (?, ?)')->execute([$key, $year + 1]);
        ShopTime::setNowForTesting(strtotime($at . ' 12:00:00 UTC'));
        (require $root . '/migrations/20261115120000_oss_credit_year_vat_payer.php')->up($db, $driver === 'sqlite');
    };
    $upgrade(($year + 1) . '-12-31');
    $checks[] = ['upgraded in Y+1: the carried Y+1 activation of c1fe7a9 is dropped, Y kept', $years() === [$year]];
    $upgrade(($year + 2) . '-01-15');
    $checks[] = ['upgraded in Y+2: the carried Y+1 activation of c1fe7a9 is dropped, Y kept', $years() === [$year]];
    $checks[] = ['after the upgrade Y+2 is at the seller VAT', !$abroadIn($year + 2)];
    // Control: when Y+1 really passes 10 000 EUR again, Y+2 stays abroad.
    $sell($year + 1, 800000, 'oss', 'N3');
    $checks[] = ['Y+1 over 10 000 EUR records its own activation', $years() === [$year, $year + 1]];
    $checks[] = ['Y+2 charges the consumer VAT after a second year over', $abroadIn($year + 2)];
    ShopTime::setNowForTesting(strtotime(($year + 2) . '-01-15 12:00:00 UTC'));
    (require $root . '/migrations/20261115120000_oss_credit_year_vat_payer.php')->up($db, $driver === 'sqlite');
    $checks[] = ['the upgrade keeps a Y+1 that crossed by its own sales', $years() === [$year, $year + 1] && $abroadIn($year + 2)];
    echo json_encode(['checks' => $checks]);
} finally {
    ShopTime::setNowForTesting(null);
    if ($db->inTransaction()) $db->rollBack();
    if ($driver === 'mysql') $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
PHP);
foreach (['sqlite', 'mysql'] as $r38aDriver) {
    if ($r38aDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { echo "  [SKIP] R38 activation native MySQL\n"; continue; }
    $proc = proc_open([PHP_BINARY, $r38aWorker, dirname(TEST_CLI_DIR), $r38aDir, $r38aDriver], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc); $result = json_decode($raw, true);
    assert_true($exit === 0 && is_array($result), "R38 activation {$r38aDriver}: isolated fixture ({$err})");
    foreach ($result['checks'] ?? [] as [$label, $ok]) assert_true($ok, "R38 activation {$r38aDriver}: {$label}");
}
