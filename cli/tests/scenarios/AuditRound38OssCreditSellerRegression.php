<?php

echo "\n--- R38 OSS threshold: a credit note counts in its sale's year; one VAT payer shares the threshold ---\n";
$r38cDir = test_fixture_path('oss-credit-seller-r38');
mkdir($r38cDir);
$r38cWorker = $r38cDir . '/credit-seller.php';
file_put_contents($r38cWorker, <<<'PHP'
<?php
[, $root, $dir, $driver, $part] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql' ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]) : new ShopPDO('sqlite:' . $dir . '/shop-' . $driver . '-' . $part . '.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$target = 'bbe_r38c_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
if ($driver === 'mysql') { $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4"); $db->exec("USE `{$target}`"); }
Db::init($db, $driver);
try {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '-' . $part . '.lock'))->apply();
    $year = (int)ShopTime::date('Y');
    $stores = Bootstrap::stores();
    $base = $stores['stores']['default'];
    $base['oss_enabled'] = false;
    $base['currency'] = 'eur';
    $base['legal'] = array_merge((array)($base['legal'] ?? []), ['country' => 'SK', 'ic_dph' => 'SK2020123456', 'invoice_prefix' => 'A', 'seller' => '']);
    $stores['stores']['default'] = $base;
    // Two storefronts of one company with their own number series (prefix B), the same IČ DPH; a third company.
    $stores['stores']['r38c2'] = array_merge($base, ['legal' => array_merge($base['legal'], ['invoice_prefix' => 'B', 'ic_dph' => 'SK 2020 123 456'])]);
    $stores['stores']['r38c3'] = array_merge($base, ['legal' => array_merge($base['legal'], ['invoice_prefix' => 'C', 'ic_dph' => 'SK2029999999'])]);
    (new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
    $order = $db->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, net_total, currency, status, payment_status, payment_method, store_id, vat_scenario, created_at)
        VALUES (?, 'R38C', 'r38c@example.test', '{\"country\":\"AT\"}', ?, ?, ?, 'eur', 'delivered', 'paid', 'bank_transfer', ?, 'domestic_subthreshold', ?)");
    $document = $db->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, vat_scenario, type, refers_to_invoice_id, credit_snapshot)
        VALUES (?, ?, ?, ?, '', ?, 0, ?, 'eur', 'issued', ?, 'domestic_subthreshold', ?, ?, ?)");
    $n = 0;
    // As InvoiceService does: the row is inserted, then OssThreshold::record() in the same transaction.
    $issue = static function(int $orderId, string $store, string $date, int $net, string $type = 'invoice', ?int $source = null, ?array $credit = null) use ($db, $document, &$n): int {
        $n++;
        $db->beginTransaction();
        $document->execute([$orderId, $store, 'R38C-' . $n, $date, $type === 'invoice' ? $net : -$net, $type === 'invoice' ? $net : -$net, 'r38c-' . $n, $type, $source, $credit === null ? null : json_encode($credit)]);
        $id = (int)$db->lastInsertId();
        OssThreshold::record($db, $id);
        $db->commit();
        return $id;
    };
    $sell = static function(string $store, string $date, int $net) use ($db, $order, $issue, &$n): array {
        ShopTime::setNowForTesting(strtotime($date . ' 12:00:00 UTC'));
        $db->beginTransaction();
        $order->execute(['R38C-O' . (++$n), $net, $net, $net, $store, $date . ' 10:00:00']);
        $id = (int)$db->lastInsertId();
        OssThreshold::recordOrder($db, $id);
        $db->commit();
        return [$id, $issue($id, $store, $date, $net)];
    };
    $abroad = static function(string $store, string $date, int $saleNet = 0): bool {
        ShopTime::setNowForTesting(strtotime($date . ' 12:00:00 UTC'));
        Bootstrap::initStoreContext('/', '', $store);
        OssThreshold::reset();
        return OssThreshold::exceeded($saleNet);
    };
    $totals = static fn(): array => array_map(static fn(array $r): array => [(string)$r['store_id'], (int)$r['year'], (int)$r['net']],
        $db->query('SELECT store_id, year, net FROM oss_threshold_totals WHERE net <> 0 ORDER BY store_id, year')->fetchAll());
    // The R38 migration (absent before the fix: its checks fail instead of the worker).
    $migrate = static function() use ($db, $root, $driver): bool {
        $file = $root . '/migrations/20261115120000_oss_credit_year_vat_payer.php';
        if (!is_file($file)) return false;
        (require $file)->up($db, $driver === 'sqlite');
        return true;
    };
    $checks = [];
    if ($part === 'credit') {
        // Year Y-1: 9 500 EUR, 2 000 EUR of it sold in December; that sale is credited (returned) in January of Y.
        $sell('default', ($year - 1) . '-05-10', 750000);
        [$decOrder, $decInvoice] = $sell('default', ($year - 1) . '-12-10', 200000);
        ShopTime::setNowForTesting(strtotime($year . '-01-15 12:00:00 UTC'));
        $credit = $issue($decOrder, 'default', $year . '-01-15', 200000, 'credit_note', $decInvoice);
        $checks[] = ['the January credit note lowers the December year, not the new one', $totals() === [['default', $year - 1, 750000]]];
        // Year Y: 9 000 EUR sold; the next 1 500 EUR supply crosses 10 000 EUR.
        $sell('default', $year . '-03-01', 900000);
        $checks[] = ['Y counts its own 9 000 EUR (was 7 000 EUR after the credit)', $totals() === [['default', $year - 1, 750000], ['default', $year, 900000]]];
        $checks[] = ['the supply that crosses 10 000 EUR in Y is charged the consumer VAT', $abroad('default', $year . '-07-01', 150000)];
        $checks[] = ['a smaller supply still stays at the seller VAT', !$abroad('default', $year . '-07-01', 90000)];
        $worst = OssThreshold::worstYear($db, 'default', $stores['stores']['default'], $stores['stores']);
        $checks[] = ['the doctor reads Y-1 at 7 500 EUR and Y at 9 000 EUR', $worst['sum'] === 900000 && $worst['year'] === $year];
        $checks[] = ['the cache audit agrees with the documents', OssThresholdCache::check($db)['mismatches'] === 0];
        // An install upgraded from c1fe7a9 cached the credit note in Y; the migration recounts it into Y-1.
        $db->exec('DELETE FROM oss_threshold_totals');
        $db->prepare('INSERT INTO oss_threshold_totals (store_id, year, currency, net) VALUES (?, ?, ?, ?), (?, ?, ?, ?)')
            ->execute(['default', $year - 1, 'eur', 950000, 'default', $year, 'eur', 700000]);
        $checks[] = ['the c1fe7a9 cache (credit note in Y) is reported by the audit', OssThresholdCache::check($db)['mismatches'] === 2];
        $checks[] = ['the migration recounts the credit note into Y-1', $migrate() && $totals() === [['default', $year - 1, 750000], ['default', $year, 900000]] && OssThresholdCache::check($db)['mismatches'] === 0];
        // Reversing the credit note restores the December year.
        ShopTime::setNowForTesting(strtotime($year . '-02-01 12:00:00 UTC'));
        $issue($decOrder, 'default', $year . '-02-01', 200000, 'credit_note', $decInvoice, ['kind' => 'reversal', 'reverses_credit_note_id' => $credit]);
        $checks[] = ['the reversal of the credit note counts in the December year again', $totals() === [['default', $year - 1, 950000], ['default', $year, 900000]]];
        // Counted from the lines (documents without a stored net), the years are the same.
        $db->exec('DELETE FROM oss_threshold_totals');
        $db->exec('UPDATE invoices SET oss_net = NULL');
        $checks[] = ['counted from the lines, Y is 9 000 EUR as well', $abroad('default', $year . '-07-01', 150000) && !$abroad('default', $year . '-07-01', 90000)];
    } else {
        // Same IČ DPH, two number series: 6 000 + 5 000 EUR in Y pass the threshold together.
        $sell('default', $year . '-03-01', 600000);
        $sell('r38c2', $year . '-04-01', 500000);
        $sell('r38c3', $year . '-04-01', 500000);
        $checks[] = ['store A (prefix A) counts the VAT payer’s 11 000 EUR', $abroad('default', $year . '-07-01')];
        $checks[] = ['store B (prefix B, same IČ DPH) counts them too', $abroad('r38c2', $year . '-07-01')];
        $checks[] = ['another VAT payer keeps its own 5 000 EUR', !$abroad('r38c3', $year . '-07-01')];
        $keys = $db->query('SELECT seller_key, year FROM oss_threshold_activations ORDER BY seller_key')->fetchAll();
        $checks[] = ['one activation, under the VAT payer', array_map(static fn(array $r): string => $r['seller_key'] . '/' . $r['year'], $keys) === ['vat:SK2020123456/' . $year]];
        // An activation recorded by c1fe7a9 under the number-series seller holds for every store of its VAT payer.
        $db->exec('DELETE FROM oss_threshold_activations');
        $db->prepare('INSERT INTO oss_threshold_activations (seller_key, year) VALUES (?, ?)')->execute(['default', $year - 1]);
        $db->exec('DELETE FROM invoices');
        $db->exec('DELETE FROM orders');
        $db->exec('DELETE FROM oss_threshold_totals');
        $checks[] = ['before the migration store B does not see store A’s previous-year activation', !$abroad('r38c2', $year . '-07-01')];
        $checks[] = ['after the migration store B charges the consumer VAT (Y-1 activated for the VAT payer)', $migrate() && $abroad('r38c2', $year . '-07-01')];
        $checks[] = ['the third company is not activated', !$abroad('r38c3', $year . '-07-01')];
    }
    echo json_encode(['checks' => $checks]);
} finally {
    ShopTime::setNowForTesting(null);
    if ($db->inTransaction()) $db->rollBack();
    if ($driver === 'mysql') $db->exec("DROP DATABASE IF EXISTS `{$target}`");
}
PHP);
foreach (['sqlite', 'mysql'] as $r38cDriver) {
    if ($r38cDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { echo "  [SKIP] R38 credit/seller native MySQL\n"; continue; }
    foreach (['credit', 'seller'] as $r38cPart) {
        $proc = proc_open([PHP_BINARY, $r38cWorker, dirname(TEST_CLI_DIR), $r38cDir, $r38cDriver, $r38cPart], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $raw = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($proc); $result = json_decode($raw, true);
        assert_true($exit === 0 && is_array($result), "R38 {$r38cPart} {$r38cDriver}: isolated fixture ({$err}" . ($exit !== 0 ? substr($raw, 0, 400) : '') . ')');
        foreach ($result['checks'] ?? [] as [$label, $ok]) assert_true($ok, "R38 {$r38cPart} {$r38cDriver}: {$label}");
    }
}
