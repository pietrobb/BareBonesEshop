<?php

// Review round 15, medium: the ERP API could not create a product, had no incremental sync (updated_after) and its
// orders carried neither lines nor the delivery address, so an ERP could not book or ship an order from the API alone.
echo "\n--- Audit round 15: ERP API (create product, updated_after, order lines and address) ---\n";

Security::setRateDbForTesting(new PDO('sqlite::memory:'));
$r15aIssued = ApiAuth::issueClient('R15 ERP', 'default', ['products:read', 'products:write', 'orders:read', 'customers:read', 'categories:read']);
$r15aClient = ApiAuth::authenticate('Bearer ' . $r15aIssued['token']);
$r15aCall = static function (string $method, string $path, array $query = [], array $body = [], string $key = '') use ($r15aClient): array {
    try {
        return ApiV1::dispatch($method, '/api/v1' . $path, $query, $body, $key === '' ? [] : ['Idempotency-Key' => $key], $r15aClient);
    } catch (Throwable $e) {
        return ApiV1::errorResponse($e);
    }
};
$r15aCategory = (int)$pdo->query("SELECT id FROM categories WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();

// POST /products: a new product with its stock booked in the warehouse ledger, retry-safe.
$r15aNew = ['sku' => 'R15A-NEW', 'name' => 'R15 ERP product', 'price' => 2490, 'stock' => 7, 'weight_g' => 300, 'category_id' => $r15aCategory];
$r15aCreated = $r15aCall('POST', '/products', [], $r15aNew, 'r15a-create-1');
$r15aId = (int)($r15aCreated['body']['data']['id'] ?? 0);
assert_equals([201, 'R15A-NEW', 'draft', 2490, 7, 300], [$r15aCreated['status'], $r15aCreated['body']['data']['sku'] ?? null,
    $r15aCreated['body']['data']['status'] ?? null, $r15aCreated['body']['data']['price']['amount'] ?? null,
    $r15aCreated['body']['data']['stock'] ?? null, $r15aCreated['body']['data']['weight_g'] ?? null],
    'R15 API: POST /products creates a draft product with price, stock and weight');
assert_true(isset($r15aCreated['headers']['ETag']), 'R15 API: the created product carries its ETag');
assert_equals($r15aCategory, (int)(Products::byId($r15aId)['category_id'] ?? 0), 'R15 API: the product lands in the requested category');
assert_equals(7, Warehouse::totalAvailable('R15A-NEW'), 'R15 API: the initial stock is booked in the warehouse ledger');
assert_equals(1, (int)$pdo->query("SELECT COUNT(*) FROM event_outbox WHERE event_type = 'product.updated' AND aggregate_id = '{$r15aId}'")->fetchColumn(),
    'R15 API: subscribers get the new product as product.updated');
$r15aReplay = $r15aCall('POST', '/products', [], $r15aNew, 'r15a-create-1');
assert_equals([201, true, 1], [$r15aReplay['status'], $r15aReplay['body']['meta']['idempotent_replay'] ?? null,
    (int)$pdo->query("SELECT COUNT(*) FROM products WHERE sku = 'R15A-NEW'")->fetchColumn()],
    'R15 API: a retry with the same Idempotency-Key replays the response and creates nothing');
$r15aDuplicate = $r15aCall('POST', '/products', [], ['name' => 'Other'] + $r15aNew, 'r15a-create-2');
assert_equals([409, 'sku_exists', 'R15 ERP product'], [$r15aDuplicate['status'], $r15aDuplicate['body']['error']['code'] ?? null, Products::byId($r15aId)['name'] ?? null],
    'R15 API: POST with a SKU that exists is a 409 and never overwrites the product');
foreach ([
    'price missing' => ['sku' => 'R15A-X1', 'name' => 'X'],
    'unsupported field' => ['sku' => 'R15A-X2', 'name' => 'X', 'price' => 1, 'description' => '<b>x</b>'],
    'string price' => ['sku' => 'R15A-X3', 'name' => 'X', 'price' => '12.90'],
    'bad slug' => ['sku' => 'R15A-X4', 'name' => 'X', 'price' => 1, 'slug' => 'Not A Slug'],
] as $r15aCase => $r15aBody) {
    $r15aBad = $r15aCall('POST', '/products', [], $r15aBody, 'r15a-bad-' . md5($r15aCase));
    assert_equals(400, $r15aBad['status'], "R15 API: POST /products refuses {$r15aCase}");
}
$r15aForeign = $r15aCall('POST', '/products', [], ['sku' => 'R15A-X5', 'name' => 'X', 'price' => 1, 'category_id' => 987654], 'r15a-foreign-cat');
assert_equals([422, false], [$r15aForeign['status'], (bool)Products::bySku('R15A-X5')], 'R15 API: an unknown category_id is a 422 and creates nothing');

// updated_after: an incremental sync sees only what changed since the previous run's server_time.
$pdo->exec("UPDATE products SET updated_at = '2020-01-01 00:00:00' WHERE store_id = 'default'");
$r15aSince = $r15aCall('GET', '/products', ['updated_after' => '2026-01-01T00:00:00Z', 'limit' => 100]);
assert_equals([200, []], [$r15aSince['status'], $r15aSince['body']['data'] ?? null], 'R15 API: nothing changed since updated_after → empty page');
$r15aServerTime = (string)($r15aSince['body']['meta']['server_time'] ?? '');
assert_true(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $r15aServerTime) === 1, 'R15 API: the page reports the database server_time for the next run');
$r15aPatched = $r15aCall('PATCH', "/products/{$r15aId}", [], ['price' => 2590], 'r15a-patch-1');
assert_equals(200, $r15aPatched['status'], 'R15 API fixture: the product changes after the sync');
$r15aNext = $r15aCall('GET', '/products', ['updated_after' => $r15aServerTime, 'limit' => 100]);
assert_equals([$r15aId], array_column($r15aNext['body']['data'] ?? [], 'id'), 'R15 API: the next sync from server_time returns exactly the changed product');
$r15aAfterOld = $r15aCall('GET', '/products', ['updated_after' => '2019-12-31T23:30:00-01:00', 'limit' => 100]);
$r15aBeforeOld = $r15aCall('GET', '/products', ['updated_after' => '2020-01-01T00:30:00+01:00', 'limit' => 100]);
assert_equals([[$r15aId], true], [array_column($r15aAfterOld['body']['data'] ?? [], 'id'), count($r15aBeforeOld['body']['data'] ?? []) > 1],
    'R15 API: an ISO offset is converted to UTC (00:30 UTC is after the old rows, 23:30 UTC the day before is not)');
foreach (['yesterday', '2026-13-01', '2026-02-30T10:00:00Z', ['2026-01-01']] as $r15aInvalid) {
    assert_equals(400, $r15aCall('GET', '/products', ['updated_after' => $r15aInvalid])['status'], 'R15 API: invalid updated_after ' . json_encode($r15aInvalid) . ' is a 400');
}
assert_equals(400, $r15aCall('GET', '/categories', ['updated_after' => '2026-01-01'])['status'], 'R15 API: updated_after on a list without updated_at is a 400, not ignored');
assert_equals(200, $r15aCall('GET', '/customers', ['updated_after' => '2026-01-01'])['status'], 'R15 API: customers take updated_after');

// Orders: lines, contact and delivery address, shipping method and payment method.
$r15aWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (9861, 'default', ?, 'R15A item', 'r15a-item', 'R15A-ITEM', 'simple', 1500, 'eur', 'active', 10, 'deny')")->execute([$r15aCategory]);
$pdo->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', ?, 'R15A-ITEM', 10, 0)")->execute([$r15aWarehouse]);
$r15aOrder = (int)(ManualOrder::create(ManualOrder::parseLines('R15A-ITEM 2')['lines'], ['name' => 'R15 Buyer', 'email' => 'r15a-buyer@example.com',
    'phone' => '+421900333444', 'company' => 'Kniha s.r.o.', 'ico' => '12345678', 'street' => 'Hlavná 1', 'city' => 'Košice', 'zip' => '04001',
    'country' => 'sk', 'shipping_method' => 'packeta', 'payment_method' => 'bank_transfer', 'locale' => 'sk'], false)['order_id'] ?? 0);
assert_true($r15aOrder > 0, 'R15 API fixture: an order with a company buyer');
$r15aDetail = $r15aCall('GET', "/orders/{$r15aOrder}")['body']['data'] ?? [];
assert_equals([['sku' => 'R15A-ITEM', 'name' => 'R15A item', 'quantity' => 2, 'unit_price' => 1500, 'total' => 3000]],
    array_map(static fn(array $item): array => array_intersect_key($item, array_flip(['sku', 'name', 'quantity', 'unit_price', 'total'])), $r15aDetail['items'] ?? []),
    'R15 API: GET /orders/{id} lists the order lines');
assert_equals(['Kniha s.r.o.', 'Hlavná 1', 'Košice', '04001', 'SK', '12345678'], [$r15aDetail['address']['company'] ?? null, $r15aDetail['address']['street'] ?? null,
    $r15aDetail['address']['city'] ?? null, $r15aDetail['address']['zip'] ?? null, $r15aDetail['address']['country'] ?? null, $r15aDetail['address']['ico'] ?? null],
    'R15 API: the order carries the delivery address and company IDs');
assert_equals(['+421900333444', 'packeta', 'bank_transfer'], [$r15aDetail['customer']['phone'] ?? null, $r15aDetail['shipping']['method'] ?? null, $r15aDetail['payment_method'] ?? null],
    'R15 API: the order carries the phone, shipping method and payment method');
$r15aList = $r15aCall('GET', '/orders', ['updated_after' => '2000-01-01', 'limit' => 100])['body']['data'] ?? [];
$r15aListed = array_values(array_filter($r15aList, static fn(array $order): bool => $order['id'] === $r15aOrder))[0] ?? [];
assert_equals([$r15aDetail['items'], $r15aDetail['address']], [$r15aListed['items'] ?? null, $r15aListed['address'] ?? null],
    'R15 API: GET /orders carries the same lines and address as the detail');

// The customer joining a guest order changes customer.id in the API: the order counts as updated for the sync.
$pdo->exec("UPDATE orders SET updated_at = '2020-01-01 00:00:00', customer_id = NULL WHERE id = {$r15aOrder}");
$pdo->prepare("INSERT INTO customers (store_id, email, name, password_hash, status, email_verified_at) VALUES ('default', 'r15a-buyer@example.com', 'R15 Buyer', 'x', 'active', '2026-01-01 00:00:00')")->execute();
$r15aCustomer = (int)$pdo->lastInsertId();
Customers::attachGuestOrders($r15aCustomer);
$r15aSynced = $r15aCall('GET', '/orders', ['updated_after' => '2021-01-01', 'limit' => 100])['body']['data'] ?? [];
assert_true(in_array($r15aOrder, array_column($r15aSynced, 'id'), true), 'R15 API: attaching a guest order to an account bumps its updated_at');

OrderWorkflow::transition($r15aOrder, 'cancelled', 'R15 API cleanup');

// Erasure clears the phone and notes on the order (shown by the API): the next sync must fetch it again.
$pdo->exec("UPDATE orders SET updated_at = '2020-01-01 00:00:00' WHERE id = {$r15aOrder}");
GdprService::anonymizeCustomer($r15aCustomer, 'admin');
$r15aErased = array_values(array_filter($r15aCall('GET', '/orders', ['updated_after' => '2021-01-01', 'limit' => 100])['body']['data'] ?? [],
    static fn(array $order): bool => $order['id'] === $r15aOrder))[0] ?? null;
assert_equals(null, $r15aErased === null ? 'missing' : $r15aErased['customer']['phone'], 'R15 API: erasing the customer bumps the order\'s updated_at; the sync sees the phone gone');
