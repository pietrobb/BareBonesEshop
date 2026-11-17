<?php

// Review round 15 (R41, medium 3): a document in another currency was fixed in EUR (invoices.oss_net_eur) at whatever
// rate was current when it was issued — a stale stored one or the configured fallback alike — and nothing converted it
// again; documents issued before R40 were never converted. Now only the ECB rate of the day of issue
// (EurRates::rateOn(), within ISSUE_WINDOW_DAYS) is fixed; without one the document waits, counted at today's rate,
// until cli/eur-rates.php converts it from the ECB history (OssThreshold::convertPending()), and the doctor says so.
echo "\n--- R41 OSS EUR at issue: only the ECB rate of the day, pending documents converted later ---\n";

foreach (['20261113180000_invoice_oss_net', '20261113190000_oss_threshold_totals', '20261116110000_eur_exchange_rates', '20261116190000_invoice_oss_net_eur', '20261117100000_eur_rate_history'] as $r41rMigration) {
    (require TEST_CLI_DIR . "/../migrations/{$r41rMigration}.php")->up($pdo, Db::isSqlite());
}
Db::forgetSchema($pdo);
$r41rYear = (int)ShopTime::date('Y');
$r41rRates = $pdo->query('SELECT currency, rate, as_of FROM eur_exchange_rates')->fetchAll(PDO::FETCH_NUM);
$r41rInvoices = [];
$r41rOrders = [];
$r41rDay = static fn(int $daysAgo): string => gmdate('Y-m-d', ShopTime::now() - $daysAgo * 86400);
$r41rTotal = static fn(string $currency): int => (int)$pdo->query("SELECT COALESCE(SUM(net), 0) FROM oss_threshold_totals WHERE store_id = 'default' AND year = {$r41rYear} AND currency = " . $pdo->quote($currency))->fetchColumn();
$r41rIssue = static function (string $number, string $issuedAt) use ($pdo, &$r41rInvoices, &$r41rOrders, $r41rYear): int {
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, net_total, currency, status, payment_status, payment_method, shipping_method, store_id, vat_scenario, created_at)
        VALUES (?, 'R41R', 'r41r@example.test', '', ?, 100000, 0, 0, 0, 100000, 100000, 'czk', 'delivered', 'paid', 'bank_transfer', 'gls', 'default', 'domestic_subthreshold', ?)")
        ->execute([$number, json_encode(['country' => 'AT']), $issuedAt]);
    $r41rOrders[] = $order = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, vat_scenario, type)
        VALUES (?, 'default', ?, ?, '', 100000, 0, 100000, 'czk', 'issued', ?, 'domestic_subthreshold', 'invoice')")
        ->execute([$order, $number, $issuedAt, strtolower($number)]);
    $r41rInvoices[] = $invoice = (int)$pdo->lastInsertId();
    OssThreshold::record($pdo, $invoice);
    return $invoice;
};
$r41rStored = static fn(int $id): array => array_map(static fn($v): ?int => $v === null ? null : (int)$v, $pdo->query("SELECT oss_net, oss_net_eur FROM invoices WHERE id = {$id}")->fetch(PDO::FETCH_NUM));
$r41rFeed = static function (array $days): string {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><Cube>';
    foreach ($days as $date => $rates) {
        $xml .= '<Cube time="' . $date . '">';
        foreach ($rates as $code => $perEur) $xml .= '<Cube currency="' . $code . '" rate="' . $perEur . '"/>';
        $xml .= '</Cube>';
    }
    return $xml . '</Cube></gesmes:Envelope>';
};
try {
    $pdo->exec('DELETE FROM eur_exchange_rate_history');
    // A stale stored rate (200 days old) is not fixed in the document.
    $pdo->exec('DELETE FROM eur_exchange_rates');
    $pdo->prepare('INSERT INTO eur_exchange_rates (currency, rate, as_of) VALUES (?, ?, ?)')->execute(['CZK', 0.05, $r41rDay(200)]);
    EurRates::reset();
    [$r41rEurBefore, $r41rCzkBefore] = [$r41rTotal('eur'), $r41rTotal('czk')];
    $r41rToday = $r41rIssue('R41R-TODAY', gmdate('Y-m-d H:i:s', ShopTime::now()));
    assert_equals([100000, null], $r41rStored($r41rToday), 'R41 rate: an invoice issued while the stored ECB rate is 200 days old is not fixed in EUR at it (oss_net_eur stays empty)');
    assert_equals([0, 100000], [$r41rTotal('eur') - $r41rEurBefore, $r41rTotal('czk') - $r41rCzkBefore], 'R41 rate: it counts in CZK at today\'s rate meanwhile');
    // The configured fallback (no stored rates) is never fixed either.
    $pdo->exec('DELETE FROM eur_exchange_rates');
    EurRates::reset();
    $r41rConfigured = $r41rIssue('R41R-CONFIG', gmdate('Y-m-d H:i:s', ShopTime::now()));
    assert_equals([100000, null], $r41rStored($r41rConfigured), 'R41 rate: an invoice issued at the configured rate only is not fixed in EUR');
    // One issued 40 days ago, before oss_net_eur (as an invoice of R39 and earlier).
    $r41rOld = $r41rIssue('R41R-OLD', $r41rDay(40) . ' 09:00:00');
    assert_equals([100000, null], $r41rStored($r41rOld), 'R41 rate: an invoice of 40 days ago without any ECB rate of its day waits');

    $r41rPending = OssThreshold::pendingOfSeller($pdo, 'default', (array)(Bootstrap::stores()['stores']['default'] ?? []), (array)(Bootstrap::stores()['stores'] ?? []));
    assert_true($r41rPending['pending'] >= 3 && $r41rPending['oldest'] === $r41rDay(40) && in_array('CZK', $r41rPending['currencies'], true),
        'R41 rate: the seller\'s pending documents are reported with the oldest day of issue (got ' . json_encode($r41rPending) . ')');
    $r41rDoctor = Setup::ossThresholdCheck($pdo, 'default', (array)(Bootstrap::stores()['stores']['default'] ?? []), (array)(Bootstrap::stores()['stores'] ?? []));
    assert_true($r41rDoctor['status'] === 'warn', 'R41 rate: the doctor does not pass while documents wait for the rate of their day (got ' . $r41rDoctor['message'] . ')');

    // cli/eur-rates.php: the ECB history converts each at the rate of its day; an implausible rate is refused.
    EurRates::setFixtureTransport(static function (string $url) use ($r41rFeed, $r41rDay): string {
        if ($url !== EurRates::HISTORY_90D) throw new RuntimeException('R41R: unexpected feed ' . $url);
        return $r41rFeed([$r41rDay(0) => ['CZK' => '25.000', 'PLN' => '0.000000001'], $r41rDay(41) => ['CZK' => '20.000'], $r41rDay(43) => ['CZK' => '10.000']]);
    });
    $r41rHistory = EurRates::fetchHistory($pdo, $r41rDay(40), ['CZK', 'PLN']);
    $r41rConverted = OssThreshold::convertPending($pdo);
    assert_equals([[100000, 4000], [100000, 4000], [100000, 5000]], [$r41rStored($r41rToday), $r41rStored($r41rConfigured), $r41rStored($r41rOld)],
        'R41 rate: converted at the ECB rate of their day (1 000 CZK at 25 CZK/EUR today; the 40-day-old one at 20 CZK/EUR published the day before), not at the stale or configured rate');
    assert_equals([13000, 0], [$r41rTotal('eur') - $r41rEurBefore, $r41rTotal('czk') - $r41rCzkBefore],
        'R41 rate: their CZK left the CZK total and their EUR joined the EUR total');
    assert_true($r41rConverted['converted'] >= 3 && in_array('PLN ' . $r41rDay(0), $r41rHistory['refused'], true),
        'R41 rate: three documents converted; a PLN rate of 1e9 per EUR is refused, not stored (got ' . json_encode([$r41rConverted, $r41rHistory]) . ')');
    assert_equals(null, EurRates::rateOn($pdo, 'CZK', $r41rDay(60)), 'R41 rate: no rate of a day more than ' . EurRates::ISSUE_WINDOW_DAYS . ' days after the last one published');

    // A later move of the rate changes nothing: the documents count at their day's rate.
    $pdo->exec('DELETE FROM eur_exchange_rates');
    $pdo->prepare('INSERT INTO eur_exchange_rates (currency, rate, as_of) VALUES (?, ?, ?)')->execute(['CZK', 0.08, $r41rDay(0)]);
    EurRates::reset();
    assert_equals(0, OssThreshold::convertPending($pdo)['converted'], 'R41 rate: converted documents are not converted again');
} catch (Throwable $r41rE) {
    assert_true(false, 'R41 OSS rate (' . get_class($r41rE) . ': ' . $r41rE->getMessage() . ' @ ' . basename($r41rE->getFile()) . ':' . $r41rE->getLine() . ')');
} finally {
    EurRates::setFixtureTransport(null);
    foreach ($r41rInvoices as $r41rId) {
        try { InvoiceService::cancel($r41rId, 'R41R'); } catch (Throwable) {}
    }
    foreach ($r41rInvoices as $r41rId) $pdo->exec("DELETE FROM invoices WHERE id = {$r41rId}");
    foreach ($r41rOrders as $r41rId) $pdo->exec("DELETE FROM orders WHERE id = {$r41rId}");
    $pdo->exec('DELETE FROM eur_exchange_rates');
    $pdo->exec('DELETE FROM eur_exchange_rate_history');
    $r41rRestore = $pdo->prepare('INSERT INTO eur_exchange_rates (currency, rate, as_of) VALUES (?, ?, ?)');
    foreach ($r41rRates as $r41rRate) $r41rRestore->execute($r41rRate);
    EurRates::reset();
    OssThreshold::reset();
}
