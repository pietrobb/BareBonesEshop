<?php

// Review round 12 (R38, medium 7): a refund Stripe executed while the payment was disputed, or after it was recorded as
// failed (a capture the shop missed), did not apply to the payment state machine: logged 'ignored', the ledger held 0.
// Real signed HTTP webhooks, SQLite and MySQL.
echo "\n--- R38 a refund during a dispute or after a failed payment is booked, not ignored ---\n";
$r38sRoot = test_fixture_path('refund-states-r38');
mkdir($r38sRoot . '/src/Http', 0700, true);
$r38sSecret = 'whsec_r38s_0123456789012345';
file_put_contents($r38sRoot . '/config.php', '<?php return ' . var_export(['stripe_webhook_secret' => $r38sSecret], true) . ';');
copy(dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php', $r38sRoot . '/src/Http/ApiHandlers.php');
$r38sStores = ['default_store' => 'default', 'stores' => [
    'default' => ['name' => 'R38S', 'default_locale' => 'en', 'languages' => ['en'], 'currency' => 'eur', 'url_prefix' => '', 'domains' => [], 'permissions' => ['*'], 'channels' => ['storefront', 'api'], 'catalog_scope' => 'default', 'provider_scope' => 'default'],
]];
$r38sCommon = <<<'PHP'
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
file_put_contents($r38sRoot . '/setup.php', $r38sCommon . <<<'PHP'

if ($fx['driver'] === 'mysql') {
    if (($argv[1] ?? '') === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$fx['target']}`"); exit(0); }
    $db->exec("CREATE DATABASE `{$fx['target']}`");
    $db->exec("USE `{$fx['target']}`");
}
Db::init($db, $fx['driver']);
(new Migrator($db, $fx['root'] . '/migrations', __DIR__ . '/migrate.lock'))->apply();
PHP);
file_put_contents($r38sRoot . '/router.php', $r38sCommon . <<<'PHP'

if ($fx['driver'] === 'mysql') $db->exec("USE `{$fx['target']}`");
require __DIR__ . '/src/Http/ApiHandlers.php';
$cfg = require __DIR__ . '/config.php';
Db::init($db, $fx['driver']);
JobQueue::setDir(__DIR__ . '/jobs');
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $fx['stores']);
StoreContext::activate(StoreContext::resolve($fx['stores'], $_SERVER['REQUEST_URI'], '', null, 'api'));
header('Content-Type: application/json');
(new ReflectionProperty(Adapters::class, 'instances'))->setValue(null, ['payment' => new StripePayment($cfg)]);
echo json_encode(apiWebhookStripe(), JSON_THROW_ON_ERROR);
PHP);

$r38sDrivers = ['sqlite' => $r38sRoot . '/webhooks.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r38sDrivers['mysql'] = 'bbe_r38s_webhook_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] R38 refund states native MySQL (set TEST_MYSQL_DSN)\n";
$r38sPhp = static function(array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
};
try {
    foreach ($r38sDrivers as $r38sDriver => $r38sTarget) {
        $r38sTag = "R38 refund states {$r38sDriver}";
        file_put_contents($r38sRoot . '/fixture.json', json_encode(['driver' => $r38sDriver, 'target' => $r38sTarget, 'root' => dirname(TEST_CLI_DIR), 'stores' => $r38sStores], JSON_THROW_ON_ERROR));
        assert_equals([0, ''], $r38sPhp([$r38sRoot . '/setup.php']), "{$r38sTag}: fresh schema migrated");
        $r38sDb = $r38sDriver === 'mysql'
            ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
            : new PDO('sqlite:' . $r38sTarget);
        $r38sDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $r38sDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($r38sDriver === 'mysql') $r38sDb->exec("USE `{$r38sTarget}`");
        $r38sOrder = static function(string $number, string $status, string $payment) use ($r38sDb): int {
            $r38sDb->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_id, subtotal, total, currency, store_id)
                VALUES (?, ?, ?, 'R38S', 'r38s@example.test', 'card', ?, 1000, 1000, 'eur', 'default')")->execute([$number, $status, $payment, 'pi_' . strtolower($number)]);
            return (int)$r38sDb->lastInsertId();
        };
        $r38sState = static function(int $orderId) use ($r38sDb): array {
            $stmt = $r38sDb->prepare("SELECT payment_status FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $status = (string)$stmt->fetchColumn();
            $stmt = $r38sDb->prepare("SELECT COALESCE(SUM(amount), 0) FROM payment_transactions WHERE order_id = ? AND status IN ('partially_refunded', 'refunded', 'refund_succeeded')");
            $stmt->execute([$orderId]);
            return [$status, (int)$stmt->fetchColumn()];
        };
        $r38sDisputed = $r38sOrder('R38S-DISPUTE', 'paid', 'disputed');
        $r38sFailed = $r38sOrder('R38S-FAILED', 'cancelled', 'failed');
        $r38sFailedFull = $r38sOrder('R38S-FULL', 'cancelled', 'failed');

        $r38sSocket = stream_socket_server('tcp://127.0.0.1:0');
        $r38sAddress = stream_socket_get_name($r38sSocket, false);
        fclose($r38sSocket);
        $r38sServer = proc_open([PHP_BINARY, '-S', $r38sAddress, $r38sRoot . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $r38sRoot . '/server.log', 'a'], 2 => ['file', $r38sRoot . '/server.log', 'a']], $r38sPipes);
        fclose($r38sPipes[0]);
        try {
            $r38sReady = false;
            for ($r38sI = 0; $r38sI < 50 && !$r38sReady; $r38sI++) {
                $r38sProbe = @stream_socket_client('tcp://' . $r38sAddress, $r38sErrno, $r38sError, 0.1);
                if ($r38sProbe) { fclose($r38sProbe); $r38sReady = true; } else usleep(100000);
            }
            assert_true($r38sReady, "{$r38sTag}: isolated HTTP fixture starts");
            $r38sStripe = static function(array $event) use ($r38sAddress, $r38sSecret): array {
                $raw = json_encode($event, JSON_THROW_ON_ERROR);
                $time = time();
                $stream = fopen('http://' . $r38sAddress . '/?action=webhook_stripe', 'r', false, stream_context_create(['http' => [
                    'method' => 'POST', 'content' => $raw, 'ignore_errors' => true, 'timeout' => 20,
                    'header' => "Content-Type: application/json\r\nStripe-Signature: t={$time},v1=" . hash_hmac('sha256', $time . '.' . $raw, $r38sSecret) . "\r\n",
                ]]));
                $body = stream_get_contents($stream);
                preg_match('/\s(\d{3})\s/', stream_get_meta_data($stream)['wrapper_data'][0], $status);
                fclose($stream);
                return [(int)$status[1], json_decode($body, true) ?? $body];
            };
            $r38sRefunded = static fn(string $event, string $pi, string $refund, int $amount): array => ['id' => $event, 'type' => 'charge.refunded', 'data' => ['object' => [
                'id' => 'ch_' . $pi, 'payment_intent' => $pi, 'amount_refunded' => $amount,
                'refunds' => ['has_more' => false, 'data' => [['id' => $refund, 'amount' => $amount, 'status' => 'succeeded', 'created' => time()]]]]]];

            assert_equals([200, ['status' => 'ok']], $r38sStripe($r38sRefunded('evt_r38s_d', 'pi_r38s-dispute', 're_r38s_d', 300)), "{$r38sTag}: a partial refund during a dispute is accepted");
            assert_equals(['partially_refunded', 300], $r38sState($r38sDisputed), "{$r38sTag}: disputed → partially_refunded, the 3.00 refund is in the ledger (was ignored, 0)");
            assert_equals([200, ['status' => 'already_processed']], $r38sStripe($r38sRefunded('evt_r38s_d', 'pi_r38s-dispute', 're_r38s_d', 300)), "{$r38sTag}: redelivered, nothing more");

            assert_equals([200, ['status' => 'ok']], $r38sStripe($r38sRefunded('evt_r38s_f', 'pi_r38s-failed', 're_r38s_f', 400)), "{$r38sTag}: a refund of a payment recorded as failed is accepted");
            assert_equals(['partially_refunded', 400], $r38sState($r38sFailed), "{$r38sTag}: failed → partially_refunded, the 4.00 refund is in the ledger (was ignored, 0)");
            assert_equals([200, ['status' => 'ok']], $r38sStripe($r38sRefunded('evt_r38s_ff', 'pi_r38s-full', 're_r38s_ff', 1000)), "{$r38sTag}: a full refund of a failed payment is accepted");
            assert_equals(['refunded', 1000], $r38sState($r38sFailedFull), "{$r38sTag}: failed → refunded with the whole amount in the ledger");
            $r38sIncidents = (int)$r38sDb->query("SELECT COUNT(*) FROM operational_incidents WHERE code = 'payment.refund_after_failed'")->fetchColumn();
            assert_true($r38sIncidents >= 1, "{$r38sTag}: the merchant is told a failed payment was captured and refunded ({$r38sIncidents})");
        } finally {
            proc_terminate($r38sServer);
            proc_close($r38sServer);
            $r38sDb = null;
        }
    }
} finally {
    if (isset($r38sDrivers['mysql'])) {
        file_put_contents($r38sRoot . '/fixture.json', json_encode(['driver' => 'mysql', 'target' => $r38sDrivers['mysql'], 'root' => dirname(TEST_CLI_DIR), 'stores' => $r38sStores], JSON_THROW_ON_ERROR));
        $r38sPhp([$r38sRoot . '/setup.php', 'teardown']);
    }
}
