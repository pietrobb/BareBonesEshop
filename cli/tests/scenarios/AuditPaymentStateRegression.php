<?php

// A06.1: real signed PayU HTTP notifications through the generic webhook_payment route and the payment state machine.
echo "\n--- Audit A06.1: generic payment webhook state machine, amount and currency ---\n";
$a061Root = test_fixture_path('payment-state');
mkdir($a061Root . '/src/Http', 0700, true);
$a061Secret = 'a061-second-key-0123456789';
file_put_contents($a061Root . '/config.php', '<?php return ' . var_export(['payu_second_key' => $a061Secret], true) . ';');
copy(dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php', $a061Root . '/src/Http/ApiHandlers.php');
$a061Stores = ['default_store' => 'default', 'stores' => ['default' => ['name' => 'default', 'default_locale' => 'en', 'languages' => ['en'], 'currency' => 'czk', 'url_prefix' => '', 'domains' => [], 'permissions' => ['*'], 'channels' => ['storefront', 'api'], 'catalog_scope' => 'default', 'provider_scope' => 'default']]];
$a061Common = <<<'PHP'
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
file_put_contents($a061Root . '/setup.php', $a061Common . <<<'PHP'

if ($fx['driver'] === 'mysql') {
    if (($argv[1] ?? '') === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$fx['target']}`"); exit(0); }
    $db->exec("CREATE DATABASE `{$fx['target']}`");
    $db->exec("USE `{$fx['target']}`");
}
Db::init($db, $fx['driver']);
(new Migrator($db, $fx['root'] . '/migrations', __DIR__ . '/migrate.lock'))->apply();
PHP);
file_put_contents($a061Root . '/router.php', $a061Common . <<<'PHP'

if ($fx['driver'] === 'mysql') $db->exec("USE `{$fx['target']}`");
require __DIR__ . '/src/Http/ApiHandlers.php';
$cfg = require __DIR__ . '/config.php';
Db::init($db, $fx['driver']);
JobQueue::setDir(__DIR__ . '/jobs');
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $fx['stores']);
StoreContext::activate(StoreContext::resolve($fx['stores'], $_SERVER['REQUEST_URI'], '', null, 'api'));
header('Content-Type: application/json');
(new ReflectionProperty(Adapters::class, 'instances'))->setValue(null, ['payment' => new PayUPayment($cfg)]);
echo json_encode(apiWebhookPayment(), JSON_THROW_ON_ERROR);
PHP);

$a061Drivers = ['sqlite' => $a061Root . '/payments.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a061Drivers['mysql'] = 'bbe_a061_payment_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A06.1 native payment state machine (set TEST_MYSQL_DSN)\n";
$a061Php = static function(array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
};
try {
    foreach ($a061Drivers as $a061Driver => $a061Target) {
        $a061Tag = "A06.1 {$a061Driver}";
        file_put_contents($a061Root . '/fixture.json', json_encode(['driver' => $a061Driver, 'target' => $a061Target, 'root' => dirname(TEST_CLI_DIR), 'stores' => $a061Stores], JSON_THROW_ON_ERROR));
        assert_equals([0, ''], $a061Php([$a061Root . '/setup.php']), "{$a061Tag}: fresh schema migrated");
        $a061Db = $a061Driver === 'mysql'
            ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
            : new PDO('sqlite:' . $a061Target);
        $a061Db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $a061Db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($a061Driver === 'mysql') $a061Db->exec("USE `{$a061Target}`");
        $a061Ids = [];
        foreach (['P1' => 'pending', 'P2' => 'partially_refunded', 'P3' => 'pending', 'P4' => 'paid', 'P5' => 'pending'] as $a061Case => $a061Payment) {
            $a061Db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_id, subtotal, total, currency, store_id)
                VALUES (?, ?, ?, 'A061', 'a061@example.test', 'payu', ?, 1000, 1000, 'czk', 'default')")
                ->execute(['A061-' . $a061Case, $a061Payment === 'pending' ? 'pending' : 'paid', $a061Payment, 'payu-A061-' . $a061Case]);
            $a061Ids[$a061Case] = (int)$a061Db->lastInsertId();
        }
        // P2: a refund the worker already executed and logged under the provider refund id.
        $a061Db->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response)
            VALUES (?, 'default', 'payu', 'R-WORKER', 'refund_succeeded', 400, 'czk', 'a061_worker_refund', '{}')")->execute([$a061Ids['P2']]);
        $a061State = static function(string $case) use ($a061Db, &$a061Ids): array {
            $stmt = $a061Db->prepare('SELECT status, payment_status FROM orders WHERE id = ?');
            $stmt->execute([$a061Ids[$case]]);
            $row = $stmt->fetch();
            $sum = $a061Db->prepare("SELECT COALESCE(SUM(amount), 0) FROM payment_transactions WHERE order_id = ? AND status IN ('partially_refunded', 'refunded', 'refund_succeeded')");
            $sum->execute([$a061Ids[$case]]);
            return [(string)$row['payment_status'], (string)$row['status'], (int)$sum->fetchColumn()];
        };

        $a061Socket = stream_socket_server('tcp://127.0.0.1:0');
        $a061Address = stream_socket_get_name($a061Socket, false);
        fclose($a061Socket);
        $a061Server = proc_open([PHP_BINARY, '-S', $a061Address, $a061Root . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $a061Root . '/server.log', 'a'], 2 => ['file', $a061Root . '/server.log', 'a']], $a061Pipes);
        fclose($a061Pipes[0]);
        try {
            $a061Ready = false;
            for ($a061Attempt = 0; $a061Attempt < 50 && !$a061Ready; $a061Attempt++) {
                $a061Probe = @stream_socket_client('tcp://' . $a061Address, $a061Errno, $a061Error, 0.1);
                if ($a061Probe) { fclose($a061Probe); $a061Ready = true; } else usleep(100000);
            }
            assert_true($a061Ready, "{$a061Tag}: isolated HTTP fixture starts");
            $a061Send = static function(array $notification) use ($a061Address, $a061Secret): array {
                $raw = json_encode($notification, JSON_THROW_ON_ERROR);
                $stream = fopen('http://' . $a061Address . '/?action=webhook_payment&provider=payu', 'r', false, stream_context_create(['http' => [
                    'method' => 'POST', 'content' => $raw, 'ignore_errors' => true, 'timeout' => 20,
                    'header' => "Content-Type: application/json\r\nOpenPayU-Signature: sender=checkout;signature=" . md5($raw . $a061Secret) . ";algorithm=MD5;content=DOCUMENT\r\n",
                ]]));
                $body = stream_get_contents($stream);
                preg_match('/\s(\d{3})\s/', stream_get_meta_data($stream)['wrapper_data'][0], $status);
                fclose($stream);
                return [(int)$status[1], json_decode($body, true) ?? $body];
            };
            $a061Order = static fn(string $case, string $status, string $amount = '1000', string $currency = 'CZK'): array => ['order' => ['orderId' => 'payu-A061-' . $case, 'extOrderId' => 'A061-' . $case, 'status' => $status, 'totalAmount' => $amount, 'currencyCode' => $currency]];
            $a061Refund = static fn(string $case, string $refundId, string $amount, string $status = 'FINALIZED', string $currency = 'CZK'): array => ['orderId' => 'payu-A061-' . $case, 'extOrderId' => 'A061-' . $case, 'refund' => ['refundId' => $refundId, 'amount' => $amount, 'currencyCode' => $currency, 'status' => $status, 'reason' => 'refund']];
            $a061Ok = [200, ['status' => 'ok']];

            // Paid requires the exact order amount and currency.
            assert_equals(500, $a061Send($a061Order('P1', 'COMPLETED', '999'))[0], "{$a061Tag}: underpaid COMPLETED is rejected");
            assert_equals(500, $a061Send($a061Order('P1', 'COMPLETED', '1000', 'EUR'))[0], "{$a061Tag}: COMPLETED in another currency is rejected");
            assert_equals(['pending', 'pending', 0], $a061State('P1'), "{$a061Tag}: mismatched payment leaves the order unpaid");
            assert_equals($a061Ok, $a061Send($a061Order('P1', 'COMPLETED')), "{$a061Tag}: matching COMPLETED accepted");
            assert_equals(['paid', 'paid', 0], $a061State('P1'), "{$a061Tag}: matching COMPLETED marks the order paid");

            // A late failure never reverts a captured payment.
            assert_equals($a061Ok, $a061Send($a061Order('P1', 'CANCELED')), "{$a061Tag}: late CANCELED acknowledged");
            assert_equals(['paid', 'paid', 0], $a061State('P1'), "{$a061Tag}: late CANCELED keeps the order paid");
            assert_equals($a061Ok, $a061Send($a061Order('P3', 'REJECTED')), "{$a061Tag}: REJECTED for a pending order accepted");
            assert_equals('failed', $a061State('P3')[0], "{$a061Tag}: REJECTED fails a pending payment");

            // Refunds follow the official PayU refund notification and accumulate amounts.
            assert_equals($a061Ok, $a061Send($a061Refund('P1', 'R-1', '400')), "{$a061Tag}: partial refund accepted");
            assert_equals(['partially_refunded', 'paid', 400], $a061State('P1'), "{$a061Tag}: partial refund is partially_refunded, not refunded");
            assert_equals([200, ['status' => 'already_processed']], $a061Send($a061Refund('P1', 'R-1', '400')), "{$a061Tag}: refund replay is a duplicate");
            assert_equals(['partially_refunded', 'paid', 400], $a061State('P1'), "{$a061Tag}: refund replay does not count twice");
            assert_equals(500, $a061Send($a061Refund('P1', 'R-EUR', '100', 'FINALIZED', 'EUR'))[0], "{$a061Tag}: refund in another currency is rejected");
            assert_equals($a061Ok, $a061Send($a061Refund('P1', 'R-CANCEL', '600', 'CANCELED')), "{$a061Tag}: canceled refund acknowledged");
            assert_equals(['partially_refunded', 'paid', 400], $a061State('P1'), "{$a061Tag}: canceled refund moves no money");
            assert_equals($a061Ok, $a061Send($a061Refund('P1', 'R-2', '600')), "{$a061Tag}: remaining refund accepted");
            assert_equals(['refunded', 'paid', 1000], $a061State('P1'), "{$a061Tag}: cumulative refund reaching the total is refunded");
            assert_equals($a061Ok, $a061Send($a061Refund('P2', 'R-WORKER', '400')), "{$a061Tag}: notification for a worker-executed refund accepted");
            assert_equals(['partially_refunded', 'paid', 400], $a061State('P2'), "{$a061Tag}: worker-executed refund is not counted twice");
            assert_equals($a061Ok, $a061Send($a061Refund('P5', 'R-UNPAID', '1000')), "{$a061Tag}: refund for an unpaid order acknowledged");
            assert_equals(['pending', 'pending', 0], $a061State('P5'), "{$a061Tag}: refund for an unpaid order changes nothing");

            // Disputes and duplicate captures go through the same state machine.
            assert_equals($a061Ok, $a061Send($a061Order('P4', 'COMPLETED')), "{$a061Tag}: second COMPLETED for a paid order acknowledged");
            assert_equals(['paid', 'paid', 0], $a061State('P4'), "{$a061Tag}: second COMPLETED changes nothing");
            $a061Tx = $a061Db->prepare("SELECT status FROM payment_transactions WHERE order_id = ? ORDER BY id");
            $a061Tx->execute([$a061Ids['P4']]);
            assert_equals(['ignored'], $a061Tx->fetchAll(PDO::FETCH_COLUMN), "{$a061Tag}: inapplicable event is kept in the payment log as ignored");
        } finally {
            proc_terminate($a061Server);
            proc_close($a061Server);
            $a061Db = null;
            unset($a061State, $a061Tx);
        }
    }
} finally {
    if (isset($a061Drivers['mysql'])) {
        file_put_contents($a061Root . '/fixture.json', json_encode(['driver' => 'mysql', 'target' => $a061Drivers['mysql'], 'root' => dirname(TEST_CLI_DIR), 'stores' => $a061Stores], JSON_THROW_ON_ERROR));
        $a061Php([$a061Root . '/setup.php', 'teardown']);
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a061Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $a061File) {
        $a061File->isDir() ? rmdir($a061File->getPathname()) : unlink($a061File->getPathname());
    }
    rmdir($a061Root);
}
