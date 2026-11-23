<?php

// Review round 21 (R56), medium M3: a goodwill refund's credit note is split over the invoice's VAT rates; a return
// then took the whole goodwill money off its goods (R51) while the rest of its rate was already lowered only by that
// rate's share, and the next return at the other rate was capped by its share again. A 20 € at 23 % + B 10 € at 5 %,
// goodwill 10 €, both returned: 26.67 € of 30 € back (23.33 € the other way round). The outside money now comes off a
// return per VAT rate: the share its credit note credited at the return's rates (plus money without a credit note).
echo "\n--- R56 goodwill VAT: a refund outside the returns comes off each return at its own rates ---\n";
$pdo ??= test_commerce_fixture();
Bootstrap::initStoreContext('/', '', 'default');
try {
    $r56gCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $r56gWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, vat_class) VALUES
        (95661, 'default', {$r56gCategory}, 'R56G-STD', 'r56g-std', 'simple', 'R56G standard', 1000, 'eur', 50, 'active', 'deny', 'standard'),
        (95662, 'default', {$r56gCategory}, 'R56G-BOOK', 'r56g-book', 'simple', 'R56G book', 1000, 'eur', 50, 'active', 'deny', 'books')");
    foreach (['R56G-STD', 'R56G-BOOK'] as $r56gSku) Warehouse::setStock($r56gWarehouse, $r56gSku, 50, 'count', 'test', 'r56g-opening-' . $r56gSku);

    // A paid, delivered and invoiced order: $std units of the standard item at 10 € (or one at 20 €) and one 10 € book.
    $r56gOrder = static function (string $tag, int $stdQty, int $stdPrice) use ($pdo): array {
        $line = static fn(int $productId, string $sku, int $qty, int $price): array => ['product_id' => $productId, 'variant_id' => null, 'sku' => $sku,
            'name' => $sku, 'qty' => $qty, 'price' => $price, 'attrs' => []];
        $orderId = Orders::create([$line(95661, 'R56G-STD', $stdQty, $stdPrice), $line(95662, 'R56G-BOOK', 1, 1000)],
            ['name' => 'R56G Buyer', 'email' => $tag . '@example.test', 'phone' => '', 'address' => ['country' => 'SK'], 'shipping_cost' => 0, 'tax_amount' => 0,
                'coupon_discount' => 0, 'currency' => 'eur'], 'bank_transfer');
        $snapshot = VatService::buildSnapshot([
            ['product_id' => 95661, 'sku' => 'R56G-STD', 'qty' => $stdQty, 'price' => $stdPrice, 'product_type' => 'simple', 'vat_class' => 'standard'],
            ['product_id' => 95662, 'sku' => 'R56G-BOOK', 'qty' => 1, 'price' => 1000, 'product_type' => 'simple', 'vat_class' => 'books'],
        ], ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur']);
        $pdo->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid', payment_id = '', vat_scenario = 'domestic', shipping_addr = '{\"country\":\"SK\"}', financial_snapshot = ? WHERE id = ?")
            ->execute([json_encode($snapshot), $orderId]);
        $invoice = InvoiceService::issue($orderId);
        $pdo->exec("UPDATE orders SET status = 'delivered' WHERE id = " . (int)$orderId);
        $items = [];
        foreach (Orders::items($orderId) as $item) $items[(string)$item['sku']] = (int)$item['id'];
        return [$orderId, (int)$invoice['id'], $items];
    };
    // Money refunded outside the returns (order page, provider dashboard), with its credit note when $credited.
    $r56gOutside = static function (int $orderId, int $amount, string $key, bool $credited) use ($pdo): void {
        $pdo->prepare("INSERT INTO payment_transactions (order_id, provider, status, amount, currency, idempotency_key, store_id) VALUES (?, 'manual', 'partially_refunded', ?, 'eur', ?, 'default')")
            ->execute([$orderId, $amount, $key]);
        if ($credited) InvoiceService::creditRefund($orderId, $amount, $key);
    };
    $r56gReturn = static function (int $orderId, int $orderItemId, int $qty, string $key): int {
        [, $rmaId] = Rma::create($orderId, null, 'R56G return', [$orderItemId => $qty]);
        Rma::setStatus((int)$rmaId, 'approved');
        Rma::receive((int)$rmaId, [(int)Rma::items((int)$rmaId)[0]['id'] => ['quantity' => $qty, 'disposition' => 'restock']], $key);
        return (int)$rmaId;
    };
    // "Refunded" with the outside money deducted: [what the return list says it pays, what its credit note credits].
    $r56gRefund = static function (int $sourceId, int $rmaId, string $key): array {
        $orderId = (int)Rma::byId($rmaId)['order_id'];
        $due = Rma::owedAfterOutside($rmaId, $orderId)[1];
        if ($due === 0 && Rma::refundedOutsideReturns($orderId) > 0) return [$due, Rma::closeWithoutPayout($rmaId) ? 0 : -1];
        $credit = InvoiceService::credit($sourceId, $rmaId, InvoiceService::creditableQuantities($rmaId), $key, true);
        return [$due, -(int)$credit['total_gross']];
    };
    $r56gCredited = static fn(int $sourceId): int => -(int)$pdo->query("SELECT COALESCE(SUM(total_gross), 0) FROM invoices WHERE type = 'credit_note' AND refers_to_invoice_id = {$sourceId}")->fetchColumn();
    $r56gShares = static function (int $orderId, string $key): array {
        $note = null;
        foreach (Db::get()->query("SELECT credit_snapshot, vat_breakdown FROM invoices WHERE type = 'credit_note' AND order_id = {$orderId}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((json_decode((string)$row['credit_snapshot'], true)['refund_key'] ?? '') === $key) $note = $row;
        }
        $shares = [];
        foreach (json_decode((string)($note['vat_breakdown'] ?? '[]'), true) ?: [] as $rate => $info) $shares[(string)$rate] = -(int)$info['net'] - (int)$info['vat'];
        return $shares;
    };

    // The reviewer's case: A 20 € at 23 %, B 10 € at 5 %, goodwill 10 € with its credit note, then both returned.
    foreach (['A then B' => ['R56G-STD', 'R56G-BOOK'], 'B then A' => ['R56G-BOOK', 'R56G-STD']] as $r56gLabel => $r56gOrderOf) {
        $r56gTag = 'r56g-' . strtolower(str_replace(' ', '', $r56gLabel));
        [$r56gId, $r56gSource, $r56gItems] = $r56gOrder($r56gTag, 1, 2000);
        $r56gOutside($r56gId, 1000, $r56gTag . '-goodwill', true);
        $r56gShare = $r56gShares($r56gId, $r56gTag . '-goodwill');
        assert_equals(2, count($r56gShare), "R56 goodwill {$r56gLabel}: the goodwill credit note is split over both rates");
        $r56gGot = [];
        foreach ($r56gOrderOf as $r56gN => $r56gSku) {
            $r56gRma = $r56gReturn($r56gId, $r56gItems[$r56gSku], 1, $r56gTag . '-receipt-' . $r56gN);
            $r56gGot[$r56gSku] = $r56gRefund($r56gSource, $r56gRma, $r56gTag . '-credit-' . $r56gN);
        }
        ksort($r56gGot);
        $r56gStdShare = max($r56gShare);
        $r56gBookShare = min($r56gShare);
        assert_equals(['R56G-BOOK' => [1000 - $r56gBookShare, 1000 - $r56gBookShare], 'R56G-STD' => [2000 - $r56gStdShare, 2000 - $r56gStdShare]],
            $r56gGot, "R56 goodwill {$r56gLabel}: each return pays its goods less the goodwill share credited at its rate (was: the first less all 10 €)");
        assert_equals(3000, 1000 + array_sum(array_column($r56gGot, 1)),
            "R56 goodwill {$r56gLabel}: goodwill + both returns = the 30 € paid (was: 26.67 € A first, 23.33 € B first)");
        assert_equals([3000, 0], [$r56gCredited($r56gSource), Rma::refundedOutsideReturns($r56gId)],
            "R56 goodwill {$r56gLabel}: the credit notes credit the whole invoice and the goodwill money is used up");
    }

    // Two 10 € units at 23 % + the 10 € book, 15 € goodwill credited (10 € at 23 %, 5 € at 5 %). The first unit's
    // return is covered by the 23 % share and closed without payout; it records the rate it used, so the second unit
    // is paid in full and the book less its 5 € share (was: the second unit less the 5 € the book's rate kept too).
    [$r56gId, $r56gSource, $r56gItems] = $r56gOrder('r56g-close', 2, 1000);
    $r56gOutside($r56gId, 1500, 'r56g-close-goodwill', true);
    $r56gShare = $r56gShares($r56gId, 'r56g-close-goodwill');
    assert_equals([1000, 500], [max($r56gShare), min($r56gShare)], 'R56 goodwill close: the 15 € goodwill is credited 10 € at 23 % and 5 € at 5 %');
    $r56gFirst = $r56gRefund($r56gSource, $r56gReturn($r56gId, $r56gItems['R56G-STD'], 1, 'r56g-close-receipt-1'), 'r56g-close-credit-1');
    $r56gKeys = json_decode((string)$pdo->query("SELECT outside_refund_keys FROM rma_requests WHERE order_id = {$r56gId} ORDER BY id LIMIT 1")->fetchColumn(), true);
    assert_equals([[0, 0], [(string)array_search(1000, $r56gShare, true) => 1000]], [$r56gFirst, $r56gKeys],
        'R56 goodwill close: the first unit is closed without payout and records the 10 € it used at 23 %');
    $r56gSecond = $r56gRefund($r56gSource, $r56gReturn($r56gId, $r56gItems['R56G-STD'], 1, 'r56g-close-receipt-2'), 'r56g-close-credit-2');
    $r56gBook = $r56gRefund($r56gSource, $r56gReturn($r56gId, $r56gItems['R56G-BOOK'], 1, 'r56g-close-receipt-3'), 'r56g-close-credit-3');
    assert_equals([[1000, 1000], [500, 500], 3000], [$r56gSecond, $r56gBook, 1500 + $r56gSecond[1] + $r56gBook[1]],
        'R56 goodwill close: the second unit in full, the book less its 5 € share: 30 € back in all (was: 25 €)');

    // Money refunded outside without a credit note still comes off any return first (R49): B's return is closed on it,
    // the rest of it comes off A.
    [$r56gId, $r56gSource, $r56gItems] = $r56gOrder('r56g-free', 1, 2000);
    $r56gOutside($r56gId, 1500, 'r56g-free-dashboard', false);
    $r56gB = $r56gRefund($r56gSource, $r56gReturn($r56gId, $r56gItems['R56G-BOOK'], 1, 'r56g-free-receipt-1'), 'r56g-free-credit-1');
    $r56gA = $r56gRefund($r56gSource, $r56gReturn($r56gId, $r56gItems['R56G-STD'], 1, 'r56g-free-receipt-2'), 'r56g-free-credit-2');
    assert_equals([[0, 0], [1500, 1500], 0], [$r56gB, $r56gA, Rma::refundedOutsideReturns($r56gId)],
        'R56 goodwill free: money without a credit note closes the book\'s return and comes off A\'s (15 € + 15 € = 30 €)');
} catch (Throwable $e) {
    assert_true(false, 'R56 goodwill VAT (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
