<?php

// Review round 24 (R59), finding 2, a regression of R58 (medium S1): R58 dropped R57's spill because a return closed
// without payout now gets a credit note of 0.00 that moves the outside money's credit to the returned goods' rate. Returns
// closed (or credited less that money) before R58 have no such note: the money stays credited at the rates of the goods
// kept, and their later return was capped at what their rate had left (A 20 € at 23 % and B 10 € at 5 %, 20 € refunded
// in the dashboard, A closed under R57, then B: 3.33 € instead of 10 €). What the caps take is now credited at the rates
// that have more uncredited than the goods not returned yet are worth there.
echo "\n--- R59 legacy spill: a return after one closed before R58 pays its goods in full ---\n";
$pdo ??= test_commerce_fixture();
Bootstrap::initStoreContext('/', '', 'default');
try {
    $r59sCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $r59sWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $r59sProducts = ['R59S-STD' => [95901, 'standard'], 'R59S-RED' => [95902, 'reduced'], 'R59S-BOOK' => [95903, 'books']];
    foreach ($r59sProducts as $r59sSku => [$r59sPid, $r59sClass]) {
        $pdo->prepare("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, vat_class)
            VALUES (?, 'default', ?, ?, ?, 'simple', ?, 1000, 'eur', 900, 'active', 'deny', ?)")->execute([$r59sPid, $r59sCategory, $r59sSku, strtolower($r59sSku), $r59sSku, $r59sClass]);
        Warehouse::setStock($r59sWarehouse, $r59sSku, 900, 'count', 'test', 'r59s-opening-' . $r59sSku);
    }
    $r59sOrder = static function (string $tag, array $lines) use ($pdo, $r59sProducts): array {
        $order = $snap = [];
        foreach ($lines as $name => [$sku, $price]) {
            $order[] = ['product_id' => $r59sProducts[$sku][0], 'variant_id' => null, 'sku' => $sku, 'name' => $name, 'qty' => 1, 'price' => $price, 'attrs' => []];
            $snap[] = ['product_id' => $r59sProducts[$sku][0], 'sku' => $sku, 'qty' => 1, 'price' => $price, 'product_type' => 'simple', 'vat_class' => $r59sProducts[$sku][1]];
        }
        $orderId = Orders::create($order, ['name' => 'R59S Buyer', 'email' => $tag . '@example.test', 'phone' => '', 'address' => ['country' => 'SK'],
            'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur'], 'bank_transfer');
        $pdo->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid', payment_id = '', vat_scenario = 'domestic', shipping_addr = '{\"country\":\"SK\"}', financial_snapshot = ? WHERE id = ?")
            ->execute([json_encode(VatService::buildSnapshot($snap, ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur'])), $orderId]);
        $invoice = InvoiceService::issue($orderId);
        $pdo->exec("UPDATE orders SET status = 'delivered' WHERE id = " . (int)$orderId);
        $items = [];
        foreach (Orders::items($orderId) as $item) $items[(string)$item['name']] = (int)$item['id'];
        return [$orderId, (int)$invoice['id'], $items];
    };
    $r59sOutside = static function (int $orderId, int $amount, string $key) use ($pdo): void {
        $pdo->prepare("INSERT INTO payment_transactions (order_id, provider, status, amount, currency, idempotency_key, store_id) VALUES (?, 'manual', 'partially_refunded', ?, 'eur', ?, 'default')")
            ->execute([$orderId, $amount, $key]);
        InvoiceService::creditRefund($orderId, $amount, $key);
    };
    // "Refunded" as the admin page does it: [what it pays (-1: refused), the rates its credit note credits].
    $r59sReturn = static function (int $orderId, int $sourceId, array $items, array $names, string $key): array {
        $choice = [];
        foreach ($names as $name) $choice[$items[$name]] = 1;
        [, $rmaId] = Rma::create($orderId, null, 'R59S return', $choice);
        Rma::setStatus((int)$rmaId, 'approved');
        $decisions = [];
        foreach (Rma::items((int)$rmaId) as $rmaItem) $decisions[(int)$rmaItem['id']] = ['quantity' => 1, 'disposition' => 'restock'];
        Rma::receive((int)$rmaId, $decisions, $key . '-receipt');
        $outside = Rma::refundedOutsideReturns($orderId);
        $quantities = InvoiceService::creditableQuantities((int)$rmaId);
        if ($outside > 0 && InvoiceService::rmaCreditOwed($sourceId, (int)$rmaId, $quantities, $outside)[1] === 0) {
            return [Rma::closeWithoutPayout((int)$rmaId) ? 0 : -1, []];
        }
        $credit = InvoiceService::credit($sourceId, (int)$rmaId, $quantities, $key . '-credit', $outside > 0);
        $rates = array_map('intval', array_keys(json_decode((string)$credit['vat_breakdown'], true) ?: []));
        sort($rates);
        return [-(int)$credit['total_gross'], $rates];
    };
    // A return closed before R58: its credit note of 0.00 never existed.
    $r59sLegacy = static function (int $orderId) use ($pdo): int {
        $notes = $pdo->query("SELECT id FROM invoices WHERE order_id = {$orderId} AND type = 'credit_note' AND credit_snapshot LIKE '%\"kind\":\"outside_balance\"%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($notes as $note) {
            $pdo->exec('DELETE FROM credit_note_items WHERE credit_note_id = ' . (int)$note);
            $pdo->exec('DELETE FROM invoices WHERE id = ' . (int)$note);
        }
        return count($notes);
    };
    $r59sUncredited = static function (int $sourceId) use ($pdo): array {
        $left = [];
        foreach ($pdo->query("SELECT vat_breakdown FROM invoices WHERE (id = {$sourceId} OR refers_to_invoice_id = {$sourceId}) AND status <> 'cancelled'")->fetchAll(PDO::FETCH_COLUMN) as $breakdown) {
            foreach (json_decode((string)$breakdown, true) ?: [] as $rate => $info) if (is_array($info)) $left[(string)(int)$rate] = ($left[(string)(int)$rate] ?? 0) + (int)$info['net'] + (int)$info['vat'];
        }
        krsort($left, SORT_NUMERIC);
        return $left;
    };

    // The review's case: A 20 € at 23 %, B 10 € at 5 %, 20 € refunded in the dashboard, A closed before R58.
    [$r59sId, $r59sSource, $r59sItems] = $r59sOrder('r59s-review', ['A' => ['R59S-STD', 2000], 'B' => ['R59S-BOOK', 1000]]);
    $r59sOutside($r59sId, 2000, 'r59s-review-dashboard');
    [$r59sPaidA] = $r59sReturn($r59sId, $r59sSource, $r59sItems, ['A'], 'r59s-review-a');
    assert_equals([0, 1, ['23' => 667, '5' => 333]], [$r59sPaidA, $r59sLegacy($r59sId), $r59sUncredited($r59sSource)],
        'R59 legacy spill: A closed without payout as before R58 (no balancing note): 6.67 € of the outside money stays credited at 5 %');
    [$r59sPaidB, $r59sRatesB] = $r59sReturn($r59sId, $r59sSource, $r59sItems, ['B'], 'r59s-review-b');
    assert_equals([1000, [5, 23], ['23' => 0, '5' => 0]], [$r59sPaidB, $r59sRatesB, $r59sUncredited($r59sSource)],
        'R59 legacy spill: B then pays 10 €, what 5 % lacks credited at 23 % where A was returned (was: 3.33 €)');

    // With goods kept at a third rate: A 20 € at 23 % closed before R58, B 10 € at 5 % returned, C 50 € at 19 % kept.
    [$r59sId, $r59sSource, $r59sItems] = $r59sOrder('r59s-kept', ['A' => ['R59S-STD', 2000], 'B' => ['R59S-BOOK', 1000], 'C' => ['R59S-RED', 5000]]);
    $r59sOutside($r59sId, 2000, 'r59s-kept-dashboard');
    [$r59sPaidA] = $r59sReturn($r59sId, $r59sSource, $r59sItems, ['A'], 'r59s-kept-a');
    $r59sLegacy($r59sId);
    [$r59sPaidB, $r59sRatesB] = $r59sReturn($r59sId, $r59sSource, $r59sItems, ['B'], 'r59s-kept-b');
    $r59sLeft = $r59sUncredited($r59sSource);
    assert_equals([0, 1000, [5, 23], 3750], [$r59sPaidA, $r59sPaidB, $r59sRatesB, $r59sLeft['19'] ?? null],
        'R59 legacy spill: B pays 10 €, the rest credited at 23 % (A returned), never at 19 % where C is kept');

    // The same order closed under R58 (with its balancing note): B is credited at 5 % alone, nothing moves.
    [$r59sId, $r59sSource, $r59sItems] = $r59sOrder('r59s-new', ['A' => ['R59S-STD', 2000], 'B' => ['R59S-BOOK', 1000]]);
    $r59sOutside($r59sId, 2000, 'r59s-new-dashboard');
    $r59sReturn($r59sId, $r59sSource, $r59sItems, ['A'], 'r59s-new-a');
    [$r59sPaidB, $r59sRatesB] = $r59sReturn($r59sId, $r59sSource, $r59sItems, ['B'], 'r59s-new-b');
    assert_equals([1000, [5]], [$r59sPaidB, $r59sRatesB], 'R59 legacy spill: after an R58 closing, B is credited at its own rate only');
} catch (Throwable $e) {
    assert_true(false, 'R59 legacy spill (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
