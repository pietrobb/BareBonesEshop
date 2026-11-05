<?php

echo "\n--- R17 admin units: every admin money field is typed and shown in the currency (\"12.90\"), like the product price, not in cents ---\n";

$r17uCatalog = StoreContext::current()->catalogId();
$r17uCurrency = strtoupper(StoreContext::current()->currency());

// Money::adminInput: the one parser behind every admin money field.
assert_equals([1290, 1290, 0, null, null, -150, null, null, 1500],
    [Money::adminInput('12.90', 'EUR'), Money::adminInput('12,90', 'EUR'), Money::adminInput('', 'EUR'), Money::adminInput('', 'EUR', null),
     Money::adminInput('12.905', 'EUR'), Money::adminInput('-1.50', 'EUR', 0, true), Money::adminInput('-1.50', 'EUR'), Money::adminInput(['12'], 'EUR'),
     Money::adminInput('15', 'EUR')],
    'R17 units: "12.90" and "12,90" are 1290 cents, "15" is 15.00, a finer amount or a negative one (outside a surcharge) is refused');
assert_equals(['12.90', ''], [Money::adminValue(1290, 'EUR'), Money::adminValue(null, 'EUR')], 'R17 units: an admin field shows 1290 cents as "12.90"');

$pdo->exec('DELETE FROM variants WHERE product_id = 97801');
$pdo->exec('DELETE FROM products WHERE id = 97801');
$pdo->prepare("INSERT INTO products (id, store_id, category_id, sku, slug, name, price, stock, status, currency)
    SELECT 97801, ?, id, 'R17U-P', 'r17u-p', 'R17U product', 2000, 10, 'active', ? FROM categories WHERE store_id = ? ORDER BY id LIMIT 1")
    ->execute([$r17uCatalog, strtolower($r17uCurrency), $r17uCatalog]);
$pdo->prepare("INSERT INTO variants (product_id, sku, name, stock, price_absolute, price_delta) VALUES (97801, 'R17U-P-S', 'S', 5, NULL, 0)")->execute();
$r17uVariant = (int)$pdo->lastInsertId();

// Variants: the price and the surcharge in the product's currency.
$r17uRow = static fn(string $absolute, string $delta): array => [(string)$r17uVariant => ['sku' => 'R17U-P-S', 'name' => 'S', 'price_absolute' => $absolute, 'price_delta' => $delta, 'stock' => '5', 'sort_order' => '0']];
$r17uValid = ProductVariants::validate(97801, $r17uRow('12.90', '-1.50'));
assert_equals([1290, -150], is_array($r17uValid) ? [$r17uValid['rows'][0]['price_absolute'] ?? null, $r17uValid['rows'][0]['price_delta'] ?? null] : $r17uValid,
    'R17 units: a variant price "12.90" and a surcharge "-1.50" are stored as 1290 and -150 cents (were read as 12 and -1 cents)');
assert_true(is_string(ProductVariants::validate(97801, $r17uRow('12.905', '0'))), 'R17 units: a variant price finer than the currency is refused');
assert_true(is_string(ProductVariants::validate(97801, $r17uRow('', '-25'))), 'R17 units: a surcharge of -25.00 below the 20.00 base price is refused');

// Price lists: lines in the list's currency, shown back the same way.
$pdo->prepare("INSERT INTO price_lists (store_id, code, name, currency, customer_group_id, priority, active) VALUES (?, 'r17u-list', 'R17U list', ?, NULL, 0, 1)")
    ->execute([StoreContext::current()->providerId(), strtolower($r17uCurrency)]);
$r17uList = (int)$pdo->lastInsertId();
$r17uSaved = PricingAdmin::savePrices($r17uList, ['prices' => "R17U-P | 1 | 18.50 | 19.90"]);
$r17uPrice = $pdo->query("SELECT price, compare_price FROM price_list_prices WHERE price_list_id = {$r17uList}")->fetch(PDO::FETCH_NUM);
assert_equals([true, [1850, 1990], 'R17U-P | 1 | 18.50 | 19.90'], [!empty($r17uSaved['ok']), array_map('intval', $r17uPrice ?: []), PricingAdmin::priceLines($r17uList)],
    'R17 units: a price-list line "18.50 | 19.90" is stored as cents and shown back as typed');
assert_true(empty(PricingAdmin::savePrices($r17uList, ['prices' => "R17U-P | 1 | 18.505"])['ok']), 'R17 units: a price-list price finer than the currency is refused');

// Promotions: a fixed discount and a minimum cart subtotal.
$r17uPromo = PricingAdmin::savePromotion(0, ['name' => 'R17U fixed', 'code' => 'R17UFIXED', 'scope' => 'cart', 'stacking' => 'stack', 'active' => '1',
    'action_type' => 'discount_fixed', 'action_value' => '5.00', 'cond_min_subtotal' => '49.90']);
$r17uPromoId = (int)($r17uPromo['id'] ?? 0);
$r17uPromoRow = $pdo->query("SELECT conditions, actions FROM promotions WHERE id = {$r17uPromoId}")->fetch(PDO::FETCH_ASSOC) ?: [];
assert_equals([true, 500, 4990], [!empty($r17uPromo['ok']), json_decode((string)($r17uPromoRow['actions'] ?? '{}'), true)['amount'] ?? null,
    json_decode((string)($r17uPromoRow['conditions'] ?? '{}'), true)['min_subtotal'] ?? null],
    'R17 units: a fixed discount "5.00" and a minimum subtotal "49.90" are 500 and 4990 cents (' . json_encode($r17uPromo) . ')');
$r17uForm = PricingAdmin::promotionForm($pdo->query("SELECT * FROM promotions WHERE id = {$r17uPromoId}")->fetch(PDO::FETCH_ASSOC) ?: null);
assert_equals(['5.00', '49.90'], [$r17uForm['action_value'], $r17uForm['cond_min_subtotal']], 'R17 units: the promotion form shows the amounts in the currency');
$pdo->exec("DELETE FROM promotions WHERE id = {$r17uPromoId}");

// B2B: the credit limit.
$r17uCompany = B2bAdmin::saveCompany(0, ['code' => 'r17u-co', 'legal_name' => 'R17U s.r.o.', 'registration_number' => '', 'vat_id' => '', 'approval_status' => 'approved',
    'credit_limit' => '1500.50', 'payment_terms_days' => '14', 'invoice_purchase' => '1', 'active' => '1']);
assert_equals([true, 150050], [!empty($r17uCompany['ok']), (int)(B2bAdmin::company((int)($r17uCompany['id'] ?? 0))['credit_limit'] ?? -1)],
    'R17 units: a credit limit "1500.50" is 150050 cents (was 1500 cents = 15.00) (' . json_encode($r17uCompany) . ')');
$pdo->exec("DELETE FROM companies WHERE code = 'r17u-co'");

// Suppliers: the purchase price of an offer.
$r17uSupplier = SupplierAdmin::saveSupplier(0, ['code' => 'r17u-sup', 'name' => 'R17U supplier', 'email' => '', 'phone' => '', 'note' => '', 'active' => '1']);
$r17uSupplierId = (int)($r17uSupplier['id'] ?? 0);
$r17uOffer = SupplierAdmin::saveOffers($r17uSupplierId, ['products' => 'R17U-P | X1 | 4.20 | 1 | 1 | 3']);
assert_equals([true, 'R17U-P | X1 | 4.20 | 1 | 1 | 3'], [!empty($r17uOffer['ok']), SupplierAdmin::offerLines($r17uSupplierId)],
    'R17 units: a purchase price "4.20" is stored as 420 cents and shown back as typed (' . json_encode($r17uOffer) . ')');
$pdo->exec("DELETE FROM supplier_products WHERE supplier_id = {$r17uSupplierId}");
$pdo->exec("DELETE FROM suppliers WHERE id = {$r17uSupplierId}");

// Shipping and payment methods: every amount goes through postedMoney (the currency), the screens show Money::adminValue.
$r17uOps = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Operations.php');
foreach (["postedMoney('free_above'", "postedMoney('price'", "postedMoney('cod_surcharge'", "postedMoney('min_amount'", "postedMoney('max_amount'", "postedMoney('fixed_fee'"] as $r17uCall) {
    assert_true(str_contains($r17uOps, $r17uCall), "R17 units: shipping/payment {$r17uCall}) reads the amount in the currency");
}
assert_true(!str_contains($r17uOps, 'paymentNullableInt('), 'R17 units: no shipping/payment amount is read as raw cents any more');

// A new product is priced in the store's currency, not the column default 'eur'.
$r17uCatalogSrc = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Catalog.php');
assert_true(str_contains($r17uCatalogSrc, "':currency' => strtolower(\$currency)"), 'R17 units: the product create stores the currency the price was typed in');

$pdo->exec("DELETE FROM price_list_prices WHERE price_list_id = {$r17uList}");
$pdo->exec("DELETE FROM price_lists WHERE id = {$r17uList}");
$pdo->exec('DELETE FROM variants WHERE product_id = 97801');
$pdo->exec('DELETE FROM products WHERE id = 97801');
