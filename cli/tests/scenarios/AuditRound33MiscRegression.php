<?php

// Review round 33 (lows): an item refund whose job ran out of re-queues (about 9 hours of network errors) left its credit
// note pending for good with no way to release it; an IČ DPH VIES answered is invalid was printed on the invoice; the no-JS
// checkout had no way to recalculate the total after picking another shipping or payment method; every step of a purchase
// on invoice or a cash on delivery order (accepted, shipped, paid) mailed the same invoice again.
echo "\n--- R33: stalled item refund release, invalid VAT ID, no-JS recalculation, invoice mailed once ---\n";

require_once TEST_CLI_DIR . '/../cli/worker.php';
if (!function_exists('pageOrder')) {
    if (!function_exists('adminFieldErrors')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Shared.php';
    require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Orders.php';
}
Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
$r33mRoot = dirname(TEST_CLI_DIR);
$r33mJobsDir = test_fixture_path('r33m-jobs');
JobQueue::setDir($r33mJobsDir);
$r33mDropQueue = static function () use ($r33mJobsDir): void {
    foreach (['pending', 'processing'] as $sub) foreach (glob($r33mJobsDir . '/' . $sub . '/*.json') ?: [] as $file) @unlink($file);
};
$r33mProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$r33mOrders = [];
$r33mOrder = static function (string $number, string $paymentStatus = 'paid', string $email = 'r33m@example.test') use ($pdo, $r33mProduct, &$r33mOrders): int {
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, store_id, created_at)
        VALUES (?, 'R33M', ?, '', '{\"country\":\"SK\",\"name\":\"R33M\",\"street\":\"Hlavna 1\",\"city\":\"Bratislava\",\"zip\":\"81101\"}', 4200, 0, 0, 0, 4200, 'eur', 'processing', ?, 'stripe', ?, ?, 'pickup', 'default', " . Db::now() . ")")
        ->execute([$number, $email, $paymentStatus, 'pi_' . strtolower($number), json_encode(['provider' => 'stripe', 'service_type' => 'online_card'])]);
    $id = $r33mOrders[] = (int)$pdo->lastInsertId();
    foreach (['payment_transactions', 'order_refunds', 'email_log', 'invoices'] as $r33mStale) $pdo->prepare("DELETE FROM {$r33mStale} WHERE order_id = ?")->execute([$id]);
    $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, 4200, 4200)')
        ->execute([$id, $r33mProduct, "{$number}-P", "R33M {$number}"]);
    return $id;
};
$r33mCredit = static function (int $orderId, int $amount, string $key) use ($pdo): int {
    $pdo->prepare("INSERT INTO order_refunds (store_id, order_id, rma_id, credit_note_id, amount, currency, status, idempotency_key) VALUES ('default', ?, 0, 0, ?, 'eur', 'pending', ?)")
        ->execute([$orderId, $amount, $key]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE orders SET payment_status = 'refund_pending' WHERE id = ?")->execute([$orderId]);
    return $id;
};

try {
    // 1. An item refund no job will send any more is offered for release; one still queued, or accepted by the provider, is not.
    $r33mDropQueue();
    $o1 = $r33mOrder('R33M-1');
    $item1 = $r33mCredit($o1, 400, 'r33m-1-item');
    JobQueue::push('payment_refund', ['order_id' => $o1, 'payment_id' => 'pi_r33m-1', 'amount' => 400, 'refund_id' => $item1, 'idempotency_key' => 'r33m-1-item'], 1);
    $queued = PaymentService::stalledItemRefund(Orders::byId($o1));
    $r33mDropQueue(); // the job died: its 12 re-queues after network errors ran out
    $stalled = PaymentService::stalledItemRefund(Orders::byId($o1));
    assert_equals([null, ['amount' => 400, 'refund_id' => $item1]], [$queued, $stalled],
        'R33 refunds: an item refund whose job died is offered for release; not while its job still waits (was: pending for good, nothing to release it)');

    $o2 = $r33mOrder('R33M-2');
    $item2 = $r33mCredit($o2, 300, 'r33m-2-item');
    $pdo->prepare("UPDATE order_refunds SET payment_reference = 're_r33m_2' WHERE id = ?")->execute([$item2]);
    $o3 = $r33mOrder('R33M-3');
    $r33mCredit($o3, 300, 'r33m-3-item');
    $pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response, created_at, updated_at)
        VALUES (?, 'default', 'stripe', '', 'refund_pending', 300, 'eur', 'r33m-3-item', '{}', " . Db::now() . ', ' . Db::now() . ')')->execute([$o3]);
    assert_equals([null, null], [PaymentService::stalledItemRefund(Orders::byId($o2)), PaymentService::stalledItemRefund(Orders::byId($o3))],
        'R33 refunds: a refund the provider accepted (its refund id, or its pending ledger row) is not offered for release (its webhook or "confirm" settles it)');

    $_GET = ['id' => (string)$o1];
    ob_start();
    try { pageOrder(); } finally { $r33mPage = (string)ob_get_clean(); $_GET = []; }
    $r33mRequest = (string)file_get_contents($r33mRoot . '/src/Entrypoints/Admin/Request.php');
    assert_true(str_contains($r33mPage, 'action=refund_release" style="margin-top:.5rem" data-refund-release') && str_contains($r33mPage, 'name="refund_id" value="' . $item1 . '"')
        && str_contains($r33mPage, esc(I18n::t('admin.order.release_refund'))) && str_contains($r33mRequest, "'order:refund_release' => handleOrderRefundRelease()"),
        'R33 refunds: the order page offers the release with a required note, routed to its handler');

    $released = PaymentService::releaseStalledItemRefund($o1, $item1, 'r33m-admin', 'R33M nothing left at Stripe');
    $again = PaymentService::releaseStalledItemRefund($o1, $item1, 'r33m-admin', 'R33M again');
    $audit = $pdo->prepare("SELECT note FROM audit_log WHERE entity = 'order_refund' AND entity_id = ? AND new_value = 'failed' ORDER BY id DESC LIMIT 1");
    $audit->execute([$o1]);
    $note = (string)$audit->fetchColumn();
    assert_equals([true, false, 'failed', 'paid', true, null], [$released, $again, (string)$pdo->query("SELECT status FROM order_refunds WHERE id = {$item1}")->fetchColumn(),
        (string)Orders::byId($o1)['payment_status'], str_contains($note, 'r33m-admin') && str_contains($note, 'R33M nothing left at Stripe'), PaymentService::stalledItemRefund(Orders::byId($o1))],
        'R33 refunds: the release fails the item refund like a declined one (payment back to paid), audited with the admin and note; it is done once');
    assert_equals(false, PaymentService::releaseStalledItemRefund($o2, $item2, 'r33m-admin', 'R33M accepted'),
        'R33 refunds: a refund the provider accepted cannot be released by a forged form');
} catch (Throwable $e) {
    assert_true(false, 'R33 stalled item refund (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r33mDropQueue();
    $_GET = [];
}

try {
    // 2. An IČ DPH VIES answered is invalid is left out of the order address the invoice reads; an unverified one stays.
    $company = ['company' => 'R33M GmbH', 'ico' => 'HRB33', 'ic_dph' => 'DE999999999'];
    // R34 (low): the unverified one is kept but marked, so the invoice does not print it as a checked registration.
    assert_equals([['company' => 'R33M GmbH', 'ico' => 'HRB33'], $company + ['ic_dph_unverified' => '1'], $company], [
        CheckoutService::withoutInvalidVatId($company, 'invalid'), CheckoutService::withoutInvalidVatId($company, 'unverified'), CheckoutService::withoutInvalidVatId($company, 'valid'),
    ], 'R33 invoice: an IČ DPH VIES answered is invalid is not stored for the invoice (was printed as the buyer\'s VAT registration)');
    $handlers = (string)file_get_contents($r33mRoot . '/src/Http/ApiHandlers.php');
    $manual = (string)file_get_contents($r33mRoot . '/src/ManualOrder.php');
    assert_true(str_contains($handlers, "\$buyerCompany['fields'] = CheckoutService::withoutInvalidVatId(\$buyerCompany['fields'], \$vatIdStatus);")
        && str_contains($manual, "\$company['fields'] = CheckoutService::withoutInvalidVatId(\$company['fields'], \$vat['vat_id_status']);"),
        'R33 invoice: checkout (HTML and API) and the manual order both drop it');
} catch (Throwable $e) {
    assert_true(false, 'R33 invalid VAT ID (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

// 3. No JS: a button recalculates the total for the shipping and payment picked (with JS the summary updates live).
$r33mCheckout = (string)file_get_contents($r33mRoot . '/themes/default/blocks/storefront/checkout.html');
$r33mMissing = [];
foreach (['en', 'sk', 'cs', 'de', 'es', 'hu', 'pl'] as $r33mLang) {
    $texts = require "{$r33mRoot}/lang/{$r33mLang}.php";
    foreach (['checkout.recalculate', 'admin.order.release_refund', 'admin.order.release_refund_hint', 'admin.order.msg.refund_released'] as $r33mKey) {
        if (trim((string)($texts[$r33mKey] ?? '')) === '') $r33mMissing[] = "{$r33mLang}:{$r33mKey}";
    }
}
assert_true(str_contains($r33mCheckout, '{{/if}}</div><noscript><p><button type="submit" name="checkout_refresh" value="1" formnovalidate data-checkout-recalc>{{recalculate_label}}</button></p></noscript>')
    && $r33mMissing === [], 'R33 checkout: without JS a "recalculate" button after the payment methods re-renders the total; texts in all 7 languages (' . implode(', ', $r33mMissing) . ')');

try {
    // 4. The invoice e-mail goes once: the next invoice_issue job of the same order (shipped, then paid) mails nothing.
    MailService::useAdapter(new class implements MailAdapter {
        public function send(array $msg): array { return ['status' => 'sent', 'message' => '', 'raw' => null]; }
        public function adapterId(): string { return 'r33m'; }
    });
    $o4 = $r33mOrder('R33M-4', 'paid', 'r33m-4@example.test');
    $count = static function () use ($pdo, $o4): int {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM email_log WHERE order_id = ? AND template = 'invoice_issued' AND status = 'sent'");
        $stmt->execute([$o4]);
        return (int)$stmt->fetchColumn();
    };
    WorkerJobs::handleInvoiceIssue(['order_id' => $o4], []);
    $first = $count();
    WorkerJobs::handleInvoiceIssue(['order_id' => $o4], []); // shipped
    WorkerJobs::handleInvoiceIssue(['order_id' => $o4], []); // paid
    assert_equals([1, 1], [$first, $count()], 'R33 invoice e-mail: the invoice is mailed once, not by every later invoice_issue job of the order (was: twice)');
    $invoice = InvoiceService::byOrderId($o4);
    InvoiceService::cancel((int)$invoice['id'], 'R33M re-issue');
    $reissued = InvoiceService::issue($o4);
    WorkerJobs::handleInvoiceIssue(['order_id' => $o4], []);
    assert_equals([true, 2], [(int)$reissued['id'] !== (int)$invoice['id'], $count()], 'R33 invoice e-mail: a new invoice of the order (re-issued) is mailed');
} catch (Throwable $e) {
    assert_true(false, 'R33 invoice e-mail once (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    MailService::useAdapter(null);
    foreach ($r33mOrders as $r33mCleanup) {
        foreach (['order_refunds' => 'order_id = ?', 'payment_transactions' => 'order_id = ?', 'email_log' => 'order_id = ?', 'audit_log' => "entity = 'order_refund' AND entity_id = ?",
            'invoice_sellers' => 'invoice_id IN (SELECT id FROM invoices WHERE order_id = ?)', 'invoices' => 'order_id = ?', 'order_items' => 'order_id = ?', 'orders' => 'id = ?'] as $r33mTable => $r33mWhere) {
            try { $pdo->prepare("DELETE FROM {$r33mTable} WHERE {$r33mWhere}")->execute([$r33mCleanup]); } catch (Throwable $e) {}
        }
    }
}
