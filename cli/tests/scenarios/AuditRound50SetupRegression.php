<?php

// Review round 20 (R50, setup and usability):
// - medium: the setup checklist never turned green (optional ERP, webhooks, carrier labels warned for ever) while it did
//   not check the VAT regime and rates; a semicolon CSV (SK/CZ/DE Excel) was refused; the admin store switcher showed
//   only the store name and a coupon was created in the wrong store;
// - low: method names in one language, stock alerts in English, legal pages "Kontakt: …, .", the web installer created
//   five sample stores, price-list validity read as UTC, the order-status page without the tracking number.
echo "\n--- Audit round 50: setup checklist, CSV delimiter, store labels, method translations, stock-alert language, legal lists, installer stores, validity zone, tracking ---\n";
// The suite's schema may predate this round's migrations (as in R34): the method translations column is migrated first.
if (!Db::hasColumn('shipping_methods', 'translations')) {
    (new Migrator($pdo, dirname(TEST_CLI_DIR) . '/migrations', test_fixture_path('r50s-migrate') . '.lock'))->apply();
    Db::forgetSchema($pdo);
}
$r50sFail = static fn(string $what, Throwable $e) => assert_true(false, "R50 setup {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
$r50sSavedStore = StoreContext::current();
$r50sSavedLocale = I18n::locale();
$r50sStore = $r50sSavedStore->id();

// 1. The checklist: info items (optional integrations, the VAT regime) neither count nor keep it from turning green.
try {
    $r50sSummary = SetupChecklist::summary(['checks' => [
        ['status' => 'pass', 'name' => 'runtime.php', 'message' => 'PHP ok', 'action' => ''],
        ['status' => 'info', 'name' => 'provider.erp', 'message' => 'HTTP ERP contract not configured (optional).', 'action' => 'Configure'],
        ['status' => 'info', 'name' => 'stores.vat.' . $r50sStore, 'message' => 'VAT payer in SK', 'action' => ''],
    ]]);
    assert_true($r50sSummary['ok'] ?? false, 'R50 checklist: only passes and info items = the checklist is green');
    assert_equals(['fail' => 0, 'warn' => 0], $r50sSummary['counts'], 'R50 checklist: info items are not counted as problems or warnings');
    assert_equals(['provider.erp', 'stores.vat.' . $r50sStore], array_column($r50sSummary['items'], 'name'), 'R50 checklist: the info items are still listed');
    $r50sWarn = SetupChecklist::summary(['checks' => [['status' => 'info', 'name' => 'provider.erp', 'message' => '', 'action' => ''], ['status' => 'warn', 'name' => 'config.app_url', 'message' => '', 'action' => '']]]);
    assert_equals([false, ['config.app_url', 'provider.erp']], [$r50sWarn['ok'], array_column($r50sWarn['items'], 'name')], 'R50 checklist: a warning keeps it open and comes before the info items');
    assert_equals('promotions', SetupChecklist::fixPage('pricing.cart_rules'), 'R50 checklist: hidden pricing.json cart rules link to Admin → Promotions');

    $r50sDoctor = Setup::doctor();
    $r50sStatus = array_column($r50sDoctor['checks'], 'status', 'name');
    foreach (['provider.erp', 'provider.webhook', 'provider.shipping_lifecycle'] as $r50sName) {
        assert_true(in_array($r50sStatus[$r50sName] ?? null, ['info', 'pass'], true), "R50 doctor: the optional {$r50sName} is info, not a warning (" . ($r50sStatus[$r50sName] ?? 'missing') . ')');
    }
    assert_true(array_key_exists('info', $r50sDoctor['counts']), 'R50 doctor: counts info items apart');
    assert_true(isset($r50sStatus['stores.vat.' . $r50sStore]) || !isset(Bootstrap::stores()['stores'][$r50sStore]['legal']) || isset(Bootstrap::stores()['stores'][$r50sStore]['legal']['seller']),
        'R50 doctor: every store with its own seller gets a VAT regime check');
} catch (Throwable $e) { $r50sFail('checklist', $e); }

// 2. The VAT regime: a missing vat_registered warns, a non-payer and a payer with its rates are info.
try {
    $r50sVat = static fn(array $legal): ?array => Setup::storeVatCheck('r50s', ['name' => 'R50S', 'legal' => $legal]);
    assert_equals('warn', $r50sVat(['country' => 'SK'])['status'] ?? null, 'R50 VAT: a store that never said whether its seller is a VAT payer warns');
    assert_equals('info', $r50sVat(['country' => 'SK', 'vat_registered' => false])['status'] ?? null, 'R50 VAT: a non-payer is an info line');
    $r50sPayer = $r50sVat(['country' => 'SK', 'vat_registered' => true]);
    assert_true(($r50sPayer['status'] ?? '') === 'info' && str_contains((string)$r50sPayer['message'], 'standard rate ' . rtrim(rtrim(number_format(VatService::rate('SK', 'standard'), 2, '.', ''), '0'), '.') . ' %'),
        'R50 VAT: a payer is told the regime and the standard rate checkout charges (' . ($r50sPayer['message'] ?? '') . ')');
    assert_equals(null, $r50sVat(['seller' => 'default']), 'R50 VAT: a store sharing another store\'s seller is checked there');
} catch (Throwable $e) { $r50sFail('VAT', $e); }

// 3. CSV import: a semicolon file (Excel in SK/CZ/DE) and Excel's "sep=;" line are read; a comma file still is.
$r50sCsvDir = sys_get_temp_dir() . '/r50s-' . getmypid() . '-' . bin2hex(random_bytes(3));
try {
    mkdir($r50sCsvDir, 0700, true);
    file_put_contents($r50sCsvDir . '/semicolon.csv', "\xEF\xBB\xBFsku;name;price;stock\nR50S-CSV-1;\"Stolička; dub\";12,50;3\n");
    file_put_contents($r50sCsvDir . '/sep.csv', "sep=;\nsku;name;price\nR50S-CSV-2;Stôl;99\n");
    file_put_contents($r50sCsvDir . '/comma.csv', "sku,name,price\nR50S-CSV-3,\"Lampa; mosadz\",15.00\n");
    $r50sSemi = Import::csv($r50sCsvDir . '/semicolon.csv');
    assert_true(($r50sSemi['success'] ?? false) && $r50sSemi['imported'] === 1 && ($r50sSemi['delimiter'] ?? '') === ';', 'R50 CSV: a semicolon file imports (' . json_encode($r50sSemi) . ')');
    $r50sSep = Import::csv($r50sCsvDir . '/sep.csv');
    assert_true(($r50sSep['success'] ?? false) && $r50sSep['imported'] === 1, 'R50 CSV: Excel\'s "sep=;" line names the delimiter (' . json_encode($r50sSep) . ')');
    $r50sComma = Import::csv($r50sCsvDir . '/comma.csv');
    assert_true(($r50sComma['success'] ?? false) && $r50sComma['imported'] === 1 && ($r50sComma['delimiter'] ?? '') === ',', 'R50 CSV: a comma file still imports (' . json_encode($r50sComma) . ')');
    $r50sRows = $pdo->query("SELECT sku, name, price FROM products WHERE sku LIKE 'R50S-CSV-%' ORDER BY sku")->fetchAll(PDO::FETCH_ASSOC);
    assert_equals([['R50S-CSV-1', 'Stolička; dub', 1250], ['R50S-CSV-2', 'Stôl', 9900], ['R50S-CSV-3', 'Lampa; mosadz', 1500]],
        array_map(static fn(array $r): array => [$r['sku'], $r['name'], (int)$r['price']], $r50sRows), 'R50 CSV: names and prices are read from the right columns');
} catch (Throwable $e) { $r50sFail('CSV', $e); } finally {
    foreach (['semicolon', 'sep', 'comma'] as $r50sFile) @unlink("{$r50sCsvDir}/{$r50sFile}.csv");
    @rmdir($r50sCsvDir);
}

// 4. The admin names a store with its code and where it is served (the store switcher, the coupon/price-list forms).
try {
    assert_equals('Obchod CZ (cz · shop.example.cz)', AdminStoreScope::label('cz', ['name' => 'Obchod CZ', 'url_prefix' => '/cs', 'domains' => ['shop.example.cz']]), 'R50 store label: name, code and domain');
    assert_equals('Obchod CZ (cz · /cs)', AdminStoreScope::label('cz', ['name' => 'Obchod CZ', 'url_prefix' => '/cs']), 'R50 store label: the URL prefix without a domain');
    assert_equals('default', AdminStoreScope::label('default', ['name' => 'default']), 'R50 store label: a store named by its code is not repeated');
    $r50sAdmin = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/PricingRules.php');
    assert_equals(3, substr_count($r50sAdmin, '<?= AdminStoreScope::targetField() ?>'), 'R50 store label: the promotion, price-list and price forms name and post their target store');
    assert_true(str_contains(AdminStoreScope::targetField(), 'name="store" value="' . $r50sStore . '"') && str_contains(AdminStoreScope::targetField(), 'data-target-store'), 'R50 store label: the target-store field posts and names the active store');
} catch (Throwable $e) { $r50sFail('store label', $e); }

// 5. A method the merchant named carries its own name in a further store language; the stock alert is in the store's language.
$r50sMail = new class implements MailAdapter {
    public array $sent = [];
    public function send(array $msg): array { $this->sent[] = $msg; return ['status' => 'sent', 'message' => '']; }
    public function adapterId(): string { return 'r50s-capture'; }
};
try {
    Bootstrap::initStoreContext('/cs/', '', 'cz');
    I18n::setLocale('cs');
    $r50sScope = StoreContext::current()->providerId();
    $pdo->prepare("DELETE FROM shipping_methods WHERE store_id = ? AND code = 'r50s_own'")->execute([$r50sScope]);
    $pdo->prepare("INSERT INTO shipping_methods (store_id, code, carrier, name, description, service_type, active, sort_order, translations) VALUES (?, 'r50s_own', 'r50s', 'R50S Vlastní rozvoz', 'Po Praze', 'parcel', 1, 900, ?)")
        ->execute([$r50sScope, MethodLabels::encodeTranslations(['en' => ['name' => 'R50S Own delivery', 'description' => ' '], 'xx' => ['name' => 'R50S other'], 'sk' => ['name' => 'R50S Vlastný rozvoz']], ['en', 'sk'])]);
    MethodLabels::forget();
    assert_equals(['R50S Own delivery', 'R50S Vlastný rozvoz', 'R50S Vlastní rozvoz', 'Po Praze'],
        [MethodLabels::name('shipping', 'r50s_own', 'R50S Vlastní rozvoz', 'en'), MethodLabels::name('shipping', 'r50s_own', 'R50S Vlastní rozvoz', 'sk'),
            MethodLabels::name('shipping', 'r50s_own', 'R50S Vlastní rozvoz'), MethodLabels::description('shipping', 'r50s_own', 'Po Praze', 'en')],
        'R50 method names: the merchant\'s name per further store language; the store language and an empty translation keep the entered text');
    assert_equals('{"en":{"name":"R50S Own delivery"},"sk":{"name":"R50S Vlastný rozvoz"}}', MethodLabels::encodeTranslations(['en' => ['name' => 'R50S Own delivery', 'description' => ' '], 'xx' => ['name' => 'R50S other'], 'sk' => ['name' => 'R50S Vlastný rozvoz']], ['en', 'sk']),
        'R50 method names: only the store\'s languages and non-empty texts are stored');
} catch (Throwable $e) { $r50sFail('method names', $e); }
try {
    MailService::useAdapter($r50sMail);
    $r50sDeliver = new ReflectionMethod(StockAlerts::class, 'deliver');
    $r50sDeliver->invoke(null, [['sku' => 'R50S-SKU', 'product_id' => 1, 'name' => 'R50S Židle', 'available' => 0, 'reserved' => 0, 'low' => 5, 'critical' => 2, 'level' => StockAlerts::OUT]],
        false, ['locale' => '', 'recipients' => ['r50s-stock@example.test']] + StockAlerts::config());
    $r50sSubject = (string)($r50sMail->sent[0]['subject'] ?? '');
    assert_true(str_starts_with($r50sSubject, 'Vyprodáno: R50S Židle'), 'R50 stock alert: without stock_alerts.locale the e-mail is in the store\'s language (cs), not English (' . $r50sSubject . ')');
} catch (Throwable $e) { $r50sFail('stock alert', $e); } finally {
    MailService::useAdapter(null);
    try { $pdo->prepare("DELETE FROM shipping_methods WHERE code = 'r50s_own'")->execute(); } catch (PDOException) {}
    if (method_exists(MethodLabels::class, 'forget')) MethodLabels::forget();
    StoreContext::activate($r50sSavedStore);
    I18n::setLocale($r50sSavedLocale);
}

// 6. Legal pages: an empty field of the seller's list goes with its label and comma.
try {
    $r50sLegal = ConsumerNotice::renderLegal('terms', 'sk', ['name' => 'R50S s.r.o.', 'street' => 'Hlavná 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK',
        'ico' => '12345678', 'dic' => '2020123456', 'email' => 'r50s@example.test'], $r50sStore);
    $r50sText = trim((string)preg_replace('/\s+/u', ' ', strip_tags($r50sLegal)));
    assert_true(str_contains($r50sText, 'r50s@example.test') && !preg_match('/,\s*[.,]|:\s*[.,]/u', $r50sText),
        'R50 legal: no "Kontakt: …, ." or "IČ DPH: ." when the phone and the VAT ID are empty (' . (preg_match('/.{0,40}(?:,\s*[.,]|:\s*[.,]).{0,20}/u', $r50sText, $r50sM) ? $r50sM[0] : 'ok') . ')');
} catch (Throwable $e) { $r50sFail('legal', $e); }

// 7. The web installer sets up the one store it asks about; the example's sample stores are left out.
try {
    $r50sExample = json_decode((string)file_get_contents(dirname(TEST_CLI_DIR) . '/stores.example.json'), true);
    $r50sInstalled = WebInstaller::withIdentity($r50sExample, ['shop_name' => 'R50S Shop', 'timezone' => 'Europe/Bratislava', 'company' => 'R50S s.r.o.', 'street' => 'Hlavná 1',
        'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK', 'vat_id' => '', 'iban' => 'SK3112000000198742637541', 'email' => 'owner@r50s.test']);
    assert_equals([$r50sExample['default_store']], array_keys($r50sInstalled['stores']), 'R50 installer: only the store the installer set up, no hidden cz/pl/hu/de/us');
    $r50sOwn = $r50sExample;
    $r50sOwn['stores']['cz']['legal'] = $r50sInstalled['stores'][$r50sExample['default_store']]['legal'];
    unset($r50sOwn['stores']['cz']['sample']);
    assert_true(isset(WebInstaller::withIdentity($r50sOwn, ['shop_name' => 'R50S Shop', 'timezone' => 'Europe/Bratislava', 'company' => 'R50S s.r.o.', 'street' => 'Hlavná 1',
        'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK', 'vat_id' => '', 'iban' => 'SK3112000000198742637541', 'email' => 'owner@r50s.test'])['stores']['cz']),
        'R50 installer: a store with its own real seller (a starter\'s) is kept');
} catch (Throwable $e) { $r50sFail('installer', $e); }

// 8. Promotion and price-list validity is typed on the store's wall clock and stored as UTC.
try {
    $r50sZone = ShopTime::zone();
    $r50sValidity = new ReflectionMethod(PricingAdmin::class, 'validity');
    $r50sExpected = (new DateTimeImmutable('2026-07-01 00:00:00', $r50sZone))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $r50sEnd = (new DateTimeImmutable('2026-07-31 23:59:59', $r50sZone))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    assert_true($r50sZone->getOffset(new DateTimeImmutable('2026-07-01')) !== 0, 'R50 validity: the test store does not run on UTC (' . $r50sZone->getName() . ')');
    assert_equals([$r50sExpected, $r50sEnd], $r50sValidity->invoke(null, '2026-07-01T00:00', '2026-07-31'), 'R50 validity: "from 1 July 00:00" starts at midnight in the store, "to 31 July" ends at its 23:59:59');
    assert_equals(['2026-07-01T00:00:00', '2026-07-01 00:00', '—'], [PricingAdmin::formDate($r50sExpected), PricingAdmin::listDate($r50sExpected), PricingAdmin::listDate(null)],
        'R50 validity: the form and the list show the stored bound on the store\'s wall clock');
} catch (Throwable $e) { $r50sFail('validity', $e); }

// 9. The order-status page names every shipped parcel's carrier and tracking number (Admin → Shipments sets them).
try {
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, store_id, created_at)
        VALUES (?, 'R50S', 'r50s@example.test', '', '{}', 1000, 0, 0, 0, 1000, 'eur', 'shipped', 'paid', 'cod', '', ?, " . Db::now() . ")")->execute(['R50S-' . bin2hex(random_bytes(4)), $r50sStore]);
    $r50sOrder = Orders::byId((int)$pdo->lastInsertId());
    $r50sShip = $pdo->prepare("INSERT INTO shipments (store_id, order_id, status, carrier, tracking_number, tracking_url, idempotency_key, shipped_at) VALUES (?, ?, ?, ?, ?, ?, ?, " . Db::now() . ")");
    $r50sShip->execute([$r50sStore, (int)$r50sOrder['id'], 'shipped', 'gls', 'R50S-TRK-1', 'https://track.example/R50S-TRK-1', 'r50s-' . $r50sOrder['id'] . '-1']);
    $r50sShip->execute([$r50sStore, (int)$r50sOrder['id'], 'shipped', 'DPD', 'R50S-TRK-2', 'javascript:alert(1)', 'r50s-' . $r50sOrder['id'] . '-2']);
    $r50sShip->execute([$r50sStore, (int)$r50sOrder['id'], 'planned', 'gls', 'R50S-TRK-3', '', 'r50s-' . $r50sOrder['id'] . '-3']);
    $r50sTracking = Fulfillment::customerTracking($r50sOrder);
    assert_equals([['R50S-TRK-1', 'https://track.example/R50S-TRK-1'], ['DPD R50S-TRK-2', '']],
        [[substr($r50sTracking[0]['label'] ?? '', -10), $r50sTracking[0]['url'] ?? null], [$r50sTracking[1]['label'] ?? null, $r50sTracking[1]['url'] ?? null]],
        'R50 tracking: each shipped parcel with its number, an https tracking link only (' . json_encode($r50sTracking) . ')');
    assert_equals(2, count($r50sTracking), 'R50 tracking: a parcel not shipped yet is not listed');
    $r50sTheme = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/themes/default/blocks/storefront/order-status.html');
    assert_true(str_contains($r50sTheme, '{{#each order.tracking}}'), 'R50 tracking: the order-status page lists the parcels');
} catch (Throwable $e) { $r50sFail('tracking', $e); }
