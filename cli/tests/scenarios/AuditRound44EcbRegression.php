<?php

// Review round 16 (R44, low 6), the ECB rates of OSS documents:
// - on a currency's first day (nothing in the week before it) every feed fetched again that day was bounded only by the
//   previous one (the stored rate, and its day's history, which each feed replaced): CZK walked 0.040 -> 0.058 -> 0.084 …
//   up to 10 times its anchor. The day's first feed stays its history now and bounds the later ones;
// - a rate only --force let through was taken by rateOn(): a document of its day was fixed in EUR at it for good.
echo "\n--- R44 ECB: the day's first feed anchors it, a forced rate fixes no document ---\n";

foreach (['20261113180000_invoice_oss_net', '20261113190000_oss_threshold_totals', '20261116110000_eur_exchange_rates', '20261116190000_invoice_oss_net_eur',
    '20261117100000_eur_rate_history', '20261118100000_eur_rates_forced'] as $r44eMigration) {
    if (is_file(TEST_CLI_DIR . "/../migrations/{$r44eMigration}.php")) (require TEST_CLI_DIR . "/../migrations/{$r44eMigration}.php")->up($pdo, Db::isSqlite());
}
Db::forgetSchema($pdo);
$r44eRates = $pdo->query('SELECT currency, rate, as_of FROM eur_exchange_rates')->fetchAll(PDO::FETCH_NUM);
$r44eHistory = $pdo->query('SELECT currency, as_of, rate FROM eur_exchange_rate_history')->fetchAll(PDO::FETCH_NUM);
$r44eInvoices = [];
$r44eOrders = [];
$r44eToday = gmdate('Y-m-d', ShopTime::now());
// A whole first feed (MIN_CURRENCIES): every currency at its anchor, CZK at $czkPerEur.
$r44eFeed = static function (string $czkPerEur, bool $all = true) use ($r44eToday): string {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><Cube><Cube time="' . $r44eToday . '">';
    foreach ($all ? EurRates::ANCHORS : ['CZK' => 0.0] as $code => $eurPerUnit) {
        $xml .= '<Cube currency="' . $code . '" rate="' . ($code === 'CZK' ? $czkPerEur : sprintf('%.6F', 1 / $eurPerUnit)) . '"/>';
    }
    return $xml . '</Cube></Cube></gesmes:Envelope>';
};
$r44eRefresh = static function (string $body, bool $force = false) use ($pdo): ?string {
    EurRates::setFixtureTransport(static fn(string $url): string => $body);
    try {
        EurRates::refresh($pdo, $force);
        return null;
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
};
$r44eCzk = static fn(): float => (float)$pdo->query("SELECT rate FROM eur_exchange_rates WHERE currency = 'CZK'")->fetchColumn();
try {
    // 1. The first day: nothing stored, nothing in the history.
    $pdo->exec('DELETE FROM eur_exchange_rate_history');
    $pdo->exec('DELETE FROM eur_exchange_rates');
    EurRates::reset();
    assert_equals(null, $r44eRefresh($r44eFeed('25.000')), 'R44 ECB: the first feed of the day (CZK 0.040 EUR) is stored (control)');
    assert_equals(null, $r44eRefresh($r44eFeed('17.241')), 'R44 ECB: a feed fetched again that day 45 % above it (0.058 EUR) is stored (control: within 50 %)');
    $r44eWalk = $r44eRefresh($r44eFeed('11.891')); // 0.0841 EUR: 45 % above the last feed, 110 % above the first
    assert_true($r44eWalk !== null && str_contains($r44eWalk, '50 %') && abs($r44eCzk() - 1 / 17.241) < 1e-9,
        'R44 ECB: a third feed that day 45 % above the second (0.084 EUR, twice the first) is refused, the stored rate stays (was: each feed moved it by 50 %) (got ' . json_encode([$r44eWalk, $r44eCzk()]) . ')');
    assert_true(abs((float)$pdo->query("SELECT rate FROM eur_exchange_rate_history WHERE currency = 'CZK'")->fetchColumn() - 0.04) < 1e-9,
        'R44 ECB: the day\'s history keeps its first feed (0.040 EUR), not the later ones');
    assert_equals(null, $r44eRefresh($r44eFeed('20.000')), 'R44 ECB: a later feed within 50 % of the first and the stored one (0.050 EUR) is stored (control)');

    // 2. A forced rate counts as today's rate, but no document is fixed in EUR at it.
    $pdo->exec('DELETE FROM eur_exchange_rate_history');
    $pdo->exec('DELETE FROM eur_exchange_rates');
    EurRates::reset();
    $r44eForced = $r44eRefresh($r44eFeed('12.500', false), true); // 0.08 EUR: twice the configured 0.040, one currency
    EurRates::reset();
    assert_true($r44eForced === null && abs((float)(EurRates::current()['rates']['czk'] ?? 0) - 0.08) < 1e-9,
        'R44 ECB: a feed only --force lets through (CZK 0.08 EUR) is stored as today\'s rate (control) (got ' . json_encode($r44eForced) . ')');
    assert_equals(null, EurRates::rateOn($pdo, 'CZK', $r44eToday), 'R44 ECB: rateOn() gives no rate of today from the forced feed (was: 0.08)');
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, net_total, currency, status, payment_status, payment_method, shipping_method, store_id, vat_scenario, created_at)
        VALUES ('R44E-FORCED', 'R44E', 'r44e@example.test', '', ?, 100000, 0, 0, 0, 100000, 100000, 'czk', 'delivered', 'paid', 'bank_transfer', 'gls', 'default', 'domestic_subthreshold', ?)")
        ->execute([json_encode(['country' => 'AT']), gmdate('Y-m-d H:i:s', ShopTime::now())]);
    $r44eOrders[] = $r44eOrder = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, vat_scenario, type)
        VALUES (?, 'default', 'R44E-FORCED', ?, '', 100000, 0, 100000, 'czk', 'issued', 'r44e-forced', 'domestic_subthreshold', 'invoice')")
        ->execute([$r44eOrder, gmdate('Y-m-d H:i:s', ShopTime::now())]);
    $r44eInvoices[] = $r44eInvoice = (int)$pdo->lastInsertId();
    OssThreshold::record($pdo, $r44eInvoice);
    assert_equals(null, ($v = $pdo->query("SELECT oss_net_eur FROM invoices WHERE id = {$r44eInvoice}")->fetchColumn()) === null || $v === false ? null : (int)$v,
        'R44 ECB: a document issued while the forced rate is stored is not fixed in EUR at it (was: 8 000 cents for good)');
    assert_equals(1, (int)$pdo->query("SELECT forced FROM eur_exchange_rates WHERE currency = 'CZK'")->fetchColumn(), 'R44 ECB: the stored rate is marked forced');
    assert_equals(null, $r44eRefresh($r44eFeed('22.000')), 'R44 ECB: the next regular feed (0.045 EUR, within 50 % of the forced one) is stored');
    assert_true((int)$pdo->query("SELECT forced FROM eur_exchange_rates WHERE currency = 'CZK'")->fetchColumn() === 0 && abs((float)EurRates::rateOn($pdo, 'CZK', $r44eToday) - 1 / 22) < 1e-9,
        'R44 ECB: a regular feed is not marked and fixes documents again (0.045)');
} catch (Throwable $r44eE) {
    assert_true(false, 'R44 ECB (' . get_class($r44eE) . ': ' . $r44eE->getMessage() . ' @ ' . basename($r44eE->getFile()) . ':' . $r44eE->getLine() . ')');
} finally {
    EurRates::setFixtureTransport(null);
    foreach ($r44eInvoices as $r44eId) {
        try { InvoiceService::cancel($r44eId, 'R44E'); } catch (Throwable) {}
        $pdo->exec("DELETE FROM invoices WHERE refers_to_invoice_id = {$r44eId}");
        $pdo->exec("DELETE FROM invoices WHERE id = {$r44eId}");
    }
    foreach ($r44eOrders as $r44eId) $pdo->exec("DELETE FROM orders WHERE id = {$r44eId}");
    $pdo->exec('DELETE FROM eur_exchange_rates');
    $pdo->exec('DELETE FROM eur_exchange_rate_history');
    $r44eRestore = $pdo->prepare('INSERT INTO eur_exchange_rates (currency, rate, as_of) VALUES (?, ?, ?)');
    foreach ($r44eRates as $r44eRate) $r44eRestore->execute($r44eRate);
    $r44eHistoryRow = $pdo->prepare('INSERT INTO eur_exchange_rate_history (currency, as_of, rate) VALUES (?, ?, ?)');
    foreach ($r44eHistory as $r44eRow) $r44eHistoryRow->execute($r44eRow);
    EurRates::reset();
    OssThreshold::reset();
}
