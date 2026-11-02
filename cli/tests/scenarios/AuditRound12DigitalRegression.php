<?php

// Round 12 review (2026-11-02, bookshop delta), H1 + M2: an order line's digital nature was read from the catalog
// whenever it was used. An e-book line (no allocation) got an invented "legacy_variant" allocation, so planOrder made
// it a parcel of its own that carried the whole cash-on-delivery amount while the paperback parcel collected 0. An
// admin switching a variant's digital flag after the order lost (paperback marked digital: never released) or invented
// stock (e-book marked physical: "released" on cancellation). Now order_items.digital freezes it at checkout.
echo "\n--- Audit round 12: digital order lines (COD parcel, frozen flag, bundles) ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r12dFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R12 digital {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r12dCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$r12dWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
if ($r12dWarehouse === 0) {
    $pdo->exec("INSERT INTO warehouses (store_id, code, name, is_default) VALUES ('default', 'r12d-main', 'R12 main', 1)");
    $r12dWarehouse = (int)$pdo->lastInsertId();
}
$pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, weight_g) VALUES
    (99201, 'default', {$r12dCategory}, 'R12D-BOOK', 'r12d-book', 'simple', 'R12 Book', 1290, 'eur', 0, 'active', 'deny', 400),
    (99202, 'default', {$r12dCategory}, 'R12D-SET', 'r12d-set', 'bundle', 'R12 Box set', 3000, 'eur', 0, 'active', 'deny', 900)");
$pdo->exec("INSERT INTO variants (id, product_id, sku, name, attrs, price_absolute, stock, sort_order, digital) VALUES
    (99211, 99201, 'R12D-EBOOK', 'eBook', '{\"format\":\"eBook\"}', 499, 0, 0, 1),
    (99212, 99201, 'R12D-PB', 'Paperback', '{\"format\":\"Paperback\"}', 1290, 0, 1, 0),
    (99221, 99202, 'R12D-SET-STD', 'Standard', '{}', 3000, 0, 0, 0)");
$pdo->exec("INSERT INTO bundle_components (bundle_product_id, component_product_id, component_variant_id, quantity, sort_order) VALUES (99202, 99201, 99212, 2, 0)");
Warehouse::setStock($r12dWarehouse, 'R12D-PB', 5, 'count', 'test', 'r12d-opening-pb');
Warehouse::syncProductStock(['R12D-PB']);
$r12dCustomer = ['name' => 'R12 Reader', 'email' => 'r12d@example.test', 'phone' => '', 'address' => [],
    'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur'];

$r12dEbookOnly = 0;
try {
    $_SESSION['cart'] = [];
    Cart::add(99201, 99211, 1);
    $r12dEbookOnly = Orders::create(Cart::enriched()['items'], $r12dCustomer, 'bank_transfer');
} catch (Throwable $e) { $r12dFail('e-book order setup', $e); }

$r12dOrder = 0;
try {
    // The e-book first in the cart: its line used to be the "first parcel" that collects the cash on delivery.
    $_SESSION['cart'] = [];
    Cart::add(99201, 99211, 1);
    Cart::add(99201, 99212, 2);
    $r12dOrder = Orders::create(Cart::enriched()['items'], $r12dCustomer, 'cod');
    assert_equals(['R12D-EBOOK' => 1, 'R12D-PB' => 0],
        array_map('intval', $pdo->query("SELECT sku, digital FROM order_items WHERE order_id = {$r12dOrder} ORDER BY sku")->fetchAll(PDO::FETCH_KEY_PAIR)),
        'R12 M2: the order freezes which of its lines are digital content');
    assert_equals(3, Warehouse::totalAvailable('R12D-PB'), 'R12 setup: the order reserves the two paperbacks');

    $r12dShipments = Fulfillment::planOrder($r12dOrder);
    assert_equals(1, count($r12dShipments), 'R12 H1: a mixed e-book + paperback order plans one parcel, none for the e-book');
    $r12dItems = $pdo->query('SELECT si.sku, si.quantity FROM shipment_items si JOIN shipments s ON s.id = si.shipment_id WHERE s.order_id = ' . $r12dOrder)->fetchAll(PDO::FETCH_KEY_PAIR);
    assert_equals(['R12D-PB' => 2], array_map('intval', $r12dItems), 'R12 H1: the parcel holds the paperbacks only');
    $r12dRow = Orders::byId($r12dOrder);
    assert_equals((int)$r12dRow['total'], ShippingService::parcelCodAmount($r12dRow, (int)($r12dShipments[0] ?? 0)),
        'R12 H1: the paperback parcel collects the whole cash on delivery (not 0 behind an e-book "parcel")');
    $r12dOpen = [];
    foreach (Fulfillment::shippable($r12dOrder) as $r12dGroup) foreach ($r12dGroup['lines'] as $r12dLine) $r12dOpen[] = $r12dLine['sku'];
    assert_equals(['R12D-PB'], $r12dOpen, 'R12 H1: the admin is offered only the paperback to ship');
} catch (Throwable $e) { $r12dFail('COD parcel', $e); }

try {
    // The admin swaps the flags after the orders: the frozen lines keep stock and the withdrawal period as sold.
    $pdo->exec('UPDATE variants SET digital = 1 WHERE id = 99212');
    $pdo->exec('UPDATE variants SET digital = 0 WHERE id = 99211');
    assert_true(Withdrawal::digitalOnly(['id' => $r12dEbookOnly]), 'R12 M2: an e-book order stays digital-only (withdrawal period from the order) after the variant turns physical');
    assert_true(!Withdrawal::digitalOnly(['id' => $r12dOrder]), 'R12 M2: the mixed order stays a delivery of goods');
    $r12dEbookLine = $pdo->query("SELECT * FROM order_items WHERE order_id = {$r12dEbookOnly}")->fetch(PDO::FETCH_ASSOC);
    assert_equals([], Inventory::reservationLines($r12dEbookLine), 'R12 M2: the e-book line still reserves nothing');
    Inventory::returnOrderItem($r12dEbookLine, 'r12d-return');
    assert_equals([0, 0], [(int)$pdo->query('SELECT stock FROM variants WHERE id = 99211')->fetchColumn(),
        (int)$pdo->query("SELECT COUNT(*) FROM warehouse_stock WHERE sku = 'R12D-EBOOK'")->fetchColumn()],
        'R12 M2: returning the e-book line restocks no phantom units');
    Fulfillment::cancelPlanned($r12dOrder);
    Orders::restoreStock($r12dOrder);
    assert_equals(5, Warehouse::totalAvailable('R12D-PB'), 'R12 M2: cancelling releases the two paperbacks although the variant is now marked digital');
    assert_equals([0, 0], [(int)$pdo->query('SELECT stock FROM variants WHERE id = 99211')->fetchColumn(),
        (int)$pdo->query("SELECT COUNT(*) FROM warehouse_stock WHERE sku = 'R12D-EBOOK'")->fetchColumn()],
        'R12 M2: cancelling credits no stock to the e-book now marked physical');
} catch (Throwable $e) { $r12dFail('flag switched after the order', $e); }
$pdo->exec('UPDATE variants SET digital = 0 WHERE id = 99212');
$pdo->exec('UPDATE variants SET digital = 1 WHERE id = 99211');
$_SESSION['cart'] = [];

try {
    // Bundles: a digital line reserves nothing, so a box set can never be a download.
    $r12dRows = ['99221' => ['sku' => 'R12D-SET-STD', 'name' => 'Standard', 'attrs' => '', 'price_absolute' => '3000', 'price_delta' => '0',
        'stock' => '0', 'stock_loaded' => '0', 'availability' => 'active', 'inventory_policy' => 'inherit', 'sort_order' => '0', 'digital' => '1']];
    $r12dValidated = ProductVariants::validate(99202, $r12dRows);
    assert_true(is_string($r12dValidated) && str_contains($r12dValidated, I18n::t('admin.msg.variant.digital_bundle')),
        'R12 low: the variant editor refuses the digital flag on a bundle');
    $r12dRefused = false;
    try {
        Inventory::assertDigitalLine(['product_id' => 99202, 'variant_id' => 99221, 'attrs' => []]);
    } catch (InvalidArgumentException $e) {
        $r12dRefused = true;
    }
    assert_true($r12dRefused, 'R12 low: checkout refuses a bundle line flagged digital instead of skipping its components');
} catch (Throwable $e) { $r12dFail('bundle', $e); }

// Existing lines: digital product, e-book without allocation -> 1; e-book sold with an allocation, goods -> 0.
$r12dMigration = require TEST_CLI_DIR . '/../migrations/20261103090000_order_item_digital.php';
$r12dMigrate = static function (PDO $db, bool $isSqlite, string $label) use ($r12dMigration): void {
    foreach (['CREATE TABLE products (id INTEGER PRIMARY KEY, product_type VARCHAR(40))',
        'CREATE TABLE variants (id INTEGER PRIMARY KEY, product_id INTEGER, digital INTEGER NOT NULL DEFAULT 0)',
        'CREATE TABLE order_items (id INTEGER PRIMARY KEY, product_id INTEGER, variant_id INTEGER, attrs TEXT)',
        "INSERT INTO products VALUES (1, 'downloadable'), (2, 'simple')",
        'INSERT INTO variants VALUES (21, 2, 1), (22, 2, 0)',
        "INSERT INTO order_items VALUES (1, 1, NULL, '{}'), (2, 2, 21, '[]'),
            (3, 2, 21, '{\"_inventory_allocations\":[{\"warehouse_id\":1,\"qty\":1,\"status\":\"reserved\"}]}'), (4, 2, 22, '{}'), (5, 2, NULL, NULL)"] as $sql) $db->exec($sql);
    $r12dMigration->up($db, $isSqlite);
    $r12dMigration->up($db, $isSqlite);
    assert_equals([1 => 1, 2 => 1, 3 => 0, 4 => 0, 5 => 0], array_map('intval', $db->query('SELECT id, digital FROM order_items ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR)),
        "R12 migration ({$label}): existing lines are frozen digital or goods as they were sold, idempotently");
};
try {
    $r12dMigrate(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), true, 'SQLite');
} catch (Throwable $e) { $r12dFail('migration (SQLite)', $e); }
if (getenv('TEST_MYSQL_DSN')) {
    $r12dMy = null;
    $r12dSchema = 'bbe_r12d_' . bin2hex(random_bytes(4));
    try {
        $r12dMy = new PDO((string)getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $r12dMy->exec("CREATE DATABASE `{$r12dSchema}` CHARACTER SET utf8mb4");
        $r12dMy->exec("USE `{$r12dSchema}`");
        $r12dMigrate($r12dMy, false, 'MySQL');
    } catch (Throwable $e) { $r12dFail('migration (MySQL)', $e); }
    if ($r12dMy) $r12dMy->exec("DROP DATABASE IF EXISTS `{$r12dSchema}`");
} else {
    echo "  [SKIP] R12 migration on MySQL (set TEST_MYSQL_DSN to enable)\n";
}
