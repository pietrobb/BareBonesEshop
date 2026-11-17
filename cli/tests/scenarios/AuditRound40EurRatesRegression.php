<?php
$pdo ??= test_commerce_fixture();

// Review round 14 (R40, low): the ECB feed cli/eur-rates.php stores (EurRates::refresh()) was trusted as it came. A rate of
// 1e-9 inflated the OSS threshold (an activation that stays), a date of 2099-12-31 outranked the configured rates for good
// without a doctor warning, and a feed of one currency deleted all others. The stored rates are kept now and the
// merchant forces a checked feed (--force); rates dated after today are not used and the doctor names them.
echo "\n--- R40 ECB rates: future date, implausible rate, incomplete feed refused; future-dated rates not used ---\n";

Bootstrap::initStoreContext('/', '', 'default');
(require TEST_CLI_DIR . '/../migrations/20261116110000_eur_exchange_rates.php')->up($pdo, Db::isSqlite());
$r40eFeed = static fn(string $day, array $perEur): string => '<?xml version="1.0" encoding="UTF-8"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
<gesmes:subject>Reference rates</gesmes:subject><Cube><Cube time="' . $day . '">'
    . implode('', array_map(static fn(string $code, $rate): string => '<Cube currency="' . $code . '" rate="' . $rate . '"/>', array_keys($perEur), $perEur))
    . '</Cube></Cube></gesmes:Envelope>';
$r40eAll = ['USD' => 1.08, 'JPY' => 160, 'BGN' => 1.9558, 'CZK' => 25.2, 'DKK' => 7.46, 'GBP' => 0.84, 'HUF' => 395, 'PLN' => 4.3, 'RON' => 4.97,
    'SEK' => 11.5, 'CHF' => 0.94, 'ISK' => 150, 'NOK' => 11.7, 'TRY' => 37, 'AUD' => 1.65, 'BRL' => 6.2, 'CAD' => 1.5, 'CNY' => 7.8, 'HKD' => 8.4,
    'IDR' => 17000, 'ILS' => 4.0, 'INR' => 90, 'KRW' => 1500, 'MXN' => 21, 'MYR' => 4.8, 'NZD' => 1.8, 'PHP' => 62, 'SGD' => 1.45, 'THB' => 37, 'ZAR' => 19.5];
$r40eYesterday = gmdate('Y-m-d', ShopTime::now() - 86400);
$r40eRefresh = static function (string $feed, bool $force = false) use ($pdo): string {
    EurRates::setFixtureTransport(static fn(string $url): string => $feed);
    EurRates::reset();
    try {
        EurRates::refresh($pdo, $force);
        return 'stored';
    } catch (RuntimeException $e) {
        return 'refused';
    } finally {
        EurRates::reset();
    }
};
$r40eStored = static fn(): array => $pdo->query('SELECT currency, rate, as_of FROM eur_exchange_rates ORDER BY currency')->fetchAll(PDO::FETCH_ASSOC);
$r40eConfigured = (string)(VatService::config()['eur_exchange_rates_as_of'] ?? '');
// R42 (low 8): the ECB history (R41) bounds a feed by the week before it; what other scenarios left is set aside, so this
// one passes in any order, and what it stores is removed again.
$r40eHistory = Db::tableExists('eur_exchange_rate_history', $pdo) ? $pdo->query('SELECT currency, as_of, rate FROM eur_exchange_rate_history')->fetchAll(PDO::FETCH_NUM) : null;
try {
    if ($r40eHistory !== null) $pdo->exec('DELETE FROM eur_exchange_rate_history');
    $pdo->exec('DELETE FROM eur_exchange_rates');
    assert_equals(['refused', [], $r40eConfigured], [$r40eRefresh($r40eFeed('2099-12-31', $r40eAll)), $r40eStored(), EurRates::current()['as_of']],
        'R40 ECB rates: a feed dated after today is refused; the configured rates stay in use (was: stored, outranking them for good)');
    assert_equals(['refused', []], [$r40eRefresh($r40eFeed($r40eYesterday, ['USD' => 1.08])), $r40eStored()],
        'R40 ECB rates: a first feed of one currency is refused');
    assert_equals(['stored', 30, $r40eYesterday], [$r40eRefresh($r40eFeed($r40eYesterday, $r40eAll)), count($r40eStored()), EurRates::current()['as_of']],
        'R40 ECB rates: a complete feed of yesterday is stored and used');
    $r40eBefore = $r40eStored();

    assert_equals(['refused', $r40eBefore], [$r40eRefresh($r40eFeed($r40eYesterday, ['CZK' => 1e9] + $r40eAll)), $r40eStored()],
        'R40 ECB rates: CZK at 1e-9 EUR (more than 50 % away from the stored rate) is refused, the stored rates kept (was: stored, threshold inflated)');
    assert_equals(['refused', $r40eBefore], [$r40eRefresh($r40eFeed($r40eYesterday, ['USD' => 1.09])), $r40eStored()],
        'R40 ECB rates: a feed lacking stored currencies is refused (was: every other currency deleted)');
    assert_equals(['refused', $r40eBefore], [$r40eRefresh($r40eFeed(gmdate('Y-m-d', ShopTime::now() - 10 * 86400), $r40eAll)), $r40eStored()],
        'R40 ECB rates: a feed older than the stored rates is refused');

    // Checked by the merchant: --force stores a large move and a currency the ECB stopped publishing; never a future date.
    $r40eMoved = ['TRY' => 100] + $r40eAll;
    unset($r40eMoved['ISK']);
    assert_equals(['stored', 29], [$r40eRefresh($r40eFeed($r40eYesterday, $r40eMoved), true), count($r40eStored())],
        'R40 ECB rates: --force stores a checked feed with a large move and without a currency');
    assert_equals('refused', $r40eRefresh($r40eFeed('2099-12-31', $r40eAll), true), 'R40 ECB rates: --force never stores a future date');

    // Rates an earlier version stored with a future date: not used, named by the doctor, replaced by the next feed.
    $pdo->exec("UPDATE eur_exchange_rates SET as_of = '2099-12-31', rate = 0.000000001");
    EurRates::reset();
    $r40eFuture = method_exists(EurRates::class, 'futureDated') ? EurRates::futureDated() : 'n/a';
    assert_equals([$r40eConfigured, '2099-12-31'], [EurRates::current()['as_of'], $r40eFuture],
        'R40 ECB rates: rates stored with a future date are not used, and their date is reported (was: used for good, no warning)');
    assert_equals(['stored', $r40eYesterday, null], [$r40eRefresh($r40eFeed($r40eYesterday, $r40eAll)), EurRates::current()['as_of'],
        method_exists(EurRates::class, 'futureDated') ? EurRates::futureDated() : 'n/a'],
        'R40 ECB rates: the next feed replaces the future-dated rates');
    $r40eSetup = (string)file_get_contents(TEST_CLI_DIR . '/../src/Setup.php');
    assert_true(str_contains($r40eSetup, 'EurRates::futureDated()') && str_contains($r40eSetup, 'after today; they are not used'),
        'R40 ECB rates: the setup doctor warns about rates dated after today');
} catch (Throwable $r40eE) {
    assert_true(false, 'R40 ECB rates (' . get_class($r40eE) . ': ' . $r40eE->getMessage() . ' @ ' . basename($r40eE->getFile()) . ':' . $r40eE->getLine() . ')');
} finally {
    EurRates::setFixtureTransport(null);
    $pdo->exec('DELETE FROM eur_exchange_rates');
    if ($r40eHistory !== null) {
        $pdo->exec('DELETE FROM eur_exchange_rate_history');
        $r40eRestore = $pdo->prepare('INSERT INTO eur_exchange_rate_history (currency, as_of, rate) VALUES (?, ?, ?)');
        foreach ($r40eHistory as $r40eRow) $r40eRestore->execute($r40eRow);
    }
    EurRates::reset();
}
