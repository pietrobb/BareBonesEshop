<?php

// Round 10 review (2026-10-25): UX 1–5 — setup checklist, unconfigured gateways, pay again, stores and legal texts.
// UX4 (seeder slugs) is checked end to end in AuditInstallSmokeRegression.
echo "\n--- Audit round 10 (UX 1-5): setup checklist, keyless gateways hidden, pay again, store and legal-text admin ---\n";
$r10uCfgProp = new ReflectionProperty(Security::class, 'cfg');
$r10uCfgBefore = $r10uCfgProp->getValue();
$r10uCfgBase = is_array($r10uCfgBefore) ? $r10uCfgBefore : require dirname(TEST_CLI_DIR) . '/config.php';
$r10uInstances = new ReflectionProperty(Adapters::class, 'instances');
$r10uSubstituted = new ReflectionProperty(Adapters::class, 'substituted');
$r10uInstancesBefore = $r10uInstances->getValue();
$r10uSubstitutedBefore = $r10uSubstituted->getValue();
$r10uTmp = sys_get_temp_dir() . '/r10u-' . getmypid() . '-' . bin2hex(random_bytes(3));
$r10uFail = static fn(string $what, Throwable $e) => assert_true(false, "R10 UX {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
$r10uStore = StoreContext::current()->id();
try {
    // UX1: the dashboard lists what doctor still flags, each with the admin page that fixes it; another store's
    // per-store checks (its SKUs, its seller) stay out of the store the dashboard is switched to.
    try {
        $r10uSummary = SetupChecklist::summary(['checks' => [
            ['status' => 'pass', 'name' => 'runtime.php', 'message' => 'PHP ok', 'action' => ''],
            ['status' => 'warn', 'name' => 'stores.currency_prices.' . $r10uStore, 'message' => 'own gap', 'action' => 'price it'],
            ['status' => 'warn', 'name' => 'stores.currency_prices.r10u-foreign', 'message' => 'R10U-FOREIGN-SKU', 'action' => ''],
            ['status' => 'fail', 'name' => 'stores.legal.r10u-foreign', 'message' => 'R10U foreign seller', 'action' => ''],
            ['status' => 'fail', 'name' => 'payment.provider.stripe', 'message' => 'Stripe keys missing', 'action' => 'set keys'],
            ['status' => 'fail', 'name' => 'operations.backup', 'message' => 'Backup off', 'action' => 'Enable config/backup.json'],
        ]]);
        assert_equals(['fail' => 2, 'warn' => 1], $r10uSummary['counts'], 'R10 UX1 checklist: counts only the current store\'s failures and warnings');
        assert_equals([['operations.backup', null], ['payment.provider.stripe', 'payments'], ['stores.currency_prices.' . $r10uStore, 'price_lists']],
            array_map(static fn(array $i): array => [$i['name'], $i['page']], $r10uSummary['items']), 'R10 UX1 checklist: failures first, each linked to the admin page that fixes it');
        assert_true(!str_contains(json_encode($r10uSummary), 'R10U'), 'R10 UX1 checklist: no other store\'s data');
        assert_equals('stores', SetupChecklist::fixPage('stores.legal.' . $r10uStore), 'R10 UX1 checklist: seller data is fixed on the stores page');
    } catch (Throwable $e) { $r10uFail('UX1', $e); }

    // UX2: the card method of a fresh install (Stripe without keys) is not offered at checkout.
    try {
        $r10uInstances->setValue(null, []);
        $r10uSubstituted->setValue(null, []);
        $r10uCfgProp->setValue(null, ['stripe_secret_key' => '', 'stripe_webhook_secret' => ''] + $r10uCfgBase);
        assert_true(!PaymentService::gatewayCanStart('stripe'), 'R10 UX2: Stripe without keys cannot start a payment');
        $r10uInstances->setValue(null, []);
        $r10uCfgProp->setValue(null, ['stripe_secret_key' => 'sk_test_' . str_repeat('a', 24), 'stripe_webhook_secret' => 'whsec_' . str_repeat('b', 24)] + $r10uCfgBase);
        assert_true(PaymentService::gatewayCanStart('stripe'), 'R10 UX2: Stripe with keys is offered');
        $r10uInstances->setValue(null, []);
        $r10uCfgProp->setValue(null, ['stripe_secret_key' => '', 'stripe_webhook_secret' => ''] + $r10uCfgBase);
        Adapters::set('payment.stripe', new class {});
        assert_true(PaymentService::gatewayCanStart('stripe'), 'R10 UX2: a substituted gateway adapter is offered');
    } catch (Throwable $e) { $r10uFail('UX2', $e); } finally {
        $r10uInstances->setValue(null, $r10uInstancesBefore);
        $r10uSubstituted->setValue(null, $r10uSubstitutedBefore);
        $r10uCfgProp->setValue(null, $r10uCfgBefore);
    }

    // UX3: an unpaid online order can be paid again through an e-mailed link; the link names only its own order and lapses.
    try {
        $r10uOrder = static function (string $status, string $paymentStatus, array $snapshot) use ($pdo, $r10uStore): array {
            $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
                VALUES (?, 'R10U', 'r10u@example.test', '', '{}', 1000, 0, 0, 0, 1000, 'eur', ?, ?, ?, '', ?, ?, " . Db::now() . ")")
                ->execute(['R10U-' . bin2hex(random_bytes(4)), $status, $paymentStatus, (string)($snapshot['provider'] ?? ''), json_encode($snapshot), $r10uStore]);
            return Orders::byId((int)$pdo->lastInsertId());
        };
        $r10uCard = ['provider' => 'r10u-gateway', 'service_type' => 'online_card', 'requires_online' => true];
        Adapters::set('payment.r10u-gateway', new class {});
        $r10uUnpaid = $r10uOrder('pending', 'failed', $r10uCard);
        assert_true(PaymentLink::payable($r10uUnpaid), 'R10 UX3: a pending order whose card payment failed can be paid again');
        assert_true(!PaymentLink::payable($r10uOrder('pending', 'paid', $r10uCard)), 'R10 UX3: a paid order cannot');
        assert_true(!PaymentLink::payable($r10uOrder('cancelled', 'pending', $r10uCard)), 'R10 UX3: a cancelled order cannot');
        assert_true(!PaymentLink::payable($r10uOrder('pending', 'pending', ['provider' => 'bank_transfer', 'requires_online' => false])), 'R10 UX3: a bank transfer has no online payment to restart');
        $r10uCfgProp->setValue(null, ['app_url' => ''] + $r10uCfgBase);
        assert_equals('no_app_url', PaymentLink::send($r10uUnpaid)['status'] ?? null, 'R10 UX3: without app_url the admin is told, nothing is e-mailed');
        $r10uCfgProp->setValue(null, ['app_url' => 'https://r10u.example.test'] + $r10uCfgBase);
        $r10uUrl = PaymentLink::issue($r10uUnpaid);
        parse_str((string)parse_url($r10uUrl, PHP_URL_QUERY), $r10uQuery);
        assert_true(($r10uQuery['page'] ?? '') === 'pay' && preg_match('/^[a-f0-9]{48}$/', (string)($r10uQuery['token'] ?? '')) === 1, 'R10 UX3: the link is /?page=pay with a random token');
        assert_equals((int)$r10uUnpaid['id'], (int)(PaymentLink::orderForToken((string)$r10uQuery['token'])['id'] ?? 0), 'R10 UX3: the token opens its order');
        assert_equals(0, (int)$pdo->query("SELECT COUNT(*) FROM checkout_sessions WHERE session_id = " . $pdo->quote((string)$r10uQuery['token']))->fetchColumn(), 'R10 UX3: only the token hash is stored');
        assert_true(PaymentLink::orderForToken(str_repeat('0', 48)) === null && PaymentLink::orderForToken('../x') === null, 'R10 UX3: an unknown or malformed token opens nothing');
        $pdo->prepare("UPDATE checkout_sessions SET created_at = ? WHERE provider = 'pay_link' AND order_id = ?")->execute([gmdate('Y-m-d H:i:s', time() - PaymentLink::LINK_TTL - 60), (int)$r10uUnpaid['id']]);
        assert_true(PaymentLink::orderForToken((string)$r10uQuery['token']) === null, 'R10 UX3: the link lapses after its validity');
        assert_equals('not_payable', PaymentLink::send($r10uOrder('pending', 'paid', $r10uCard))['status'] ?? null, 'R10 UX3: no payment link is e-mailed for a paid order');

        // Pay again after a gateway outage: a failed attempt releases its claim, so the next "Pay" opens a session.
        $r10uGateway = new class implements PaymentAdapter {
            public int $calls = 0;
            public bool $down = true;
            public string $status = 'pending';
            public function createSession(array $order): array {
                $this->calls++;
                if ($this->down) throw new RuntimeException('gateway outage');
                return ['url' => 'https://pay.test/r10u-' . $this->calls, 'session_id' => 'cs_r10u_' . bin2hex(random_bytes(4))];
            }
            public function handleWebhook(array $payload): array { return []; }
            public function refund(string $paymentId, int $amount): array { return []; }
            public function getStatus(string $paymentId): string { return $this->status; }
        };
        $r10uInstances->setValue(null, array_diff_key($r10uInstances->getValue(), ['payment' => true])); // the legacy slot would win over the substitute
        Adapters::set('payment.stripe', $r10uGateway);
        $r10uFlaky = ['provider' => 'stripe', 'service_type' => 'online_card', 'requires_online' => true];
        $r10uRetry = $r10uOrder('pending', 'failed', $r10uFlaky);
        $r10uClaims = static fn(int $id): int => (int)$pdo->query("SELECT COUNT(*) FROM checkout_sessions WHERE provider = 'pay_restart' AND order_id = {$id}")->fetchColumn();
        $r10uInitiated = static fn(int $id): int => (int)$pdo->query("SELECT COUNT(*) FROM payment_transactions WHERE status = 'initiated' AND order_id = {$id}")->fetchColumn();
        assert_true(PaymentLink::start($r10uRetry) === null && $r10uClaims((int)$r10uRetry['id']) === 0, 'R10 UX3 pay again: a gateway failure returns no URL and releases its attempt claim');
        $r10uGateway->down = false;
        assert_equals('https://pay.test/r10u-2', PaymentLink::start($r10uRetry), 'R10 UX3 pay again: the retry after a gateway failure opens a session');
        assert_equals(1, $r10uInitiated((int)$r10uRetry['id']), 'R10 UX3 pay again: the retry logs one initiated payment');
        $r10uCallsBefore = $r10uGateway->calls;
        assert_true(PaymentLink::start($r10uRetry) === 'https://pay.test/r10u-2' && $r10uGateway->calls === $r10uCallsBefore, 'R10 UX3 pay again: the live session opened after the failure is reused, no second one is opened');
        PaymentService::recordEvent((int)$r10uRetry['id'], 'payment_failed', 'failed', 1000, 'eur', 'r10u-fail-' . $r10uRetry['id']);
        $r10uGateway->status = 'failed'; // R11 H2: a new session opens only once the gateway reports the previous one unpayable
        $pdo->prepare("INSERT INTO checkout_sessions (order_id, provider, session_id, status) VALUES (?, 'pay_restart', ?, 'completed')")->execute([(int)$r10uRetry['id'], $r10uRetry['id'] . ':1']);
        assert_true(PaymentLink::start($r10uRetry) === null && $r10uGateway->calls === $r10uCallsBefore, 'R10 UX3 pay again: a concurrent attempt (double submit) opens no second session');
        $pdo->prepare("UPDATE checkout_sessions SET created_at = ? WHERE provider = 'pay_restart' AND session_id = ?")->execute([gmdate('Y-m-d H:i:s', time() - 3600), $r10uRetry['id'] . ':1']);
        assert_true(PaymentLink::start($r10uRetry) === 'https://pay.test/r10u-' . ($r10uCallsBefore + 1) && $r10uInitiated((int)$r10uRetry['id']) === 2, 'R10 UX3 pay again: a claim left by a crashed attempt lapses');
        foreach (['sk', 'cs', 'en', 'de', 'es', 'hu', 'pl'] as $r10uLocale) {
            $r10uMail = (string)@file_get_contents(dirname(TEST_CLI_DIR) . "/templates/emails/{$r10uLocale}/order_payment_link.html");
            assert_true(str_contains($r10uMail, '{{pay_url}}') && str_contains($r10uMail, '{{order_number}}'), "R10 UX3: the {$r10uLocale} payment-link e-mail carries the link and the order");
        }
    } catch (Throwable $e) { $r10uFail('UX3', $e); } finally {
        $r10uInstances->setValue(null, $r10uInstancesBefore);
        $r10uSubstituted->setValue(null, $r10uSubstitutedBefore);
        $r10uCfgProp->setValue(null, $r10uCfgBefore);
    }

    // UX5: stores added and removed in the admin; legal texts saved to the data directory and used by the storefront.
    try {
        @mkdir($r10uTmp . '/data', 0700, true);
        $r10uStoresFile = $r10uTmp . '/stores.json';
        copy(dirname(TEST_CLI_DIR) . '/stores.json', $r10uStoresFile);
        DataOverrides::setDirForTesting($r10uTmp . '/data');
        assert_equals(dirname(TEST_CLI_DIR) . '/stores.json', DataOverrides::storesPath(dirname(TEST_CLI_DIR)), 'R10 UX5: without a saved copy the shipped stores.json is used');
        file_put_contents($r10uTmp . '/data/stores.json', '{"default_store":"r10u"}');
        assert_equals($r10uTmp . '/data/stores.json', DataOverrides::storesPath(dirname(TEST_CLI_DIR)), 'R10 UX5: a stores.json saved in the data directory takes precedence over the read-only code');
        unlink($r10uTmp . '/data/stores.json');

        StoreSettings::setPathForTesting($r10uStoresFile);
        $r10uStores = static fn(): array => json_decode((string)file_get_contents($r10uStoresFile), true);
        $r10uDefault = (string)$r10uStores()['default_store'];
        $r10uAdd = StoreSettings::addStore(['id' => 'r10u', 'name' => 'R10U shop', 'url_prefix' => '/r10u', 'default_locale' => 'en', 'currency' => 'eur']);
        $r10uNew = $r10uStores()['stores']['r10u'] ?? [];
        assert_true($r10uAdd['ok'] && ($r10uNew['url_prefix'] ?? '') === '/r10u' && ($r10uNew['legal']['seller'] ?? '') !== '' && ($r10uNew['currency'] ?? '') === 'eur',
            'R10 UX5: the owner adds a store under its own prefix, invoicing as an existing seller' . ($r10uAdd['ok'] ? '' : ': ' . implode(' ', $r10uAdd['errors'])));
        foreach ([['id' => 'r10u', 'url_prefix' => '/r10v'], ['id' => 'r10v', 'url_prefix' => '/r10u'], ['id' => 'R 10', 'url_prefix' => '/r10w'],
                  ['id' => 'r10x', 'url_prefix' => '/r10x', 'currency' => 'xyz'], ['id' => 'r10y', 'url_prefix' => '/r10y', 'default_locale' => 'zz']] as $r10uBad) {
            $r10uBefore = (string)file_get_contents($r10uStoresFile);
            $r10uResult = StoreSettings::addStore($r10uBad + ['name' => 'Bad', 'default_locale' => 'en', 'currency' => 'eur']);
            assert_true(!$r10uResult['ok'] && $r10uBefore === (string)file_get_contents($r10uStoresFile), 'R10 UX5: refused new store ' . json_encode($r10uBad) . ' leaves stores.json unchanged');
        }
        assert_true(!StoreSettings::removeStore($r10uDefault, $pdo)['ok'] && isset($r10uStores()['stores'][$r10uDefault]), 'R10 UX5: the default store cannot be removed');
        $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, store_id, created_at)
            VALUES ('R10U-HIST', 'R10U', 'r10u@example.test', '', '{}', 0, 0, 0, 0, 0, 'eur', 'delivered', 'paid', 'bank_transfer', 'r10u', " . Db::now() . ")")->execute();
        assert_true(!StoreSettings::removeStore('r10u', $pdo)['ok'] && isset($r10uStores()['stores']['r10u']), 'R10 UX5: a store with orders cannot be removed');
        $pdo->exec("DELETE FROM orders WHERE order_number = 'R10U-HIST'");
        assert_true(StoreSettings::removeStore('r10u', $pdo)['ok'] && !isset($r10uStores()['stores']['r10u']), 'R10 UX5: an unused store is removed');

        $r10uLegal = ['name' => 'R10U Trading', 'email' => 'legal@r10u.test'];
        $r10uShipped = ConsumerNotice::renderLegal('terms', 'sk', $r10uLegal);
        assert_true(DataOverrides::saveLegal('terms', 'sk', "<h1>R10U terms of {{name}}</h1>\n"), 'R10 UX5: a legal text is saved to the data directory');
        assert_true(is_file($r10uTmp . '/data/legal/sk/terms.html'), 'R10 UX5: the saved text lives in the data directory');
        assert_true(str_contains(ConsumerNotice::renderLegal('terms', 'sk', $r10uLegal), 'R10U terms of R10U Trading'), 'R10 UX5: the storefront shows the saved text with the seller filled in');
        assert_true(!str_contains(ConsumerNotice::renderLegal('terms', 'cs', $r10uLegal), 'R10U terms'), 'R10 UX5: other languages keep their own text');
        assert_true(!DataOverrides::saveLegal('../../config', 'sk', 'x') && !DataOverrides::saveLegal('terms', '../x', 'x'), 'R10 UX5: only known legal pages and two-letter languages are saved');
        assert_true(DataOverrides::saveLegal('terms', 'sk', '') && ConsumerNotice::renderLegal('terms', 'sk', $r10uLegal) === $r10uShipped, 'R10 UX5: reset restores the shipped text');
    } catch (Throwable $e) { $r10uFail('UX5', $e); } finally {
        StoreSettings::setPathForTesting(null);
        DataOverrides::setDirForTesting(null);
        $pdo->exec("DELETE FROM orders WHERE order_number = 'R10U-HIST'");
    }
} finally {
    $r10uCfgProp->setValue(null, $r10uCfgBefore);
    if (is_dir($r10uTmp)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r10uTmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $r10uEntry) {
            $r10uEntry->isDir() ? @rmdir($r10uEntry->getPathname()) : @unlink($r10uEntry->getPathname());
        }
        @rmdir($r10uTmp);
    }
}
