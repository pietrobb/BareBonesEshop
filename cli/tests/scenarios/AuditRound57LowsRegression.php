<?php

// Review round 22 (R57), lows:
//  - N1: a name of invisible characters alone still passed in the checkout, the customer profile, B2B companies and the
//    CSV import (each trimmed on its own), a name of combining marks alone (U+0301) passed everywhere, and typedText()
//    kept a trailing NUL.
//  - store domains resolving to a private address (localtest.me, 127.0.0.1.nip.io) were probed; the domains left at the
//    probe deadline were never tested.
//  - doctor: "0 0 31 2 *" read "neither a cron schedule nor a crontab line", an invalid worker_cron passed with web_worker
//    on, a quoted PHP binary or worker path was refused.
//  - "1 produktov": counts after a colon in the import, feed sync and bulk order status messages.
echo "\n--- Audit round 57: invisible names, private domains, doctor cron, plural forms ---\n";

$pdo ??= test_commerce_fixture();
$r57lCall = static function (string $class, string $method, array $args) {
    $m = new ReflectionMethod($class, $method);
    $m->setAccessible(true);
    return $m->invokeArgs(null, $args);
};
$r57lRepo = dirname(TEST_CLI_DIR);

// --- N1: one check for names ----------------------------------------------------------------------------------------------
$r57lBlank = ["\u{0301}", "\u{0301}\u{0308}\u{20DD}", " \u{200B}\u{0301} ", "\u{00A0}\u{0000}"];
assert_equals(['', '', '', ''], array_map([Security::class, 'typedText'], $r57lBlank),
    'R57 typed text: combining marks alone and a NUL with invisible characters are empty (was: kept)');
assert_equals(['Ján', "Ján\u{0301}"], [Security::typedText("Ján\u{0000}"), Security::typedText("Ján\u{0301}")],
    'R57 typed text: a trailing NUL is trimmed (was: stored), a combining mark on a letter stays');
if (!function_exists('checkoutExecute')) require_once $r57lRepo . '/src/Http/ApiHandlers.php';
$r57lCheckout = [];
foreach ($r57lBlank as $r57lName) {
    $r57lCheckout[] = checkoutExecute(['name' => $r57lName, 'email' => 'r57-lows@example.com', 'country' => 'SK', 'payment_method' => 'bank_transfer'])['error_code'] ?? 'accepted';
}
http_response_code(200);
assert_equals(array_fill(0, 4, 'checkout.error.name_email_required'), $r57lCheckout, 'R57 checkout: a name of invisible or combining characters alone is missing');
$r57lProfile = [];
foreach ($r57lBlank as $r57lName) $r57lProfile[] = isset(Customers::profileErrors(['name' => $r57lName, 'phone' => ''])['name']);
assert_equals([true, true, true, true], $r57lProfile, 'R57 profile: a name of invisible or combining characters alone is refused (was: saved blank)');
assert_equals([], Customers::profileErrors(['name' => 'Ján Novák', 'phone' => '']), 'R57 profile: a real name passes');
$r57lB2b = [];
foreach ([...$r57lBlank, 'Firma s.r.o.'] as $r57lName) {
    try {
        $r57lCall(B2bAdmin::class, 'text', [$r57lName, true, 'Name']);
        $r57lB2b[] = 'accepted';
    } catch (DomainException $e) {
        $r57lB2b[] = 'refused';
    }
}
assert_equals(['refused', 'refused', 'refused', 'refused', 'accepted'], $r57lB2b, 'R57 B2B: a company name of invisible or combining characters alone is refused');
$r57lImport = [];
foreach ($r57lBlank as $r57lName) {
    try {
        $r57lCall(Import::class, 'importRow', [['sku' => 'R57L-IMPORT', 'name' => $r57lName, 'price' => '1.00'], $pdo]);
        $r57lImport[] = 'imported';
    } catch (Throwable $e) {
        $r57lImport[] = str_contains($e->getMessage(), 'Missing required field') ? 'missing' : 'other: ' . $e->getMessage();
    }
}
assert_equals(array_fill(0, 4, 'missing'), $r57lImport, 'R57 CSV import: a name of invisible or combining characters alone is missing (was: imported blank)');
assert_equals(0, (int)$pdo->query("SELECT COUNT(*) FROM products WHERE sku = 'R57L-IMPORT'")->fetchColumn(), 'R57 CSV import: no blank-named product was saved');

// --- Store domains: private addresses, the deadline ------------------------------------------------------------------------
$r57lDir = test_fixture_path('r57-domains');
@mkdir($r57lDir, 0700, true);
$r57lCfg = new ReflectionProperty(Security::class, 'cfg');
$r57lCfgBefore = $r57lCfg->getValue();
$r57lHost = $_SERVER['HTTP_HOST'] ?? null;
try {
    $r57lStores = json_decode((string)file_get_contents($r57lRepo . '/stores.example.json'), true);
    $r57lStores['stores']['us']['domains'] = ['us.shop.example'];
    file_put_contents($r57lDir . '/stores.json', json_encode($r57lStores, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    StoreSettings::setPathForTesting($r57lDir . '/stores.json');
    $r57lCfg->setValue(null, ['app_url' => 'https://shop.example'] + (array)$r57lCfgBefore);
    $_SERVER['HTTP_HOST'] = 'shop.example';
    $r57lRead = static fn(string $key): mixed => json_decode((string)file_get_contents($r57lDir . '/stores.json'), true)['stores']['us'][$key] ?? null;
    if (method_exists(StoreSettings::class, 'setResolverForTesting')) {
        StoreSettings::setResolverForTesting(static fn(string $host): array => [
            'localtest.me' => ['127.0.0.1'], '127.0.0.1.nip.io' => ['127.0.0.1'], 'meta.shop.example' => ['169.254.169.254'],
            'lan.shop.example' => ['10.0.0.5', '93.184.216.34'], 'v6.shop.example' => ['::1'],
        ][$host] ?? ['93.184.216.34']);
    }
    $r57lCalls = [];
    $r57lSlow = 0;
    JsonHttpClient::setFixtureTransport(static function (string $method, string $url) use (&$r57lCalls, &$r57lSlow): array {
        $r57lCalls[] = (string)parse_url($url, PHP_URL_HOST);
        if ($r57lSlow > 0) usleep($r57lSlow);
        return ['status' => 200, 'body' => json_encode(['status' => 'ok', 'service' => 'bareboneseshop'])];
    });
    $r57lPrivate = ['localtest.me', '127.0.0.1.nip.io', 'meta.shop.example', 'lan.shop.example', 'v6.shop.example'];
    $r57lSaved = StoreSettings::saveDomains('us', implode("\n", ['us.shop.example', ...$r57lPrivate]));
    assert_true($r57lSaved['ok'] && array_intersect($r57lCalls, $r57lPrivate) === [] && str_contains((string)($r57lSaved['warning'] ?? ''), 'localtest.me')
        && str_contains((string)$r57lSaved['warning'], 'lan.shop.example'),
        'R57 domains: a domain resolving to a loopback, private, link-local or IPv6 loopback address is not probed and is named (was: /healthz asked of it): '
        . json_encode([$r57lCalls, $r57lSaved]));

    // The deadline: with probes taking the whole budget, the domains left are kept and probed by the next save.
    $r57lSeconds = (int)(new ReflectionClassConstant(StoreSettings::class, 'PROBE_SECONDS'))->getValue();
    StoreSettings::saveDomains('us', 'us.shop.example');
    $r57lCalls = [];
    $r57lSlow = (int)(($r57lSeconds + 0.2) * 1000000);
    $r57lNew = ['a.shop.example', 'b.shop.example', 'c.shop.example'];
    $r57lFirst = StoreSettings::saveDomains('us', implode("\n", ['us.shop.example', ...$r57lNew]));
    $r57lFirstCalls = $r57lCalls;
    $r57lPending = $r57lRead('domains_untested');
    $r57lCalls = [];
    $r57lSlow = 0;
    $r57lSecond = StoreSettings::saveDomains('us', implode("\n", ['us.shop.example', ...$r57lNew]));
    assert_true($r57lFirstCalls === ['a.shop.example'] && $r57lPending === ['b.shop.example', 'c.shop.example'] && str_contains((string)($r57lFirst['warning'] ?? ''), 'b.shop.example')
        && $r57lCalls === ['b.shop.example', 'c.shop.example'] && $r57lRead('domains_untested') === null && ($r57lSecond['warning'] ?? '') === '',
        'R57 domains: the domains left at the deadline are named, kept and probed by the next save (was: never tested): '
        . json_encode([$r57lFirstCalls, $r57lPending, $r57lFirst['warning'] ?? null, $r57lCalls, $r57lRead('domains_untested')]));
} catch (Throwable $e) {
    assert_true(false, 'R57 domains: ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    JsonHttpClient::setFixtureTransport(null);
    if (method_exists(StoreSettings::class, 'setResolverForTesting')) StoreSettings::setResolverForTesting(null);
    $r57lCfg->setValue(null, $r57lCfgBefore);
    StoreSettings::setPathForTesting(null);
    if ($r57lHost === null) unset($_SERVER['HTTP_HOST']); else $_SERVER['HTTP_HOST'] = $r57lHost;
}

// --- Doctor: worker_cron ----------------------------------------------------------------------------------------------------
$r57lOps = static function (string $cron, bool $web) use ($r57lCall): array {
    $found = [];
    $r57lCall(Setup::class, 'operationsChecks', [['operations' => ['worker_cron' => $cron, 'web_worker' => $web]], dirname(TEST_CLI_DIR),
        static function (string $status, string $id, string $message) use (&$found): void { if ($id === 'operations.cron') $found = [$status, $message]; }]);
    return $found;
};
$r57lNever = $r57lOps('0 0 31 2 *', false);
assert_true(($r57lNever[0] ?? '') === 'warn' && str_contains($r57lNever[1] ?? '', 'never runs'),
    'R57 doctor: "0 0 31 2 *" is reported as never running (was: "neither a cron schedule nor a crontab line"): ' . json_encode($r57lNever));
$r57lWeb = [$r57lOps('a b c d e', true), $r57lOps('0 0 31 2 *', true), $r57lOps('', true), $r57lOps('@daily', true)];
assert_equals(['warn', 'warn', 'pass', 'pass'], array_map(static fn(array $r): string => $r[0] ?? '', $r57lWeb),
    'R57 doctor: with web_worker on, an invalid worker_cron still warns (was: passed as "no cron"): ' . json_encode($r57lWeb));
assert_equals([true, true, true, true], array_map(static fn(string $c): bool => (bool)$r57lCall(Setup::class, 'workerCronValid', [$c]), [
    '* * * * * php "/var/www/my shop/cli/worker.php"',
    "* * * * * '/usr/bin/php8.3' /srv/shop/cli/worker.php",
    '* * * * * cd "/srv/my shop" && "/usr/bin/php" cli/worker.php --max-jobs=50',
    "* * * * * php '/srv/shop/cli/worker.php' >> /var/log/worker.log 2>&1",
]), 'R57 doctor: a quoted PHP binary or worker path is a valid crontab line (was: refused)');
assert_equals(false, (bool)$r57lCall(Setup::class, 'workerCronValid', ['* * * * * echo "worker.php"']), 'R57 doctor: echo "worker.php" still does not run the worker');

// --- Counts after a colon ---------------------------------------------------------------------------------------------------
$r57lCounts = [];
foreach (['sk', 'cs', 'pl', 'en'] as $r57lLocale) {
    $r57lLang = require $r57lRepo . "/lang/{$r57lLocale}.php";
    foreach (['admin.import.done' => '{count}', 'admin.feed.sync_done' => '{imported}', 'admin.orders.bulk.changed' => '{count}'] as $r57lKey => $r57lVar) {
        if (preg_match('/' . preg_quote($r57lVar, '/') . '\s+\p{L}/u', (string)($r57lLang[$r57lKey] ?? ''))) $r57lCounts[] = "{$r57lLocale}:{$r57lKey}";
    }
}
assert_equals([], $r57lCounts, 'R57 texts: the import, feed sync and bulk status messages put the count after a colon (was: "1 produktov")');
