<?php

// Review round 17 (2026-11-05) "developer docs", medium: the migration example in docs/EXTENSIONS.md created its table
// without a primary-key column name ("(INTEGER PRIMARY KEY, ...)": SQLite took INTEGER as the column name, MySQL
// refused the statement). The example is run here as written, on SQLite and, with TEST_MYSQL_DSN, on MySQL/MariaDB.
echo "\n--- Audit round 17: developer docs ---\n";

$r17dDoc = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/docs/EXTENSIONS.md');
preg_match('#```php\n(<\?php\n// extensions/acme-loyalty/migrations/[^\n]+\n.*?)```#s', $r17dDoc, $r17dBlock);
assert_true(isset($r17dBlock[1]), 'R17 docs: EXTENSIONS.md has the acme-loyalty migration example');
$r17dFile = sys_get_temp_dir() . '/bbe-r17-docs-migration-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($r17dFile, $r17dBlock[1] ?? '<?php return null;');
$r17dMigration = require $r17dFile;
@unlink($r17dFile);

$r17dDrivers = ['sqlite' => null];
if (getenv('TEST_MYSQL_DSN')) $r17dDrivers['mysql'] = 'bbe_r17d_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
foreach ($r17dDrivers as $r17dDriver => $r17dDbName) {
    $r17dPdo = null;
    try {
        if ($r17dDriver === 'mysql') {
            $r17dPdo = new PDO((string)getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]);
            $r17dPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $r17dPdo->exec("CREATE DATABASE `{$r17dDbName}` CHARACTER SET utf8mb4");
            $r17dPdo->exec("USE `{$r17dDbName}`");
        } else {
            $r17dPdo = new PDO('sqlite::memory:');
            $r17dPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        $r17dMigration->up($r17dPdo, $r17dDriver === 'sqlite');
        $r17dPdo->exec('INSERT INTO ext_acme_loyalty_points (customer_id, points) VALUES (7, 10)');
        $r17dPdo->exec('INSERT INTO ext_acme_loyalty_points (customer_id, points) VALUES (8, 20)');
        $r17dRows = $r17dPdo->query('SELECT id, customer_id, points FROM ext_acme_loyalty_points ORDER BY id')->fetchAll(PDO::FETCH_NUM);
        assert_equals([[1, 7, 10], [2, 8, 20]], array_map(static fn(array $r): array => array_map('intval', $r), $r17dRows),
            "R17 docs {$r17dDriver}: the documented extension migration creates a table with an auto-increment id");
    } catch (Throwable $e) {
        assert_true(false, "R17 docs {$r17dDriver}: the documented extension migration runs (" . get_class($e) . ': ' . $e->getMessage() . ')');
    } finally {
        if ($r17dDriver === 'mysql' && $r17dPdo instanceof PDO) $r17dPdo->exec("DROP DATABASE IF EXISTS `{$r17dDbName}`");
    }
}

// theme-check warned 17 times per store about the root layouts.json pages category, cart, checkout and search, which the
// storefront renders with fixed templates (storefront/category-page, storefront/cart, ...), never through sections.
$r17dCheck = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(TEST_CLI_DIR) . '/cli/theme-check.php') . ' 2>&1', $r17dCheck, $r17dCheckExit);
assert_equals([0, []], [$r17dCheckExit, array_values(preg_grep('/^\[(WARN|FAIL)\]/', $r17dCheck))],
    'R17 theme-check: the shipped themes and stores pass without warnings (was 17 per store for unused root layouts.json pages)');

// generateLabel() got the whole order row (SELECT *), the customer's IP address included: a carrier gets the parcel's
// fields only (ShippingService::LABEL_ORDER_FIELDS).
try {
    $r17dDb = Db::get();
    $r17dCategory = (int)$r17dDb->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $r17dWarehouse = (int)$r17dDb->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    // Fixed id: an auto-assigned id follows the highest fixed id of earlier scenarios and collides with later ones.
    $r17dDb->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy)
        VALUES (98871, 'default', {$r17dCategory}, 'R17D-LABEL', 'r17d-label', 'simple', 'R17D label goods', 1000, 'eur', 5, 'active', 'deny')");
    Warehouse::setStock($r17dWarehouse, 'R17D-LABEL', 5, 'count', 'test', 'r17d-opening');
    $r17dOrder = Orders::create([['product_id' => 98871, 'variant_id' => null, 'sku' => 'R17D-LABEL', 'name' => 'R17D-LABEL', 'qty' => 1, 'price' => 1000, 'attrs' => []]],
        ['name' => 'R17D Label', 'email' => 'r17d-label@example.test', 'phone' => '+421900000000', 'address' => ['street' => 'Hlavná 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'],
            'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur'], 'bank_transfer');
    $r17dDb->prepare("UPDATE orders SET ip_address = '203.0.113.77' WHERE id = :id")->execute([':id' => $r17dOrder]);
    $r17dCarrier = new class implements ShippingAdapter {
        public ?array $order = null;
        public function getRates(array $params): array { return []; }
        public function generateLabel(array $order): array { $this->order = $order; return ['awb' => 'R17D-' . $order['id'], 'label_data' => '']; }
        public function trackShipment(string $awb): array { return []; }
        public function supportsCOD(): bool { return true; }
        public function carrierId(): string { return 'r17d-carrier'; }
    };
    ShippingService::setAdapters(['r17d-carrier' => $r17dCarrier]);
    ShippingService::generateLabel($r17dOrder, 'r17d-carrier');
    $r17dSeen = $r17dCarrier->order ?? [];
    assert_equals([], array_values(array_diff(array_keys($r17dSeen), ShippingService::LABEL_ORDER_FIELDS)),
        'R17 label: the carrier gets only the label fields of the order (was the whole row: ' . implode(',', array_keys($r17dSeen)) . ')');
    assert_true(!array_key_exists('ip_address', $r17dSeen) && !str_contains(json_encode($r17dSeen) ?: '', '203.0.113.77'),
        'R17 label: the customer\'s IP address does not reach the carrier');
    assert_equals([$r17dOrder, 'R17D Label', 'r17d-label@example.test', '+421900000000', true],
        [(int)($r17dSeen['id'] ?? 0), $r17dSeen['customer_name'] ?? null, $r17dSeen['customer_email'] ?? null, $r17dSeen['customer_phone'] ?? null, str_contains((string)($r17dSeen['shipping_addr'] ?? ''), 'Bratislava')],
        'R17 label: the carrier still gets the recipient and the delivery address');
} catch (Throwable $e) {
    assert_true(false, 'R17 label fields (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    ShippingService::setAdapters([]);
}

// The payment provider docs did not say which header name the webhook endpoint reads (signature_header) nor that a
// provider is offered at checkout only through payment_methods + payment_method_countries rows.
preg_match("#'signature_header' => '([^']+)'#", $r17dDoc, $r17dHeader);
assert_true(isset($r17dHeader[1]) && preg_match('/^HTTP_[A-Z0-9_]+$/', $r17dHeader[1]) === 1,
    'R17 docs payment: EXTENSIONS.md shows a signature_header the webhook endpoint accepts (' . ($r17dHeader[1] ?? 'none') . ')');
try {
    $r17dDb = Db::get();
    $r17dDb->exec("INSERT INTO payment_methods (store_id, code, provider, name, service_type, requires_online, active, sort_order)
        VALUES ('default', 'r17d-acme', 'acme-pay', 'R17D Acme', 'online_card', 1, 1, 1)");
    $r17dOffered = static fn(): bool => in_array('r17d-acme', array_column(PaymentService::availableMethods('AT', 'eur', 1000), 'code'), true);
    $r17dWithoutCountry = $r17dOffered();
    $r17dDb->exec("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES ('default', 'r17d-acme', 'AT')");
    assert_equals([false, true], [$r17dWithoutCountry, $r17dOffered()],
        'R17 docs payment: the documented payment_methods row is offered at checkout once it has a payment_method_countries row');
} catch (Throwable $e) {
    assert_true(false, 'R17 docs payment rows (' . get_class($e) . ': ' . $e->getMessage() . ')');
} finally {
    Db::get()->exec("DELETE FROM payment_method_countries WHERE method_code = 'r17d-acme'");
    Db::get()->exec("DELETE FROM payment_methods WHERE code = 'r17d-acme'");
}
