<?php
$pdo ??= test_commerce_fixture();

// Review round 20 (R50, the three highs): the shipped pricing.json gave every new shop a public 10 % coupon (WELCOME10)
// and free shipping on every method over 50 EUR, which Admin → Promotions did not show and the doctor did not name;
// saving a store in the admin switched its VAT registration off (the form read a missing vat_registered as "no",
// checkout as "yes"); a product's VAT rate class could not be set (admin, CSV, API), so books and food paid 23 %.
echo "\n--- R50 highs: no shipped coupon or free shipping, VAT registration kept on save, product VAT class settable ---\n";

$r50Root = dirname(TEST_CLI_DIR);
$r50Fail = static function (string $what, Throwable $e): void {
    assert_true(false, "R50 highs {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};

// 1. pricing.json: the sample applies no cart rule; a configured one is named by the doctor (shared and per store).
try {
    $r50Sample = (array)json_decode((string)file_get_contents($r50Root . '/pricing.json'), true);
    assert_equals([], Pricing::activeCartRules($r50Sample), 'R50 high 1: the shipped pricing.json has no active coupon or automatic cart rule');
    assert_equals('pass', Setup::pricingCartRulesCheck($r50Sample, (array)(json_decode((string)file_get_contents($r50Root . '/stores.example.json'), true)['stores'] ?? []))['status'],
        'R50 high 1: the doctor finds no cart rule in the shipped pricing.json and stores.example.json');
    $r50Old = ['cart_rules' => [
        ['name' => 'WELCOME10', 'type' => 'coupon', 'code' => 'welcome10', 'discount_type' => 'percent', 'discount_value' => 10, 'min_subtotal' => 5000, 'max_uses' => 1000, 'active' => true],
        ['name' => 'Free Shipping over 50 EUR', 'type' => 'automatic', 'conditions' => ['min_subtotal' => 5000], 'action' => 'free_shipping', 'active' => true],
        ['name' => 'Switched off', 'type' => 'coupon', 'code' => 'OFF', 'discount_type' => 'fixed', 'discount_value' => 100, 'active' => false],
    ]];
    $r50Rules = Pricing::activeCartRules($r50Old);
    assert_equals([['WELCOME10', 'coupon', 'WELCOME10'], ['Free Shipping over 50 EUR', 'automatic', '']],
        array_map(static fn(array $r): array => [$r['name'], $r['type'], $r['code']], $r50Rules), 'R50 high 1: the active coupon and the automatic rule are listed, the inactive one is not');
    assert_true(str_starts_with($r50Rules[0]['effect'], '10 %') && str_contains($r50Rules[0]['effect'], '50') && str_starts_with($r50Rules[1]['effect'], 'free_shipping'),
        'R50 high 1: each rule says what it gives and from which subtotal (' . $r50Rules[0]['effect'] . ' | ' . $r50Rules[1]['effect'] . ')');
    $r50Check = Setup::pricingCartRulesCheck($r50Old, ['cz' => ['pricing' => ['cart_rules' => [['name' => 'CZ ship', 'type' => 'automatic', 'action' => 'free_shipping', 'active' => true]]]]]);
    assert_true($r50Check['status'] === 'warn' && $r50Check['name'] === 'pricing.cart_rules' && str_contains($r50Check['message'], 'coupon WELCOME10')
        && str_contains($r50Check['message'], 'Free Shipping over 50 EUR') && str_contains($r50Check['message'], 'CZ ship (free_shipping) in store cz') && $r50Check['action'] !== '',
        'R50 high 1: the doctor warns that pricing.json cart rules apply, naming the coupon, the free shipping and a store\'s own rule — ' . $r50Check['message']);
    $r50Setup = (string)file_get_contents($r50Root . '/src/Setup.php');
    assert_true(str_contains($r50Setup, 'self::pricingCartRulesCheck($pricing'), 'R50 high 1: the doctor runs the cart-rule check');
} catch (Throwable $e) { $r50Fail('pricing.json', $e); }

// 2. Store settings: a legal block without vat_registered is a VAT payer in the form as in checkout; saving keeps it.
$r50Dir = test_fixture_path('r50-highs-stores');
@mkdir($r50Dir, 0700, true);
$r50Session = $_SESSION ?? [];
try {
    $r50Example = json_decode((string)file_get_contents($r50Root . '/stores.example.json'), true);
    $r50Missing = [];
    foreach ($r50Example['stores'] as $r50Id => $r50Store) {
        if (!isset($r50Store['legal']['seller']) && !array_key_exists('vat_registered', $r50Store['legal'] ?? [])) $r50Missing[] = $r50Id;
    }
    assert_equals([], $r50Missing, 'R50 high 2: every sample store with its own seller says whether it is VAT registered');

    // A stores.json written before R50: the cz seller has no vat_registered key (checkout charges VAT).
    $r50Stores = $r50Example;
    unset($r50Stores['stores']['cz']['legal']['vat_registered']);
    $r50Stores['stores']['cz']['legal'] = array_replace($r50Stores['stores']['cz']['legal'], ['name' => 'R50 Obchod s.r.o.', 'email' => 'info@r50-obchod.cz', 'iban' => '']);
    file_put_contents($r50Dir . '/stores.json', json_encode($r50Stores, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    StoreSettings::setPathForTesting($r50Dir . '/stores.json');
    assert_true(StoreSettings::vatRegistered($r50Stores['stores']['cz']['legal']) && !StoreSettings::vatRegistered(['vat_registered' => false]),
        'R50 high 2: a legal block without vat_registered is VAT registered, an explicit false is not');

    require_once $r50Root . '/src/Entrypoints/Admin/Shared.php';
    require_once $r50Root . '/src/Entrypoints/Admin/StoreManagement.php';
    require_once $r50Root . '/src/Entrypoints/Admin/StoreSettings.php';
    $_SESSION = ['admin_role' => 'owner', 'admin_user' => 'r50', 'csrf_token' => 'r50-token'];
    ob_start();
    pageStoreSettings();
    $r50Page = (string)ob_get_clean();
    $r50Form = (string)(preg_match('#data-store-settings="cz".*?</form>#s', $r50Page, $r50M) ? $r50M[0] : '');
    assert_true(str_contains($r50Form, '<input type="hidden" name="vat_registered" value="0">')
        && str_contains($r50Form, '<input type="checkbox" name="vat_registered" value="1" checked>'),
        'R50 high 2: the store form shows the VAT payer box ticked for a seller without the key (as checkout charges VAT)');

    $r50Input = ['store_name' => 'R50 CZ', 'payment_terms_days' => '14'];
    foreach (StoreSettings::LEGAL_TEXT as $r50Field) $r50Input[$r50Field] = (string)($r50Stores['stores']['cz']['legal'][$r50Field] ?? '');
    $r50Vat = static fn(): mixed => StoreSettings::load()['stores']['cz']['legal']['vat_registered'] ?? 'absent';
    $r50Saved = StoreSettings::save('cz', $r50Input); // a form without the field (older page, API)
    assert_equals([true, true], [$r50Saved['ok'], $r50Vat()], 'R50 high 2: saving without the field keeps the seller VAT registered (' . implode(' ', $r50Saved['errors']) . ')');
    $r50Saved = StoreSettings::save('cz', ['vat_registered' => '0'] + $r50Input); // the box unticked: only the hidden 0 is posted
    assert_equals([true, false], [$r50Saved['ok'], $r50Vat()], 'R50 high 2: unticking the box switches VAT registration off');
    $r50Saved = StoreSettings::save('cz', ['vat_registered' => '1'] + $r50Input);
    assert_equals([true, true], [$r50Saved['ok'], $r50Vat()], 'R50 high 2: ticking it switches VAT registration on');
    $r50Saved = StoreSettings::save('cz', ['ic_dph' => ''] + $r50Input);
    assert_true(!$r50Saved['ok'] && in_array(I18n::t('admin.stores.err.vat_id'), $r50Saved['errors'], true) && $r50Vat() === true,
        'R50 high 2: a VAT payer saved without a VAT ID is refused, not switched to non-payer');
} catch (Throwable $e) { $r50Fail('store settings', $e); } finally {
    $_SESSION = $r50Session;
    StoreSettings::setPathForTesting(null);
    @unlink($r50Dir . '/stores.json');
    @unlink($r50Dir . '/stores.json.lock');
    @rmdir($r50Dir);
}

// 3. Product VAT class: offered in the admin form, read and written by CSV import/export and API v1.
$r50Catalog = StoreContext::current()->catalogId();
$r50Class = static fn(string $sku): ?string => ($v = $pdo->query('SELECT vat_class FROM products WHERE sku = ' . $pdo->quote($sku))->fetchColumn()) === false ? null : (string)$v;
$pdo->exec("DELETE FROM products WHERE sku IN ('R50-BOOK', 'R50-FOOD', 'R50-BAD', 'R50-API')");
try {
    $r50Classes = VatService::productClasses();
    assert_true(!array_diff(['standard', 'reduced', 'reduced2', 'zero', 'books', 'food', 'medicines'], $r50Classes) && !in_array('default', $r50Classes, true),
        'R50 high 3: the product VAT classes are the four rate classes and the category aliases — ' . implode(', ', $r50Classes));
    assert_equals(['books', '', null], [VatService::normalizeProductClass(' Books '), VatService::normalizeProductClass(''), VatService::normalizeProductClass('luxury')],
        'R50 high 3: a posted class is lower-cased; empty is "not given"; an unknown one is refused');
    $r50Form = json_decode((string)file_get_contents($r50Root . '/forms/admin-product.json'), true);
    $r50Field = array_values(array_filter($r50Form['fields'] ?? $r50Form['schema']['fields'] ?? [], static fn($f): bool => is_array($f) && ($f['name'] ?? '') === 'vat_class'))[0] ?? [];
    $r50Options = BbfOptions::fromSource((string)($r50Field['options_from'] ?? ''));
    assert_true(($r50Field['type'] ?? '') === 'select' && is_array($r50Options) && in_array('books', array_column($r50Options, 'value'), true)
        && !in_array('', array_column($r50Options, 'label'), true), 'R50 high 3: the product form has a VAT rate select with labelled classes (books, food, ...)');

    // CSV import: a new row with the class, an existing one keeps it without the column, an unknown class fails the row.
    Import::importProduct(['sku' => 'R50-BOOK', 'name' => 'R50 Book', 'price' => '12.00', 'stock' => '1', 'vat_class' => 'Books'], $pdo);
    Import::importProduct(['sku' => 'R50-FOOD', 'name' => 'R50 Food', 'price' => '3.00', 'stock' => '1'], $pdo);
    assert_equals(['books', 'standard'], [$r50Class('R50-BOOK'), $r50Class('R50-FOOD')], 'R50 high 3: CSV import stores the VAT class; a row without it is standard');
    Import::importProduct(['sku' => 'R50-BOOK', 'name' => 'R50 Book 2', 'price' => '12.00'], $pdo);
    Import::importProduct(['sku' => 'R50-FOOD', 'name' => 'R50 Food', 'price' => '3.00', 'vat_class' => 'food'], $pdo);
    assert_equals(['books', 'food'], [$r50Class('R50-BOOK'), $r50Class('R50-FOOD')], 'R50 high 3: re-import without the column keeps the class; with it, changes it');
    $r50Refused = '';
    try { Import::importProduct(['sku' => 'R50-BAD', 'name' => 'R50 Bad', 'price' => '1.00', 'vat_class' => 'luxury'], $pdo); } catch (InvalidArgumentException $e) { $r50Refused = $e->getMessage(); }
    assert_true(str_contains($r50Refused, 'vat_class') && $r50Class('R50-BAD') === null, 'R50 high 3: an unknown VAT class fails the CSV row instead of taxing at the standard rate');
    assert_true(VatService::rate('SK', $r50Class('R50-BOOK')) < VatService::rate('SK', 'standard') && VatService::rate('SK', $r50Class('R50-FOOD')) < VatService::rate('SK', 'standard'),
        'R50 high 3: the imported book and food are taxed below the standard rate');
    require_once $r50Root . '/src/Entrypoints/Admin/Catalog.php';
    $r50Out = fopen('php://memory', 'w+');
    exportProductsCsv($r50Out, 'R50-BOOK');
    rewind($r50Out);
    $r50Csv = (string)stream_get_contents($r50Out);
    fclose($r50Out);
    $r50Lines = array_map('str_getcsv', array_values(array_filter(explode("\n", $r50Csv), 'strlen')));
    $r50Header = array_map(static fn($h): string => ltrim((string)$h, "\xEF\xBB\xBF"), $r50Lines[0] ?? []);
    assert_equals('books', ($c = array_search('vat_class', $r50Header, true)) === false ? null : ($r50Lines[1][$c] ?? null), 'R50 high 3: the CSV export has the vat_class column (round trip)');

    // API v1: read, create and patch the class; an unknown one is a 400/422.
    $r50Client = ApiAuth::authenticate('Bearer ' . ApiAuth::issueClient('R50', 'default', ['products:read', 'products:write'])['token']);
    $r50Created = ApiV1::dispatch('POST', '/api/v1/products', [], ['sku' => 'R50-API', 'name' => 'R50 API', 'price' => 900, 'vat_class' => 'medicines'], ['Idempotency-Key' => 'r50-api-create-0001'], $r50Client);
    assert_equals([201, 'medicines', 'medicines'], [$r50Created['status'], $r50Created['body']['data']['vat_class'] ?? null, $r50Class('R50-API')], 'R50 high 3: API v1 creates a product with its VAT class');
    $r50ApiId = (int)$pdo->query("SELECT id FROM products WHERE sku = 'R50-API'")->fetchColumn();
    $r50Patched = ApiV1::dispatch('PATCH', "/api/v1/products/{$r50ApiId}", [], ['vat_class' => 'reduced'], ['Idempotency-Key' => 'r50-api-patch-0001'], $r50Client);
    $r50Bad = '';
    try { ApiV1::dispatch('PATCH', "/api/v1/products/{$r50ApiId}", [], ['vat_class' => 'luxury'], ['Idempotency-Key' => 'r50-api-patch-0002'], $r50Client); } catch (InvalidArgumentException $e) { $r50Bad = $e->getMessage(); }
    assert_equals([200, 'reduced', true, 'reduced'], [$r50Patched['status'], $r50Patched['body']['data']['vat_class'] ?? null, str_contains($r50Bad, 'vat_class must be one of'), $r50Class('R50-API')],
        'R50 high 3: API v1 PATCH sets the VAT class and refuses an unknown one as a bad request (' . $r50Bad . ')');
    $r50Listed = ApiV1::dispatch('GET', '/api/v1/products', ['limit' => '100'], [], [], $r50Client);
    assert_true(array_key_exists('vat_class', (array)($r50Listed['body']['data'][0] ?? [])), 'R50 high 3: the API v1 product list reports vat_class');
    $r50Spec = (string)file_get_contents($r50Root . '/docs/openapi.yaml');
    assert_true(substr_count($r50Spec, 'vat_class:') >= 3, 'R50 high 3: the OpenAPI Product, ProductPatch and ProductCreate schemas document vat_class');

    // The bookshop demo sells books at the reduced rate.
    assert_true(str_contains((string)file_get_contents($r50Root . '/demo/bookshop/build.php'), "'vat_class' => 'books'"), 'R50 high 3: the bookshop demo imports its titles with the books VAT class');
} catch (Throwable $e) { $r50Fail('product VAT class', $e); } finally {
    $pdo->exec("DELETE FROM products WHERE sku IN ('R50-BOOK', 'R50-FOOD', 'R50-BAD', 'R50-API')");
}
