<?php

// Review round 16 (2026-11-04) "ERP API". Medium: updated_after missed stock changes (a sale left products.updated_at
// as it was); resuming at server_time lost rows of transactions committed later; the line VAT disagreed with the order
// and its invoice under a discount; docs/openapi.yaml misparsed (an unquoted comma in a flow mapping); and v2.1.1 gave
// the phone, delivery address and note of every order to any orders:read key without notice.
echo "\n--- Audit round 16: ERP API (stock in updated_after, sync overlap, line VAT, personal data, OpenAPI lint) ---\n";

Bootstrap::initStoreContext('/', '', 'default');
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
$r16aKey = static function (array $scopes): callable {
    $client = ApiAuth::authenticate('Bearer ' . ApiAuth::issueClient('R16 ERP ' . implode(' ', $scopes), 'default', $scopes)['token']);
    return static function (string $method, string $path, array $query = []) use ($client): array {
        try {
            return ApiV1::dispatch($method, '/api/v1' . $path, $query, [], [], $client);
        } catch (Throwable $e) {
            return ApiV1::errorResponse($e);
        }
    };
};
$r16aErp = $r16aKey(['products:read', 'orders:read', 'customers:read']);
$r16aOrdersOnly = $r16aKey(['orders:read']);
$r16aChanged = static function (callable $call, string $since): array {
    $ids = [];
    $cursor = '';
    do {
        $page = $call('GET', '/products', ['updated_after' => $since, 'limit' => 100] + ($cursor === '' ? [] : ['cursor' => $cursor]))['body'] ?? [];
        $ids = array_merge($ids, array_column($page['data'] ?? [], 'id'));
        $cursor = (string)($page['meta']['next_cursor'] ?? '');
    } while ($cursor !== '');
    return $ids;
};
$r16aWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
$r16aCategory = (int)$pdo->query("SELECT id FROM categories WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();

// Medium: a sale (reservation) and an adjustment change the stock the API shows: updated_after must return the product.
try {
    $pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy, updated_at)
        VALUES (9871, 'default', ?, 'R16A stock', 'r16a-stock', 'R16A-STOCK', 'simple', 1500, 'eur', 'active', 10, 'deny', '2020-01-01 00:00:00')")->execute([$r16aCategory]);
    $pdo->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', ?, 'R16A-STOCK', 10, 0)")->execute([$r16aWarehouse]);
    $pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy, updated_at)
        VALUES (9872, 'default', ?, 'R16A variants', 'r16a-variants', 'R16A-VARP', 'variable', 1500, 'eur', 'active', 0, 'deny', '2020-01-01 00:00:00')")->execute([$r16aCategory]);
    $pdo->prepare("INSERT INTO variants (product_id, sku, name, stock) VALUES (9872, 'R16A-VAR-RED', 'Red', 6)")->execute();
    $pdo->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', ?, 'R16A-VAR-RED', 6, 0)")->execute([$r16aWarehouse]);
    Warehouse::syncProductStock(['R16A-STOCK', 'R16A-VAR-RED']);
    assert_equals('2020-01-01 00:00:00', (string)Products::byId(9871)['updated_at'], 'R16 API: a stock sync that changes nothing keeps updated_at');
    $pdo->exec("UPDATE products SET updated_at = '2020-01-01 00:00:00', stock_updated_at = NULL WHERE id IN (9871, 9872)");

    $r16aSince = $r16aErp('GET', '/products', ['updated_after' => '2021-01-01', 'limit' => 1])['body']['meta'] ?? [];
    $pdo->beginTransaction();
    Inventory::reserveLine(['product_id' => 9871, 'variant_id' => null, 'sku' => 'R16A-STOCK', 'product_type' => 'simple'], 3);
    $pdo->commit();
    $r16aAfterSale = $r16aChanged($r16aErp, '2021-01-01');
    assert_true(in_array(9871, $r16aAfterSale, true), 'R16 API: a sale (stock reserved) makes the product appear in updated_after (was: updated_at unchanged, the ERP never saw the stock drop)');
    assert_equals(7, (int)Products::byId(9871)['stock'], 'R16 API: the product shows the stock left after the sale');
    $pdo->exec("UPDATE products SET updated_at = '2020-01-01 00:00:00', stock_updated_at = NULL WHERE id = 9871");
    Warehouse::adjustStock($r16aWarehouse, 'R16A-STOCK', 5, 'adjustment', 'r16a');
    assert_true(in_array(9871, $r16aChanged($r16aErp, '2021-01-01'), true), 'R16 API: a stock adjustment makes the product appear in updated_after');
    $r16aVariant = (int)$pdo->query("SELECT id FROM variants WHERE sku = 'R16A-VAR-RED'")->fetchColumn();
    $pdo->beginTransaction();
    Inventory::reserveLine(['product_id' => 9872, 'variant_id' => $r16aVariant, 'sku' => 'R16A-VAR-RED', 'product_type' => 'variable'], 2);
    $pdo->commit();
    assert_true(in_array(9872, $r16aChanged($r16aErp, '2021-01-01'), true), 'R16 API: a variant sold makes its product appear in updated_after');

    // Medium: the next run resumes before server_time, so a row committed late by an earlier transaction is still seen.
    $r16aServer = new DateTimeImmutable((string)($r16aSince['server_time'] ?? ''), new DateTimeZone('UTC'));
    $r16aResume = (string)($r16aSince['next_updated_after'] ?? '');
    assert_equals($r16aServer->modify('-' . ApiV1::SYNC_OVERLAP_SECONDS . ' seconds')->format('Y-m-d H:i:s'), $r16aResume,
        'R16 API: meta.next_updated_after is server_time less the sync overlap');
    $pdo->prepare("UPDATE products SET updated_at = ? WHERE id = 9871")->execute([$r16aServer->modify('-60 seconds')->format('Y-m-d H:i:s')]);
    assert_true(in_array(9871, $r16aChanged($r16aErp, $r16aResume), true),
        'R16 API: a change committed after server_time with an earlier updated_at is fetched by the next sync (was: lost)');
} catch (Throwable $e) {
    assert_true(false, 'R16 API stock and overlap (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

// Medium: a discounted order: the lines' VAT adds up to the order's VAT (its financial snapshot, as on the invoice).
$r16aOrders = [];
try {
    $ctx = ['seller_country' => 'SK', 'customer_country' => 'SK', 'tax_provider' => 'vat'];
    $line = static fn(string $sku, string $class, int $price): array => ['product_id' => 9871, 'variant_id' => null, 'sku' => $sku, 'price' => $price, 'qty' => 1,
        'total' => $price, 'available' => true, 'currency_mismatch' => false, 'product_type' => 'simple', 'vat_class' => $class, 'currency' => 'eur',
        'price_formatted' => '', 'total_formatted' => ''];
    VatService::reset();
    $items = Cart::applyVat(['items' => [$line('R16A-STD', 'standard', 2300), $line('R16A-BOOK', 'books', 1050)], 'subtotal' => 3350, 'currency' => 'eur'], $ctx)['items'];
    $snapshot = VatService::buildSnapshot($items, $ctx, ['currency' => 'eur', 'discount' => 670, 'effective_date' => ShopTime::date('Y-m-d')]);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, notes, subtotal, shipping_cost, tax_amount, discount_amount,
            total, currency, status, payment_status, payment_method, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
        VALUES ('R16A-VAT', 'R16A', 'r16a@example.test', '+421900111222', '{\"street\":\"Hlavná 1\",\"city\":\"Košice\",\"country\":\"SK\"}', 'Ring twice',
            3350, 0, ?, 670, ?, 'eur', 'processing', 'paid', 'card', 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
        ->execute([(int)$snapshot['totals']['vat'], (int)$snapshot['totals']['gross'], json_encode($snapshot)]);
    $r16aOrder = $r16aOrders[] = (int)$pdo->lastInsertId();
    foreach ($items as $item) {
        // The stored line VAT is that of the undiscounted line.
        $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, vat_rate, vat_amount) VALUES (?, 9871, ?, ?, 1, ?, ?, ?, ?)')
            ->execute([$r16aOrder, $item['sku'], $item['sku'], $item['price'], $item['total'], $item['vat_rate'] ?? 0, $item['vat_amount'] ?? 0]);
    }
    $r16aDetail = $r16aErp('GET', "/orders/{$r16aOrder}")['body']['data'] ?? [];
    assert_equals((int)$snapshot['totals']['vat'], array_sum(array_column($r16aDetail['items'] ?? [], 'vat_amount')),
        'R16 API: the lines\' vat_amount adds up to the VAT of the discounted order and its invoice (was: the undiscounted line VAT)');
    assert_equals(670, array_sum(array_column($r16aDetail['items'] ?? [], 'discount')), 'R16 API: each line carries its share of the order discount');

    // Medium: personal data only for a key granted customers:read.
    assert_equals(['+421900111222', 'Košice', 'Ring twice'], [$r16aDetail['customer']['phone'] ?? null, $r16aDetail['address']['city'] ?? null, $r16aDetail['notes'] ?? null],
        'R16 API: a key with customers:read gets the phone, address and note');
    $r16aPlain = $r16aOrdersOnly('GET', "/orders/{$r16aOrder}")['body']['data'] ?? [];
    assert_true(($r16aPlain['id'] ?? null) === $r16aOrder && !array_key_exists('phone', $r16aPlain['customer'] ?? []) && !array_key_exists('address', $r16aPlain)
        && !array_key_exists('notes', $r16aPlain) && count($r16aPlain['items'] ?? []) === 2,
        'R16 API: an orders:read key without customers:read gets the order and its lines but no phone, address or note (was: every key got them)');
} catch (Throwable $e) {
    assert_true(false, 'R16 API order (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    foreach ($r16aOrders as $r16aCleanup) {
        $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$r16aCleanup]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$r16aCleanup]);
    }
}

// Medium: the OpenAPI document. A lenient parser read "{ description: a, b }" as a key "b" without value; the CI lints it.
$r16aSpec = str_replace("\r\n", "\n", (string)file_get_contents(dirname(TEST_CLI_DIR) . '/docs/openapi.yaml'));
$r16aUnquoted = [];
foreach (explode("\n", $r16aSpec) as $r16aNo => $r16aLine) {
    if (preg_match('/\{.*\bdescription:\s*+(?![\'"])([^}]*)\}\s*$/', $r16aLine, $r16aM) && str_contains($r16aM[1], ',')) $r16aUnquoted[] = $r16aNo + 1;
}
assert_equals([], $r16aUnquoted, 'R16 API: no flow-mapping description in docs/openapi.yaml holds an unquoted comma');
$r16aCi = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/.github/workflows/ci.yml');
$r16aPackage = json_decode((string)file_get_contents(dirname(TEST_CLI_DIR) . '/package.json'), true) ?: [];
assert_true(str_contains($r16aCi, 'npm run lint:openapi') && ($r16aPackage['scripts']['lint:openapi'] ?? '') === 'node tests/openapi/lint.mjs'
    && is_file(dirname(TEST_CLI_DIR) . '/tests/openapi/lint.mjs') && isset($r16aPackage['devDependencies']['yaml']),
    'R16 API: CI lints docs/openapi.yaml with a strict YAML parser');
assert_true(str_contains($r16aSpec, 'next_updated_after') && str_contains($r16aSpec, 'customers:read (personal data)'),
    'R16 API: the OpenAPI document describes next_updated_after and the personal data scope');
