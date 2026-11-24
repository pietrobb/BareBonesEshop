<?php

// Review round 23 (R58), medium S1: R57 took money refunded outside the returns (a goodwill or dashboard refund) off a
// return whole, which paid the customer right, but the return's credit note left that money credited at the rates its
// own credit note had split it over: the returned goods stayed partly uncredited at their rate and the rates of the goods
// kept were credited for nothing. A later return at such a rate then lacked room, and R57 credited what it lacked at the
// rate with most room left, even one with nothing returned (2 € at 19 % on the return of 5 % books). Now the return's
// credit note credits its goods at their own rates and reverses the outside money's credit at the other rates (lines of
// negative gross, nothing in all); a return closed without payout gets such a note of nothing in all. After every return
// each rate has uncredited the goods kept at it, less the outside money no return took.
echo "\n--- R58 credit VAT: a return's credit note moves money refunded outside it to the returned goods' rates ---\n";
$pdo ??= test_commerce_fixture();
Bootstrap::initStoreContext('/', '', 'default');
try {
    $r58cCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $r58cWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $r58cProducts = ['R58C-STD' => [95801, 'standard'], 'R58C-RED' => [95802, 'reduced'], 'R58C-BOOK' => [95803, 'books']];
    foreach ($r58cProducts as $r58cSku => [$r58cPid, $r58cClass]) {
        $pdo->prepare("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, vat_class)
            VALUES (?, 'default', ?, ?, ?, 'simple', ?, 1000, 'eur', 900, 'active', 'deny', ?)")->execute([$r58cPid, $r58cCategory, $r58cSku, strtolower($r58cSku), $r58cSku, $r58cClass]);
        Warehouse::setStock($r58cWarehouse, $r58cSku, 900, 'count', 'test', 'r58c-opening-' . $r58cSku);
    }
    $r58cRateOf = ['R58C-STD' => '23', 'R58C-RED' => '19', 'R58C-BOOK' => '5'];

    // A paid, delivered and invoiced order of $lines (name => [sku, price]; one unit each).
    $r58cOrder = static function (string $tag, array $lines) use ($pdo, $r58cProducts): array {
        $order = $snap = [];
        foreach ($lines as $name => [$sku, $price]) {
            $order[] = ['product_id' => $r58cProducts[$sku][0], 'variant_id' => null, 'sku' => $sku, 'name' => $name, 'qty' => 1, 'price' => $price, 'attrs' => []];
            $snap[] = ['product_id' => $r58cProducts[$sku][0], 'sku' => $sku, 'qty' => 1, 'price' => $price, 'product_type' => 'simple', 'vat_class' => $r58cProducts[$sku][1]];
        }
        $orderId = Orders::create($order, ['name' => 'R58C Buyer', 'email' => $tag . '@example.test', 'phone' => '', 'address' => ['country' => 'SK'],
            'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur'], 'bank_transfer');
        $pdo->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid', payment_id = '', vat_scenario = 'domestic', shipping_addr = '{\"country\":\"SK\"}', financial_snapshot = ? WHERE id = ?")
            ->execute([json_encode(VatService::buildSnapshot($snap, ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur'])), $orderId]);
        $invoice = InvoiceService::issue($orderId);
        $pdo->exec("UPDATE orders SET status = 'delivered' WHERE id = " . (int)$orderId);
        $items = [];
        foreach (Orders::items($orderId) as $item) $items[(string)$item['name']] = (int)$item['id'];
        return [$orderId, (int)$invoice['id'], $items];
    };
    // Money refunded in the provider's dashboard, with its credit note (the refund_credit_note job ran).
    $r58cOutside = static function (int $orderId, int $amount, string $key) use ($pdo): void {
        $pdo->prepare("INSERT INTO payment_transactions (order_id, provider, status, amount, currency, idempotency_key, store_id) VALUES (?, 'manual', 'partially_refunded', ?, 'eur', ?, 'default')")
            ->execute([$orderId, $amount, $key]);
        InvoiceService::creditRefund($orderId, $amount, $key);
    };
    // A received return of $names, then "Refunded" as the admin page does it: closed without payout when the money
    // refunded outside covers it, else credited less that money. [what it pays, its refund id or 0].
    $r58cReturn = static function (int $orderId, int $sourceId, array $items, array $names, string $key): array {
        $choice = [];
        foreach ($names as $name) $choice[$items[$name]] = 1;
        [, $rmaId] = Rma::create($orderId, null, 'R58C return', $choice);
        Rma::setStatus((int)$rmaId, 'approved');
        $decisions = [];
        foreach (Rma::items((int)$rmaId) as $rmaItem) $decisions[(int)$rmaItem['id']] = ['quantity' => 1, 'disposition' => 'restock'];
        Rma::receive((int)$rmaId, $decisions, $key . '-receipt');
        $outside = Rma::refundedOutsideReturns($orderId);
        $quantities = InvoiceService::creditableQuantities((int)$rmaId);
        if ($outside > 0 && InvoiceService::rmaCreditOwed($sourceId, (int)$rmaId, $quantities, $outside)[1] === 0) {
            return [Rma::closeWithoutPayout((int)$rmaId) ? 0 : -1, 0];
        }
        $credit = InvoiceService::credit($sourceId, (int)$rmaId, $quantities, $key . '-credit', $outside > 0);
        return [-(int)$credit['total_gross'], (int)$credit['refund_id']];
    };
    // Per VAT rate (23 / 19 / 5) what the invoice has uncredited, gross, as the review read it.
    $r58cUncredited = static function (int $sourceId) use ($pdo): array {
        $left = [];
        foreach ($pdo->query("SELECT vat_breakdown FROM invoices WHERE (id = {$sourceId} OR refers_to_invoice_id = {$sourceId}) AND status <> 'cancelled'")->fetchAll(PDO::FETCH_COLUMN) as $breakdown) {
            foreach (json_decode((string)$breakdown, true) ?: [] as $rate => $info) if (is_array($info)) $left[(string)$rate] = ($left[(string)$rate] ?? 0) + (int)$info['net'] + (int)$info['vat'];
        }
        krsort($left, SORT_NUMERIC);
        return $left;
    };
    // Per order item what the order's live credit notes credit it (as SalesReport reads them), less its value.
    $r58cItemsOver = static function (int $orderId) use ($pdo): array {
        $over = [];
        foreach ($pdo->query("SELECT cni.order_item_id, SUM(cni.gross) AS g, oi.total FROM credit_note_items cni JOIN invoices i ON i.id = cni.credit_note_id AND i.status <> 'cancelled'
            LEFT JOIN order_refunds r ON r.id = cni.refund_id JOIN order_items oi ON oi.id = cni.order_item_id
            WHERE i.order_id = {$orderId} AND (cni.refund_id = 0 OR " . Rma::LIVE_REFUND_SQL . ") GROUP BY cni.order_item_id, oi.total")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int)$row['g'] < 0 || (int)$row['g'] > (int)$row['total']) $over[(int)$row['order_item_id']] = (int)$row['g'] . ' of ' . (int)$row['total'];
        }
        return $over;
    };

    // The review's first case: A1, A2 14.50 € at 23 % kept, B1-B3 12 € at 5 % returned, 23.82 € refunded in the dashboard.
    [$r58cId, $r58cSource, $r58cItems] = $r58cOrder('r58c-books', ['A1' => ['R58C-STD', 1450], 'A2' => ['R58C-STD', 1450],
        'B1' => ['R58C-BOOK', 1200], 'B2' => ['R58C-BOOK', 1200], 'B3' => ['R58C-BOOK', 1200]]);
    $r58cOutside($r58cId, 2382, 'r58c-books-dashboard');
    [$r58cPaid] = $r58cReturn($r58cId, $r58cSource, $r58cItems, ['B1', 'B2', 'B3'], 'r58c-books');
    assert_equals([1218, ['23' => 2900, '5' => 0], []], [$r58cPaid, $r58cUncredited($r58cSource), $r58cItemsOver($r58cId)],
        'R58 S1: the three books returned after 23.82 € refunded outside pay 12.18 €, and 5 % is credited in full, 23 % keeps the 29 € of A1 and A2 (was: 10.63 € left at 5 %, 18.37 € at 23 %)');

    // A 20 € at 23 % returned, B and D 10 € each at 5 % kept, 10 € refunded outside.
    [$r58cId, $r58cSource, $r58cItems] = $r58cOrder('r58c-a20', ['A' => ['R58C-STD', 2000], 'B' => ['R58C-BOOK', 1000], 'D' => ['R58C-BOOK', 1000]]);
    $r58cOutside($r58cId, 1000, 'r58c-a20-dashboard');
    [$r58cPaid, $r58cRefund] = $r58cReturn($r58cId, $r58cSource, $r58cItems, ['A'], 'r58c-a20');
    assert_equals([1000, ['23' => 0, '5' => 2000]], [$r58cPaid, $r58cUncredited($r58cSource)],
        'R58 S1: A returned after 10 € refunded outside pays 10 €, 23 % is credited in full and 5 % keeps B and D (was: 15 € at 5 %, 5 € at 23 %)');
    // The item refund declined: its credit note reversed, every rate as before the return (was: the reversal took the
    // rate the note gave back once more).
    Rma::failRefund($r58cRefund, 'R58 declined');
    assert_equals(['23' => 1500, '5' => 1500], $r58cUncredited($r58cSource),
        'R58 S1: the reversal of that credit note restores both rates (it gives 5 % back what the note took from it)');
    // ... and the provider paid it out after all: its money credits exactly what the reversed note did.
    $pdo->prepare("INSERT INTO payment_transactions (order_id, provider, provider_ref, status, amount, currency, idempotency_key, store_id) VALUES (?, 'manual', 're_r58c_a20', 'partially_refunded', 1000, 'eur', 'r58c-a20-credit', 'default')")
        ->execute([$r58cId]);
    Rma::coverReleasedRefund($r58cRefund, 're_r58c_a20');
    InvoiceService::creditBookedRefund($r58cId, 'r58c-a20-credit');
    assert_equals([['23' => 0, '5' => 2000], 0], [$r58cUncredited($r58cSource), Rma::refundedOutsideReturns($r58cId)],
        'R58 S1: the released item refund paid out after all credits A at 23 % and gives 5 % back again (its credit note\'s rates)');

    // Three rates: A 10 € at 23 %, B 20 € at 5 % returned one after the other, C 50 € at 19 % kept, 8 € refunded outside.
    [$r58cId, $r58cSource, $r58cItems] = $r58cOrder('r58c-three', ['A' => ['R58C-STD', 1000], 'B' => ['R58C-BOOK', 2000], 'C' => ['R58C-RED', 5000]]);
    $r58cOutside($r58cId, 800, 'r58c-three-dashboard');
    [$r58cPaidA] = $r58cReturn($r58cId, $r58cSource, $r58cItems, ['A'], 'r58c-three-a');
    [$r58cPaidB] = $r58cReturn($r58cId, $r58cSource, $r58cItems, ['B'], 'r58c-three-b');
    // The rates B is credited at (lines of positive gross naming it).
    $r58cRates = $pdo->query("SELECT DISTINCT cni.rate_basis_points FROM credit_note_items cni JOIN invoices i ON i.id = cni.credit_note_id
        WHERE i.order_id = {$r58cId} AND cni.order_item_id = {$r58cItems['B']} AND cni.gross > 0 ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
    assert_equals([200, 2000, ['23' => 0, '19' => 5000, '5' => 0], [500], []], [$r58cPaidA, $r58cPaidB, $r58cUncredited($r58cSource), array_map('intval', $r58cRates), $r58cItemsOver($r58cId)],
        'R58 S1: A then B returned after 8 € refunded outside pay 2 € and 20 €; 19 % keeps C\'s 50 € and B is credited at 5 % only (was: 7 € left at 23 %, 43 € at 19 %, 2 € of B credited at 19 %)');

    // Closed without payout: A 20 € at 23 % returned after 20 € refunded outside (13.33 € at 23 %, 6.67 € at 5 %),
    // then B 10 € at 5 %. The closing issues a credit note of nothing that moves 6.67 € from 5 % to 23 %.
    [$r58cId, $r58cSource, $r58cItems] = $r58cOrder('r58c-closed', ['A' => ['R58C-STD', 2000], 'B' => ['R58C-BOOK', 1000]]);
    $r58cOutside($r58cId, 2000, 'r58c-closed-dashboard');
    [$r58cPaidA] = $r58cReturn($r58cId, $r58cSource, $r58cItems, ['A'], 'r58c-closed-a');
    $r58cAfterA = $r58cUncredited($r58cSource);
    $r58cBalance = $pdo->query("SELECT total_gross FROM invoices WHERE order_id = {$r58cId} AND type = 'credit_note' AND credit_snapshot LIKE '%\"kind\":\"outside_balance\"%'")->fetchAll(PDO::FETCH_COLUMN);
    [$r58cPaidB] = $r58cReturn($r58cId, $r58cSource, $r58cItems, ['B'], 'r58c-closed-b');
    assert_equals([0, ['23' => 0, '5' => 1000], ['0'], 1000, ['23' => 0, '5' => 0], []],
        [$r58cPaidA, $r58cAfterA, array_map('strval', $r58cBalance), $r58cPaidB, $r58cUncredited($r58cSource), $r58cItemsOver($r58cId)],
        'R58 S1: A closed without payout gets a credit note of 0.00 that credits it at 23 % and gives 5 % back; B then pays 10 € at 5 % (was: no note, B capped and credited partly at 23 %)');

    // Property over amounts and returns: the customer gets the goods returned or the money refunded outside, whichever is
    // more; at no rate more is credited than invoiced; every rate has uncredited at most the goods kept at it, and all
    // rates together the goods kept less the outside money no return took.
    $r58cRun = 0;
    $r58cLines = ['A' => ['R58C-STD', 1000], 'B' => ['R58C-BOOK', 2000], 'C' => ['R58C-RED', 5000]];
    foreach ([300, 800, 2500, 4000, 7900] as $r58cAmount) {
        foreach ([['A'], ['B'], ['C'], ['A', 'B'], ['B', 'A'], ['C', 'A'], ['A', 'B', 'C'], ['C', 'B', 'A']] as $r58cSequence) {
            $r58cTag = 'r58c-p' . (++$r58cRun);
            [$r58cId, $r58cSource, $r58cItems] = $r58cOrder($r58cTag, $r58cLines);
            $r58cOutside($r58cId, $r58cAmount, $r58cTag . '-dashboard');
            $r58cPaid = $r58cValue = 0;
            foreach ($r58cSequence as $r58cN => $r58cName) {
                $r58cPaid += $r58cReturn($r58cId, $r58cSource, $r58cItems, [$r58cName], $r58cTag . '-' . $r58cN)[0];
                $r58cValue += $r58cLines[$r58cName][1];
            }
            $r58cKept = ['23' => 1000, '19' => 5000, '5' => 2000];
            foreach ($r58cSequence as $r58cName) $r58cKept[$r58cRateOf[$r58cLines[$r58cName][0]]] -= $r58cLines[$r58cName][1];
            $r58cLeft = $r58cUncredited($r58cSource);
            $r58cWrong = [];
            foreach ($r58cKept as $r58cRate => $r58cGross) if (($r58cLeft[$r58cRate] ?? 0) < 0 || ($r58cLeft[$r58cRate] ?? 0) > $r58cGross) $r58cWrong[$r58cRate] = ($r58cLeft[$r58cRate] ?? 0) . ' of kept ' . $r58cGross;
            $r58cCase = "{$r58cAmount} " . implode('>', $r58cSequence);
            assert_equals([max($r58cValue, $r58cAmount) - $r58cAmount, [], array_sum($r58cKept) - max(0, $r58cAmount - $r58cValue), []],
                [$r58cPaid, $r58cWrong, array_sum($r58cLeft), $r58cItemsOver($r58cId)],
                "R58 S1 property {$r58cCase}: payouts are the goods less the outside money, each rate keeps at most its kept goods uncredited");
        }
    }
} catch (Throwable $e) {
    assert_true(false, 'R58 credit VAT (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
