<?php

// Review round 22 (R57), high V1: R56 took money refunded outside the returns off a return only at the VAT rates of its
// goods (the share the refund's credit note credited there). The share credited at the other rates was paid out again:
// A 20 € at 23 % + B 10 € at 5 %, B returned, 10 € refunded in the provider's dashboard, "Refunded": 6.67 € more
// (16.67 € for a 10 € book); 20 € refunded and A returned: 26.67 € for 20 € of goods. Now all of that money comes off
// the return; a later return at the other rate gets what the caps took at its rate credited at a rate with room left.
echo "\n--- R57 double payout: money refunded outside the returns comes off a return whole ---\n";
$pdo ??= test_commerce_fixture();
Bootstrap::initStoreContext('/', '', 'default');
try {
    $r57vCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $r57vWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, vat_class) VALUES
        (95771, 'default', {$r57vCategory}, 'R57V-STD', 'r57v-std', 'simple', 'R57V standard', 2000, 'eur', 500, 'active', 'deny', 'standard'),
        (95772, 'default', {$r57vCategory}, 'R57V-BOOK', 'r57v-book', 'simple', 'R57V book', 1000, 'eur', 500, 'active', 'deny', 'books')");
    foreach (['R57V-STD', 'R57V-BOOK'] as $r57vSku) Warehouse::setStock($r57vWarehouse, $r57vSku, 500, 'count', 'test', 'r57v-opening-' . $r57vSku);

    // A paid, delivered and invoiced order: A 20 € at 23 % and B 10 € at 5 %.
    $r57vOrder = static function (string $tag) use ($pdo): array {
        $line = static fn(int $productId, string $sku, int $price): array => ['product_id' => $productId, 'variant_id' => null, 'sku' => $sku,
            'name' => $sku, 'qty' => 1, 'price' => $price, 'attrs' => []];
        $orderId = Orders::create([$line(95771, 'R57V-STD', 2000), $line(95772, 'R57V-BOOK', 1000)],
            ['name' => 'R57V Buyer', 'email' => $tag . '@example.test', 'phone' => '', 'address' => ['country' => 'SK'], 'shipping_cost' => 0, 'tax_amount' => 0,
                'coupon_discount' => 0, 'currency' => 'eur'], 'bank_transfer');
        $snapshot = VatService::buildSnapshot([
            ['product_id' => 95771, 'sku' => 'R57V-STD', 'qty' => 1, 'price' => 2000, 'product_type' => 'simple', 'vat_class' => 'standard'],
            ['product_id' => 95772, 'sku' => 'R57V-BOOK', 'qty' => 1, 'price' => 1000, 'product_type' => 'simple', 'vat_class' => 'books'],
        ], ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur']);
        $pdo->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid', payment_id = '', vat_scenario = 'domestic', shipping_addr = '{\"country\":\"SK\"}', financial_snapshot = ? WHERE id = ?")
            ->execute([json_encode($snapshot), $orderId]);
        $invoice = InvoiceService::issue($orderId);
        $pdo->exec("UPDATE orders SET status = 'delivered' WHERE id = " . (int)$orderId);
        $items = [];
        foreach (Orders::items($orderId) as $item) $items[(string)$item['sku']] = (int)$item['id'];
        return [$orderId, (int)$invoice['id'], $items];
    };
    // Money refunded in the provider's dashboard, with its credit note when the refund_credit_note job ran.
    $r57vOutside = static function (int $orderId, int $amount, string $key, bool $credited) use ($pdo): void {
        $pdo->prepare("INSERT INTO payment_transactions (order_id, provider, status, amount, currency, idempotency_key, store_id) VALUES (?, 'manual', 'partially_refunded', ?, 'eur', ?, 'default')")
            ->execute([$orderId, $amount, $key]);
        if ($credited) InvoiceService::creditRefund($orderId, $amount, $key);
    };
    // A received return, then "Refunded" as the admin page does it: closed without payout when the money refunded
    // outside covers it, else credited (less that money when there is any). What its credit note credits.
    $r57vReturn = static function (int $orderId, int $sourceId, int $orderItemId, string $key): int {
        [, $rmaId] = Rma::create($orderId, null, 'R57V return', [$orderItemId => 1]);
        Rma::setStatus((int)$rmaId, 'approved');
        Rma::receive((int)$rmaId, [(int)Rma::items((int)$rmaId)[0]['id'] => ['quantity' => 1, 'disposition' => 'restock']], $key . '-receipt');
        $outside = Rma::refundedOutsideReturns($orderId);
        $quantities = InvoiceService::creditableQuantities((int)$rmaId);
        if ($outside > 0 && InvoiceService::rmaCreditOwed($sourceId, (int)$rmaId, $quantities, $outside)[1] === 0) {
            return Rma::closeWithoutPayout((int)$rmaId) ? 0 : -1;
        }
        return -(int)InvoiceService::credit($sourceId, (int)$rmaId, $quantities, $key . '-credit', $outside > 0)['total_gross'];
    };
    // Per VAT rate: what the invoice charged and what its credit notes credited, gross.
    $r57vRates = static function (int $sourceId) use ($pdo): array {
        $gross = static fn(array $info): int => (int)($info['net'] ?? 0) + (int)($info['vat'] ?? 0);
        $charged = $credited = [];
        foreach (json_decode((string)$pdo->query("SELECT vat_breakdown FROM invoices WHERE id = {$sourceId}")->fetchColumn(), true) as $rate => $info) $charged[(string)$rate] = $gross($info);
        foreach ($pdo->query("SELECT vat_breakdown FROM invoices WHERE type = 'credit_note' AND refers_to_invoice_id = {$sourceId}")->fetchAll(PDO::FETCH_COLUMN) as $breakdown) {
            foreach (json_decode((string)$breakdown, true) ?: [] as $rate => $info) $credited[(string)$rate] = ($credited[(string)$rate] ?? 0) - $gross($info);
        }
        return [$charged, $credited];
    };

    // The reviewer's case: B returned, 10 € refunded in the dashboard (its credit note issued or not yet), "Refunded".
    foreach (['with its credit note' => true, 'before its credit note' => false] as $r57vLabel => $r57vCredited) {
        $r57vTag = 'r57v-b-' . ($r57vCredited ? 'credited' : 'pending');
        [$r57vId, $r57vSource, $r57vItems] = $r57vOrder($r57vTag);
        $r57vOutside($r57vId, 1000, $r57vTag . '-dashboard', $r57vCredited);
        $r57vPaid = $r57vReturn($r57vId, $r57vSource, $r57vItems['R57V-BOOK'], $r57vTag);
        assert_equals([0, 0], [$r57vPaid, Rma::refundedOutsideReturns($r57vId)],
            "R57 V1 {$r57vLabel}: the 10 € book's return is closed without payout on the 10 € refunded in the dashboard (was: 6.67 € more)");
    }

    // 20 € refunded in the dashboard with its credit note (13.33 € at 23 %, 6.67 € at 5 %), then A returned.
    [$r57vId, $r57vSource, $r57vItems] = $r57vOrder('r57v-a20');
    $r57vOutside($r57vId, 2000, 'r57v-a20-dashboard', true);
    assert_equals(0, $r57vReturn($r57vId, $r57vSource, $r57vItems['R57V-STD'], 'r57v-a20'),
        'R57 V1: A (20 €) returned after 20 € refunded outside is closed without payout (was: 6.67 € more, 26.67 € in all)');

    // Property over amounts, credit note issued or not, and the order of the returns: the customer never gets more than
    // the goods returned (outside money plus payouts), gets all of it once both are back, and no rate is credited more
    // than the invoice charged at it.
    $r57vRun = 0;
    foreach ([300, 1000, 1500, 2000, 2500] as $r57vAmount) {
        foreach ([true, false] as $r57vCredited) {
            foreach ([['R57V-STD'], ['R57V-BOOK'], ['R57V-STD', 'R57V-BOOK'], ['R57V-BOOK', 'R57V-STD']] as $r57vSequence) {
                $r57vTag = 'r57v-p' . (++$r57vRun);
                [$r57vId, $r57vSource, $r57vItems] = $r57vOrder($r57vTag);
                $r57vOutside($r57vId, $r57vAmount, $r57vTag . '-dashboard', $r57vCredited);
                $r57vPaid = 0;
                $r57vValue = 0;
                foreach ($r57vSequence as $r57vN => $r57vSku) {
                    $r57vPaid += $r57vReturn($r57vId, $r57vSource, $r57vItems[$r57vSku], $r57vTag . '-' . $r57vN);
                    $r57vValue += $r57vSku === 'R57V-STD' ? 2000 : 1000;
                }
                // Money refunded in the dashboard after both returns has no credit note to hold its rates: credit it now.
                if (!$r57vCredited) InvoiceService::creditRefund($r57vId, $r57vAmount, $r57vTag . '-dashboard');
                [$r57vCharged, $r57vCreditedRates] = $r57vRates($r57vSource);
                $r57vOver = array_filter($r57vCreditedRates, static fn(int $gross, string $rate): bool => $gross > ($r57vCharged[$rate] ?? 0), ARRAY_FILTER_USE_BOTH);
                $r57vCase = "{$r57vAmount} " . ($r57vCredited ? 'credited' : 'uncredited') . ' ' . implode('>', $r57vSequence);
                assert_equals([max(0, $r57vValue - $r57vAmount), []], [$r57vPaid, $r57vOver],
                    "R57 V1 property {$r57vCase}: payouts are the returned goods less the dashboard refund, no rate over-credited");
            }
        }
    }
} catch (Throwable $e) {
    assert_true(false, 'R57 double payout (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
