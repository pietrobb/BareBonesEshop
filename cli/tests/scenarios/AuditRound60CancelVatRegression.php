<?php

// Review round 25 (R60), medium 4: after any earlier partial refund split over the invoice (a 5 € goodwill refund), the
// refund of the cancelled unshipped rest found its goods' rate short of their price and was split over the whole invoice:
// the cancelled 5 % books were credited largely at 23 % (regression of R54/R55).
echo "\n--- R60 cancel VAT: the cancelled rest's refund credits its goods' rate in full after an earlier partial refund ---\n";
$pdo ??= test_commerce_fixture();
Bootstrap::initStoreContext('/', '', 'default');
try {
    $r60vCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $r60vWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, vat_class) VALUES
        (96061, 'default', {$r60vCategory}, 'R60V-STD', 'r60v-std', 'simple', 'R60V standard', 2000, 'eur', 5, 'active', 'deny', 'standard'),
        (96062, 'default', {$r60vCategory}, 'R60V-BOOK', 'r60v-book', 'simple', 'R60V book unshipped', 1000, 'eur', 5, 'active', 'deny', 'books')");
    foreach (['R60V-STD', 'R60V-BOOK'] as $r60vSku) Warehouse::setStock($r60vWarehouse, $r60vSku, 5, 'count', 'test', 'r60v-opening-' . $r60vSku);
    $r60vLine = static fn(int $productId, string $sku, int $price): array => ['product_id' => $productId, 'variant_id' => null, 'sku' => $sku,
        'name' => $sku, 'qty' => 1, 'price' => $price, 'attrs' => []];
    $r60vOrder = Orders::create([$r60vLine(96061, 'R60V-STD', 2000), $r60vLine(96062, 'R60V-BOOK', 1000)],
        ['name' => 'R60V Buyer', 'email' => 'r60v@example.test', 'phone' => '', 'address' => ['country' => 'SK'], 'shipping_cost' => 0, 'tax_amount' => 0,
            'coupon_discount' => 0, 'currency' => 'eur'], 'bank_transfer');
    $r60vSnapshot = VatService::buildSnapshot([
        ['product_id' => 96061, 'sku' => 'R60V-STD', 'qty' => 1, 'price' => 2000, 'product_type' => 'simple', 'vat_class' => 'standard'],
        ['product_id' => 96062, 'sku' => 'R60V-BOOK', 'qty' => 1, 'price' => 1000, 'product_type' => 'simple', 'vat_class' => 'books'],
    ], ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur']);
    $pdo->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid', vat_scenario = 'domestic', shipping_addr = '{\"country\":\"SK\"}', financial_snapshot = ? WHERE id = ?")
        ->execute([json_encode($r60vSnapshot), $r60vOrder]);
    $r60vInvoice = InvoiceService::issue($r60vOrder);
    $r60vBookKey = $r60vStdKey = '';
    foreach (json_decode((string)$r60vInvoice['vat_breakdown'], true) as $r60vKey => $r60vRate) {
        if ((int)($r60vRate['net'] ?? 0) + (int)($r60vRate['vat'] ?? 0) === 1000) $r60vBookKey = (string)$r60vKey;
        else $r60vStdKey = (string)$r60vKey;
    }
    assert_true($r60vBookKey !== '' && $r60vStdKey !== '', 'R60 cancel VAT: the invoice has the book\'s rate (10.00) and the standard rate (20.00)');
    $r60vRates = static fn(?array $credit): array => array_map(static fn(array $rate): int => -(int)($rate['net'] ?? 0) - (int)($rate['vat'] ?? 0),
        (array)json_decode((string)($credit['vat_breakdown'] ?? '[]'), true));
    // A 5 € goodwill refund while the order is paid: split over the invoice (1.67 of it at the book's rate).
    $r60vGoodwill = $r60vRates(InvoiceService::creditRefund($r60vOrder, 500, 'r60v-goodwill'));
    assert_equals(167, (int)($r60vGoodwill[$r60vBookKey] ?? 0), 'R60 cancel VAT: the goodwill refund takes 1.67 of the book\'s rate');
    // The standard item ships; the book never does and the rest is cancelled; its 10 € go back.
    $r60vParcels = Fulfillment::planOrder($r60vOrder);
    $r60vParcel = (int)$pdo->query("SELECT shipment_id FROM shipment_items WHERE sku = 'R60V-STD' AND shipment_id IN (" . implode(',', array_map('intval', $r60vParcels) ?: [0]) . ')')->fetchColumn();
    $pdo->exec("DELETE FROM shipment_items WHERE sku = 'R60V-BOOK' AND shipment_id = {$r60vParcel}");
    Fulfillment::ship($r60vParcel);
    OrderWorkflow::transition($r60vOrder, 'cancelled', 'R60 cancelled rest');
    assert_equals(1000, (int)Fulfillment::unshippedGross($r60vOrder), 'R60 cancel VAT: the book never shipped (10.00)');
    $r60vCancel = $r60vRates(InvoiceService::creditRefund($r60vOrder, 1000, 'order_refund_' . $r60vOrder));
    ksort($r60vCancel);
    $r60vExpected = [$r60vBookKey => 833, $r60vStdKey => 167];
    ksort($r60vExpected);
    assert_equals($r60vExpected, $r60vCancel,
        'R60 cancel VAT: the cancelled book\'s refund credits all its rate has left (8.33), only the rest at the standard rate (was: 3.33 / 6.67)');
    $r60vCredited = [];
    foreach ($pdo->query('SELECT vat_breakdown FROM invoices WHERE refers_to_invoice_id = ' . (int)$r60vInvoice['id'] . " AND type = 'credit_note'")->fetchAll(PDO::FETCH_COLUMN) as $r60vJson) {
        foreach ($r60vRates(['vat_breakdown' => $r60vJson]) as $r60vKey => $r60vGross) $r60vCredited[$r60vKey] = ($r60vCredited[$r60vKey] ?? 0) + $r60vGross;
    }
    assert_equals([1000, 500], [(int)($r60vCredited[$r60vBookKey] ?? 0), (int)($r60vCredited[$r60vStdKey] ?? 0)],
        'R60 cancel VAT: the cancelled book is credited in full at its rate, the goodwill refund stays on the goods kept');
    // A later refund of the order goes to what the invoice has left (the standard rate), not to the book again.
    assert_equals([$r60vStdKey => 500], $r60vRates(InvoiceService::creditRefund($r60vOrder, 500, 'r60v-later')),
        'R60 cancel VAT: a later refund credits the standard rate only');
    // R60 low: the customer's account listed one document per order (the oldest in the list, the newest on the order).
    [$r60vRegistered, $r60vCustomer] = Customers::register('r60v-account@example.test', 'R60v-account-pass-1', 'R60V Buyer');
    assert_true($r60vRegistered && $r60vCustomer > 0, 'R60 account documents: a customer account');
    $pdo->prepare('UPDATE orders SET customer_id = ? WHERE id = ?')->execute([$r60vCustomer, $r60vOrder]);
    $r60vNumbers = $pdo->query('SELECT number FROM invoices WHERE order_id = ' . (int)$r60vOrder . " AND status != 'cancelled' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $r60vCustomerRow = ['id' => (int)$r60vCustomer, 'email' => 'r60v-account@example.test', 'name' => 'R60V Buyer', 'phone' => '', 'locale' => 'en'];
    $r60vList = AccountViewModel::build('orders', $r60vCustomerRow, ['en'], [], null, null)['account']['orders'] ?? [];
    $r60vListed = array_values(array_filter($r60vList, static fn(array $row): bool => $row['url'] === Template::storeUrl('/account.php?p=order&id=' . $r60vOrder)));
    $r60vDetail = AccountViewModel::build('order', $r60vCustomerRow, ['en'], [], null, null, Orders::byId($r60vOrder))['account']['order'] ?? [];
    assert_equals([4, $r60vNumbers, $r60vNumbers], [count($r60vNumbers), array_column($r60vListed[0]['invoices'] ?? [], 'number'), array_column($r60vDetail['invoices'] ?? [], 'number')],
        'R60 account documents: the order list and the order page link the invoice and its three credit notes (was: one of them)');
    $r60vPages = Template::page('account', AccountViewModel::build('orders', $r60vCustomerRow, ['en'], [], null, null))
        . Template::page('account', AccountViewModel::build('order', $r60vCustomerRow, ['en'], [], null, null, Orders::byId($r60vOrder)));
    assert_equals(array_fill_keys($r60vNumbers, 2), array_map(static fn(string $number): int => substr_count($r60vPages, '>' . $number . '<'), array_combine($r60vNumbers, $r60vNumbers)),
        'R60 account documents: both pages show every document number as a link');
    // R61 (review round 26, low 4): a shop's copy of account-page.html made before 2.1.3 reads `invoice`.
    assert_equals([$r60vNumbers[0], $r60vNumbers[0]], [$r60vListed[0]['invoice']['number'] ?? null, $r60vDetail['invoice']['number'] ?? null],
        'R61 account documents: `invoice` still holds the order\'s invoice for templates copied before 2.1.3 (was: missing, "—")');
} catch (Throwable $e) {
    assert_true(false, 'R60 cancel VAT (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
