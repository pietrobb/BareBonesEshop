<?php

// Round 11 review (2026-11-01) hotfix: the payment link. H1 account takeover through the confirmation page, H2 a second
// gateway session paid as well, H7 an order expiring while its e-mailed link still promises time, the page after paying,
// and a session opened for an amount an edit has changed.
echo "\n--- Audit round 11 (hotfix): pay-link account takeover, double payment, link vs. expiry, return page, amount change ---\n";
if (!function_exists('guestAccountOfferView')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Storefront/GuestPages.php';
if (!function_exists('renderOrderDone')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Storefront/CheckoutPages.php';
$r11pCfgProp = new ReflectionProperty(Security::class, 'cfg');
$r11pCfgBefore = $r11pCfgProp->getValue();
$r11pCfgBase = is_array($r11pCfgBefore) ? $r11pCfgBefore : require dirname(TEST_CLI_DIR) . '/config.php';
$r11pInstances = new ReflectionProperty(Adapters::class, 'instances');
$r11pSubstituted = new ReflectionProperty(Adapters::class, 'substituted');
$r11pInstancesBefore = $r11pInstances->getValue();
$r11pSubstitutedBefore = $r11pSubstituted->getValue();
$r11pMailProp = new ReflectionProperty(MailService::class, 'adapterOverride');
$r11pMailBefore = $r11pMailProp->getValue();
$r11pRequestBefore = [$_GET, $_POST, $_SERVER['REQUEST_METHOD'] ?? 'GET', $GLOBALS['t'] ?? null];
$GLOBALS['t'] = static fn(string $key): string => I18n::t($key); // the storefront pages' translator (Storefront.php)
$r11pFail = static fn(string $what, Throwable $e) => assert_true(false, "R11 {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
$r11pStore = StoreContext::current()->id();
$r11pOrder = static function (string $paymentStatus, string $email, string $createdAt = '') use ($pdo, $r11pStore): array {
    $snapshot = ['provider' => 'stripe', 'service_type' => 'online_card', 'requires_online' => true, 'label' => 'Card'];
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
        VALUES (?, 'R11 Victim', ?, '+421900111222', '{\"street\":\"Victim street 1\"}', 1000, 0, 0, 0, 1000, 'eur', 'pending', ?, 'stripe', '', ?, ?, ?)")
        ->execute(['R11P-' . bin2hex(random_bytes(4)), $email, $paymentStatus, json_encode($snapshot), $r11pStore, $createdAt !== '' ? $createdAt : gmdate('Y-m-d H:i:s')]);
    return Orders::byId((int)$pdo->lastInsertId());
};
$r11pMailer = new class implements MailAdapter {
    public array $sent = [];
    public function send(array $msg): array { $this->sent[] = $msg; return ['status' => 'sent', 'message' => '', 'raw' => null]; }
    public function adapterId(): string { return 'r11p-capture'; }
};
// A hosted gateway whose sessions stay payable until it reports them otherwise; $cancellable decides whether it can void one.
$r11pGatewayClass = new class {
    public function make(bool $cancellable): PaymentAdapter {
        $base = new class implements PaymentAdapter {
            public int $calls = 0;
            public array $status = [];
            public array $amounts = [];
            public array $cancelled = [];
            public function createSession(array $order): array {
                $this->calls++;
                $id = 'cs_r11p_' . $this->calls . '_' . bin2hex(random_bytes(3));
                $this->status[$id] = 'open';
                $this->amounts[$id] = (int)$order['total'];
                return ['url' => 'https://pay.test/' . $id, 'session_id' => $id];
            }
            public function handleWebhook(array $payload): array { return []; }
            public function refund(string $paymentId, int $amount): array { return []; }
            public function getStatus(string $paymentId): string { return $this->status[$paymentId] ?? 'unknown'; }
        };
        if (!$cancellable) return $base;
        return new class($base) implements CancellablePaymentAdapter {
            public function __construct(public PaymentAdapter $inner) {}
            public array $cancelled = [];
            public function createSession(array $order): array { return $this->inner->createSession($order); }
            public function handleWebhook(array $payload): array { return []; }
            public function refund(string $paymentId, int $amount): array { return []; }
            public function getStatus(string $paymentId): string { return $this->inner->getStatus($paymentId); }
            public function cancelPayment(string $paymentId): void { $this->cancelled[] = $paymentId; $this->inner->status[$paymentId] = 'expired'; }
        };
    }
};
$r11pUseGateway = static function (PaymentAdapter $gateway) use ($r11pInstances): void {
    $r11pInstances->setValue(null, array_diff_key($r11pInstances->getValue(), ['payment' => true])); // the legacy slot would win over the substitute
    Adapters::set('payment.stripe', $gateway);
};
$r11pSessions = static fn(int $orderId): array => Db::get()->query("SELECT provider_ref, amount FROM payment_transactions WHERE status = 'initiated' AND order_id = {$orderId} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$r11pPaid = static fn(int $orderId, string $session, string $intent, int $amount = 1000): array => PaymentService::applyProviderStatusEvent([
    'event_type' => 'payment.paid', 'event_id' => 'evt_r11p_' . bin2hex(random_bytes(5)),
    'data' => ['order_id' => $orderId, 'id' => $session, 'payment_intent' => $intent, 'amount' => $amount, 'currency' => 'eur'],
], 'stripe');

try {
    $r11pCfgProp->setValue(null, ['app_url' => 'https://r11p.example.test'] + $r11pCfgBase);
    MailService::useAdapter($r11pMailer);
    Customers::logout();

    // H1: whoever reaches the confirmation page (a payment link, the status lookup by number + e-mail) cannot create
    // the account: the page takes no password, the account is created from the link e-mailed to the order's address.
    try {
        $r11pVictim = $r11pOrder('failed', 'r11p-victim@example.test');
        $r11pCustomers = static fn(string $email): int => (int)$pdo->query('SELECT COUNT(*) FROM customers WHERE email = ' . $pdo->quote($email))->fetchColumn();
        $r11pOffer = guestAccountOfferView($r11pVictim, []);
        $r11pHtml = Template::render('storefront/order-done', ['title' => 'Done', 'order_number' => 'x', 'account_offer' => $r11pOffer, 'home_url' => '/', 'continue_label' => 'Back']);
        assert_true($r11pOffer !== null && str_contains($r11pHtml, 'data-account-offer') && !str_contains($r11pHtml, 'type="password"'),
            'R11 H1: the confirmation page offers the account without a password field');
        assert_equals('sent', GuestCheckoutAccount::sendLink($r11pVictim, 'sk'), 'R11 H1: asking for the account e-mails a link');
        assert_equals([0, null], [$r11pCustomers('r11p-victim@example.test'), Orders::byId((int)$r11pVictim['id'])['customer_id'] ?? null],
            'R11 H1: asking creates no account and attaches no order');
        $r11pMail = end($r11pMailer->sent) ?: [];
        preg_match('~(https://r11p\.example\.test[^"\s<]*\?page=account-claim&(?:amp;)?token=)([a-f0-9]{64})~', (string)($r11pMail['html'] ?? ''), $r11pMatch);
        $r11pToken = (string)($r11pMatch[2] ?? '');
        assert_true(($r11pMail['to'] ?? '') === 'r11p-victim@example.test' && $r11pToken !== '', 'R11 H1: the link goes to the order\'s e-mail address only');
        assert_true(!str_contains((string)$pdo->query("SELECT payload FROM email_log WHERE template = 'account_offer' ORDER BY id DESC LIMIT 1")->fetchColumn(), $r11pToken),
            'R11 H1: the mail log keeps no usable link');
        assert_true(GuestCheckoutAccount::orderForToken(str_repeat('a', 64)) === null && GuestCheckoutAccount::orderForToken('../x') === null, 'R11 H1: an unknown or malformed link opens nothing');
        $r11pWeak = GuestCheckoutAccount::claim($r11pToken, 'short', 'sk');
        assert_true(!$r11pWeak['ok'] && $r11pCustomers('r11p-victim@example.test') === 0 && GuestCheckoutAccount::orderForToken($r11pToken) !== null,
            'R11 H1: a too-short password creates nothing and the link still works');
        $r11pClaim = GuestCheckoutAccount::claim($r11pToken, 'R11p-Strong-Pass', 'sk');
        $r11pCustomer = Customers::byId((int)($r11pClaim['customer_id'] ?? 0)) ?? [];
        assert_true($r11pClaim['ok'] && !empty($r11pCustomer['email_verified_at']), 'R11 H1: the link creates the account with the address verified (' . ($r11pClaim['error'] ?? 'ok') . ')');
        assert_equals([(int)$r11pClaim['customer_id'], (int)$r11pClaim['customer_id']], [(int)(Orders::byId((int)$r11pVictim['id'])['customer_id'] ?? 0), (int)($_SESSION['customer_id'] ?? 0)],
            'R11 H1: the order moves into the account and its owner is signed in');
        Customers::logout();
        assert_true(!GuestCheckoutAccount::claim($r11pToken, 'Other-Strong-Pass', 'sk')['ok'] && GuestCheckoutAccount::orderForToken($r11pToken) === null, 'R11 H1: the link works once');
        $r11pLate = $r11pOrder('pending', 'r11p-late@example.test');
        GuestCheckoutAccount::sendLink($r11pLate, 'sk');
        $pdo->prepare("UPDATE checkout_sessions SET created_at = ? WHERE provider = 'account_offer' AND order_id = ?")->execute([gmdate('Y-m-d H:i:s', time() - GuestCheckoutAccount::LINK_TTL - 60), (int)$r11pLate['id']]);
        preg_match('~token=([a-f0-9]{64})~', (string)((end($r11pMailer->sent) ?: [])['html'] ?? ''), $r11pMatch);
        assert_true(GuestCheckoutAccount::orderForToken((string)($r11pMatch[1] ?? '')) === null, 'R11 H1: the link lapses after its validity');
        foreach (['sk', 'cs', 'en', 'de', 'es', 'hu', 'pl'] as $r11pLocale) {
            $r11pTpl = (string)@file_get_contents(dirname(TEST_CLI_DIR) . "/templates/emails/{$r11pLocale}/account_offer.html");
            assert_true(str_contains($r11pTpl, '{{claim_url}}') && is_file(dirname(TEST_CLI_DIR) . "/templates/emails/{$r11pLocale}/account_offer.subject.txt"), "R11 H1: the {$r11pLocale} account-link e-mail exists");
        }
    } catch (Throwable $e) { $r11pFail('H1', $e); }

    // H2: "pay again" opens a second session only after the gateway says the first one can no longer be paid — the
    // gateway's word, not a 23-hour guess; a gateway that can void a session gets the stale one voided.
    try {
        $r11pGw = $r11pGatewayClass->make(false);
        $r11pUseGateway($r11pGw);
        $r11pRetry = $r11pOrder('failed', 'r11p-h2@example.test');
        $r11pFirst = PaymentLink::start($r11pRetry);
        $pdo->prepare("UPDATE payment_transactions SET created_at = ? WHERE order_id = ? AND status = 'initiated'")->execute([gmdate('Y-m-d H:i:s', time() - 2 * 86400), (int)$r11pRetry['id']]);
        assert_true($r11pFirst !== null && PaymentLink::start($r11pRetry) === $r11pFirst && $r11pGw->calls === 1,
            'R11 H2: a two-day-old session the gateway still takes payment on is reused, no second one is opened');
        $r11pGw->status[(string)$r11pSessions((int)$r11pRetry['id'])[0]['provider_ref']] = 'expired';
        $r11pSecond = PaymentLink::start($r11pRetry);
        assert_true($r11pSecond !== null && $r11pSecond !== $r11pFirst && $r11pGw->calls === 2, 'R11 H2: once the gateway reports it expired, a new session opens');
        $r11pGw->status[(string)$r11pSessions((int)$r11pRetry['id'])[1]['provider_ref']] = 'paid';
        assert_true(PaymentLink::start($r11pRetry) === null && $r11pGw->calls === 2, 'R11 H2: a session the gateway reports paid opens nothing (the capture is being confirmed)');
    } catch (Throwable $e) { $r11pFail('H2 sessions', $e); }

    // H2: the first capture books the order; the second session paid as well is money taken twice — incident, and the
    // order keeps the reference of the capture it was paid by.
    try {
        $r11pTwice = $r11pOrder('pending', 'r11p-twice@example.test');
        PaymentService::recordEvent((int)$r11pTwice['id'], 'payment.initiated', 'initiated', 1000, 'eur', 'r11p-init-a-' . $r11pTwice['id'], ['url' => 'https://pay.test/a'], 'stripe', 'cs_r11p_A_' . $r11pTwice['id']);
        PaymentService::recordEvent((int)$r11pTwice['id'], 'payment.initiated', 'initiated', 1000, 'eur', 'r11p-init-b-' . $r11pTwice['id'], ['url' => 'https://pay.test/b'], 'stripe', 'cs_r11p_B_' . $r11pTwice['id']);
        $pdo->prepare('UPDATE orders SET payment_id = ? WHERE id = ?')->execute(['cs_r11p_B_' . $r11pTwice['id'], (int)$r11pTwice['id']]);
        $r11pPaid((int)$r11pTwice['id'], 'cs_r11p_A_' . $r11pTwice['id'], 'pi_r11p_A_' . $r11pTwice['id']);
        assert_equals(['paid', 'pi_r11p_A_' . $r11pTwice['id']], [Orders::byId((int)$r11pTwice['id'])['payment_status'] ?? '', Orders::byId((int)$r11pTwice['id'])['payment_id'] ?? ''],
            'R11 H2: the first capture pays the order under its own reference');
        $r11pPaid((int)$r11pTwice['id'], 'pi_r11p_A_' . $r11pTwice['id'], 'pi_r11p_A_' . $r11pTwice['id']);
        assert_true(!IncidentMonitor::reportedForOrder('payment.duplicate_capture', (int)$r11pTwice['id']), 'R11 H2: a redelivery of the same capture is no incident');
        $r11pPaid((int)$r11pTwice['id'], 'cs_r11p_B_' . $r11pTwice['id'], 'pi_r11p_B_' . $r11pTwice['id']);
        assert_true(IncidentMonitor::reportedForOrder('payment.duplicate_capture', (int)$r11pTwice['id']), 'R11 H2: the second session paid as well raises the double-payment incident');
        assert_equals('pi_r11p_A_' . $r11pTwice['id'], Orders::byId((int)$r11pTwice['id'])['payment_id'] ?? '', 'R11 H2: the second capture does not replace the order\'s payment reference');
    } catch (Throwable $e) { $r11pFail('H2 capture', $e); }

    // Amount change: a session opened for the old total is voided before one for the new total opens; a capture of
    // another amount is never booked and raises an incident.
    try {
        $r11pVoid = $r11pGatewayClass->make(true);
        $r11pUseGateway($r11pVoid);
        $r11pEdited = $r11pOrder('pending', 'r11p-edit@example.test');
        $r11pOld = PaymentLink::start($r11pEdited);
        $pdo->prepare('UPDATE orders SET total = 1500 WHERE id = ?')->execute([(int)$r11pEdited['id']]);
        $r11pNew = PaymentLink::start(Orders::byId((int)$r11pEdited['id']) ?? []);
        $r11pList = $r11pSessions((int)$r11pEdited['id']);
        assert_true($r11pOld !== null && $r11pNew !== null && $r11pNew !== $r11pOld && count($r11pList) === 2 && (int)$r11pList[1]['amount'] === 1500,
            'R11 amount change: a new session opens for the new total');
        assert_equals([(string)$r11pList[0]['provider_ref']], $r11pVoid->cancelled, 'R11 amount change: the session for the old total is voided at the gateway');
        $r11pMismatch = $r11pOrder('pending', 'r11p-mismatch@example.test');
        try { $r11pPaid((int)$r11pMismatch['id'], 'cs_r11p_M', 'pi_r11p_M', 900); } catch (RuntimeException) {}
        assert_true(Orders::byId((int)$r11pMismatch['id'])['payment_status'] === 'pending' && IncidentMonitor::reportedForOrder('payment.amount_mismatch', (int)$r11pMismatch['id']),
            'R11 amount change: a capture of another amount is not booked and raises an incident');
    } catch (Throwable $e) { $r11pFail('amount change', $e); }

    // H7: the e-mailed link promises days to pay; the order waits for it instead of expiring after 48 hours.
    try {
        $r11pAge = gmdate('Y-m-d H:i:s', time() - 3 * 86400);
        $r11pLinked = $r11pOrder('pending', 'r11p-h7@example.test', $r11pAge);
        $r11pUnlinked = $r11pOrder('pending', 'r11p-h7-none@example.test', $r11pAge);
        PaymentLink::issue($r11pLinked);
        Orders::expirePending(172800, 100, static fn(array $o): string => 'expired');
        assert_equals(['pending', 'cancelled'], [Orders::byId((int)$r11pLinked['id'])['status'] ?? '', Orders::byId((int)$r11pUnlinked['id'])['status'] ?? ''],
            'R11 H7: an order with a live payment link is not expired; one without a link still is');
        $pdo->prepare("UPDATE checkout_sessions SET created_at = ? WHERE provider = 'pay_link' AND order_id = ?")->execute([gmdate('Y-m-d H:i:s', time() - PaymentLink::LINK_TTL - 60), (int)$r11pLinked['id']]);
        Orders::expirePending(172800, 100, static fn(array $o): string => 'expired');
        assert_equals('cancelled', Orders::byId((int)$r11pLinked['id'])['status'] ?? '', 'R11 H7: after the link lapses the order expires');
    } catch (Throwable $e) { $r11pFail('H7', $e); }

    // The page after paying a session opened after an earlier failure: the order is still 'failed' until the
    // notification arrives, but the gateway reports it paid — "pending", no "payment failed", no second "Pay".
    try {
        $r11pBack = $r11pGatewayClass->make(false);
        $r11pUseGateway($r11pBack);
        $r11pReturn = $r11pOrder('pending', 'r11p-return@example.test');
        PaymentService::recordEvent((int)$r11pReturn['id'], 'payment.initiated', 'initiated', 1000, 'eur', 'r11p-init-r-' . $r11pReturn['id'], [], 'stripe', 'cs_r11p_R0_' . $r11pReturn['id']);
        PaymentService::transitionPayment((int)$r11pReturn['id'], 'failed', 'payment.failed', 1000, 'r11p-fail-r-' . $r11pReturn['id']);
        $r11pBack->status['cs_r11p_R0_' . $r11pReturn['id']] = 'failed';
        $r11pUrl = PaymentLink::start(Orders::byId((int)$r11pReturn['id']) ?? []);
        $r11pLast = static fn(): string => (string)(array_slice($r11pSessions((int)$r11pReturn['id']), -1)[0]['provider_ref'] ?? '');
        $r11pBack->status[$r11pLast()] = 'paid';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        $_GET = ['page' => 'order-done', 'order' => (string)$r11pReturn['order_number'],
            'confirmation_token' => CheckoutSession::createConfirmation((int)$r11pReturn['id'], CheckoutSession::browserSessionId())];
        $r11pPage = renderOrderDone((string)$r11pReturn['order_number']);
        assert_true($r11pUrl !== null && str_contains($r11pPage, 'badge-pending') && !str_contains($r11pPage, 'badge-failed')
            && !str_contains($r11pPage, 'data-payment-error') && !str_contains($r11pPage, 'data-pending-payment-link'),
            'R11 return page: a paid, not yet confirmed retry shows pending, no failure and no second "Pay"');
        $r11pBack->status[$r11pLast()] = 'expired';
        assert_equals('failed', PaymentLink::returnStatus(Orders::byId((int)$r11pReturn['id']) ?? []), 'R11 return page: a retry the gateway reports expired is still failed');
    } catch (Throwable $e) { $r11pFail('return page', $e); }

    // H1 end to end, last: the confirmation page reached through the payment link with the attacker's own password —
    // after a failed payment, on an order entered in the admin, and on an order whose gateway session is over 23 h old.
    // The vulnerable page signed the visitor in and redirected (exit) into the victim's order: report that, not silence.
    $r11pH1Done = false;
    $r11pEmail = '';
    register_shutdown_function(static function () use (&$r11pH1Done, &$r11pEmail, $pdo): void {
        if ($r11pH1Done) return;
        $r11pLeft = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE email = ?');
        $r11pLeft->execute([$r11pEmail]);
        echo "  [FAIL] R11 H1: the posted confirmation page left the request (redirect) with " . (int)$r11pLeft->fetchColumn() . " account(s) created for {$r11pEmail}\n";
        exit(1);
    });
    foreach (['failed' => 'failed', 'manual' => 'pending', 'old session' => 'pending'] as $r11pVariant => $r11pStatus) {
        try {
            $r11pEmail = 'r11p-target-' . str_replace(' ', '-', $r11pVariant) . '@example.test';
            $r11pTarget = $r11pOrder($r11pStatus, $r11pEmail);
            if ($r11pVariant === 'manual') AuditLog::log('order', (int)$r11pTarget['id'], null, ['source' => 'admin'], 'Manual order entered in the admin');
            if ($r11pVariant === 'old session') {
                PaymentService::initiate(['order_id' => (int)$r11pTarget['id'], 'order_number' => $r11pTarget['order_number'], 'total' => 1000, 'currency' => 'eur'], $r11pGatewayClass->make(false), 'stripe');
                $r11pAged = $pdo->prepare('UPDATE payment_transactions SET created_at = ? WHERE order_id = ?');
                $r11pAged->execute([gmdate('Y-m-d H:i:s', time() - 30 * 3600), (int)$r11pTarget['id']]);
                assert_equals(1, $r11pAged->rowCount(), 'R11 H1 (old session): the order has a gateway session opened 30 h ago');
            }
            $r11pMailsBefore = count($r11pMailer->sent);
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['action' => 'create_account', 'csrf_token' => Security::csrfToken(), 'password' => 'Attacker-Pass-11'];
            $_GET = ['page' => 'order-done', 'order' => (string)$r11pTarget['order_number'],
                'confirmation_token' => CheckoutSession::createConfirmation((int)$r11pTarget['id'], CheckoutSession::browserSessionId())];
            $r11pPage = @renderOrderDone((string)$r11pTarget['order_number']);
            $r11pAccounts = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE email = ?');
            $r11pAccounts->execute([$r11pEmail]);
            assert_true((int)$r11pAccounts->fetchColumn() === 0 && empty($_SESSION['customer_id'])
                && Orders::byId((int)$r11pTarget['id'])['customer_id'] === null, "R11 H1 ({$r11pVariant}): the posted confirmation page creates no account with the visitor's password");
            assert_true(str_contains($r11pPage, 'data-account-offer-sent') && count($r11pMailer->sent) === $r11pMailsBefore + 1
                && (end($r11pMailer->sent)['to'] ?? '') === $r11pEmail, "R11 H1 ({$r11pVariant}): it only e-mails the link to the order's address");
        } catch (Throwable $e) { $r11pFail("H1 page {$r11pVariant}", $e); }
    }
    $r11pH1Done = true;
} finally {
    [$_GET, $_POST, $_SERVER['REQUEST_METHOD'], $GLOBALS['t']] = $r11pRequestBefore;
    Customers::logout();
    $r11pMailProp->setValue(null, $r11pMailBefore);
    $r11pInstances->setValue(null, $r11pInstancesBefore);
    $r11pSubstituted->setValue(null, $r11pSubstitutedBefore);
    $r11pCfgProp->setValue(null, $r11pCfgBefore);
}
