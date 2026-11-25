<?php

// Review round 24 (R59), finding 5 + credit note lows. The credit note of a return closed without payout (R58) printed
// the reversal of the outside money's credit at the other rates under the returned goods' names ("Product A, 5 %,
// +6.67" for goods sold at 23 %); it now names the credit notes it corrects and the rate. Its PDF was written inside the
// closing's transaction (a rollback left a PDF whose number the next credit note took); a rate fully credited could keep
// a cent of net with nothing of gross; a refund credit note re-rendered (lost file, restore) had no rows; a Comgate 4xx
// carrying an error code (1100, 1200, 1500) was a declined refund.
echo "\n--- R59 credit documents: correction lines, PDF after commit, net rest, refund rows, Comgate 4xx codes ---\n";
$pdo ??= test_commerce_fixture();
Bootstrap::initStoreContext('/', '', 'default');
try {
    $r59dCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $r59dWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $r59dProducts = ['R59D-STD' => [95911, 'standard'], 'R59D-RED' => [95912, 'reduced'], 'R59D-BOOK' => [95913, 'books']];
    foreach ($r59dProducts as $r59dSku => [$r59dPid, $r59dClass]) {
        $pdo->prepare("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, vat_class)
            VALUES (?, 'default', ?, ?, ?, 'simple', ?, 1000, 'eur', 9000, 'active', 'deny', ?)")->execute([$r59dPid, $r59dCategory, $r59dSku, strtolower($r59dSku), $r59dSku, $r59dClass]);
        Warehouse::setStock($r59dWarehouse, $r59dSku, 9000, 'count', 'test', 'r59d-opening-' . $r59dSku);
    }
    $r59dOrder = static function (string $tag, array $lines) use ($pdo, $r59dProducts): array {
        $order = $snap = [];
        foreach ($lines as $name => [$sku, $price]) {
            $order[] = ['product_id' => $r59dProducts[$sku][0], 'variant_id' => null, 'sku' => $sku, 'name' => $name, 'qty' => 1, 'price' => $price, 'attrs' => []];
            $snap[] = ['product_id' => $r59dProducts[$sku][0], 'sku' => $sku, 'qty' => 1, 'price' => $price, 'product_type' => 'simple', 'vat_class' => $r59dProducts[$sku][1]];
        }
        $orderId = Orders::create($order, ['name' => 'R59D Buyer', 'email' => $tag . '@example.test', 'phone' => '', 'address' => ['country' => 'SK'],
            'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur'], 'bank_transfer');
        $pdo->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid', payment_id = '', locale = 'en', vat_scenario = 'domestic', shipping_addr = '{\"country\":\"SK\"}', financial_snapshot = ? WHERE id = ?")
            ->execute([json_encode(VatService::buildSnapshot($snap, ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur'])), $orderId]);
        $invoice = InvoiceService::issue($orderId);
        $pdo->exec("UPDATE orders SET status = 'delivered' WHERE id = " . (int)$orderId);
        $items = [];
        foreach (Orders::items($orderId) as $item) $items[(string)$item['name']] = (int)$item['id'];
        return [$orderId, (int)$invoice['id'], $items];
    };
    $r59dOutside = static function (int $orderId, int $amount, string $key) use ($pdo): ?array {
        $pdo->prepare("INSERT INTO payment_transactions (order_id, provider, status, amount, currency, idempotency_key, store_id) VALUES (?, 'manual', 'partially_refunded', ?, 'eur', ?, 'default')")
            ->execute([$orderId, $amount, $key]);
        return InvoiceService::creditRefund($orderId, $amount, $key);
    };
    // A received return of $names; [return id, whether the money refunded outside covers it].
    $r59dReceived = static function (int $orderId, int $sourceId, array $items, array $names, string $key): array {
        $choice = [];
        foreach ($names as $name) $choice[$items[$name]] = 1;
        [, $rmaId] = Rma::create($orderId, null, 'R59D return', $choice);
        Rma::setStatus((int)$rmaId, 'approved');
        $decisions = [];
        foreach (Rma::items((int)$rmaId) as $rmaItem) $decisions[(int)$rmaItem['id']] = ['quantity' => 1, 'disposition' => 'restock'];
        Rma::receive((int)$rmaId, $decisions, $key . '-receipt');
        $outside = Rma::refundedOutsideReturns($orderId);
        $covered = $outside > 0 && InvoiceService::rmaCreditOwed($sourceId, (int)$rmaId, InvoiceService::creditableQuantities((int)$rmaId), $outside)[1] === 0;
        return [(int)$rmaId, $covered];
    };
    $r59dReturn = static function (int $orderId, int $sourceId, array $items, array $names, string $key) use ($r59dReceived): int {
        [$rmaId, $covered] = $r59dReceived($orderId, $sourceId, $items, $names, $key);
        if ($covered) return Rma::closeWithoutPayout($rmaId) ? 0 : -1;
        $credit = InvoiceService::credit($sourceId, $rmaId, InvoiceService::creditableQuantities($rmaId), $key . '-credit', Rma::refundedOutsideReturns($orderId) > 0);
        return -(int)$credit['total_gross'];
    };
    $r59dBalance = static fn(int $orderId): array => $pdo->query("SELECT * FROM invoices WHERE order_id = {$orderId} AND type = 'credit_note' AND credit_snapshot LIKE '%\"kind\":\"outside_balance\"%'")->fetchAll(PDO::FETCH_ASSOC);
    $r59dRuns = static function (string $path): array {
        preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\) Tj/', (string)@file_get_contents($path), $runs);
        return array_map(static fn(string $run): string => (string)preg_replace('/\\\\([()\\\\])/', '$1', $run), $runs[1]);
    };
    $r59dLabel = (new ReflectionMethod(InvoiceService::class, 'loadInvoiceLabels'))->invoke(null, 'en')['credit_correction'] ?? '?';

    // The review's case: A 20 € at 23 %, B 10 € at 5 %, 20 € refunded in the dashboard (13.33 € at 23 %, 6.67 € at 5 %).
    [$r59dId, $r59dSource, $r59dItems] = $r59dOrder('r59d-closed', ['Product A' => ['R59D-STD', 2000], 'Product B' => ['R59D-BOOK', 1000]]);
    $r59dDashboard = $r59dOutside($r59dId, 2000, 'r59d-closed-dashboard');
    [$r59dRma, $r59dCovered] = $r59dReceived($r59dId, $r59dSource, $r59dItems, ['Product A'], 'r59d-closed-a');

    // Closed inside a transaction that is rolled back: no PDF stays behind for the number the next note takes.
    Db::get()->beginTransaction();
    $r59dClosedInside = Rma::closeWithoutPayout($r59dRma);
    $r59dRolledPath = InvoiceService::pdfAbsolutePath($r59dBalance($r59dId)[0] ?? ['pdf_path' => 'none.pdf']);
    Db::get()->rollBack();
    assert_equals([true, true, false, []], [$r59dCovered, $r59dClosedInside, is_file($r59dRolledPath), $r59dBalance($r59dId)],
        'R59 low: a closing rolled back leaves no PDF of its credit note (was: written in the transaction, kept with no document)');

    // Closed for real: the PDF is written after the commit, the reversing line names the corrected credit note and rate.
    $r59dClosed = Rma::closeWithoutPayout($r59dRma);
    $r59dNote = $r59dBalance($r59dId)[0] ?? [];
    $r59dLines = $pdo->query('SELECT name, gross FROM credit_note_items WHERE credit_note_id = ' . (int)($r59dNote['id'] ?? 0) . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $r59dExpected = $r59dLabel . ' ' . ($r59dDashboard['number'] ?? '?') . ' (5%)';
    $r59dByName = [];
    foreach ($r59dLines as $r59dLine) $r59dByName[(string)$r59dLine['name']] = ($r59dByName[(string)$r59dLine['name']] ?? 0) + (int)$r59dLine['gross'];
    $r59dPath = InvoiceService::pdfAbsolutePath($r59dNote + ['pdf_path' => 'none.pdf']);
    $r59dSuperseded = glob(substr($r59dPath, 0, -4) . '.superseded-*.pdf') ?: [];
    assert_equals([true, ['Product A' => 667, $r59dExpected => -667], true, 1, []],
        [$r59dClosed, $r59dByName, is_file($r59dPath), substr_count(implode(' ', $r59dRuns($r59dPath)), $r59dExpected), $r59dSuperseded],
        'R59 finding 5: the credit note credits Product A 6.67 € and names the reversal "' . $r59dExpected . '", one PDF row (was: "Product A" at 5 %)');

    // The dashboard refund's credit note re-rendered (lost file, restore) prints its rows as issued (was: none).
    $r59dRefundPath = InvoiceService::pdfAbsolutePath($r59dDashboard ?? ['pdf_path' => 'none.pdf']);
    $r59dIssuedRuns = $r59dRuns($r59dRefundPath);
    @unlink($r59dRefundPath);
    InvoiceService::regeneratePdf((int)($r59dDashboard['id'] ?? 0));
    $r59dRefundLabel = (new ReflectionMethod(InvoiceService::class, 'loadInvoiceLabels'))->invoke(null, 'en')['refund'] ?? '?';
    assert_equals([2, $r59dIssuedRuns], [count(array_keys($r59dRuns($r59dRefundPath), $r59dRefundLabel, true)), $r59dRuns($r59dRefundPath)],
        'R59 low: a refund credit note re-rendered prints its two "Refund" rows, as issued (was: no rows)');

    // Property: whenever a rate has nothing of gross left uncredited, it has nothing of net either.
    $r59dRun = 0;
    $r59dPrices = ['A' => ['R59D-STD', 1999], 'B' => ['R59D-BOOK', 1337], 'C' => ['R59D-RED', 2471]];
    $r59dDrift = [];
    foreach ([333, 1001, 2777, 4999] as $r59dAmount) {
        foreach ([['A'], ['B'], ['A', 'B'], ['B', 'A'], ['C', 'A'], ['A', 'B', 'C'], ['C', 'B', 'A']] as $r59dSequence) {
            $r59dTag = 'r59d-p' . (++$r59dRun);
            [$r59dId, $r59dSource, $r59dItems] = $r59dOrder($r59dTag, $r59dPrices);
            $r59dOutside($r59dId, $r59dAmount, $r59dTag . '-dashboard');
            foreach ($r59dSequence as $r59dN => $r59dName) $r59dReturn($r59dId, $r59dSource, $r59dItems, [$r59dName], $r59dTag . '-' . $r59dN);
            $r59dLeft = [];
            foreach ($pdo->query("SELECT vat_breakdown FROM invoices WHERE (id = {$r59dSource} OR refers_to_invoice_id = {$r59dSource}) AND status <> 'cancelled'")->fetchAll(PDO::FETCH_COLUMN) as $r59dBreakdown) {
                foreach (json_decode((string)$r59dBreakdown, true) ?: [] as $r59dRate => $r59dInfo) {
                    if (!is_array($r59dInfo)) continue;
                    $r59dLeft[(string)$r59dRate]['gross'] = ($r59dLeft[(string)$r59dRate]['gross'] ?? 0) + (int)$r59dInfo['net'] + (int)$r59dInfo['vat'];
                    $r59dLeft[(string)$r59dRate]['net'] = ($r59dLeft[(string)$r59dRate]['net'] ?? 0) + (int)$r59dInfo['net'];
                }
            }
            foreach ($r59dLeft as $r59dRate => $r59dRest) {
                if ($r59dRest['gross'] === 0 && $r59dRest['net'] !== 0) $r59dDrift[] = "{$r59dAmount} " . implode('>', $r59dSequence) . " at {$r59dRate}: net {$r59dRest['net']}";
            }
        }
    }
    assert_equals([], $r59dDrift, 'R59 low: a rate credited in full keeps no net (was: ±1 cent of net with gross 0)');
} catch (Throwable $e) {
    assert_true(false, 'R59 credit documents (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

// --- Comgate: a 4xx naming an error code is not a declined refund --------------------------------------------------------------
try {
    require_once dirname(TEST_CLI_DIR) . '/adapters/PaymentAdapter.php';
    require_once dirname(TEST_CLI_DIR) . '/adapters/contrib/ComgatePayment.php';
    $r59dAnswer = [];
    $r59dComgate = new ComgatePayment(['comgate_merchant' => 'm', 'comgate_secret' => 's'],
        static function (string $method, string $url, array $headers, ?string $body) use (&$r59dAnswer): array { return $r59dAnswer; });
    $r59dOutcome = static function (int $status, ?int $code) use (&$r59dAnswer, $r59dComgate): string {
        $r59dAnswer = ['status' => $status, 'body' => json_encode($code === null ? ['message' => 'refused'] : ['code' => $code, 'message' => 'x'])];
        try {
            return (string)($r59dComgate->refund('R59D-AB12-CD34', 500)['status'] ?? '');
        } catch (RuntimeException) {
            return 'error';
        }
    };
    assert_equals(['error', 'error', 'error', 'failed', 'failed', 'failed'],
        [$r59dOutcome(400, 1100), $r59dOutcome(400, 1200), $r59dOutcome(422, 1500), $r59dOutcome(400, 1400), $r59dOutcome(400, 1402), $r59dOutcome(400, null)],
        'R59 low: Comgate HTTP 4xx with code 1100/1200/1500 is an error to resolve, 1400-1402 or no code a refused refund (was: all failed, released as declined)');
} catch (Throwable $e) {
    assert_true(false, 'R59 Comgate 4xx (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
