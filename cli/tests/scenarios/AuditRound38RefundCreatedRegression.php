<?php

// Review round 12 (R38, medium 6): a refund made in the Stripe Dashboard was booked only from charge.refunded, which
// waits for the charge's refund list. When Stripe could not list it, the event was answered 503 until Stripe stopped
// redelivering it (three days) and the refund was never booked; refund.created was ignored. A deferred event had no
// attempt count, no final state, and its incident named no order. Real signed HTTP webhooks, SQLite and MySQL.
echo "\n--- R38 Stripe refund.created books a dashboard refund; deferred events end dead with the order number ---\n";
$r38rRoot = test_fixture_path('refund-created-r38');
mkdir($r38rRoot . '/src/Http', 0700, true);
$r38rSecret = 'whsec_r38r_0123456789012345';
file_put_contents($r38rRoot . '/config.php', '<?php return ' . var_export(['stripe_webhook_secret' => $r38rSecret], true) . ';');
copy(dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php', $r38rRoot . '/src/Http/ApiHandlers.php');
$r38rStores = ['default_store' => 'default', 'stores' => [
    'default' => ['name' => 'R38R', 'default_locale' => 'en', 'languages' => ['en'], 'currency' => 'eur', 'url_prefix' => '', 'domains' => [], 'permissions' => ['*'], 'channels' => ['storefront', 'api'], 'catalog_scope' => 'default', 'provider_scope' => 'default'],
]];
$r38rCommon = <<<'PHP'
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
file_put_contents($r38rRoot . '/setup.php', $r38rCommon . <<<'PHP'

if ($fx['driver'] === 'mysql') {
    if (($argv[1] ?? '') === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$fx['target']}`"); exit(0); }
    $db->exec("CREATE DATABASE `{$fx['target']}`");
    $db->exec("USE `{$fx['target']}`");
}
Db::init($db, $fx['driver']);
(new Migrator($db, $fx['root'] . '/migrations', __DIR__ . '/migrate.lock'))->apply();
PHP);
file_put_contents($r38rRoot . '/router.php', $r38rCommon . <<<'PHP'

if ($fx['driver'] === 'mysql') $db->exec("USE `{$fx['target']}`");
require __DIR__ . '/src/Http/ApiHandlers.php';
$cfg = require __DIR__ . '/config.php';
Db::init($db, $fx['driver']);
JobQueue::setDir(__DIR__ . '/jobs');
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $fx['stores']);
StoreContext::activate(StoreContext::resolve($fx['stores'], $_SERVER['REQUEST_URI'], '', null, 'api'));
header('Content-Type: application/json');
// No stripe_secret_key: the charge's refunds cannot be listed through the API, as when Stripe is unreachable.
(new ReflectionProperty(Adapters::class, 'instances'))->setValue(null, ['payment' => new StripePayment($cfg)]);
echo json_encode(apiWebhookStripe(), JSON_THROW_ON_ERROR);
PHP);

$r38rDrivers = ['sqlite' => $r38rRoot . '/webhooks.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r38rDrivers['mysql'] = 'bbe_r38r_webhook_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] R38 refund.created native MySQL (set TEST_MYSQL_DSN)\n";
$r38rPhp = static function(array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
};
try {
    foreach ($r38rDrivers as $r38rDriver => $r38rTarget) {
        $tag = "R38 refund.created {$r38rDriver}";
        file_put_contents($r38rRoot . '/fixture.json', json_encode(['driver' => $r38rDriver, 'target' => $r38rTarget, 'root' => dirname(TEST_CLI_DIR), 'stores' => $r38rStores], JSON_THROW_ON_ERROR));
        assert_equals([0, ''], $r38rPhp([$r38rRoot . '/setup.php']), "{$tag}: fresh schema migrated");
        $db = $r38rDriver === 'mysql'
            ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
            : new PDO('sqlite:' . $r38rTarget);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($r38rDriver === 'mysql') $db->exec("USE `{$r38rTarget}`");
        $db->exec("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_id, subtotal, total, currency, store_id)
            VALUES ('R38R-0001', 'paid', 'paid', 'R38R', 'r38r@example.test', 'card', 'pi_r38r', 1000, 1000, 'eur', 'default')");
        $orderId = (int)$db->lastInsertId();
        $state = static function() use ($db, $orderId): array {
            $stmt = $db->prepare("SELECT payment_status FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $status = (string)$stmt->fetchColumn();
            // The rows that move money (PaymentService::REFUNDED_STATUSES; refund_confirmed only identifies a refund).
            $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM payment_transactions WHERE order_id = ? AND status IN ('partially_refunded', 'refunded', 'refund_succeeded')");
            $stmt->execute([$orderId]);
            return [$status, (int)$stmt->fetchColumn()];
        };
        $inbox = static function(string $eventId) use ($db): array {
            $stmt = $db->prepare('SELECT store_id, status, attempts, refs, last_error FROM payment_webhook_inbox WHERE event_id = ?');
            $stmt->execute([$eventId]);
            return $stmt->fetch() ?: [];
        };

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $server = test_spawn_server([PHP_BINARY, '-S', $address, $r38rRoot . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $r38rRoot . '/server.log', 'a'], 2 => ['file', $r38rRoot . '/server.log', 'a']], $pipes);
        fclose($pipes[0]);
        try {
            $ready = false;
            for ($i = 0; $i < 50 && !$ready; $i++) {
                $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
                if ($probe) { fclose($probe); $ready = true; } else usleep(100000);
            }
            assert_true($ready, "{$tag}: isolated HTTP fixture starts");
            $stripe = static function(array $event) use ($address, $r38rSecret): array {
                $raw = json_encode($event, JSON_THROW_ON_ERROR);
                $time = time();
                $stream = fopen('http://' . $address . '/?action=webhook_stripe', 'r', false, stream_context_create(['http' => [
                    'method' => 'POST', 'content' => $raw, 'ignore_errors' => true, 'timeout' => 20,
                    'header' => "Content-Type: application/json\r\nStripe-Signature: t={$time},v1=" . hash_hmac('sha256', $time . '.' . $raw, $r38rSecret) . "\r\n",
                ]]));
                $body = stream_get_contents($stream);
                preg_match('/\s(\d{3})\s/', stream_get_meta_data($stream)['wrapper_data'][0], $status);
                fclose($stream);
                return [(int)$status[1], json_decode($body, true) ?? $body];
            };

            // A 4.00 dashboard refund: its charge.refunded lists no refunds and they cannot be listed.
            $summary = ['id' => 'evt_r38r_charge', 'type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_r38r', 'payment_intent' => 'pi_r38r', 'amount_refunded' => 400]]];
            for ($i = 1; $i < PaymentWebhookRouting::MAX_ATTEMPTS; $i++) {
                assert_equals([503, 'retry', $i], [$stripe($summary)[0], $inbox('evt_r38r_charge')['status'] ?? null, (int)($inbox('evt_r38r_charge')['attempts'] ?? 0)],
                    "{$tag}: the deferred charge.refunded is counted (delivery {$i})");
            }
            assert_equals([503, 'dead', PaymentWebhookRouting::MAX_ATTEMPTS], [$stripe($summary)[0], $inbox('evt_r38r_charge')['status'] ?? null, (int)($inbox('evt_r38r_charge')['attempts'] ?? 0)],
                "{$tag}: after " . PaymentWebhookRouting::MAX_ATTEMPTS . ' deliveries the deferred event is dead');
            $row = $inbox('evt_r38r_charge');
            assert_true(($row['store_id'] ?? '') === 'default' && str_contains((string)($row['refs'] ?? ''), 'R38R-0001') && str_contains((string)($row['last_error'] ?? ''), 'R38R-0001'),
                "{$tag}: the inbox row names the order (" . json_encode($row) . ')');
            $incident = $db->query("SELECT severity, context_json FROM operational_incidents WHERE code = 'payment.webhook_deferred_dead' ORDER BY occurred_at DESC LIMIT 1")->fetch() ?: [];
            assert_true(($incident['severity'] ?? '') === 'critical' && str_contains((string)($incident['context_json'] ?? ''), '"order_number":"R38R-0001"')
                && str_contains((string)($incident['context_json'] ?? ''), '"order_id":' . $orderId),
                "{$tag}: a critical incident names the order (" . json_encode($incident) . ')');
            assert_equals(['paid', 0], $state(), "{$tag}: nothing booked while the list is unavailable");

            // refund.created of the same dashboard refund books it by its id.
            $created = ['id' => 'evt_r38r_created', 'type' => 'refund.created', 'data' => ['object' => ['id' => 're_r38r', 'object' => 'refund', 'amount' => 400, 'currency' => 'eur',
                'charge' => 'ch_r38r', 'payment_intent' => 'pi_r38r', 'status' => 'succeeded', 'created' => time()]]];
            assert_equals([200, ['status' => 'ok']], $stripe($created), "{$tag}: refund.created is accepted");
            assert_equals(['partially_refunded', 400], $state(), "{$tag}: the dashboard refund is booked from refund.created (was ignored)");
            assert_equals([200, ['status' => 'already_processed']], $stripe($created), "{$tag}: a redelivered refund.created books nothing more");
            // A pending refund (SEPA) waits for its refund.updated.
            $pending = ['id' => 'evt_r38r_pending', 'type' => 'refund.created', 'data' => ['object' => ['id' => 're_r38r_p', 'object' => 'refund', 'amount' => 100, 'currency' => 'eur',
                'charge' => 'ch_r38r', 'payment_intent' => 'pi_r38r', 'status' => 'pending', 'created' => time()]]];
            assert_equals([[200, ['status' => 'ok']], ['partially_refunded', 400]], [$stripe($pending), $state()], "{$tag}: a pending refund.created books nothing yet");

            // The summary redelivered with its list books the same refund once and closes the dead event.
            $summary['data']['object']['refunds'] = ['has_more' => false, 'data' => [['id' => 're_r38r', 'amount' => 400, 'status' => 'succeeded', 'created' => time()]]];
            assert_equals([200, ['status' => 'ok']], $stripe($summary), "{$tag}: the summary with its refund list is accepted");
            assert_equals([['partially_refunded', 400], 'resolved'], [$state(), $inbox('evt_r38r_charge')['status'] ?? null], "{$tag}: booked once, the dead event resolved");
        } finally {
            proc_terminate($server);
            proc_close($server);
            $db = null;
        }
    }
} finally {
    if (isset($r38rDrivers['mysql'])) {
        file_put_contents($r38rRoot . '/fixture.json', json_encode(['driver' => 'mysql', 'target' => $r38rDrivers['mysql'], 'root' => dirname(TEST_CLI_DIR), 'stores' => $r38rStores], JSON_THROW_ON_ERROR));
        $r38rPhp([$r38rRoot . '/setup.php', 'teardown']);
    }
}
