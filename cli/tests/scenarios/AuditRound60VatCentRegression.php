<?php

// Review round 25 (R60), lows "VAT by 1 cent": a return less money refunded outside the returns credited a cent more VAT
// than its goods had left (each line kept its own proportion, not what that money's credit note took at the rate); the
// line giving back what that money credited at the other rate used the invoice's proportion, so the rate kept a cent of
// VAT credited until its last return; a refund credit note that leaves a rate nothing uncredited did not take its net
// rest (R59 fixed that for item credits only). Swept over prices and discounts: after each document no rate is credited
// more net or VAT than invoiced, the book's rate fully credited by its return is credited exactly, and all returned
// credits the invoice exactly.
echo "\n--- R60 VAT cents: outside money, balancing lines and refund credit notes end on the invoice's own cents ---\n";
$pdo ??= test_commerce_fixture();
Bootstrap::initStoreContext('/', '', 'default');
try {
    $r60cCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $r60cWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $r60cProducts = ['R60C-STD' => [96081, 'standard'], 'R60C-BOOK' => [96082, 'books']];
    foreach ($r60cProducts as $r60cSku => [$r60cPid, $r60cClass]) {
        $pdo->prepare("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, vat_class)
            VALUES (?, 'default', ?, ?, ?, 'simple', ?, 1000, 'eur', 9000, 'active', 'deny', ?)")->execute([$r60cPid, $r60cCategory, $r60cSku, strtolower($r60cSku), $r60cSku, $r60cClass]);
        Warehouse::setStock($r60cWarehouse, $r60cSku, 9000, 'count', 'test', 'r60c-opening-' . $r60cSku);
    }
    // Per rate what the invoice less its credit notes has left: [gross, net, vat].
    $r60cLeft = static function (int $sourceId) use ($pdo): array {
        $left = [];
        foreach ($pdo->query("SELECT vat_breakdown FROM invoices WHERE (id = {$sourceId} OR refers_to_invoice_id = {$sourceId}) AND status <> 'cancelled'")->fetchAll(PDO::FETCH_COLUMN) as $breakdown) {
            foreach (json_decode((string)$breakdown, true) ?: [] as $rate => $info) {
                if (!is_array($info)) continue;
                $rate = (string)(int)$rate;
                $left[$rate] ??= [0, 0, 0];
                $left[$rate] = [$left[$rate][0] + (int)$info['net'] + (int)$info['vat'], $left[$rate][1] + (int)$info['net'], $left[$rate][2] + (int)$info['vat']];
            }
        }
        return $left;
    };
    $r60cBad = [];
    $r60cCase = 0;
    $r60cCases = [];
    foreach ([[2000, 1000, 500], [2001, 1003, 333], [1999, 1007, 501], [2013, 999, 257], [2507, 1011, 701], [1993, 1019, 123], [2111, 1013, 997], [2003, 1001, 667]] as $r60cBase) {
        foreach ([['B', 'A'], ['A', 'B']] as $r60cReturns) foreach ([1, 2] as $r60cParts) $r60cCases[] = [...$r60cBase, $r60cReturns, $r60cParts];
    }
    foreach ($r60cCases as [$r60cPriceA, $r60cPriceB, $r60cDiscount, $r60cReturns, $r60cParts]) {
        $r60cTag = 'r60c-' . (++$r60cCase);
        $order = $snap = [];
        foreach (['A' => ['R60C-STD', $r60cPriceA], 'B' => ['R60C-BOOK', $r60cPriceB]] as $name => [$sku, $price]) {
            $order[] = ['product_id' => $r60cProducts[$sku][0], 'variant_id' => null, 'sku' => $sku, 'name' => $name, 'qty' => 1, 'price' => $price, 'attrs' => []];
            $snap[] = ['product_id' => $r60cProducts[$sku][0], 'sku' => $sku, 'qty' => 1, 'price' => $price, 'product_type' => 'simple', 'vat_class' => $r60cProducts[$sku][1]];
        }
        $orderId = Orders::create($order, ['name' => 'R60C Buyer', 'email' => $r60cTag . '@example.test', 'phone' => '', 'address' => ['country' => 'SK'],
            'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur'], 'bank_transfer');
        $pdo->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid', payment_id = '', vat_scenario = 'domestic', shipping_addr = '{\"country\":\"SK\"}', financial_snapshot = ? WHERE id = ?")
            ->execute([json_encode(VatService::buildSnapshot($snap, ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur'])), $orderId]);
        $sourceId = (int)InvoiceService::issue($orderId)['id'];
        $pdo->exec("UPDATE orders SET status = 'delivered' WHERE id = " . (int)$orderId);
        $items = [];
        foreach (Orders::items($orderId) as $item) $items[(string)$item['name']] = (int)$item['id'];
        $invoiced = $r60cLeft($sourceId);
        $check = static function (string $step) use (&$r60cBad, $r60cLeft, $sourceId, $invoiced, $r60cTag): array {
            $left = $r60cLeft($sourceId);
            foreach ($left as $rate => [$gross, $net, $vat]) {
                if ($net < 0 || $vat < 0 || $net > $invoiced[$rate][1] || $vat > $invoiced[$rate][2] || ($gross === 0 && ($net !== 0 || $vat !== 0))
                    || ($gross === $invoiced[$rate][0] && $net !== $invoiced[$rate][1])) {
                    $r60cBad[] = "{$r60cTag} {$step} {$rate} %: " . json_encode($left[$rate]);
                }
            }
            return $left;
        };
        // A discount for a late delivery, refunded from the order page over the invoice (in one or two refunds).
        foreach ($r60cParts === 1 ? [$r60cDiscount] : [intdiv($r60cDiscount, 3), $r60cDiscount - intdiv($r60cDiscount, 3)] as $part => $amount) {
            $pdo->prepare("INSERT INTO payment_transactions (order_id, provider, status, amount, currency, idempotency_key, store_id) VALUES (?, 'manual', 'partially_refunded', ?, 'eur', ?, 'default')")
                ->execute([$orderId, $amount, $r60cTag . '-late' . $part]);
            InvoiceService::creditRefund($orderId, $amount, $r60cTag . '-late' . $part);
            $check('discount ' . $part);
        }
        foreach ($r60cReturns as $name) {
            [, $rmaId] = Rma::create($orderId, null, 'R60C return', [$items[$name] => 1]);
            Rma::setStatus((int)$rmaId, 'approved');
            $decisions = [];
            foreach (Rma::items((int)$rmaId) as $rmaItem) $decisions[(int)$rmaItem['id']] = ['quantity' => 1, 'disposition' => 'restock'];
            Rma::receive((int)$rmaId, $decisions, $r60cTag . '-' . $name . '-receipt');
            InvoiceService::credit($sourceId, (int)$rmaId, InvoiceService::creditableQuantities((int)$rmaId), $r60cTag . '-' . $name . '-credit', Rma::refundedOutsideReturns($orderId) > 0);
            $left = $check('return ' . $name);
            // The book's rate: its return leaves nothing of it, gross, net or VAT.
            if ($name === 'B' && $r60cReturns[0] === 'B' && ($left['5'] ?? [0, 0, 0]) !== [0, 0, 0]) $r60cBad[] = "{$r60cTag} book rate after its return: " . json_encode($left['5']);
        }
        if (array_filter($r60cLeft($sourceId), static fn(array $part): bool => $part !== [0, 0, 0])) $r60cBad[] = "{$r60cTag} all returned: " . json_encode($r60cLeft($sourceId));
    }
    assert_equals([], $r60cBad, 'R60 VAT cents: over 32 price/discount/return-order cases no rate is credited past its net or VAT, and a fully credited rate keeps no cent (was: a cent more VAT)');
} catch (Throwable $e) {
    assert_true(false, 'R60 VAT cents (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
