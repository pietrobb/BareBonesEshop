<?php

// Review round 26 (R61), lows 10 and 13 (goal #655).
echo "\n--- R61 lows ---\n";
$pdo ??= test_commerce_fixture();
Bootstrap::initStoreContext('/', '', 'default');

// Low 10: the domain probe's DNS lookup ends at its deadline. Was: dns_get_record() waited as long as the system
// resolver did (12 s per save with a name server that did not answer, against the probe's 8 s).
$r61lSilent = @stream_socket_server('udp://127.0.0.1:0', $r61lErrno, $r61lError, STREAM_SERVER_BIND);
try {
    if ($r61lSilent === false) {
        assert_true(false, "R61 DNS: a local UDP socket for the test ({$r61lError})");
    } else {
        BoundedDns::setNameserversForTesting([stream_socket_get_name($r61lSilent, false)]);
        $r61lStart = microtime(true);
        $r61lAnswer = BoundedDns::resolve('r61-silent.example', $r61lStart + 0.8);
        $r61lTook = microtime(true) - $r61lStart;
        assert_true($r61lAnswer === null && $r61lTook >= 0.7 && $r61lTook < 1.6,
            'R61 DNS: a name server that does not answer ends the lookup at its deadline, untested (' . round($r61lTook, 2) . ' s; was: as long as the system resolver waited)');
        $r61lStart = microtime(true);
        assert_true(BoundedDns::resolve('r61-late.example', $r61lStart - 1) === null && microtime(true) - $r61lStart < 0.2,
            'R61 DNS: no time left, no lookup');
    }
    if (is_readable('/etc/hosts') && str_contains((string)file_get_contents('/etc/hosts'), 'localhost')) {
        assert_true(in_array('127.0.0.1', BoundedDns::resolve('localhost', microtime(true) + 1) ?? [], true) || in_array('::1', BoundedDns::resolve('localhost', microtime(true) + 1) ?? [], true),
            'R61 DNS: /etc/hosts answers first, as the system resolver does');
    }
} finally {
    BoundedDns::setNameserversForTesting(null);
    if (is_resource($r61lSilent)) fclose($r61lSilent);
}

try {
    $r61lCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $r61lWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $r61lProducts = ['R61L-STD' => [96101, 'standard'], 'R61L-RED' => [96102, 'reduced'], 'R61L-BOOK' => [96103, 'books']];
    foreach ($r61lProducts as $r61lSku => [$r61lPid, $r61lClass]) {
        $pdo->prepare("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, vat_class)
            VALUES (?, 'default', ?, ?, ?, 'simple', ?, 1000, 'eur', 900, 'active', 'deny', ?)")->execute([$r61lPid, $r61lCategory, $r61lSku, strtolower($r61lSku), $r61lSku, $r61lClass]);
        Warehouse::setStock($r61lWarehouse, $r61lSku, 900, 'count', 'test', 'r61l-opening-' . $r61lSku);
    }
    // A paid, invoiced bank-transfer order of $lines (name => [sku, quantity, unit price]) plus $shipping.
    $r61lOrder = static function (string $tag, array $lines, int $shipping = 0) use ($pdo, $r61lProducts): array {
        $order = $snap = [];
        foreach ($lines as $name => [$sku, $quantity, $price]) {
            $order[] = ['product_id' => $r61lProducts[$sku][0], 'variant_id' => null, 'sku' => $sku, 'name' => $name, 'qty' => $quantity, 'price' => $price, 'attrs' => []];
            $snap[] = ['product_id' => $r61lProducts[$sku][0], 'sku' => $sku, 'qty' => $quantity, 'price' => $price, 'product_type' => 'simple', 'vat_class' => $r61lProducts[$sku][1]];
        }
        $orderId = Orders::create($order, ['name' => 'R61L Buyer', 'email' => $tag . '@example.test', 'phone' => '', 'address' => ['country' => 'SK'],
            'shipping_cost' => $shipping, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur'], 'bank_transfer');
        $pdo->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid', payment_id = '', vat_scenario = 'domestic', shipping_addr = '{\"country\":\"SK\"}', financial_snapshot = ? WHERE id = ?")
            ->execute([json_encode(VatService::buildSnapshot($snap, ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur', 'shipping' => $shipping])), $orderId]);
        $invoice = InvoiceService::issue($orderId);
        $items = [];
        foreach (Orders::items($orderId) as $item) $items[(string)$item['name']] = [(int)$item['id'], (int)$item['quantity']];
        return [$orderId, (int)$invoice['id'], $items];
    };
    $r61lReceived = static function (int $orderId, array $items, array $names, string $key): int {
        $choice = [];
        foreach ($names as $name) $choice[$items[$name][0]] = $items[$name][1];
        [, $rmaId] = Rma::create($orderId, null, 'R61L return', $choice);
        Rma::setStatus((int)$rmaId, 'approved');
        $decisions = [];
        foreach (Rma::items((int)$rmaId) as $rmaItem) $decisions[(int)$rmaItem['id']] = ['quantity' => (int)$rmaItem['quantity'], 'disposition' => 'restock'];
        Rma::receive((int)$rmaId, $decisions, $key . '-receipt');
        return (int)$rmaId;
    };
    $r61lLeft = static function (int $sourceId) use ($pdo): array {
        $left = [];
        foreach ($pdo->query("SELECT vat_breakdown FROM invoices WHERE (id = {$sourceId} OR refers_to_invoice_id = {$sourceId}) AND status <> 'cancelled'")->fetchAll(PDO::FETCH_COLUMN) as $breakdown) {
            foreach (json_decode((string)$breakdown, true) ?: [] as $rate => $info) if (is_array($info)) $left[(string)$rate] = ($left[(string)$rate] ?? 0) + (int)$info['net'] + (int)$info['vat'];
        }
        krsort($left, SORT_NUMERIC);
        return $left;
    };

    // Low 13: "Refunded in full" names what it pays; a bank-transfer order whose money all went back by hand is refunded.
    // A 20 € (23 %) and B 10 € (5 %); 10 € refunded by hand outside the returns, then both returned and refunded in full
    // with that money kept (the admin's "Refunded in full" button): 30 € back in all.
    [$r61lId, $r61lSource, $r61lItems] = $r61lOrder('r61l-keep', ['A' => ['R61L-STD', 1, 2000], 'B' => ['R61L-BOOK', 1, 1000]]);
    $pdo->exec("UPDATE orders SET status = 'delivered' WHERE id = " . (int)$r61lId);
    PaymentService::completeManualRefund($r61lId, 1000, 'admin', 'R61 goodwill');
    $r61lBefore = (string)Orders::byId($r61lId)['payment_status'];
    $r61lRma = $r61lReceived($r61lId, $r61lItems, ['A', 'B'], 'r61l-keep');
    $r61lOffered = Rma::owedKeepingOutside($r61lRma, $r61lId);
    $r61lCredit = InvoiceService::credit($r61lSource, $r61lRma, InvoiceService::creditableQuantities($r61lRma), 'r61l-keep-credit', false, true);
    if (Rma::completeRefund((int)$r61lCredit['refund_id'], 'manual-admin')) PaymentService::settleManualItemRefund($r61lId);
    assert_equals([2000, 2000, 'partially_refunded', 'refunded', 0],
        [$r61lOffered, -(int)$r61lCredit['total_gross'], $r61lBefore, (string)Orders::byId($r61lId)['payment_status'], PaymentService::claimableRefund($r61lId)],
        'R61 keep: "Refunded in full" names the 20 € it pays; the order whose 30 € all went back by hand is refunded (was: no amount, partially_refunded)');

    // Low 13: the refund of the cancelled rest after an outside refund credits that refund's share of the goods' rate at
    // the other rates (R60); a later return then no longer reverses it at the cancelled goods' rate. D 3×3.33 € (23 %)
    // and C 2×8.99 € (5 %) shipped, B 12.50 € (19 %) cancelled, 3.90 € shipping (23 %); $dash refunded outside first,
    // then D and C returned. Every rate ends with what the customer keeps: the shipping less the outside money no return
    // took (was: 19 % kept up to 2.66 € uncredited for the cancelled B, C's credit was capped: the customer paid it).
    foreach ([400 => ['credit', 'credit'], 1010 => ['closed', 'credit'], 2962 => ['closed', 'closed']] as $r61lDash => $r61lHow) {
        [$r61lId, $r61lSource, $r61lItems] = $r61lOrder('r61l-rest-' . $r61lDash, ['D' => ['R61L-STD', 3, 333], 'B' => ['R61L-RED', 1, 1250], 'C' => ['R61L-BOOK', 2, 899]], 390);
        $r61lParcels = Fulfillment::planOrder($r61lId);
        $r61lParcel = (int)$pdo->query("SELECT shipment_id FROM shipment_items WHERE sku = 'R61L-STD' AND shipment_id IN (" . implode(',', array_map('intval', $r61lParcels) ?: [0]) . ')')->fetchColumn();
        $pdo->exec("DELETE FROM shipment_items WHERE sku = 'R61L-RED' AND shipment_id = {$r61lParcel}");
        Fulfillment::ship($r61lParcel);
        $pdo->prepare("INSERT INTO payment_transactions (order_id, provider, status, amount, currency, idempotency_key, store_id) VALUES (?, 'manual', 'partially_refunded', ?, 'eur', ?, 'default')")
            ->execute([$r61lId, $r61lDash, 'r61l-dash-' . $r61lDash]);
        InvoiceService::creditRefund($r61lId, $r61lDash, 'r61l-dash-' . $r61lDash);
        OrderWorkflow::transition($r61lId, 'cancelled', 'R61 cancelled rest');
        foreach ([['order_refund_request_' . $r61lId, 'refund_requested', '{"reason":"order.cancelled"}'], ['order_refund_' . $r61lId, 'partially_refunded', '{}']] as [$r61lKey, $r61lStatus, $r61lRaw]) {
            $pdo->prepare("DELETE FROM payment_transactions WHERE order_id = ? AND idempotency_key = ?")->execute([$r61lId, $r61lKey]);
            $pdo->prepare("INSERT INTO payment_transactions (order_id, provider, status, amount, currency, idempotency_key, store_id, raw_response) VALUES (?, 'manual', ?, 1250, 'eur', ?, 'default', ?)")
                ->execute([$r61lId, $r61lStatus, $r61lKey, $r61lRaw]);
        }
        $pdo->exec("UPDATE orders SET payment_status = 'partially_refunded' WHERE id = " . (int)$r61lId);
        InvoiceService::creditRefund($r61lId, 1250, 'order_refund_' . $r61lId);
        $r61lGot = [];
        $r61lBack = 0;
        foreach (['D', 'C'] as $r61lName) {
            $r61lRma = $r61lReceived($r61lId, $r61lItems, [$r61lName], 'r61l-rest-' . $r61lDash . $r61lName);
            $r61lOutside = Rma::refundedOutsideReturns($r61lId);
            $r61lQuantities = InvoiceService::creditableQuantities($r61lRma);
            if ($r61lOutside > 0 && InvoiceService::rmaCreditOwed($r61lSource, $r61lRma, $r61lQuantities, $r61lOutside)[1] === 0) {
                $r61lGot[] = Rma::closeWithoutPayout($r61lRma) ? 'closed' : 'refused';
                continue;
            }
            $r61lBack += -(int)InvoiceService::credit($r61lSource, $r61lRma, $r61lQuantities, 'r61l-rest-credit-' . $r61lDash . $r61lName, $r61lOutside > 0)['total_gross'];
            $r61lGot[] = 'credit';
        }
        assert_equals([$r61lHow, max(0, 999 + 1798 - $r61lDash), ['23' => 390 - max(0, $r61lDash - 999 - 1798), '19' => 0, '5' => 0]], [$r61lGot, $r61lBack, $r61lLeft($r61lSource)],
            "R61 cancelled rest after {$r61lDash} refunded outside: D and C pay the goods less that money, 19 % and 5 % end credited in full, 23 % keeps the shipping");
    }
} catch (Throwable $e) {
    assert_true(false, 'R61 lows money (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

// Low 13: a date format ending in a dot (hu "Y. m. d.") takes the sentence's full stop. Was: "2026. 11. 26.."
$r61lLegal = ['name' => 'R61 Kft.', 'email' => 'info@r61.example', 'phone' => '+36 1 000', 'updated_at' => '2026-11-26'];
$r61lDates = [];
foreach (['hu', 'sk', 'en'] as $r61lLang) {
    $r61lHtml = ConsumerNotice::renderLegal('privacy', $r61lLang, $r61lLegal, 'default');
    $r61lDates[$r61lLang] = [str_contains($r61lHtml, I18n::date('2026-11-26', $r61lLang)), preg_match('/\d\.\./', $r61lHtml) === 1];
}
assert_equals(['hu' => [true, false], 'sk' => [true, false], 'en' => [true, false]], $r61lDates,
    'R61 legal: the privacy policy\'s date ends its sentence with one full stop in every format (was: hu "2026. 11. 26..")');

// Low 13: the staging sweep removes only this user's private directories. Was: any bbe-backup-<hex> in the temp
// directory, so a shop run as root removed what another process staged there.
if (function_exists('posix_geteuid')) {
    $r61lLogDir = sys_get_temp_dir() . '/r61l-backup-log-' . bin2hex(random_bytes(6));
    $r61lCfg = new ReflectionProperty(BackupService::class, 'cfg');
    $r61lCfgBefore = $r61lCfg->getValue();
    $r61lCfg->setValue(null, ['log_file' => $r61lLogDir . '/backup.log'] + (array)$r61lCfgBefore);
    $r61lSweep = new ReflectionMethod(BackupService::class, 'sweepStaging');
    $r61lDirs = [];
    foreach (['private' => 0700, 'open' => 0777] as $r61lKind => $r61lMode) {
        $r61lDir = sys_get_temp_dir() . '/bbe-backup-' . bin2hex(random_bytes(16));
        mkdir($r61lDir, 0700);
        chmod($r61lDir, $r61lMode);
        file_put_contents($r61lDir . '/.owner', '');
        touch($r61lDir, time() - 300);
        $r61lDirs[$r61lKind] = $r61lDir;
    }
    $r61lSweep->invoke(null);
    $r61lKept = array_map('is_dir', $r61lDirs);
    foreach ($r61lDirs as $r61lDir) {
        if (!is_dir($r61lDir)) continue;
        @unlink($r61lDir . '/.owner');
        @rmdir($r61lDir);
    }
    $r61lCfg->setValue(null, $r61lCfgBefore);
    @unlink($r61lLogDir . '/backup.log');
    @rmdir($r61lLogDir);
    assert_equals(['private' => false, 'open' => true], $r61lKept,
        'R61 staging sweep: a leftover private staging directory is removed, one open to others is left alone (was: removed)');
}
