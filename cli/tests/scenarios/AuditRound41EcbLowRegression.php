<?php
$pdo ??= test_commerce_fixture();

// Review round 15 (R41, low 9): the ECB feed checks of R40 (EurRates::refresh()) had gaps:
// 1. A first feed checked only the configured currencies: JPY at 1e-9 EUR was stored (the configuration lacks it).
// 2. A rate was bounded by the stored one only, so feeds fetched again the same day moved it by 49 % each.
// 3. --force stored any rate (1e-9, 1e9), not only a checked large move.
echo "\n--- R41 ECB low: unconfigured currencies on a first feed, repeated same-day feeds, bounded --force ---\n";

Bootstrap::initStoreContext('/', '', 'default');
(require TEST_CLI_DIR . '/../migrations/20261116110000_eur_exchange_rates.php')->up($pdo, Db::isSqlite());
(require TEST_CLI_DIR . '/../migrations/20261117100000_eur_rate_history.php')->up($pdo, Db::isSqlite());
$r41eFeed = static fn(string $day, array $perEur): string => '<?xml version="1.0" encoding="UTF-8"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
<gesmes:subject>Reference rates</gesmes:subject><Cube><Cube time="' . $day . '">'
    . implode('', array_map(static fn(string $code, $rate): string => '<Cube currency="' . $code . '" rate="' . $rate . '"/>', array_keys($perEur), $perEur))
    . '</Cube></Cube></gesmes:Envelope>';
$r41eAll = ['USD' => 1.16, 'JPY' => 175, 'BGN' => 1.9558, 'CZK' => 24.3, 'DKK' => 7.46, 'GBP' => 0.87, 'HUF' => 385, 'PLN' => 4.25, 'RON' => 5.08,
    'SEK' => 11.0, 'CHF' => 0.93, 'ISK' => 143, 'NOK' => 11.7, 'TRY' => 48, 'AUD' => 1.78, 'BRL' => 6.2, 'CAD' => 1.62, 'CNY' => 8.3, 'HKD' => 9.0,
    'IDR' => 19300, 'ILS' => 3.8, 'INR' => 102, 'KRW' => 1680, 'MXN' => 21.3, 'MYR' => 4.85, 'NZD' => 2.03, 'PHP' => 68, 'SGD' => 1.51, 'THB' => 37.8, 'ZAR' => 20.3];
$r41eDay = static fn(int $ago): string => gmdate('Y-m-d', ShopTime::now() - $ago * 86400);
$r41eRefresh = static function (string $feed, bool $force = false) use ($pdo): string {
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
$r41eRate = static function (string $code) use ($pdo): ?float {
    $stmt = $pdo->prepare('SELECT rate FROM eur_exchange_rates WHERE currency = ?');
    $stmt->execute([$code]);
    $rate = $stmt->fetchColumn();
    return $rate === false ? null : round((float)$rate, 6);
};
$r41eUsd = round(1 / 1.16, 6);
try {
    $pdo->exec('DELETE FROM eur_exchange_rates');
    $pdo->exec('DELETE FROM eur_exchange_rate_history');
    assert_true(!isset(VatService::config()['eur_exchange_rates']['JPY']) && !isset(VatService::config()['eur_exchange_rates']['jpy']),
        'R41 ECB low: the sample configuration has no JPY rate (the case reviewed)');

    // 1. A first feed: an unconfigured currency is checked against the approximate anchors.
    assert_equals(['refused', null], [$r41eRefresh($r41eFeed($r41eDay(3), ['JPY' => 1e9] + $r41eAll)), $r41eRate('JPY')],
        'R41 ECB low: a first feed with JPY at 1e-9 EUR (not configured) is refused (was: stored, threshold inflated)');
    assert_equals('refused', $r41eRefresh($r41eFeed($r41eDay(3), ['JPY' => 1.75] + $r41eAll)),
        'R41 ECB low: a first feed with JPY 100 times too valuable (not configured) is refused');
    assert_equals(['stored', $r41eUsd], [$r41eRefresh($r41eFeed($r41eDay(3), $r41eAll)), $r41eRate('USD')],
        'R41 ECB low: a plausible first feed is stored (control)');
    assert_equals('stored', $r41eRefresh($r41eFeed($r41eDay(2), $r41eAll)), 'R41 ECB low: the next day\'s feed is stored (control)');

    // 2. Feeds fetched again the same day: each 49 % up from the stored one, not 49 % from the day before.
    $r41eUp = static fn(float $factor): array => ['USD' => 1.16 / $factor] + $r41eAll;
    assert_equals('stored', $r41eRefresh($r41eFeed($r41eDay(1), $r41eUp(1.45))), 'R41 ECB low: USD 45 % up in a day is stored (control)');
    assert_equals(['refused', round(1.45 / 1.16, 6)], [$r41eRefresh($r41eFeed($r41eDay(1), $r41eUp(1.45 * 1.45))), $r41eRate('USD')],
        'R41 ECB low: the same day\'s feed again 45 % up (110 % from the day before) is refused (was: stored, then again)');
    assert_equals('refused', $r41eRefresh($r41eFeed($r41eDay(0), $r41eUp(1.45 * 1.45))),
        'R41 ECB low: the next day\'s feed 110 % up from two days before is refused too (the week before bounds it)');
    assert_equals('stored', $r41eRefresh($r41eFeed($r41eDay(0), $r41eUp(1.3))), 'R41 ECB low: a feed within 50 % of the whole week is stored (control)');

    // 3. --force: a checked large move, not nonsense.
    assert_equals(['refused', round(1.3 / 1.16, 6)], [$r41eRefresh($r41eFeed($r41eDay(0), ['USD' => 1e-9] + $r41eAll), true), $r41eRate('USD')],
        'R41 ECB low: --force refuses USD at 1e9 EUR (was: stored)');
    assert_equals('refused', $r41eRefresh($r41eFeed($r41eDay(0), ['CZK' => 1e9] + $r41eAll), true),
        'R41 ECB low: --force refuses CZK at 1e-9 EUR');
    assert_equals('refused', $r41eRefresh($r41eFeed($r41eDay(0), ['USD' => 1.16 / 1.3 / 12] + $r41eAll), true),
        'R41 ECB low: --force refuses a rate 12 times away from the stored one (a lost decimal point)');
    assert_equals(['stored', round(4 / 48, 6)], [$r41eRefresh($r41eFeed($r41eDay(0), ['TRY' => 12] + $r41eAll), true), $r41eRate('TRY')],
        'R41 ECB low: --force stores a checked move of 4 times (control)');
} catch (Throwable $r41eE) {
    assert_true(false, 'R41 ECB low (' . get_class($r41eE) . ': ' . $r41eE->getMessage() . ' @ ' . basename($r41eE->getFile()) . ':' . $r41eE->getLine() . ')');
} finally {
    EurRates::setFixtureTransport(null);
    $pdo->exec('DELETE FROM eur_exchange_rates');
    if (Db::tableExists('eur_exchange_rate_history', $pdo)) $pdo->exec('DELETE FROM eur_exchange_rate_history');
    EurRates::reset();
}
