<?php

// Review round 19 (2026-11-06), low "cart + payments":
// - cart_add took any number of lines (only cart_restore stopped at Cart::MAX_LINES);
// - cart_restore recorded a new line as null and isset() missed it, so a duplicate entry added its quantity again (2 × 30 → 60);
// - the admin "Paid" with the amount 0 paid the whole order (0 meant "the order total"), an overpayment passed without a word.
echo "\n--- R19: the cart holds at most MAX_LINES lines, a restore counts a duplicate once, Paid with 0 is refused ---\n";
$r19cCatalog = StoreContext::current()->catalogId();
$r19cDb = Db::get();
$r19cSession = $_SESSION ?? [];
// Own id range: 9810xx is R18 cart limits, 988xx earlier scenarios.
try {
    $r19cInsert = $r19cDb->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (?, ?, 1, ?, ?, ?, 'simple', 500, 'eur', 'active', 50, 'deny')");
    for ($r19cI = 1; $r19cI <= 101; $r19cI++) {
        $r19cInsert->execute([982000 + $r19cI, $r19cCatalog, "R19C {$r19cI}", "r19c-{$r19cI}", "R19C-{$r19cI}"]);
    }

    $_SESSION = ['cart' => []];
    Cart::restore([['product_id' => 982001, 'qty' => 30], ['product_id' => 982001, 'qty' => 30]]);
    assert_equals(30, (int)($_SESSION['cart']['p_982001']['qty'] ?? 0),
        'R19 cart_restore: a new line repeated in the same restore counts once (was: 2 × 30 → 60)');

    $_SESSION = ['cart' => []];
    for ($r19cI = 1; $r19cI <= Cart::MAX_LINES; $r19cI++) {
        $_SESSION['cart']['p_' . (982000 + $r19cI)] = ['product_id' => 982000 + $r19cI, 'variant_id' => null, 'qty' => 1];
    }
    $r19cFull = Cart::addValidated(982101, null, 1);
    $r19cMore = Cart::addValidated(982001, null, 1);
    assert_equals([false, 'cart_full', false, true, 2], [$r19cFull['ok'] ?? null, $r19cFull['error'] ?? '',
        isset($_SESSION['cart']['p_982101']), $r19cMore['ok'] ?? null, (int)($_SESSION['cart']['p_982001']['qty'] ?? 0)],
        'R19 cart_add: a full cart refuses a new line with cart_full; a line already in it still takes more units');
    assert_true(I18n::t('cart_full') !== 'cart_full'
        && str_contains((string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Storefront/Layout.php'), "'cart_full' => I18n::t('cart_full')"),
        'R19 cart_add: the storefront says why the item was not added');
} finally {
    $_SESSION = $r19cSession;
    $r19cDb->exec('DELETE FROM products WHERE id > 982000 AND id <= 982101');
}

$r19cOrder = ['id' => 0, 'status' => 'pending', 'total' => 10000, 'currency' => 'eur'];
assert_equals([true, true, false, false, false], [
    PaymentService::adminPaymentZero('paid', '0', 0),
    PaymentService::adminPaymentZero('paid', ' 0,00 ', 0),
    PaymentService::adminPaymentZero('paid', '', 0),
    PaymentService::adminPaymentZero('paid', '100', 10000),
    PaymentService::adminPaymentZero('failed', '0', 0),
], 'R19 paid: 0 typed as the paid amount is refused (was: paid the whole order); an empty field still means the order total');
assert_equals([2000, 0, 0, 0], [
    PaymentService::adminPaymentExcess($r19cOrder, 'paid', 12000),
    PaymentService::adminPaymentExcess($r19cOrder, 'paid', 10000),
    PaymentService::adminPaymentExcess(['status' => 'cancelled'] + $r19cOrder, 'paid', 12000),
    PaymentService::adminPaymentExcess($r19cOrder, 'failed', 12000),
], 'R19 paid: 120 € recorded on a 100 € order is 20 € over; the exact amount, a cancelled order and other states are not');
$r19cAdmin = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Admin/Orders.php');
assert_true(str_contains($r19cAdmin, 'PaymentService::adminPaymentZero(') && str_contains($r19cAdmin, 'PaymentService::adminPaymentExcess(')
    && I18n::t('admin.order.msg.paid_zero') !== 'admin.order.msg.paid_zero' && I18n::t('admin.order.msg.paid_over') !== 'admin.order.msg.paid_over',
    'R19 paid: the order detail refuses 0 and warns about an overpayment, in words');
