<?php

// Review round 23 (2026-11-08, kolo 7), low "API, time and retention":
// - POST /products: two concurrent creates of one SKU both passed the SKU check on MySQL and the second upsert
//   overwrote the first, still answering 201. An API create now inserts only (409 sku_exists on the unique SKU).
// - OpenAPI did not document PATCH /products/{id} 422 price_below_variant_floor.
// - vat_validated_at was written in local time and read as UTC.
// - checkout_sessions were never deleted, and every order lookup (a payment link shown on the page) added a row.
// - back-in-stock requests were cleaned up only when a SKU was restocked.
// - T12: feed sync times in the admin were shown in UTC.

$r23oRoot = dirname(TEST_CLI_DIR);

// 1. Create only: a second create of the SKU (what the slower of two concurrent requests runs) fails, nothing changes.
try {
    $r23oRow = ['sku' => 'R23O-API-1', 'name' => 'R23O first', 'price' => 1000, 'status' => 'active', 'weight_g' => 0, 'stock' => '1'];
    Import::importProduct($r23oRow, $pdo, true);
    $r23oSecond = null;
    try {
        Import::importProduct(['name' => 'R23O second', 'price' => 1] + $r23oRow, $pdo, true);
    } catch (DomainException $e) {
        $r23oSecond = 'refused';
    }
    $r23oRace = null;
    try {
        // The interleaving MySQL allowed: the product check saw nothing, the row exists by the time of the insert.
        Import::upsertProduct(['sku' => 'R23O-API-1', 'slug' => 'r23o-race', 'name' => 'R23O race', 'category_id' => null, 'price' => 1, 'compare_price' => null,
            'stock' => 0], $pdo, true);
    } catch (PDOException $e) {
        $r23oRace = substr((string)$e->getCode(), 0, 2);
    }
    $r23oName = $pdo->query("SELECT name, price FROM products WHERE sku = 'R23O-API-1'")->fetch(PDO::FETCH_NUM);
    assert_equals(['refused', '23', ['R23O first', 1000]], [$r23oSecond, $r23oRace, [(string)$r23oName[0], (int)$r23oName[1]]],
        'R23 API: a create of an existing SKU is refused and a racing insert fails on the unique SKU; the first product stays (was: overwritten, 201)');
} catch (Throwable $e) {
    assert_true(false, 'R23 API create (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
$r23oApi = (string)file_get_contents($r23oRoot . '/src/ApiV1.php');
assert_true(str_contains($r23oApi, 'Import::importProduct($row, $db, true)') && str_contains($r23oApi, "catch (DomainException | PDOException \$e)"),
    'R23 API: POST /products creates only and answers 409 sku_exists when the SKU appeared meanwhile');

// 2. OpenAPI: PATCH /products/{id} documents its 422.
$r23oSpec = str_replace("\r\n", "\n", (string)file_get_contents($r23oRoot . '/docs/openapi.yaml'));
$r23oPatch = (string)(preg_match('/\n    patch:\n      operationId: updateProduct\n(.*?)\n  \/[a-z]/s', $r23oSpec, $m) ? $m[1] : '');
assert_true(str_contains($r23oPatch, "'422':") && str_contains($r23oPatch, 'price_below_variant_floor'),
    'R23 OpenAPI: PATCH /products/{id} documents 422 price_below_variant_floor');

// 3. vat_validated_at in UTC.
$r23oHandlers = (string)file_get_contents($r23oRoot . '/src/Http/ApiHandlers.php');
assert_true(str_contains($r23oHandlers, "'vat_validated_at' => \$vatIdValid ? gmdate('Y-m-d H:i:s')"),
    'R23 time: vat_validated_at is stored in UTC (it was local time, read as UTC)');

// 4. checkout_sessions: lapsed links go, a page link per view does not pile up, gateway sessions stay.
try {
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, store_id, created_at)
        VALUES ('R23O-CS', 'R23O', 'r23o@example.test', '', '{}', 1000, 0, 0, 0, 1000, 'eur', 'pending', 'pending', 'stripe', 'default', " . Db::now() . ")")->execute();
    $r23oOrder = (int)$pdo->lastInsertId();
    $r23oAt = static fn(int $daysAgo): string => gmdate('Y-m-d H:i:s', time() - $daysAgo * 86400);
    $r23oAdd = $pdo->prepare("INSERT INTO checkout_sessions (order_id, provider, session_id, status, created_at) VALUES (?, ?, ?, 'pending', ?)");
    foreach ([['pay_link', 'old-link', 8], ['pay_link', 'live-link', 6], ['account_offer', 'old-offer', 2], ['confirmation', 'old-conf', 91],
        ['confirmation', 'conf', 30], ['stripe', 'cs_old', 400]] as [$provider, $session, $days]) {
        $r23oAdd->execute([$r23oOrder, $provider, $session, $r23oAt($days)]);
    }
    $r23oDeleted = CheckoutSession::cleanup(90);
    $r23oLeft = $pdo->query("SELECT session_id FROM checkout_sessions WHERE order_id = {$r23oOrder} ORDER BY session_id")->fetchAll(PDO::FETCH_COLUMN);
    assert_equals([3, ['conf', 'cs_old', 'live-link']], [$r23oDeleted, $r23oLeft],
        'R23 retention: expired payment/account links and old confirmations are deleted, live ones and gateway sessions stay (was: never deleted)');
    CheckoutSession::keepNewest($r23oOrder, 'pay_link_page', 5);
    foreach (range(1, 8) as $i) $r23oAdd->execute([$r23oOrder, 'pay_link_page', "page-{$i}", $r23oAt(0)]);
    CheckoutSession::keepNewest($r23oOrder, 'pay_link_page', 5);
    assert_equals(['page-4', 'page-5', 'page-6', 'page-7', 'page-8'],
        $pdo->query("SELECT session_id FROM checkout_sessions WHERE order_id = {$r23oOrder} AND provider = 'pay_link_page' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN),
        'R23 retention: an order keeps only its newest five page payment links (each lookup added one)');
    $pdo->exec("DELETE FROM checkout_sessions WHERE order_id = {$r23oOrder}");
    $pdo->exec("DELETE FROM orders WHERE id = {$r23oOrder}");
} catch (Throwable $e) {
    assert_true(false, 'R23 checkout_sessions retention (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
$r23oLink = (string)file_get_contents($r23oRoot . '/src/PaymentLink.php');
assert_true(str_contains($r23oLink, 'CheckoutSession::keepNewest((int)$order[\'id\'], self::PAGE_PROVIDER'),
    'R23 retention: PaymentLink::issue trims the page links of the order');

// 5. back-in-stock: the daily cleanup job deletes lapsed requests without a restock.
$r23oBisDb = new PDO('sqlite:' . test_fixture_path('r23o-bis.sqlite'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r23oBisDb->exec('CREATE TABLE back_in_stock_requests (id INTEGER PRIMARY KEY, store_id TEXT, product_id INTEGER, email TEXT, locale TEXT, created_at TEXT, confirmed_at TEXT, token_hash TEXT)');
$r23oMain = [Db::get(), Db::driver()];
Db::init($r23oBisDb, 'sqlite');
try {
    $r23oBis = $r23oBisDb->prepare('INSERT INTO back_in_stock_requests (store_id, product_id, email, locale, created_at, confirmed_at) VALUES (?, 1, ?, ?, ?, ?)');
    $r23oBis->execute(['default', 'r23o-lapsed@example.test', 'sk', gmdate('Y-m-d H:i:s', time() - (BackInStock::RETENTION_DAYS + 1) * 86400), gmdate('Y-m-d H:i:s')]);
    $r23oBis->execute(['cz', 'r23o-unconfirmed@example.test', 'sk', gmdate('Y-m-d H:i:s', time() - 3 * 86400), null]);
    $r23oBis->execute(['default', 'r23o-waiting@example.test', 'sk', gmdate('Y-m-d H:i:s', time() - 86400), gmdate('Y-m-d H:i:s')]);
    assert_equals([2, ['r23o-waiting@example.test']], [BackInStock::cleanup(), $r23oBisDb->query('SELECT email FROM back_in_stock_requests')->fetchAll(PDO::FETCH_COLUMN)],
        'R23 retention: the cleanup deletes lapsed and unconfirmed back-in-stock requests of every store without a restock (was: only on restock)');
} catch (Throwable $e) {
    assert_true(false, 'R23 back-in-stock retention (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    Db::init($r23oMain[0], $r23oMain[1]);
    $r23oBisDb = null;
}
$r23oRetention = (string)file_get_contents($r23oRoot . '/src/RetentionCleanup.php');
assert_true(str_contains($r23oRetention, 'BackInStock::cleanup($now)') && str_contains($r23oRetention, 'CheckoutSession::cleanup('),
    'R23 retention: the daily cleanup job runs both cleanups');
$pdo->exec("DELETE FROM products WHERE sku = 'R23O-API-1'");

// 6. T12: the admin shows feed sync times on the shop's clock.
$r23oOps = (string)file_get_contents($r23oRoot . '/src/Entrypoints/Admin/Operations.php');
assert_true(substr_count($r23oOps, "I18n::dateTime((string)\$f['last_sync'])") === 3 && !str_contains($r23oOps, "substr(\$f['last_sync']"),
    'R23 T12: feed sync times in the admin are shown in the shop time zone (were UTC)');
