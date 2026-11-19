<?php
$pdo ??= test_commerce_fixture();

// Review round 19 (R49, low): a document counted in the year of its supply (delivery_date) was converted to EUR at the
// ECB rate of its day of issue — cents, but another day than the one it counts in (art. 91: the chargeable event).
echo "\n--- R49 OSS EUR: a document converts at the ECB rate of its day of supply ---\n";

foreach (['20261113180000_invoice_oss_net', '20261113190000_oss_threshold_totals', '20261116110000_eur_exchange_rates', '20261116190000_invoice_oss_net_eur', '20261117100000_eur_rate_history'] as $r49rMigration) {
    (require TEST_CLI_DIR . "/../migrations/{$r49rMigration}.php")->up($pdo, Db::isSqlite());
}
Db::forgetSchema($pdo);
$r49rRates = $pdo->query('SELECT currency, rate, as_of FROM eur_exchange_rates')->fetchAll(PDO::FETCH_NUM);
$r49rHistory = $pdo->query('SELECT currency, as_of, rate FROM eur_exchange_rate_history')->fetchAll(PDO::FETCH_NUM);
[$r49rInvoices, $r49rOrders] = [[], []];
$r49rDay = static fn(int $daysAgo): string => gmdate('Y-m-d', ShopTime::now() - $daysAgo * 86400);
$r49rIssue = static function (string $number, string $issuedAt, string $suppliedOn) use ($pdo, &$r49rInvoices, &$r49rOrders): int {
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, net_total, currency, status, payment_status, payment_method, shipping_method, store_id, vat_scenario, created_at)
        VALUES (?, 'R49R', 'r49r@example.test', '', ?, 100000, 0, 0, 0, 100000, 100000, 'czk', 'delivered', 'paid', 'bank_transfer', 'gls', 'default', 'domestic_subthreshold', ?)")
        ->execute([$number, json_encode(['country' => 'AT']), $suppliedOn]);
    $r49rOrders[] = $order = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, delivery_date, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, vat_scenario, type)
        VALUES (?, 'default', ?, ?, ?, '', 100000, 0, 100000, 'czk', 'issued', ?, 'domestic_subthreshold', 'invoice')")
        ->execute([$order, $number, $issuedAt, $suppliedOn, strtolower($number)]);
    $r49rInvoices[] = $invoice = (int)$pdo->lastInsertId();
    OssThreshold::record($pdo, $invoice);
    return $invoice;
};
$r49rEur = static fn(int $id): ?int => ($v = $pdo->query("SELECT oss_net_eur FROM invoices WHERE id = {$id}")->fetchColumn()) === null ? null : (int)$v;
try {
    $pdo->exec('DELETE FROM eur_exchange_rates');
    $pdo->exec('DELETE FROM eur_exchange_rate_history');
    $r49rStore = $pdo->prepare('INSERT INTO eur_exchange_rate_history (currency, as_of, rate) VALUES (?, ?, ?)');
    $r49rStore->execute(['CZK', $r49rDay(12), 0.05]); // 20 CZK/EUR on the day of supply
    $r49rStore->execute(['CZK', $r49rDay(2), 0.04]);  // 25 CZK/EUR on the day of issue
    EurRates::reset();
    $r49rSupplied = $r49rIssue('R49R-SUPPLIED', $r49rDay(2) . ' 09:00:00', $r49rDay(12));
    assert_equals(5000, $r49rEur($r49rSupplied), 'R49 rate: 1 000 CZK supplied at 20 CZK/EUR and invoiced at 25 CZK/EUR count 50 EUR (was 40 EUR, the day of issue)');
    $r49rIssued = $r49rIssue('R49R-ISSUED', $r49rDay(2) . ' 09:00:00', '');
    assert_equals(4000, $r49rEur($r49rIssued), 'R49 rate: without a day of supply, the day of issue');

    // Waiting for its day's rate, the ECB history is fetched from the day of supply (cli/eur-rates.php: fetchHistory()).
    $pdo->exec('DELETE FROM eur_exchange_rate_history');
    EurRates::reset();
    $r49rWaiting = $r49rIssue('R49R-WAITING', $r49rDay(3) . ' 09:00:00', $r49rDay(30));
    $r49rPending = OssThreshold::pending($pdo);
    assert_true($r49rEur($r49rWaiting) === null && $r49rPending['oldest'] === $r49rDay(30),
        'R49 rate: a document waiting for its rate is pending from its day of supply (got ' . json_encode($r49rPending) . ')');
} catch (Throwable $r49rE) {
    assert_true(false, 'R49 OSS rate day (' . get_class($r49rE) . ': ' . $r49rE->getMessage() . ' @ ' . basename($r49rE->getFile()) . ':' . $r49rE->getLine() . ')');
} finally {
    foreach ($r49rInvoices as $r49rId) {
        try { InvoiceService::cancel($r49rId, 'R49R'); } catch (Throwable) {}
    }
    foreach ($r49rInvoices as $r49rId) $pdo->exec("DELETE FROM invoices WHERE id = {$r49rId}");
    foreach ($r49rOrders as $r49rId) $pdo->exec("DELETE FROM orders WHERE id = {$r49rId}");
    $pdo->exec('DELETE FROM eur_exchange_rates');
    $pdo->exec('DELETE FROM eur_exchange_rate_history');
    $r49rRestore = $pdo->prepare('INSERT INTO eur_exchange_rates (currency, rate, as_of) VALUES (?, ?, ?)');
    foreach ($r49rRates as $r49rRate) $r49rRestore->execute($r49rRate);
    $r49rRestore = $pdo->prepare('INSERT INTO eur_exchange_rate_history (currency, as_of, rate) VALUES (?, ?, ?)');
    foreach ($r49rHistory as $r49rRate) $r49rRestore->execute($r49rRate);
    EurRates::reset();
    OssThreshold::reset();
}
