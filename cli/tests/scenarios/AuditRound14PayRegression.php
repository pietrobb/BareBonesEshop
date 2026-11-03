<?php

// Review round 14 (kolo 6), finding 3: a payment retry pointed payment_id at its new gateway session. A customer who
// paid the older attempt left the order paid with the reference of the unpaid one (refunds went to it and failed), and
// with that notification lost the order expired although the money had left: expiry asked about the newest attempt only.
echo "\n--- Audit round 14: payments (capture of an older attempt, expiry over every attempt) ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r14pFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R14 pay {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r14pOrders = [];
$r14pOrder = static function (string $tag, string $provider, string $paymentId, string $createdAt) use ($pdo, &$r14pOrders): int {
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency,
            status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
        VALUES (?, 'R14P', 'r14p@example.test', '', '{}', 1000, 0, 0, 0, 1000, 'eur', 'pending', 'pending', ?, ?, ?, 'default', ?)")
        ->execute(['R14P-' . $tag . '-' . bin2hex(random_bytes(3)), $provider, $paymentId,
            json_encode(['code' => $provider, 'provider' => $provider, 'service_type' => 'online_card', 'requires_online' => true]), $createdAt]);
    return $r14pOrders[] = (int)$pdo->lastInsertId();
};
$r14pAttempt = static function (int $orderId, string $provider, string $ref) use ($pdo): void {
    $pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response)
        VALUES (?, 'default', ?, ?, 'initiated', 1000, 'eur', ?, '{}')")->execute([$orderId, $provider, $ref, 'r14p-init-' . $ref]);
};
try {
    // The customer pays the first PayU attempt after a retry opened a second one.
    $r14pPaid = $r14pOrder('older', 'payu', 'PAYU-R14P-NEW', gmdate('Y-m-d H:i:s'));
    $r14pAttempt($r14pPaid, 'payu', 'PAYU-R14P-OLD');
    $r14pAttempt($r14pPaid, 'payu', 'PAYU-R14P-NEW');
    PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => 'evt_r14p_older',
        'data' => ['order_id' => $r14pPaid, 'amount' => 1000, 'currency' => 'eur', 'orderId' => 'PAYU-R14P-OLD']], 'payu');
    $r14pRow = Orders::byId($r14pPaid);
    assert_equals(['paid', 'PAYU-R14P-OLD'], [(string)$r14pRow['payment_status'], (string)$r14pRow['payment_id']],
        'R14 #3: the order paid through an older attempt names that attempt as its payment (was: the unpaid newer session, so refunds failed)');

    // Stripe: a checkout session id never replaces the payment intent of the capture.
    $r14pStripe = $r14pOrder('stripe', 'stripe', 'cs_r14p_new', gmdate('Y-m-d H:i:s'));
    PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => 'evt_r14p_stripe',
        'data' => ['order_id' => $r14pStripe, 'amount' => 1000, 'currency' => 'eur', 'payment_intent' => 'pi_r14p_old']], 'stripe');
    assert_equals('pi_r14p_old', (string)Orders::byId($r14pStripe)['payment_id'], 'R14 #3: a Stripe capture records its payment intent');

    // Expiry: the newest attempt expired, the older one is still payable (or paid, its notification lost).
    $r14pOld = gmdate('Y-m-d H:i:s', time() - 10 * 86400);
    $r14pExpiring = $r14pOrder('expiry', 'mock', 'mock_r14p_new', $r14pOld);
    $r14pAttempt($r14pExpiring, 'mock', 'mock_r14p_old');
    $r14pAttempt($r14pExpiring, 'mock', 'mock_r14p_new');
    $r14pStates = ['mock_r14p_new' => 'expired', 'mock_r14p_old' => 'paid'];
    $r14pAsked = [];
    $r14pResolver = static function (array $order) use (&$r14pStates, &$r14pAsked, $r14pExpiring): string {
        if ((int)$order['id'] !== $r14pExpiring) return 'created';
        $r14pAsked[] = (string)$order['payment_id'];
        return $r14pStates[(string)$order['payment_id']] ?? 'created';
    };
    Orders::expirePending(3600, 100, $r14pResolver);
    assert_equals('pending', (string)Orders::byId($r14pExpiring)['status'],
        'R14 #3: an order whose older attempt the gateway reports paid does not expire (was: only the newest attempt was asked)');
    assert_true(in_array('mock_r14p_old', $r14pAsked, true), 'R14 #3: expiry asks the gateway about the older attempt too');
    $r14pStates['mock_r14p_old'] = 'expired';
    Orders::expirePending(3600, 100, $r14pResolver);
    assert_equals('cancelled', (string)Orders::byId($r14pExpiring)['status'], 'R14 #3: once every attempt is over the order expires');
} catch (Throwable $e) {
    $r14pFail('finding 3', $e);
} finally {
    foreach ($r14pOrders as $r14pId) {
        foreach (['payment_transactions' => 'order_id = ?', 'audit_log' => "entity_id = ? AND action LIKE 'payment%'", 'orders' => 'id = ?'] as $r14pTable => $r14pWhere) {
            try { $pdo->prepare("DELETE FROM {$r14pTable} WHERE {$r14pWhere}")->execute([$r14pId]); } catch (Throwable $e) {}
        }
    }
}

// T18 (c): back from the gateway after the newest attempt failed, the page asked only about that attempt: an older one
// the customer paid (its notification not here yet) was shown as failed with a second "Pay".
$r14pInstances = new ReflectionProperty(Adapters::class, 'instances');
$r14pSubstituted = new ReflectionProperty(Adapters::class, 'substituted');
$r14pAdaptersBefore = [$r14pInstances->getValue(), $r14pSubstituted->getValue()];
try {
    $r14pGateway = new class implements PaymentAdapter {
        public array $status = [];
        public function createSession(array $order): array { return []; }
        public function handleWebhook(array $payload): array { return []; }
        public function refund(string $paymentId, int $amount): array { return []; }
        public function getStatus(string $paymentId): string { return $this->status[$paymentId] ?? 'unknown'; }
    };
    $r14pInstances->setValue(null, array_diff_key($r14pInstances->getValue(), ['payment' => true]));
    Adapters::set('payment.stripe', $r14pGateway);
    $r14pBack = $r14pOrder('return', 'stripe', 'cs_r14p_ret_new', gmdate('Y-m-d H:i:s'));
    $r14pAttempt($r14pBack, 'stripe', 'cs_r14p_ret_old');
    $r14pAttempt($r14pBack, 'stripe', 'cs_r14p_ret_new');
    $pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response)
        VALUES (?, 'default', 'stripe', 'cs_r14p_ret_new', 'failed', 0, 'eur', 'r14p-ret-failed', '{\"event_type\":\"payment.failed\"}')")->execute([$r14pBack]);
    $pdo->prepare("UPDATE orders SET payment_status = 'failed' WHERE id = ?")->execute([$r14pBack]);
    $r14pGateway->status = ['cs_r14p_ret_old' => 'paid', 'cs_r14p_ret_new' => 'failed'];
    assert_equals('pending', PaymentLink::returnStatus(Orders::byId($r14pBack) ?? []),
        'R14 T18: the return page shows an order whose older attempt the gateway reports paid as being confirmed (was: failed, with a second "Pay")');
    $r14pGateway->status['cs_r14p_ret_old'] = 'expired';
    assert_equals('failed', PaymentLink::returnStatus(Orders::byId($r14pBack) ?? []), 'R14 T18: with every attempt over the return page still says failed');
} catch (Throwable $e) {
    $r14pFail('T18 return page', $e);
} finally {
    [$r14pA, $r14pB] = $r14pAdaptersBefore;
    $r14pInstances->setValue(null, $r14pA);
    $r14pSubstituted->setValue(null, $r14pB);
    foreach ($r14pOrders as $r14pId) {
        foreach (['payment_transactions' => 'order_id = ?', 'orders' => 'id = ?'] as $r14pTable => $r14pWhere) {
            try { $pdo->prepare("DELETE FROM {$r14pTable} WHERE {$r14pWhere}")->execute([$r14pId]); } catch (Throwable $e) {}
        }
    }
}

// T18 (a): the Stripe completion read the order's payment state and wrote payment_id before any lock: two sessions
// completing at once both saw "pending", the later overwrote payment_id and its second capture was never reported.
$r14pStripeSrc = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php');
$r14pStripeFn = substr($r14pStripeSrc, (int)strpos($r14pStripeSrc, 'function applyStripePaymentCompleted('));
$r14pStripeFn = substr($r14pStripeFn, 0, (int)strpos($r14pStripeFn, 'function onChargeRefunded('));
$r14pLockAt = strpos($r14pStripeFn, 'Orders::lockWithin(');
assert_true($r14pLockAt !== false && strpos($r14pStripeFn, 'reportForeignCapture(', $r14pLockAt) !== false && strpos($r14pStripeFn, 'Orders::setPaymentId(', $r14pLockAt) !== false
    && strpos($r14pStripeFn, 'reportForeignCapture(') > $r14pLockAt && strpos($r14pStripeFn, 'Orders::byId(', $r14pLockAt) !== false,
    'R14 T18: the Stripe completion re-reads the order, checks a second capture and records payment_id under the order lock');

// T2 (partial in round 13): a hidden store answered its webhooks, but the payment link and the pages the gateway returns
// to were 404, so its (test) order could be neither paid nor confirmed. Its catalog and cart stay hidden.
try {
    $r14pDir = test_fixture_path('r14p-hidden-store');
    if (!is_dir($r14pDir)) mkdir($r14pDir, 0700, true);
    $r14pScript = $r14pDir . '/probe.php';
    file_put_contents($r14pScript, '<?php
require ' . var_export(dirname(TEST_CLI_DIR) . '/src/autoload.php', true) . ';
$uri = $argv[1];
parse_str((string)parse_url($uri, PHP_URL_QUERY), $get);
$post = json_decode($argv[2], true);
$stores = ["default_store" => "default", "stores" => [
    "default" => ["name" => "default", "default_locale" => "en", "languages" => ["en"], "currency" => "eur", "url_prefix" => "", "domains" => [], "permissions" => ["*"], "channels" => ["storefront", "api"]],
    "hx" => ["name" => "hx", "default_locale" => "en", "languages" => ["en"], "currency" => "eur", "url_prefix" => "/hx", "domains" => [], "permissions" => ["*"], "channels" => ["storefront", "api"], "public" => false],
]];
(new ReflectionProperty(Bootstrap::class, "stores"))->setValue(null, $stores);
Bootstrap::initLocale($uri, Bootstrap::servesHiddenOrderPage($get, $post));
echo "reached " . StoreContext::current()->id();
');
    $r14pProbe = static function (string $uri, array $post = []) use ($r14pScript): string {
        $process = proc_open([PHP_BINARY, $r14pScript, $uri, json_encode($post)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return trim($out);
    };
    assert_equals(['reached hx', 'reached hx', 'reached hx'],
        [$r14pProbe('/hx/?page=pay&token=x'), $r14pProbe('/hx/?page=order-done&order=X&confirmation_token=y'), $r14pProbe('/hx/?page=checkout-cancel&order=X')],
        'R14 T2: a hidden store opens its payment link and the pages its gateway returns to (was 404)');
    assert_equals(['Not Found', 'Not Found', 'Not Found'], [$r14pProbe('/hx/'), $r14pProbe('/hx/?page=checkout'), $r14pProbe('/hx/?page=pay', ['cart_action' => 'add'])],
        'R14 T2: its catalog, checkout and cart actions stay hidden');
    $r14pFront = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Storefront.php');
    assert_true(str_contains($r14pFront, 'Bootstrap::initLocale(null, Bootstrap::servesHiddenOrderPage($_GET, $_POST))'),
        'R14 T2: the storefront entry asks which order pages a hidden store serves');
} catch (Throwable $e) {
    $r14pFail('T2', $e);
}

// Low: the page a payment link's gateway returns to showed the order summary with the customer's name and delivery
// address to whoever held the link (more than the order lookup shows). Only the browser that placed the order sees it.
try {
    $r14pAddrOrder = $r14pOrder('address', 'stripe', '', gmdate('Y-m-d H:i:s'));
    $pdo->prepare('UPDATE orders SET shipping_addr = ? WHERE id = ?')->execute([json_encode(['street' => 'R14P Tajná 7', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK']), $r14pAddrOrder]);
    $r14pBrowser = 'r14p-browser-' . bin2hex(random_bytes(4));
    $r14pPlacedToken = CheckoutSession::createConfirmation($r14pAddrOrder, $r14pBrowser);
    $r14pLinkToken = CheckoutSession::createConfirmation($r14pAddrOrder, $r14pBrowser, false);
    assert_equals([true, true, true, false], [
        CheckoutSession::verifyConfirmation($r14pAddrOrder, $r14pPlacedToken, $r14pBrowser), CheckoutSession::verifyConfirmation($r14pAddrOrder, $r14pLinkToken, $r14pBrowser),
        CheckoutSession::confirmsPlacedOrder($r14pAddrOrder, $r14pPlacedToken, $r14pBrowser), CheckoutSession::confirmsPlacedOrder($r14pAddrOrder, $r14pLinkToken, $r14pBrowser),
    ], 'R14 low: both confirmations open the order page, only the checkout one proves the browser placed the order');
    $r14pAddrRow = Orders::byId($r14pAddrOrder) ?? [];
    $r14pWith = implode(' ', AccountViewModel::orderDoneSummary($r14pAddrRow)['address']);
    $r14pWithout = AccountViewModel::orderDoneSummary($r14pAddrRow, false);
    assert_true(str_contains($r14pWith, 'R14P Tajná 7') && $r14pWithout['address'] === [] && $r14pWithout['total'] !== '',
        'R14 low: the summary after paying through a link shows the order without the name and delivery address');
    $r14pPages = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Storefront/CheckoutPages.php');
    $r14pLink = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/PaymentLink.php');
    assert_true(str_contains($r14pPages, 'orderDoneSummary($order, CheckoutSession::confirmsPlacedOrder(') && str_contains($r14pLink, 'createConfirmation($orderId, CheckoutSession::browserSessionId(), false)'),
        'R14 low: the payment link issues a payer confirmation and the order page shows the address only for the placing browser');
} catch (Throwable $e) {
    $r14pFail('order summary address', $e);
} finally {
    foreach ($r14pOrders as $r14pId) {
        foreach (['checkout_sessions' => 'order_id = ?', 'payment_transactions' => 'order_id = ?', 'orders' => 'id = ?'] as $r14pTable => $r14pWhere) {
            try { $pdo->prepare("DELETE FROM {$r14pTable} WHERE {$r14pWhere}")->execute([$r14pId]); } catch (Throwable $e) {}
        }
    }
}

// T20: PayU notifications signed with SHA-256 (a POS setting) were all rejected; only MD5 was accepted.
require_once dirname(TEST_CLI_DIR) . '/adapters/contrib/PayUPayment.php';
$r14pKey = 'r14p-second-key';
$r14pPayU = new PayUPayment(['payu_second_key' => $r14pKey, 'payu_client_secret' => 'r14p-client-secret']);
$r14pBody = '{"order":{"orderId":"R14P","status":"COMPLETED"}}';
$r14pSigned = static fn(string $algo, string $hash, string $body = '', string $key = ''): bool
    => $r14pPayU->verifySignature($r14pBody, 'sender=checkout;signature=' . hash($hash, ($body ?: $r14pBody) . ($key ?: $r14pKey)) . ";algorithm={$algo};content=DOCUMENT");
assert_equals([true, true, true], [$r14pSigned('SHA-256', 'sha256'), $r14pSigned('SHA256', 'sha256'), $r14pSigned('MD5', 'md5')],
    'R14 T20: PayU notifications signed with SHA-256 (either spelling) are accepted, MD5 still is');
assert_equals([false, false, false, false], [$r14pSigned('SHA-256', 'sha256', $r14pBody . ' '), $r14pSigned('SHA-256', 'sha256', '', 'r14p-client-secret'),
    $r14pSigned('SHA-256', 'md5'), $r14pSigned('MD5', 'sha256')],
    'R14 T20: a tampered body, another key or a digest not matching the named algorithm is rejected');
