<?php

// CR-M14 (code review 2026-10-15): GDPR erasure anonymised the customer row, addresses, mail log,
// audit log and coupon uses, but left the person's reviews (name + text), wishlist, abandoned carts
// (e-mail + cart contents), RMA reasons and outbox event payloads (customer.created carries e-mail
// and name) untouched. The same checks run on native MySQL/MariaDB (TEST_MYSQL_DSN): the first
// fix threw "Illegal mix of collations" there while SQLite passed.
echo "\n--- CR-M14: GDPR erasure covers reviews, wishlist, carts, RMA and outbox ---\n";
$geChecks = static function (PDO $geDb, int $geProduct, array $geOrderTemplate, string $geLabel): void {
    $geEmail = 'cr-m14-' . bin2hex(random_bytes(4)) . '@example.test';
    $geOtherEmail = 'cr-m14-other-' . bin2hex(random_bytes(4)) . '@example.test';
    $geRegister = static function (string $email, string $name) use ($geDb): int {
        [$ok, $id] = Customers::register($email, 'Str0ng-pass-CR-M14', $name);
        if (!$ok) throw new RuntimeException('CR-M14 fixture: registration failed');
        $geDb->prepare("UPDATE customers SET email_verified_at = '2026-10-01 00:00:00' WHERE id = ?")->execute([$id]);
        return (int)$id;
    };
    $geSubject = $geRegister($geEmail, 'Erika Erased');
    $geOther = $geRegister($geOtherEmail, 'Kept Customer');
    $geOrders = [];
    foreach (['subject' => [$geSubject, $geEmail], 'other' => [$geOther, $geOtherEmail]] as $geCase => [$geCustomer, $geMail]) {
        $geRow = array_replace($geOrderTemplate, ['order_number' => 'CR-M14-' . $geCase . '-' . bin2hex(random_bytes(3)), 'customer_id' => $geCustomer, 'customer_email' => $geMail]);
        $geDb->prepare('INSERT INTO orders (' . implode(',', array_keys($geRow)) . ') VALUES (' . implode(',', array_fill(0, count($geRow), '?')) . ')')->execute(array_values($geRow));
        $geOrders[$geCase] = (int)$geDb->lastInsertId();
        $geDb->prepare("INSERT INTO product_reviews (product_id, customer_id, author_name, rating, title, body, status) VALUES (?, ?, ?, 5, 'Great', ?, 'approved')")
            ->execute([$geProduct, $geCustomer, $geCase . ' author', 'I am ' . $geCase . ' and I live next door']);
        $geDb->prepare('INSERT INTO wishlist_items (owner_key, customer_id, product_id) VALUES (?, ?, ?)')->execute(['c:' . $geCustomer, $geCustomer, $geProduct]);
        $geDb->prepare("INSERT INTO abandoned_carts (session_id, customer_email, cart_data, store_id) VALUES (?, ?, ?, 'default')")
            ->execute(['cr-m14-' . $geCase . '-' . bin2hex(random_bytes(4)), $geMail, '{"items":[{"name":"' . $geCase . '"}]}']);
        $geDb->prepare("INSERT INTO rma_requests (rma_number, order_id, customer_id, reason, store_id) VALUES (?, ?, ?, ?, 'default')")
            ->execute(['CR-M14-' . $geCase . '-' . bin2hex(random_bytes(3)), $geOrders[$geCase], $geCustomer, 'Broken, call me at +421 900 ' . $geCase]);
        $geDb->beginTransaction();
        Outbox::record('order.created', 'order', $geOrders[$geCase], ['order_id' => $geOrders[$geCase], 'customer_email' => $geMail]);
        $geDb->commit();
    }
    $geCount = static function (string $sql, array $params = []) use ($geDb): int {
        $stmt = $geDb->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    };
    $gePayloads = static function (string $needle) use ($geDb): int {
        $stmt = $geDb->prepare('SELECT COUNT(*) FROM event_outbox WHERE INSTR(payload, ?) > 0');
        $stmt->execute([$needle]);
        return (int)$stmt->fetchColumn();
    };
    assert_true($gePayloads($geEmail) >= 2, "CR-M14 {$geLabel} fixture: customer and order events carry the e-mail before erasure");

    $geError = '';
    try {
        $geStatus = GdprService::anonymizeCustomer($geSubject, 'cr-m14-test')['status'];
    } catch (PDOException $e) {
        $geStatus = 'error';
        $geError = ' (' . $e->getMessage() . ')';
    }
    assert_equals('anonymized', $geStatus, "CR-M14 {$geLabel}: erasure succeeds{$geError}");

    assert_equals(0, $geCount('SELECT COUNT(*) FROM product_reviews WHERE customer_id = ?', [$geSubject]), "CR-M14 {$geLabel}: the erased customer's reviews are removed");
    assert_equals(0, $geCount("SELECT COUNT(*) FROM product_reviews WHERE author_name = 'subject author'"), "CR-M14 {$geLabel}: no review keeps the erased author name");
    assert_equals(0, $geCount('SELECT COUNT(*) FROM wishlist_items WHERE customer_id = ? OR owner_key = ?', [$geSubject, 'c:' . $geSubject]), "CR-M14 {$geLabel}: the wishlist is removed");
    assert_equals(0, $geCount('SELECT COUNT(*) FROM abandoned_carts WHERE LOWER(customer_email) = LOWER(?)', [$geEmail]), "CR-M14 {$geLabel}: abandoned carts with the e-mail are removed");
    assert_equals(0, $geCount('SELECT COUNT(*) FROM rma_requests WHERE order_id = ? AND reason LIKE ?', [$geOrders['subject'], '%+421%']), "CR-M14 {$geLabel}: the RMA reason is scrubbed");
    assert_equals(1, $geCount('SELECT COUNT(*) FROM rma_requests WHERE order_id = ?', [$geOrders['subject']]), "CR-M14 {$geLabel}: the RMA itself stays for the credit-note trail");
    assert_equals(0, $gePayloads($geEmail), "CR-M14 {$geLabel}: no outbox event payload keeps the e-mail");
    assert_equals(0, $gePayloads('Erika Erased'), "CR-M14 {$geLabel}: no outbox event payload keeps the name");

    assert_equals(1, $geCount('SELECT COUNT(*) FROM product_reviews WHERE customer_id = ?', [$geOther]), "CR-M14 {$geLabel}: other customers' reviews are untouched");
    assert_equals(1, $geCount('SELECT COUNT(*) FROM wishlist_items WHERE customer_id = ?', [$geOther]), "CR-M14 {$geLabel}: other customers' wishlists are untouched");
    assert_equals(1, $geCount('SELECT COUNT(*) FROM abandoned_carts WHERE customer_email = ?', [$geOtherEmail]), "CR-M14 {$geLabel}: other customers' carts are untouched");
    assert_equals(1, $geCount('SELECT COUNT(*) FROM rma_requests WHERE order_id = ? AND reason LIKE ?', [$geOrders['other'], '%+421%']), "CR-M14 {$geLabel}: other customers' RMA reasons are untouched");
    assert_true($gePayloads($geOtherEmail) >= 2, "CR-M14 {$geLabel}: other customers' event payloads are untouched");
};

$geSqlite = Db::get();
$geTemplate = $geSqlite->query("SELECT * FROM orders WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
unset($geTemplate['id']);
$geChecks($geSqlite, (int)$geSqlite->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn(), $geTemplate, 'SQLite');

if (getenv('TEST_MYSQL_DSN')) {
    $geNative = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $geNativeDatabase = 'bbe_crm14_' . bin2hex(random_bytes(6)) . '_test';
    $geNativeLock = test_fixture_path('cr-m14-native') . '.lock';
    $geNative->exec("CREATE DATABASE {$geNativeDatabase} CHARACTER SET utf8mb4");
    try {
        $geNative->exec("USE {$geNativeDatabase}");
        Db::init($geNative, 'mysql');
        (new Migrator($geNative, dirname(TEST_CLI_DIR) . '/migrations', $geNativeLock))->apply();
        $geNative->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'cr-m14', 'CR-M14', 'cr-m14', 0)");
        $geNative->exec("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', " . (int)$geNative->lastInsertId() . ", 'CR-M14-P', 'cr-m14-p', 'CR-M14 Product', 1000, 5, 'active')");
        $geNativeProduct = (int)$geNative->lastInsertId();
        $geChecks($geNative, $geNativeProduct, ['store_id' => 'default', 'customer_name' => 'CR-M14', 'payment_method' => 'card', 'subtotal' => 1000, 'total' => 1000], 'native');
    } finally {
        Db::init($geSqlite, 'sqlite');
        $geNative->exec("DROP DATABASE IF EXISTS {$geNativeDatabase}");
        $geNative = null;
        @unlink($geNativeLock);
    }
} else {
    echo "  [SKIP] CR-M14 native MySQL/MariaDB erasure (set TEST_MYSQL_DSN)\n";
}
