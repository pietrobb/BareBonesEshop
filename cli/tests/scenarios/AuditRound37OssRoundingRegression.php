<?php

echo "\n--- R37 OSS CZK: convert the combined currency total once, not two rounded EUR amounts ---\n";
$r37rDir = test_fixture_path('oss-rounding-r37');
mkdir($r37rDir);
$r37rWorker = $r37rDir . '/rounding.php';
file_put_contents($r37rWorker, <<<'PHP'
<?php
[, $root, $dir, $driver] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql' ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]) : new ShopPDO('sqlite:' . $dir . '/shop.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$target = 'bbe_r37r_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
if ($driver === 'mysql') { $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4"); $db->exec("USE `{$target}`"); }
Db::init($db, $driver);
try {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '.lock'))->apply();
    $stores = Bootstrap::stores();
    $stores['stores']['default']['oss_enabled'] = false;
    $stores['stores']['default']['legal']['country'] = 'SK';
    $stores['stores']['default']['currency'] = 'czk';
    (new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
    Bootstrap::initStoreContext('/', '', 'default');
    $rate = (float)VatService::config()['eur_exchange_rates']['CZK'];
    $checks = [['configured CZK rate is the fixture rate', $rate === 0.04]];
    foreach ([[24999988, 13, false], [25000001, 12, true]] as [$history, $sale, $crosses]) {
        $db->exec('DELETE FROM oss_threshold_totals');
        $db->prepare("INSERT INTO oss_threshold_totals (store_id, year, currency, net) VALUES ('default', ?, 'czk', ?)")->execute([(int)ShopTime::date('Y'), $history]);
        OssThreshold::reset();
        $home = ['seller_country' => 'SK', 'customer_country' => 'AT', 'seller_oss_enabled' => false];
        $items = [['product_type' => 'simple', 'qty' => 1, 'price' => $sale === 13 ? 16 : 15, 'vat_class' => 'standard']];
        $snapshot = VatService::buildSnapshot($items, $home, ['currency' => 'czk']);
        $checks[] = ["{$history}+{$sale}: real snapshot has the intended native net", (int)$snapshot['totals']['net'] === $sale];
        $vat = ['context' => $home, 'scenario' => VatService::resolveScenario($home), 'tax_provider' => Adapters::defaultProviderCode('tax'), 'oss_threshold' => ['home_context' => $home, 'goods' => true, 'digital' => false]];
        [$decided] = CheckoutService::thresholdVat($vat, $items, ['currency' => 'czk']);
        $checks[] = ["{$history}+{$sale}: final checkout pricing agrees with the combined total", ($decided['scenario']['scenario'] === 'oss') === $crosses];
        $preview = OssThreshold::saleNet([['gross' => $items[0]['price'], 'vat_class' => 'standard', 'digital' => false]], true, false, 'SK');
        $checks[] = ["{$history}+{$sale}: cart preview keeps the original currency until addition", OssThreshold::exceeded($preview, 'czk') === $crosses];
        $refused = false;
        try {
            OssThreshold::releaseOrderLock($db, OssThreshold::guardOrder($db, $snapshot, ['address' => ['country' => 'AT']], $items));
        } catch (VatChangedException $e) { $refused = true; }
        $checks[] = ["{$history}+{$sale}: guard agrees with the combined accounting sum", $refused === $crosses];
        $checks[] = ["{$history}+{$sale}: one conversion gives the intended boundary", ((int)round(($history + $sale) * $rate) > OssThreshold::EUR_CENTS) === $crosses];
        $db->prepare("UPDATE oss_threshold_totals SET net = net + ? WHERE currency = 'czk'")->execute([$sale]);
        $sum = OssThreshold::worstYear($db, 'default', $stores['stores']['default'], $stores['stores'])['sum'];
        $checks[] = ["{$history}+{$sale}: posted turnover matches the decision", ($sum > OssThreshold::EUR_CENTS) === $refused];
    }
    echo json_encode(['checks' => $checks]);
} finally {
    if ($db->inTransaction()) $db->rollBack();
    if ($driver === 'mysql') $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
PHP);
foreach (['sqlite', 'mysql'] as $r37rDriver) {
    if ($r37rDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { echo "  [SKIP] R37 rounding native MySQL\n"; continue; }
    $proc = proc_open([PHP_BINARY, $r37rWorker, dirname(TEST_CLI_DIR), $r37rDir, $r37rDriver], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc); $result = json_decode($raw, true);
    assert_true($exit === 0 && is_array($result), "R37 rounding {$r37rDriver}: isolated fixture ({$err})");
    foreach ($result['checks'] ?? [] as [$label, $ok]) assert_true($ok, "R37 rounding {$r37rDriver}: {$label}");
}
