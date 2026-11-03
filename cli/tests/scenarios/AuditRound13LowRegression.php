<?php

// Review round 13, low findings: variantStatesMany overwrote its digital column expression with a boolean (listings
// of more than 500 products), ProductVariants wrote variants.digital before `migrate` added it, the order_items.digital
// backfill matched snapshot keys with LIKE on the raw JSON, a digital product without its file was still sold, a bundle
// with an e-book component was sold (its download never delivered), pricing.json catalog rules fed a price map nothing
// read, a transfer due date across a daylight-saving change, and the bookshop theme's hard-coded English.
echo "\n--- Audit round 13: low findings (listings, downloads, bundles, price rules, due date, bookshop texts) ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r13lFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R13 low {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r13lCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, weight_g) VALUES
    (98301, 'default', {$r13lCategory}, 'R13L-BOOK-A', 'r13l-book-a', 'simple', 'R13 Book A', 1500, 'eur', 0, 'active', 'deny', 300),
    (98302, 'default', {$r13lCategory}, 'R13L-BOOK-B', 'r13l-book-b', 'simple', 'R13 Book B', 1500, 'eur', 0, 'active', 'deny', 300),
    (98303, 'default', {$r13lCategory}, 'R13L-GUIDE', 'r13l-guide', 'digital', 'R13 Guide', 900, 'eur', 0, 'active', 'deny', 0),
    (98304, 'default', {$r13lCategory}, 'R13L-SET', 'r13l-set', 'bundle', 'R13 Set', 2500, 'eur', 5, 'active', 'deny', 600)");
$pdo->exec("INSERT INTO variants (id, product_id, sku, name, attrs, price_absolute, stock, sort_order, digital) VALUES
    (98311, 98301, 'R13L-A-EB', 'eBook', '{\"format\":\"eBook\"}', 600, 0, 0, 1),
    (98321, 98302, 'R13L-B-PB', 'Paperback', '{\"format\":\"Paperback\"}', 1500, 3, 0, 0)");
$pdo->exec("INSERT INTO digital_assets (sku, storage_path, download_name, content_type) VALUES ('R13L-A-EB', 'default/r13l-a.epub', 'r13l-a.epub', 'application/epub+zip')");
$pdo->exec("INSERT INTO bundle_components (bundle_product_id, component_product_id, component_variant_id, quantity, sort_order) VALUES (98304, 98301, 98311, 1, 0)");

try {
    // 1. A listing of more than 500 products: the second chunk read every variant as a download (or failed to parse).
    $r13lIds = array_merge([98301], range(91000001, 91000499), [98302]);
    $r13lStates = VariantAvailability::variantStatesMany($r13lIds);
    assert_equals(['purchasable' => true, 'in_stock' => true, 'quantity' => 3], $r13lStates[98302] ?? null,
        'R13 low: in the 501st product of a listing a paperback with 3 in stock is for sale and counted (not read as a download)');
    assert_true(($r13lStates[98301]['purchasable'] ?? false) === true, 'R13 low: the e-book with its file in the first chunk stays for sale');
} catch (Throwable $e) { $r13lFail('listing over 500 products', $e); }

try {
    // 2. A digital product without variants is sold under its own SKU: without its file it is not for sale.
    assert_true(!VariantAvailability::isPurchasable(98303) && (VariantAvailability::purchasableMany([98303])[98303] ?? true) === false,
        'R13 low: a digital product without its file is not for sale (product page and listing)');
    $r13lMissingFiles = (int)Setup::digitalFilesCheck($pdo)['message'];
    $pdo->exec("INSERT INTO digital_assets (sku, storage_path, download_name, content_type) VALUES ('R13L-GUIDE', 'default/r13l-guide.pdf', 'guide.pdf', 'application/pdf')");
    assert_equals($r13lMissingFiles - 1, (int)Setup::digitalFilesCheck($pdo)['message'], 'R13 low: the doctor counted the digital product without a file (and stops when it has one)');
    $_SESSION['cart'] = [];
    Cart::add(98303, null, 1);
    assert_true(VariantAvailability::isPurchasable(98303) && (VariantAvailability::purchasableMany([98303])[98303] ?? false) === true
        && !empty(Cart::enriched()['items'][0]['available']), 'R13 low: with its file the digital product is for sale');
    $pdo->exec("UPDATE digital_assets SET active = 0 WHERE sku = 'R13L-GUIDE'");
    assert_true(empty(Cart::enriched()['items'][0]['available']), 'R13 low: a cart line of a digital product whose file was withdrawn cannot be checked out');
    $_SESSION['cart'] = [];
} catch (Throwable $e) { $r13lFail('digital product without file', $e); }

try {
    // 3. A bundle with an e-book component: its download follows the bundle's SKU and would never be delivered.
    assert_true(!VariantAvailability::isPurchasable(98304) && (VariantAvailability::purchasableMany([98304])[98304] ?? true) === false,
        'R13 low: a bundle with an e-book component is not for sale');
    $r13lReserve = '';
    try { Inventory::reserveLine(['product_id' => 98304, 'variant_id' => null, 'sku' => 'R13L-SET', 'attrs' => []], 1); }
    catch (Throwable $e) { $r13lReserve = $e->getMessage(); }
    assert_true(str_contains($r13lReserve, 'digital component'), 'R13 low: checkout refuses to reserve a bundle with a digital component (' . $r13lReserve . ')');
    $r13lHasBundleCheck = method_exists(Setup::class, 'digitalBundlesCheck');
    assert_true($r13lHasBundleCheck && str_contains(Setup::digitalBundlesCheck($pdo)['message'], 'R13L-SET'), 'R13 low: the doctor names the bundle');
} catch (Throwable $e) { $r13lFail('bundle with an e-book', $e); }

try {
    // 4. pricing.json catalog rules and group discounts: nothing applies them; the doctor says so, the sample ships none.
    assert_true(!method_exists(CacheRebuild::class, 'writePrices'), 'R13 low: the cache rebuild writes no per-group price map nothing reads');
    $r13lHasPricingCheck = method_exists(Setup::class, 'legacyPricingCheck');
    assert_true($r13lHasPricingCheck, 'R13 low: the doctor checks pricing.json for discounts it does not apply');
    if ($r13lHasPricingCheck) {
        $r13lWarn = Setup::legacyPricingCheck(['customer_groups' => ['vip' => ['discount' => 10]],
            'catalog_rules' => [['name' => 'eBooks -50 %', 'active' => true, 'conditions' => ['product_type' => 'digital'], 'discount_type' => 'percent', 'discount_value' => 50]]]);
        assert_true($r13lWarn['status'] === 'warn' && str_contains($r13lWarn['message'], 'eBooks -50 %') && str_contains($r13lWarn['message'], 'vip'),
            'R13 low: an active product_type catalog rule and a group discount are reported as not applied');
        $r13lSample = (array)json_decode((string)file_get_contents(dirname(TEST_CLI_DIR) . '/pricing.json'), true);
        assert_equals('pass', Setup::legacyPricingCheck($r13lSample)['status'], 'R13 low: the shipped pricing.json configures no discount that is not applied');
    }
} catch (Throwable $e) { $r13lFail('price rules', $e); }

try {
    // 5. A transfer due in 14 days: 14 × 24 h across the autumn clock change ended a day early for an order placed
    // in the first local hour after midnight.
    $r13lZone = ShopTime::zone('default');
    $r13lBack = null;
    $r13lTransitions = $r13lZone->getTransitions(strtotime('2026-01-01 UTC'), strtotime('2026-12-31 UTC'));
    for ($r13lI = 1; $r13lI < count($r13lTransitions); $r13lI++) {
        if ($r13lTransitions[$r13lI]['offset'] < $r13lTransitions[$r13lI - 1]['offset']) { $r13lBack = (int)$r13lTransitions[$r13lI]['ts']; break; }
    }
    $r13lPlaced = $r13lBack === null
        ? new DateTimeImmutable('2026-10-20 00:30:00', $r13lZone)
        : (new DateTimeImmutable('@' . ($r13lBack - 5 * 86400)))->setTimezone($r13lZone)->setTime(0, 30);
    $r13lOrder = ['created_at' => $r13lPlaced->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'store_id' => 'default'];
    assert_equals($r13lPlaced->modify('+14 days')->format('Y-m-d') . ' 23:59:59',
        ShopTime::date('Y-m-d H:i:s', BankTransferInstructions::dueAt($r13lOrder, 14 * 86400), 'default'),
        'R13 low: a transfer placed at 00:30 local five days before the clocks go back is due on the 14th calendar day (' . $r13lZone->getName() . ')');
} catch (Throwable $e) { $r13lFail('transfer due date', $e); }

try {
    // 6. The order_items.digital backfill reads the snapshot keys, not a LIKE on the JSON a customer value may contain.
    $r13lDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r13lDb->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, product_type TEXT)');
    $r13lDb->exec('CREATE TABLE variants (id INTEGER PRIMARY KEY, product_id INTEGER, digital INTEGER)');
    $r13lDb->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY, product_id INTEGER, variant_id INTEGER, attrs TEXT)');
    $r13lDb->exec("INSERT INTO products VALUES (1, 'simple'), (2, 'digital')");
    $r13lDb->exec('INSERT INTO variants VALUES (10, 1, 1), (11, 1, 0)');
    $r13lDb->exec("INSERT INTO order_items VALUES
        (1, 1, 10, '{\"format\":\"eBook\",\"edition\":\"Notes on allocations\"}'),
        (2, 1, 10, '{\"format\":\"eBook\",\"_inventory_allocations\":[{\"warehouse_id\":1,\"quantity\":1}]}'),
        (3, 1, 11, '{\"format\":\"Paperback\"}'),
        (4, 2, NULL, NULL),
        (5, 1, 10, '{\"dedication\":\"for the bundle_components club\"}')");
    (require dirname(TEST_CLI_DIR) . '/migrations/20261103090000_order_item_digital.php')->up($r13lDb, true);
    assert_equals([1 => 1, 2 => 0, 3 => 0, 4 => 1, 5 => 1],
        array_map('intval', $r13lDb->query('SELECT id, digital FROM order_items ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR)),
        'R13 low: the backfill marks an e-book line digital even when a customer value mentions "allocations" or "bundle_components"');
} catch (Throwable $e) { $r13lFail('order_items.digital backfill', $e); }

// 7. Saving variants before `migrate` added variants.digital: the column is only written when it exists.
$r13lVariantsSrc = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/ProductVariants.php');
assert_true(str_contains($r13lVariantsSrc, "Db::hasColumn('variants', 'digital')") && str_contains($r13lVariantsSrc, "(\$hasDigital ? ', digital = :digital' : '')"),
    'R13 low: ProductVariants writes variants.digital only when the column exists (code deployed before migrate)');

// 8. The bookshop theme speaks the store's language: no English text outside {{t.…}} keys, every key translated.
$r13lThemeDir = dirname(TEST_CLI_DIR) . '/themes/bookshop/blocks/storefront';
$r13lLeft = [];
$r13lKeys = [];
foreach (glob($r13lThemeDir . '/*.html') ?: [] as $r13lFile) {
    $r13lHtml = (string)file_get_contents($r13lFile);
    preg_match_all('/\{\{t\.(bookshop\.[a-z_]+)\}\}/', $r13lHtml, $r13lM);
    $r13lKeys = array_merge($r13lKeys, $r13lM[1]);
    // Attribute values (placeholder="…") are visible text too.
    $r13lText = preg_replace(['/\{\{\{?[^}]*\}?\}\}/', '/<[^>]+>/', '/&[a-z]+;/'], ' ', $r13lHtml);
    if (preg_match_all('/[A-Za-z]{3,}/', (string)$r13lText, $r13lWords) && $r13lWords[0] !== []) $r13lLeft[basename($r13lFile)] = implode(' ', array_slice($r13lWords[0], 0, 6));
    if (preg_match_all('/\s(?:placeholder|title|aria-label|alt)="([^"{]*[A-Za-z]{3,}[^"{]*)"/', $r13lHtml, $r13lAttr)) {
        foreach ($r13lAttr[1] as $r13lValue) $r13lLeft[basename($r13lFile) . ' attr'] = $r13lValue;
    }
}
assert_equals([], $r13lLeft, 'R13 low: the bookshop templates hold no hard-coded English text');
$r13lMissing = [];
foreach (glob(dirname(TEST_CLI_DIR) . '/lang/*.php') ?: [] as $r13lLang) {
    $r13lStrings = require $r13lLang;
    foreach (array_unique($r13lKeys) as $r13lKey) if (!isset($r13lStrings[$r13lKey])) $r13lMissing[] = basename($r13lLang, '.php') . ':' . $r13lKey;
}
assert_true($r13lKeys !== [] && $r13lMissing === [], 'R13 low: every bookshop text key exists in every language (' . implode(', ', array_slice($r13lMissing, 0, 5)) . ')');

$_SESSION['cart'] = [];
$pdo->exec("DELETE FROM digital_assets WHERE sku IN ('R13L-A-EB', 'R13L-GUIDE')");
$pdo->exec('DELETE FROM bundle_components WHERE bundle_product_id = 98304');
$pdo->exec('DELETE FROM variants WHERE product_id IN (98301, 98302)');
$pdo->exec('DELETE FROM products WHERE id IN (98301, 98302, 98303, 98304)');
