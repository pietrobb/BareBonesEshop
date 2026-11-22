<?php

// Review round 20 (R55), medium 1: R54 split the refund of a paid order whose unshipped rest was cancelled by the goods
// that never shipped, but did so for every later refund of the order too (a dashboard or admin refund): the unshipped
// book's rate was credited twice, and the shipped book's return then found nothing left at its rate.
echo "\n--- R55 credit VAT: only the refund of the cancelled rest credits the unshipped goods' rate ---\n";
$pdo ??= test_commerce_fixture();
Bootstrap::initStoreContext('/', '', 'default');
try {
    $r55vCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $r55vWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, vat_class) VALUES
        (95581, 'default', {$r55vCategory}, 'R55V-STD', 'r55v-std', 'simple', 'R55V standard', 1000, 'eur', 5, 'active', 'deny', 'standard'),
        (95582, 'default', {$r55vCategory}, 'R55V-BOOK1', 'r55v-book1', 'simple', 'R55V book shipped', 1000, 'eur', 5, 'active', 'deny', 'books'),
        (95583, 'default', {$r55vCategory}, 'R55V-BOOK2', 'r55v-book2', 'simple', 'R55V book unshipped', 1000, 'eur', 5, 'active', 'deny', 'books')");
    foreach (['R55V-STD', 'R55V-BOOK1', 'R55V-BOOK2'] as $r55vSku) Warehouse::setStock($r55vWarehouse, $r55vSku, 5, 'count', 'test', 'r55v-opening-' . $r55vSku);
    $r55vLine = static fn(int $productId, string $sku): array => ['product_id' => $productId, 'variant_id' => null, 'sku' => $sku,
        'name' => $sku, 'qty' => 1, 'price' => 1000, 'attrs' => []];
    $r55vOrder = Orders::create([$r55vLine(95581, 'R55V-STD'), $r55vLine(95582, 'R55V-BOOK1'), $r55vLine(95583, 'R55V-BOOK2')],
        ['name' => 'R55V Buyer', 'email' => 'r55v@example.test', 'phone' => '', 'address' => ['country' => 'SK'], 'shipping_cost' => 0, 'tax_amount' => 0,
            'coupon_discount' => 0, 'currency' => 'eur'], 'bank_transfer');
    $r55vSnapshot = VatService::buildSnapshot([
        ['product_id' => 95581, 'sku' => 'R55V-STD', 'qty' => 1, 'price' => 1000, 'product_type' => 'simple', 'vat_class' => 'standard'],
        ['product_id' => 95582, 'sku' => 'R55V-BOOK1', 'qty' => 1, 'price' => 1000, 'product_type' => 'simple', 'vat_class' => 'books'],
        ['product_id' => 95583, 'sku' => 'R55V-BOOK2', 'qty' => 1, 'price' => 1000, 'product_type' => 'simple', 'vat_class' => 'books'],
    ], ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur']);
    $pdo->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid', vat_scenario = 'domestic', shipping_addr = '{\"country\":\"SK\"}', financial_snapshot = ? WHERE id = ?")
        ->execute([json_encode($r55vSnapshot), $r55vOrder]);
    $r55vInvoice = InvoiceService::issue($r55vOrder);
    $r55vBookKey = $r55vStdKey = '';
    foreach (json_decode((string)$r55vInvoice['vat_breakdown'], true) as $r55vKey => $r55vRate) {
        if ((int)($r55vRate['net'] ?? 0) + (int)($r55vRate['vat'] ?? 0) === 2000) $r55vBookKey = (string)$r55vKey;
        else $r55vStdKey = (string)$r55vKey;
    }
    assert_true($r55vBookKey !== '' && $r55vStdKey !== '', 'R55 credit VAT: the invoice has the books\' rate (20.00) and the standard rate (10.00)');
    // The standard item and one book leave; the other book never ships and the rest is cancelled.
    $r55vParcels = Fulfillment::planOrder($r55vOrder);
    $r55vParcel = (int)$pdo->query("SELECT shipment_id FROM shipment_items WHERE sku = 'R55V-STD' AND shipment_id IN (" . implode(',', array_map('intval', $r55vParcels) ?: [0]) . ')')->fetchColumn();
    $pdo->exec("DELETE FROM shipment_items WHERE sku = 'R55V-BOOK2' AND shipment_id = {$r55vParcel}");
    $pdo->exec("UPDATE shipment_items SET shipment_id = {$r55vParcel} WHERE sku = 'R55V-BOOK1' AND shipment_id IN (" . implode(',', array_map('intval', $r55vParcels) ?: [0]) . ')');
    Fulfillment::ship($r55vParcel);
    OrderWorkflow::transition($r55vOrder, 'cancelled', 'R55 cancelled rest');
    assert_equals(1000, (int)Fulfillment::unshippedGross($r55vOrder), 'R55 credit VAT: one book never shipped (10.00)');
    $r55vRates = static fn(?array $credit): array => array_map(static fn(array $rate): int => -(int)($rate['net'] ?? 0) - (int)($rate['vat'] ?? 0),
        (array)json_decode((string)($credit['vat_breakdown'] ?? '[]'), true));
    $r55vCancel = InvoiceService::creditRefund($r55vOrder, 1000, 'order_refund_' . $r55vOrder);
    assert_equals([$r55vBookKey => 1000], $r55vRates($r55vCancel), 'R55 credit VAT: the refund of the cancelled rest credits the unshipped book\'s rate');
    $r55vDashboard = InvoiceService::creditRefund($r55vOrder, 1000, 'r55v-dashboard-refund');
    $r55vSplit = $r55vRates($r55vDashboard);
    ksort($r55vSplit);
    $r55vExpected = [$r55vBookKey => 500, $r55vStdKey => 500];
    ksort($r55vExpected);
    assert_equals($r55vExpected, $r55vSplit,
        'R55 credit VAT: a later dashboard refund is split over what the invoice has left (was: the unshipped book\'s rate credited again)');
    $r55vReturn = InvoiceService::creditRefund($r55vOrder, 1000, 'r55v-shipped-return');
    assert_equals(-1000, (int)($r55vReturn['total_gross'] ?? 0), 'R55 credit VAT: the rest of the invoice is credited by a further refund');
    $r55vLeft = (int)$pdo->query("SELECT COALESCE(SUM(total_gross), 0) FROM invoices WHERE refers_to_invoice_id = " . (int)$r55vInvoice['id'] . " AND type = 'credit_note'")->fetchColumn();
    assert_equals(-3000, $r55vLeft, 'R55 credit VAT: the three refunds credit the whole invoice, nothing left uncredited at either rate');
} catch (Throwable $e) {
    assert_true(false, 'R55 credit VAT (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
