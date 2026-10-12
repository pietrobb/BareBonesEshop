<?php

// Exercise the real HTTP handlers with raw signed bodies, without changing deployment config.
echo "\n--- Audit A02: PayU lifecycle and shared Stripe webhook ---\n";
$a02Root = test_fixture_path('payment-webhooks');
mkdir($a02Root . '/src/Http', 0700, true);
require_once dirname(TEST_CLI_DIR) . '/adapters/PaymentAdapter.php';
$a02Secret = 'audit-second-key-0123456789';
$a02StripeSecret = 'whsec_audit_0123456789012345';
$a02Cfg = ['payu_second_key' => $a02Secret, 'payu_client_secret' => 'oauth-secret-not-signing-key', 'stripe_webhook_secret' => $a02StripeSecret];
file_put_contents($a02Root . '/config.php', '<?php return ' . var_export($a02Cfg, true) . ';');
copy(dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php', $a02Root . '/src/Http/ApiHandlers.php');
$a02DbPath = $a02Root . '/webhooks.sqlite';
$a02SnapshotDb = new PDO('sqlite:' . $testDbPath);
$a02SnapshotDb->exec('VACUUM INTO ' . $a02SnapshotDb->quote($a02DbPath));
$a02SnapshotDb = null;
$a02Db = new PDO('sqlite:' . $a02DbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$a02Template = $a02Db->query('SELECT * FROM orders LIMIT 1')->fetch();
unset($a02Template['id']);
$a02Ids = [];
foreach (['payu', 'generic-first', 'dedicated-first', 'amount-retry', 'currency-retry', 'async', 'invalid'] as $a02Case) {
    $a02Row = array_replace($a02Template, ['order_number' => 'A02-' . $a02Case, 'status' => 'pending', 'payment_status' => 'pending', 'payment_id' => null, 'total' => 1000, 'currency' => 'eur', 'store_id' => 'default', 'payment_method_snapshot' => json_encode(['provider' => $a02Case === 'payu' ? 'payu' : 'stripe'])]);
    $a02Db->prepare('INSERT INTO orders (' . implode(',', array_keys($a02Row)) . ') VALUES (' . implode(',', array_fill(0, count($a02Row), '?')) . ')')->execute(array_values($a02Row));
    $a02Ids[$a02Case] = (int)$a02Db->lastInsertId();
}
file_put_contents($a02Root . '/router.php', '<?php
require ' . var_export(dirname(TEST_CLI_DIR) . '/src/autoload.php', true) . ';
require ' . var_export(dirname(TEST_CLI_DIR) . '/adapters/PaymentAdapter.php', true) . ';
require __DIR__ . "/src/Http/ApiHandlers.php";
$cfg = require __DIR__ . "/config.php";
$db = new PDO("sqlite:" . __DIR__ . "/webhooks.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Db::init($db, "sqlite");
JobQueue::setDir(__DIR__ . "/jobs");
Bootstrap::initStoreContext("/", "", "default");
$gateway = ($_GET["provider"] ?? "stripe") === "payu" ? new PayUPayment($cfg) : new StripePayment($cfg);
(new ReflectionProperty(Adapters::class, "instances"))->setValue(null, ["payment" => $gateway]);
header("Content-Type: application/json");
echo json_encode(($_GET["action"] ?? "") === "webhook_stripe" ? apiWebhookStripe() : apiWebhookPayment(), JSON_THROW_ON_ERROR);
');
$a02Socket = stream_socket_server('tcp://127.0.0.1:0', $a02Errno, $a02Error);
$a02Address = stream_socket_get_name($a02Socket, false);
fclose($a02Socket);
$a02Server = proc_open([PHP_BINARY, '-S', $a02Address, $a02Root . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $a02Root . '/server.log', 'a'], 2 => ['file', $a02Root . '/server.log', 'a']], $a02Pipes);
fclose($a02Pipes[0]);
try {
    $a02Ready = false;
    for ($a02Attempt = 0; $a02Attempt < 50; $a02Attempt++) {
        $a02Probe = @stream_socket_client('tcp://' . $a02Address, $a02Errno, $a02Error, 0.1);
        if ($a02Probe) { fclose($a02Probe); $a02Ready = true; break; }
        usleep(100000);
    }
    assert_true($a02Ready, 'Audit A02: isolated HTTP fixture starts');
    $a02Post = static function(string $action, string $provider, array $payload, ?string $signature = null, ?string $raw = null) use ($a02Address, $a02Secret, $a02StripeSecret): array {
        $raw ??= json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $header = $provider === 'payu' ? 'OpenPayU-Signature' : 'Stripe-Signature';
        $signature ??= $provider === 'payu'
            ? 'sender=checkout;signature=' . md5($raw . $a02Secret) . ';algorithm=MD5;content=DOCUMENT'
            : 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $raw, $a02StripeSecret);
        $stream = fopen('http://' . $a02Address . '/?action=' . $action . '&provider=' . $provider, 'r', false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n{$header}: {$signature}\r\n", 'content' => $raw, 'ignore_errors' => true, 'timeout' => 10]]));
        $body = stream_get_contents($stream);
        preg_match('/\s(\d{3})\s/', stream_get_meta_data($stream)['wrapper_data'][0], $status);
        fclose($stream);
        return [(int)$status[1], json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
    };
    $a02State = static fn(string $case): string => (string)$a02Db->query('SELECT payment_status FROM orders WHERE id = ' . $a02Ids[$case])->fetchColumn();
    $a02TxCount = static fn(string $case): int => (int)$a02Db->query('SELECT COUNT(*) FROM payment_transactions WHERE order_id = ' . $a02Ids[$case])->fetchColumn();
    $a02PayU = new PayUPayment($a02Cfg);
    $a02Pending = ['order' => ['orderId' => 'payu-audit-order', 'extOrderId' => 'A02-payu', 'status' => 'PENDING', 'totalAmount' => '1000', 'currencyCode' => 'EUR']];
    $a02Completed = $a02Pending;
    $a02Completed['order']['status'] = 'COMPLETED';
    assert_true($a02PayU->handleWebhook($a02Pending)['event_id'] !== $a02PayU->handleWebhook($a02Completed)['event_id'], 'Audit A02 PayU: distinct status identities');
    assert_equals([200, ['status' => 'ok']], $a02Post('webhook_payment', 'payu', $a02Pending), 'Audit A02 PayU: official MD5 second-key PENDING accepted');
    assert_equals('pending', $a02State('payu'), 'Audit A02 PayU: PENDING does not mark order paid');
    assert_equals([200, ['status' => 'already_processed']], $a02Post('webhook_payment', 'payu', $a02Pending), 'Audit A02 PayU: repeated PENDING deduplicates');
    assert_equals([200, ['status' => 'ok']], $a02Post('webhook_payment', 'payu', $a02Completed), 'Audit A02 PayU: COMPLETED not consumed by PENDING');
    assert_equals('paid', $a02State('payu'), 'Audit A02 PayU: COMPLETED marks payment paid');
    $a02PayUTxCount = $a02TxCount('payu');
    assert_equals([200, ['status' => 'already_processed']], $a02Post('webhook_payment', 'payu', $a02Completed), 'Audit A02 PayU: COMPLETED replay deduplicates');
    assert_equals($a02PayUTxCount, $a02TxCount('payu'), 'Audit A02 PayU: replay writes no transaction');
    $a02Raw = json_encode($a02Completed, JSON_THROW_ON_ERROR);
    foreach ([
        '',
        'signature=' . hash('sha256', $a02Raw . $a02Cfg['payu_client_secret']) . ';algorithm=SHA-256',
        'signature=' . md5($a02Raw . $a02Secret) . ';algorithm=SHA-512',
        'signature=' . md5($a02Raw . $a02Secret) . ';algorithm=MD5;algorithm=SHA-256',
    ] as $a02BadSignature) {
        assert_true(!$a02PayU->verifySignature($a02Raw, $a02BadSignature), 'Audit A02 PayU: malformed/unsupported/OAuth signature rejected');
    }
    assert_true(!$a02PayU->verifySignature($a02Raw . ' ', 'signature=' . md5($a02Raw . $a02Secret) . ';algorithm=MD5'), 'Audit A02 PayU: raw body tampering rejected');
    assert_true(!(new PayUPayment(['payu_client_secret' => $a02Secret]))->verifySignature($a02Raw, 'signature=' . md5($a02Raw . $a02Secret) . ';algorithm=MD5'), 'Audit A02 PayU: missing second key fails closed');
    assert_equals('', $a02PayU->handleWebhook(['order' => ['status' => 'COMPLETED']])['event_id'], 'Audit A02 PayU: missing provider identity never random');
    $a02StripeEvent = static fn(string $case): array => ['id' => 'evt_a02_' . $case, 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_a02_' . $case, 'payment_intent' => 'pi_a02_' . $case, 'metadata' => ['order_id' => $a02Ids[$case]], 'payment_status' => 'paid', 'amount_total' => 1000, 'currency' => 'eur']]];
    foreach (['generic-first' => ['webhook_payment', 'webhook_stripe'], 'dedicated-first' => ['webhook_stripe', 'webhook_payment']] as $a02Case => [$a02First, $a02Second]) {
        $a02Event = $a02StripeEvent($a02Case);
        assert_equals([200, ['status' => 'ok']], $a02Post($a02First, 'stripe', $a02Event), "Audit A02 Stripe {$a02Case}: first handler accepts");
        assert_equals('paid', $a02State($a02Case), "Audit A02 Stripe {$a02Case}: first handler pays order");
        $a02Count = $a02TxCount($a02Case);
        assert_equals([200, ['status' => 'already_processed']], $a02Post($a02Second, 'stripe', $a02Event), "Audit A02 Stripe {$a02Case}: second handler shares dedup");
        assert_equals($a02Count, $a02TxCount($a02Case), "Audit A02 Stripe {$a02Case}: replay adds no transaction");
    }
    foreach (['amount-retry' => ['amount_total', 999], 'currency-retry' => ['currency', 'usd']] as $a02Case => [$a02Field, $a02Wrong]) {
        $a02Event = $a02StripeEvent($a02Case);
        $a02Event['data']['object'][$a02Field] = $a02Wrong;
        assert_equals(500, $a02Post('webhook_payment', 'stripe', $a02Event)[0], "Audit A02 Stripe {$a02Case}: mismatch fails");
        assert_equals('pending', $a02State($a02Case), "Audit A02 Stripe {$a02Case}: mismatch cannot pay");
        assert_equals(0, $a02TxCount($a02Case), "Audit A02 Stripe {$a02Case}: mismatch writes no payment transaction");
        assert_equals(0, (int)$a02Db->query('SELECT COUNT(*) FROM processed_events WHERE event_id = ' . $a02Db->quote('payment_stripe_' . $a02Event['id']))->fetchColumn(), "Audit A02 Stripe {$a02Case}: failed claim released");
        assert_equals([200, ['status' => 'ok']], $a02Post('webhook_stripe', 'stripe', $a02StripeEvent($a02Case)), "Audit A02 Stripe {$a02Case}: valid retry accepted");
        assert_equals('paid', $a02State($a02Case), "Audit A02 Stripe {$a02Case}: retry pays order");
    }
    $a02Event = $a02StripeEvent('async');
    $a02Event['data']['object']['payment_status'] = 'unpaid';
    assert_equals([200, ['status' => 'ok']], $a02Post('webhook_payment', 'stripe', $a02Event), 'Audit A02 Stripe: unpaid completion acknowledged');
    assert_equals('pending', $a02State('async'), 'Audit A02 Stripe: unpaid completion cannot pay');
    $a02Event['id'] .= '_succeeded';
    $a02Event['type'] = 'checkout.session.async_payment_succeeded';
    $a02Event['data']['object']['payment_status'] = 'paid';
    assert_equals([200, ['status' => 'ok']], $a02Post('webhook_payment', 'stripe', $a02Event), 'Audit A02 Stripe: async success accepted');
    assert_equals('paid', $a02State('async'), 'Audit A02 Stripe: async success pays order');
    $a02Event = $a02StripeEvent('invalid');
    foreach (['webhook_payment', 'webhook_stripe'] as $a02Action) {
        assert_equals([400, ['error' => 'Invalid signature']], $a02Post($a02Action, 'stripe', $a02Event, 't=' . time() . ',v1=bad'), "Audit A02 Stripe {$a02Action}: invalid signature rejected");
        $a02SignedRaw = json_encode($a02Event, JSON_THROW_ON_ERROR);
        $a02Time = time();
        $a02Signature = 't=' . $a02Time . ',v1=' . hash_hmac('sha256', $a02Time . '.' . $a02SignedRaw, $a02StripeSecret);
        assert_equals(400, $a02Post($a02Action, 'stripe', $a02Event, $a02Signature, $a02SignedRaw . ' ')[0], "Audit A02 Stripe {$a02Action}: raw tampering rejected");
    }
    assert_equals('pending', $a02State('invalid'), 'Audit A02 Stripe: rejected bodies cannot pay');
    assert_equals([200, ['status' => 'ok']], $a02Post('webhook_payment', 'stripe', $a02Event), 'Audit A02 Stripe: invalid signature does not consume valid retry');
} finally {
    proc_terminate($a02Server);
    proc_close($a02Server);
    unset($a02State, $a02TxCount);
    $a02Db = null;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a02Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $a02File) {
        $a02File->isDir() ? rmdir($a02File->getPathname()) : unlink($a02File->getPathname());
    }
    rmdir($a02Root);
}
