<?php

// Round 6 review (2026-10-19), hotfix: a released migration edited without its old checksum stopped
// `migrate` on every install that had applied it, and a payment the provider reports paid whose
// notification went to a removed route stayed pending forever (the expiry sweep only skipped it).
// Retry refund on credit notes: AuditRound4FinalReviewRegression (FR6 2); carrier webhooks: AuditRound5ShippingApiRegression (R6).
echo "\n--- Audit round 6 (hotfix): released migration checksums, provider-paid pending payments ---\n";
$r6hRoot = dirname(TEST_CLI_DIR);

// --- Released migrations keep being accepted --------------------------------------------------
// cli/tests/fixtures/released-migration-checksums.json lists every checksum (LF and CRLF) a released
// migration was applied with. A migration edited later must map each of them to its new content in
// Migrator::LEGACY_CHECKSUMS, or `migrate` stops with "modified after being applied" on those installs.
$r6hReleased = json_decode((string)file_get_contents(TEST_CLI_DIR . '/tests/fixtures/released-migration-checksums.json'), true);
assert_true(is_array($r6hReleased) && count($r6hReleased) >= 50, 'R6 migrations: the released checksum list is readable');
$r6hDir = test_fixture_path('r6-migrations');
mkdir($r6hDir, 0700, true);
$r6hUnaccepted = [];
$r6hUnlisted = [];
foreach (glob($r6hRoot . '/migrations/*.php') ?: [] as $r6hFile) {
    $r6hName = basename($r6hFile, '.php');
    if (!isset($r6hReleased[$r6hName])) { $r6hUnlisted[] = $r6hName; continue; }
    // The checksum an install records depends on the line endings of its checkout, which an update keeps.
    $r6hLf = str_replace("\r\n", "\n", (string)file_get_contents($r6hFile));
    foreach (['lf' => $r6hLf, 'crlf' => str_replace("\n", "\r\n", $r6hLf)] as $r6hEol => $r6hContent) {
        file_put_contents($r6hDir . '/' . $r6hName . '.php', $r6hContent);
        foreach ($r6hReleased[$r6hName][$r6hEol] ?? [] as $r6hRecorded) {
            $r6hDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $r6hMigrator = new Migrator($r6hDb, $r6hDir, $r6hDir . '/.lock');
            $r6hMigrator->ensureMigrationsTable();
            $r6hDb->prepare('INSERT INTO schema_migrations (name, checksum, duration_ms) VALUES (?, ?, 0)')->execute([$r6hName, $r6hRecorded]);
            if (($r6hMigrator->status()['applied'][$r6hName]['checksum_ok'] ?? false) !== true) $r6hUnaccepted[] = "{$r6hName} ({$r6hEol}, applied as {$r6hRecorded})";
        }
        unlink($r6hDir . '/' . $r6hName . '.php');
    }
}
@unlink($r6hDir . '/.lock');
@rmdir($r6hDir);
assert_equals([], $r6hUnaccepted, 'R6 migrations: every released checksum of every migration is still accepted (add the old checksum to Migrator::LEGACY_CHECKSUMS)');
assert_equals([], $r6hUnlisted, 'R6 migrations: every migration is in cli/tests/fixtures/released-migration-checksums.json (add its LF and CRLF sha256 when it is released)');
assert_equals([2, 2], [count($r6hReleased['20261017093000_catalog_stock_erp_retry']['lf'] ?? []), count($r6hReleased['20261017093000_catalog_stock_erp_retry']['crlf'] ?? [])], 'R6 migrations: the stock retry migration lists its PR #19 and PR #20 versions');

// --- A payment the provider reports paid is booked by the expiry sweep ------------------------
require_once $r6hRoot . '/adapters/PaymentAdapter.php';
$r6hCfg = ['gopay_goid' => '8123456789', 'gopay_client_id' => 'r6h-client', 'gopay_client_secret' => 'r6h-secret'];
$r6hStates = [];
$r6hTransport = static function (string $method, string $url, array $headers, ?string $body) use (&$r6hStates): array {
    $path = (string)parse_url($url, PHP_URL_PATH);
    if (str_ends_with($path, '/oauth2/token')) return ['status' => 200, 'body' => '{"token_type":"bearer","access_token":"r6h-token","expires_in":1800}'];
    $id = basename($path);
    if (!isset($r6hStates[$id])) return ['status' => 404, 'body' => '{"errors":[{"error_code":404}]}'];
    [$number, $state] = $r6hStates[$id];
    return ['status' => 200, 'body' => json_encode(['id' => (int)$id, 'order_number' => $number, 'state' => $state, 'amount' => 1990, 'currency' => 'EUR',
        'target' => ['type' => 'ACCOUNT', 'goid' => 8123456789]])];
};
$r6hGateway = new GoPayPayment($r6hCfg, $r6hTransport);
$r6hOrder = static function (string $number, string $paymentId) use ($pdo): int {
    $snapshot = json_encode(['provider' => 'gopay', 'service_type' => 'online_card', 'requires_online' => true]);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, store_id, created_at)
        VALUES (?, 'R6H', 'r6h@example.test', '', '{}', 1990, 0, 0, 0, 1990, 'eur', 'pending', 'pending', 'gopay', ?, ?, 'default', '2000-01-01 00:00:00')")
        ->execute([$number, $paymentId, $snapshot]);
    return (int)$pdo->lastInsertId();
};
$r6hState = static function (int $id): array {
    $order = Orders::byId($id);
    return [(string)$order['status'], (string)$order['payment_status']];
};
$r6hPaid = $r6hOrder('R6H-PAID-' . bin2hex(random_bytes(3)), '3600000001');
$r6hGone = $r6hOrder('R6H-GONE-' . bin2hex(random_bytes(3)), '3600000002');
$r6hStates['3600000001'] = [(string)Orders::byId($r6hPaid)['order_number'], 'PAID'];
$r6hStates['3600000002'] = [(string)Orders::byId($r6hGone)['order_number'], 'TIMEOUTED'];
Orders::expirePending(3600, 100, static fn(array $order): string => in_array((string)$order['payment_id'], ['3600000001', '3600000002'], true)
    ? PendingOrderExpiry::providerStatus($order, $r6hGateway, 'gopay') : 'created');
assert_true(($r6hState($r6hPaid)[1] ?? '') === 'paid' && ($r6hState($r6hPaid)[0] ?? '') !== 'cancelled',
    'R6 expiry: a pending GoPay payment the provider reports PAID is booked paid, not left pending forever (' . json_encode($r6hState($r6hPaid)) . ')');
assert_equals(['cancelled', 'cancelled'], $r6hState($r6hGone), 'R6 expiry: a payment the provider reports timed out still expires');
$r6hBooked = (int)$pdo->query("SELECT COUNT(*) FROM payment_transactions WHERE order_id = {$r6hPaid} AND status = 'paid'")->fetchColumn();
Orders::expirePending(3600, 100, static fn(array $order): string => (string)$order['payment_id'] === '3600000001' ? PendingOrderExpiry::providerStatus($order, $r6hGateway, 'gopay') : 'created');
assert_equals($r6hBooked, (int)$pdo->query("SELECT COUNT(*) FROM payment_transactions WHERE order_id = {$r6hPaid} AND status = 'paid'")->fetchColumn(),
    'R6 expiry: a second sweep books nothing again');
foreach (['GoPayPayment', 'ThePayPayment', 'ComgatePayment', 'PayUPayment'] as $r6hClass) {
    assert_true(is_subclass_of(Adapters::providerClass('payment', strtolower(str_replace('Payment', '', $r6hClass))), PaymentStatusEventAdapter::class),
        "R6 expiry: {$r6hClass} describes a payment as a status event, so the sweep can book it");
}
$r6hWorker = (string)file_get_contents(TEST_CLI_DIR . '/worker.php');
assert_true(str_contains($r6hWorker, 'PendingOrderExpiry::providerStatus('), 'R6 expiry: the worker sweep resolves provider status through PendingOrderExpiry::providerStatus()');
