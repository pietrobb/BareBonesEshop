<?php

// Review round 30 (2026-11-11, main @ e41e8e0b), lows of "Buy later" (goal #417):
// - an admin changing a variant's SKU deleted every saved line of it for good;
// - lines of a draft or archived product were hidden but counted towards the 100 (90 shown, "the list is full"), and only
//   "Clear" removed them;
// - signing in with a full list said how many guest lines were dropped, not which;
// - code deployed before the migration dropped the guest list at sign-in silently;
// - "0 moved to your cart" when nothing moved; a failed move said the item could not be saved for later.
echo "\n--- R30: Buy later — SKU change, hidden lines, named merge losses, before migration, nothing moved ---\n";
$r30sDb = Db::get();
(require TEST_CLI_DIR . '/../migrations/20261110190000_saved_for_later_items.php')->up($r30sDb, Db::isSqlite());
(require TEST_CLI_DIR . '/../migrations/20261111100000_saved_for_later_variant_sku.php')->up($r30sDb, Db::isSqlite());
$r30sSession = $_SESSION;
$r30sIds = [93921, 93922, 93923, 93924];
$r30sResetTable = static function (): void {
    Db::forgetSchema();
    (new ReflectionProperty(SavedForLater::class, 'tableReady'))->setValue(null, false);
};
try {
    $r30sDb->exec('DELETE FROM saved_for_later_items');
    $r30sDb->exec('DELETE FROM variants WHERE product_id IN (' . implode(',', $r30sIds) . ')');
    $r30sDb->exec('DELETE FROM products WHERE id IN (' . implode(',', $r30sIds) . ')');
    $r30sDb->exec("DELETE FROM customers WHERE email = 'r30s@example.test'");
    $r30sInsert = $r30sDb->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (?, 'default', 1, ?, ?, ?, 'simple', 1000, 'eur', 'active', 0, 'deny')");
    foreach ($r30sIds as $r30sId) $r30sInsert->execute([$r30sId, "R30S {$r30sId}", "r30s-{$r30sId}", "R30S-{$r30sId}"]);
    $r30sWh = (int)$r30sDb->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $r30sStock = static function (string $sku, int $qty) use ($r30sWh): void {
        Warehouse::setStock($r30sWh, $sku, $qty, 'count');
        Warehouse::syncProductStock([$sku]);
    };
    $r30sDb->exec("DELETE FROM warehouse_stock WHERE sku LIKE 'R30S-%'");
    foreach ($r30sIds as $r30sId) $r30sStock("R30S-{$r30sId}", 10);
    $r30sDb->prepare("INSERT INTO variants (id, product_id, sku, name) VALUES (939211, 93921, 'R30S-V1', 'Red')")->execute();
    $r30sDb->prepare("INSERT INTO variants (id, product_id, sku, name) VALUES (939212, 93921, 'R30S-V2', 'Blue')")->execute();
    $r30sStock('R30S-V1', 10);
    $r30sStock('R30S-V2', 10);
    $r30sDb->prepare("INSERT INTO customers (store_id, email, password_hash, name, status, email_verified_at) VALUES ('default', 'r30s@example.test', ?, 'R30S', 'active', '2026-01-01 00:00:00')")
        ->execute([password_hash('R30s-pass-1234', PASSWORD_BCRYPT, ['cost' => 4])]);
    $r30sCustomer = (int)$r30sDb->query("SELECT id FROM customers WHERE email = 'r30s@example.test'")->fetchColumn();

    $r30sAct = static fn(array $input): array => StorefrontCartAction::execute($input);
    $r30sSave = static function (string $key, int $productId, ?int $variantId, int $qty) use ($r30sAct): array {
        $_SESSION['cart'][$key] = ['product_id' => $productId, 'variant_id' => $variantId, 'qty' => $qty];
        return $r30sAct(['cart_action' => 'save_for_later', 'cart_key' => $key]);
    };
    $r30sView = static fn(): array => array_column(SavedForLater::view(null, '/cart', 'r30s')['items'], null, 'key');

    // 1. A signed-in customer's variant line survives the admin changing the variant's SKU.
    $_SESSION = ['csrf_token' => 'r30s', 'customer_id' => $r30sCustomer];
    $r30sSave('v_939211', 93921, 939211, 2);
    $r30sRow = array_column(ProductVariants::forEditor(93921), null, 'id')[939211];
    $r30sErrors = ProductVariants::save(93921, ['delete' => [], 'rows' => [[
        'id' => 939211, 'sku' => 'R30S-V1-NEW', 'name' => 'Red', 'attrs' => '{}', 'price_absolute' => null, 'price_delta' => 0,
        'stock' => (int)$r30sRow['stock'], 'stock_loaded' => null, 'availability' => VariantAvailability::ACTIVE,
        'inventory_policy' => 'inherit', 'sort_order' => 0, 'digital' => 0,
    ]]], 'simple');
    $r30sItem = $r30sView()['v_939211'] ?? [];
    assert_equals([[], 'R30S-V1-NEW', 2, true], [$r30sErrors, SavedForLater::lines()['v_939211']['variant_sku'] ?? null, $r30sItem['quantity'] ?? null, $r30sItem['can_move'] ?? null],
        'R30 buy later: a variant whose SKU an admin changed keeps its saved lines (was: deleted from every list for good)');
    SavedForLater::clear();

    // 2. A guest's variant line whose SKU changed becomes its product's line: "choose a variant", not deleted.
    $_SESSION = ['csrf_token' => 'r30s'];
    $r30sSave('v_939212', 93921, 939212, 3);
    $r30sDb->exec("UPDATE variants SET sku = 'R30S-V2-NEW' WHERE id = 939212");
    $r30sItem = $r30sView()['p_93921'] ?? [];
    assert_equals([['p_93921'], 3, false, I18n::t('saved_for_later_choose_variant_link')],
        [array_keys(SavedForLater::lines()), $r30sItem['quantity'] ?? null, $r30sItem['can_move'] ?? null, $r30sItem['choose_variant'] ?? null],
        'R30 buy later: a guest line whose variant SKU changed asks to choose the variant (was: deleted)');
    SavedForLater::clear();

    // 3. A line of a draft product is listed as unavailable, counted in the title and removable on its own.
    $r30sSave('p_93922', 93922, null, 1);
    $r30sSave('p_93923', 93923, null, 1);
    $r30sDb->exec("UPDATE products SET status = 'draft' WHERE id = 93922");
    $r30sPage = SavedForLater::view(null, '/cart', 'r30s');
    $r30sItem = array_column($r30sPage['items'], null, 'key')['p_93922'] ?? [];
    assert_equals([['p_93923', 'p_93922'], I18n::format('saved_for_later_title', ['count' => 2]), 'R30S 93922', '', false, I18n::t('saved_for_later_unavailable')],
        [array_column($r30sPage['items'], 'key'), $r30sPage['title'], $r30sItem['name'] ?? null, $r30sItem['url'] ?? null, $r30sItem['can_move'] ?? null, $r30sItem['unavailable'] ?? null],
        'R30 buy later: a draft product\'s line is listed (unavailable, newest first) and counted (was: hidden, yet counted towards the 100)');
    if (!function_exists('renderCart')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Storefront/CheckoutPages.php';
    $r30sGlobalT = $GLOBALS['t'] ?? null;
    $GLOBALS['t'] = static fn(string $key): string => I18n::t($key);
    $r30sHtml = renderCart();
    $GLOBALS['t'] = $r30sGlobalT;
    assert_true(str_contains($r30sHtml, '<span class="prod-name">R30S 93922</span>') && str_contains($r30sHtml, 'name="saved_key" value="p_93922"'),
        'R30 buy later: the cart page shows the draft line without a link and with its own remove button');
    $r30sAct(['cart_action' => 'saved_remove', 'saved_key' => 'p_93922']);
    assert_equals(['p_93923'], array_keys(SavedForLater::lines()), 'R30 buy later: the draft line is removed on its own (was: only "Clear" removed it)');
    $r30sDb->exec("UPDATE products SET status = 'active' WHERE id = 93922");
    SavedForLater::clear();

    // 4. Nothing moved (the cart holds all there is in stock): said so, not "0 moved to your cart".
    $r30sStock('R30S-93924', 3);
    $r30sSave('p_93924', 93924, null, 2);
    $_SESSION['cart']['p_93924'] = ['product_id' => 93924, 'variant_id' => null, 'qty' => 3];
    $r30sMoved = $r30sAct(['cart_action' => 'saved_to_cart', 'saved_key' => 'p_93924']);
    assert_equals([false, 'saved_for_later_not_moved', I18n::format('saved_for_later_not_moved', ['count' => 3]), 3, 2],
        [$r30sMoved['ok'], $r30sMoved['error'] ?? null, StorefrontCartAction::notice($r30sMoved), (int)(Cart::get()['p_93924']['qty'] ?? 0), (int)(SavedForLater::lines()['p_93924']['qty'] ?? 0)],
        'R30 buy later: with the cart holding all the stock nothing moves and the notice says so (was: "0 moved to your cart")');
    $r30sMissing = $r30sAct(['cart_action' => 'saved_to_cart', 'saved_key' => 'p_93929']);
    assert_equals('saved_for_later_move_error', $r30sMissing['error'] ?? null, 'R30 buy later: a failed move has its own text (was: "could not be saved for later")');
    $r30sStock('R30S-93924', 10);
    SavedForLater::clear();
    unset($_SESSION['cart']);

    // 5. Signing in before the migration keeps the guest list; it joins the account once the table is there.
    $_SESSION = ['csrf_token' => 'r30s', 'saved_for_later' => ['default' => ['p_93923' => ['product_id' => 93923, 'variant_id' => null, 'qty' => 2]]]];
    $r30sDb->exec('ALTER TABLE saved_for_later_items RENAME TO r30s_saved_moved');
    $r30sResetTable();
    try {
        [$r30sIn] = Customers::login('r30s@example.test', 'R30s-pass-1234');
        $r30sKept = isset($_SESSION['saved_for_later']['default']['p_93923']);
    } finally {
        $r30sDb->exec('ALTER TABLE r30s_saved_moved RENAME TO saved_for_later_items');
        $r30sResetTable();
    }
    assert_equals([true, true, ['p_93923' => 2], false],
        [$r30sIn, $r30sKept, array_map(static fn(array $l): int => $l['qty'], SavedForLater::lines()), isset($_SESSION['saved_for_later']['default'])],
        'R30 buy later: a guest list at a sign-in before the migration is kept and joins the account after it (was: dropped silently)');
    SavedForLater::clear();

    // 6. Signing in with a full account list names the guest lines that did not fit.
    $r30sFill = $r30sDb->prepare("INSERT INTO saved_for_later_items (customer_id, store_id, item_key, product_id, variant_id, qty) VALUES (?, 'default', ?, ?, NULL, 1)");
    for ($i = 1; $i <= SavedForLater::MAX_LINES; $i++) $r30sFill->execute([$r30sCustomer, 'p_' . (8100000 + $i), 8100000 + $i]);
    $_SESSION = ['csrf_token' => 'r30s', 'saved_for_later' => ['default' => [
        'p_93922' => ['product_id' => 93922, 'variant_id' => null, 'qty' => 1],
        'v_939211' => ['product_id' => 93921, 'variant_id' => 939211, 'qty' => 1],
    ]]];
    StorefrontFlash::pull();
    Customers::login('r30s@example.test', 'R30s-pass-1234');
    assert_equals(I18n::format('saved_for_later_merge_full', ['count' => 2, 'max' => SavedForLater::MAX_LINES])
        . ' ' . I18n::format('saved_for_later_merge_left', ['items' => 'R30S 93922, R30S 93921 (Red)']), StorefrontFlash::pull(),
        'R30 buy later: the guest lines a full account list cannot take are named (was: only counted)');
} catch (Throwable $e) {
    assert_true(false, 'R30 buy later (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $_SESSION = $r30sSession;
    $r30sDb->exec('DELETE FROM saved_for_later_items');
    $r30sDb->exec("DELETE FROM customers WHERE email = 'r30s@example.test'");
    $r30sDb->exec("DELETE FROM warehouse_stock WHERE sku LIKE 'R30S-%'");
    $r30sDb->exec('DELETE FROM variants WHERE product_id IN (' . implode(',', $r30sIds) . ')');
    $r30sDb->exec('DELETE FROM products WHERE id IN (' . implode(',', $r30sIds) . ')');
}
