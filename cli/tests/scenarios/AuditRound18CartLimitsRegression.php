<?php

// Review round 18 (2026-11-06), medium "cart_restore": one anonymous request with 20 000 lines took ~40 s and ended in
// HTTP 500 (every line validated against a freshly priced cart). Low: name and address lengths of the JSON checkout were
// unbounded and reached VARCHAR(191) columns (MySQL refused the order as an incident).
echo "\n--- R18: cart restore is bounded and linear, checkout fields have a maximum length ---\n";
$r18cCatalog = StoreContext::current()->catalogId();
$r18cDb = Db::get();
$r18cSession = $_SESSION ?? [];
// Own id range: 988xx is taken by earlier scenarios (R17 dev docs keeps product 98871 with an order).
try {
    $r18cInsert = $r18cDb->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (?, ?, 1, ?, ?, ?, 'simple', 500, 'eur', 'active', 50, 'deny')");
    for ($r18cI = 1; $r18cI <= 150; $r18cI++) {
        $r18cInsert->execute([981000 + $r18cI, $r18cCatalog, "R18C {$r18cI}", "r18c-{$r18cI}", "R18C-{$r18cI}"]);
    }
    $r18cItems = [];
    for ($r18cI = 1; $r18cI <= 150; $r18cI++) $r18cItems[] = ['product_id' => 981000 + $r18cI, 'qty' => 1];
    for ($r18cI = 0; $r18cI < 3000; $r18cI++) $r18cItems[] = ['product_id' => 981001, 'qty' => 99];

    $_SESSION = ['cart' => []];
    $r18cStart = microtime(true);
    Cart::restore($r18cItems);
    $r18cSeconds = microtime(true) - $r18cStart;
    assert_true(count($_SESSION['cart']) === Cart::MAX_LINES && $r18cSeconds < 5,
        sprintf('R18 cart_restore: 3 150 entries give at most Cart::MAX_LINES lines, quickly (%d lines, %.1f s)', count($_SESSION['cart']), $r18cSeconds));
    assert_equals(1, (int)($_SESSION['cart']['p_981001']['qty'] ?? 0),
        'R18 cart_restore: an entry repeated in the same restore counts once (it did not add 99 × 3 000)');

    $_SESSION = ['cart' => ['p_981001' => ['product_id' => 981001, 'variant_id' => null, 'qty' => 2]]];
    Cart::restore([['product_id' => 981001, 'qty' => 3], ['product_id' => 981002, 'qty' => 1], ['product_id' => 99999999, 'qty' => 1], 'junk']);
    assert_equals([5, 1, false], [(int)($_SESSION['cart']['p_981001']['qty'] ?? 0), (int)($_SESSION['cart']['p_981002']['qty'] ?? 0), isset($_SESSION['cart']['p_99999999'])],
        'R18 cart_restore: an existing line still adds up, an unknown product or a malformed entry is skipped');

    $r18cApi = (string)file_get_contents(TEST_CLI_DIR . '/../src/Http/ApiHandlers.php');
    assert_true(str_contains($r18cApi, "file_get_contents('php://input', false, null, 0, 65537)") && str_contains($r18cApi, 'http_response_code(413)'),
        'R18 cart_restore: a request body over 64 KiB is refused with 413 before it is decoded');

    // Checkout: a field over its maximum is refused with a message; one at the maximum passes this check.
    $_SESSION = ['cart' => []];
    I18n::setLocale('en');
    $r18cLong = CheckoutService::execute(['name' => str_repeat('Á', 192), 'email' => 'r18c@example.test', 'country' => 'SK']);
    $r18cStreet = CheckoutService::execute(['name' => 'R18', 'email' => 'r18c@example.test', 'country' => 'SK', 'street' => str_repeat('x', 201)]);
    // An invalid country stops the checkout right after the length check: no order is placed (the session may hold a cart).
    $r18cMax = CheckoutService::execute(['name' => str_repeat('Á', 191), 'email' => 'r18c@example.test', 'country' => 'X1', 'street' => str_repeat('x', 200)]);
    assert_equals(['checkout.error.field_too_long', 'name', 191, I18n::t('checkout.error.field_too_long'), 'checkout.error.field_too_long', 'street'],
        [$r18cLong['error_code'] ?? '', $r18cLong['field'] ?? '', $r18cLong['max_length'] ?? 0, $r18cLong['error'] ?? '', $r18cStreet['error_code'] ?? '', $r18cStreet['field'] ?? ''],
        'R18 checkout: a name or street longer than its maximum is refused with field_too_long, naming the field');
    assert_true(($r18cMax['error_code'] ?? '') === 'checkout.error.invalid_country' && I18n::t('checkout.error.field_too_long') !== 'checkout.error.field_too_long',
        'R18 checkout: a name of 191 characters and a street of 200 pass the length check (' . ($r18cMax['error_code'] ?? '') . ')');
    I18n::setLocale('sk');
} finally {
    $_SESSION = $r18cSession;
    $r18cDb->exec('DELETE FROM products WHERE id > 981000 AND id <= 981150');
}
