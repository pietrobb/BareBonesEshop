<?php

// Review round 27 (R62), findings 2, 3 and 4 (goal #659): credit note VAT after the cancelled unshipped rest.
//  2: poolsAfterUnshippedSpill() moved the outside money's gross from the cancelled goods' rate to where their refund
//     credited it, but not its net: the next return reversed 2.26 € at 5 % with 0.72 € of VAT and credited its 23 %
//     goods with 1.15 € of VAT on 8.25 € (each credit note off its rate by up to 2.34 €; the next one evened it out).
//  3: legacySpill() lines were not bounded by what VAT the rate had left beside the capped lines (+1 cent).
//  4: of cancelled 19 % and 5 % goods, the 19 % shortfall their refund credited at 5 % stayed counted at 19 %: a return
//     reversed it there and 19 % kept it uncredited although every 19 % item was cancelled.
echo "\n--- Audit round 62: credit note VAT after the cancelled unshipped rest ---\n";
$pdo ??= test_commerce_fixture();
Bootstrap::initStoreContext('/', '', 'default');

try {
    $r62cCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $r62cWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $r62cProducts = ['R62C-STD' => [96211, 'standard'], 'R62C-RED' => [96212, 'reduced'], 'R62C-BOOK' => [96213, 'books']];
    foreach ($r62cProducts as $r62cSku => [$r62cPid, $r62cClass]) {
        $pdo->prepare("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, vat_class)
            VALUES (?, 'default', ?, ?, ?, 'simple', ?, 1000, 'eur', 900, 'active', 'deny', ?)")->execute([$r62cPid, $r62cCategory, $r62cSku, strtolower($r62cSku), $r62cSku, $r62cClass]);
        Warehouse::setStock($r62cWarehouse, $r62cSku, 900, 'count', 'test', 'r62c-opening-' . $r62cSku);
    }
    // A paid, invoiced bank-transfer order (name => [sku, quantity, unit price]) plus 3.90 € shipping; $cancelled never
    // ship, $outside is refunded by hand, then the order is cancelled and the unshipped goods refunded. Returns the order,
    // its invoice and its items.
    $r62cCancelledRest = static function (string $tag, array $lines, array $cancelled, int $outside) use ($pdo, $r62cProducts): array {
        $order = $snap = [];
        foreach ($lines as $name => [$sku, $quantity, $price]) {
            $order[] = ['product_id' => $r62cProducts[$sku][0], 'variant_id' => null, 'sku' => $sku, 'name' => $name, 'qty' => $quantity, 'price' => $price, 'attrs' => []];
            $snap[] = ['product_id' => $r62cProducts[$sku][0], 'sku' => $sku, 'qty' => $quantity, 'price' => $price, 'product_type' => 'simple', 'vat_class' => $r62cProducts[$sku][1]];
        }
        $orderId = Orders::create($order, ['name' => 'R62C Buyer', 'email' => $tag . '@example.test', 'phone' => '', 'address' => ['country' => 'SK'],
            'shipping_cost' => 390, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur'], 'bank_transfer');
        $pdo->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid', payment_id = '', vat_scenario = 'domestic', shipping_addr = '{\"country\":\"SK\"}', financial_snapshot = ? WHERE id = ?")
            ->execute([json_encode(VatService::buildSnapshot($snap, ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur', 'shipping' => 390])), $orderId]);
        $sourceId = (int)InvoiceService::issue($orderId)['id'];
        $items = [];
        foreach (Orders::items($orderId) as $item) $items[(string)$item['name']] = [(int)$item['id'], (int)$item['quantity']];
        $parcels = Fulfillment::planOrder($orderId);
        $parcel = (int)$pdo->query("SELECT shipment_id FROM shipment_items WHERE sku = 'R62C-STD' AND shipment_id IN (" . implode(',', array_map('intval', $parcels) ?: [0]) . ')')->fetchColumn();
        $cancelledGross = 0;
        foreach ($cancelled as $name) {
            $pdo->prepare('DELETE FROM shipment_items WHERE order_item_id = ? AND shipment_id = ?')->execute([$items[$name][0], $parcel]);
            $cancelledGross += $lines[$name][1] * $lines[$name][2];
        }
        Fulfillment::ship($parcel);
        $pdo->prepare("INSERT INTO payment_transactions (order_id, provider, status, amount, currency, idempotency_key, store_id) VALUES (?, 'manual', 'partially_refunded', ?, 'eur', ?, 'default')")
            ->execute([$orderId, $outside, $tag . '-dash']);
        InvoiceService::creditRefund($orderId, $outside, $tag . '-dash');
        OrderWorkflow::transition($orderId, 'cancelled', 'R62 cancelled rest');
        foreach ([['order_refund_request_' . $orderId, 'refund_requested', '{"reason":"order.cancelled"}'], ['order_refund_' . $orderId, 'partially_refunded', '{}']] as [$key, $status, $raw]) {
            $pdo->prepare('DELETE FROM payment_transactions WHERE order_id = ? AND idempotency_key = ?')->execute([$orderId, $key]);
            $pdo->prepare("INSERT INTO payment_transactions (order_id, provider, status, amount, currency, idempotency_key, store_id, raw_response) VALUES (?, 'manual', ?, ?, 'eur', ?, 'default', ?)")
                ->execute([$orderId, $status, $cancelledGross, $key, $raw]);
        }
        $pdo->exec("UPDATE orders SET payment_status = 'partially_refunded' WHERE id = " . (int)$orderId);
        InvoiceService::creditRefund($orderId, $cancelledGross, 'order_refund_' . $orderId);
        return [$orderId, $sourceId, $items];
    };
    // $names returned, received and refunded (closed without payout when the outside money covers them).
    $r62cReturn = static function (int $orderId, int $sourceId, array $items, string $name, string $key): string|int {
        [, $rmaId] = Rma::create($orderId, null, 'R62C return', [$items[$name][0] => $items[$name][1]]);
        Rma::setStatus((int)$rmaId, 'approved');
        $decisions = [];
        foreach (Rma::items((int)$rmaId) as $rmaItem) $decisions[(int)$rmaItem['id']] = ['quantity' => (int)$rmaItem['quantity'], 'disposition' => 'restock'];
        Rma::receive((int)$rmaId, $decisions, $key . '-receipt');
        $outside = Rma::refundedOutsideReturns($orderId);
        $quantities = InvoiceService::creditableQuantities((int)$rmaId);
        if ($outside > 0 && InvoiceService::rmaCreditOwed($sourceId, (int)$rmaId, $quantities, $outside)[1] === 0) {
            return Rma::closeWithoutPayout((int)$rmaId) ? 'closed' : 'refused';
        }
        return -(int)InvoiceService::credit($sourceId, (int)$rmaId, $quantities, $key . '-credit', $outside > 0)['total_gross'];
    };
    // Per rate what the invoice has uncredited, and every credit note rate whose VAT is off its net by more than 1 cent.
    $r62cState = static function (int $sourceId) use ($pdo): array {
        $left = [];
        $off = [];
        foreach ($pdo->query("SELECT id, refers_to_invoice_id, vat_breakdown FROM invoices WHERE (id = {$sourceId} OR refers_to_invoice_id = {$sourceId}) AND status <> 'cancelled' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $document) {
            foreach (json_decode((string)$document['vat_breakdown'], true) ?: [] as $rate => $info) {
                if (!is_array($info)) continue;
                $left[(string)(int)$rate] = ($left[(string)(int)$rate] ?? 0) + (int)$info['net'] + (int)$info['vat'];
                if ((int)$document['refers_to_invoice_id'] !== $sourceId) continue;
                $expected = (int)round((int)$info['net'] * (float)$rate / 100);
                if (abs($expected - (int)$info['vat']) > 1) $off[] = sprintf('%d%%: %d/%d/%d', (int)$rate, -(int)$info['net'] - (int)$info['vat'], -(int)$info['net'], -(int)$info['vat']);
            }
        }
        krsort($left, SORT_NUMERIC);
        return [$left, $off];
    };

    // Finding 2: D 3×3.33 € (23 %) and C 2×8.99 € shipped, B 12.50 € cancelled, at 19 % / 5 % and the other way round.
    foreach (['19-cancelled' => ['R62C-RED', 'R62C-BOOK'], '5-cancelled' => ['R62C-BOOK', 'R62C-RED']] as $r62cCase => [$r62cB, $r62cC]) {
        foreach ([400, 1010, 2000] as $r62cDash) {
            foreach ([['D', 'C'], ['C', 'D']] as $r62cOrder) {
                $r62cTag = 'r62c-' . $r62cCase . '-' . $r62cDash . '-' . implode('', $r62cOrder);
                [$r62cId, $r62cSource, $r62cItems] = $r62cCancelledRest($r62cTag, ['D' => ['R62C-STD', 3, 333], 'B' => [$r62cB, 1, 1250], 'C' => [$r62cC, 2, 899]], ['B'], $r62cDash);
                $r62cPaid = [];
                foreach ($r62cOrder as $r62cName) $r62cPaid[] = $r62cReturn($r62cId, $r62cSource, $r62cItems, $r62cName, $r62cTag . $r62cName);
                [$r62cLeft, $r62cOff] = $r62cState($r62cSource);
                assert_equals([[], ['23' => 390, '19' => 0, '5' => 0], max(0, 999 + 1798 - $r62cDash)],
                    [$r62cOff, $r62cLeft, array_sum(array_filter($r62cPaid, 'is_int'))],
                    "R62 credit VAT ({$r62cCase}, {$r62cDash} refunded outside, returned " . implode(' then ', $r62cOrder) . '): every credit note\'s VAT fits its net at each rate, 23 % keeps the shipping, the goods less that money paid (was: 5 %: 226/154/72, 23 %: 825/710/115)');
            }
        }
    }

    // Finding 4: cancelled R 12.50 € (19 %) and K 47.70 € (5 %), shipped D 9.99 € (23 %) and S 8.99 € (5 %).
    foreach ([400, 1000] as $r62cDash) {
        $r62cTag = 'r62c-two-' . $r62cDash;
        [$r62cId, $r62cSource, $r62cItems] = $r62cCancelledRest($r62cTag,
            ['D' => ['R62C-STD', 1, 999], 'S' => ['R62C-BOOK', 1, 899], 'R' => ['R62C-RED', 1, 1250], 'K' => ['R62C-BOOK', 1, 4770]], ['R', 'K'], $r62cDash);
        $r62cPaid = [];
        foreach (['D', 'S'] as $r62cName) $r62cPaid[] = $r62cReturn($r62cId, $r62cSource, $r62cItems, $r62cName, $r62cTag . $r62cName);
        [$r62cLeft, $r62cOff] = $r62cState($r62cSource);
        assert_equals([[], ['23' => 390, '19' => 0, '5' => 0], 999 + 899 - $r62cDash],
            [$r62cOff, $r62cLeft, array_sum(array_filter($r62cPaid, 'is_int'))],
            "R62 two cancelled rates ({$r62cDash} refunded outside): 19 % ends credited in full, D and S pay the goods less that money (was: 19 % kept 0.19 € uncredited, S paid 8.80 €)");
    }

    // Finding 3: the spill line at a rate whose capped lines took a cent more VAT than its proportion.
    $r62cKey = VatService::breakdownKey(2300);
    $r62cLine = ['order_item_id' => 7, 'rma_item_id' => 5, 'sku' => 'R62C-STD', 'name' => 'R62C', 'quantity' => 1, 'unit_gross' => 10000, 'rate_basis_points' => 2300];
    $r62cSpill = (new ReflectionMethod(InvoiceService::class, 'legacySpill'))->invoke(null, $pdo,
        ['vat_breakdown' => json_encode([$r62cKey => ['net' => 2967, 'vat' => 684]])], ['id' => 0],
        [['gross' => 10000, 'net' => 8130, 'vat' => 1870] + $r62cLine], [['gross' => 100, 'net' => 80, 'vat' => 20] + $r62cLine],
        [$r62cKey => ['gross' => 3651, 'net' => 2967, 'supply' => []]], true);
    assert_equals([3551, 664], [(int)($r62cSpill[0]['gross'] ?? 0), (int)($r62cSpill[0]['vat'] ?? 0)],
        'R62 legacy spill: next to capped lines with 0.20 € of VAT the spill at 23 % credits the 6.64 € left, 6.84 € in all (was: 6.65 €, 6.85 € on an invoice with 6.84 €)');
} catch (Throwable $e) {
    assert_true(false, 'R62 credit VAT (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
