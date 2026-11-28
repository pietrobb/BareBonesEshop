<?php

// Review round 62 (kolo 27), findings 5–8 and 13 (goal #661).
echo "\n--- R62 UX and DNS ---\n";
$pdo ??= test_commerce_fixture();
Bootstrap::initStoreContext('/', '', 'default');

// Finding 5: the timeline row of returns refunded by hand is translated and names the money and who recorded it.
// Was: "refund.manual_item_completed", 0,00 € by "admin".
$r62uLangs = [];
foreach (glob(dirname(__DIR__, 3) . '/lang/*.php') ?: [] as $r62uFile) {
    $r62uStrings = require $r62uFile;
    $r62uLangs[basename($r62uFile, '.php')] = [isset($r62uStrings['admin.audit.event.refund.manual_item_completed']), isset($r62uStrings['admin.rma.invoice_pending']), isset($r62uStrings['admin.shipping.methods_file_invalid'])];
}
assert_equals(array_fill_keys(array_keys($r62uLangs), [true, true, true]), $r62uLangs,
    'R62 i18n: every language names the returns-refunded-by-hand event, the pending invoice of a return and the unreadable shipping settings');
try {
    $r62uCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $r62uWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    foreach (['R62U-STD' => [96201, 'standard'], 'R62U-BOOK' => [96202, 'books']] as $r62uSku => [$r62uPid, $r62uClass]) {
        $pdo->prepare("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, vat_class)
            VALUES (?, 'default', ?, ?, ?, 'simple', ?, 1000, 'eur', 900, 'active', 'deny', ?)")->execute([$r62uPid, $r62uCategory, $r62uSku, strtolower($r62uSku), $r62uSku, $r62uClass]);
        Warehouse::setStock($r62uWarehouse, $r62uSku, 900, 'count', 'test', 'r62u-opening-' . $r62uSku);
    }
    $r62uLines = [['product_id' => 96201, 'variant_id' => null, 'sku' => 'R62U-STD', 'name' => 'A', 'qty' => 1, 'price' => 2000, 'attrs' => []],
        ['product_id' => 96202, 'variant_id' => null, 'sku' => 'R62U-BOOK', 'name' => 'B', 'qty' => 1, 'price' => 1000, 'attrs' => []]];
    $r62uSnap = [['product_id' => 96201, 'sku' => 'R62U-STD', 'qty' => 1, 'price' => 2000, 'product_type' => 'simple', 'vat_class' => 'standard'],
        ['product_id' => 96202, 'sku' => 'R62U-BOOK', 'qty' => 1, 'price' => 1000, 'product_type' => 'simple', 'vat_class' => 'books']];
    $r62uId = Orders::create($r62uLines, ['name' => 'R62U Buyer', 'email' => 'r62u@example.test', 'phone' => '', 'address' => ['country' => 'SK'],
        'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur'], 'bank_transfer');
    $pdo->prepare("UPDATE orders SET status = 'delivered', payment_status = 'paid', payment_id = '', vat_scenario = 'domestic', shipping_addr = '{\"country\":\"SK\"}', financial_snapshot = ? WHERE id = ?")
        ->execute([json_encode(VatService::buildSnapshot($r62uSnap, ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur', 'shipping' => 0])), $r62uId]);
    $r62uSource = (int)InvoiceService::issue($r62uId)['id'];
    PaymentService::completeManualRefund($r62uId, 1000, 'admin', 'R62 goodwill');
    $r62uChoice = [];
    foreach (Orders::items($r62uId) as $r62uItem) $r62uChoice[(int)$r62uItem['id']] = (int)$r62uItem['quantity'];
    [, $r62uRma] = Rma::create($r62uId, null, 'R62U return', $r62uChoice);
    Rma::setStatus((int)$r62uRma, 'approved');
    $r62uDecisions = [];
    foreach (Rma::items((int)$r62uRma) as $r62uRmaItem) $r62uDecisions[(int)$r62uRmaItem['id']] = ['quantity' => (int)$r62uRmaItem['quantity'], 'disposition' => 'restock'];
    Rma::receive((int)$r62uRma, $r62uDecisions, 'r62u-receipt');
    $r62uCredit = InvoiceService::credit($r62uSource, (int)$r62uRma, InvoiceService::creditableQuantities((int)$r62uRma), 'r62u-credit', false, true);
    $r62uByHand = 0;
    if (Rma::completeRefund((int)$r62uCredit['refund_id'], 'manual-admin')) {
        $r62uByHand = (int)(Rma::refundById((int)$r62uCredit['refund_id'])['amount'] ?? 0);
        PaymentService::settleManualItemRefund($r62uId, $r62uByHand, 'r62-merchant');
    }
    $r62uRow = $pdo->query("SELECT amount, raw_response FROM payment_transactions WHERE idempotency_key = 'manual_item_refunded_{$r62uId}'")->fetch(PDO::FETCH_ASSOC) ?: [];
    $r62uRaw = json_decode((string)($r62uRow['raw_response'] ?? ''), true) ?: [];
    assert_equals([2000, 'refunded', 0, 2000, 'r62-merchant'],
        [$r62uByHand, (string)Orders::byId($r62uId)['payment_status'], (int)($r62uRow['amount'] ?? -1), (int)($r62uRaw['refunded_by_hand'] ?? 0), (string)($r62uRaw['actor'] ?? '')],
        'R62 timeline: the returns refunded by hand record the 20 € paid and the admin who recorded it (was: 0,00 € by "admin")');
} catch (Throwable $e) {
    assert_true(false, 'R62 manual item refund (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
$r62uOrders = (string)file_get_contents(dirname(__DIR__, 3) . '/src/Entrypoints/Admin/Orders.php');
assert_true(str_contains($r62uOrders, "\$raw['refunded_by_hand']"), 'R62 timeline: the order page shows the money refunded by hand in place of the row\'s 0');

// Finding 13: before the worker issues the invoice, "Refunded in full" and "Refunded" are not offered (they failed: no
// source invoice); the return says it waits for the invoice.
assert_true(str_contains($r62uOrders, 'data-rma-invoice-pending') && str_contains($r62uOrders, "\$outside > 0 && !\$invoicePending")
    && str_contains($r62uOrders, "\$invoicePending ? ['refunded' => true] : []"),
    'R62 returns: no refund button before the order\'s invoice is issued, a note says it waits for it (was: "Refunded in full: 0 Ft" that failed)');

// Finding 6: shipping-import reads the unit of an imported delivery time. Was: "24h" 24 days, "2-3 weeks" 2-3 days.
$r62uDays = [];
foreach (['24h', '48 hours', '12-24 h', '2-3 weeks', '1 týždeň', '2-3 working days', '2-3 pracovné dni', '3 dní', '5 (EU)', '2 months', '24 hodín'] as $r62uText) {
    $r62uDays[$r62uText] = Shipping::normalizeDeliveryDays($r62uText);
}
assert_equals(['24h' => '1', '48 hours' => '2', '12-24 h' => '1', '2-3 weeks' => '14-21', '1 týždeň' => '7', '2-3 working days' => '2-3',
    '2-3 pracovné dni' => '2-3', '3 dní' => '3', '5 (EU)' => '5', '2 months' => null, '24 hodín' => '1'], $r62uDays,
    'R62 shipping-import: hours round up to days, weeks are 7 days, months are not taken (was: the number as days)');

// Finding 8: the doctor's "Fix" for an edited shipping.json or unreadable settings opens Admin → Shipping, which says
// what to do in every store (was: a HUF/CZK/PLN store showed only that Standard and Express are in EUR).
$r62uFile = (string)file_get_contents(dirname(__DIR__, 3) . '/src/Entrypoints/Admin/ShippingFile.php');
$r62uOther = strpos($r62uFile, 'data-shipping-file-other-currency');
$r62uNotices = (string)strstr($r62uFile, 'function shippingFileNotices');
assert_true($r62uOther !== false && (int)strpos($r62uFile, '<?php shippingFileNotices(); ?>') < $r62uOther
    && str_contains($r62uNotices, 'data-shipping-file-edited') && str_contains($r62uNotices, 'data-shipping-methods-file-invalid'),
    'R62 shipping: the edited shipping.json and the unreadable settings are explained before the other-currency note');

// Finding 7: BoundedDns.
$r62uStub = static function (string $mode): array {
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/fixtures/dns-stub.php', $mode, '6'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) return [null, null, []];
    $port = (int)trim((string)fgets($pipes[1]));
    return [$process, $port, $pipes];
};
$r62uStop = static function ($process, array $pipes): void {
    if (!is_resource($process)) return;
    proc_terminate($process);
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    proc_close($process);
};
foreach (['a-only' => ['203.0.113.7'], 'tc' => ['203.0.113.8']] as $r62uMode => $r62uExpect) {
    [$r62uProc, $r62uPort, $r62uPipes] = $r62uStub($r62uMode);
    try {
        if ($r62uPort < 1) {
            assert_true(false, "R62 DNS: the {$r62uMode} test name server started");
            continue;
        }
        BoundedDns::setNameserversForTesting(["127.0.0.1:{$r62uPort}"]);
        $r62uStart = microtime(true);
        $r62uGot = BoundedDns::resolve('r62-' . $r62uMode . '.example', $r62uStart + 3);
        $r62uTook = microtime(true) - $r62uStart;
        assert_true($r62uGot === $r62uExpect && $r62uTook < 2,
            $r62uMode === 'a-only'
                ? 'R62 DNS: a name server that drops AAAA gives the A address soon after it (' . round($r62uTook, 2) . ' s; was: no answer at the deadline, untested)'
                : 'R62 DNS: a truncated answer is asked again over TCP (' . json_encode($r62uGot) . '; was: the truncated part)');
    } finally {
        BoundedDns::setNameserversForTesting(null);
        $r62uStop($r62uProc, $r62uPipes);
    }
}
if (PHP_OS_FAMILY !== 'Windows') {
    $r62uSrc = dirname(__DIR__, 3) . '/src';
    $r62uCode = "require '{$r62uSrc}/BoundedDns.php'; \$s = microtime(true); \$r = BoundedDns::resolve('r62-limits.example', \$s + 3); echo json_encode([\$r, microtime(true) - \$s < 1]);";
    $r62uLimits = [];
    foreach (['disable_functions=stream_socket_client,stream_select,dns_get_record,gethostbynamel', "open_basedir={$r62uSrc}"] as $r62uIni) {
        $r62uOut = [];
        exec(escapeshellarg(PHP_BINARY) . ' -n -d ' . escapeshellarg($r62uIni) . ' -r ' . escapeshellarg($r62uCode) . ' 2>&1', $r62uOut, $r62uRc);
        $r62uLimits[explode('=', $r62uIni)[0]] = [$r62uRc, implode("\n", $r62uOut)];
    }
    assert_equals(['disable_functions' => [0, '[null,true]'], 'open_basedir' => [0, '[null,true]']], $r62uLimits,
        'R62 DNS: disabled socket functions or an open_basedir that hides resolv.conf leave the domain untested at once (was: a fatal error, or the unbounded system resolver)');
}
