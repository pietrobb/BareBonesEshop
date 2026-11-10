<?php

// Goal #399 (2026-11-10): "Buy later" (save for later, as Amazon) — a cart line moves to a list under the cart with its
// variant and quantity, and back with one click. Saved lines are not ordered: no total, count, shipping, coupon or stock
// reservation sees them. Moving back re-checks today's price and stock. A guest's list is in the session and joins the
// account on login without duplicates; lists are per store; GDPR export and erasure cover the account's list.
echo "\n--- Save for later (buy later): cart → saved → cart, totals, price change, sold out, login merge, store, GDPR ---\n";
$sflDb = Db::get();
(require TEST_CLI_DIR . '/../migrations/20261110190000_saved_for_later_items.php')->up($sflDb, Db::isSqlite());
$sflSession = $_SESSION;
$sflGlobals = ['t' => $GLOBALS['t'] ?? null, 'storefront_currency' => $GLOBALS['storefront_currency'] ?? null];
try {
    $sflDb->exec('DELETE FROM saved_for_later_items');
    $sflDb->exec('DELETE FROM products WHERE id IN (93901, 93902)');
    $sflDb->exec("DELETE FROM customers WHERE email = 'sfl-shopper@example.test'");
    $sflInsert = $sflDb->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (?, 'default', 1, ?, ?, ?, 'simple', ?, 'eur', 'active', 0, 'deny')");
    $sflInsert->execute([93901, 'SFL Lamp', 'sfl-lamp', 'SFL-A', 1500]);
    $sflInsert->execute([93902, 'SFL Vase', 'sfl-vase', 'SFL-B', 700]);
    $sflWh = (int)$sflDb->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $sflStock = static function (string $sku, int $qty) use ($sflWh): void {
        Warehouse::setStock($sflWh, $sku, $qty, 'count');
        Warehouse::syncProductStock([$sku]);
    };
    $sflDb->prepare("DELETE FROM warehouse_stock WHERE sku IN ('SFL-A', 'SFL-B')")->execute();
    $sflDb->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', ?, 'SFL-A', 0, 0), ('default', ?, 'SFL-B', 0, 0)")->execute([$sflWh, $sflWh]);
    $sflStock('SFL-A', 10);
    $sflStock('SFL-B', 10);

    $_SESSION = ['csrf_token' => 'sfl'];
    $sflAct = static fn(array $input): array => StorefrontCartAction::execute($input);

    // 1. A guest saves a cart line: it leaves the cart (total, count) and keeps product and quantity.
    assert_true(Cart::addValidated(93901, null, 2)['ok'] && Cart::addValidated(93902, null, 1)['ok'], 'Save for later: both products go into the cart');
    $sflSaved = $sflAct(['cart_action' => 'save_for_later', 'cart_key' => 'p_93901']);
    $sflCart = Cart::enriched();
    assert_true($sflSaved['ok'] && !isset(Cart::get()['p_93901']), 'Save for later: the saved line leaves the cart');
    assert_equals([700, 1], [(int)$sflCart['subtotal'], (int)$sflCart['count']], 'Save for later: the cart total and item count no longer include the saved line');
    assert_equals(['p_93901' => ['product_id' => 93901, 'variant_id' => null, 'qty' => 2, 'saved_price' => 1500, 'saved_currency' => 'eur']], SavedForLater::lines(),
        'Save for later: the list keeps product, quantity and the unit price seen when saving');
    assert_equals(0, (int)$sflDb->query("SELECT COUNT(*) FROM saved_for_later_items")->fetchColumn(), 'Save for later: a guest list is not written to the database');

    // A variant line keeps its variant; saving a line that is already saved adds the quantities (capped like the cart).
    $_SESSION['cart']['v_939011'] = ['product_id' => 93901, 'variant_id' => 939011, 'qty' => 3];
    SavedForLater::saveFromCart('v_939011');
    assert_equals(['product_id' => 93901, 'variant_id' => 939011, 'qty' => 3], array_slice(SavedForLater::lines()['v_939011'] ?? [], 0, 3),
        'Save for later: a variant line keeps its variant and quantity');
    assert_equals(['v_939011', 'p_93901'], array_keys(SavedForLater::lines()), 'Save for later: newest saved line first');
    $sflAct(['cart_action' => 'saved_remove', 'saved_key' => 'v_939011']);
    $_SESSION['cart']['p_93901'] = ['product_id' => 93901, 'variant_id' => null, 'qty' => 98];
    SavedForLater::saveFromCart('p_93901');
    assert_equals(Cart::MAX_QTY, SavedForLater::lines()['p_93901']['qty'], 'Save for later: saving again adds the quantities, capped at Cart::MAX_QTY');
    $_SESSION['saved_for_later']['default']['p_93901']['qty'] = 2;
    assert_equals('cart_error', $sflAct(['cart_action' => 'save_for_later', 'cart_key' => 'p_404'])['error'], 'Save for later: a key not in the cart is refused');

    // Checkout sees only the cart: the saved line is not in the goods it orders.
    $sflGoods = CheckoutService::previewCart('SK', [], '')['goods'];
    assert_equals(['p_93902'], array_column($sflGoods, 'key'), 'Save for later: checkout goods (shipping, coupons, reservation) do not include saved lines');

    // 2. The cart page shows the section with its count, note, quantity, price and the no-JS forms.
    if (!function_exists('renderCart')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Storefront/CheckoutPages.php';
    $GLOBALS['t'] = static fn(string $key): string => I18n::t($key);
    $sflHtml = renderCart();
    $sflE = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES);
    assert_true(str_contains($sflHtml, 'class="saved-later"') && str_contains($sflHtml, $sflE(I18n::format('saved_for_later_title', ['count' => 1])))
        && str_contains($sflHtml, $sflE(I18n::t('saved_for_later_note'))) && str_contains($sflHtml, 'SFL Lamp'),
        'Save for later: the cart page lists the saved line under a "Buy later (1)" heading with the note');
    assert_true(str_contains($sflHtml, 'name="cart_action" value="saved_to_cart"') && str_contains($sflHtml, 'name="cart_action" value="saved_remove"')
        && str_contains($sflHtml, 'name="cart_action" value="saved_clear"') && str_contains($sflHtml, 'name="cart_action" value="save_for_later"'),
        'Save for later: move to cart, remove, clear saved and save for later are plain forms (no JavaScript needed)');
    assert_true(str_contains($sflHtml, $sflE(I18n::price(1500, 'eur'))) && str_contains($sflHtml, $sflE(I18n::price(700, 'eur'))),
        'Save for later: the saved line shows its current unit price, the cart its own total');
    // With an empty cart the page still shows the saved lines.
    $sflCartBefore = $_SESSION['cart'];
    $_SESSION['cart'] = [];
    $sflEmptyHtml = renderCart();
    assert_true(str_contains($sflEmptyHtml, $sflE(I18n::t('cart_empty'))) && str_contains($sflEmptyHtml, 'class="saved-later"'),
        'Save for later: an empty cart still shows the "Buy later" list');
    $_SESSION['cart'] = $sflCartBefore;

    // 3. The price rose meanwhile: the list says so, and moving back puts today's price in the cart and tells the old one.
    $sflDb->exec('UPDATE products SET price = 1800 WHERE id = 93901');
    $sflPriced = SavedForLater::priced();
    assert_true(($sflPriced[0]['price_changed'] ?? false) === true && (int)$sflPriced[0]['price'] === 1800, 'Save for later: the list shows today\'s price and marks the change');
    assert_true(str_contains(renderCart(), $sflE(I18n::format('saved_for_later_was', ['price' => I18n::price(1500, 'eur')]))), 'Save for later: the cart page shows the earlier price');
    $sflMoved = $sflAct(['cart_action' => 'saved_to_cart', 'saved_key' => 'p_93901']);
    $sflCart = Cart::enriched();
    assert_true($sflMoved['ok'] && (int)(Cart::get()['p_93901']['qty'] ?? 0) === 2 && !isset(SavedForLater::lines()['p_93901']),
        'Save for later: "to cart" moves the line back with its quantity and removes it from the list');
    assert_equals(1800 * 2 + 700, (int)$sflCart['subtotal'], 'Save for later: the moved line is charged today\'s price');
    assert_true(str_contains((string)$sflMoved['notice'], I18n::price(1500, 'eur')) && str_contains((string)$sflMoved['notice'], I18n::price(1800, 'eur')),
        'Save for later: the shopper is told the price changed from the saved to today\'s price');

    // 4. Sold out meanwhile: the line stays saved, is marked unavailable and "to cart" says why.
    $sflAct(['cart_action' => 'save_for_later', 'cart_key' => 'p_93902']);
    $sflStock('SFL-B', 0);
    $sflPriced = array_column(SavedForLater::priced(), null, 'key');
    assert_true(empty($sflPriced['p_93902']['available']), 'Save for later: a sold-out saved line is shown as unavailable');
    $sflRefused = $sflAct(['cart_action' => 'saved_to_cart', 'saved_key' => 'p_93902']);
    assert_true(!$sflRefused['ok'] && $sflRefused['error'] === 'product_unavailable' && isset(SavedForLater::lines()['p_93902']) && !isset(Cart::get()['p_93902']),
        'Save for later: a sold-out line is not moved to the cart and stays saved');
    assert_equals(I18n::t('product_unavailable'), $sflRefused['notice'], 'Save for later: the shopper is told the product is unavailable');
    $sflStock('SFL-B', 10);

    // 5. Login: the guest list of this store joins the account's list; a line in both is kept once.
    $sflDb->prepare("INSERT INTO customers (store_id, email, password_hash, name, status, email_verified_at) VALUES ('default', 'sfl-shopper@example.test', ?, 'SFL', 'active', '2026-01-01 00:00:00')")
        ->execute([password_hash('Sfl-pass-1234', PASSWORD_BCRYPT, ['cost' => 4])]);
    $sflCustomer = (int)$sflDb->query("SELECT id FROM customers WHERE email = 'sfl-shopper@example.test'")->fetchColumn();
    $sflDb->prepare("INSERT INTO saved_for_later_items (customer_id, store_id, item_key, product_id, variant_id, qty) VALUES (?, 'default', 'p_93902', 93902, NULL, 4), (?, 'cz', 'p_93901', 93901, NULL, 1)")
        ->execute([$sflCustomer, $sflCustomer]);
    $sflAct(['cart_action' => 'save_for_later', 'cart_key' => 'p_93901']);
    assert_equals(['p_93901', 'p_93902'], array_keys(SavedForLater::lines()), 'Save for later: the guest has two saved lines before signing in');
    [$sflLoggedIn] = Customers::login('sfl-shopper@example.test', 'Sfl-pass-1234');
    $sflRows = $sflDb->query("SELECT store_id, item_key, qty FROM saved_for_later_items WHERE customer_id = {$sflCustomer} ORDER BY store_id, item_key")->fetchAll(PDO::FETCH_NUM);
    assert_true($sflLoggedIn, 'Save for later: the shopper signs in');
    assert_equals([['cz', 'p_93901', 1], ['default', 'p_93901', 2], ['default', 'p_93902', 4]], array_map(static fn(array $r): array => [(string)$r[0], (string)$r[1], (int)$r[2]], $sflRows),
        'Save for later: on login the guest lines join the account once (larger quantity kept), the other store\'s list untouched');
    assert_true(empty($_SESSION['saved_for_later']['default']), 'Save for later: the guest copy is gone from the session after the merge');
    assert_equals(['p_93901', 'p_93902'], array_keys(SavedForLater::lines()), 'Save for later: the signed-in shopper sees only this store\'s list');

    // The account list works the same way: to cart, then clear removes only this store's lines.
    assert_true($sflAct(['cart_action' => 'saved_to_cart', 'saved_key' => 'p_93902'])['ok'] && !isset(SavedForLater::lines()['p_93902']), 'Save for later: an account line moves to the cart');
    $sflAct(['cart_action' => 'save_for_later', 'cart_key' => 'p_93902']);
    assert_equals(1, (int)$sflDb->query("SELECT COUNT(*) FROM saved_for_later_items WHERE customer_id = {$sflCustomer} AND item_key = 'p_93902'")->fetchColumn(),
        'Save for later: saving an account line again keeps one row');

    // 6. GDPR: export lists the account's lines, erasure deletes them in every store.
    $sflExport = GdprService::exportCustomer($sflCustomer);
    assert_equals(3, count($sflExport['saved_for_later'] ?? []), 'Save for later: the GDPR export contains the account\'s saved lines');
    $sflAct(['cart_action' => 'saved_clear']);
    assert_equals([['cz']], $sflDb->query("SELECT store_id FROM saved_for_later_items WHERE customer_id = {$sflCustomer}")->fetchAll(PDO::FETCH_NUM),
        'Save for later: "clear saved" empties this store\'s list only');
    GdprService::anonymizeCustomer($sflCustomer, 'self');
    assert_equals(0, (int)$sflDb->query("SELECT COUNT(*) FROM saved_for_later_items WHERE customer_id = {$sflCustomer}")->fetchColumn(),
        'Save for later: GDPR erasure deletes the account\'s saved lines');

    // 7. Logout leaves nothing for the next person at the computer.
    $_SESSION['saved_for_later']['default']['p_93901'] = ['product_id' => 93901, 'variant_id' => null, 'qty' => 1];
    Customers::logout();
    assert_true(!isset($_SESSION['saved_for_later']), 'Save for later: logout clears the session list');
} finally {
    $_SESSION = $sflSession;
    foreach ($sflGlobals as $sflKey => $sflValue) $GLOBALS[$sflKey] = $sflValue;
    $sflDb->exec('DELETE FROM saved_for_later_items');
    $sflDb->exec("DELETE FROM warehouse_stock WHERE sku IN ('SFL-A', 'SFL-B')");
    $sflDb->exec('DELETE FROM products WHERE id IN (93901, 93902)');
}
