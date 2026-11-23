<?php

// Review round 22 (2026-11-23, main @ c5d668e1), UX S5 + S6 + installer (goal #629): the shipping.json methods
// (Standardversand/Expressversand) had Slovakia as their home zone in every shop, so a German customer of a German shop paid
// the EU rate (12.70) and a Slovak one the domestic rate (3.99), and the admin did not show these methods at all. The order
// detail and the order list did not say how an order ships. The web installer kept the example's Slovak and Czech
// storefront languages in a German shop and named the sample zones in English.
echo "\n--- R57: shipping.json home zone and admin overview, shipping in the admin orders, installer languages ---\n";
if (!function_exists('pageOrder')) {
    if (!function_exists('adminFieldErrors')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Shared.php';
    require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Orders.php';
}
if (!function_exists('pageShipping')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Operations.php';

$r57shRoot = dirname(TEST_CLI_DIR);
$r57shLocale = I18n::locale();
$r57shConfig = json_decode((string)file_get_contents($r57shRoot . '/shipping.json'), true);
$r57shOrder = 0;
try {
    // 1. S5: the bundled shipping.json takes the home zone from the store's seller country.
    $r57shStores = static fn(string $country): array => ['default_store' => 'r57sh', 'stores' => ['r57sh' => ['name' => 'R57 shipping', 'default_locale' => 'de',
        'languages' => ['de'], 'currency' => 'eur', 'url_prefix' => '', 'domains' => [], 'permissions' => ['*'], 'legal' => ['country' => $country]]]];
    Shipping::setConfig((array)$r57shConfig);
    StoreContext::activate(StoreContext::resolve($r57shStores('DE'), '/', '', 'r57sh'));
    $r57shDe = [Shipping::calculate('standard', 'DE', 1000, 1000), Shipping::calculate('standard', 'SK', 1000, 1000), in_array('pickup', array_column(Shipping::availableMethods('DE'), 'key'), true), in_array('pickup', array_column(Shipping::availableMethods('SK'), 'key'), true)];
    StoreContext::activate(StoreContext::resolve($r57shStores('SK'), '/', '', 'r57sh'));
    $r57shSk = [Shipping::calculate('standard', 'SK', 1000, 1000), Shipping::calculate('standard', 'DE', 1000, 1000)];
    assert_equals([[399, 899, true, false], [399, 899]], [$r57shDe, $r57shSk],
        'R57 S5 shipping.json: a German shop ships to Germany at the domestic rate and to Slovakia at the EU rate, pickup only at home (was: Germany 8.99 EU, Slovakia 3.99 domestic)');

    // 2. S5: the admin's shipping page lists the shipping.json methods, each country under the zone whose rate it pays.
    StoreContext::activate(StoreContext::resolve($r57shStores('DE'), '/', '', 'r57sh'));
    $r57shRows = [];
    foreach (Shipping::overview('de') as $row) $r57shRows[$row['key'] . ':' . $row['zone']] = [$row['name'], $row['countries'], $row['base']];
    assert_equals([['Standardversand', ['DE'], 399], true, false, ['*']],
        [$r57shRows['standard:domestic'] ?? null, in_array('SK', $r57shRows['standard:eu'][1] ?? [], true), in_array('DE', $r57shRows['standard:eu'][1] ?? [], true), $r57shRows['standard:international'][1] ?? null],
        'R57 S5 admin: the overview names Standardversand with its home country, and Germany is not listed again under the EU rate: ' . json_encode($r57shRows, JSON_UNESCAPED_UNICODE));
    Bootstrap::initStoreContext('/', '', 'default');
    I18n::setLocale('en');
    ob_start();
    try { pageShipping(); } finally { $r57shPage = (string)ob_get_clean(); }
    assert_true(str_contains($r57shPage, esc(I18n::t('admin.shipping.file_methods'))) && str_contains($r57shPage, '<td title="standard">Standard Delivery</td>'),
        'R57 S5 admin: the shipping page shows the shipping.json methods (was: not shown, their prices and countries invisible)');

    // 3. S6: the order detail and the order list say how the order ships.
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method,
            payment_id, shipping_method, shipping_rate_snapshot, store_id, locale, created_at)
        VALUES ('R57SH-1', 'R57SH', 'r57sh@example.test', '{\"country\":\"SK\"}', 1000, 1000, 'eur', 'pending', 'pending', 'bank_transfer', '', 'r57sh_gls', ?, 'default', 'sk', " . Db::now() . ")")
        ->execute([json_encode(['label' => 'R57 GLS courier'])]);
    $r57shOrder = (int)$pdo->lastInsertId();
    $_GET = ['id' => (string)$r57shOrder];
    ob_start();
    try { pageOrder(); } finally { $r57shDetail = (string)ob_get_clean(); $_GET = []; }
    ob_start();
    try { pageOrders(); } finally { $r57shList = (string)ob_get_clean(); }
    assert_equals([true, true, true],
        [preg_match('~<p [^>]*data-order-shipping>' . preg_quote(esc(I18n::t('admin.delivery_note.shipping_method')), '~') . ': R57 GLS courier</p>~', $r57shDetail) === 1,
            str_contains($r57shList, '<th>' . esc(I18n::t('admin.delivery_note.shipping_method')) . '</th>'),
            str_contains($r57shList, '<td class="muted" title="r57sh_gls" data-order-list-shipping>R57 GLS courier</td>')],
        'R57 S6 admin: the order detail names the shipping method and the order list has a shipping column (was: neither)');

    // 4. Installer: the storefront offers only the chosen language.
    $r57shExample = json_decode((string)file_get_contents($r57shRoot . '/stores.example.json'), true);
    $r57shInstalled = WebInstaller::withIdentity((array)$r57shExample, ['shop_name' => 'R57 Laden', 'timezone' => 'Europe/Berlin', 'shop_locale' => 'de', 'currency' => 'eur',
        'company' => 'R57 GmbH', 'street' => 'Hauptstr. 1', 'city' => 'Berlin', 'zip' => '10115', 'country' => 'DE', 'vat_id' => '', 'iban' => '', 'email' => 'r57@example.test']);
    $r57shDefault = $r57shInstalled['stores'][(string)($r57shInstalled['default_store'] ?? 'default')] ?? [];
    assert_equals(['de'], $r57shDefault['languages'] ?? null,
        'R57 installer: a German shop offers German only (was: ["de", "sk", "cs", "en"], Slovak and Czech pages and hreflang links)');
} catch (Throwable $e) {
    assert_true(false, 'R57 shipping UX (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    I18n::setLocale($r57shLocale);
    $_GET = [];
    Shipping::setConfig(is_array($r57shConfig) ? $r57shConfig : []);
    Bootstrap::initStoreContext('/', '', 'default');
    if ($r57shOrder > 0) $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$r57shOrder]);
}
