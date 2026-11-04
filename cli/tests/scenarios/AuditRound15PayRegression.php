<?php

// Review round 15 (2026-11-04), payments. (1) The expiry sweep asked the gateway about the order's attempts, then a
// "pay again" opened a new session, and the sweep cancelled the order anyway: the customer paid a cancelled order.
// (2) The payment webhook route took only [a-z0-9_] provider codes, so an extension provider such as acme-pay (the code
// the extension docs use) got 404. (3) At a provider whose checkout session id is not its payment id the order kept the
// session id after the capture, and refunds went to the session instead of the payment.
echo "\n--- Audit round 15: payments (expiry vs pay again, provider codes, refund reference) ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r15pFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R15 pay {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r15pOrders = [];
$r15pOrder = static function (string $tag, string $provider, string $paymentId, string $createdAt) use ($pdo, &$r15pOrders): int {
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency,
            status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
        VALUES (?, 'R15P', 'r15p@example.test', '', '{}', 1000, 0, 0, 0, 1000, 'eur', 'pending', 'pending', ?, ?, ?, 'default', ?)")
        ->execute(['R15P-' . $tag . '-' . bin2hex(random_bytes(3)), $provider, $paymentId,
            json_encode(['code' => $provider, 'provider' => $provider, 'service_type' => 'online_card', 'requires_online' => true]), $createdAt]);
    return $r15pOrders[] = (int)$pdo->lastInsertId();
};
$r15pAttempt = static function (int $orderId, string $provider, string $ref) use ($pdo): void {
    $pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response)
        VALUES (?, 'default', ?, ?, 'initiated', 1000, 'eur', ?, '{}')")->execute([$orderId, $provider, $ref, 'r15p-init-' . $ref]);
};
try {
    $r15pOld = gmdate('Y-m-d H:i:s', time() - 10 * 86400);
    // (1a) While the gateway is asked about the expired attempt, "pay again" opens a new session and points the order at it.
    $r15pReopened = $r15pOrder('reopened', 'mock', 'mock_r15p_1', $r15pOld);
    $r15pAttempt($r15pReopened, 'mock', 'mock_r15p_1');
    // (1b) ... or has claimed its attempt and is still waiting for the gateway to create the session.
    $r15pClaimed = $r15pOrder('claimed', 'mock', 'mock_r15p_2', $r15pOld);
    $r15pAttempt($r15pClaimed, 'mock', 'mock_r15p_2');
    $r15pDone = false;
    $r15pResolver = static function (array $order) use ($pdo, $r15pReopened, $r15pClaimed, $r15pAttempt, &$r15pDone): string {
        if (!$r15pDone && (int)$order['id'] === $r15pReopened) {
            $r15pAttempt($r15pReopened, 'mock', 'mock_r15p_1b');
            $pdo->prepare("UPDATE orders SET payment_id = 'mock_r15p_1b' WHERE id = ?")->execute([$r15pReopened]);
        }
        if (!$r15pDone && (int)$order['id'] === $r15pClaimed) {
            $pdo->prepare("INSERT INTO checkout_sessions (order_id, provider, session_id, status) VALUES (?, 'pay_restart', ?, 'completed')")
                ->execute([$r15pClaimed, $r15pClaimed . ':1']);
        }
        return in_array((int)$order['id'], [$r15pReopened, $r15pClaimed], true) ? 'expired' : 'created';
    };
    Orders::expirePending(3600, 100, $r15pResolver);
    assert_equals(['pending', 'pending'], [(string)Orders::byId($r15pReopened)['status'], (string)Orders::byId($r15pClaimed)['status']],
        'R15 pay: an order whose "pay again" opened (or is opening) a session after the gateway was asked is not expired (was: cancelled, the new session still payable)');
    // Once the new session is over too (and no attempt is in flight), the order expires as before.
    $r15pDone = true;
    $pdo->prepare("UPDATE checkout_sessions SET created_at = ? WHERE order_id = ? AND provider = 'pay_restart'")->execute([gmdate('Y-m-d H:i:s', time() - 3600), $r15pClaimed]);
    Orders::expirePending(3600, 100, $r15pResolver);
    assert_equals(['cancelled', 'cancelled'], [(string)Orders::byId($r15pReopened)['status'], (string)Orders::byId($r15pClaimed)['status']],
        'R15 pay: with every attempt over and none in flight the order expires');
} catch (Throwable $e) {
    $r15pFail('expiry vs pay again', $e);
}

try {
    // (2) The webhook route and its handler take the provider code shape the adapters accept.
    $r15pRoute = ApiRoute::resolve('/api/v1/webhooks/payment/acme-pay', '', []);
    assert_equals(['webhook_payment', 'acme-pay'], [(string)($r15pRoute['action'] ?? ''), (string)($r15pRoute['get']['provider'] ?? '')],
        'R15 pay: /api/v1/webhooks/payment/acme-pay reaches the payment webhook (was: 404)');
    $r15pHandler = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php');
    preg_match("/function apiWebhookPayment\(\): array \{.*?preg_match\('([^']+)', \\\$provider\)/s", $r15pHandler, $r15pPattern);
    assert_true(isset($r15pPattern[1]) && preg_match($r15pPattern[1], 'acme-pay') === 1 && preg_match($r15pPattern[1], '../x') !== 1,
        'R15 pay: the payment webhook handler accepts acme-pay and still refuses other characters (was: "Invalid provider")');
} catch (Throwable $e) {
    $r15pFail('provider code', $e);
}

try {
    // (3) A provider whose session (acs_…) is not its payment (apay_…): the capture of the order's own session names the payment.
    $r15pAcme = $r15pOrder('acme', 'mock', 'acs_r15p_session', gmdate('Y-m-d H:i:s'));
    $r15pAttempt($r15pAcme, 'mock', 'acs_r15p_session');
    PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => 'evt_r15p_acme',
        'data' => ['order_id' => $r15pAcme, 'amount' => 1000, 'currency' => 'eur', 'payment_id' => 'apay_r15p_payment']], 'mock');
    $r15pRow = Orders::byId($r15pAcme);
    assert_equals(['paid', 'apay_r15p_payment'], [(string)$r15pRow['payment_status'], (string)$r15pRow['payment_id']],
        'R15 pay: after the capture the order holds the payment id, so its refund goes to the payment (was: the checkout session id)');
    // A reference the order did not open as an attempt is still not replaced by a notification (R8).
    $r15pForeign = $r15pOrder('foreign', 'mock', 'mock_r15p_held', gmdate('Y-m-d H:i:s'));
    PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => 'evt_r15p_foreign',
        'data' => ['order_id' => $r15pForeign, 'amount' => 1000, 'currency' => 'eur', 'payment_id' => 'mock_r15p_other']], 'mock');
    assert_equals('mock_r15p_held', (string)Orders::byId($r15pForeign)['payment_id'], 'R15 pay: a held reference that is no attempt of the order is kept (R8)');
} catch (Throwable $e) {
    $r15pFail('refund reference', $e);
} finally {
    foreach ($r15pOrders as $r15pId) {
        foreach (['checkout_sessions' => 'order_id = ?', 'payment_transactions' => 'order_id = ?', 'audit_log' => "entity_id = ? AND action LIKE 'payment%'", 'orders' => 'id = ?'] as $r15pTable => $r15pWhere) {
            try { $pdo->prepare("DELETE FROM {$r15pTable} WHERE {$r15pWhere}")->execute([$r15pId]); } catch (Throwable $e) {}
        }
    }
}
