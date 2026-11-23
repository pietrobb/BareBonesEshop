<?php

// Review round 22 (R57), refund lows:
// - A refund credit note without "split" (all a release before R54 issued: split over the whole invoice) counted as the
//   unshipped goods' credit whenever those goods had its rates too (R56's reading of R54's notes); the cancelled rest's
//   refund then fell back to the invoice's rates and the shipped goods' return came short.
// - An incident code once critical stayed critical: a later warning of it (a refund declined after the money went back
//   another way, R56) was alerted as critical all the same.
echo "\n--- R57 refund lows: legacy credit split, incident severity ---\n";
$pdo ??= test_commerce_fixture();
Bootstrap::initStoreContext('/', '', 'default');

// --- A refund split over the invoice is not the unshipped goods' credit --------------------------------------------------------
try {
    $r57lCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $r57lWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $r57lSkus = ['R57L-STDA' => [95781, 'standard'], 'R57L-STDB' => [95782, 'standard'], 'R57L-BOOK1' => [95783, 'books'], 'R57L-BOOK2' => [95784, 'books'], 'R57L-BOOK3' => [95785, 'books']];
    foreach ($r57lSkus as $r57lSku => [$r57lPid, $r57lClass]) {
        $pdo->prepare("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, vat_class)
            VALUES (?, 'default', ?, ?, ?, 'simple', ?, 1000, 'eur', 5, 'active', 'deny', ?)")->execute([$r57lPid, $r57lCategory, $r57lSku, strtolower($r57lSku), $r57lSku, $r57lClass]);
        Warehouse::setStock($r57lWarehouse, $r57lSku, 5, 'count', 'test', 'r57l-opening-' . $r57lSku);
    }
    $r57lLines = $r57lSnapLines = [];
    foreach ($r57lSkus as $r57lSku => [$r57lPid, $r57lClass]) {
        $r57lLines[] = ['product_id' => $r57lPid, 'variant_id' => null, 'sku' => $r57lSku, 'name' => $r57lSku, 'qty' => 1, 'price' => 1000, 'attrs' => []];
        $r57lSnapLines[] = ['product_id' => $r57lPid, 'sku' => $r57lSku, 'qty' => 1, 'price' => 1000, 'product_type' => 'simple', 'vat_class' => $r57lClass];
    }
    $r57lOrder = Orders::create($r57lLines, ['name' => 'R57L Buyer', 'email' => 'r57l@example.test', 'phone' => '', 'address' => ['country' => 'SK'],
        'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur'], 'bank_transfer');
    $pdo->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid', vat_scenario = 'domestic', shipping_addr = '{\"country\":\"SK\"}', financial_snapshot = ? WHERE id = ?")
        ->execute([json_encode(VatService::buildSnapshot($r57lSnapLines, ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur'])), $r57lOrder]);
    $r57lInvoice = InvoiceService::issue($r57lOrder);
    $r57lStdKey = $r57lBookKey = '';
    foreach (json_decode((string)$r57lInvoice['vat_breakdown'], true) as $r57lKey => $r57lRate) {
        if ((int)$r57lKey === 23) $r57lStdKey = (string)$r57lKey; else $r57lBookKey = (string)$r57lKey;
    }
    $r57lRates = static function (?array $credit): array {
        $rates = array_map(static fn(array $rate): int => -(int)($rate['net'] ?? 0) - (int)($rate['vat'] ?? 0), (array)json_decode((string)($credit['vat_breakdown'] ?? '[]'), true));
        ksort($rates);
        return $rates;
    };
    // A and B at 23 %, three books at 5 %. 5 € refunded in the dashboard while the order was paid: split over the
    // invoice (2 € + 3 €), as a release before R54 wrote it (no split named).
    $pdo->prepare("INSERT INTO payment_transactions (order_id, provider, status, amount, currency, idempotency_key, store_id) VALUES (?, 'manual', 'partially_refunded', 500, 'eur', 'r57l-dashboard', 'default')")->execute([$r57lOrder]);
    $r57lDashboard = InvoiceService::creditRefund($r57lOrder, 500, 'r57l-dashboard');
    $r57lExpected = [$r57lStdKey => 200, $r57lBookKey => 300];
    ksort($r57lExpected);
    assert_equals($r57lExpected, $r57lRates($r57lDashboard), 'R57 legacy split: the dashboard refund is split over the invoice');
    $r57lLegacy = json_decode((string)$r57lDashboard['credit_snapshot'], true);
    unset($r57lLegacy['split']);
    $pdo->prepare('UPDATE invoices SET credit_snapshot = ? WHERE id = ?')->execute([json_encode($r57lLegacy), (int)$r57lDashboard['id']]);
    // A and one book ship; the rest (B at 23 %, two books at 5 %: 1:2, the invoice 2:3) is cancelled and refunded.
    $r57lParcels = array_map('intval', Fulfillment::planOrder($r57lOrder)) ?: [0];
    $r57lIn = implode(',', $r57lParcels);
    $r57lParcel = (int)$pdo->query("SELECT shipment_id FROM shipment_items WHERE sku = 'R57L-STDA' AND shipment_id IN ({$r57lIn})")->fetchColumn();
    $pdo->exec("DELETE FROM shipment_items WHERE sku NOT IN ('R57L-STDA', 'R57L-BOOK1') AND shipment_id IN ({$r57lIn})");
    $pdo->exec("UPDATE shipment_items SET shipment_id = {$r57lParcel} WHERE sku = 'R57L-BOOK1' AND shipment_id IN ({$r57lIn})");
    Fulfillment::ship($r57lParcel);
    OrderWorkflow::transition($r57lOrder, 'cancelled', 'R57 cancelled rest');
    $r57lCancel = InvoiceService::creditRefund($r57lOrder, 3000, 'order_refund_' . $r57lOrder);
    $r57lExpected = [$r57lStdKey => 1000, $r57lBookKey => 2000];
    ksort($r57lExpected);
    assert_equals($r57lExpected, $r57lRates($r57lCancel),
        'R57 legacy split: the cancelled rest\'s refund credits B and the two books at their own rates (was: the invoice-split refund counted as theirs, 12 € + 18 €)');
} catch (Throwable $e) {
    assert_true(false, 'R57 legacy split (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

// --- An alerted critical does not make the code's later warnings critical ------------------------------------------------------
$r57iMonitorConfig = new ReflectionProperty(IncidentMonitor::class, 'config');
$r57iJobsDir = new ReflectionProperty(JobQueue::class, 'jobsDir');
$r57iOldMonitor = $r57iMonitorConfig->getValue();
$r57iOldJobs = $r57iJobsDir->getValue();
try {
    $r57iRoot = test_fixture_path('r57-incident-severity');
    @mkdir($r57iRoot . '/jobs', 0700, true);
    $r57iMonitorConfig->setValue(null, ['incident_alerts' => ['enabled' => true, 'window_seconds' => 900, 'cooldown_seconds' => 900]]);
    JobQueue::setDir($r57iRoot . '/jobs');
    $r57iFingerprint = hash('sha256', 'default|r57.refund_failed|payment');
    $r57iSeverity = static fn(): string => (string)$pdo->query('SELECT severity FROM operational_alert_state WHERE fingerprint = ' . $pdo->quote($r57iFingerprint))->fetchColumn();
    IncidentMonitor::report('r57.refund_failed', 'payment', 'critical', ['order_id' => 1]);
    IncidentMonitor::report('r57.refund_failed', 'payment', 'warning', ['order_id' => 2]);
    assert_equals('critical', $r57iSeverity(), 'R57 incident: a warning after a critical not alerted yet keeps it critical');
    // The alert went out (IncidentMonitor::notify() counts what it reported).
    $pdo->prepare('UPDATE operational_alert_state SET notified_count = occurrences, notification_status = \'accepted\' WHERE fingerprint = ?')->execute([$r57iFingerprint]);
    IncidentMonitor::report('r57.refund_failed', 'payment', 'warning', ['order_id' => 3]);
    assert_equals('warning', $r57iSeverity(), 'R57 incident: a warning after the critical was alerted is a warning (was: critical for good, alerted again)');
    IncidentMonitor::report('r57.refund_failed', 'payment', 'critical', ['order_id' => 4]);
    assert_equals('critical', $r57iSeverity(), 'R57 incident: a new critical is critical again');
} catch (Throwable $e) {
    assert_true(false, 'R57 incident severity (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r57iMonitorConfig->setValue(null, $r57iOldMonitor);
    $r57iJobsDir->setValue(null, $r57iOldJobs);
}
