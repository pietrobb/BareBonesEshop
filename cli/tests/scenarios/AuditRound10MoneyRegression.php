<?php

// Round 10 review (2026-10-25): money and cart.
echo "\n--- Audit round 10 (money): variant price floor, variant-required cart, coupons, Stripe refunds ---\n";
if (!function_exists('apiCartAdd')) require_once TEST_CLI_DIR . '/../src/Http/ApiHandlers.php';
$r10mCatalog = StoreContext::current()->catalogId();
$r10mSession = $_SESSION ?? [];
$r10mServer = array_intersect_key($_SERVER, array_flip(['REQUEST_METHOD']));
$r10mPost = $_POST;
try {
    $r10mProduct = $pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (?, ?, 1, ?, ?, ?, 'simple', 1000, 'eur', 'active', 5, 'deny')");
    $r10mProduct->execute([9861, $r10mCatalog, 'R10M variants', 'r10m-variants', 'R10M-VARIANTS']);
    $pdo->prepare("INSERT INTO variants (product_id, sku, name, stock, price_delta) VALUES (9861, 'R10M-RED', 'Red', 5, -300)")->execute();
    $r10mVariant = (int)$pdo->query("SELECT id FROM variants WHERE sku = 'R10M-RED'")->fetchColumn();

    // M6: API v1 PATCH must not set a base price that turns a delta-priced variant negative.
    $r10mClientRow = ApiAuth::issueClient('R10M', 'default', ['products:read', 'products:write']);
    $r10mClient = ApiAuth::authenticate('Bearer ' . $r10mClientRow['token']);
    $r10mBelow = ApiV1::dispatch('PATCH', '/api/v1/products/9861', [], ['price' => 200], ['Idempotency-Key' => 'r10m-floor-below-01'], $r10mClient);
    assert_true($r10mBelow['status'] === 422 && (int)$pdo->query('SELECT price FROM products WHERE id = 9861')->fetchColumn() === 1000,
        'R10 M6: API v1 PATCH below the variant price floor (3.00 €) is refused with 422 and the price stays (status ' . $r10mBelow['status'] . ')');
    $r10mAt = ApiV1::dispatch('PATCH', '/api/v1/products/9861', [], ['price' => 300], ['Idempotency-Key' => 'r10m-floor-at-0001'], $r10mClient);
    assert_true($r10mAt['status'] === 200 && (int)$r10mAt['body']['data']['price']['amount'] === 300, 'R10 M6: a price exactly at the variant floor is accepted (status ' . $r10mAt['status'] . ')');

    // M7/S10: the JSON cart API and the localStorage restore need a variant for a product that has variants.
    $_SESSION = ['csrf_token' => 'r10m-csrf', 'cart' => []];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf_token' => 'r10m-csrf', 'product_id' => '9861', 'qty' => '1'];
    $r10mAdd = @apiCartAdd(); // @: the CLI test has already sent output, so http_response_code() only warns
    assert_true(($r10mAdd['error'] ?? '') !== '' && empty($_SESSION['cart']['p_9861']),
        'R10 M7: cart_add without variant_id for a variant product is refused and adds no base-product line (' . json_encode($r10mAdd['error'] ?? null) . ')');
    $_POST = ['csrf_token' => 'r10m-csrf', 'product_id' => '9861', 'variant_id' => (string)$r10mVariant, 'qty' => '1'];
    $r10mAddVariant = apiCartAdd();
    assert_true(!empty($r10mAddVariant['success']) && isset($_SESSION['cart']['v_' . $r10mVariant]), 'R10 M7: cart_add with the variant still adds it');
    $_SESSION['cart'] = [];
    Cart::restore([['product_id' => 9861, 'variant_id' => null, 'qty' => 2], ['product_id' => 9861, 'variant_id' => $r10mVariant, 'qty' => 1]]);
    assert_true(!isset($_SESSION['cart']['p_9861']) && ($_SESSION['cart']['v_' . $r10mVariant]['qty'] ?? 0) === 1,
        'S10: cart_restore skips a variant product without variant_id and keeps the valid variant line');

    // M8: a pricing.json coupon amount (fixed value, minimum order) is in the rules' currency, not the cart's.
    $r10mPricing = (new ReflectionProperty(Pricing::class, 'config'))->getValue();
    $r10mCur = strtolower((string)(StoreContext::current()->config()['currency'] ?? 'eur'));
    $r10mOther = $r10mCur === 'czk' ? 'eur' : 'czk';
    Pricing::setConfig(['cart_rules' => [
        ['name' => 'R10M fixed', 'active' => true, 'type' => 'coupon', 'code' => 'R10MFIX', 'discount_type' => 'fixed', 'discount_value' => 500],
        ['name' => 'R10M min', 'active' => true, 'type' => 'coupon', 'code' => 'R10MMIN', 'discount_type' => 'percent', 'discount_value' => 10, 'min_subtotal' => 5000],
        ['name' => 'R10M pct', 'active' => true, 'type' => 'coupon', 'code' => 'R10MPCT', 'discount_type' => 'percent', 'discount_value' => 10],
    ]]);
    assert_equals(500, Pricing::applyCoupon('R10MFIX', 10000, null, $r10mCur)['discount'], 'R10 M8: a fixed coupon applies in the rules currency');
    assert_equals(0, Pricing::applyCoupon('R10MFIX', 1000000, null, $r10mOther)['discount'], 'R10 M8: a fixed coupon is not charged as the same number in another currency');
    assert_equals(0, Pricing::applyCoupon('R10MMIN', 6000, null, $r10mOther)['discount'], 'R10 M8: a minimum order is not compared across currencies');
    assert_equals(100, Pricing::applyCoupon('R10MPCT', 1000, null, $r10mOther)['discount'], 'R10 M8: a plain percentage coupon has no amount and still applies');

    // M8: a shared pricing.json coupon's per_customer limit counts the customer's uses in every store.
    Pricing::setConfig(['cart_rules' => [
        ['name' => 'R10M once', 'active' => true, 'type' => 'coupon', 'code' => 'R10MONE', 'discount_type' => 'percent', 'discount_value' => 10, 'per_customer' => 1],
    ]]);
    $r10mHome = StoreContext::current();
    $r10mForeign = array_values(array_diff(array_keys(Bootstrap::stores()['stores'] ?? []), [$r10mHome->id()]))[0];
    $r10mOrder = (int)$pdo->query('SELECT MIN(id) FROM orders WHERE id NOT IN (SELECT order_id FROM coupon_uses)')->fetchColumn();
    try {
        Pricing::recordCouponUse('R10MONE', $r10mOrder, 'Once.R10M@example.com', 100);
        StoreContext::activate(StoreContext::resolve(Bootstrap::stores(), null, null, $r10mForeign, 'storefront'));
        assert_equals('Coupon already used', Pricing::applyCoupon('R10MONE', 1000, 'once.r10m@example.com')['error'],
            'R10 M8: a shared coupon used once in one store is refused for the same customer in another store');
        $r10mSecond = null;
        try { Pricing::recordCouponUse('R10MONE', $r10mOrder, 'once.r10m@example.com', 100); } catch (CouponRejectedException $e) { $r10mSecond = $e; }
        assert_true($r10mSecond !== null, 'R10 M8: the order in the other store cannot claim the shared per_customer use either');
        StoreContext::activate($r10mHome);
        Pricing::releaseCouponUse($r10mOrder);
        StoreContext::activate(StoreContext::resolve(Bootstrap::stores(), null, null, $r10mForeign, 'storefront'));
        assert_equals(null, Pricing::applyCoupon('R10MONE', 1000, 'once.r10m@example.com')['error'],
            'R10 M8: a cancelled order gives the shared use back in every store');
    } finally {
        StoreContext::activate($r10mHome);
        $pdo->exec("DELETE FROM coupon_uses WHERE coupon_code = 'R10MONE'");
        $pdo->exec("DELETE FROM coupon_usage_counters WHERE coupon_code = 'R10MONE'");
    }
    Pricing::setConfig(is_array($r10mPricing) ? $r10mPricing : []);

    // Credit note numbering: issue date, number-series year and PDF month came from three date() calls, so a credit
    // note issued at midnight on 31 December got the old date but a number from the new year's series.
    $r10mInvoiceSrc = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/InvoiceService.php');
    $r10mCreditStart = (int)strpos($r10mInvoiceSrc, 'public static function credit(');
    $r10mCredit = substr($r10mInvoiceSrc, $r10mCreditStart, (int)strpos($r10mInvoiceSrc, "\n    public static function ", $r10mCreditStart + 10) - $r10mCreditStart);
    assert_equals(1, preg_match_all('/\b(?:date|dateIn)\(/', $r10mCredit), 'R10 credit note: issue date, series year and PDF month come from one date call');

    // M10: a refund request and a pending credit note each see the money the other already promised back.
    $r10mPaidOrder = static function (string $tag) use ($pdo): int {
        $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
            VALUES (?, 'R10M', 'r10m@example.test', '', '{}', 1000, 0, 0, 0, 1000, 'eur', 'delivered', 'paid', 'stripe', ?, ?, ?, " . Db::now() . ")")
            ->execute(['R10M-' . $tag . '-' . bin2hex(random_bytes(4)), 'pi_r10m_' . $tag, json_encode(['provider' => 'stripe', 'service_type' => 'online_card', 'requires_online' => true]), StoreContext::current()->id()]);
        return (int)$pdo->lastInsertId();
    };
    $r10mCredited = $r10mPaidOrder('credit');
    $pdo->prepare("INSERT INTO order_refunds (store_id, order_id, rma_id, credit_note_id, amount, currency, status, idempotency_key) VALUES (?, ?, 0, 0, 600, 'eur', 'pending', 'r10m-credit-pending')")
        ->execute([StoreContext::current()->id(), $r10mCredited]);
    $r10mOver = null;
    try { PaymentService::requestRefund($r10mCredited, 1000, 'r10m-over-credit', ['reason' => 'R10M']); } catch (LogicException $e) { $r10mOver = $e; }
    assert_true($r10mOver !== null && (string)Orders::byId($r10mCredited)['payment_status'] === 'paid',
        'R10 M10: a refund request cannot claim money a pending credit note (600 of 1000) already returns');
    assert_equals(400, PaymentService::claimableRefund($r10mCredited), 'R10 M10: only the rest (400) is claimable by a new refund');
    PaymentService::requestRefund($r10mCredited, 400, 'r10m-rest-credit', ['reason' => 'R10M']);
    assert_equals('refund_pending', (string)Orders::byId($r10mCredited)['payment_status'], 'R10 M10: the rest can still be refunded');

    $r10mRequested = $r10mPaidOrder('request');
    PaymentService::requestRefund($r10mRequested, 700, 'r10m-request-700', ['reason' => 'R10M']);
    assert_equals(300, (new ReflectionMethod(InvoiceService::class, 'refundableForCredit'))->invoke(null, Orders::byId($r10mRequested)),
        'R10 M10: a credit note sees the 700 refund request in flight and may return only 300');

    // M10: a Stripe refund still pending is not money returned; its later failure releases the request.
    (require TEST_CLI_DIR . '/../migrations/20261012090000_operational_incidents.php')->up($pdo, true);
    $r10mStripe = new class(['stripe_secret_key' => 'sk_test_r10m']) extends StripePayment {
        /** @var array<string, array<string, mixed>> */
        public array $responses = [];
        protected function apiCall(string $method, string $endpoint, array $params = [], array $extraHeaders = []): array {
            return $this->responses[$method . ' ' . $endpoint] ?? throw new RuntimeException('Unexpected Stripe call ' . $method . ' ' . $endpoint);
        }
    };
    $r10mStripe->responses['POST /v1/refunds'] = ['id' => 're_r10m_pending', 'status' => 'pending'];
    $r10mPending = $r10mPaidOrder('pending');
    PaymentService::requestRefund($r10mPending, 1000, 'r10m_pending_request', ['reason' => 'R10M']);
    PaymentService::refund($r10mPending, 'pi_r10m_pending', 1000, $r10mStripe, 'r10m_pending_request_execute', 'eur');
    applyStripeChargeRefunded(['id' => 'ch_r10m_pending', 'payment_intent' => 'pi_r10m_pending', 'amount_refunded' => 1000,
        'refunds' => ['data' => [['id' => 're_r10m_pending', 'amount' => 1000, 'status' => 'pending']], 'has_more' => false]], 'pi_r10m_pending', $r10mPending);
    $r10mState = static fn(int $id): array => [(string)Orders::byId($id)['status'], (string)Orders::byId($id)['payment_status'], PaymentService::refundedAmount($id)];
    assert_equals(['delivered', 'refund_pending', 0], $r10mState($r10mPending),
        'R10 M10: charge.refunded with a pending Stripe refund books no money and does not close the order');
    applyStripeRefundUpdated(['id' => 're_r10m_pending', 'amount' => 1000, 'status' => 'failed', 'payment_intent' => 'pi_r10m_pending', 'charge' => 'ch_r10m_pending'], 'evt_r10m_failed', $r10mPending);
    assert_equals([['delivered', 'paid', 0], 1000], [$r10mState($r10mPending), PaymentService::claimableRefund($r10mPending)],
        'R10 M10: refund.updated failed returns the payment to paid, so the refund can be requested again');

    $r10mLater = $r10mPaidOrder('later');
    PaymentService::requestRefund($r10mLater, 1000, 'r10m_later_request', ['reason' => 'R10M']);
    $r10mStripe->responses['POST /v1/refunds'] = ['id' => 're_r10m_later', 'status' => 'requires_action'];
    PaymentService::refund($r10mLater, 'pi_r10m_later', 1000, $r10mStripe, 'r10m_later_request_execute', 'eur');
    assert_equals(0, PaymentService::refundedAmount($r10mLater), 'R10 M10: a refund Stripe answers requires_action is not booked as returned');
    applyStripeRefundUpdated(['id' => 're_r10m_later', 'amount' => 1000, 'status' => 'succeeded', 'payment_intent' => 'pi_r10m_later', 'charge' => 'ch_r10m_later'], 'evt_r10m_ok', $r10mLater);
    assert_equals('refunded', $r10mState($r10mLater)[1], 'R10 M10: refund.updated succeeded books the refund');
    assert_equals(1000, PaymentService::refundedAmount($r10mLater), 'R10 M10: the succeeded refund is booked once (1000)');

    // M10: a failed SEPA debit marks the payment failed, and the provider status lets the unpaid order expire.
    $r10mStripe->responses['GET /v1/checkout/sessions/cs_r10m_sepa'] = ['status' => 'complete', 'payment_status' => 'unpaid', 'payment_intent' => 'pi_r10m_sepa'];
    $r10mStripe->responses['GET /v1/payment_intents/pi_r10m_sepa'] = ['status' => 'processing'];
    assert_equals('processing', $r10mStripe->getStatus('cs_r10m_sepa'), 'R10 M10: a SEPA debit still processing is not a final status');
    $r10mStripe->responses['GET /v1/payment_intents/pi_r10m_sepa'] = ['status' => 'requires_payment_method'];
    assert_equals('failed', $r10mStripe->getStatus('cs_r10m_sepa'), 'R10 M10: a failed SEPA debit (PaymentIntent back to requires_payment_method) is final failed');
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
        VALUES (?, 'R10M', 'r10m@example.test', '', '{}', 1000, 0, 0, 0, 1000, 'eur', 'pending', 'pending', 'stripe', 'cs_r10m_sepa', ?, ?, ?)")
        ->execute(['R10M-SEPA-' . bin2hex(random_bytes(4)), json_encode(['provider' => 'stripe', 'service_type' => 'online_card', 'requires_online' => true]),
            StoreContext::current()->id(), gmdate('Y-m-d H:i:s', time() - 3 * 86400)]);
    $r10mSepa = (int)$pdo->lastInsertId();
    applyStripeAsyncPaymentFailed(['id' => 'cs_r10m_sepa', 'payment_intent' => 'pi_r10m_sepa'], 'cs_r10m_sepa', $r10mSepa);
    assert_equals('failed', (string)Orders::byId($r10mSepa)['payment_status'], 'R10 M10: checkout.session.async_payment_failed marks the payment failed');
    Orders::expirePending(172800, 100, static fn(array $order): string => $r10mStripe->getStatus((string)$order['payment_id']));
    assert_equals('cancelled', (string)Orders::byId($r10mSepa)['status'], 'R10 M10: the failed SEPA order expires (stock released) instead of staying pending');
} finally {
    if (isset($r10mCredited)) {
        foreach ([$r10mCredited, $r10mRequested ?? 0, $r10mPending ?? 0, $r10mLater ?? 0, $r10mSepa ?? 0] as $r10mRefundOrder) {
            $pdo->exec('DELETE FROM order_refunds WHERE order_id = ' . (int)$r10mRefundOrder);
            $pdo->exec('DELETE FROM payment_transactions WHERE order_id = ' . (int)$r10mRefundOrder);
            $pdo->exec('DELETE FROM orders WHERE id = ' . (int)$r10mRefundOrder);
        }
    }
    $_SESSION = $r10mSession;
    foreach (['REQUEST_METHOD'] as $r10mKey) {
        if (array_key_exists($r10mKey, $r10mServer)) $_SERVER[$r10mKey] = $r10mServer[$r10mKey]; else unset($_SERVER[$r10mKey]);
    }
    $_POST = $r10mPost;
    $pdo->exec('DELETE FROM variants WHERE product_id = 9861');
    $pdo->exec('DELETE FROM products WHERE id = 9861');
}
