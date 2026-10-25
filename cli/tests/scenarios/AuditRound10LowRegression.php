<?php

// Round 10 review (2026-10-25): remaining low findings.
echo "\n--- Audit round 10 (low): shared attribute rename, return after withdrawal, digital complaint, cart key, client IP, admin landing, addresses ---\n";
if (!function_exists('apiCartUpdate')) require_once TEST_CLI_DIR . '/../src/Http/ApiHandlers.php';
$r10lDay = 86400;
$r10lCatalog = StoreContext::current()->catalogId();
$r10lSession = $_SESSION ?? [];
$r10lServer = array_intersect_key($_SERVER, array_flip(['REQUEST_METHOD', 'REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR']));
$r10lPost = $_POST;
$r10lCfgProp = new ReflectionProperty(Security::class, 'cfg');
$r10lCfgBefore = $r10lCfgProp->getValue();
$r10lCfgBase = is_array($r10lCfgBefore) ? $r10lCfgBefore : require dirname(TEST_CLI_DIR) . '/config.php';
$r10lFail = static fn(string $what, Throwable $e) => assert_true(false, "R10 low {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
$r10lNow = static fn(int $daysAgo): string => gmdate('Y-m-d H:i:s', time() - $daysAgo * 86400);
// The suite database has no attribute, withdrawal or complaint tables before the files sorted after this one create them.
(require TEST_CLI_DIR . '/../migrations/20261022090000_withdrawal_requests.php')->up($pdo, Db::isSqlite());
(require TEST_CLI_DIR . '/../migrations/20261022100000_complaints.php')->up($pdo, Db::isSqlite());
$r10lId = Db::isSqlite() ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
$pdo->exec("CREATE TABLE IF NOT EXISTS attribute_definitions (id {$r10lId}, name VARCHAR(255) NOT NULL, slug VARCHAR(191) NOT NULL UNIQUE,
    type VARCHAR(32) NOT NULL DEFAULT 'enum', unit VARCHAR(64) DEFAULT '', sort_order INTEGER DEFAULT 0)");
$pdo->exec("CREATE TABLE IF NOT EXISTS attribute_options (id {$r10lId}, attribute_id INTEGER NOT NULL, value VARCHAR(255) NOT NULL, sort_order INTEGER DEFAULT 0)");
$pdo->exec('CREATE TABLE IF NOT EXISTS category_attributes (category_id INTEGER NOT NULL, attribute_id INTEGER NOT NULL, PRIMARY KEY (category_id, attribute_id))');
try {
    try {
        // Shared attribute: one store's admin renamed an attribute another catalog uses, and that store's product values
        // (product_specifications, keyed by the attribute name) no longer matched it.
        $pdo->prepare('INSERT INTO categories (store_id, slug, name, path) VALUES (?, ?, ?, ?)')->execute([$r10lCatalog, 'r10l-home', 'R10L home', 'r10l-home']);
        $r10lHomeCat = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO categories (store_id, slug, name, path) VALUES (?, ?, ?, ?)')->execute(['r10l-other', 'r10l-other', 'R10L other', 'r10l-other']);
        $r10lOtherCat = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO attribute_definitions (name, slug, type, unit) VALUES ('R10L Weight', 'r10l-weight', 'number', 'kg')");
        $r10lAttr = (int)$pdo->lastInsertId();
        $r10lLink = $pdo->prepare('INSERT INTO category_attributes (category_id, attribute_id) VALUES (?, ?)');
        $r10lLink->execute([$r10lHomeCat, $r10lAttr]);
        $r10lLink->execute([$r10lOtherCat, $r10lAttr]);
        $pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock) VALUES (98251, 'r10l-other', ?, 'R10L other', 'r10l-other-p', 'R10L-OTHER', 'simple', 1000, 'eur', 'active', 1)")
            ->execute([$r10lOtherCat]);
        $pdo->exec("INSERT INTO product_specifications (product_id, criteria_name, criteria_value, numeric_val, lang) VALUES (98251, 'R10L Weight', '2 kg', 2, 'sk')");
        $r10lOld = ['name' => 'R10L Weight', 'slug' => 'r10l-weight', 'type' => 'number', 'unit' => 'kg'];
        $_SESSION['admin_role'] = 'manager';
        assert_equals([true, true, false],
            [CatalogScope::sharedAttributeChangeRefused($r10lAttr, $r10lOld, 'R10L Mass', 'r10l-weight', 'number', 'kg'),
             CatalogScope::sharedAttributeChangeRefused($r10lAttr, $r10lOld, 'R10L Weight', 'r10l-weight', 'number', 'g'),
             CatalogScope::sharedAttributeChangeRefused($r10lAttr, $r10lOld, 'R10L Weight', 'r10l-weight', 'number', 'kg')],
            'R10 shared attribute: a store admin may not rename a shared attribute or change its unit (was allowed), an unchanged save passes');
        $_SESSION['admin_role'] = 'owner';
        assert_equals([false, true],
            [CatalogScope::sharedAttributeChangeRefused($r10lAttr, $r10lOld, 'R10L Mass', 'r10l-weight', 'number', 'kg'),
             CatalogScope::sharedAttributeChangeRefused($r10lAttr, $r10lOld, 'R10L Mass', 'r10l-mass', 'number', 'kg')],
            'R10 shared attribute: the owner may rename it, its slug stays locked for everyone');
        CatalogScope::renameAttributeValues($r10lAttr, 'R10L Weight', 'R10L Mass');
        assert_equals('R10L Mass', (string)$pdo->query('SELECT criteria_name FROM product_specifications WHERE product_id = 98251')->fetchColumn(),
            "R10 shared attribute: a rename carries the other catalog's product values to the new name (they disappeared)");
        $r10lCatalogSrc = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Catalog.php');
        assert_true(str_contains($r10lCatalogSrc, 'CatalogScope::sharedAttributeChangeRefused($id, $old, $name, $slug, $type, $unit)')
            && str_contains($r10lCatalogSrc, "CatalogScope::renameAttributeValues(\$id, (string)\$old['name'], \$name)"),
            'R10 shared attribute: the admin attribute save uses both rules');
    } catch (Throwable $e) {
        $r10lFail('shared attribute', $e);
    }
    try {
        // Return after a timely withdrawal: the consumer has 14 more days to send the goods back (art. 14(1)); RMA refused
        // them 14 days after delivery. Digital content (never shipped) could not be complained about at all.
        $pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock) VALUES (98252, ?, 1, 'R10L ebook', 'r10l-ebook', 'R10L-EBOOK', 'digital', 500, 'eur', 'active', 100)")
            ->execute([$r10lCatalog]);
        $r10lOrderIns = $pdo->prepare("INSERT INTO orders (id, store_id, order_number, status, customer_name, customer_email, customer_phone, shipping_addr, payment_method,
            subtotal, discount_amount, shipping_cost, tax_amount, total, currency, locale, created_at, delivered_at)
            VALUES (?, ?, ?, ?, 'R10L', 'r10l@example.com', '', '{}', 'bank_transfer', 500, 0, 0, 0, 500, 'eur', 'sk', ?, ?)");
        $r10lStore = StoreContext::current()->id();
        $r10lOrderIns->execute([98253, $r10lStore, 'R10L-RET', 'delivered', $r10lNow(25), $r10lNow(20)]);
        $r10lOrderIns->execute([98254, $r10lStore, 'R10L-DIG', 'paid', $r10lNow(40), null]);
        $r10lOrderIns->execute([98255, $r10lStore, 'R10L-NEW', 'paid', $r10lNow(1), null]);
        $pdo->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (98254, 98252, 'R10L-EBOOK', 'R10L ebook', 1, 500, 500)");
        $r10lDigitalItem = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (98255, 98252, 'R10L-EBOOK', 'R10L ebook', 1, 500, 500)");
        $r10lCfgProp->setValue(null, ['rma_return_window_days' => 14] + $r10lCfgBase);
        $r10lReturnOrder = Orders::byId(98253);
        assert_true(!Rma::withinReturnWindow($r10lReturnOrder), 'R10 return: 20 days after delivery without a withdrawal the return window is closed');
        $pdo->prepare("INSERT INTO withdrawal_requests (store_id, order_id, order_number, customer_name, customer_email, items, received_at) VALUES (?, 98253, 'R10L-RET', 'R10L', 'r10l@example.com', '{}', ?)")
            ->execute([$r10lStore, $r10lNow(8)]);
        assert_equals([true, false], [Rma::withinReturnWindow($r10lReturnOrder), Rma::withinReturnWindow($r10lReturnOrder, time() + 7 * $r10lDay)],
            'R10 return: a withdrawal on day 12 leaves 14 days to return the goods (refused on day 20 before), and no longer');
        $pdo->exec("INSERT INTO digital_assets (sku, asset_key, storage_path) VALUES ('R10L-EBOOK', 'r10l', 'r10l/ebook.pdf')");
        $r10lAsset = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO digital_entitlements (order_id, order_item_id, asset_id, token_hash, status, delivered_at) VALUES (98254, ?, ?, ?, 'active', ?)")
            ->execute([$r10lDigitalItem, $r10lAsset, hash('sha256', 'r10l-ebook'), $r10lNow(35)]);
        assert_equals([null, 'not_delivered', 'period_over'],
            [Complaints::refusal(Orders::byId(98254)), Complaints::refusal(Orders::byId(98255)), Complaints::refusal(Orders::byId(98254), time() + 800 * $r10lDay)],
            'R10 complaint: supplied digital content can be complained about (was not_delivered), unsupplied content cannot, the liability period still ends');
    } catch (Throwable $e) {
        $r10lFail('return/complaint', $e);
    }
    try {
        // cart_update with an array key was a TypeError (500); a bad key is a 400.
        $_SESSION = ['csrf_token' => 'r10l-csrf', 'cart' => []];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['csrf_token' => 'r10l-csrf', 'key' => ['p_1'], 'qty' => '1'];
        $r10lUpdate = @apiCartUpdate(); // @: the CLI test has already sent output, so http_response_code() only warns
        assert_equals('Invalid cart key', $r10lUpdate['error'] ?? null, 'R10 cart_update: an array key is refused as an invalid key (was a 500 TypeError)');
        $r10lHandlersSrc = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php');
        assert_true((bool)preg_match("/function apiCartUpdate\(\).*?!is_string\(\\\$key\).*?http_response_code\(400\).*?Invalid cart key/s", $r10lHandlersSrc),
            'R10 cart_update: an invalid key answers HTTP 400');
    } catch (Throwable $e) {
        $r10lFail('cart_update', $e);
    }
    try {
        // Cookie consent stored the proxy's address: behind a trusted proxy it is the client's (Security::clientIp).
        $r10lCfgProp->setValue(null, ['trusted_proxies' => ['10.0.0.5']] + $r10lCfgBase);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';
        $_POST = ['csrf_token' => 'r10l-csrf', 'analytics' => '1', 'method' => 'accept_all'];
        $r10lConsent = apiCookieConsent();
        $r10lConsentHash = (string)$pdo->query('SELECT ip_hash FROM cookie_consent ORDER BY id DESC LIMIT 1')->fetchColumn();
        assert_true(!empty($r10lConsent['ok']) && hash_equals(GdprService::hashPii('203.0.113.7'), $r10lConsentHash),
            'R10 cookie consent: the record holds the client IP behind a trusted proxy, not the proxy address');
        foreach (['src/Http/ApiHandlers.php', 'src/Entrypoints/Storefront/Account.php', 'src/Orders.php'] as $r10lIpFile) {
            assert_true(!str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/' . $r10lIpFile), "\$_SERVER['REMOTE_ADDR']"),
                "R10 client IP: {$r10lIpFile} takes the address from Security::clientIp(), never the raw REMOTE_ADDR");
        }
    } catch (Throwable $e) {
        $r10lFail('client IP', $e);
    }
    try {
        // Editor and fulfillment landed on a 403 dashboard after login: admin.php sends them to their first menu page.
        Security::setRolesForTesting([]);
        $r10lLanding = [];
        foreach (['owner', 'editor', 'fulfillment', 'viewer'] as $r10lRole) {
            $_SESSION = ['admin_role' => $r10lRole];
            $r10lLanding[$r10lRole] = Security::adminLandingPage();
        }
        assert_equals(['owner' => 'dashboard', 'editor' => 'products', 'fulfillment' => 'sales', 'viewer' => 'dashboard'], $r10lLanding,
            'R10 admin landing: each role lands on the first menu page it may open');
        $r10lAdminSrc = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin.php');
        assert_true(strpos($r10lAdminSrc, 'Security::adminLandingPage()') !== false
            && strpos($r10lAdminSrc, 'Security::adminLandingPage()') < strpos($r10lAdminSrc, 'Security::adminRequire($requiredPerm)'),
            'R10 admin landing: the plain admin.php redirects before the dashboard permission check answers 403');
    } catch (Throwable $e) {
        $r10lFail('admin landing', $e);
    }
    try {
        // Addresses: a save without type was a NOT NULL error (500) and the account edit skipped all validation.
        $_SESSION = [];
        [$r10lRegOk, $r10lCustomer] = Customers::register('r10l-addr@example.com', 'R10l-Password-1', 'R10L Address');
        $r10lAddr = ['name' => 'R10L', 'street' => 'Hlavna 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'];
        $r10lAddrId = Customers::addAddress((int)$r10lCustomer, $r10lAddr);
        $r10lAddrType = static fn(): string => (string)$pdo->query("SELECT type FROM customer_addresses WHERE id = {$r10lAddrId}")->fetchColumn();
        assert_true($r10lRegOk && $r10lAddrId > 0 && $r10lAddrType() === 'shipping', 'R10 address: an address saved without type is a shipping address (was a 500)');
        Customers::updateAddress((int)$r10lCustomer, $r10lAddrId, ['type' => 'billing']);
        Customers::updateAddress((int)$r10lCustomer, $r10lAddrId, ['city' => 'Kosice']);
        assert_equals('billing', $r10lAddrType(), 'R10 address: an update without type keeps the stored type (was a 500)');
        $r10lChecked = static fn(array $posted, int $id = 0): ?array => Customers::updateCheckedAddress((int)$r10lCustomer, $id ?: $r10lAddrId, $posted);
        assert_equals([null, ['city'], ['type'], ['country']],
            [$r10lChecked(['city' => 'X'], 999999), array_keys($r10lChecked(['city' => ' '])), array_keys($r10lChecked(['type' => 'office'])), array_keys($r10lChecked(['country' => 'ZZ']))],
            'R10 address: the account edit checks the address like a new one (empty city, unknown type, undelivered country; foreign id = null), it saved anything');
        $r10lCity = static fn(): string => (string)$pdo->query("SELECT city FROM customer_addresses WHERE id = {$r10lAddrId}")->fetchColumn();
        $r10lCityAfterRefused = $r10lCity();
        assert_equals(['Kosice', [], 'Presov', 'billing'], [$r10lCityAfterRefused, $r10lChecked(['city' => 'Presov']), $r10lCity(), $r10lAddrType()],
            'R10 address: the refused edits changed nothing, a valid edit is saved');
        $r10lAccountSrc = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Storefront/Account.php');
        assert_true((bool)preg_match("/action === 'address_update'.*?Customers::updateCheckedAddress\(.*?http_response_code\(\\\$addressErrors === null \? 404 : 400\).*?action === 'address_delete'/s", $r10lAccountSrc),
            'R10 address: the account edit answers 400 for a refused address and 404 for a foreign id');
    } catch (Throwable $e) {
        $r10lFail('addresses', $e);
    }
} finally {
    $r10lCfgProp->setValue(null, $r10lCfgBefore);
    Security::setRolesForTesting(null);
    $_SESSION = $r10lSession;
    $_POST = $r10lPost;
    foreach (['REQUEST_METHOD', 'REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR'] as $r10lKey) {
        if (array_key_exists($r10lKey, $r10lServer)) $_SERVER[$r10lKey] = $r10lServer[$r10lKey]; else unset($_SERVER[$r10lKey]);
    }
    $pdo->exec("DELETE FROM customer_addresses WHERE customer_id IN (SELECT id FROM customers WHERE email = 'r10l-addr@example.com')");
    $pdo->exec("DELETE FROM customers WHERE email = 'r10l-addr@example.com'");
    $pdo->exec('DELETE FROM digital_entitlements WHERE order_id IN (98254, 98255)');
    $pdo->exec("DELETE FROM digital_assets WHERE sku = 'R10L-EBOOK'");
    $pdo->exec('DELETE FROM withdrawal_requests WHERE order_id = 98253');
    $pdo->exec('DELETE FROM order_items WHERE order_id IN (98253, 98254, 98255)');
    $pdo->exec('DELETE FROM orders WHERE id IN (98253, 98254, 98255)');
    $pdo->exec('DELETE FROM product_specifications WHERE product_id = 98251');
    $pdo->exec('DELETE FROM products WHERE id IN (98251, 98252)');
    $pdo->exec("DELETE FROM category_attributes WHERE attribute_id IN (SELECT id FROM attribute_definitions WHERE slug = 'r10l-weight')");
    $pdo->exec("DELETE FROM attribute_definitions WHERE slug = 'r10l-weight'");
    $pdo->exec("DELETE FROM categories WHERE slug IN ('r10l-home', 'r10l-other')");
}
