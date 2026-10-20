<?php

// Round 7 review (2026-10-20), hotfix. Deleting a warehouse destroyed the reservations open orders held
// in it; the expiry sweep then threw on the first such order, with no per-order or per-store catch, so
// no later pending order in any store expired again (their stock stayed locked).
echo "\n--- Audit round 7 (hotfix): warehouse deletion with reservations, expiry sweep isolation ---\n";
(require TEST_CLI_DIR . '/../migrations/20261012090000_operational_incidents.php')->up($pdo, true);
// Earlier scenarios drop `feeds` in the shared suite DB; Warehouse::delete() cascades into it.
try { $pdo->query('SELECT 1 FROM feeds LIMIT 1'); } catch (PDOException) { $pdo->exec('CREATE TABLE feeds (id INTEGER PRIMARY KEY, store_id VARCHAR(40), warehouse_id INTEGER)'); }

$r7hProduct = $pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (?, 'default', 1, ?, ?, ?, 'simple', 1000, 'eur', 'active', 0, 'deny')");
$r7hProduct->execute([97101, 'R7H held', 'r7h-held', 'R7H-HELD']);
$r7hProduct->execute([97102, 'R7H next', 'r7h-next', 'R7H-NEXT']);
$pdo->exec("INSERT INTO warehouses (store_id, code, name, priority, is_default) VALUES ('default', 'r7h-wh', 'R7H', 1, 0)");
$r7hWh = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', ?, 'R7H-HELD', 5, 2), ('default', ?, 'R7H-NEXT', 5, 1)")
    ->execute([$r7hWh, $r7hWh]);
$r7hOrder = static function (string $sku, int $productId, int $qty) use ($pdo, $r7hWh): int {
    $snapshot = json_encode(['provider' => 'bank_transfer', 'service_type' => 'bank_transfer', 'requires_online' => false]);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_method_snapshot, store_id, created_at)
        VALUES (?, 'R7H', '', '', '{}', 1000, 0, 0, 0, 1000, 'eur', 'pending', 'pending', 'bank_transfer', ?, 'default', ?)")
        ->execute(['R7H-' . bin2hex(random_bytes(4)), $snapshot, gmdate('Y-m-d H:i:s', time() - 20 * 86400)]);
    $id = (int)$pdo->lastInsertId();
    $attrs = json_encode(['_inventory_allocations' => [['warehouse_id' => $r7hWh, 'warehouse_code' => 'r7h-wh', 'qty' => $qty, 'status' => 'reserved']]]);
    $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, attrs, reserved_qty) VALUES (?, ?, ?, ?, ?, 1000, ?, ?, ?)')
        ->execute([$id, $productId, $sku, $sku, $qty, 1000 * $qty, $attrs, $qty]);
    return $id;
};
$r7hHeld = $r7hOrder('R7H-HELD', 97101, 2);
$r7hNext = $r7hOrder('R7H-NEXT', 97102, 1);

// The warehouse cannot be deleted while open orders hold units in it.
$r7hRefused = '';
try { Warehouse::delete($r7hWh); } catch (RuntimeException $e) { $r7hRefused = $e->getMessage(); }
assert_true(str_contains($r7hRefused, '3 reserved'), 'R7 warehouse: deleting a warehouse whose stock open orders reserve is refused (' . $r7hRefused . ')');
assert_equals(2, (int)$pdo->query("SELECT COUNT(*) FROM warehouse_stock WHERE warehouse_id = {$r7hWh}")->fetchColumn(), 'R7 warehouse: the refused delete keeps its stock rows');
$r7hAdmin = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Operations.php');
assert_true(str_contains($r7hAdmin, "Warehouse::reservedUnits(\$id)") && str_contains($r7hAdmin, 'admin.warehouse.delete_reserved'), 'R7 warehouse: the admin delete explains why it refuses');

// A broken reservation (e.g. from a delete before this fix) fails only its own order.
$pdo->exec("UPDATE warehouse_stock SET reserved = 0 WHERE warehouse_id = {$r7hWh} AND sku = 'R7H-HELD'");
$r7hBefore = (int)$pdo->query("SELECT COUNT(*) FROM operational_incidents WHERE code = 'order.expiry_failed'")->fetchColumn();
$r7hError = '';
try {
    PendingOrderExpiry::sweep(['pending_order_expiry_seconds' => 172800, 'pending_order_expiry_batch' => 25], null);
} catch (Throwable $e) {
    $r7hError = $e->getMessage();
}
assert_equals('', $r7hError, 'R7 expiry: a broken reservation does not abort the sweep');
assert_equals(['pending', 'cancelled'], [(string)Orders::byId($r7hHeld)['status'], (string)Orders::byId($r7hNext)['status']],
    'R7 expiry: the order with a broken reservation stays pending, the next order still expires');
assert_equals(0, (int)$pdo->query("SELECT reserved FROM warehouse_stock WHERE warehouse_id = {$r7hWh} AND sku = 'R7H-NEXT'")->fetchColumn(), 'R7 expiry: the next order releases its reservation');
assert_equals($r7hBefore + 1, (int)$pdo->query("SELECT COUNT(*) FROM operational_incidents WHERE code = 'order.expiry_failed'")->fetchColumn(), 'R7 expiry: the failing order raises one critical incident');
$r7hSweep = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/PendingOrderExpiry.php');
assert_true(preg_match('/foreach \(\$storeIds as \$storeId\) \{\s*try \{/', $r7hSweep) === 1, 'R7 expiry: each store is swept inside its own try, so one store cannot stop the others');

// Without reservations the warehouse can be deleted, and its stock leaves the product totals.
$pdo->exec("UPDATE orders SET status = 'cancelled' WHERE id = {$r7hHeld}");
Warehouse::delete($r7hWh);
assert_equals([0, 0], [(int)$pdo->query("SELECT COUNT(*) FROM warehouses WHERE id = {$r7hWh}")->fetchColumn(), (int)$pdo->query("SELECT stock FROM products WHERE id = 97102")->fetchColumn()],
    'R7 warehouse: an unreserved warehouse is deleted and its units no longer count as product stock');

// --- Price lists of a shared provider scope are in the scope owner's currency ------------------
// The shipped stores.json lets the CZK/PLN/HUF/USD stores share the EUR store's provider scope: they
// charged its shipping rates, COD surcharges and payment fees as their own currency (4,99 Kč shipping).
$r7hStores = Bootstrap::stores();
$r7hPrevious = StoreContext::current();
$r7hStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r7hTestStores = $r7hStores;
$r7hTestStores['stores']['r7czk'] = array_replace($r7hStores['stores']['default'], ['id' => 'r7czk', 'url_prefix' => '/r7czk', 'domains' => [],
    'catalog_scope' => 'default', 'provider_scope' => 'default', 'currency' => 'czk', 'channels' => ['storefront']]);
$r7hStoresProp->setValue(null, $r7hTestStores);
$pdo->exec("INSERT INTO payment_methods (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order)
    VALUES ('default', 'r7fee', 'manual', 'R7 fee', '', 'offline', 0, 0, 150, 0, NULL, 0, 1, 1), ('default', 'r7free', 'manual', 'R7 free', '', 'offline', 0, 0, 0, 100, NULL, 0, 1, 2)");
$pdo->exec("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES ('default', 'r7fee', 'SK'), ('default', 'r7free', 'SK')");
$r7hQuote = static function (string $storeId): array {
    StoreContext::activate(StoreContext::resolve(Bootstrap::stores(), null, null, $storeId, 'storefront'));
    $shipping = array_map(static fn(array $r): array => [(string)$r['method_code'], (int)$r['price']], ShippingService::calculateRates('SK', 1000, 2000, true));
    $payment = array_values(array_intersect(['r7fee', 'r7free'], array_column(PaymentService::availableMethods('SK', StoreContext::current()->currency(), 2000), 'code')));
    return [$shipping, $payment];
};
try {
    [$r7hEurShipping, $r7hEurPayment] = $r7hQuote('default');
    [$r7hCzkShipping, $r7hCzkPayment] = $r7hQuote('r7czk');
} finally {
    $r7hStoresProp->setValue(null, $r7hStores);
    StoreContext::activate($r7hPrevious);
}
assert_true(array_filter($r7hEurShipping, static fn(array $r): bool => $r[1] > 0) !== [], 'R7 currency: the EUR store owning the scope is quoted its paid rates (' . json_encode($r7hEurShipping) . ')');
assert_equals([], array_values(array_filter($r7hCzkShipping, static fn(array $r): bool => $r[1] > 0)),
    'R7 currency: a CZK store sharing the EUR scope is not charged its EUR shipping rates or COD surcharges as koruny (' . json_encode($r7hCzkShipping) . ')');
assert_equals([['r7fee', 'r7free'], ['r7free']], [$r7hEurPayment, $r7hCzkPayment],
    'R7 currency: a payment method with an EUR fee is not offered in the CZK store; one without amounts still is');

// --- A credit note paid back by hand is refunded money --------------------------------------------
// An order without a payment id (bank transfer) has its credit notes settled off-gateway ('manual-admin'):
// no payment transaction records them, so the whole order could be refunded a second time.
$pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, store_id)
    VALUES (?, 'R7H', '', '', '{}', 1000, 0, 0, 0, 1000, 'eur', 'delivered', 'paid', 'bank_transfer', '', 'default')")->execute(['R7H-CN-' . bin2hex(random_bytes(4))]);
$r7hCredited = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_refunds (store_id, order_id, rma_id, credit_note_id, amount, currency, status, idempotency_key) VALUES ('default', ?, 0, 0, 400, 'eur', 'pending', ?)")
    ->execute([$r7hCredited, 'r7h-credit-' . $r7hCredited]);
Rma::completeRefund((int)$pdo->lastInsertId(), 'manual-admin');
assert_equals(600, PaymentService::refundableBalance($r7hCredited), 'R7 refund: a credit note settled outside the gateway lowers the refundable balance');
$r7hSecond = '';
try { PaymentService::completeManualRefund($r7hCredited, 1000, 'r7h-admin', 'Full refund'); } catch (LogicException $e) { $r7hSecond = $e->getMessage(); }
assert_true($r7hSecond !== '', 'R7 refund: the credited part cannot be refunded a second time');
PaymentService::completeManualRefund($r7hCredited, 600, 'r7h-admin', 'Rest refunded by transfer');
assert_equals(['refunded', 0], [(string)Orders::byId($r7hCredited)['payment_status'], PaymentService::refundableBalance($r7hCredited)],
    'R7 refund: refunding the rest by hand completes the refund');

// --- SQLite: transactions take the write lock when they begin; /readyz tells busy from read-only ----
// A deferred read-then-write transaction failed at once with "database is locked" when another connection
// wrote meanwhile (busy_timeout cannot help a stale snapshot): 8–16 % of checkouts at 8 clients.
$r7hFile = sys_get_temp_dir() . '/barebones-r7h-' . bin2hex(random_bytes(4)) . '.sqlite';
$r7hOpts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
$r7hA = new ShopPDO('sqlite:' . $r7hFile, null, null, $r7hOpts);
$r7hA->exec('PRAGMA journal_mode = WAL');
$r7hA->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY)');
$r7hA->exec('PRAGMA busy_timeout = 5000');
$r7hB = new ShopPDO('sqlite:' . $r7hFile, null, null, $r7hOpts);
$r7hB->exec('PRAGMA busy_timeout = 100');
$r7hB->beginTransaction();
$r7hB->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$r7hLocked = false;
try { $r7hA->exec('BEGIN IMMEDIATE'); $r7hA->exec('ROLLBACK'); } catch (PDOException) { $r7hLocked = true; }
$r7hA->exec('PRAGMA busy_timeout = 5000');
assert_true($r7hLocked, 'R7 sqlite: ShopPDO::beginTransaction() takes the write lock at once (BEGIN IMMEDIATE), so its later write cannot fail on a stale snapshot');
$r7hStarted = microtime(true);
$r7hWritable = Db::writable($r7hA);
assert_true($r7hWritable && microtime(true) - $r7hStarted < 1.0, 'R7 readyz: a database another connection is writing is reported writable, without waiting for the writer');
assert_equals(5000, (int)$r7hA->query('PRAGMA busy_timeout')->fetchColumn(), 'R7 readyz: the probe restores the connection busy_timeout');
$r7hB->commit();
assert_true(str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Db.php'), 'QueryCountingPDO::class : ShopPDO::class'), 'R7 sqlite: the shop connection is a ShopPDO');
$r7hReadOnly = new PDO('sqlite:' . $r7hFile, null, null, $r7hOpts + (defined('Pdo\Sqlite::ATTR_OPEN_FLAGS')
    ? [constant('Pdo\Sqlite::ATTR_OPEN_FLAGS') => constant('Pdo\Sqlite::OPEN_READONLY')]
    : [constant('PDO::SQLITE_ATTR_OPEN_FLAGS') => constant('PDO::SQLITE_OPEN_READONLY')]));
assert_true(!Db::writable($r7hReadOnly), 'R7 readyz: a read-only database is still reported read-only');
$r7hA = $r7hB = $r7hReadOnly = null;
foreach ([$r7hFile, $r7hFile . '-wal', $r7hFile . '-shm'] as $r7hPath) if (is_file($r7hPath)) @unlink($r7hPath);

// --- Packeta: a postal code with a hyphen (PL) is a postal code, not search text --------------------
$r7hPacketaDir = sys_get_temp_dir() . '/barebones-r7h-packeta-' . bin2hex(random_bytes(4));
mkdir($r7hPacketaDir . '/cache', 0777, true);
try {
    $r7hPacketa = new PacketaShipping(['private_cache_dir' => $r7hPacketaDir . '/cache']);
    $r7hPacketa->storePickupPoints('PL', [
        ['id' => '7001', 'country' => 'pl', 'status' => ['statusId' => '1'], 'displayFrontend' => '1', 'name' => 'Paczkomat Warszawa Centrum', 'street' => 'Marszałkowska 1', 'city' => 'Warszawa', 'zip' => '00-950'],
        ['id' => '7002', 'country' => 'pl', 'status' => ['statusId' => '1'], 'displayFrontend' => '1', 'name' => 'Paczkomat Kraków', 'street' => 'Floriańska 2', 'city' => 'Kraków', 'zip' => '31-019'],
        ['id' => '7003', 'country' => 'pl', 'status' => ['statusId' => '1'], 'displayFrontend' => '1', 'name' => 'Punkt Bielsko-Biała', 'street' => 'Cechowa 3', 'city' => 'Bielsko-Biała', 'zip' => '43-300'],
    ]);
    assert_equals(['7001', '7002', '7003'], array_column($r7hPacketa->pickupPoints('PL', '00-950'), 'id'), 'R7 Packeta: a Polish postal code "00-950" lists every point, nearest first');
    assert_equals(['7003'], array_column($r7hPacketa->pickupPoints('PL', 'Bielsko-Biała 43-300'), 'id'), 'R7 Packeta: a hyphenated town name is still searched');
} finally {
    foreach (glob($r7hPacketaDir . '/cache/{,*/}*', GLOB_BRACE) ?: [] as $r7hPath) if (is_file($r7hPath)) unlink($r7hPath);
}

// --- The shop-wide checkout cap cannot be held by a few clients ----------------------------------
// It was a hard-coded 200 attempts/min counting refused attempts too: twenty addresses locked every shopper out.
$r7hCfgProp = new ReflectionProperty(Security::class, 'cfg');
$r7hCfg = $r7hCfgProp->getValue();
$r7hServer = $_SERVER['REMOTE_ADDR'] ?? null;
$r7hSessionWasActive = session_status() === PHP_SESSION_ACTIVE;
if ($r7hSessionWasActive) session_write_close();
$r7hSessionId = session_id();
$r7hRateProp = new ReflectionProperty(Security::class, 'rateDb');
$r7hPreviousRateDb = $r7hRateProp->getValue();
$r7hRateDb = new PDO('sqlite::memory:');
Security::setRateDbForTesting($r7hRateDb);
$r7hAttempt = static function (string $ip, string $session): bool {
    $_SERVER['REMOTE_ADDR'] = $ip;
    session_id($session);
    $allowed = Security::checkoutAllowed();
    http_response_code(200);
    return $allowed;
};
try {
    $r7hCfgProp->setValue(null, ['checkout_global_limit' => 0] + (array)$r7hCfg);
    $r7hFlood = 0;
    for ($i = 0; $i < 30; $i++) for ($j = 0; $j < 12; $j++) $r7hFlood += $r7hAttempt("198.51.100.{$i}", "r7hflood{$i}x{$j}") ? 1 : 0;
    assert_equals([300, true], [$r7hFlood, $r7hAttempt('203.0.113.7', 'r7hshopper1')],
        'R7 checkout limit: off by default, 30 addresses hit only their own 10/min limit and a shopper still checks out');
    $r7hRateDb->exec('DELETE FROM hits');
    $r7hCfgProp->setValue(null, ['checkout_global_limit' => 3] + (array)$r7hCfg);
    $r7hCapped = [];
    for ($i = 0; $i < 5; $i++) $r7hCapped[] = $r7hAttempt("203.0.113.{$i}", "r7hcap{$i}");
    $r7hGlobalHits = (int)$r7hRateDb->query("SELECT COUNT(*) FROM hits WHERE key = '" . hash('sha256', 'checkout_global|global') . "'")->fetchColumn();
    assert_equals([[true, true, true, false, false], 3], [$r7hCapped, $r7hGlobalHits],
        'R7 checkout limit: a configured cap refuses beyond its count and never records a refused attempt');
} finally {
    $r7hCfgProp->setValue(null, $r7hCfg);
    $r7hRateProp->setValue(null, $r7hPreviousRateDb);
    if ($r7hServer === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $r7hServer;
    if ($r7hSessionId !== '') session_id($r7hSessionId);
    if ($r7hSessionWasActive) @session_start();
}
