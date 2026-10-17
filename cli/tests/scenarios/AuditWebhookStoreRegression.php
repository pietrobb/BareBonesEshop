<?php

// Real signed HTTP webhooks against a fully migrated DB with stores sharing / not sharing a provider scope.
echo "\n--- Audit A05.6: payment webhook store resolution and unresolved retry ---\n";
$a056Root = test_fixture_path('webhook-store');
mkdir($a056Root . '/src/Http', 0700, true);
$a056StripeSecret = 'whsec_a056_0123456789012345';
$a056PayUSecret = 'a056-second-key-0123456789';
$a056ShipSecret = 'a056-ship-secret-0123456789';
file_put_contents($a056Root . '/config.php', '<?php return ' . var_export(['stripe_webhook_secret' => $a056StripeSecret, 'payu_second_key' => $a056PayUSecret], true) . ';');
copy(dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php', $a056Root . '/src/Http/ApiHandlers.php');
$a056Store = static fn(string $prefix, string $currency, string $scope): array => ['name' => $prefix ?: 'default', 'default_locale' => 'en', 'languages' => ['en'], 'currency' => $currency, 'url_prefix' => $prefix, 'domains' => [], 'permissions' => ['*'], 'channels' => ['storefront', 'api'], 'catalog_scope' => 'default', 'provider_scope' => $scope];
$a056Stores = ['default_store' => 'default', 'stores' => [
    'default' => $a056Store('', 'eur', 'default'),
    'cz' => $a056Store('/cz', 'czk', 'default'),
    'xx' => $a056Store('/xx', 'eur', 'other'),
]];
$a056Common = <<<'PHP'
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
file_put_contents($a056Root . '/setup.php', $a056Common . <<<'PHP'

if ($fx['driver'] === 'mysql') {
    if (($argv[1] ?? '') === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$fx['target']}`"); exit(0); }
    $db->exec("CREATE DATABASE `{$fx['target']}`");
    $db->exec("USE `{$fx['target']}`");
}
Db::init($db, $fx['driver']);
(new Migrator($db, $fx['root'] . '/migrations', __DIR__ . '/migrate.lock'))->apply();
PHP);
file_put_contents($a056Root . '/router.php', $a056Common . <<<'PHP'

if ($fx['driver'] === 'mysql') $db->exec("USE `{$fx['target']}`");
require __DIR__ . '/src/Http/ApiHandlers.php';
$cfg = require __DIR__ . '/config.php';
Db::init($db, $fx['driver']);
JobQueue::setDir(__DIR__ . '/jobs');
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $fx['stores']);
StoreContext::activate(StoreContext::resolve($fx['stores'], $_SERVER['REQUEST_URI'], '', null, 'api'));
header('Content-Type: application/json');
if (($_GET['action'] ?? '') === 'dashboard') {
    echo json_encode(OperationalDashboard::summary()['webhook_incidents'], JSON_THROW_ON_ERROR);
    return;
}
if (($_GET['action'] ?? '') === 'webhook_shipping') {
    putenv('SHIP_PACKETA_WEBHOOK_SECRET=' . $fx['ship_secret']);
    echo json_encode(apiWebhookShipping(), JSON_THROW_ON_ERROR);
    return;
}
$gateway = ($_GET['provider'] ?? 'stripe') === 'payu' ? new PayUPayment($cfg) : new StripePayment($cfg);
(new ReflectionProperty(Adapters::class, 'instances'))->setValue(null, ['payment' => $gateway]);
echo json_encode(($_GET['action'] ?? '') === 'webhook_stripe' ? apiWebhookStripe() : apiWebhookPayment(), JSON_THROW_ON_ERROR);
PHP);

$a056Drivers = ['sqlite' => $a056Root . '/webhooks.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a056Drivers['mysql'] = 'bbe_a056_webhook_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A05.6 native webhook store resolution (set TEST_MYSQL_DSN)\n";
$a056Php = static function(array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
};
try {
    foreach ($a056Drivers as $a056Driver => $a056Target) {
        $a056Tag = "A05.6 {$a056Driver}";
        file_put_contents($a056Root . '/fixture.json', json_encode(['driver' => $a056Driver, 'target' => $a056Target, 'root' => dirname(TEST_CLI_DIR), 'stores' => $a056Stores, 'ship_secret' => $a056ShipSecret], JSON_THROW_ON_ERROR));
        assert_equals([0, ''], $a056Php([$a056Root . '/setup.php']), "{$a056Tag}: fresh schema migrated");
        $a056Db = $a056Driver === 'mysql'
            ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
            : new PDO('sqlite:' . $a056Target);
        $a056Db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $a056Db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($a056Driver === 'mysql') $a056Db->exec("USE `{$a056Target}`");
        $a056Ids = [];
        $a056Insert = static function(string $case, string $store, string $currency, string $paymentStatus = 'pending', string $paymentId = '') use ($a056Db, &$a056Ids): void {
            $a056Db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_id, subtotal, total, currency, store_id)
                VALUES (?, ?, ?, 'A056', 'a056@example.test', 'card', ?, 1000, 1000, ?, ?)")
                ->execute(['A056-' . $case, $paymentStatus === 'pending' ? 'pending' : 'paid', $paymentStatus, $paymentId, $currency, $store]);
            $a056Ids[$case] = (int)$a056Db->lastInsertId();
        };
        $a056Insert('D1', 'default', 'eur');
        $a056Insert('C1', 'cz', 'czk');
        $a056Insert('C2', 'cz', 'czk');
        $a056Insert('C3', 'cz', 'czk', 'paid', 'pi_a056_c3');
        $a056Insert('C4', 'cz', 'czk', 'paid', 'pi_a056_c4');
        $a056Insert('X1', 'xx', 'eur');
        $a056Insert('X2', 'xx', 'czk');
        $a056State = static function(string $case) use ($a056Db, &$a056Ids): string {
            $stmt = $a056Db->prepare('SELECT payment_status FROM orders WHERE id = ?');
            $stmt->execute([$a056Ids[$case]]);
            return (string)$stmt->fetchColumn();
        };
        $a056Inbox = static function(string $eventId) use ($a056Db): array {
            $stmt = $a056Db->prepare('SELECT store_id, status, attempts, resolved_store_id FROM payment_webhook_inbox WHERE event_id = ?');
            $stmt->execute([$eventId]);
            $row = $stmt->fetch();
            return $row ? ['store_id' => $row['store_id'], 'status' => $row['status'], 'attempts' => (int)$row['attempts'], 'resolved_store_id' => $row['resolved_store_id']] : [];
        };

        $a056Socket = stream_socket_server('tcp://127.0.0.1:0');
        $a056Address = stream_socket_get_name($a056Socket, false);
        fclose($a056Socket);
        $a056Server = proc_open([PHP_BINARY, '-S', $a056Address, $a056Root . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $a056Root . '/server.log', 'a'], 2 => ['file', $a056Root . '/server.log', 'a']], $a056Pipes);
        fclose($a056Pipes[0]);
        try {
            $a056Ready = false;
            for ($a056Attempt = 0; $a056Attempt < 50 && !$a056Ready; $a056Attempt++) {
                $a056Probe = @stream_socket_client('tcp://' . $a056Address, $a056Errno, $a056Error, 0.1);
                if ($a056Probe) { fclose($a056Probe); $a056Ready = true; } else usleep(100000);
            }
            assert_true($a056Ready, "{$a056Tag}: isolated HTTP fixture starts");
            $a056Request = static function(string $prefix, string $query, ?string $raw = null, string $header = '') use ($a056Address): array {
                $stream = fopen('http://' . $a056Address . $prefix . '/?' . $query, 'r', false, stream_context_create(['http' => [
                    'method' => $raw === null ? 'GET' : 'POST',
                    'header' => "Content-Type: application/json\r\n" . $header,
                    'content' => (string)$raw, 'ignore_errors' => true, 'timeout' => 20,
                ]]));
                $body = stream_get_contents($stream);
                preg_match('/\s(\d{3})\s/', stream_get_meta_data($stream)['wrapper_data'][0], $status);
                fclose($stream);
                return [(int)$status[1], json_decode($body, true) ?? $body];
            };
            $a056Stripe = static function(string $prefix, array $event, string $action = 'webhook_stripe') use ($a056Request, $a056StripeSecret): array {
                $raw = json_encode($event, JSON_THROW_ON_ERROR);
                $time = time();
                return $a056Request($prefix, 'action=' . $action . '&provider=stripe', $raw, 'Stripe-Signature: t=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $raw, $a056StripeSecret) . "\r\n");
            };
            $a056PayU = static function(string $prefix, string $orderNumber, string $status) use ($a056Request, $a056PayUSecret): array {
                $raw = json_encode(['order' => ['orderId' => 'payu-' . $orderNumber, 'extOrderId' => $orderNumber, 'status' => $status, 'totalAmount' => '1000', 'currencyCode' => 'CZK']], JSON_THROW_ON_ERROR);
                return $a056Request($prefix, 'action=webhook_payment&provider=payu', $raw, 'OpenPayU-Signature: sender=checkout;signature=' . md5($raw . $a056PayUSecret) . ";algorithm=MD5;content=DOCUMENT\r\n");
            };
            $a056Completed = static fn(string $id, int $orderId, string $currency, string $paymentStatus = 'paid'): array => ['id' => 'evt_a056_' . $id, 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_a056_' . $id, 'payment_intent' => 'pi_a056_' . $id, 'metadata' => ['order_id' => $orderId], 'payment_status' => $paymentStatus, 'amount_total' => 1000, 'currency' => $currency]]];

            // Provider-scope sharing: the default endpoint settles a cz order and vice versa.
            assert_equals([200, ['status' => 'ok']], $a056Stripe('', $a056Completed('c1', $a056Ids['C1'], 'czk')), "{$a056Tag}/stripe: default endpoint accepts cz order completion");
            assert_equals('paid', $a056State('C1'), "{$a056Tag}/stripe: cz order paid via shared provider scope");
            $a056Tx = $a056Db->prepare('SELECT store_id FROM payment_transactions WHERE order_id = ?');
            $a056Tx->execute([$a056Ids['C1']]);
            assert_equals(['cz'], array_values(array_unique($a056Tx->fetchAll(PDO::FETCH_COLUMN))), "{$a056Tag}/stripe: payment transactions recorded in the order's store");
            assert_equals([200, ['status' => 'ok']], $a056PayU('', 'A056-C2', 'COMPLETED'), "{$a056Tag}/payu: default endpoint accepts cz order");
            assert_equals('paid', $a056State('C2'), "{$a056Tag}/payu: cz order paid via shared provider scope");
            assert_equals([200, ['status' => 'ok']], $a056Stripe('', ['id' => 'evt_a056_c4_refund', 'type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_a056_c4', 'payment_intent' => 'pi_a056_c4', 'amount_refunded' => 1000]]]), "{$a056Tag}/stripe: refund for cz order accepted");
            assert_equals('refunded', $a056State('C4'), "{$a056Tag}/stripe: cz refund applied in the order's store");
            assert_equals([200, ['status' => 'ok']], $a056Stripe('', ['id' => 'evt_a056_c3_dispute', 'type' => 'charge.dispute.created', 'data' => ['object' => ['id' => 'dp_a056_c3', 'payment_intent' => 'pi_a056_c3', 'amount' => 1000]]], 'webhook_payment'), "{$a056Tag}/stripe: dispute for cz order accepted (generic route)");
            assert_equals('disputed', $a056State('C3'), "{$a056Tag}/stripe: cz dispute applied in the order's store");
            assert_equals([200, ['status' => 'ok']], $a056Stripe('/cz', $a056Completed('d1', $a056Ids['D1'], 'eur')), "{$a056Tag}/stripe: cz endpoint accepts default order completion");
            assert_equals('paid', $a056State('D1'), "{$a056Tag}/stripe: default order paid via cz endpoint");

            // Audit round 4: Stripe verifies with the single global stripe_webhook_secret (one dashboard endpoint), so an
            // order of a store in another provider scope is located and settled as well.
            assert_equals([200, ['status' => 'ok']], $a056Stripe('', $a056Completed('x1', $a056Ids['X1'], 'eur')), "{$a056Tag}/stripe: other provider scope order is settled by the global Stripe endpoint");
            assert_equals('paid', $a056State('X1'), "{$a056Tag}/stripe: other provider scope order paid in its store");
            assert_equals([], $a056Inbox('evt_a056_x1'), "{$a056Tag}/stripe: other provider scope event is not parked");
            // Other providers keep their scope: a PayU notification for the xx order reaches it only through /xx.
            assert_equals(503, $a056PayU('', 'A056-X2', 'COMPLETED')[0], "{$a056Tag}/payu: foreign provider scope order is not settled but retried");
            assert_equals('pending', $a056State('X2'), "{$a056Tag}/payu: foreign provider scope order stays pending");
            assert_equals([200, ['status' => 'ok']], $a056PayU('/xx', 'A056-X2', 'COMPLETED'), "{$a056Tag}/payu: the store's own notify_url settles it");
            assert_equals('paid', $a056State('X2'), "{$a056Tag}/payu: order paid through its store's endpoint");

            // Unknown order: persisted, provider retries are bounded, then parked as an incident.
            $a056Unknown = $a056Completed('unknown', 99999999, 'eur');
            for ($a056I = 1; $a056I < PaymentWebhookRouting::MAX_ATTEMPTS; $a056I++) {
                assert_equals([503, ['error' => 'Order not resolvable yet', 'retry' => true]], $a056Stripe('', $a056Unknown), "{$a056Tag}/stripe: unknown order attempt {$a056I} asks provider to retry");
            }
            assert_equals([202, ['status' => 'parked']], $a056Stripe('', $a056Unknown), "{$a056Tag}/stripe: unknown order parked after bounded retries");
            assert_equals(['store_id' => 'default', 'status' => 'dead', 'attempts' => PaymentWebhookRouting::MAX_ATTEMPTS, 'resolved_store_id' => null], $a056Inbox('evt_a056_unknown'), "{$a056Tag}/stripe: unknown order persisted as dead incident");
            assert_equals(0, (int)$a056Db->query("SELECT COUNT(*) FROM processed_events WHERE event_id = 'payment_stripe_evt_a056_unknown'")->fetchColumn(), "{$a056Tag}/stripe: unresolved event never consumes the idempotency claim");
            [$a056Code, $a056Incidents] = $a056Request('', 'action=dashboard');
            assert_true($a056Code === 200 && in_array(['stripe:checkout.session.completed', 'unresolved_dead'], array_map(static fn(array $row): array => [$row['event_type'], $row['status']], (array)$a056Incidents), true), "{$a056Tag}: admin dashboard of the receiving store shows the parked webhook");
            [$a056Code, $a056Incidents] = $a056Request('/cz', 'action=dashboard');
            assert_equals([200, []], [$a056Code, $a056Incidents], "{$a056Tag}: other store dashboard does not show the incident");

            // Late order: the retry after the order appears is processed and closes the inbox row.
            assert_equals(503, $a056PayU('', 'A056-LATE', 'COMPLETED')[0], "{$a056Tag}/payu: not-yet-known order asks provider to retry");
            $a056Insert('LATE', 'cz', 'czk');
            assert_equals([200, ['status' => 'ok']], $a056PayU('', 'A056-LATE', 'COMPLETED'), "{$a056Tag}/payu: redelivery after the order exists is accepted");
            assert_equals('paid', $a056State('LATE'), "{$a056Tag}/payu: late order paid in its store");
            assert_equals(['store_id' => 'default', 'status' => 'resolved', 'attempts' => 1, 'resolved_store_id' => 'cz'], $a056Inbox('payu-A056-LATE:completed'), "{$a056Tag}/payu: inbox row resolved with the owning store");

            // Events that move no money for an unknown order are acknowledged without noise.
            assert_equals([200, ['status' => 'ok']], $a056Stripe('', $a056Completed('unpaid', 99999998, 'eur', 'unpaid')), "{$a056Tag}/stripe: unpaid completion for unknown order acknowledged");
            assert_equals([200, ['status' => 'ok']], $a056PayU('', 'A056-NOPE', 'PENDING'), "{$a056Tag}/payu: PENDING for unknown order acknowledged");
            assert_equals([[], []], [$a056Inbox('evt_a056_unpaid'), $a056Inbox('payu-A056-NOPE:pending')], "{$a056Tag}: non-actionable events are not parked");

            // A05.7: carrier tracking webhooks resolve labels in the same provider scope, never across it.
            foreach (['C1' => 'cz', 'X1' => 'xx'] as $a056Case => $a056LabelStore) {
                $a056Db->prepare("INSERT INTO shipping_labels (order_id, carrier, awb, status, label_data, store_id) VALUES (?, 'packeta', ?, 'created', '', ?)")
                    ->execute([$a056Ids[$a056Case], 'AWB-A056-' . $a056Case, $a056LabelStore]);
            }
            $a056Track = static function(string $awb) use ($a056Request, $a056ShipSecret): array {
                $raw = json_encode(['awb' => $awb, 'status' => 'in_transit'], JSON_THROW_ON_ERROR);
                return $a056Request('', 'action=webhook_shipping&carrier=packeta', $raw, 'X-Packeta-Signature: ' . hash_hmac('sha256', $raw, $a056ShipSecret) . "\r\n");
            };
            $a056LabelState = static function(string $case) use ($a056Db): string {
                $stmt = $a056Db->prepare('SELECT status FROM shipping_labels WHERE awb = ?');
                $stmt->execute(['AWB-A056-' . $case]);
                return (string)$stmt->fetchColumn();
            };
            [$a056Code, $a056Body] = $a056Track('AWB-A056-C1');
            assert_equals([200, true, $a056Ids['C1']], [$a056Code, $a056Body['handled'] ?? null, $a056Body['order_id'] ?? null], "{$a056Tag}/shipping: default endpoint resolves cz label");
            assert_equals('in_transit', $a056LabelState('C1'), "{$a056Tag}/shipping: cz label status updated in its store");
            assert_equals([200, ['handled' => false, 'reason' => 'unknown_awb']], $a056Track('AWB-A056-X1'), "{$a056Tag}/shipping: foreign provider scope label is not resolved");
            assert_equals('created', $a056LabelState('X1'), "{$a056Tag}/shipping: foreign provider scope label unchanged");
        } finally {
            proc_terminate($a056Server);
            proc_close($a056Server);
            $a056Db = null;
            unset($a056State, $a056Inbox, $a056Insert, $a056Tx, $a056LabelState);
        }
    }
} finally {
    if (isset($a056Drivers['mysql'])) {
        file_put_contents($a056Root . '/fixture.json', json_encode(['driver' => 'mysql', 'target' => $a056Drivers['mysql'], 'root' => dirname(TEST_CLI_DIR), 'stores' => $a056Stores], JSON_THROW_ON_ERROR));
        $a056Php([$a056Root . '/setup.php', 'teardown']);
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a056Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $a056File) {
        $a056File->isDir() ? rmdir($a056File->getPathname()) : unlink($a056File->getPathname());
    }
    rmdir($a056Root);
}
