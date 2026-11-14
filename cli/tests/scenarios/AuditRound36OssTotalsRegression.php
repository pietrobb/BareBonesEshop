<?php

// Review round 36 (medium 1, goal #455): the OSS threshold check read every invoice of two years on each checkout
// preview and order — 0.8 s of sums, 1.1–1.3 s of e-book lines and ~2000 more queries at 200 000 invoices, ~5 s per
// request on MySQL. Issued documents now add their stored net to a per-store, per-year total (oss_threshold_totals),
// placed orders store theirs (orders.oss_net), and the check reads those rows; past the threshold last year it stops.
echo "\n--- Audit round 36: OSS threshold read from per-year totals, not from every invoice ---\n";

Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
$r36tStores = Bootstrap::stores();
$r36tStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r36tOff = $r36tStores;
$r36tOff['stores']['default']['oss_enabled'] = false;
$r36tOff['stores']['default']['legal']['country'] = 'SK';
$r36tStoresProp->setValue(null, $r36tOff);
Bootstrap::initStoreContext('/', '', 'default');
$r36tStashedTotals = [];
try {
    (require TEST_CLI_DIR . '/../migrations/20261113180000_invoice_oss_net.php')->up($pdo, Db::isSqlite());
    (require TEST_CLI_DIR . '/../migrations/20261113190000_oss_threshold_totals.php')->up($pdo, Db::isSqlite());
    Db::forgetSchema($pdo);
    assert_true(Db::tableExists('oss_threshold_totals', $pdo) && Db::hasColumn('orders', 'oss_net', $pdo), 'R36 OSS totals: the migration adds oss_threshold_totals and orders.oss_net');
    $year = (int)ShopTime::date('Y');
    $since = sprintf('%04d-01-01', $year - 1);
    // Nothing of other tests counts this year or the last.
    $r36tStashedInvoices = $pdo->query("SELECT id, issued_at FROM invoices WHERE issued_at >= '{$since}'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $pdo->exec("UPDATE invoices SET issued_at = '2001-01-01 00:00:00' WHERE issued_at >= '{$since}'");
    $r36tStashedOrders = $pdo->query("SELECT id, created_at FROM orders WHERE created_at >= '{$since}'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $pdo->exec("UPDATE orders SET created_at = '2001-01-01 00:00:00' WHERE created_at >= '{$since}'");
    $r36tStashedTotals = $pdo->query('SELECT store_id, year, currency, net FROM oss_threshold_totals')->fetchAll(PDO::FETCH_NUM);
    $pdo->exec('DELETE FROM oss_threshold_totals');

    $r36tOrderStmt = $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, net_total, currency, status, payment_status, payment_method, shipping_method, store_id, vat_scenario, created_at)
        VALUES (?, 'R36T', 'r36t@example.test', '', ?, ?, 0, 0, 0, ?, ?, 'eur', ?, 'paid', 'bank_transfer', 'gls', 'default', ?, ?)");
    $r36tOrder = static function (string $number, string $country, int $net, string $scenario, string $status = 'delivered') use ($pdo, $r36tOrderStmt, $year): int {
        $r36tOrderStmt->execute([$number, json_encode(['country' => $country]), $net, $net, $net, $status, $scenario, sprintf('%04d-02-10 10:00:00', $year)]);
        return (int)$pdo->lastInsertId();
    };
    $r36tInvoiceStmt = $pdo->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, vat_scenario, type)
        VALUES (?, 'default', ?, ?, '', ?, 0, ?, 'eur', 'issued', ?, ?, 'invoice')");
    $r36tInvoice = static function (int $orderId, string $number, int $net, string $scenario, int $docYear) use ($pdo, $r36tInvoiceStmt): int {
        $r36tInvoiceStmt->execute([$orderId, $number, sprintf('%04d-02-15', $docYear), $net, $net, 'r36t-' . $number, $scenario]);
        $id = (int)$pdo->lastInsertId();
        OssThreshold::record($pdo, $id);
        return $id;
    };
    $r36tStore = $r36tOff['stores']['default'];
    $r36tSum = static fn(): int => OssThreshold::worstYear($pdo, 'default', $r36tStore, ['default' => $r36tStore])['sum'];
    $r36tTotals = static fn(): int => (int)$pdo->query("SELECT COALESCE(SUM(net), 0) FROM oss_threshold_totals WHERE store_id = 'default' AND year = {$year}")->fetchColumn();
    $r36tRecount = static function () use ($pdo): int {
        $sum = 0;
        foreach ($pdo->query("SELECT * FROM invoices WHERE number LIKE 'R36T-%' AND status = 'issued'")->fetchAll(PDO::FETCH_ASSOC) as $doc) $sum += OssThreshold::documentNet($pdo, $doc);
        return $sum;
    };

    // 1. 200 sales of 45 EUR net to Austrian consumers at Slovak VAT and 50 domestic ones, issued one by one.
    $pdo->beginTransaction();
    $r36tFirst = 0;
    for ($i = 1; $i <= 200; $i++) {
        $id = $r36tInvoice($r36tOrder("R36T-AT{$i}", 'AT', 4500, 'domestic_subthreshold'), "R36T-AT{$i}", 4500, 'domestic_subthreshold', $year);
        $r36tFirst = $r36tFirst ?: $id;
    }
    for ($i = 1; $i <= 50; $i++) $r36tInvoice($r36tOrder("R36T-SK{$i}", 'SK', 10000, 'domestic'), "R36T-SK{$i}", 10000, 'domestic', $year);
    $pdo->commit();
    assert_equals([900000, 900000, 900000], [$r36tTotals(), $r36tSum(), $r36tRecount()],
        'R36 OSS totals: the year total kept as documents are issued equals the threshold count and a full recount of the documents');
    OssThreshold::record($pdo, $r36tFirst);
    assert_equals(900000, $r36tTotals(), 'R36 OSS totals: recording a document again does not count it twice');

    // 2. The check reads the totals, not the documents: emptied document amounts change nothing.
    $pdo->exec("UPDATE invoices SET total_net = 0 WHERE number LIKE 'R36T-%'");
    assert_equals(900000, $r36tSum(), 'R36 OSS totals: the threshold check reads the stored year total, not every invoice of the year (was ~5 s per checkout request)');
    $pdo->exec("UPDATE invoices SET total_net = CASE WHEN number LIKE 'R36T-AT%' THEN 4500 ELSE 10000 END WHERE number LIKE 'R36T-%'");

    // 3. The invoice of a cancelled order leaves the total.
    $pdo->exec("UPDATE orders SET status = 'cancelled' WHERE order_number = 'R36T-AT1'");
    InvoiceService::cancel($r36tFirst, 'R36T');
    assert_equals([895500, 895500], [$r36tTotals(), $r36tSum()], 'R36 OSS totals: a cancelled invoice leaves the year total');

    // 4. An order placed and not invoiced yet counts by its stored net; once invoiced, only by its invoice.
    $r36tOpen = $r36tOrder('R36T-OPEN', 'AT', 20000, 'domestic_subthreshold', 'pending');
    OssThreshold::recordOrder($pdo, $r36tOpen);
    assert_equals([20000, 915500], [(int)$pdo->query("SELECT oss_net FROM orders WHERE id = {$r36tOpen}")->fetchColumn(), $r36tSum()],
        'R36 OSS totals: a placed order stores what it adds to the threshold and counts until it is invoiced');
    $r36tInvoice($r36tOpen, 'R36T-OPEN', 20000, 'domestic_subthreshold', $year);
    assert_equals(915500, $r36tSum(), 'R36 OSS totals: an invoiced order counts once, by its invoice');
    $r36tDomestic = $r36tOrder('R36T-DOM', 'SK', 50000, 'domestic', 'pending');
    OssThreshold::recordOrder($pdo, $r36tDomestic);
    assert_equals([0, 915500], [(int)$pdo->query("SELECT oss_net FROM orders WHERE id = {$r36tDomestic}")->fetchColumn(), $r36tSum()],
        'R36 OSS totals: a domestic order stores 0 and adds nothing');

    // 5. The migration rebuilds the same totals from the stored nets.
    $pdo->exec('DELETE FROM oss_threshold_totals');
    (require TEST_CLI_DIR . '/../migrations/20261113190000_oss_threshold_totals.php')->up($pdo, Db::isSqlite());
    assert_equals([895500 + 20000, 915500], [$r36tTotals(), $r36tSum()], 'R36 OSS totals: the migration fills the year totals from the stored nets');

    // 6. Past the threshold last year, a consumer of another member state pays their own VAT, whatever this year holds.
    $pdo->prepare('INSERT INTO oss_threshold_totals (store_id, year, currency, net) VALUES (?, ?, ?, ?)')->execute(['default', $year - 1, 'eur', 1000001]);
    VatService::reset();
    assert_true(OssThreshold::exceeded(), 'R36 OSS totals: past the threshold last year (stored total) the check answers at once');

    $r36tSources = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Orders.php') . (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/InvoiceService.php');
    assert_true(substr_count($r36tSources, 'OssThreshold::recordOrder($db, $orderId)') === 2 && str_contains($r36tSources, 'OssThreshold::forget(Db::get(), $invoiceId)'),
        'R36 OSS totals: placing or editing an order stores its net and cancelling an invoice takes it out of the total');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    assert_true(false, 'R36 OSS totals (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
$pdo->exec("DELETE FROM invoices WHERE number LIKE 'R36T-%'");
$pdo->exec("DELETE FROM orders WHERE order_number LIKE 'R36T-%'");
if (Db::tableExists('oss_threshold_totals', $pdo)) {
    $pdo->exec('DELETE FROM oss_threshold_totals');
    $restore = $pdo->prepare('INSERT INTO oss_threshold_totals (store_id, year, currency, net) VALUES (?, ?, ?, ?)');
    foreach ($r36tStashedTotals as $row) $restore->execute($row);
}
$restore = $pdo->prepare('UPDATE invoices SET issued_at = ? WHERE id = ?');
foreach ($r36tStashedInvoices ?? [] as $id => $issuedAt) $restore->execute([$issuedAt, $id]);
$restore = $pdo->prepare('UPDATE orders SET created_at = ? WHERE id = ?');
foreach ($r36tStashedOrders ?? [] as $id => $createdAt) $restore->execute([$createdAt, $id]);
$r36tStoresProp->setValue(null, $r36tStores);
Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
