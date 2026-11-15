<?php

echo "\n--- R37 OSS activation survives a later credit note, including the following year ---\n";
$r37lDir = test_fixture_path('oss-activation-r37');
mkdir($r37lDir);
$r37lWorker = $r37lDir . '/activation.php';
file_put_contents($r37lWorker, <<<'PHP'
<?php
[, $root, $dir, $driver] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql' ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]) : new ShopPDO('sqlite:' . $dir . '/shop.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$target = 'bbe_r37l_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
if ($driver === 'mysql') { $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4"); $db->exec("USE `{$target}`"); }
Db::init($db, $driver);
try {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '.lock'))->apply();
    $stores = Bootstrap::stores();
    $stores['stores']['default']['oss_enabled'] = false;
    $stores['stores']['default']['name'] = 'R37 Named Shop';
    $stores['stores']['default']['legal']['country'] = 'SK';
    (new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
    Bootstrap::initStoreContext('/', '', 'default');
    $year = (int)ShopTime::date('Y');
    ShopTime::setNowForTesting(strtotime($year . '-07-01 12:00:00 UTC'));
    $order = $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, net_total, currency, status, payment_status, payment_method, store_id, vat_scenario, created_at)
        VALUES (?, 'R37L', 'r37l@example.test', '{\"country\":\"AT\"}', ?, ?, ?, 'eur', 'delivered', 'paid', 'bank_transfer', 'default', ?, ?)");
    $invoice = $db->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, vat_scenario, type, refers_to_invoice_id, credit_snapshot)
        VALUES (?, 'default', ?, ?, '', ?, 0, ?, 'eur', 'issued', ?, ?, ?, ?, ?)");
    $issue = static function(int $net, string $scenario, string $suffix) use ($db, $order, $invoice, $year): array {
        $order->execute(['R37L-' . $suffix, $net, $net, $net, $scenario, $year . '-02-01']);
        $id = (int)$db->lastInsertId();
        OssThreshold::recordOrder($db, $id);
        $invoice->execute([$id, 'R37L-' . $suffix, $year . '-02-02', $net, $net, 'r37l-' . $suffix, $scenario, 'invoice', null, null]);
        $doc = (int)$db->lastInsertId();
        OssThreshold::record($db, $doc);
        return [$id, $doc];
    };
    $active = static function(): bool { OssThreshold::reset(); return OssThreshold::exceeded(); };
    $checks = [];
    $issue(900000, 'domestic_subthreshold', 'A');
    $checks[] = ['9000 EUR alone does not activate OSS', !$active()];
    $checks[] = ['a hypothetical crossing preview uses OSS', OssThreshold::exceeded(200000)];
    $checks[] = ['a hypothetical preview does not persist activation', (int)$db->query('SELECT COUNT(*) FROM oss_threshold_activations')->fetchColumn() === 0 && !$active()];
    $db->beginTransaction();
    $issue(200000, 'oss', 'rollback');
    $checks[] = ['a real crossing is recorded in the same transaction', (int)$db->query('SELECT COUNT(*) FROM oss_threshold_activations')->fetchColumn() === 1];
    $db->rollBack();
    $checks[] = ['rollback removes the crossing with the sale', (int)$db->query('SELECT COUNT(*) FROM oss_threshold_activations')->fetchColumn() === 0 && !$active()];
    [$orderId, $docId] = $issue(200000, 'oss', 'B');
    $checks[] = ['9000 plus 2000 EUR activates OSS', $active()];
    $db->beginTransaction();
    $invoice->execute([$orderId, 'R37L-B-CN', $year . '-03-02', -200000, -200000, 'r37l-b-cn', 'oss', 'credit_note', $docId, json_encode(['kind' => 'refund', 'totals' => ['net' => 200000]])]);
    OssThreshold::record($db, (int)$db->lastInsertId());
    $db->commit();
    $checks[] = ['credit note really reduces recorded turnover to 9000 EUR', OssThreshold::worstYear($db, 'default', $stores['stores']['default'], $stores['stores'])['sum'] === 900000];
    $checks[] = ['the later credit note does not undo current-year OSS activation', $active()];
    $doctor = Setup::ossThresholdCheck($db, 'default', $stores['stores']['default'], $stores['stores']);
    $checks[] = ['doctor warns about activation while reporting the reduced accounting turnover', $doctor['status'] === 'warn' && str_contains($doctor['message'], '9 000.00 EUR') && str_contains($doctor['message'], 'despite later credits')];
    $checks[] = ['dashboard OSS title uses the merchant-facing store name', str_contains((string)SetupChecklist::title('stores.oss_threshold.default'), 'R37 Named Shop')];
    $db->exec('DELETE FROM oss_threshold_activations');
    $checks[] = ['legacy state without activation evidence reproduces the lost crossing', !$active()];
    (require $root . '/migrations/20261114210000_oss_threshold_activations.php')->up($db, $driver === 'sqlite');
    $checks[] = ['migration reconstructs the crossing before the later credit', $active()];
    (require $root . '/migrations/20261114210000_oss_threshold_activations.php')->up($db, $driver === 'sqlite');
    $checks[] = ['historical activation rebuild is idempotent', (int)$db->query('SELECT COUNT(*) FROM oss_threshold_activations')->fetchColumn() === 1];
    $stores['stores']['outlet'] = $stores['stores']['default'];
    unset($stores['stores']['outlet']['legal']);
    (new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
    Bootstrap::initStoreContext('/', '', 'outlet');
    $checks[] = ['outlet without legal shares the historical activation', $active()];
    Bootstrap::initStoreContext('/', '', 'default');
    ShopTime::setNowForTesting(strtotime(($year + 1) . '-07-01 12:00:00 UTC'));
    $checks[] = ['activation survives into the following calendar year despite the credit note', $active()];
    ShopTime::setNowForTesting(strtotime(($year + 2) . '-07-01 12:00:00 UTC'));
    $checks[] = ['activation is not permanent beyond the following year without more sales', !$active()];
    echo json_encode(['checks' => $checks]);
} finally {
    ShopTime::setNowForTesting(null);
    if ($db->inTransaction()) $db->rollBack();
    if ($driver === 'mysql') $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
PHP);
foreach (['sqlite', 'mysql'] as $r37lDriver) {
    if ($r37lDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { echo "  [SKIP] R37 activation native MySQL\n"; continue; }
    $proc = proc_open([PHP_BINARY, $r37lWorker, dirname(TEST_CLI_DIR), $r37lDir, $r37lDriver], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc); $result = json_decode($raw, true);
    assert_true($exit === 0 && is_array($result), "R37 activation {$r37lDriver}: isolated fixture ({$err})");
    foreach ($result['checks'] ?? [] as [$label, $ok]) assert_true($ok, "R37 activation {$r37lDriver}: {$label}");
}
