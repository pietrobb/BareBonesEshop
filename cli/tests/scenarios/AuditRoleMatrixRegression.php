<?php

// A07.1 / findings 17, 18: least-privilege admin roles for money-moving actions, product CSV export behind its permission.
echo "\n--- Audit A07.1: admin role matrix ---\n";
ConsentService::setDefinitionsForTesting([]);
// Earlier scenarios keep half-read global statements (e.g. LIMIT 1 + fetch); their open cursors pin an old WAL
// snapshot, so the suite connection could neither see nor write past commits made by the child processes below.
foreach ($GLOBALS as $a071Value) if ($a071Value instanceof PDOStatement) $a071Value->closeCursor();
$pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (9711, 'default', 1, 'A071 role item', 'a071-role-item', 'A071-ROLE', 'simple', 1500, 'eur', 'active', 0, 'backorder')");
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
Cart::clear();
Cart::add(9711, null, 1);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.7.1';
$_SESSION['csrf_token'] = 'a071-checkout';
$_POST = ['csrf_token' => 'a071-checkout', 'name' => 'A071 Buyer', 'email' => 'a071-role@example.com', 'country' => 'SK',
    'street' => 'Role 1', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'packeta', 'payment_method' => 'bank_transfer'];
$a071Checkout = apiCheckout();
$_POST = [];
Cart::clear();
$a071OrderId = (int)($a071Checkout['order_id'] ?? 0);
assert_true($a071OrderId > 0, 'A07.1 fixture: pending bank-transfer order exists (' . ($a071Checkout['error'] ?? 'ok') . ')');

$a071Root = test_fixture_path('admin-roles');
mkdir($a071Root);
mkdir($a071Root . '/sessions');
// Router runs sign in as real DB identities: a legacy config session ends once the database holds an account (r10 M1).
putenv('BBE_ADMIN_IDENTITY_KEY=' . base64_encode(str_repeat('R', 32)));
$a071Tokens = [];
foreach (['editor', 'fulfillment', 'manager'] as $a071Role) {
    AdminIdentity::grantStore(AdminIdentity::create('a071-' . $a071Role, 'A071-password-' . $a071Role, $a071Role, false), 'default', ['*']);
    $a071Tokens[$a071Role] = (string)(AdminIdentity::authenticate('a071-' . $a071Role, 'A071-password-' . $a071Role, '', '127.0.7.2', 'A071')['token'] ?? '');
}
putenv('BBE_ADMIN_IDENTITY_KEY');
file_put_contents($a071Root . '/tokens.json', json_encode($a071Tokens));
file_put_contents($a071Root . '/admin.php', <<<'PHP'
<?php
[, $root, $dbPath, $dir, $mode, $role, $page, $action, $postJson] = $argv;
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
JobQueue::setDir($dir . '/jobs');
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$post = json_decode($postJson, true);
$_SESSION = ['csrf_token' => 'a071', 'admin_user' => 'a071-' . $role, 'admin_role' => $role];
if ($mode === 'router') {
    $_SESSION += ['admin_identity_token' => json_decode(file_get_contents($dir . '/tokens.json'), true)[$role]];
} else {
    $_SESSION += ['admin_identity_id' => 1, 'admin_store_permissions' => ['default' => $post['__grants']]];
}
unset($post['__grants']);
$_SERVER['REQUEST_METHOD'] = $action === '' ? 'GET' : 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.7.2';
$_GET = ['p' => $page, 'action' => $action] + ($post['__get'] ?? []);
unset($post['__get']);
$_POST = $post + ['csrf_token' => 'a071'];
ob_start();
register_shutdown_function(static function(): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nA071RESULT" . json_encode(['code' => http_response_code(), 'flash' => $_SESSION['flash'] ?? null, 'out' => $out]);
});
if ($mode === 'router') {
    require $root . '/src/Entrypoints/Admin.php';
} else {
    require_once $root . '/src/Entrypoints/Admin/Shared.php';
    require_once $root . '/src/Entrypoints/Admin/Orders.php';
    $action();
}
PHP);
$a071Run = static function(string $mode, string $role, string $page, string $action, array $post = []) use ($a071Root, $testDbPath): array {
    $process = proc_open([PHP_BINARY, $a071Root . '/admin.php', dirname(TEST_CLI_DIR), $testDbPath, $a071Root, $mode, $role, $page, $action, json_encode($post)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $at = strrpos($raw, "A071RESULT");
    $result = $at === false ? null : json_decode(substr($raw, $at + 10), true);
    return is_array($result) ? $result + ['raw' => ''] : ['code' => null, 'flash' => null, 'out' => '', 'raw' => substr($raw, 0, 400)];
};
// The suite connection can hold a WAL snapshot from an unfinished cursor; read child effects through a fresh one.
$a071Read = static fn(string $sql, array $args = []) => (static function() use ($sql, $args, $testDbPath) {
    $read = new PDO('sqlite:' . $testDbPath);
    $stmt = $read->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchColumn();
})();
$a071Denied = static fn(array $r): bool => $r['code'] === 403 && str_contains($r['out'], 'insufficient permissions');
// Allowed = the handler really ran: it set a flash (handlers redirect) and nothing fatal happened.
$a071Handled = static fn(array $r): bool => $r['code'] !== 403 && is_array($r['flash']) && !str_contains($r['out'], 'Fatal error');
$a071Label = static fn(string $label, array $r): string => $label . ' (code=' . var_export($r['code'], true) . ' flash=' . json_encode($r['flash']) . ($r['raw'] !== '' ? ' raw=' . $r['raw'] : '') . ')';
$a071PaymentStatus = static fn() => $a071Read('SELECT payment_status FROM orders WHERE id = ?', [$a071OrderId]);

try {
    // Editor (catalogue role): no payment methods/fees, no shipping prices, no returns.
    $r = $a071Run('router', 'editor', 'payments', 'method_save', ['code' => 'a071-editor', 'name' => 'Editor fee', 'provider' => 'manual', 'service_type' => 'offline', 'fixed_fee' => '0', 'active' => '1']);
    assert_true($a071Denied($r) && (int)$a071Read("SELECT COUNT(*) FROM payment_methods WHERE code = 'a071-editor'") === 0, $a071Label('A07.1 editor: cannot create or change payment methods/fees', $r));
    $r = $a071Run('router', 'editor', 'shipping', 'zone_save', ['code' => 'a071-ed', 'name' => 'Editor zone', 'active' => '1']);
    assert_true($a071Denied($r) && (int)$a071Read("SELECT COUNT(*) FROM shipping_zones WHERE code = 'a071-ed'") === 0, $a071Label('A07.1 editor: cannot change shipping configuration', $r));
    $r = $a071Run('router', 'editor', 'rma', 'status', ['rma_id' => '999999', 'status' => 'refunded']);
    assert_true($a071Denied($r), $a071Label('A07.1 editor: cannot move an RMA to refunded (credit note)', $r));
    $r = $a071Run('router', 'editor', 'products', '');
    assert_true(!$a071Denied($r) && str_contains($r['out'], 'A071 role item') && !str_contains($r['out'], 'Fatal error'), $a071Label('A07.1 editor: catalogue work is still allowed', $r));

    // Fulfillment: ships orders, but never confirms payments or refunds.
    $r = $a071Run('router', 'fulfillment', 'order', 'payment_transition', ['__get' => ['id' => (string)$a071OrderId], 'new_payment_status' => 'paid', 'amount' => '1500']);
    assert_true($a071Denied($r) && $a071PaymentStatus() === 'pending', $a071Label('A07.1 fulfillment: cannot change payment status', $r));
    $r = $a071Run('router', 'fulfillment', 'awaiting_payment', 'confirm', ['id' => (string)$a071OrderId, 'note' => 'fake statement']);
    assert_true($a071Denied($r) && $a071PaymentStatus() === 'pending', $a071Label('A07.1 fulfillment: cannot confirm an offline payment', $r));
    $r = $a071Run('router', 'fulfillment', 'order', 'refund_request', ['__get' => ['id' => (string)$a071OrderId], 'amount' => '100', 'note' => 'refund']);
    assert_true($a071Denied($r), $a071Label('A07.1 fulfillment: cannot request a refund', $r));
    $r = $a071Run('router', 'fulfillment', 'order', 'status', ['__get' => ['id' => (string)$a071OrderId], 'new_status' => 'refunded']);
    assert_true($a071Denied($r), $a071Label('A07.1 fulfillment: cannot set the order to refunded', $r));
    $r = $a071Run('router', 'fulfillment', 'order', 'status', ['__get' => ['id' => (string)$a071OrderId], 'new_status' => 'processing', 'note' => 'packing']);
    assert_true($a071Handled($r), $a071Label('A07.1 fulfillment: ordinary order workflow is still allowed', $r));
    $r = $a071Run('router', 'fulfillment', 'products', 'export', ['q' => '', 'status' => '']);
    assert_true($a071Denied($r) && !str_contains($r['out'], 'A071-ROLE'), $a071Label('A07.1 finding 18: product CSV export is refused without products.view', $r));
    $r = $a071Run('direct', 'fulfillment', 'order', 'pageOrder', ['__grants' => ['orders.view', 'orders.edit'], '__get' => ['id' => (string)$a071OrderId]]);
    assert_true(str_contains($r['out'], 'A071') && !str_contains($r['out'], 'action=payment_transition') && !str_contains($r['out'], 'action=refund_request') && !str_contains($r['out'], 'value="refunded"'),
        $a071Label('A07.1 fulfillment: order detail hides payment, refund and refunded-status controls', $r));

    // Manager keeps its money permissions; a per-store grant without orders.refund still cannot refund an RMA.
    $r = $a071Run('router', 'manager', 'payments', 'method_save', ['code' => 'a071-manager', 'name' => 'Manager fee', 'provider' => 'manual', 'service_type' => 'offline', 'fixed_fee' => '0', 'active' => '1']);
    assert_true($a071Handled($r) && (int)$a071Read("SELECT COUNT(*) FROM payment_methods WHERE code = 'a071-manager'") === 1, $a071Label('A07.1 manager: payment methods remain manageable', $r));
    $r = $a071Run('router', 'manager', 'order', 'payment_transition', ['__get' => ['id' => (string)$a071OrderId], 'new_payment_status' => 'a071-invalid']);
    assert_true($a071Handled($r) && $a071PaymentStatus() === 'pending', $a071Label('A07.1 manager: payment transitions pass the permission check', $r));
    $r = $a071Run('direct', 'manager', 'rma', 'handleRmaStatus', ['__grants' => ['rma.manage'], 'rma_id' => '999999', 'status' => 'refunded']);
    assert_true($a071Denied($r), $a071Label('A07.1 grants: rma.manage without orders.refund cannot refund an RMA', $r));
    $r = $a071Run('direct', 'manager', 'rma', 'handleRmaStatus', ['__grants' => ['rma.manage'], 'rma_id' => '999999', 'status' => 'approved']);
    assert_true($a071Handled($r), $a071Label('A07.1 grants: rma.manage still handles non-refund RMA steps', $r));

    // CR-M5: the admin credit note covers only units not already exchanged or credited; a second credit note on the
    // same RMA (an exchange fell through) must not collide with the first one's idempotency key; and no credit note
    // may exceed what the order can still refund.
    Cart::clear();
    Cart::add(9711, null, 4);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SESSION['csrf_token'] = 'crm5-checkout';
    $_POST = ['csrf_token' => 'crm5-checkout', 'name' => 'CRM5 Buyer', 'email' => 'crm5@example.com', 'country' => 'SK',
        'street' => 'Credit 1', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'packeta', 'payment_method' => 'bank_transfer'];
    $crm5Checkout = apiCheckout();
    $_POST = [];
    Cart::clear();
    $crm5OrderId = (int)($crm5Checkout['order_id'] ?? 0);
    $pdo->exec("UPDATE orders SET status='paid', payment_status='paid', payment_id='' WHERE id={$crm5OrderId}");
    InvoiceService::issue($crm5OrderId);
    $pdo->exec("UPDATE orders SET status='delivered' WHERE id={$crm5OrderId}");
    $crm5Item = Orders::items($crm5OrderId)[0];
    [, $crm5RmaId] = Rma::create($crm5OrderId, null, 'CRM5 return', [(int)$crm5Item['id'] => 4]);
    Rma::setStatus($crm5RmaId, 'approved');
    $crm5RmaItemId = (int)Rma::items($crm5RmaId)[0]['id'];
    Rma::receive($crm5RmaId, [$crm5RmaItemId => ['quantity' => 4, 'disposition' => 'repair']], 'crm5-receipt');
    Rma::createExchange($crm5RmaId, $crm5RmaItemId, 1, 9711, null, 'crm5-exchange-1');
    Rma::createExchange($crm5RmaId, $crm5RmaItemId, 1, 9711, null, 'crm5-exchange-2');
    // No admin screen cancels an exchange yet; a replacement that fell through is recorded as status 'cancelled'.
    $crm5CancelExchange = static fn(string $key) => $pdo->prepare("UPDATE return_exchanges SET status = 'cancelled' WHERE idempotency_key = ?")->execute([$key]);
    $crm5Credits = static fn(): int => (int)$a071Read("SELECT COUNT(*) FROM order_refunds WHERE rma_id = ? AND credit_note_id IS NOT NULL", [$crm5RmaId]);
    $crm5Credited = static fn(): int => (int)$a071Read("SELECT COALESCE(SUM(cni.quantity), 0) FROM credit_note_items cni JOIN order_refunds r ON r.id = cni.refund_id WHERE r.rma_id = ?", [$crm5RmaId]);
    $crm5Refund = ['__grants' => ['rma.manage', 'orders.refund'], 'rma_id' => (string)$crm5RmaId, 'status' => 'refunded'];
    $r = $a071Run('direct', 'manager', 'rma', 'handleRmaStatus', $crm5Refund);
    assert_true(($r['flash']['type'] ?? '') === 'ok' && $crm5Credits() === 1 && $crm5Credited() === 2,
        $a071Label('CR-M5: the credit note covers the received units that are not being exchanged', $r));
    $crm5CancelExchange('crm5-exchange-1');
    $r = $a071Run('direct', 'manager', 'rma', 'handleRmaStatus', $crm5Refund);
    assert_true(($r['flash']['type'] ?? '') === 'ok' && $crm5Credits() === 2 && $crm5Credited() === 3,
        $a071Label('CR-M5: a second credit note on the same RMA covers the unit whose exchange fell through', $r));
    $r = $a071Run('direct', 'manager', 'rma', 'handleRmaStatus', $crm5Refund);
    assert_true(($r['flash']['type'] ?? '') !== 'ok' && $crm5Credits() === 2, $a071Label('CR-M5: resubmitting with nothing left to credit issues no credit note', $r));
    // The rest of the order is refunded through the gateway ledger, so the last unit has nothing left to refund.
    $crm5Left = (int)Orders::byId($crm5OrderId)['total'] - (int)$a071Read("SELECT COALESCE(SUM(amount), 0) FROM order_refunds WHERE order_id = ?", [$crm5OrderId]);
    $pdo->prepare("INSERT INTO payment_transactions (order_id, provider, status, amount, currency, idempotency_key, store_id) VALUES (?, 'manual', 'refunded', ?, 'eur', 'crm5-ledger-refund', 'default')")
        ->execute([$crm5OrderId, $crm5Left]);
    $crm5CancelExchange('crm5-exchange-2');
    $r = $a071Run('direct', 'manager', 'rma', 'handleRmaStatus', $crm5Refund);
    assert_true(($r['flash']['type'] ?? '') !== 'ok' && $crm5Credits() === 2, $a071Label('CR-M5: a credit note beyond the refundable balance is refused', $r));
} finally {
    $pdo->exec("DELETE FROM payment_methods WHERE code IN ('a071-editor', 'a071-manager')");
    $a071Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a071Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a071Tree as $a071Path) $a071Path->isDir() ? rmdir($a071Path->getPathname()) : unlink($a071Path->getPathname());
    rmdir($a071Root);
}
