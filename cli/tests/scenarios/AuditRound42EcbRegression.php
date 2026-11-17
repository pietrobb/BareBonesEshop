<?php

// Review round 16 (R42, low 4), the ECB rates of OSS documents:
// - a document was fixed in EUR at a stale daily rate (the cron stopped for five days: the rate of five days before), and
//   cli/eur-rates.php converted a Sunday's document at Wednesday's rate before it fetched Thursday's and Friday's. Only the
//   rate of the last day the ECB published on (EurRates::publicationDay()) is taken now, or an older one where the history
//   fetched in the same run shows nothing was published in between;
// - --force chained CZK 0.041 -> 0.41 -> 4.03 EUR (each 10 times the stored one) and the forced rate stayed in the history;
// - a credit note of an invoice of an older year (not counted any more) waited for good: doctor warning, history download;
// - the history was parsed with LIBXML_PARSEHUGE and its size checked only after the whole download.
echo "\n--- R42 ECB: the rate of the last publication day, --force bounded by every anchor, old credit notes not pending ---\n";

foreach (['20261113180000_invoice_oss_net', '20261113190000_oss_threshold_totals', '20261116110000_eur_exchange_rates', '20261116190000_invoice_oss_net_eur', '20261117100000_eur_rate_history'] as $r42eMigration) {
    (require TEST_CLI_DIR . "/../migrations/{$r42eMigration}.php")->up($pdo, Db::isSqlite());
}
Db::forgetSchema($pdo);
$r42eRates = $pdo->query('SELECT currency, rate, as_of FROM eur_exchange_rates')->fetchAll(PDO::FETCH_NUM);
$r42eHistory = $pdo->query('SELECT currency, as_of, rate FROM eur_exchange_rate_history')->fetchAll(PDO::FETCH_NUM);
$r42eInvoices = [];
$r42eRaw = []; // inserted with oss_net directly, never added to the totals: deleted, not cancelled
$r42eOrders = [];
$r42eShift = static fn(string $date, int $days): string => gmdate('Y-m-d', (int)strtotime($date . ' 00:00:00 UTC') + $days * 86400);
$r42eOrder = static function (string $number, string $createdAt) use ($pdo, &$r42eOrders): int {
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, net_total, currency, status, payment_status, payment_method, shipping_method, store_id, vat_scenario, created_at)
        VALUES (?, 'R42E', 'r42e@example.test', '', ?, 100000, 0, 0, 0, 100000, 100000, 'czk', 'delivered', 'paid', 'bank_transfer', 'gls', 'default', 'domestic_subthreshold', ?)")
        ->execute([$number, json_encode(['country' => 'AT']), $createdAt]);
    return $r42eOrders[] = (int)$pdo->lastInsertId();
};
$r42eIssue = static function (string $number, string $issuedAt) use ($pdo, &$r42eInvoices, $r42eOrder): int {
    $order = $r42eOrder($number, $issuedAt);
    $pdo->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, vat_scenario, type)
        VALUES (?, 'default', ?, ?, '', 100000, 0, 100000, 'czk', 'issued', ?, 'domestic_subthreshold', 'invoice')")
        ->execute([$order, $number, $issuedAt, strtolower($number)]);
    $r42eInvoices[] = $invoice = (int)$pdo->lastInsertId();
    OssThreshold::record($pdo, $invoice);
    return $invoice;
};
$r42eEur = static fn(int $id): ?int => ($v = $pdo->query("SELECT oss_net_eur FROM invoices WHERE id = {$id}")->fetchColumn()) === null || $v === false ? null : (int)$v;
$r42eFeed = static function (array $days): string {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><Cube>';
    foreach ($days as $date => $rates) {
        $xml .= '<Cube time="' . $date . '">';
        foreach ($rates as $code => $perEur) $xml .= '<Cube currency="' . $code . '" rate="' . $perEur . '"/>';
        $xml .= '</Cube>';
    }
    return $xml . '</Cube></gesmes:Envelope>';
};
$r42eHistoryRow = $pdo->prepare('INSERT INTO eur_exchange_rate_history (currency, as_of, rate) VALUES (?, ?, ?)');
try {
    // The publication calendar: weekends, 1 January, Good Friday, Easter Monday, 1 May, 25 and 26 December.
    assert_equals(['2026-04-02', '2026-04-02', '2026-04-07', '2026-12-24', '2025-12-31', '2026-11-13', '2026-11-16'],
        is_callable([EurRates::class, 'publicationDay']) ? array_map([EurRates::class, 'publicationDay'], ['2026-04-03', '2026-04-06', '2026-04-07', '2026-12-27', '2026-01-01', '2026-11-15', '2026-11-16']) : 'no publicationDay()',
        'R42 ECB: the last publication day skips weekends and TARGET holidays (Good Friday, Easter Monday, Christmas, New Year)');

    // A Friday of this year at least two weeks ago, its Monday to Thursday working days outside Easter, May and Christmas.
    $r42eFri = null;
    for ($r42eBack = 14; $r42eBack < 90 && $r42eFri === null; $r42eBack++) {
        $r42eDay = gmdate('Y-m-d', ShopTime::now() - $r42eBack * 86400);
        if (gmdate('N', (int)strtotime($r42eDay . ' UTC')) === '5' && !in_array((int)substr($r42eDay, 5, 2), [1, 3, 4, 5, 12], true)
            && substr($r42eShift($r42eDay, -5), 0, 4) === ShopTime::date('Y')) $r42eFri = $r42eDay;
    }
    if ($r42eFri === null) throw new RuntimeException('R42E: no suitable Friday found');
    [$r42eWed, $r42eThu, $r42eSun, $r42eMonBefore] = [$r42eShift($r42eFri, -2), $r42eShift($r42eFri, -1), $r42eShift($r42eFri, 2), $r42eShift($r42eFri, -4)];

    $pdo->exec('DELETE FROM eur_exchange_rate_history');
    $pdo->exec('DELETE FROM eur_exchange_rates');
    EurRates::reset();
    // The cron stopped: the last rate stored is Monday's. A Friday document is not fixed at it (was: five days old).
    $r42eHistoryRow->execute(['CZK', $r42eMonBefore, 0.0400]);
    $r42eFriDoc = $r42eIssue('R42E-FRI', $r42eFri . ' 18:00:00');
    assert_equals(null, $r42eEur($r42eFriDoc), 'R42 ECB: a Friday document is not fixed in EUR at Monday\'s rate when the cron stopped (was: the rate of five days before)');
    // Wednesday's rate is stored: a Sunday document is not fixed at it either (Friday's is the one).
    $r42eHistoryRow->execute(['CZK', $r42eWed, 0.0410]);
    $r42eSunDoc = $r42eIssue('R42E-SUN', $r42eSun . ' 10:00:00');
    assert_equals(null, $r42eEur($r42eSunDoc), 'R42 ECB: a Sunday document is not fixed at Wednesday\'s rate (Friday is the last publication day before it)');
    assert_equals(null, EurRates::rateOn($pdo, 'CZK', $r42eSun), 'R42 ECB: rateOn() of the Sunday gives no rate while Friday\'s is not stored');
    $r42eHistoryRow->execute(['CZK', $r42eFri, 0.0400]);
    assert_equals(0.04, EurRates::rateOn($pdo, 'CZK', $r42eSun), 'R42 ECB: with Friday\'s rate stored, the Sunday has it');
    $pdo->prepare('DELETE FROM eur_exchange_rate_history WHERE as_of = ?')->execute([$r42eFri]);

    // cli/eur-rates.php: the history is fetched before a stale rate is taken; the Sunday document gets Friday's rate.
    EurRates::setFixtureTransport(static function (string $url) use ($r42eFeed, $r42eWed, $r42eThu, $r42eFri): string {
        if ($url === EurRates::FEED) throw new RuntimeException('R42E: no daily feed');
        if ($url === EurRates::HISTORY_90D) return $r42eFeed([$r42eWed => ['CZK' => '24.390'], $r42eThu => ['CZK' => '25.000'], $r42eFri => ['CZK' => '20.000']]);
        throw new RuntimeException('R42E: unexpected feed ' . $url);
    });
    $r42eRun = EurRates::daily($pdo);
    assert_equals([5000, 5000], [$r42eEur($r42eSunDoc), $r42eEur($r42eFriDoc)],
        'R42 ECB: daily() converts the Sunday and the Friday documents at Friday\'s rate (20 CZK/EUR), not Wednesday\'s or Monday\'s (got ' . json_encode($r42eRun) . ')');

    // The fetched history shows the days the ECB published nothing: an older rate is the one there (a closure the
    // calendar does not know).
    $pdo->exec('DELETE FROM eur_exchange_rate_history');
    EurRates::reset();
    $r42eHistoryRow->execute(['CZK', $r42eWed, 0.0410]);
    assert_equals(null, EurRates::rateOn($pdo, 'CZK', $r42eFri), 'R42 ECB: Friday has no rate from Wednesday by the calendar alone');
    EurRates::setFixtureTransport(static fn(string $url): string => $r42eFeed([$r42eWed => ['CZK' => '24.390'], $r42eShift($r42eFri, 3) => ['CZK' => '24.000']]));
    EurRates::fetchHistory($pdo, $r42eFri, ['CZK']);
    assert_true(abs((float)EurRates::rateOn($pdo, 'CZK', $r42eFri) - 1 / 24.39) < 1e-9, 'R42 ECB: the history fetched in the run shows nothing published on Thursday and Friday: Wednesday\'s rate is Friday\'s');

    // --force: every anchor bounds it, and a forced feed stays out of the history.
    $pdo->exec('DELETE FROM eur_exchange_rate_history');
    $pdo->exec('DELETE FROM eur_exchange_rates');
    EurRates::reset();
    $r42eToday = gmdate('Y-m-d', ShopTime::now());
    $r42eForced = static fn(string $perEur): string => $r42eFeed([$r42eToday => ['CZK' => $perEur]]);
    EurRates::setFixtureTransport(static fn(string $url): string => $r42eForced('2.564')); // 0.39 EUR: 9.75 times the configured 0.040
    $r42eFirst = EurRates::refresh($pdo, true);
    assert_equals(1, $r42eFirst['count'], 'R42 ECB: a forced first feed of 0.39 EUR per CZK (under 10 times the configured and anchor rate) is stored');
    assert_equals(0, (int)$pdo->query('SELECT COUNT(*) FROM eur_exchange_rate_history')->fetchColumn(), 'R42 ECB: the forced rate is not written to the history (was: for good, the next feeds\' reference)');
    EurRates::setFixtureTransport(static fn(string $url): string => $r42eForced('0.2632')); // 3.8 EUR: 9.7 times the stored 0.39
    $r42eChained = null;
    try {
        EurRates::refresh($pdo, true);
    } catch (RuntimeException $r42eErr) {
        $r42eChained = $r42eErr->getMessage();
    }
    assert_true($r42eChained !== null && str_contains($r42eChained, 'not even with --force'),
        'R42 ECB: a second forced feed 10 times the forced one (3.8 EUR per CZK) is refused by the configured and anchor rates (was: CZK 0.041 -> 0.41 -> 4.03) (got ' . json_encode($r42eChained) . ')');
    assert_true(abs((float)$pdo->query("SELECT rate FROM eur_exchange_rates WHERE currency = 'CZK'")->fetchColumn() - 1 / 2.564) < 1e-9, 'R42 ECB: the stored rate stays');

    // A credit note of an invoice of an older year is not pending.
    $pdo->exec('DELETE FROM eur_exchange_rates');
    EurRates::reset();
    $r42ePendingBefore = OssThreshold::pending($pdo)['pending'];
    $r42eOldYear = (int)ShopTime::date('Y') - 3;
    $r42eOldOrder = $r42eOrder('R42E-OLD', "{$r42eOldYear}-06-01 10:00:00");
    $pdo->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, vat_scenario, type, oss_net)
        VALUES (?, 'default', 'R42E-OLD', ?, '', 100000, 0, 100000, 'czk', 'issued', 'r42e-old', 'domestic_subthreshold', 'invoice', 100000)")->execute([$r42eOldOrder, "{$r42eOldYear}-06-01 10:00:00"]);
    $r42eRaw[] = $r42eOldInvoice = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, vat_scenario, type, refers_to_invoice_id, oss_net)
        VALUES (?, 'default', 'R42E-OLD-CN', ?, '', -20000, 0, -20000, 'czk', 'issued', 'r42e-old-cn', 'domestic_subthreshold', 'credit_note', ?, -20000)")
        ->execute([$r42eOldOrder, gmdate('Y-m-d H:i:s', ShopTime::now()), $r42eOldInvoice]);
    $r42eRaw[] = (int)$pdo->lastInsertId();
    assert_equals($r42ePendingBefore, OssThreshold::pending($pdo)['pending'],
        'R42 ECB: a credit note of an invoice of ' . $r42eOldYear . ' (a year the threshold no longer counts) does not wait for a rate (was: for good, doctor warning and history download)');

    // The history download: no LIBXML_PARSEHUGE, the size bounded while it downloads.
    $r42eSource = (string)file_get_contents(TEST_CLI_DIR . '/../src/EurRates.php');
    assert_true(!str_contains(preg_replace('/^\s*\/\/.*$/m', '', $r42eSource), 'LIBXML_PARSEHUGE | ') && !preg_match('/simplexml_load_string\([^)]*PARSEHUGE/', $r42eSource)
        && str_contains($r42eSource, 'CURLOPT_WRITEFUNCTION') && str_contains($r42eSource, 'CURLOPT_MAXFILESIZE'),
        'R42 ECB: the ECB XML is parsed without LIBXML_PARSEHUGE and the download stops past its limit');
} catch (Throwable $r42eE) {
    assert_true(false, 'R42 ECB (' . get_class($r42eE) . ': ' . $r42eE->getMessage() . ' @ ' . basename($r42eE->getFile()) . ':' . $r42eE->getLine() . ')');
} finally {
    EurRates::setFixtureTransport(null);
    foreach ($r42eInvoices as $r42eId) {
        try { InvoiceService::cancel($r42eId, 'R42E'); } catch (Throwable) {}
    }
    foreach (array_merge($r42eInvoices, array_reverse($r42eRaw)) as $r42eId) $pdo->exec("DELETE FROM invoices WHERE id = {$r42eId}");
    foreach ($r42eOrders as $r42eId) $pdo->exec("DELETE FROM orders WHERE id = {$r42eId}");
    $pdo->exec('DELETE FROM eur_exchange_rates');
    $pdo->exec('DELETE FROM eur_exchange_rate_history');
    $r42eRestore = $pdo->prepare('INSERT INTO eur_exchange_rates (currency, rate, as_of) VALUES (?, ?, ?)');
    foreach ($r42eRates as $r42eRate) $r42eRestore->execute($r42eRate);
    foreach ($r42eHistory as $r42eRow) $r42eHistoryRow->execute($r42eRow);
    EurRates::reset();
    OssThreshold::reset();
}
