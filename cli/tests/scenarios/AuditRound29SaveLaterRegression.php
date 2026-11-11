<?php

// Review round 29 (2026-11-11, main @ d9dc6722), lows of "Buy later" (goal #411):
// - a product switched to draft for a moment vanished for good from every shopper's list (priced() deleted unlisted lines);
// - a full list and a failure showed an empty notice; signing in with a full account list (100) dropped the guest list silently;
// - moving back with less in stock dropped the rest of the saved quantity; a sold-out line had an active button;
// - code deployed before its migration made a signed-in customer's cart a 500;
// - SQLite reuses a deleted variant's id: the saved line was mapped onto the new variant;
// - a product that got variants since could not be moved back to the cart (variant_required, no way out).
echo "\n--- R29: Buy later — drafts, notices, full merge, limited stock, sold out, before migration, reused variant id, new variants ---\n";
$r29sDb = Db::get();
(require TEST_CLI_DIR . '/../migrations/20261110190000_saved_for_later_items.php')->up($r29sDb, Db::isSqlite());
(require TEST_CLI_DIR . '/../migrations/20261111100000_saved_for_later_variant_sku.php')->up($r29sDb, Db::isSqlite());
$r29sSession = $_SESSION;
$r29sGlobals = ['t' => $GLOBALS['t'] ?? null];
$r29sIds = [93911, 93912, 93913, 93914];
try {
    $r29sDb->exec('DELETE FROM saved_for_later_items');
    $r29sDb->exec('DELETE FROM variants WHERE product_id IN (' . implode(',', $r29sIds) . ')');
    $r29sDb->exec('DELETE FROM products WHERE id IN (' . implode(',', $r29sIds) . ')');
    $r29sDb->exec("DELETE FROM customers WHERE email = 'r29s@example.test'");
    $r29sInsert = $r29sDb->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (?, 'default', 1, ?, ?, ?, 'simple', 1000, 'eur', 'active', 0, 'deny')");
    foreach ($r29sIds as $r29sId) $r29sInsert->execute([$r29sId, "R29S {$r29sId}", "r29s-{$r29sId}", "R29S-{$r29sId}"]);
    $r29sWh = (int)$r29sDb->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $r29sStock = static function (string $sku, int $qty) use ($r29sWh): void {
        Warehouse::setStock($r29sWh, $sku, $qty, 'count');
        Warehouse::syncProductStock([$sku]);
    };
    $r29sDb->exec("DELETE FROM warehouse_stock WHERE sku LIKE 'R29S-%'");
    foreach ($r29sIds as $r29sId) $r29sStock("R29S-{$r29sId}", 10);
    $r29sDb->prepare("INSERT INTO variants (id, product_id, sku, name) VALUES (939111, 93911, 'R29S-V1', 'Red')")->execute();
    $r29sStock('R29S-V1', 10);

    $_SESSION = ['csrf_token' => 'r29s'];
    $r29sAct = static fn(array $input): array => StorefrontCartAction::execute($input);
    $r29sSave = static function (string $key, int $productId, ?int $variantId, int $qty) use ($r29sAct): array {
        $_SESSION['cart'][$key] = ['product_id' => $productId, 'variant_id' => $variantId, 'qty' => $qty];
        return $r29sAct(['cart_action' => 'save_for_later', 'cart_key' => $key]);
    };
    $r29sListed = static fn(): array => array_column(SavedForLater::priced(), 'key');

    // 1. A product switched to draft and back: hidden meanwhile, still saved after.
    $r29sSave('p_93913', 93913, null, 1);
    $r29sDb->exec("UPDATE products SET status = 'draft' WHERE id = 93913");
    $r29sHidden = $r29sListed();
    $r29sDb->exec("UPDATE products SET status = 'active' WHERE id = 93913");
    assert_equals([[], ['p_93913']], [$r29sHidden, $r29sListed()], 'R29 buy later: a draft product is not listed but stays saved (was: deleted from every list for good)');

    // 2. A deleted variant whose id SQLite gives to the next one: the line is dropped, not mapped onto the new variant.
    $r29sSave('v_939111', 93911, 939111, 1);
    assert_equals('R29S-V1', SavedForLater::lines()['v_939111']['variant_sku'] ?? null, 'R29 buy later: a variant line keeps its variant SKU');
    $r29sDb->exec('DELETE FROM variants WHERE id = 939111');
    $r29sDb->prepare("INSERT INTO variants (id, product_id, sku, name) VALUES (939111, 93911, 'R29S-V2', 'Blue')")->execute();
    $r29sStock('R29S-V2', 10);
    assert_true(!in_array('v_939111', $r29sListed(), true) && !isset(SavedForLater::lines()['v_939111']),
        'R29 buy later: a line whose variant id now names another variant is dropped (was: shown and moved as "Blue")');

    // 3. A product saved without a variant that has variants now: the list links its page, "to cart" says to choose one.
    $r29sSave('p_93912', 93912, null, 1);
    $r29sDb->prepare("INSERT INTO variants (product_id, sku, name) VALUES (93912, 'R29S-93912-S', 'Small')")->execute();
    $r29sStock('R29S-93912-S', 10);
    $r29sView = array_column(SavedForLater::view(null, '/cart', 'r29s')['items'], null, 'key')['p_93912'] ?? [];
    $r29sMoved = $r29sAct(['cart_action' => 'saved_to_cart', 'saved_key' => 'p_93912']);
    assert_equals([false, I18n::t('saved_for_later_choose_variant_link'), false, I18n::t('saved_for_later_choose_variant'), true],
        [$r29sView['can_move'] ?? null, $r29sView['choose_variant'] ?? null, $r29sMoved['ok'], $r29sMoved['notice'] ?? null, isset(SavedForLater::lines()['p_93912'])],
        'R29 buy later: a product with new variants links its page and is kept (was: "variant required", no way back)');
    if (!function_exists('renderCart')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Storefront/CheckoutPages.php';
    $GLOBALS['t'] = static fn(string $key): string => I18n::t($key);
    $r29sDb->exec("UPDATE products SET stock = 0 WHERE id = 93913");
    $r29sStock('R29S-93913', 0);
    $r29sHtml = renderCart();
    assert_true(str_contains($r29sHtml, '<a href="' . htmlspecialchars(Template::storeUrl('/product/r29s-93912'), ENT_QUOTES) . '" class="btn-saved-to-cart">')
        && str_contains($r29sHtml, 'class="btn-saved-to-cart" disabled aria-disabled="true"'),
        'R29 buy later: the cart page links "choose a variant" and disables "to cart" of a sold-out line (was: an active button)');
    $r29sStock('R29S-93913', 10);

    // 4. Less in stock than saved: what fits goes to the cart, the rest stays saved.
    $r29sStock('R29S-93914', 3);
    $r29sSave('p_93914', 93914, null, 5);
    $r29sMoved = $r29sAct(['cart_action' => 'saved_to_cart', 'saved_key' => 'p_93914']);
    assert_equals([true, 3, 2, I18n::format('saved_for_later_partly_moved', ['moved' => 3, 'left' => 2])],
        [$r29sMoved['ok'], (int)(Cart::get()['p_93914']['qty'] ?? 0), (int)(SavedForLater::lines()['p_93914']['qty'] ?? 0), $r29sMoved['notice'] ?? null],
        'R29 buy later: 3 of 5 in stock move to the cart and 2 stay saved (was: the 2 were lost)');

    // 5. A full list says so (was: an empty notice on the no-JS cart).
    $r29sGuestFull = [];
    for ($i = 1; $i <= SavedForLater::MAX_LINES; $i++) $r29sGuestFull["p_" . (8000000 + $i)] = ['product_id' => 8000000 + $i, 'variant_id' => null, 'qty' => 1];
    $r29sKeep = $_SESSION['saved_for_later']['default'];
    $_SESSION['saved_for_later']['default'] = $r29sGuestFull;
    $r29sFull = $r29sSave('p_93911', 93911, null, 1);
    assert_equals(['saved_for_later_full', I18n::t('saved_for_later_full'), I18n::t('saved_for_later_full')], [$r29sFull['error'], $r29sFull['notice'] ?? null, StorefrontCartAction::notice($r29sFull)],
        'R29 buy later: saving into a full list is refused with its notice');
    $_SESSION['saved_for_later']['default'] = $r29sKeep;
    unset($_SESSION['cart']['p_93911']);

    // 6. Signing in with a full account list: the guest lines that do not fit are announced.
    $r29sDb->prepare("INSERT INTO customers (store_id, email, password_hash, name, status, email_verified_at) VALUES ('default', 'r29s@example.test', ?, 'R29S', 'active', '2026-01-01 00:00:00')")
        ->execute([password_hash('R29s-pass-1234', PASSWORD_BCRYPT, ['cost' => 4])]);
    $r29sCustomer = (int)$r29sDb->query("SELECT id FROM customers WHERE email = 'r29s@example.test'")->fetchColumn();
    $r29sRow = $r29sDb->prepare("INSERT INTO saved_for_later_items (customer_id, store_id, item_key, product_id, variant_id, qty) VALUES (?, 'default', ?, ?, NULL, 1)");
    foreach (array_keys($r29sGuestFull) as $r29sKey) $r29sRow->execute([$r29sCustomer, $r29sKey, (int)substr($r29sKey, 2)]);
    $r29sGuestLines = count(SavedForLater::lines());
    StorefrontFlash::pull();
    [$r29sIn] = Customers::login('r29s@example.test', 'R29s-pass-1234');
    assert_equals([true, I18n::format('saved_for_later_merge_full', ['count' => $r29sGuestLines, 'max' => SavedForLater::MAX_LINES])], [$r29sIn, StorefrontFlash::pull()],
        'R29 buy later: guest lines a full account list cannot take are announced on sign-in (was: dropped silently)');

    // 7. Code deployed before its migration: the signed-in cart works (empty list), saving is refused with a notice.
    $r29sDb->exec('ALTER TABLE saved_for_later_items RENAME TO r29s_saved_moved');
    Db::forgetSchema();
    if (property_exists(SavedForLater::class, 'tableReady')) (new ReflectionProperty(SavedForLater::class, 'tableReady'))->setValue(null, false);
    try {
        $r29sPage = null;
        try {
            $r29sPage = SavedForLater::view(null, '/cart', 'r29s');
            $_SESSION['cart']['p_93913'] = ['product_id' => 93913, 'variant_id' => null, 'qty' => 1];
            $r29sNoTable = SavedForLater::saveFromCart('p_93913');
        } catch (Throwable $e) {
            $r29sNoTable = ['error' => get_class($e) . ': ' . $e->getMessage()];
        }
        assert_equals([[], 'saved_for_later_error'], [$r29sPage['items'] ?? null, $r29sNoTable['error'] ?? null],
            'R29 buy later: before the migration a signed-in cart shows no list and saving is refused (was: a 500)');
    } finally {
        $r29sDb->exec('ALTER TABLE r29s_saved_moved RENAME TO saved_for_later_items');
        Db::forgetSchema();
    }
} catch (Throwable $e) {
    assert_true(false, 'R29 buy later (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $_SESSION = $r29sSession;
    foreach ($r29sGlobals as $r29sKey => $r29sValue) $GLOBALS[$r29sKey] = $r29sValue;
    $r29sDb->exec('DELETE FROM saved_for_later_items');
    $r29sDb->exec("DELETE FROM customers WHERE email = 'r29s@example.test'");
    $r29sDb->exec("DELETE FROM warehouse_stock WHERE sku LIKE 'R29S-%'");
    $r29sDb->exec('DELETE FROM variants WHERE product_id IN (' . implode(',', $r29sIds) . ')');
    $r29sDb->exec('DELETE FROM products WHERE id IN (' . implode(',', $r29sIds) . ')');
}
