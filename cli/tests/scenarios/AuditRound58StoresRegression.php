<?php
$pdo ??= test_commerce_fixture();

// Review round 23 (2026-11-24, main @ bd743edb), stores (goal #640): a store added in Admin → Stores ({"legal": {"seller":
// "default"}}) sold as a Slovak seller everywhere but on its invoices: a Czech shop's /sk store charged its Czech buyers 23 %
// Slovak VAT (V1), the shipping.json home zone was Slovakia and bank transfer was not offered (no IBAN of its own). The admin
// offered only the storefronts' languages (a German install had no English admin) and Admin → Stores could not add a
// storefront language, as the installer said it could (S3). The store domain test asked a name without an address unpinned,
// and libcurl answers *.localhost itself with 127.0.0.1 (N1).
echo "\n--- R58: seller of an added store, admin and storefront languages, store domain test ---\n";

$r58Root = dirname(TEST_CLI_DIR);
$r58Dir = test_fixture_path('r58-stores');
@mkdir($r58Dir, 0700, true);
$r58StoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r58StoresBefore = $r58StoresProp->getValue();
$r58InvoiceStoresBefore = (new ReflectionProperty(InvoiceService::class, 'storesOverride'))->getValue();
$r58Session = $_SESSION ?? [];
$r58Cart = $_SESSION['cart'] ?? null;
$r58Locale = I18n::locale();
$r58Fail = static function (string $what, Throwable $e): void {
    assert_true(false, "R58 {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r58Example = json_decode((string)file_get_contents($r58Root . '/stores.example.json'), true);
// A web install (WebInstaller::withIdentity()) and a store added in Admin → Stores, as stores.json then holds them.
$r58Install = static function (string $tag, array $identity, array $added) use ($r58Dir, $r58Example, $r58StoresProp): array {
    $installed = WebInstaller::withIdentity((array)$r58Example, $identity + ['shop_name' => 'R58 ' . $tag, 'street' => 'Hlavni 1', 'zip' => '11000', 'ico' => '', 'dic' => '', 'bic' => '',
        'email' => 'info@r58-' . $tag . '.test']);
    $path = "{$r58Dir}/{$tag}.json";
    file_put_contents($path, json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    StoreSettings::setPathForTesting($path);
    $result = StoreSettings::addStore($added);
    $root = StoreSettings::load();
    $r58StoresProp->setValue(null, $root);
    InvoiceService::setStoresConfigForTesting($root);
    StoreContext::activate(StoreContext::resolve($root, (string)$added['url_prefix'] . '/', '', (string)$added['id']));
    return [$result, $root];
};
$r58Rate = static fn(array $vat): array => [(string)$vat['seller_country'], (string)$vat['scenario']['scenario'], (string)$vat['scenario']['rate_country'],
    VatService::rate((string)$vat['scenario']['rate_country'], 'standard')];

// 1. V1: a Czech install (CZK) adds a Slovak storefront /sk; it sells as the Czech seller.
try {
    $_SESSION['cart'] = [];
    VatService::reset();
    [$r58Added, $r58CzRoot] = $r58Install('cz', ['timezone' => 'Europe/Prague', 'shop_locale' => 'cs', 'currency' => 'czk', 'company' => 'R58 Obchod s.r.o.',
        'city' => 'Praha', 'country' => 'CZ', 'vat_id' => 'CZ12345678', 'iban' => 'CZ65 0800 0000 1920 0014 5399'],
        ['id' => 'sk', 'url_prefix' => '/sk', 'default_locale' => 'sk', 'currency' => 'eur', 'name' => 'R58 SK']);
    assert_equals([true, ['seller' => 'default']], [$r58Added['ok'], $r58CzRoot['stores']['sk']['legal'] ?? null],
        'R58 V1: Admin → Stores adds the store sharing the install\'s seller (' . implode(' ', $r58Added['errors']) . ')');
    $r58Store = StoreContext::current();
    assert_equals(['sk', 'CZ', 'CZ6508000000192000145399'], [$r58Store->id(), method_exists($r58Store, 'sellerCountry') ? $r58Store->sellerCountry() : 'SK', BankTransferInstructions::storeIban()],
        'R58 V1: the added store\'s seller is the Czech one, with its IBAN for bank transfer (was: SK and no IBAN, bank transfer not offered)');
    assert_equals(['CZ', 'domestic', 'CZ', 21.0], $r58Rate(CheckoutService::vatContext('CZ', '', '', false)),
        'R58 V1: a Czech buyer of the /sk store pays 21 % Czech VAT (was: seller SK, 23 % Slovak VAT)');
    assert_equals(['CZ', 'R58 Obchod s.r.o.'], [Shipping::storeCountry(), (string)(StoreContext::current()->sellerLegal()['name'] ?? '')],
        'R58 V1: the shipping.json home zone ("@store") of the added store is the seller\'s Czech Republic (was: Slovakia)');
    $r58Oss = new ReflectionMethod(OssThreshold::class, 'sellerCountry');
    $r58Invoice = InvoiceService::sellerIdentity(['store_id' => 'sk', 'currency' => 'eur']);
    assert_equals(['CZ', 'CZ', 'CZ', 'CZ', 'R58 Obchod s.r.o.'],
        [$r58Oss->invoke(null, 'sk'), (string)($r58Invoice['country'] ?? ''), (string)(JsonLd::organization('', $r58CzRoot, 'sk')['address']['addressCountry'] ?? ''),
            (string)(ConsumerNotice::sellerLegal(['store_id' => 'sk', 'currency' => 'eur'])['country'] ?? ''), (string)(Template::branding()['contact']['name'] ?? '')],
        'R58 V1: OSS, the invoice, the Organization schema, the consumer notice and the theme contact name the same Czech seller');
} catch (Throwable $e) { $r58Fail('V1 Czech install', $e); }

// 2. V1: a German install adds /en; a German buyer pays German VAT, a Slovak one is no domestic buyer.
try {
    $_SESSION['cart'] = [];
    [$r58Added, $r58DeRoot] = $r58Install('de', ['timezone' => 'Europe/Berlin', 'shop_locale' => 'de', 'currency' => 'eur', 'company' => 'R58 GmbH',
        'city' => 'Berlin', 'country' => 'DE', 'vat_id' => 'DE123456789', 'iban' => 'DE89 3704 0044 0532 0130 00'],
        ['id' => 'en', 'url_prefix' => '/en', 'default_locale' => 'en', 'currency' => 'eur', 'name' => 'R58 EN']);
    $r58Sk = CheckoutService::vatContext('SK', '', '', false);
    assert_equals([true, ['DE', 'domestic', 'DE', 19.0], 'DE', true, 'DE'],
        [$r58Added['ok'], $r58Rate(CheckoutService::vatContext('DE', '', '', false)), (string)$r58Sk['seller_country'], $r58Sk['scenario']['scenario'] !== 'domestic', Shipping::storeCountry()],
        'R58 V1: in the /en store of a German shop a German buyer pays 19 % German VAT and a Slovak one is no domestic buyer, the home zone is Germany (was: 23 % SK VAT, SK domestic)');
    assert_true(BankTransferInstructions::storeIban() === 'DE89370400440532013000' && StoreContext::current()->marketCountry() === 'DE',
        'R58 V1: the /en store offers bank transfer to the German IBAN and sells to Germany by default (was: no IBAN, Slovakia)');
} catch (Throwable $e) { $r58Fail('V1 German install', $e); }

// 3. V1: a reference loop or a missing seller resolves to the default seller (never a hang), and invoicing refuses it.
try {
    $r58Loop = ['default_store' => 'main', 'stores' => [
        'main' => ['currency' => 'eur', 'legal' => ['name' => 'R58 Main', 'country' => 'AT']],
        'a' => ['currency' => 'eur', 'legal' => ['seller' => 'b']], 'b' => ['currency' => 'eur', 'legal' => ['seller' => 'a']],
        'lost' => ['currency' => 'eur', 'legal' => ['seller' => 'gone', 'phone' => '+43 1']], 'own' => ['currency' => 'eur', 'legal' => ['seller' => 'main', 'phone' => '+43 2']],
    ]];
    assert_equals([['AT', 'AT', 'AT', 'AT'], [null, null, null, 'main'], '+43 2'],
        [array_map(static fn(string $id): string => (string)(SellerLegal::forStore($r58Loop, $id)['country'] ?? ''), ['a', 'b', 'lost', 'own']),
            array_map(static fn(string $id): ?string => InvoiceService::sellerCode($r58Loop, $id), ['a', 'b', 'lost', 'own']),
            (string)(SellerLegal::forStore($r58Loop, 'own')['phone'] ?? '')],
        'R58 V1: a seller loop or a missing seller resolves to the default seller for checkout and is refused for invoices; own fields override the seller\'s');
} catch (Throwable $e) { $r58Fail('V1 reference guard', $e); }

// 4. S3: the admin speaks every shipped language; Admin → Stores sets a storefront's languages.
try {
    $r58Shipped = array_map(static fn(string $f): string => basename($f, '.php'), glob($r58Root . '/lang/*.php') ?: []);
    sort($r58Shipped);
    $r58AdminSource = (string)file_get_contents($r58Root . '/src/Entrypoints/Admin.php');
    assert_true(method_exists(I18n::class, 'shippedLocales') && I18n::shippedLocales() === $r58Shipped && str_contains($r58AdminSource, '$adminSupportedLocales = I18n::shippedLocales();')
        && !str_contains($r58AdminSource, "\$s['languages']"),
        'R58 S3: the admin offers every shipped language (' . implode(', ', $r58Shipped) . '), not only the storefronts\' (a German install had no English admin)');

    StoreSettings::setPathForTesting("{$r58Dir}/de.json");
    $r58Input = ['store_name' => 'R58 EN', 'timezone' => 'Europe/Berlin', 'oss_enabled' => '', 'public' => '1'];
    $r58Langs = static fn(): mixed => StoreSettings::load()['stores']['en']['languages'] ?? null;
    $r58Saved = StoreSettings::save('en', $r58Input + ['languages' => ['de', 'en', 'sk']]);
    $r58After = $r58Langs();
    $r58Kept = StoreSettings::save('en', $r58Input);
    $r58Bad = StoreSettings::save('en', $r58Input + ['languages' => ['de', 'xx']]);
    assert_equals([true, ['en', 'de', 'sk'], true, ['en', 'de', 'sk'], false, ['en', 'de', 'sk']],
        [$r58Saved['ok'], $r58After, $r58Kept['ok'], $r58Langs(), $r58Bad['ok'], $r58Langs()],
        'R58 S3: Admin → Stores saves the storefront languages (the default one first and kept), a form without the field keeps them, an unknown one is refused: '
        . implode(' ', $r58Saved['errors'] + $r58Bad['errors']));
    $r58StoresProp->setValue(null, StoreSettings::load());
    $r58Hreflang = Bootstrap::hreflangAlternates('/en/');
    assert_true(isset($r58Hreflang['regional']['sk-DE']) || isset($r58Hreflang['secondary']['sk']),
        'R58 S3: the added language is announced (hreflang) through the existing code path: ' . json_encode($r58Hreflang['secondary'] + $r58Hreflang['regional']));

    if (!function_exists('pageStoreSettings')) {
        if (!function_exists('adminFieldErrors')) require_once $r58Root . '/src/Entrypoints/Admin/Shared.php';
        require_once $r58Root . '/src/Entrypoints/Admin/StoreManagement.php';
        require_once $r58Root . '/src/Entrypoints/Admin/StoreSettings.php';
    }
    $_SESSION = ['admin_role' => 'owner', 'admin_user' => 'r58', 'csrf_token' => 'r58-token'];
    I18n::setLocale('en');
    ob_start();
    try { pageStoreSettings(); } finally { $r58Page = (string)ob_get_clean(); }
    $r58Form = (string)(preg_match('#data-store-settings="en".*?</form>#s', $r58Page, $r58M) ? $r58M[0] : '');
    assert_true(str_contains($r58Form, 'data-store-languages') && str_contains($r58Form, '<input type="hidden" name="languages[]" value="en">')
        && str_contains($r58Form, '<input type="checkbox" name="languages[]" value="sk" checked>') && str_contains($r58Form, '<input type="checkbox" name="languages[]" value="pl">')
        && str_contains($r58Form, '<input type="checkbox" name="languages[]" value="en" checked disabled>'),
        'R58 S3: the store form lists the shipped languages, the store\'s ticked and its default one fixed (was: no languages field)');
    assert_true(str_contains(I18n::tIn('install.hint.shop_locale', 'en'), I18n::tIn('admin.nav.stores', 'en')),
        'R58 S3: the installer\'s hint names where storefront languages are added (Admin → Stores and seller)');
} catch (Throwable $e) { $r58Fail('S3 languages', $e); }

// 5. N1: a store domain without an address is not asked; names of the local network are refused.
$r58Cfg = new ReflectionProperty(Security::class, 'cfg');
$r58CfgBefore = $r58Cfg->getValue();
$r58Host = $_SERVER['HTTP_HOST'] ?? null;
try {
    StoreSettings::setPathForTesting("{$r58Dir}/de.json");
    $r58Cfg->setValue(null, ['app_url' => 'https://shop.r58.example'] + (array)$r58CfgBefore);
    $_SERVER['HTTP_HOST'] = 'shop.r58.example';
    StoreSettings::setResolverForTesting(static fn(string $host): array => $host === 'gone.r58.example' ? [] : ['93.184.216.34']);
    $r58Calls = [];
    JsonHttpClient::setFixtureTransport(static function (string $method, string $url) use (&$r58Calls): array {
        $r58Calls[] = (string)parse_url($url, PHP_URL_HOST);
        return ['status' => 200, 'body' => json_encode(['status' => 'ok', 'service' => 'bareboneseshop'])];
    });
    $r58Gone = StoreSettings::saveDomains('en', 'gone.r58.example');
    assert_true($r58Gone['ok'] && $r58Calls === [] && str_contains((string)($r58Gone['warning'] ?? ''), 'gone.r58.example'),
        'R58 N1: a domain without a DNS address is reported unreachable and not asked (was: asked unpinned, *.localhost reached 127.0.0.1): ' . json_encode([$r58Calls, $r58Gone]));
    $r58Local = ['shop.localhost', 'printer.local', 'db.internal', 'nas.home.arpa', 'box.localdomain'];
    $r58Refused = StoreSettings::saveDomains('en', implode("\n", $r58Local));
    assert_true(!$r58Refused['ok'] && $r58Calls === [] && array_filter($r58Local, static fn(string $d): bool => !str_contains(implode(' ', $r58Refused['errors']), $d)) === [],
        'R58 N1: names of the local network (.localhost, .local, .internal, .home.arpa) are refused as store domains: ' . json_encode($r58Refused));
    assert_equals([false, false, false, false, false, false, true],
        array_map([FeedImport::class, 'isPublicFeedIp'], ['192.0.2.10', '198.51.100.7', '203.0.113.5', '239.1.2.3', 'ff02::1', 'fec0::1', '93.184.216.34']),
        'R58 N1: documentation, multicast and site-local addresses are not public (the domain test and feed imports do not ask them)');
} catch (Throwable $e) { $r58Fail('N1 domain test', $e); } finally {
    JsonHttpClient::setFixtureTransport(null);
    StoreSettings::setResolverForTesting(null);
    $r58Cfg->setValue(null, $r58CfgBefore);
    if ($r58Host === null) unset($_SERVER['HTTP_HOST']); else $_SERVER['HTTP_HOST'] = $r58Host;
}

// Clean up: the application's stores.json and store context again.
$_SESSION = $r58Session;
if ($r58Cart === null) unset($_SESSION['cart']); else $_SESSION['cart'] = $r58Cart;
I18n::setLocale($r58Locale);
StoreSettings::setPathForTesting(null);
InvoiceService::setStoresConfigForTesting($r58InvoiceStoresBefore);
$r58StoresProp->setValue(null, $r58StoresBefore);
VatService::reset();
Bootstrap::initStoreContext('/', '', 'default');
foreach (glob($r58Dir . '/*') ?: [] as $r58File) @unlink($r58File);
@rmdir($r58Dir);
