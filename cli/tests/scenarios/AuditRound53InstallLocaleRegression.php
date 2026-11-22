<?php

// Review round 53 (medium 3 and UI lows): a shop installed in CZK kept the default store's shipping and payment prices in
// euro cents (GLS 2.99 Kč, free shipping from 50 Kč, cash on delivery 3 Kč) and the setup doctor said nothing.
echo "\n--- Audit round 53: install in another currency prices shipping and payment in it ---\n";

$r53iRoot = test_fixture_path('r53-install-locale');
mkdir($r53iRoot);
file_put_contents($r53iRoot . '/child.php', <<<'PHP'
<?php
[, $root, $currency] = $argv;
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . __DIR__ . "/shop-{$currency}.sqlite");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
(new Migrator($db, $root . '/migrations', __DIR__ . "/migrate-{$currency}.lock"))->apply();
Bootstrap::initStoreContext('/', '', 'default');
$stores = ['default_store' => 'default', 'stores' => ['default' => ['currency' => $currency, 'provider_scope' => 'default']]];
$figures = static function () use ($db): array {
    $one = static fn(string $sql): mixed => $db->query($sql)->fetchColumn();
    return [
        'gls' => (int)$one("SELECT MIN(price) FROM shipping_rates WHERE store_id = 'default' AND method_code = 'gls'"),
        'free_above' => (int)$one("SELECT MAX(free_above) FROM shipping_methods WHERE store_id = 'default'"),
        'cod_fee' => (int)$one("SELECT fixed_fee FROM payment_methods WHERE store_id = 'default' AND code = 'cod'"),
        'cod_countries' => (int)$one("SELECT COUNT(*) FROM payment_method_countries WHERE store_id = 'default' AND method_code = 'cod'"),
    ];
};
$out = ['doctor_before' => method_exists('Setup', 'providerPricesCheck') ? Setup::providerPricesCheck($db, 'default', $stores['stores']['default'])['status'] ?? null : null];
if (method_exists('WebInstaller', 'priceInStoreCurrency')) WebInstaller::priceInStoreCurrency($db, $stores);
$out += $figures();
$out['doctor_after'] = method_exists('Setup', 'providerPricesCheck') ? Setup::providerPricesCheck($db, 'default', $stores['stores']['default'])['status'] ?? null : null;
$out['cz_kept'] = (int)$db->query("SELECT COUNT(*) FROM shipping_rates WHERE store_id = 'cz'")->fetchColumn();
echo 'r53i ' . json_encode($out) . "\n";
PHP);
$r53iRun = static function (string $currency) use ($r53iRoot): array {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($r53iRoot . '/child.php') . ' ' . escapeshellarg(dirname(TEST_CLI_DIR)) . ' ' . escapeshellarg($currency) . ' 2>&1', $out);
    foreach ($out as $line) {
        if (str_starts_with($line, 'r53i ')) return (array)json_decode(substr($line, 5), true);
    }
    return ['error' => substr(implode(' | ', $out), 0, 400)];
};

$r53iCzk = $r53iRun('czk');
assert_equals(['gls' => 9900, 'free_above' => 200000, 'cod_fee' => 3900],
    array_intersect_key($r53iCzk, ['gls' => 1, 'free_above' => 1, 'cod_fee' => 1]),
    'R53 install: a CZK shop gets GLS from 99 Kč, free shipping from 2 000 Kč and cash on delivery for 39 Kč (' . json_encode($r53iCzk) . ')');
assert_true(($r53iCzk['cod_countries'] ?? 0) > 0 && ($r53iCzk['cz_kept'] ?? 0) > 0, 'R53 install: the payment countries come along and the CZ price list stays');
assert_equals(['warn', 'pass'], [$r53iCzk['doctor_before'] ?? null, $r53iCzk['doctor_after'] ?? null],
    'R53 doctor: euro-cent shipping prices in a CZK store warn; the CZK price list passes');
$r53iEur = $r53iRun('eur');
assert_equals([399, 'pass', 'pass'], [$r53iEur['gls'] ?? null, $r53iEur['doctor_before'] ?? null, $r53iEur['doctor_after'] ?? null],
    'R53 install: a EUR shop keeps its EUR prices and the doctor passes (' . json_encode($r53iEur) . ')');
assert_true(str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/WebInstaller.php'), 'self::priceInStoreCurrency(Db::get(), $stores);'),
    'R53 install: the installer prices the default store in its currency before it switches the payment methods');

// UI lows.
$r53iRepo = dirname(TEST_CLI_DIR);
$r53iExample = json_decode((string)file_get_contents($r53iRepo . '/stores.example.json'), true);
$r53iInstalled = WebInstaller::withIdentity($r53iExample, ['shop_name' => 'Obchod', 'timezone' => 'Europe/Prague', 'shop_locale' => 'cs', 'currency' => 'czk',
    'company' => 'Firma s.r.o.', 'street' => 'Hlavní 1', 'city' => 'Praha', 'zip' => '11000', 'country' => 'CZ', 'vat_id' => '', 'iban' => '', 'email' => 'a@example.cz']);
assert_equals('cs', $r53iInstalled['default_locale'] ?? null, 'R53 install: a Czech install makes Czech the system language the admin opens in (was "en")');
$r53iPlain = $r53iRoot . '/plain';
mkdir($r53iPlain);
copy($r53iRepo . '/stores.example.json', $r53iPlain . '/stores.example.json');
$r53iDefaults = WebInstaller::defaults($r53iPlain, ['HTTP_HOST' => 'shop.example.cz', 'SCRIPT_NAME' => '/install.php'], 'cs');
assert_equals(['CZ', 'Europe/Prague', 'cs', 'czk'], [$r53iDefaults['country'], $r53iDefaults['timezone'], $r53iDefaults['shop_locale'], $r53iDefaults['currency']],
    'R53 install: the Czech installer prefills a Czech shop, not the Slovak example store');
$r53iEnglish = WebInstaller::defaults($r53iPlain, ['HTTP_HOST' => 'shop.example.com', 'SCRIPT_NAME' => '/install.php'], 'en');
// R54 (review round 19): English no longer keeps the Slovak example store (SK, EUR, Slovak); no country is prefilled.
assert_equals(['', 'UTC', 'en', 'eur', ''], [$r53iEnglish['country'], $r53iEnglish['timezone'], $r53iEnglish['shop_locale'], $r53iEnglish['currency'], $r53iEnglish['shop_name']],
    'R54 install: in English the installer prefills an English shop without a country or the Slovak example name');
assert_equals('', $r53iDefaults['shop_name'], 'R54 install: the Czech installer does not prefill the Slovak example name "Slovenský obchod"');
$r53iSlovak = WebInstaller::defaults($r53iPlain, ['HTTP_HOST' => 'shop.example.sk', 'SCRIPT_NAME' => '/install.php'], 'sk');
assert_equals((string)$r53iExample['stores']['default']['name'], $r53iSlovak['shop_name'], 'R54 install: the Slovak installer keeps the example store name');

$r53iMissing = [];
foreach (array_keys(WebInstaller::LOCALES) as $r53iLang) {
    foreach (VatService::productClasses() as $r53iClass) {
        $r53iKey = 'admin.product.vat_class.' . $r53iClass;
        if (I18n::tIn($r53iKey, $r53iLang) === $r53iKey) $r53iMissing[] = "{$r53iLang}:{$r53iClass}";
    }
}
assert_equals([], $r53iMissing, 'R53 product: every VAT class (ebooks too) has a label in every admin language');

$r53iOps = (string)file_get_contents($r53iRepo . '/src/Entrypoints/Admin/Operations.php');
$r53iMark = 'lang="<?= esc(MethodLabels::baseLanguage()) ?>" name=';
assert_true(substr_count($r53iOps, 'action=method_save') === 4 && substr_count($r53iOps, $r53iMark . '"name"') === 4
    && substr_count($r53iOps, $r53iMark . '"description"') === 4 && method_exists('MethodLabels', 'baseLanguage'),
    'R53 methods: the own name and description of every shipping and payment method row carry their language');
assert_equals((string)(Bootstrap::stores()['stores'][StoreContext::current()->providerId()]['default_locale'] ?? StoreContext::current()->defaultLocale()),
    method_exists('MethodLabels', 'baseLanguage') ? MethodLabels::baseLanguage() : null, 'R53 methods: the own name is in the default language of the store owning the methods');

$r53iLegal = static fn(string $lang, string $page): string => (string)file_get_contents("{$r53iRepo}/templates/legal/{$lang}/{$page}.html");
assert_equals(substr_count($r53iLegal('sk', 'privacy'), '<h2>'), substr_count($r53iLegal('cs', 'privacy'), '<h2>'),
    'R53 legal: the Czech privacy policy has every section of the Slovak one (was a five-line stub)');
assert_true(str_contains($r53iLegal('cs', 'privacy'), 'Úřad pro ochranu osobních údajů') && str_contains($r53iLegal('cs', 'privacy'), 'zpracovatel'),
    'R53 legal: the Czech privacy policy names the supervisory authority and the processors');
$r53iDeclined = [];
foreach (['cs', 'sk', 'pl'] as $r53iLang) {
    // The ADR body's name ("Česká obchodní inspekce", or the merchant's own) is in the nominative: it follows a colon.
    if (!str_contains($r53iLegal($r53iLang, 'imprint'), ': <a href="{{adr_url}}" rel="noopener">{{adr_name}}</a>')) $r53iDeclined[] = $r53iLang;
}
assert_equals([], $r53iDeclined, 'R53 legal: the imprint does not put the ADR body\'s name after a preposition needing another case');
