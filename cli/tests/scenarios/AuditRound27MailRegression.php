<?php

// Review round 27 (2026-11-10, "kolo 11"), low (goal #397): download links, the invoice e-mail and the payment link of an
// order created by hand, written to the log for a misconfigured mail provider (MAIL_ADAPTER="smtp."), reached nobody and
// were never sent once the provider was fixed (the download links were even marked delivered). They are tried again like
// order mail (R26, WorkerJobs::requeueLoggedMail()); with MAIL_ADAPTER=log on purpose nothing is tried again.
echo "\n--- R27: download links, invoice and manual-order payment link logged for a misconfigured provider are retried ---\n";
$r27mDb = Db::get();
$r27mEnvNames = array_merge(array_values(MailSettings::ENV), ['MAIL_ADAPTER']);
$r27mEnv = [];
foreach ($r27mEnvNames as $name) { $r27mEnv[$name] = getenv($name); putenv($name); }
$r27mJobs = test_fixture_path('r27m-jobs');
JobQueue::setDir($r27mJobs);
if (!class_exists('R27mCaptureMail', false)) {
    class R27mCaptureMail implements MailAdapter {
        /** @var list<array> */
        public array $sent = [];
        public function send(array $msg): array { $this->sent[] = $msg; return ['status' => 'sent']; }
        public function adapterId(): string { return 'smtp'; }
    }
}
// The jobs of $type queued again for a mail the misconfigured provider only logged: [payload, ...], taken.
$r27mQueued = static function (string $type) use ($r27mJobs): array {
    $found = [];
    if (!is_dir($r27mJobs)) return [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r27mJobs, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $job = json_decode((string)file_get_contents($file->getPathname()), true);
        if (is_array($job) && ($job['type'] ?? '') === $type && isset($job['payload']['_mail_fallback_tries'])) {
            $found[] = $job['payload'];
            @unlink($file->getPathname());
        }
    }
    return $found;
};
$r27mTries = static fn(array $queued): array => array_map(static fn(array $p): int => (int)$p['_mail_fallback_tries'], $queued);
$r27mCfgProp = new ReflectionProperty(Security::class, 'cfg');
$r27mCfgBefore = $r27mCfgProp->getValue();
$r27mBaseCfg = is_array($r27mCfgBefore) ? $r27mCfgBefore : require AppConfig::path(dirname(TEST_CLI_DIR));
$r27mInstances = new ReflectionProperty(Adapters::class, 'instances');
$r27mInstancesBefore = $r27mInstances->getValue();
$r27mSku = 'R27M-EBOOK-' . bin2hex(random_bytes(3));
$r27mInvoiceOrder = null;
try {
    $r27mCfgProp->setValue(null, ['app_url' => 'https://r27m.example.test'] + $r27mBaseCfg);
    DigitalDelivery::setConfigForTesting(['app_url' => 'https://r27m.example.test'] + $r27mBaseCfg);

    // 1. Download links: a paid e-book order (supplied with the consent, so not held).
    $r27mDb->prepare("INSERT INTO digital_assets (sku, asset_key, storage_path, download_name) VALUES (?, 'default', 'r27m/book.pdf', 'book.pdf')")->execute([$r27mSku]);
    $r27mDb->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_id, subtotal, total, currency, store_id, locale, financial_snapshot)
        VALUES (?, 'processing', 'paid', 'R27M Buyer', 'r27m-book@r27m.example.test', 'card', 'pi_r27m', 500, 500, 'eur', 'default', 'en', '{}')")
        ->execute(['R27M-' . bin2hex(random_bytes(3))]);
    $r27mBook = (int)$r27mDb->lastInsertId();
    // Any product row: the asset joins the order line by its SKU.
    $r27mProduct = (int)$r27mDb->query("SELECT id FROM products ORDER BY id LIMIT 1")->fetchColumn();
    $r27mDb->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, 'R27M E-book', 1, 500, 500)")->execute([$r27mBook, $r27mProduct, $r27mSku]);
    $r27mDb->prepare("INSERT INTO order_consents (order_id, consent_key, version, consent_text, user_agent) VALUES (?, 'digital_delivery', '2026-10-22', 'r27m', '')")->execute([$r27mBook]);
    $r27mLinks = static function () use ($r27mDb, $r27mBook): array {
        $stmt = $r27mDb->prepare("SELECT status, COALESCE(revoked_reason, ''), CASE WHEN delivered_at IS NULL THEN 0 ELSE 1 END FROM digital_entitlements WHERE order_id = ? ORDER BY id");
        $stmt->execute([$r27mBook]);
        return $stmt->fetchAll(PDO::FETCH_NUM);
    };
    putenv('MAIL_ADAPTER=smtp.');
    WorkerJobs::handleDigitalFulfill(['order_id' => $r27mBook], []);
    $r27mBookQueued = $r27mQueued('digital_fulfill');
    assert_equals([[['revoked', 'delivery_email_logged', 0]], [1]], [$r27mLinks(), $r27mTries($r27mBookQueued)],
        'R27 mail: download links only written to the log are revoked, not delivered, and the delivery is queued again (was: marked delivered, never sent)');
    putenv('MAIL_ADAPTER=smtp');
    $r27mCapture = new R27mCaptureMail();
    MailService::useAdapter($r27mCapture);
    foreach ($r27mBookQueued as $r27mPayload) WorkerJobs::handleDigitalFulfill($r27mPayload, []);
    MailService::useAdapter(null);
    assert_equals([['r27m-book@r27m.example.test'], [['revoked', 'delivery_email_logged', 0], ['active', '', 1]], []],
        [array_column($r27mCapture->sent, 'to'), $r27mLinks(), $r27mQueued('digital_fulfill')],
        'R27 mail: once the provider is fixed the retry mails new download links, delivered, and nothing is queued again');

    // 2. The invoice e-mail of an invoiced order.
    $r27mInvoiceOrder = $r27mDb->query("SELECT o.id, o.customer_email FROM orders o JOIN invoices i ON i.order_id = o.id
        WHERE o.store_id = 'default' ORDER BY o.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $r27mInvoiceTo = 'r27m-invoice-' . bin2hex(random_bytes(3)) . '@r27m.example.test';
    $r27mDb->prepare('UPDATE orders SET customer_email = ? WHERE id = ?')->execute([$r27mInvoiceTo, $r27mInvoiceOrder['id']]);
    putenv('MAIL_ADAPTER=smtp.');
    WorkerJobs::handleInvoiceIssue(['order_id' => (int)$r27mInvoiceOrder['id']], []);
    $r27mInvoiceQueued = $r27mQueued('invoice_issue');
    putenv('MAIL_ADAPTER=smtp');
    $r27mCapture = new R27mCaptureMail();
    MailService::useAdapter($r27mCapture);
    foreach ($r27mInvoiceQueued as $r27mPayload) WorkerJobs::handleInvoiceIssue($r27mPayload, []);
    MailService::useAdapter(null);
    assert_equals([[1], [$r27mInvoiceTo], []], [$r27mTries($r27mInvoiceQueued), array_column($r27mCapture->sent, 'to'), $r27mQueued('invoice_issue')],
        'R27 mail: an invoice e-mail only written to the log is queued again and sent once the provider is fixed (was: never)');

    // 3. The payment link of an order created by hand (ManualOrder::notify()).
    Adapters::set('payment.r27m-gateway', new class {});
    $r27mUnpaid = static function () use ($r27mDb): int {
        $r27mDb->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
            VALUES (?, 'R27M', 'r27m-link@r27m.example.test', '', '{}', 1000, 0, 0, 0, 1000, 'eur', 'pending', 'pending', 'r27m-gateway', '', ?, ?, " . Db::now() . ")")
            ->execute(['R27ML-' . bin2hex(random_bytes(4)), json_encode(['provider' => 'r27m-gateway', 'service_type' => 'online_card', 'requires_online' => true]), StoreContext::current()->id()]);
        return (int)$r27mDb->lastInsertId();
    };
    $r27mNotify = new ReflectionMethod(ManualOrder::class, 'notify');
    $r27mLinkRows = static function (int $orderId) use ($r27mDb): int {
        $stmt = $r27mDb->prepare("SELECT COUNT(*) FROM checkout_sessions WHERE order_id = ? AND provider = 'pay_link'");
        $stmt->execute([$orderId]);
        return (int)$stmt->fetchColumn();
    };
    $r27mManual = $r27mUnpaid();
    putenv('MAIL_ADAPTER=smtp.');
    $r27mNotify->invoke(null, $r27mManual);
    $r27mLinkQueued = $r27mQueued('payment_link_send');
    // Still misconfigured at the retry: no new link is issued, the retry is queued once more.
    foreach ($r27mLinkQueued as $r27mPayload) WorkerJobs::handlePaymentLinkSend($r27mPayload, []);
    $r27mLinkQueued2 = $r27mQueued('payment_link_send');
    assert_equals([[1], 1, [2]], [$r27mTries($r27mLinkQueued), $r27mLinkRows($r27mManual), $r27mTries($r27mLinkQueued2)],
        'R27 mail: a manual order\'s payment link only written to the log is queued again; a retry while still misconfigured issues no link (was: never sent)');
    putenv('MAIL_ADAPTER=smtp');
    $r27mCapture = new R27mCaptureMail();
    MailService::useAdapter($r27mCapture);
    foreach ($r27mLinkQueued2 as $r27mPayload) WorkerJobs::handlePaymentLinkSend($r27mPayload, []);
    MailService::useAdapter(null);
    assert_equals([['r27m-link@r27m.example.test'], 2, []], [array_column($r27mCapture->sent, 'to'), $r27mLinkRows($r27mManual), $r27mQueued('payment_link_send')],
        'R27 mail: once the provider is fixed the manual order\'s payment link is sent, and nothing is queued again');

    // 4. MAIL_ADAPTER=log on purpose: logged as before, nothing tried again (as order mail).
    putenv('MAIL_ADAPTER=log');
    $r27mNotify->invoke(null, $r27mUnpaid());
    WorkerJobs::handleInvoiceIssue(['order_id' => (int)$r27mInvoiceOrder['id']], []);
    assert_equals([[], []], [$r27mQueued('payment_link_send'), $r27mQueued('invoice_issue')],
        'R27 mail: with MAIL_ADAPTER=log on purpose a payment link or invoice is not tried again');
} catch (Throwable $e) {
    assert_true(false, 'R27 mail (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    MailService::useAdapter(null);
    DigitalDelivery::setConfigForTesting(null);
    $r27mInstances->setValue(null, $r27mInstancesBefore);
    $r27mCfgProp->setValue(null, $r27mCfgBefore);
    if (is_array($r27mInvoiceOrder)) $r27mDb->prepare('UPDATE orders SET customer_email = ? WHERE id = ?')->execute([$r27mInvoiceOrder['customer_email'], $r27mInvoiceOrder['id']]);
    try {
        $r27mDb->prepare('DELETE FROM digital_entitlements WHERE asset_id IN (SELECT id FROM digital_assets WHERE sku = ?)')->execute([$r27mSku]);
        $r27mDb->prepare('DELETE FROM digital_assets WHERE sku = ?')->execute([$r27mSku]);
    } catch (Throwable $e) {}
    foreach ($r27mEnv as $name => $value) putenv($value === false ? $name : "{$name}={$value}");
}
