<?php

// Review round 25 (R60), low: money refunded outside the returns (a 5 € discount for a late delivery) always came off a
// later return: "Refunded" on the return refused anything else, so the customer who returned the 10 € book got 5 €. The
// admin may now refund the goods in full, the discount staying with the goods kept ("keep"): what that discount credited
// at the book's rate moves to the rate of the goods kept.
echo "\n--- R60 keep outside: a return refunded in full after a discount for a late delivery ---\n";
$pdo ??= test_commerce_fixture();
Bootstrap::initStoreContext('/', '', 'default');
try {
    $r60kCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $r60kWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $r60kProducts = ['R60K-STD' => [96071, 'standard'], 'R60K-BOOK' => [96072, 'books']];
    foreach ($r60kProducts as $r60kSku => [$r60kPid, $r60kClass]) {
        $pdo->prepare("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, vat_class)
            VALUES (?, 'default', ?, ?, ?, 'simple', ?, 1000, 'eur', 900, 'active', 'deny', ?)")->execute([$r60kPid, $r60kCategory, $r60kSku, strtolower($r60kSku), $r60kSku, $r60kClass]);
        Warehouse::setStock($r60kWarehouse, $r60kSku, 900, 'count', 'test', 'r60k-opening-' . $r60kSku);
    }
    $r60kOrder = static function (string $tag) use ($pdo, $r60kProducts): array {
        $order = $snap = [];
        foreach (['A' => ['R60K-STD', 2000], 'B' => ['R60K-BOOK', 1000]] as $name => [$sku, $price]) {
            $order[] = ['product_id' => $r60kProducts[$sku][0], 'variant_id' => null, 'sku' => $sku, 'name' => $name, 'qty' => 1, 'price' => $price, 'attrs' => []];
            $snap[] = ['product_id' => $r60kProducts[$sku][0], 'sku' => $sku, 'qty' => 1, 'price' => $price, 'product_type' => 'simple', 'vat_class' => $r60kProducts[$sku][1]];
        }
        $orderId = Orders::create($order, ['name' => 'R60K Buyer', 'email' => $tag . '@example.test', 'phone' => '', 'address' => ['country' => 'SK'],
            'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur'], 'bank_transfer');
        $pdo->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid', payment_id = '', vat_scenario = 'domestic', shipping_addr = '{\"country\":\"SK\"}', financial_snapshot = ? WHERE id = ?")
            ->execute([json_encode(VatService::buildSnapshot($snap, ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur'])), $orderId]);
        $invoice = InvoiceService::issue($orderId);
        $pdo->exec("UPDATE orders SET status = 'delivered' WHERE id = " . (int)$orderId);
        $items = [];
        foreach (Orders::items($orderId) as $item) $items[(string)$item['name']] = (int)$item['id'];
        // The discount for the late delivery: 5 € refunded from the order page, split over the invoice.
        $pdo->prepare("INSERT INTO payment_transactions (order_id, provider, status, amount, currency, idempotency_key, store_id) VALUES (?, 'manual', 'partially_refunded', 500, 'eur', ?, 'default')")
            ->execute([$orderId, $tag . '-late']);
        InvoiceService::creditRefund($orderId, 500, $tag . '-late');
        return [$orderId, (int)$invoice['id'], $items];
    };
    // "Refunded" on the return as the admin page does it with $choice 'deduct' or 'keep': [what it pays, its rates].
    $r60kReturn = static function (int $orderId, int $sourceId, array $items, string $name, string $choice, string $key): array {
        [, $rmaId] = Rma::create($orderId, null, 'R60K return', [$items[$name] => 1]);
        Rma::setStatus((int)$rmaId, 'approved');
        $decisions = [];
        foreach (Rma::items((int)$rmaId) as $rmaItem) $decisions[(int)$rmaItem['id']] = ['quantity' => 1, 'disposition' => 'restock'];
        Rma::receive((int)$rmaId, $decisions, $key . '-receipt');
        $outside = Rma::refundedOutsideReturns($orderId);
        $keep = $outside > 0 && $choice === 'keep';
        $credit = InvoiceService::credit($sourceId, (int)$rmaId, InvoiceService::creditableQuantities((int)$rmaId), $key . '-credit', $outside > 0 && !$keep, $keep);
        $rates = array_map('intval', array_keys(json_decode((string)$credit['vat_breakdown'], true) ?: []));
        sort($rates);
        return [-(int)$credit['total_gross'], $rates];
    };
    $r60kUncredited = static function (int $sourceId) use ($pdo): array {
        $left = [];
        foreach ($pdo->query("SELECT vat_breakdown FROM invoices WHERE (id = {$sourceId} OR refers_to_invoice_id = {$sourceId}) AND status <> 'cancelled'")->fetchAll(PDO::FETCH_COLUMN) as $breakdown) {
            foreach (json_decode((string)$breakdown, true) ?: [] as $rate => $info) if (is_array($info)) $left[(string)(int)$rate] = ($left[(string)(int)$rate] ?? 0) + (int)$info['net'] + (int)$info['vat'];
        }
        krsort($left, SORT_NUMERIC);
        return $left;
    };

    // As before: the discount comes off the return of the book.
    [$r60kId, $r60kSource, $r60kItems] = $r60kOrder('r60k-deduct');
    assert_equals(500, $r60kReturn($r60kId, $r60kSource, $r60kItems, 'B', 'deduct', 'r60k-deduct-b')[0],
        'R60 keep outside: "count the refunded money" still pays the book less the discount (5 €)');

    // Kept: the book is refunded in full; the discount's 1.67 € at 5 % moves to 23 %, where the goods kept are.
    [$r60kId, $r60kSource, $r60kItems] = $r60kOrder('r60k-keep');
    [$r60kPaidB, $r60kRatesB] = $r60kReturn($r60kId, $r60kSource, $r60kItems, 'B', 'keep', 'r60k-keep-b');
    assert_equals([1000, [5, 23], ['23' => 1500, '5' => 0]], [$r60kPaidB, $r60kRatesB, $r60kUncredited($r60kSource)],
        'R60 keep outside: "refunded in full" pays the book its 10 €, 8.33 € at 5 % and 1.67 € at 23 % (was: 5 € at most)');
    // Then A comes back too: what is left of it, so the order is refunded what was paid and no more.
    [$r60kPaidA, $r60kRatesA] = $r60kReturn($r60kId, $r60kSource, $r60kItems, 'A', 'keep', 'r60k-keep-a');
    assert_equals([1500, [23], ['23' => 0, '5' => 0], 3000], [$r60kPaidA, $r60kRatesA, $r60kUncredited($r60kSource), PaymentService::refundedAmount($r60kId) + 1000 + 1500],
        'R60 keep outside: A then pays the 15 € its rate has left; 30 € refunded in all, what was paid');
} catch (Throwable $e) {
    assert_true(false, 'R60 keep outside (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
