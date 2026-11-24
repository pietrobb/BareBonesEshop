<?php

// Review round 23 (R58), refund lows:
// - An item refund the provider declined or released after the payment was refunded in full (the merchant refunded
//   everything in the provider's dashboard before the job ran) raised a critical payment.refund_failed although the
//   customer had all the money; a refund request got a warning in that case since R56.
// - A critical incident held back by the cooldown was dropped: a warning of the same code reported after the window
//   rolled over (before a cron sweep) made the code a warning and reset its counts, so the critical was never e-mailed.
// - Comgate: any result code but 0 on HTTP 200 was a failed refund, which the shop releases as declined (the payment
//   back to paid, the merchant invited to refund again); a server error (1100, 1200, 1500) says nothing about whether
//   Comgate refunded, so a second refund could pay the customer twice.
echo "\n--- R58 refund lows: item refund severity, pending critical alert, Comgate refund codes ---\n";
$pdo ??= test_commerce_fixture();
Bootstrap::initStoreContext('/', '', 'default');
require_once dirname(TEST_CLI_DIR) . '/adapters/PaymentAdapter.php';
require_once dirname(TEST_CLI_DIR) . '/adapters/contrib/ComgatePayment.php';

$r58lInstances = new ReflectionProperty(Adapters::class, 'instances');
$r58lSaved = $r58lInstances->getValue();
$r58lJobsDir = new ReflectionProperty(JobQueue::class, 'jobsDir');
$r58lOldJobs = $r58lJobsDir->getValue();
$r58lRoot = test_fixture_path('r58-refund-lows');
@mkdir($r58lRoot . '/jobs', 0700, true);
JobQueue::setDir($r58lRoot . '/jobs');

// A gateway without idempotent refunds whose refund call is declined ('declined': PaymentRefundDeclined) or answered failed.
$r58lGateway = new class implements PaymentAdapter {
    public string $answer = 'declined';
    public int $calls = 0;
    public function createSession(array $order): array { return []; }
    public function handleWebhook(array $payload): array { return []; }
    public function getStatus(string $paymentId): string { return 'paid'; }
    public function refund(string $paymentId, int $amount): array {
        $this->calls++;
        if ($this->answer === 'declined') throw new PaymentRefundDeclined('refund refused: payment refunded');
        return ['refund_id' => '', 'status' => 'failed'];
    }
};

try {
    $r58lCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $r58lWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, vat_class)
        VALUES (95811, 'default', {$r58lCategory}, 'R58L-A', 'r58l-a', 'simple', 'R58L lamp', 1000, 'eur', 500, 'active', 'deny', 'standard')");
    Warehouse::setStock($r58lWarehouse, 'R58L-A', 500, 'count', 'test', 'r58l-opening');

    // A paid, delivered and invoiced order of three lamps (30 €) whose one returned lamp is credited: the item refund's
    // job payload. The payment then refunded in full in the provider's dashboard, or not.
    $r58lItemRefund = static function (string $tag, bool $dashboardRefundedAll) use ($pdo): array {
        $orderId = Orders::create([['product_id' => 95811, 'variant_id' => null, 'sku' => 'R58L-A', 'name' => 'R58L lamp', 'qty' => 3, 'price' => 1000, 'attrs' => []]],
            ['name' => 'R58L Buyer', 'email' => $tag . '@example.test', 'phone' => '', 'address' => ['country' => 'SK'], 'shipping_cost' => 0, 'tax_amount' => 0,
                'coupon_discount' => 0, 'currency' => 'eur'], 'bank_transfer');
        $snapshot = VatService::buildSnapshot([['product_id' => 95811, 'sku' => 'R58L-A', 'qty' => 3, 'price' => 1000, 'product_type' => 'simple', 'vat_class' => 'standard']],
            ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur']);
        $pdo->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid', payment_method = 'r58l', payment_id = ?, vat_scenario = 'domestic', shipping_addr = '{\"country\":\"SK\"}', financial_snapshot = ? WHERE id = ?")
            ->execute([$tag . '-pay', json_encode($snapshot), $orderId]);
        $pdo->prepare('DELETE FROM operational_incidents WHERE context_json = ? OR context_json LIKE ?')->execute(['{"order_id":' . $orderId . '}', '{"order_id":' . $orderId . ',%']);
        $source = InvoiceService::issue($orderId);
        $pdo->exec("UPDATE orders SET status = 'delivered' WHERE id = " . (int)$orderId);
        $item = Orders::items($orderId)[0];
        [, $rmaId] = Rma::create($orderId, null, 'R58L return', [(int)$item['id'] => 1]);
        Rma::setStatus((int)$rmaId, 'approved');
        Rma::receive((int)$rmaId, [(int)Rma::items((int)$rmaId)[0]['id'] => ['quantity' => 1, 'disposition' => 'restock']], $tag . '-receipt');
        $credit = InvoiceService::credit((int)$source['id'], (int)$rmaId, InvoiceService::creditableQuantities((int)$rmaId), $tag . '-credit');
        if ($dashboardRefundedAll) {
            $pdo->prepare("INSERT INTO payment_transactions (order_id, provider, provider_ref, status, amount, currency, idempotency_key, store_id) VALUES (?, 'r58l', ?, 'refunded', 3000, 'eur', ?, 'default')")
                ->execute([$orderId, $tag . '-pay', $tag . '-dashboard']);
            $pdo->prepare("UPDATE orders SET payment_status = 'refunded' WHERE id = ?")->execute([$orderId]);
        }
        return [$orderId, ['order_id' => $orderId, 'payment_id' => $tag . '-pay', 'amount' => 1000, 'refund_id' => (int)$credit['refund_id'], 'idempotency_key' => $tag . '-credit']];
    };
    $r58lSeverities = static function (int $orderId) use ($pdo): array {
        $stmt = $pdo->prepare("SELECT severity FROM operational_incidents WHERE code = 'payment.refund_failed' AND (context_json = ? OR context_json LIKE ?)");
        $stmt->execute(['{"order_id":' . $orderId . '}', '{"order_id":' . $orderId . ',%']);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    };
    $r58lInstances->setValue(null, ['payment' => $r58lGateway] + $r58lSaved);
    foreach (['declined' => 'declined (PaymentRefundDeclined)', 'failed' => 'answered failed (released)'] as $r58lAnswer => $r58lLabel) {
        $r58lGateway->answer = $r58lAnswer;
        foreach ([true => 'warning', false => 'critical'] as $r58lAll => $r58lSeverity) {
            [$r58lOrder, $r58lPayload] = $r58lItemRefund("r58l-{$r58lAnswer}-" . ($r58lAll ? 'all' : 'part'), (bool)$r58lAll);
            try {
                WorkerJobs::handlePaymentRefund($r58lPayload, ['attempts' => 1, 'max_attempts' => 3]);
            } catch (Throwable $e) {
                // A failed job is retried; the incident is what this checks.
            }
            assert_equals([(string)(Rma::refundById($r58lPayload['refund_id'])['status'] ?? ''), [$r58lSeverity]], ['failed', $r58lSeverities($r58lOrder)],
                $r58lAll ? "R58 low: an item refund {$r58lLabel} after the payment was refunded in full is a warning (was: critical, the customer had all the money)"
                    : "R58 low: an item refund {$r58lLabel} while money is owed stays critical");
        }
    }
} catch (Throwable $e) {
    assert_true(false, 'R58 item refund severity (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r58lInstances->setValue(null, $r58lSaved);
}

// --- A critical held back by the cooldown is alerted, whatever follows it ---------------------------------------------------
$r58iMonitorConfig = new ReflectionProperty(IncidentMonitor::class, 'config');
$r58iOldMonitor = $r58iMonitorConfig->getValue();
try {
    $r58iMonitorConfig->setValue(null, ['incident_alerts' => ['enabled' => true, 'window_seconds' => 900, 'cooldown_seconds' => 900]]);
    $r58iFingerprint = hash('sha256', 'default|r58.refund_failed|payment');
    $r58iRow = static fn(): array => $pdo->query('SELECT severity, occurrences, notified_count FROM operational_alert_state WHERE fingerprint = ' . $pdo->quote($r58iFingerprint))->fetch(PDO::FETCH_ASSOC) ?: [];
    $r58iQueued = static fn(): int => count(glob($r58lRoot . '/jobs/pending/*.json') ?: []);
    foreach (glob($r58lRoot . '/jobs/pending/*.json') ?: [] as $r58iFile) unlink($r58iFile);
    // Critical A, alerted (the e-mail went out).
    IncidentMonitor::report('r58.refund_failed', 'payment', 'critical', ['order_id' => 1]);
    $pdo->prepare("UPDATE operational_alert_state SET notified_count = occurrences, notification_status = 'accepted' WHERE fingerprint = ?")->execute([$r58iFingerprint]);
    foreach (glob($r58lRoot . '/jobs/pending/*.json') ?: [] as $r58iFile) unlink($r58iFile);
    // Critical B within the cooldown: not alerted yet.
    IncidentMonitor::report('r58.refund_failed', 'payment', 'critical', ['order_id' => 2]);
    assert_equals(0, $r58iQueued(), 'R58 incident: a second critical within the cooldown waits');
    // 905 s later the window and the cooldown have passed; a warning of the code comes before the cron sweep.
    $pdo->prepare('UPDATE operational_alert_state SET window_started_at = window_started_at - 905, last_queued_at = last_queued_at - 905 WHERE fingerprint = ?')->execute([$r58iFingerprint]);
    IncidentMonitor::report('r58.refund_failed', 'payment', 'warning', ['order_id' => 3]);
    IncidentMonitor::sweep();
    assert_equals([['severity' => 'critical', 'occurrences' => 2, 'notified_count' => 0], 1],
        [array_map(static fn($v) => is_numeric($v) ? (int)$v : $v, $r58iRow()), $r58iQueued()],
        'R58 incident: the pending critical B stays critical across the window and is alerted with the warning (was: a warning, counts reset, B never e-mailed)');
    // Alerted; a warning of the next window is a warning again (R57).
    $pdo->prepare("UPDATE operational_alert_state SET notified_count = occurrences, notification_status = 'accepted', window_started_at = window_started_at - 905 WHERE fingerprint = ?")->execute([$r58iFingerprint]);
    IncidentMonitor::report('r58.refund_failed', 'payment', 'warning', ['order_id' => 4]);
    assert_equals('warning', (string)($r58iRow()['severity'] ?? ''), 'R58 incident: once alerted, a later warning of the code is a warning');
} catch (Throwable $e) {
    assert_true(false, 'R58 incident (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r58iMonitorConfig->setValue(null, $r58iOldMonitor);
}

// --- Comgate: only a refused request is a failed refund ---------------------------------------------------------------------------
try {
    $r58gAnswer = ['status' => 200, 'body' => '{"code":0}'];
    $r58gCalls = 0;
    $r58gComgate = new ComgatePayment(['comgate_merchant' => 'm', 'comgate_secret' => 's'],
        static function (string $method, string $url, array $headers, ?string $body) use (&$r58gAnswer, &$r58gCalls): array {
            if (str_ends_with($url, '/v2.0/refund.json')) { $r58gCalls++; return $r58gAnswer; }
            // Comgate reports no refunds: a payment refunded in its portal still reads PAID.
            preg_match('#/transId/([^/]+)\.json$#', $url, $m);
            return ['status' => 200, 'body' => json_encode(['code' => 0, 'transId' => $m[1] ?? '', 'status' => 'PAID', 'price' => '3000', 'curr' => 'EUR', 'refId' => 'R58G-' . ($m[1] ?? ''), 'test' => 'false'])];
        });
    $r58gOutcome = static function (int $code) use (&$r58gAnswer, $r58gComgate): string {
        $r58gAnswer = ['status' => 200, 'body' => json_encode(['code' => $code, 'message' => 'r58'])];
        try {
            return (string)($r58gComgate->refund('R58G-AB12-CD34', 500)['status'] ?? '');
        } catch (RuntimeException $e) {
            return 'error';
        }
    };
    assert_equals(['succeeded', 'failed', 'failed', 'failed', 'error', 'error', 'error', 'error'],
        [$r58gOutcome(0), $r58gOutcome(1400), $r58gOutcome(1401), $r58gOutcome(1402), $r58gOutcome(1100), $r58gOutcome(1200), $r58gOutcome(1500), $r58gOutcome(1399)],
        'R58 Comgate: code 0 refunded, 1400-1402 refused (failed), a server error or an unknown code is an error to resolve (was: every code but 0 failed)');

    // The refund job of an admin's refund request answered 1500: not released as declined (the payment back to paid);
    // its retry does not call Comgate again and asks the merchant to confirm or release it.
    $pdo->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', ?, 'R58G', 'r58g', 'R58G', 1000, 50, 'active')")
        ->execute([(int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn()]);
    $r58gProduct = (int)$pdo->lastInsertId();
    $r58gSnapshot = VatService::buildSnapshot([['product_id' => $r58gProduct, 'sku' => 'R58G', 'qty' => 3, 'price' => 1000, 'product_type' => 'simple']],
        ['seller_country' => 'SK', 'customer_country' => 'SK'], ['currency' => 'eur']);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id)
        VALUES ('R58G-AB12-CD34', 'R58G', 'r58g@example.test', '{\"country\":\"SK\"}', 3000, 3000, 'eur', 'processing', 'paid', 'comgate', 'AB12-CD34', ?, 'pickup', 'domestic', ?, 'default')")
        ->execute([json_encode(['provider' => 'comgate', 'service_type' => 'online_card', 'requires_online' => true]), json_encode($r58gSnapshot)]);
    $r58gOrder = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'R58G', 'R58G', 3, 1000, 3000)")->execute([$r58gOrder, $r58gProduct]);
    $pdo->prepare('DELETE FROM operational_incidents WHERE context_json = ? OR context_json LIKE ?')->execute(['{"order_id":' . $r58gOrder . '}', '{"order_id":' . $r58gOrder . ',%']);
    InvoiceService::issue($r58gOrder);
    PaymentService::requestRefund($r58gOrder, 500, 'r58g_request', ['reason' => 'R58G']);
    $r58gAnswer = ['status' => 200, 'body' => '{"code":1500,"message":"unexpected error"}'];
    $r58gCalls = 0;
    $r58lInstances->setValue(null, ['payment' => $r58gComgate] + $r58lSaved);
    $r58gJob = static function () use ($r58gOrder): string {
        try {
            WorkerJobs::handlePaymentRefund(['order_id' => $r58gOrder, 'payment_id' => 'AB12-CD34', 'amount' => 500, 'idempotency_key' => 'r58g_request_execute'], ['attempts' => 3, 'max_attempts' => 3]);
            return 'done';
        } catch (Throwable $e) {
            return 'failed';
        }
    };
    $r58gFirst = $r58gJob();
    $r58gAfterFirst = (string)Orders::byId($r58gOrder)['payment_status'];
    $r58gSecond = $r58gJob();
    $r58gIncidents = $pdo->prepare("SELECT code FROM operational_incidents WHERE code IN ('payment.refund_failed', 'payment.refund_outcome_unknown') AND (context_json = ? OR context_json LIKE ?)");
    $r58gIncidents->execute(['{"order_id":' . $r58gOrder . '}', '{"order_id":' . $r58gOrder . ',%']);
    assert_equals(['failed', 'refund_pending', 1, 'refund_pending', ['payment.refund_outcome_unknown'], 0],
        [$r58gFirst, $r58gAfterFirst, $r58gCalls, (string)Orders::byId($r58gOrder)['payment_status'], $r58gIncidents->fetchAll(PDO::FETCH_COLUMN), PaymentService::refundedAmount($r58gOrder)],
        'R58 Comgate: a refund answered 1500 stays refund pending with "refund outcome unknown", Comgate is not called again (was: released as declined, the payment back to paid)');
} catch (Throwable $e) {
    assert_true(false, 'R58 Comgate refund codes (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r58lInstances->setValue(null, $r58lSaved);
    $r58lJobsDir->setValue(null, $r58lOldJobs);
}
