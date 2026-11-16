<?php

echo "\n--- R39 OSS activation repair: decided on the documents, not on the table being repaired; OSS per VAT payer ---\n";
$r39mDir = test_fixture_path('oss-migration-r39');
mkdir($r39mDir);
$r39mWorker = $r39mDir . '/migration.php';
file_put_contents($r39mWorker, <<<'PHP'
<?php
[, $root, $dir, $driver] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql' ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]) : new ShopPDO('sqlite:' . $dir . '/shop-' . $driver . '.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$target = 'bbe_r39m_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
if ($driver === 'mysql') { $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4"); $db->exec("USE `{$target}`"); }
Db::init($db, $driver);
try {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '.lock'))->apply();
    $stores = Bootstrap::stores();
    $stores['stores']['default']['oss_enabled'] = false;
    $stores['stores']['default']['legal'] = array_merge((array)($stores['stores']['default']['legal'] ?? []), ['country' => 'SK', 'ic_dph' => 'SK2020393939']);
    (new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
    Bootstrap::initStoreContext('/', '', 'default');
    $y = (int)ShopTime::date('Y');
    $order = $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, net_total, currency, status, payment_status, payment_method, store_id, vat_scenario, financial_snapshot, created_at)
        VALUES (?, 'R39M', 'r39m@example.test', ?, ?, ?, ?, 'eur', 'delivered', 'paid', 'bank_transfer', 'default', ?, ?, ?)");
    $invoice = $db->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, vat_scenario, type)
        VALUES (?, 'default', ?, ?, '', ?, 0, ?, 'eur', 'issued', ?, ?, 'invoice')");
    $n = 0;
    // As Orders::create() and InvoiceService do: recorded when placed, its invoice when issued.
    $sell = static function(int $year, string $date, int $net, string $scenario, ?array $snapshot = null, string $country = 'AT') use ($db, $order, $invoice, &$n): void {
        $n++;
        ShopTime::setNowForTesting(strtotime("{$year}-{$date} 12:00:00 UTC"));
        $db->beginTransaction();
        $order->execute(['R39M-' . $n, json_encode(['country' => $country]), $net, $net, $net, $scenario, $snapshot === null ? null : json_encode($snapshot), "{$year}-{$date} 10:00:00"]);
        $id = (int)$db->lastInsertId();
        OssThreshold::recordOrder($db, $id);
        $invoice->execute([$id, 'R39M-' . $n, "{$year}-{$date}", $net, $net, 'r39m-' . $n, $scenario]);
        OssThreshold::record($db, (int)$db->lastInsertId());
        $db->commit();
    };
    $key = 'vat:SK2020393939';
    $years = static fn(): array => array_map('intval', $db->query("SELECT year FROM oss_threshold_activations WHERE seller_key = '{$key}' ORDER BY year")->fetchAll(PDO::FETCH_COLUMN));
    // The activations as c1fe7a9 left them (it recorded a year for every sale charged abroad).
    $c1fe7a9 = static function(array $rows) use ($db, $key): void {
        $db->exec('DELETE FROM oss_threshold_activations');
        foreach ($rows as $year) $db->prepare('INSERT INTO oss_threshold_activations (seller_key, year) VALUES (?, ?)')->execute([$key, $year]);
    };
    $migrate = static function(string $file, string $at) use ($db, $root, $driver): void {
        ShopTime::setNowForTesting(strtotime($at . ' 12:00:00 UTC'));
        (require $root . '/migrations/' . $file . '.php')->up($db, $driver === 'sqlite');
    };
    $r38 = '20261115120000_oss_credit_year_vat_payer';
    $r39 = '20261116100000_oss_activation_repair';
    $abroadIn = static function(string $at): bool {
        ShopTime::setNowForTesting(strtotime($at . ' 12:00:00 UTC'));
        OssThreshold::reset();
        return OssThreshold::exceeded();
    };
    $reset = static function() use ($db): void {
        foreach (['invoices', 'orders', 'oss_threshold_totals', 'oss_threshold_activations'] as $table) $db->exec("DELETE FROM {$table}");
    };
    $checks = [];

    // The reviewer's history: Y = 11 000 EUR, Y+1 and Y+2 3 000 EUR each, charged abroad (OSS scenario, OSS off).
    $history = static function() use ($sell, $y): void {
        $sell($y, '03-01', 900000, 'domestic_subthreshold');
        $sell($y, '05-01', 200000, 'oss');
        $sell($y + 1, '03-01', 300000, 'oss');
        $sell($y + 2, '03-01', 300000, 'oss');
    };
    $history();
    $c1fe7a9([$y, $y + 1, $y + 2]);
    $migrate($r38, ($y + 2) . '-07-01');
    $migrate($r39, ($y + 2) . '-07-01');
    $checks[] = ['upgrade c1fe7a9 → R39 in Y+2: only Y, the year that crossed, stays', $years() === [$y]];
    $checks[] = ['after the upgrade Y+2 is at the seller VAT (Y+1 under the threshold)', !$abroadIn(($y + 2) . '-08-01')];
    $checks[] = ['after the upgrade Y+3 is at the seller VAT', !$abroadIn(($y + 3) . '-02-01')];

    // Upgraded through R38 already: [Y, Y+2] is in the table, the R39 migration repairs it.
    $c1fe7a9([$y, $y + 2]);
    $migrate($r39, ($y + 2) . '-07-01');
    $checks[] = ['a database R38 left at [Y, Y+2] is repaired to [Y]', $years() === [$y]];

    // A lone carried Y+1 row (Y's own row gone): no new Y+2 row may appear.
    $c1fe7a9([$y + 1]);
    $migrate($r38, ($y + 2) . '-07-01');
    $migrate($r39, ($y + 2) . '-07-01');
    $checks[] = ['a lone carried Y+1 row creates no Y+2 activation', !in_array($y + 2, $years(), true) && !in_array($y + 1, $years(), true)];
    $checks[] = ['… and Y+2 is at the seller VAT', !$abroadIn(($y + 2) . '-08-01')];
    // Capability review (run 513): the runtime judges a pre-R39 order like the migration. Cancelling the invoice of the
    // Y+2 3 000 EUR sale re-records it (forget() → recordOrder()); with Y+1 gone it was taken as a crossing of Y+2.
    ShopTime::setNowForTesting(strtotime(($y + 2) . '-08-01 12:00:00 UTC'));
    InvoiceService::cancel((int)$db->query("SELECT id FROM invoices WHERE number = 'R39M-4'")->fetchColumn(), 'R39 probe');
    $checks[] = ['cancelling the invoice of a pre-R39 Y+2 sale activates nothing', $years() === []];
    $checks[] = ['… and Y+2 stays at the seller VAT', !$abroadIn(($y + 2) . '-08-02')];

    // The same history decided in the other order of the table: the result does not depend on what it held.
    $c1fe7a9([$y + 2, $y + 1]);
    $migrate($r39, ($y + 2) . '-07-01');
    $checks[] = ['repair is independent of the rows found', $years() === []];

    // Control: Y+2 crossing by its own sales keeps its row.
    $sell($y + 2, '05-01', 750000, 'oss');
    $c1fe7a9([$y + 1, $y + 2]);
    $migrate($r39, ($y + 2) . '-07-01');
    $checks[] = ['a Y+2 over 10 000 EUR by its own sales keeps its row', $years() === [$y + 2]];

    // R37 HU27: the sale that passes 10 000 EUR at the seller's 23 % is charged 27 % HU VAT and stores a smaller net
    // (9 990 + 9,84 EUR); placed before R39 (no reason in its snapshot) it is still the crossing of its year.
    $reset();
    $sell($y + 2, '02-01', 999000, 'domestic_subthreshold');
    $hu = ['version' => 1, 'scenario' => 'oss', 'components' => [['type' => 'product', 'vat_class' => 'standard', 'gross' => 1250, 'net' => 984, 'rate_basis_points' => 2700]], 'totals' => ['net' => 984, 'gross' => 1250]];
    $sell($y + 2, '03-01', 984, 'oss', $hu, 'HU');
    $db->exec('DELETE FROM oss_threshold_activations');
    $migrate($r39, ($y + 2) . '-07-01');
    $checks[] = ['legacy: the sale passing the threshold at home VAT (HU 27 %) is a crossing of its own', $years() === [$y + 2]];

    // R39 orders carry the reason: a sale charged abroad because of the previous year never activates its year …
    $reset();
    $previous = ['version' => 1, 'scenario' => 'oss', 'oss_threshold' => 'previous_year', 'components' => [], 'totals' => ['net' => 300000]];
    $sell($y + 1, '03-01', 300000, 'oss', $previous);
    $current = ['version' => 1, 'scenario' => 'oss', 'oss_threshold' => 'current_year', 'components' => [], 'totals' => ['net' => 5000]];
    $sell($y + 2, '03-01', 5000, 'oss', $current);
    $checks[] = ['recordOrder: previous_year activates nothing, current_year activates its year', $years() === [$y + 2]];
    $db->exec('DELETE FROM oss_threshold_activations');
    $migrate($r39, ($y + 2) . '-07-01');
    $checks[] = ['rebuild follows the snapshot reason', $years() === [$y + 2]];

    // exceeded() tells why, and the checkout snapshot records it.
    $reset();
    $reason = static fn(): ?string => method_exists(OssThreshold::class, 'lastReason') ? OssThreshold::lastReason() : null;
    $c1fe7a9([$y + 1]);
    $checks[] = ['exceeded() in Y+2 after an activated Y+1: previous_year', $abroadIn(($y + 2) . '-02-01') && $reason() === 'previous_year'];
    $c1fe7a9([$y + 2]);
    $checks[] = ['exceeded() in an activated Y+2: current_year', $abroadIn(($y + 2) . '-02-01') && $reason() === 'current_year'];
    $snapshot = VatService::buildSnapshot([['qty' => 1, 'price' => 1230, 'vat_class' => 'standard', 'sku' => 'R39M']],
        ['seller_country' => 'SK', 'seller_oss_enabled' => true, 'oss_threshold_reason' => $reason(), 'customer_country' => 'AT', 'is_b2b' => false]);
    $checks[] = ['the order snapshot records the reason', ($snapshot['oss_threshold'] ?? null) === 'current_year'];

    // R39 (low): OSS is the VAT payer's: a second store of the same IČ DPH with OSS off is in the scheme too.
    $reset();
    $stores['stores']['default']['oss_enabled'] = true;
    $stores['stores']['r39b'] = array_merge($stores['stores']['default'], ['oss_enabled' => false, 'name' => 'R39 B', 'url_prefix' => 'r39b']);
    $stores['stores']['r39b']['legal']['ic_dph'] = 'SK 2020 393 939';
    (new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
    Bootstrap::initStoreContext('/', '', 'r39b');
    ShopTime::setNowForTesting(strtotime(($y + 2) . '-07-01 12:00:00 UTC'));
    $vat = CheckoutService::vatContext('AT', '', '', false);
    $checks[] = ['store B (OSS off, same IČ DPH as an OSS store) charges the Austrian consumer AT VAT', $vat['scenario']['scenario'] === 'oss' && ($vat['scenario']['rate_country'] ?? '') === 'AT'];
    $doctor = Setup::ossThresholdCheck($db, 'r39b', $stores['stores']['r39b'], $stores['stores']);
    $checks[] = ['doctor warns about store B\'s OSS switch', $doctor['status'] === 'warn' && str_contains($doctor['message'], 'same VAT ID')];
    $stores['stores']['r39b']['legal']['ic_dph'] = 'SK2029999999';
    (new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
    Bootstrap::initStoreContext('/', '', 'r39b');
    $vat = CheckoutService::vatContext('AT', '', '', false);
    $checks[] = ['control: another VAT payer with OSS off stays at the seller VAT under the threshold', $vat['scenario']['scenario'] === 'domestic_subthreshold'];
    echo json_encode(['checks' => $checks]);
} finally {
    ShopTime::setNowForTesting(null);
    if ($db->inTransaction()) $db->rollBack();
    if ($driver === 'mysql') $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
PHP);
foreach (['sqlite', 'mysql'] as $r39mDriver) {
    if ($r39mDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { echo "  [SKIP] R39 OSS migration native MySQL\n"; continue; }
    $proc = proc_open([PHP_BINARY, $r39mWorker, dirname(TEST_CLI_DIR), $r39mDir, $r39mDriver], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc); $result = json_decode($raw, true);
    assert_true($exit === 0 && is_array($result), "R39 OSS migration {$r39mDriver}: isolated fixture ({$err}" . substr((string)$raw, 0, 600) . ')');
    foreach ($result['checks'] ?? [] as [$label, $ok]) assert_true($ok, "R39 OSS migration {$r39mDriver}: {$label}");
}
