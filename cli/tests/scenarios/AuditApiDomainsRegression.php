<?php

// A16.2 / usability 10: API v1 covers categories, customers, inventory (read + ledger adjust), order status changes
// through OrderWorkflow and shipments — with the same scope, store isolation, Idempotency-Key and cursor contract.
echo "\n--- Audit A16.2: API v1 domains (categories, customers, inventory, order status, shipments) ---\n";
$a162Root = test_fixture_path('api-domains');
mkdir($a162Root);
file_put_contents($a162Root . '/probe.php', <<<'PHP'
<?php
[, $phase, $driver, $root, $target] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new PDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');
if ($driver === 'sqlite') $db->exec('PRAGMA busy_timeout = 5000');
if ($driver === 'mysql') {
    if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
    if ($phase === 'setup') $db->exec("CREATE DATABASE `{$target}`");
    $db->exec("USE `{$target}`");
}
Db::init($db, $driver);
$base = ['default_locale' => 'en', 'languages' => ['en'], 'currency' => 'eur', 'domains' => [], 'permissions' => ['*'], 'channels' => ['storefront', 'api']];
$stores = ['default_store' => 'default', 'stores' => [
    'default' => ['name' => 'default', 'url_prefix' => '', 'catalog_scope' => 'default'] + $base,
    'other' => ['name' => 'other', 'url_prefix' => '/other', 'catalog_scope' => 'other'] + $base,
]];
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores);
StoreContext::activate(StoreContext::resolve($stores, '/', '', null, 'api'));
JobQueue::setDir(__DIR__ . '/jobs');
$state = __DIR__ . '/state-' . $driver . '.json';
$one = static function(string $sql, array $params = []) use ($db): mixed {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
};

if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
    $full = ApiAuth::issueClient('A16.2', 'default', ['categories:read', 'customers:read', 'inventory:read', 'inventory:write', 'orders:read', 'orders:write']);
    $narrow = ApiAuth::issueClient('A16.2 narrow', 'default', ['products:read']);
    $ids = [];
    $category = $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $category->execute(['default', 'a162-root', 'A162 Root', null, 'a162-root', 0, 1]);
    $ids['cat_root'] = (int)$db->lastInsertId();
    $category->execute(['default', 'a162-child', 'A162 Child', $ids['cat_root'], 'a162-root/a162-child', 1, 2]);
    $ids['cat_child'] = (int)$db->lastInsertId();
    $category->execute(['other', 'a162-foreign', 'A162 Foreign', null, 'a162-foreign', 0, 1]);
    $ids['cat_foreign'] = (int)$db->lastInsertId();

    $customer = $db->prepare("INSERT INTO customers (store_id, email, password_hash, name, status) VALUES (?, ?, 'a162-secret-hash', ?, 'active')");
    foreach (['c1' => ['default', 'a162-one@example.test'], 'c2' => ['default', 'a162-two@example.test'], 'c_foreign' => ['other', 'a162-foreign@example.test']] as $key => [$store, $email]) {
        $customer->execute([$store, $email, 'A162 ' . $key]);
        $ids[$key] = (int)$db->lastInsertId();
    }

    $product = $db->prepare("INSERT INTO products (sku, name, slug, category_id, price, stock, status, store_id) VALUES (?, ?, ?, ?, 1000, 0, 'active', ?)");
    $product->execute(['A162-SKU', 'A162 product', 'a162-product', $ids['cat_root'], 'default']);
    $ids['product'] = (int)$db->lastInsertId();
    $product->execute(['A162-FOREIGN', 'A162 foreign product', 'a162-foreign-product', $ids['cat_foreign'], 'other']);
    $warehouse = Warehouse::save(['code' => 'a162-main', 'name' => 'A162 main', 'priority' => 10, 'is_default' => 1]);
    Warehouse::setStock($warehouse, 'A162-SKU', 5);
    $db->exec("UPDATE warehouse_stock SET reserved = 2 WHERE sku = 'A162-SKU'");
    Warehouse::syncProductStock(['A162-SKU']);

    $order = $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, store_id) VALUES (?, ?, ?, 'A162', 'a162-one@example.test', 'card', 1000, 1000, 'eur', ?)");
    foreach (['o_paid_pending' => ['pending', 'paid', 'default'], 'o_unpaid' => ['pending', 'pending', 'default'], 'o_paid' => ['paid', 'paid', 'default'], 'o_foreign' => ['pending', 'paid', 'other']] as $key => [$status, $payment, $store]) {
        $order->execute(['A162-' . strtoupper($key), $status, $payment, $store]);
        $ids[$key] = (int)$db->lastInsertId();
    }
    $item = $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'A162-SKU', 'A162 product', 2, 500, 1000)");
    $shipment = $db->prepare("INSERT INTO shipments (store_id, order_id, warehouse_id, status, carrier, tracking_number, idempotency_key) VALUES (?, ?, ?, 'shipped', 'packeta', ?, ?)");
    $shipmentItem = $db->prepare("INSERT INTO shipment_items (shipment_id, order_item_id, sku, quantity, status) VALUES (?, ?, 'A162-SKU', 2, 'shipped')");
    foreach (['o_paid' => 'default', 'o_foreign' => 'other'] as $key => $store) {
        $item->execute([$ids[$key], $ids['product']]);
        $orderItem = (int)$db->lastInsertId();
        $shipment->execute([$store, $ids[$key], $warehouse, 'TRK-' . $key, 'a162-ship-' . $key]);
        $shipmentItem->execute([(int)$db->lastInsertId(), $orderItem]);
    }
    file_put_contents($state, json_encode(['full' => $full['token'], 'narrow' => $narrow['token'], 'ids' => $ids], JSON_THROW_ON_ERROR));
    echo json_encode(['ok' => true]);
    exit(0);
}

$fx = json_decode(file_get_contents($state), true, 512, JSON_THROW_ON_ERROR);
$full = ApiAuth::authenticate('Bearer ' . $fx['full']);
$narrow = ApiAuth::authenticate('Bearer ' . $fx['narrow']);
$ids = $fx['ids'];
// The production error mapping of api/v1/index.php.
$call = static function(string $method, string $path, array $query = [], array $body = [], string $key = '', ?array $client = null) use ($full): array {
    $headers = $key === '' ? [] : ['Idempotency-Key' => $key];
    try {
        $result = ApiV1::dispatch($method, '/api/v1' . $path, $query, $body, $headers, $client ?? $full);
    } catch (Throwable $e) {
        $result = method_exists(ApiV1::class, 'errorResponse')
            ? ApiV1::errorResponse($e)
            : ['status' => 500, 'body' => ['error' => ['code' => get_class($e), 'message' => $e->getMessage()]]];
    }
    return [$result['status'], $result['body']];
};
$code = static fn(array $response): string => (string)($response[1]['error']['code'] ?? '');
$jobs = static function(): int {
    if (!is_dir(__DIR__ . '/jobs')) return 0;
    $n = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/jobs', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getFilename(), '.json')) $n++;
    }
    return $n;
};
$out = [];

// Categories: catalog scoped, cursor paginated, parent/path/depth.
$page1 = $call('GET', '/categories', ['limit' => '1']);
$page2 = $call('GET', '/categories', ['limit' => '1', 'cursor' => (string)($page1[1]['meta']['next_cursor'] ?? '')]);
$all = $call('GET', '/categories', ['limit' => '100']);
$out['categories'] = [
    $page1[0], $page1[1]['data'][0]['slug'] ?? null, is_string($page1[1]['meta']['next_cursor'] ?? null),
    $page2[0], $page2[1]['data'][0]['slug'] ?? null, $page2[1]['data'][0]['parent_id'] ?? 'missing', $page2[1]['data'][0]['depth'] ?? null, $page2[1]['data'][0]['path'] ?? null,
    array_column($all[1]['data'] ?? [], 'slug'),
];
$out['category_one'] = [$call('GET', '/categories/' . $ids['cat_child'])[1]['data']['name'] ?? null, $call('GET', '/categories/' . $ids['cat_foreign'])[0]];
$out['category_scope'] = $call('GET', '/categories', [], [], '', $narrow)[0];

// Customers: own store only, no secrets.
$customers = $call('GET', '/customers', ['limit' => '100']);
$customerJson = json_encode($customers[1]);
$out['customers'] = [$customers[0], array_column($customers[1]['data'] ?? [], 'email'),
    str_contains((string)$customerJson, 'a162-secret-hash') || str_contains((string)$customerJson, 'password'),
    array_keys($customers[1]['data'][0] ?? [])];
$out['customer_one'] = [$call('GET', '/customers/' . $ids['c1'])[1]['data']['email'] ?? null, $call('GET', '/customers/' . $ids['c_foreign'])[0],
    $call('GET', '/customers', [], [], '', $narrow)[0]];

// Inventory read: per warehouse on hand / reserved / available.
$stock = $call('GET', '/inventory', ['sku' => 'A162-SKU']);
$out['inventory'] = [$stock[0], $stock[1]['data']['available'] ?? null,
    array_values(array_filter($stock[1]['data']['warehouses'] ?? [], static fn(array $w): bool => ($w['code'] ?? '') === 'a162-main'))[0] ?? null,
    $call('GET', '/inventory', ['sku' => 'A162-FOREIGN'])[0], $call('GET', '/inventory')[0], $call('GET', '/inventory', ['sku' => 'A162-SKU'], [], '', $narrow)[0]];

// Inventory adjust: ledger movement, idempotent, reservations guard is not stored under the key.
$movements = static fn(): int => (int)$one("SELECT COUNT(*) FROM stock_movements WHERE sku = 'A162-SKU' AND reference_type = 'api_v1'");
$adjust = ['sku' => 'A162-SKU', 'delta' => 4, 'reason' => 'cycle count'];
$first = $call('POST', '/inventory/adjustments', [], $adjust, 'a162-adjust-0001');
$replay = $call('POST', '/inventory/adjustments', [], $adjust, 'a162-adjust-0001');
$out['adjust'] = [$first[0], $first[1]['data']['available'] ?? null, $first[1]['meta']['idempotent_replay'] ?? null,
    $replay[0], $replay[1]['meta']['idempotent_replay'] ?? null, $movements(),
    (int)$one("SELECT qty FROM warehouse_stock WHERE sku = 'A162-SKU'"), (int)$one("SELECT stock FROM products WHERE sku = 'A162-SKU'")];
$out['adjust_conflict'] = [
    $code($call('POST', '/inventory/adjustments', [], ['sku' => 'A162-SKU', 'delta' => 5], 'a162-adjust-0001')),
    $code($call('POST', '/inventory/adjustments', [], ['sku' => 'A162-SKU', 'delta' => -10], 'a162-adjust-0002')),
    $call('POST', '/inventory/adjustments', [], ['sku' => 'A162-SKU', 'delta' => -1], 'a162-adjust-0002')[0],
    $movements(),
];
$out['adjust_invalid'] = [
    $call('POST', '/inventory/adjustments', [], ['sku' => 'A162-SKU', 'delta' => 1])[0],
    $call('POST', '/inventory/adjustments', [], ['sku' => 'A162-SKU', 'delta' => 0], 'a162-adjust-0003')[0],
    $call('POST', '/inventory/adjustments', [], ['sku' => 'A162-SKU', 'delta' => '2'], 'a162-adjust-0004')[0],
    $call('POST', '/inventory/adjustments', [], ['sku' => 'A162-SKU', 'delta' => 1, 'extra' => 1], 'a162-adjust-0005')[0],
    $call('POST', '/inventory/adjustments', [], ['sku' => 'A162-FOREIGN', 'delta' => 1], 'a162-adjust-0006')[0],
    $call('POST', '/inventory/adjustments', [], ['sku' => 'A162-SKU', 'delta' => 1], 'a162-adjust-0007', $narrow)[0],
];

// Order status through OrderWorkflow: side effects once, jobs only after commit.
$statusOf = static fn(int $id): string => (string)$one('SELECT status FROM orders WHERE id = ?', [$id]);
$jobsBefore = $jobs();
$paid = $call('POST', '/orders/' . $ids['o_paid_pending'] . '/status', [], ['status' => 'paid', 'note' => 'ERP sync'], 'a162-status-0001');
$jobsAfter = $jobs();
$paidReplay = $call('POST', '/orders/' . $ids['o_paid_pending'] . '/status', [], ['status' => 'paid', 'note' => 'ERP sync'], 'a162-status-0001');
$out['status_paid'] = [$paid[0], $paid[1]['data']['status'] ?? null, $paid[1]['meta']['idempotent_replay'] ?? null, $statusOf($ids['o_paid_pending']),
    $jobsAfter > $jobsBefore, $paidReplay[0], $paidReplay[1]['meta']['idempotent_replay'] ?? null, $jobs() === $jobsAfter,
    (int)$one("SELECT COUNT(*) FROM event_outbox WHERE event_type = 'order.status_changed' AND aggregate_id = ?", [(string)$ids['o_paid_pending']]),
    (int)$one("SELECT COUNT(*) FROM audit_log WHERE entity = 'order_status' AND entity_id = ?", [(string)$ids['o_paid_pending']])];
$unpaid = $call('POST', '/orders/' . $ids['o_unpaid'] . '/status', [], ['status' => 'paid'], 'a162-status-0002');
$invalid = $call('POST', '/orders/' . $ids['o_paid'] . '/status', [], ['status' => 'delivered'], 'a162-status-0003');
$out['status_rejected'] = [$unpaid[0], $code($unpaid), $statusOf($ids['o_unpaid']), $invalid[0], $code($invalid), $invalid[1]['error']['allowed'] ?? null, $statusOf($ids['o_paid'])];
$out['status_invalid'] = [
    $call('POST', '/orders/' . $ids['o_paid'] . '/status', [], ['status' => 'bogus'], 'a162-status-0004')[0],
    $call('POST', '/orders/' . $ids['o_paid'] . '/status', [], ['status' => 'processing'])[0],
    $call('POST', '/orders/' . $ids['o_paid'] . '/status', [], ['status' => 'refunded'], 'a162-status-0005')[0],
    $call('POST', '/orders/' . $ids['o_foreign'] . '/status', [], ['status' => 'paid'], 'a162-status-0006')[0],
    $statusOf($ids['o_foreign']),
    $call('POST', '/orders/' . $ids['o_paid'] . '/status', [], ['status' => 'processing'], 'a162-status-0007', $narrow)[0],
    $statusOf($ids['o_paid']),
];

// Shipments with tracking, own store only.
$shipments = $call('GET', '/orders/' . $ids['o_paid'] . '/shipments');
$first = $shipments[1]['data'][0] ?? [];
$out['shipments'] = [$shipments[0], count($shipments[1]['data'] ?? []), $first['carrier'] ?? null, $first['tracking_number'] ?? null, $first['status'] ?? null,
    array_map(static fn(array $i): array => [$i['sku'] ?? null, $i['quantity'] ?? null], $first['items'] ?? []),
    $call('GET', '/orders/' . $ids['o_foreign'] . '/shipments')[0]];
$out['unknown'] = $call('GET', '/nope')[0];
echo json_encode($out);
PHP);
$a162Php = static function(array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out, $err];
};
$a162Drivers = ['sqlite' => $a162Root . '/api.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a162Drivers['mysql'] = 'bbe_a162_api_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A16.2 native API domains (set TEST_MYSQL_DSN)\n";
try {
    foreach ($a162Drivers as $a162Driver => $a162Target) {
        $a162Tag = "A16.2 {$a162Driver}";
        $a162Run = static fn(string $phase): array => $a162Php([$a162Root . '/probe.php', $phase, $a162Driver, dirname(TEST_CLI_DIR), $a162Target]);
        [$a162Code, $a162Out, $a162Err] = $a162Run('setup');
        assert_equals([0, ['ok' => true]], [$a162Code, json_decode($a162Out, true) ?? $a162Out . $a162Err], "{$a162Tag}: fixture migrated (two stores, categories, customers, stock, orders, shipments)");
        [$a162Code, $a162Out, $a162Err] = $a162Run('run');
        $a162 = json_decode($a162Out, true);
        assert_true($a162Code === 0 && is_array($a162), "{$a162Tag}: API probe runs (" . substr($a162Out . $a162Err, 0, 400) . ')');
        $a162 = is_array($a162) ? $a162 : [];

        $a162Cat = $a162['categories'] ?? [];
        assert_equals([200, 'a162-root', true], array_slice($a162Cat, 0, 3), "{$a162Tag}: GET /categories pages by cursor");
        assert_equals([200, 'a162-child', null, 1, 'a162-root/a162-child'], [$a162Cat[3] ?? null, $a162Cat[4] ?? null, null, $a162Cat[6] ?? null, $a162Cat[7] ?? null], "{$a162Tag}: second page is the child with depth and path");
        assert_true(is_int($a162Cat[5] ?? null), "{$a162Tag}: child category carries parent_id");
        assert_equals(['a162-root', 'a162-child'], $a162Cat[8] ?? null, "{$a162Tag}: categories of another catalog are not listed");
        assert_equals(['A162 Child', 404], $a162['category_one'] ?? null, "{$a162Tag}: GET /categories/{id} — own 200, other catalog 404");
        assert_equals(403, $a162['category_scope'] ?? null, "{$a162Tag}: categories need categories:read");

        assert_equals([200, ['a162-one@example.test', 'a162-two@example.test'], false], array_slice($a162['customers'] ?? [], 0, 3), "{$a162Tag}: GET /customers lists own store only, without password hash");
        assert_equals([], array_values(array_intersect($a162['customers'][3] ?? ['missing'], ['password_hash', 'remember_token', 'reset_token', 'verification_token'])), "{$a162Tag}: customer DTO has no credential fields");
        assert_equals(['a162-one@example.test', 404, 403], $a162['customer_one'] ?? null, "{$a162Tag}: GET /customers/{id} — own 200, other store 404, missing scope 403");

        assert_equals([200, 3, ['code' => 'a162-main', 'name' => 'A162 main', 'qty' => 5, 'reserved' => 2, 'available' => 3]], array_slice($a162['inventory'] ?? [], 0, 3), "{$a162Tag}: GET /inventory?sku= shows per warehouse on hand, reserved, available");
        assert_equals([404, 400, 403], array_slice($a162['inventory'] ?? [], 3), "{$a162Tag}: inventory — other catalog SKU 404, missing sku 400, missing scope 403");
        assert_equals([200, 7, false, 200, true, 1, 9, 7], $a162['adjust'] ?? null, "{$a162Tag}: POST /inventory/adjustments books one ledger movement; identical retry replays");
        assert_equals(['idempotency_conflict', 'stock_conflict', 200, 2], $a162['adjust_conflict'] ?? null, "{$a162Tag}: reused key with other body 409; reservation conflict 409 is not stored, so the key can be retried");
        assert_equals([400, 400, 400, 400, 404, 403], $a162['adjust_invalid'] ?? null, "{$a162Tag}: adjust needs Idempotency-Key, non-zero integer delta, known fields, own SKU, inventory:write");

        assert_equals([200, 'paid', false, 'paid', true, 200, true, true, 1, 1], $a162['status_paid'] ?? null, "{$a162Tag}: POST /orders/{id}/status runs OrderWorkflow once (event, audit, jobs after commit); retry replays");
        assert_equals([422, 'payment_not_confirmed', 'pending', 422, 'invalid_transition', ['processing', 'shipped', 'cancelled', 'refunded'], 'paid'], $a162['status_rejected'] ?? null, "{$a162Tag}: unconfirmed payment and invalid transition are 422 and change nothing");
        assert_equals([400, 400, 403, 404, 'pending', 403, 'paid'], $a162['status_invalid'] ?? null, "{$a162Tag}: unknown status 400, missing key 400, refund needs refunds:write, other store 404, missing scope 403");

        assert_equals([200, 1, 'packeta', 'TRK-o_paid', 'shipped', [['A162-SKU', 2]], 404], $a162['shipments'] ?? null, "{$a162Tag}: GET /orders/{id}/shipments returns tracking and items; other store 404");
        assert_equals(404, $a162['unknown'] ?? null, "{$a162Tag}: unknown route stays 404");
    }
    $a162OpenApi = str_replace("\r\n", "\n", (string)file_get_contents(dirname(TEST_CLI_DIR) . '/docs/openapi.yaml'));
    $a162Documented = array_filter(['/orders/{id}/status:', '/orders/{id}/shipments:', '/categories:', '/categories/{id}:', '/customers:', '/customers/{id}:', '/inventory:', '/inventory/adjustments:'],
        static fn(string $route): bool => str_contains($a162OpenApi, "\n  {$route}\n"));
    assert_equals(8, count($a162Documented), 'A16.2: docs/openapi.yaml documents every new API v1 route');
} finally {
    if (isset($a162Drivers['mysql'])) $a162Php([$a162Root . '/probe.php', 'teardown', 'mysql', dirname(TEST_CLI_DIR), $a162Drivers['mysql']]);
    if (is_dir($a162Root)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a162Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $a162File) {
            $a162File->isDir() ? rmdir($a162File->getPathname()) : unlink($a162File->getPathname());
        }
        rmdir($a162Root);
    }
}
