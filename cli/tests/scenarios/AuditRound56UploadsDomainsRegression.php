<?php

// Review round 21 (R56), security lows:
// - restore: uploads/ was screened by a list of active types; .es, .ecma, .dat or a name without an extension stayed in
//   the web root, where Apache served them sniffed (nosniff only for its FilesMatch list) and CSP 'self' ran them as a
//   script. Now an allowlist of images, documents and media, and nosniff for every upload.
// - the domain test (/healthz) took an IP literal (127.0.0.1, 169.254.169.254, 0x7f.1), probed every domain on every
//   save (10 × 4 s); without app_url no host was kept for the default store; domains[] removed all domains; the seller
//   form saved a store without URL prefix and domain; /admin was a valid URL prefix.
echo "\n--- R56 uploads allowlist and store domains lows ---\n";
$r56dRoot = dirname(TEST_CLI_DIR);
$r56dDir = test_fixture_path('r56-domains');
@mkdir($r56dDir, 0700, true);
$r56dCfg = new ReflectionProperty(Security::class, 'cfg');
$r56dCfgBefore = $r56dCfg->getValue();
$r56dHost = $_SERVER['HTTP_HOST'] ?? null;
try {
    $r56dActive = new ReflectionMethod(BackupService::class, 'activeInBrowser');
    $r56dVerdict = [];
    foreach (['products/photo.jpg', 'products/photo.JPEG', 'a/b.webp', 'docs/manual.pdf', 'video/intro.mp4', 'x.es', 'x.ecma', 'blob.dat', 'noext',
        'x.js.jpg', 'page.html', 'logo.svg', 'archive.zip', '.hidden.png'] as $r56dName) {
        $r56dVerdict[$r56dName] = $r56dActive->invoke(null, $r56dName);
    }
    assert_equals(['products/photo.jpg' => false, 'products/photo.JPEG' => false, 'a/b.webp' => false, 'docs/manual.pdf' => false, 'video/intro.mp4' => false,
        'x.es' => true, 'x.ecma' => true, 'blob.dat' => true, 'noext' => true, 'x.js.jpg' => true, 'page.html' => true, 'logo.svg' => true,
        'archive.zip' => true, '.hidden.png' => true], $r56dVerdict,
        'R56 restore: uploads keep only images, documents and media in the web root (was: .es, .ecma, .dat and names without an extension too)');
    $r56dHtaccess = (string)file_get_contents($r56dRoot . '/.htaccess');
    assert_true(preg_match('#<If "%\{REQUEST_FILENAME\} =~ m\#/uploads/\#">(?:(?!</If>).)*Header always set X-Content-Type-Options "nosniff"#s', $r56dHtaccess) === 1,
        'R56 restore: Apache sends nosniff for every file of uploads/, not only the extensions it lists');

    $r56dStores = json_decode((string)file_get_contents($r56dRoot . '/stores.example.json'), true);
    $r56dStores['stores']['us']['domains'] = ['us.shop.example'];
    $r56dStores['stores']['us']['legal']['iban'] = '';
    file_put_contents($r56dDir . '/stores.json', json_encode($r56dStores, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    StoreSettings::setPathForTesting($r56dDir . '/stores.json');
    $r56dCfg->setValue(null, ['app_url' => 'https://shop.example'] + (array)$r56dCfgBefore);
    $r56dRead = static fn(string $store, string $key): mixed => json_decode((string)file_get_contents($r56dDir . '/stores.json'), true)['stores'][$store][$key] ?? null;
    // R58 (review round 23, low N1): a name without a DNS address is no longer asked; these names stand for public hosts.
    StoreSettings::setResolverForTesting(static fn(string $host): array => ['93.184.216.34']);
    $r56dCalls = [];
    JsonHttpClient::setFixtureTransport(static function (string $method, string $url) use (&$r56dCalls): array {
        $r56dCalls[] = $url;
        return ['status' => 200, 'body' => json_encode(['status' => 'ok', 'service' => 'bareboneseshop'])];
    });

    foreach (['127.0.0.1', '169.254.169.254', '0x7f.1', '10.0.0.1', 'shop.0x7f'] as $r56dIp) {
        $r56dCalls = [];
        $r56dSaved = StoreSettings::saveDomains('us', $r56dIp);
        assert_true(!$r56dSaved['ok'] && $r56dCalls === [] && $r56dRead('us', 'domains') === ['us.shop.example'],
            "R56 domains: the IP literal {$r56dIp} is refused and never probed (was: saved, /healthz asked of it)");
    }
    $r56dCalls = [];
    StoreSettings::saveDomains('us', "us.shop.example\nnew.shop.example");
    $r56dAdded = $r56dCalls;
    $r56dCalls = [];
    $r56dAgain = StoreSettings::saveDomains('us', "us.shop.example\nnew.shop.example");
    assert_equals([['https://new.shop.example/healthz'], [], true], [$r56dAdded, $r56dCalls, $r56dAgain['ok']],
        'R56 domains: only the domains a save adds are probed, an unchanged save probes none (was: every domain on every save)');
    $r56dSeconds = (new ReflectionClassConstant(StoreSettings::class, 'PROBE_SECONDS'))->getValue();
    assert_true(is_int($r56dSeconds) && $r56dSeconds <= 10, "R56 domains: the probes of one save share {$r56dSeconds} s (was: up to 40 s)");

    $r56dArray = StoreSettings::saveDomains('us', ['us.shop.example']);
    assert_true(!$r56dArray['ok'] && $r56dRead('us', 'domains') === ['us.shop.example', 'new.shop.example'],
        'R56 domains: domains[] posted as an array is refused, the domains stay (was: all removed)');
    $r56dAdmin = StoreSettings::saveDomains('us', 'us.shop.example', '/admin');
    assert_true(!$r56dAdmin['ok'] && $r56dRead('us', 'url_prefix') !== '/admin' && StoreContext::isReservedPrefix('/admin'),
        'R56 domains: /admin is not a store URL prefix');

    // Without app_url, the host the admin came through serves the default store: another store cannot take it.
    $r56dCfg->setValue(null, ['app_url' => ''] + (array)$r56dCfgBefore);
    $_SERVER['HTTP_HOST'] = 'Main.Example:8080';
    $r56dMain = StoreSettings::saveDomains('us', 'main.example');
    assert_true(!$r56dMain['ok'] && str_contains(implode(' ', $r56dMain['errors']), 'main.example') && $r56dRead('us', 'domains') === ['us.shop.example', 'new.shop.example'],
        'R56 domains: without app_url the admin request\'s host is kept for the default store (was: nothing reserved): ' . json_encode($r56dMain['errors']));
    $_SERVER['HTTP_HOST'] = 'us.shop.example';
    assert_true(StoreSettings::saveDomains('us', "us.shop.example")['ok'], 'R56 domains: the store\'s own domain the admin is reached through stays its own');

    // The seller form: no URL prefix and no domain is refused there too.
    $r56dStores = json_decode((string)file_get_contents($r56dDir . '/stores.json'), true);
    $r56dStores['stores']['us']['url_prefix'] = '';
    file_put_contents($r56dDir . '/stores.json', json_encode($r56dStores, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $r56dInput = ['store_name' => 'US Store', 'payment_terms_days' => '14', 'vat_registered' => '0'];
    foreach (StoreSettings::LEGAL_TEXT as $r56dField) $r56dInput[$r56dField] = (string)($r56dStores['stores']['us']['legal'][$r56dField] ?? '');
    $r56dSeller = StoreSettings::save('us', $r56dInput + ['domains' => '']);
    assert_true(!$r56dSeller['ok'] && in_array(I18n::t('admin.stores.err.prefix_needs_domain'), $r56dSeller['errors'], true) && $r56dRead('us', 'domains') === ['us.shop.example'],
        'R56 domains: the seller form does not save a store without URL prefix and domain (was: saved, the store unreachable): ' . json_encode($r56dSeller['errors']));
} catch (Throwable $e) {
    assert_true(false, 'R56 uploads/domains: ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    JsonHttpClient::setFixtureTransport(null);
    StoreSettings::setResolverForTesting(null);
    $r56dCfg->setValue(null, $r56dCfgBefore);
    StoreSettings::setPathForTesting(null);
    if ($r56dHost === null) unset($_SERVER['HTTP_HOST']); else $_SERVER['HTTP_HOST'] = $r56dHost;
}
