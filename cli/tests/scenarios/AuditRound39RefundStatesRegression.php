<?php

// Review round 13 (R39, low, refunds):
// 1. A Stripe refund of a payment the shop still held as pending, authorized or cancelled (a capture it missed) was
//    ignored (R38 covered disputed and failed only): the ledger held 0.
// 2. A charge.refunded whose refund list Stripe could not give was deferred until dead, raising a critical incident on
//    every redelivery, although each of its refunds was booked by its id already (refund.created).
// 3. "Paid" set by hand on a disputed payment (the dispute won) dropped the refunds booked before it from its status.
// Real signed HTTP webhooks (Stripe's API stubbed unreachable), SQLite and MySQL.
echo "\n--- R39 refunds of uncaptured payments, booked refunds of an unlistable charge, paid after a dispute ---\n";
$r39sRoot = test_fixture_path('refund-states-r39');
mkdir($r39sRoot . '/src/Http', 0700, true);
$r39sSecret = 'whsec_r39s_0123456789012345';
file_put_contents($r39sRoot . '/config.php', '<?php return ' . var_export(['stripe_webhook_secret' => $r39sSecret, 'stripe_secret_key' => 'rk_test_r39s'], true) . ';');
copy(dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php', $r39sRoot . '/src/Http/ApiHandlers.php');
$r39sStores = ['default_store' => 'default', 'stores' => [
    'default' => ['name' => 'R39S', 'default_locale' => 'en', 'languages' => ['en'], 'currency' => 'eur', 'url_prefix' => '', 'domains' => [], 'permissions' => ['*'], 'channels' => ['storefront', 'api'], 'catalog_scope' => 'default', 'provider_scope' => 'default'],
]];
$r39sCommon = <<<'PHP'
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
file_put_contents($r39sRoot . '/setup.php', $r39sCommon . <<<'PHP'

if ($fx['driver'] === 'mysql') {
    if (($argv[1] ?? '') === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$fx['target']}`"); exit(0); }
    $db->exec("CREATE DATABASE `{$fx['target']}`");
    $db->exec("USE `{$fx['target']}`");
}
Db::init($db, $fx['driver']);
(new Migrator($db, $fx['root'] . '/migrations', __DIR__ . '/migrate.lock'))->apply();
PHP);
// The admin's "paid" on the order detail (PaymentService::recordAdminPayment()).
file_put_contents($r39sRoot . '/paid.php', $r39sCommon . <<<'PHP'

if ($fx['driver'] === 'mysql') $db->exec("USE `{$fx['target']}`");
Db::init($db, $fx['driver']);
JobQueue::setDir(__DIR__ . '/jobs');
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $fx['stores']);
StoreContext::activate(StoreContext::resolve($fx['stores'], '/', '', 'default'));
PaymentService::recordAdminPayment(Orders::byId((int)$argv[1]), 'paid', 0, 'Dispute won', 'r39s-admin');
echo (string)Orders::byId((int)$argv[1])['payment_status'];
PHP);
file_put_contents($r39sRoot . '/router.php', $r39sCommon . <<<'PHP'

if ($fx['driver'] === 'mysql') $db->exec("USE `{$fx['target']}`");
require __DIR__ . '/src/Http/ApiHandlers.php';
$cfg = require __DIR__ . '/config.php';
Db::init($db, $fx['driver']);
JobQueue::setDir(__DIR__ . '/jobs');
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $fx['stores']);
StoreContext::activate(StoreContext::resolve($fx['stores'], $_SERVER['REQUEST_URI'], '', null, 'api'));
header('Content-Type: application/json');
// Stripe's API is unreachable: a charge's refund list cannot be read.
class R39sStripe extends StripePayment {
    protected function apiCall(string $method, string $endpoint, array $params = [], array $extraHeaders = []): array {
        throw new RuntimeException('Stripe API: An error occurred with our connection to Stripe (HTTP 503)', 503);
    }
}
(new ReflectionProperty(Adapters::class, 'instances'))->setValue(null, ['payment' => new R39sStripe($cfg)]);
echo json_encode(apiWebhookStripe(), JSON_THROW_ON_ERROR);
PHP);

$r39sDrivers = ['sqlite' => $r39sRoot . '/webhooks.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r39sDrivers['mysql'] = 'bbe_r39s_webhook_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] R39 refund states native MySQL (set TEST_MYSQL_DSN)\n";
$r39sPhp = static function(array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
};
try {
    foreach ($r39sDrivers as $r39sDriver => $r39sTarget) {
        $r39sTag = "R39 refund states {$r39sDriver}";
        if ($r39sDriver === 'sqlite') @unlink($r39sTarget);
        file_put_contents($r39sRoot . '/fixture.json', json_encode(['driver' => $r39sDriver, 'target' => $r39sTarget, 'root' => dirname(TEST_CLI_DIR), 'stores' => $r39sStores], JSON_THROW_ON_ERROR));
        assert_equals([0, ''], $r39sPhp([$r39sRoot . '/setup.php']), "{$r39sTag}: fresh schema migrated");
        $r39sDb = $r39sDriver === 'mysql'
            ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
            : new PDO('sqlite:' . $r39sTarget);
        $r39sDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $r39sDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($r39sDriver === 'mysql') $r39sDb->exec("USE `{$r39sTarget}`");
        $r39sOrder = static function(string $number, string $status, string $payment) use ($r39sDb): int {
            $r39sDb->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_id, subtotal, total, currency, store_id)
                VALUES (?, ?, ?, 'R39S', 'r39s@example.test', 'card', ?, 1000, 1000, 'eur', 'default')")->execute([$number, $status, $payment, 'pi_' . strtolower($number)]);
            return (int)$r39sDb->lastInsertId();
        };
        $r39sBooked = static function(int $orderId, string $refundId, int $amount) use ($r39sDb): void {
            $r39sDb->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response)
                VALUES (?, 'default', 'stripe', ?, 'refunded', ?, 'eur', ?, '{}')")->execute([$orderId, $refundId, $amount, 'stripe_refund_' . $refundId]);
        };
        $r39sState = static function(int $orderId) use ($r39sDb): array {
            $stmt = $r39sDb->prepare("SELECT payment_status FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $status = (string)$stmt->fetchColumn();
            $stmt = $r39sDb->prepare("SELECT COALESCE(SUM(amount), 0) FROM payment_transactions WHERE order_id = ? AND status IN ('partially_refunded', 'refunded', 'refund_succeeded')");
            $stmt->execute([$orderId]);
            return [$status, (int)$stmt->fetchColumn()];
        };
        $r39sUncaptured = [];
        foreach (['pending' => 'pending', 'authorized' => 'pending', 'cancelled' => 'cancelled'] as $payment => $status) {
            $r39sUncaptured[$payment] = $r39sOrder('R39S-' . strtoupper($payment), $status, $payment);
        }
        $r39sListed = $r39sOrder('R39S-BOOKED', 'delivered', 'partially_refunded');
        $r39sBooked($r39sListed, 're_r39s_booked', 300);
        $r39sDisputed = $r39sOrder('R39S-DISPUTE', 'delivered', 'disputed');
        $r39sBooked($r39sDisputed, 're_r39s_dispute', 200);

        $r39sSocket = stream_socket_server('tcp://127.0.0.1:0');
        $r39sAddress = stream_socket_get_name($r39sSocket, false);
        fclose($r39sSocket);
        $r39sServer = proc_open([PHP_BINARY, '-S', $r39sAddress, $r39sRoot . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $r39sRoot . '/server.log', 'a'], 2 => ['file', $r39sRoot . '/server.log', 'a']], $r39sPipes);
        fclose($r39sPipes[0]);
        try {
            $r39sReady = false;
            for ($r39sI = 0; $r39sI < 50 && !$r39sReady; $r39sI++) {
                $r39sProbe = @stream_socket_client('tcp://' . $r39sAddress, $r39sErrno, $r39sError, 0.1);
                if ($r39sProbe) { fclose($r39sProbe); $r39sReady = true; } else usleep(100000);
            }
            assert_true($r39sReady, "{$r39sTag}: isolated HTTP fixture starts");
            $r39sStripe = static function(array $event) use ($r39sAddress, $r39sSecret): array {
                $raw = json_encode($event, JSON_THROW_ON_ERROR);
                $time = time();
                $stream = fopen('http://' . $r39sAddress . '/?action=webhook_stripe', 'r', false, stream_context_create(['http' => [
                    'method' => 'POST', 'content' => $raw, 'ignore_errors' => true, 'timeout' => 20,
                    'header' => "Content-Type: application/json\r\nStripe-Signature: t={$time},v1=" . hash_hmac('sha256', $time . '.' . $raw, $r39sSecret) . "\r\n",
                ]]));
                $body = stream_get_contents($stream);
                preg_match('/\s(\d{3})\s/', stream_get_meta_data($stream)['wrapper_data'][0], $status);
                fclose($stream);
                return [(int)$status[1], json_decode($body, true) ?? $body];
            };
            $r39sRefunded = static fn(string $event, string $pi, ?string $refund, int $amount): array => ['id' => $event, 'type' => 'charge.refunded', 'data' => ['object' => [
                'id' => 'ch_' . $pi, 'payment_intent' => $pi, 'amount_refunded' => $amount,
                'refunds' => $refund === null ? ['has_more' => true, 'data' => []] : ['has_more' => false, 'data' => [['id' => $refund, 'amount' => $amount, 'status' => 'succeeded', 'created' => time()]]]]]];

            // 1.
            foreach ($r39sUncaptured as $payment => $orderId) {
                $response = $r39sStripe($r39sRefunded('evt_r39s_' . $payment, 'pi_r39s-' . $payment, 're_r39s_' . $payment, 400));
                assert_equals([[200, ['status' => 'ok']], ['partially_refunded', 400]], [$response, $r39sState($orderId)],
                    "{$r39sTag}: a Stripe refund of a payment held as {$payment} is booked: partially_refunded, 4.00 in the ledger (was ignored, 0)");
            }
            $r39sIncidents = (int)$r39sDb->query("SELECT COUNT(*) FROM operational_incidents WHERE code = 'payment.refund_after_failed'")->fetchColumn();
            assert_equals(3, $r39sIncidents, "{$r39sTag}: the merchant is told of each capture the shop missed");

            // 2.
            $response = $r39sStripe($r39sRefunded('evt_r39s_booked', 'pi_r39s-booked', null, 300));
            assert_equals([[200, ['status' => 'ok']], ['partially_refunded', 300]], [$response, $r39sState($r39sListed)],
                "{$r39sTag}: a charge.refunded whose refunds are all booked by their ids is done without Stripe's list (was: deferred until dead)");
            $r39sDead = (int)$r39sDb->query("SELECT COUNT(*) FROM operational_incidents WHERE code LIKE 'payment.webhook%'")->fetchColumn();
            assert_equals(0, $r39sDead, "{$r39sTag}: no incident for it");
            $response = $r39sStripe($r39sRefunded('evt_r39s_more', 'pi_r39s-booked', null, 500));
            assert_true($response !== [200, ['status' => 'ok']] && $r39sState($r39sListed) === ['partially_refunded', 300],
                "{$r39sTag}: one reporting more than is booked still waits for the list (" . json_encode($response) . ')');
        } finally {
            proc_terminate($r39sServer);
            proc_close($r39sServer);
        }

        // 3.
        assert_equals([0, 'partially_refunded'], $r39sPhp([$r39sRoot . '/paid.php', (string)$r39sDisputed]),
            "{$r39sTag}: \"paid\" after a won dispute keeps the 2.00 refunded before it (was: paid)");
        $r39sDb = null;
    }
} finally {
    if (isset($r39sDrivers['mysql'])) {
        file_put_contents($r39sRoot . '/fixture.json', json_encode(['driver' => 'mysql', 'target' => $r39sDrivers['mysql'], 'root' => dirname(TEST_CLI_DIR), 'stores' => $r39sStores], JSON_THROW_ON_ERROR));
        $r39sPhp([$r39sRoot . '/setup.php', 'teardown']);
    }
}
