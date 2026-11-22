<?php

// Review round 20 (R55), store domains:
// medium M1 (owner only). Another store could be given the host of app_url: one save left the main storefront 404, or
//    showed that store in its place.
// usability. The domains were saved with the seller form, so a sample IBAN kept a store from moving to its domain; a
//    domain that did not reach the shop yet was saved without a word; a Unicode domain was refused without the punycode.
// low. A host with a trailing dot fell to the default store; the store's links dropped app_url's port.
echo "\n--- R55 store domains: app_url host kept for the default store, own form, reachability, trailing dot, port ---\n";
$r55dRoot = dirname(TEST_CLI_DIR);
$r55dDir = test_fixture_path('r55-domains');
@mkdir($r55dDir, 0700, true);
$r55dCfg = new ReflectionProperty(Security::class, 'cfg');
$r55dCfgBefore = $r55dCfg->getValue();
try {
    $r55dStores = json_decode((string)file_get_contents($r55dRoot . '/stores.example.json'), true);
    $r55dStores['stores']['cz']['domains'] = ['shop.example.cz'];
    $r55dStores['stores']['us']['legal']['iban'] = 'XX00 0000 0000';
    file_put_contents($r55dDir . '/stores.json', json_encode($r55dStores, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    StoreSettings::setPathForTesting($r55dDir . '/stores.json');
    $r55dCfg->setValue(null, ['app_url' => 'https://shop.example:8443/eshop'] + (array)$r55dCfgBefore);
    $r55dDefault = (string)$r55dStores['default_store'];
    $r55dDomains = static fn(string $store): mixed => json_decode((string)file_get_contents($r55dDir . '/stores.json'), true)['stores'][$store]['domains'] ?? null;
    $r55dCalls = [];
    JsonHttpClient::setFixtureTransport(static function (string $method, string $url) use (&$r55dCalls): array {
        $r55dCalls[] = $url;
        return $url === 'https://us.shop.example:8443/eshop/healthz'
            ? ['status' => 200, 'body' => json_encode(['status' => 'ok', 'service' => 'bareboneseshop'])]
            : ['status' => 404, 'body' => '{}'];
    });

    // M1: the host of app_url, as typed or with a trailing dot, through either form.
    $r55dMain = StoreSettings::saveDomains('us', "Shop.Example.");
    $r55dInput = ['store_name' => 'US Store', 'payment_terms_days' => '14', 'vat_registered' => '1', 'iban' => ''];
    foreach (StoreSettings::LEGAL_TEXT as $r55dField) $r55dInput[$r55dField] ??= (string)($r55dStores['stores']['us']['legal'][$r55dField] ?? '');
    $r55dMainForm = StoreSettings::save('us', $r55dInput + ['domains' => 'shop.example']);
    assert_true(!$r55dMain['ok'] && str_contains(implode(' ', $r55dMain['errors']), 'app_url') && !$r55dMainForm['ok']
        && str_contains(implode(' ', $r55dMainForm['errors']), 'app_url') && $r55dDomains('us') === [],
        'R55 domains: another store cannot take the host of app_url, in the domains form or the seller form (was: saved, the main storefront 404): ' . json_encode([$r55dMain['errors'], $r55dMainForm['errors']]));
    assert_true(StoreSettings::saveDomains($r55dDefault, 'shop.example')['ok'] && $r55dDomains($r55dDefault) === ['shop.example'],
        'R55 domains: the default store may list the host of app_url');
    StoreSettings::saveDomains($r55dDefault, '');

    // UX: saved on its own although the seller form is not valid (a sample IBAN); the domain that does not answer as
    // this shop is named.
    $r55dCalls = [];
    $r55dSaved = StoreSettings::saveDomains('us', "us.shop.example\nother.shop.example");
    assert_true($r55dSaved['ok'] && $r55dDomains('us') === ['us.shop.example', 'other.shop.example'],
        'R55 domains: the domains form saves them while the seller form has a sample IBAN (was: refused with the seller form): ' . json_encode($r55dSaved['errors']));
    assert_true(str_contains((string)($r55dSaved['warning'] ?? ''), 'other.shop.example') && !str_contains((string)($r55dSaved['warning'] ?? ''), 'us.shop.example'),
        'R55 domains: the warning names the domain whose /healthz does not answer as this shop, not the one that does: ' . ($r55dSaved['warning'] ?? ''));
    assert_equals(['https://us.shop.example:8443/eshop/healthz', 'https://other.shop.example:8443/eshop/healthz'], $r55dCalls,
        'R55 domains: /healthz is asked under app_url\'s scheme, port and path');

    // UX: a Unicode domain is refused with its punycode.
    $r55dIdn = StoreSettings::saveDomains('us', 'obchod-žltý.example');
    $r55dIdnText = implode(' ', $r55dIdn['errors']);
    assert_true(!$r55dIdn['ok'] && str_contains($r55dIdnText, 'obchod-žltý.example') && str_contains($r55dIdnText, 'xn--'),
        'R55 domains: a Unicode domain is refused with the punycode to enter: ' . $r55dIdnText);

    // Low: a trailing dot on the Host header, and app_url's port in the store's links.
    $r55dConfig = json_decode((string)file_get_contents($r55dDir . '/stores.json'), true);
    assert_equals('us', StoreContext::resolve($r55dConfig, '/', 'us.shop.example.')->id(), 'R55 domains: a host with a trailing dot is served by its store (was: the default store)');
    assert_equals('us', StoreContext::resolve($r55dConfig, '/', 'US.Shop.Example.:8443')->id(), 'R55 domains: with a port as well');
    assert_equals('https://us.shop.example:8443/eshop', Security::storeAppUrl('https://shop.example:8443/eshop/', ['domains' => ['us.shop.example.']]),
        'R55 domains: the store\'s links keep app_url\'s port (was: dropped)');

    $r55dPage = (string)file_get_contents($r55dRoot . '/src/Entrypoints/Admin/StoreSettings.php');
    assert_true(str_contains($r55dPage, 'action=domains') && str_contains((string)file_get_contents($r55dRoot . '/src/Entrypoints/Admin/Request.php'), "'stores:domains' => handleStoreDomainsSave()")
        && str_contains($r55dPage, "if (Security::adminRole() !== 'owner' || !adminStoreSettingsAllowed(\$storeId))"),
        'R55 domains: the admin page has a domains form of its own, saved by the owner only');
    foreach (['en', 'sk', 'cs', 'de', 'pl', 'hu', 'es'] as $r55dLang) {
        foreach (['admin.stores.err.domains_app_url', 'admin.stores.err.domains_idn', 'admin.stores.domains_unreachable'] as $r55dKey) {
            $r55dText = I18n::tIn($r55dKey, $r55dLang);
            assert_true($r55dText !== $r55dKey && $r55dText !== '', "R55 domains {$r55dLang}: {$r55dKey} is translated");
        }
    }
} catch (Throwable $e) {
    assert_true(false, 'R55 domains: ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    JsonHttpClient::setFixtureTransport(null);
    $r55dCfg->setValue(null, $r55dCfgBefore);
    StoreSettings::setPathForTesting(null);
}
