<?php

// Review round 14 (R40, low, refunds; goal #494):
// 1. The capture of a payment arriving after its refund was booked (the refund of a payment the shop still held as pending
//    is the first sign of its capture, R39) was ignored: the order stayed pending for ever and its stock reserved. Now a
//    full refund cancels the order, a partial one pays it; the payment keeps what its refunds left.
// 2. A charge.refunded whose refund list Stripe never gives raised the critical payment.webhook_deferred_dead although
//    what the ledger lacked was the shop's own refund in flight (booked by its id when it settles).
// Real signed HTTP webhooks (Stripe's API stubbed unreachable), SQLite and MySQL; the generic provider path in process.
echo "\n--- R40 refunds: a capture after its refund settles the order, a stalled list of the shop's own refund is no critical ---\n";
$r40fRoot = test_fixture_path('refund-low-r40');
mkdir($r40fRoot . '/src/Http', 0700, true);
$r40fSecret = 'whsec_r40f_0123456789012345';
file_put_contents($r40fRoot . '/config.php', '<?php return ' . var_export(['stripe_webhook_secret' => $r40fSecret, 'stripe_secret_key' => 'rk_test_r40f'], true) . ';');
copy(dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php', $r40fRoot . '/src/Http/ApiHandlers.php');
$r40fStores = ['default_store' => 'default', 'stores' => [
    'default' => ['name' => 'R40F', 'default_locale' => 'en', 'languages' => ['en'], 'currency' => 'eur', 'url_prefix' => '', 'domains' => [], 'permissions' => ['*'], 'channels' => ['storefront', 'api'], 'catalog_scope' => 'default', 'provider_scope' => 'default'],
]];
$r40fCommon = <<<'PHP'
<?php
$fx = json_decode(file_get_contents(__DIR__ . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
require $fx['root'] . '/src/autoload.php';
require $fx['root'] . '/adapters/PaymentAdapter.php';
$db = $fx['driver'] === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new PDO('sqlite:' . $fx['target']);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
PHP;
file_put_contents($r40fRoot . '/setup.php', $r40fCommon . <<<'PHP'

if ($fx['driver'] === 'mysql') {
    if (($argv[1] ?? '') === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$fx['target']}`"); exit(0); }
    $db->exec("CREATE DATABASE `{$fx['target']}`");
    $db->exec("USE `{$fx['target']}`");
}
Db::init($db, $fx['driver']);
(new Migrator($db, $fx['root'] . '/migrations', __DIR__ . '/migrate.lock'))->apply();
PHP);
file_put_contents($r40fRoot . '/router.php', $r40fCommon . <<<'PHP'

if ($fx['driver'] === 'mysql') $db->exec("USE `{$fx['target']}`");
require __DIR__ . '/src/Http/ApiHandlers.php';
$cfg = require __DIR__ . '/config.php';
Db::init($db, $fx['driver']);
JobQueue::setDir(__DIR__ . '/jobs');
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $fx['stores']);
StoreContext::activate(StoreContext::resolve($fx['stores'], $_SERVER['REQUEST_URI'], '', null, 'api'));
header('Content-Type: application/json');
// Stripe's API is unreachable: a charge's refund list cannot be read.
class R40fStripe extends StripePayment {
    protected function apiCall(string $method, string $endpoint, array $params = [], array $extraHeaders = []): array {
        throw new RuntimeException('Stripe API: An error occurred with our connection to Stripe (HTTP 503)', 503);
    }
}
(new ReflectionProperty(Adapters::class, 'instances'))->setValue(null, ['payment' => new R40fStripe($cfg)]);
echo json_encode(apiWebhookStripe(), JSON_THROW_ON_ERROR);
PHP);

$r40fDrivers = ['sqlite' => $r40fRoot . '/webhooks.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r40fDrivers['mysql'] = 'bbe_r40f_webhook_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] R40 refund low native MySQL (set TEST_MYSQL_DSN)\n";
$r40fPhp = static function(array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
};
try {
    foreach ($r40fDrivers as $r40fDriver => $r40fTarget) {
        $r40fTag = "R40 refund low {$r40fDriver}";
        if ($r40fDriver === 'sqlite') @unlink($r40fTarget);
        file_put_contents($r40fRoot . '/fixture.json', json_encode(['driver' => $r40fDriver, 'target' => $r40fTarget, 'root' => dirname(TEST_CLI_DIR), 'stores' => $r40fStores], JSON_THROW_ON_ERROR));
        assert_equals([0, ''], $r40fPhp([$r40fRoot . '/setup.php']), "{$r40fTag}: fresh schema migrated");
        $r40fDb = $r40fDriver === 'mysql'
            ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
            : new PDO('sqlite:' . $r40fTarget);
        $r40fDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $r40fDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($r40fDriver === 'mysql') $r40fDb->exec("USE `{$r40fTarget}`");
        $r40fOrder = static function(string $number, string $status, string $payment) use ($r40fDb): int {
            $r40fDb->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_id, subtotal, total, currency, store_id)
                VALUES (?, ?, ?, 'R40F', 'r40f@example.test', 'card', ?, 1000, 1000, 'eur', 'default')")->execute([$number, $status, $payment, 'pi_' . strtolower($number)]);
            return (int)$r40fDb->lastInsertId();
        };
        $r40fState = static function(int $orderId) use ($r40fDb): array {
            $stmt = $r40fDb->prepare("SELECT status, payment_status FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $row = $stmt->fetch();
            $stmt = $r40fDb->prepare("SELECT COALESCE(SUM(amount), 0) FROM payment_transactions WHERE order_id = ? AND status IN ('partially_refunded', 'refunded', 'refund_succeeded')");
            $stmt->execute([$orderId]);
            return [(string)$row['status'], (string)$row['payment_status'], (int)$stmt->fetchColumn()];
        };
        $r40fIncidents = static function(string $code) use ($r40fDb): int {
            $stmt = $r40fDb->prepare('SELECT COUNT(*) FROM operational_incidents WHERE code = ?');
            $stmt->execute([$code]);
            return (int)$stmt->fetchColumn();
        };
        $r40fFull = $r40fOrder('R40F-FULL', 'pending', 'pending');
        $r40fPart = $r40fOrder('R40F-PART', 'pending', 'pending');
        // The shop's own refund of 5.00 in flight (requestRefund()) after 3.00 booked: the payment is refund_pending.
        $r40fFlight = $r40fOrder('R40F-FLIGHT', 'delivered', 'refund_pending');
        $r40fLedger = $r40fDb->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response)
            VALUES (?, 'default', 'stripe', ?, ?, ?, 'eur', ?, ?)");
        $r40fLedger->execute([$r40fFlight, 're_r40f_booked', 'partially_refunded', 300, 'stripe_refund_re_r40f_booked', '{}']);
        $r40fLedger->execute([$r40fFlight, 'pi_r40f-flight', 'refund_pending', 500, 'r40f_request', json_encode(['event_type' => 'refund.requested'])]);
        // R42 (low 8): its payment_refund job started (the dispatch marker PaymentService::refund() writes before calling Stripe).
        $r40fLedger->execute([$r40fFlight, '', 'refund_attempted', 0, 'refund_dispatch_r40f_request_execute', json_encode(['dispatch_started' => true])]);
        // R42 (low 8): the same, but its job still waits in the queue: it is not at Stripe yet.
        $r40fQueued = $r40fOrder('R40F-QUEUED', 'delivered', 'refund_pending');
        $r40fLedger->execute([$r40fQueued, 're_r40f_qbooked', 'partially_refunded', 300, 'stripe_refund_re_r40f_qbooked', '{}']);
        $r40fLedger->execute([$r40fQueued, 'pi_r40f-queued', 'refund_pending', 500, 'r40f_qrequest', json_encode(['event_type' => 'refund.requested'])]);

        $r40fSocket = stream_socket_server('tcp://127.0.0.1:0');
        $r40fAddress = stream_socket_get_name($r40fSocket, false);
        fclose($r40fSocket);
        $r40fServer = test_spawn_server([PHP_BINARY, '-S', $r40fAddress, $r40fRoot . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $r40fRoot . '/server.log', 'a'], 2 => ['file', $r40fRoot . '/server.log', 'a']], $r40fPipes);
        fclose($r40fPipes[0]);
        try {
            $r40fReady = false;
            for ($r40fI = 0; $r40fI < 50 && !$r40fReady; $r40fI++) {
                $r40fProbe = @stream_socket_client('tcp://' . $r40fAddress, $r40fErrno, $r40fError, 0.1);
                if ($r40fProbe) { fclose($r40fProbe); $r40fReady = true; } else usleep(100000);
            }
            assert_true($r40fReady, "{$r40fTag}: isolated HTTP fixture starts");
            $r40fStripe = static function(array $event) use ($r40fAddress, $r40fSecret): array {
                $raw = json_encode($event, JSON_THROW_ON_ERROR);
                $time = time();
                $stream = fopen('http://' . $r40fAddress . '/?action=webhook_stripe', 'r', false, stream_context_create(['http' => [
                    'method' => 'POST', 'content' => $raw, 'ignore_errors' => true, 'timeout' => 20,
                    'header' => "Content-Type: application/json\r\nStripe-Signature: t={$time},v1=" . hash_hmac('sha256', $time . '.' . $raw, $r40fSecret) . "\r\n",
                ]]));
                $body = stream_get_contents($stream);
                preg_match('/\s(\d{3})\s/', stream_get_meta_data($stream)['wrapper_data'][0], $status);
                fclose($stream);
                return [(int)$status[1], json_decode($body, true) ?? $body];
            };
            $r40fRefunded = static fn(string $event, string $pi, ?string $refund, int $amount): array => ['id' => $event, 'type' => 'charge.refunded', 'data' => ['object' => [
                'id' => 'ch_' . $pi, 'payment_intent' => $pi, 'amount_refunded' => $amount,
                'refunds' => $refund === null ? ['has_more' => true, 'data' => []] : ['has_more' => false, 'data' => [['id' => $refund, 'amount' => $amount, 'status' => 'succeeded', 'created' => time()]]]]]];
            $r40fCompleted = static fn(string $event, int $orderId, string $pi): array => ['id' => $event, 'type' => 'checkout.session.completed', 'data' => ['object' => [
                'id' => 'cs_' . $event, 'payment_status' => 'paid', 'amount_total' => 1000, 'currency' => 'eur', 'payment_intent' => $pi, 'metadata' => ['order_id' => (string)$orderId]]]];

            // 1. Refunded first (the capture missed), captured after.
            assert_equals([200, ['status' => 'ok']], $r40fStripe($r40fRefunded('evt_r40f_full_refund', 'pi_r40f-full', 're_r40f_full', 1000)), "{$r40fTag}: the full refund of a pending payment is booked");
            assert_equals([200, ['status' => 'ok']], $r40fStripe($r40fRefunded('evt_r40f_part_refund', 'pi_r40f-part', 're_r40f_part', 400)), "{$r40fTag}: a partial refund of a pending payment is booked");
            $r40fFullCapture = $r40fStripe($r40fCompleted('evt_r40f_full_paid', $r40fFull, 'pi_r40f-full'));
            assert_equals([[200, ['status' => 'ok']], ['cancelled', 'refunded', 1000]], [$r40fFullCapture, $r40fState($r40fFull)],
                "{$r40fTag}: captured after its full refund, the order is cancelled (its stock released) and the payment stays refunded (was: pending for ever)");
            $r40fPartCapture = $r40fStripe($r40fCompleted('evt_r40f_part_paid', $r40fPart, 'pi_r40f-part'));
            assert_equals([[200, ['status' => 'ok']], ['paid', 'partially_refunded', 400]], [$r40fPartCapture, $r40fState($r40fPart)],
                "{$r40fTag}: captured after a partial refund, the order is paid and the payment keeps its refund");
            assert_equals([200, ['status' => 'ok']], $r40fStripe($r40fCompleted('evt_r40f_full_paid_again', $r40fFull, 'pi_r40f-full')), "{$r40fTag}: a second delivery changes nothing");
            assert_equals(['cancelled', 'refunded', 1000], $r40fState($r40fFull), "{$r40fTag}: the cancelled order stays as it is");

            // 2. A list that never comes: 8.00 reported, 3.00 booked and the shop's own 5.00 in flight.
            for ($r40fI = 0; $r40fI < PaymentWebhookRouting::MAX_ATTEMPTS; $r40fI++) $r40fFlightResponse = $r40fStripe($r40fRefunded('evt_r40f_flight', 'pi_r40f-flight', null, 800));
            assert_equals([503, 0, ['delivered', 'refund_pending', 300]], [$r40fFlightResponse[0], $r40fIncidents('payment.webhook_deferred_dead'), $r40fState($r40fFlight)],
                "{$r40fTag}: an event whose excess is the shop's own refund in flight is still deferred, with no critical incident after " . PaymentWebhookRouting::MAX_ATTEMPTS . ' deliveries (was: payment.webhook_deferred_dead)');
            assert_true($r40fIncidents('payment.webhook_deferred') >= 1, "{$r40fTag}: it is reported as a warning");
            for ($r40fI = 0; $r40fI < PaymentWebhookRouting::MAX_ATTEMPTS; $r40fI++) $r40fQueuedResponse = $r40fStripe($r40fRefunded('evt_r40f_queued', 'pi_r40f-queued', null, 800));
            assert_equals([503, 1, ['delivered', 'refund_pending', 300]], [$r40fQueuedResponse[0], min(1, $r40fIncidents('payment.webhook_deferred_dead')), $r40fState($r40fQueued)],
                "{$r40fTag}: R42 an admin refund whose job has not reached Stripe yet does not explain the 5.00 more: critical once dead (was: explained, no incident)");
            for ($r40fI = 0; $r40fI < PaymentWebhookRouting::MAX_ATTEMPTS; $r40fI++) $r40fStripe($r40fRefunded('evt_r40f_unknown', 'pi_r40f-flight', null, 900));
            assert_equals(1, min(1, $r40fIncidents('payment.webhook_deferred_dead')), "{$r40fTag}: 1.00 more than booked and in flight is a refund the shop does not know: critical once dead (control)");
        } finally {
            proc_terminate($r40fServer);
            proc_close($r40fServer);
        }
        $r40fDb = null;
    }
} finally {
    if (isset($r40fDrivers['mysql'])) {
        file_put_contents($r40fRoot . '/fixture.json', json_encode(['driver' => 'mysql', 'target' => $r40fDrivers['mysql'], 'root' => dirname(TEST_CLI_DIR), 'stores' => $r40fStores], JSON_THROW_ON_ERROR));
        $r40fPhp([$r40fRoot . '/setup.php', 'teardown']);
    }
}

// 3. The generic provider path (GoPay and others): a payment.paid after the refund that revealed it.
$pdo ??= test_commerce_fixture();
Bootstrap::initStoreContext('/', '', 'default');
$r40fIds = [];
try {
    $r40fInsert = $pdo->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_id, subtotal, total, currency, store_id, created_at)
        VALUES (?, 'pending', ?, 'R40F', 'r40f-generic@example.test', 'gopay', ?, 1000, 1000, 'eur', 'default', " . Db::now() . ")");
    $r40fApply = new ReflectionMethod(PaymentService::class, 'applyWebhookEvent');
    foreach (['refunded' => [1000, 'cancelled'], 'partially_refunded' => [250, 'paid']] as $r40fPayment => [$r40fRefund, $r40fExpected]) {
        $r40fInsert->execute(['R40F-GP-' . $r40fPayment, $r40fPayment, 'gp_r40f_' . $r40fPayment]);
        $r40fIds[] = $r40fId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response)
            VALUES (?, 'default', 'gopay', ?, ?, ?, 'eur', ?, '{}')")->execute([$r40fId, 'gp_r40f_' . $r40fPayment, $r40fPayment, $r40fRefund, 'r40f_gp_refund_' . $r40fId]);
        $r40fApply->invoke(null, $r40fId, 'gopay', 'payment.paid', 'r40f_gp_paid_' . $r40fId, ['amount' => 1000, 'currency' => 'eur', 'id' => 'gp_r40f_' . $r40fPayment]);
        $r40fOrder = Orders::byId($r40fId);
        assert_equals([$r40fExpected, $r40fPayment], [(string)$r40fOrder['status'], (string)$r40fOrder['payment_status']],
            "R40 refund low generic: a GoPay capture after the payment was {$r40fPayment} settles the order as {$r40fExpected} (was: pending for ever)");
    }
} catch (Throwable $r40fE) {
    assert_true(false, 'R40 refund low generic (' . get_class($r40fE) . ': ' . $r40fE->getMessage() . ' @ ' . basename($r40fE->getFile()) . ':' . $r40fE->getLine() . ')');
} finally {
    foreach ($r40fIds as $r40fId) {
        if (OrderWorkflow::canTransition((string)(Orders::byId($r40fId)['status'] ?? ''), 'cancelled')) OrderWorkflow::transition($r40fId, 'cancelled', 'R40 refund low fixture');
    }
}
