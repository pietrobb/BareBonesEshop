<?php

// TEST: Category translations — categories.translations JSON + I18n::category
// ============================================================
echo "\n--- Test: Category translations (DB JSON + I18n::category) ---\n";

// Translations live only in categories.translations; the bundled auto-parts dictionary left the core (CR-A).
$pdo->exec("INSERT INTO categories (slug, name, path, translations) VALUES ('test-garden', 'Garden', 'test-garden', '{\"sk\":{\"name\":\"Záhrada\"},\"de\":{\"name\":\"Garten\"}}')");
$testCatId = (int)$pdo->lastInsertId();
assert_equals('Kitchen Tools', Bootstrap::categoryName('Kitchen Tools', 'sk'), 'CR-A: untranslated category keeps its base name (no auto-parts fallback)');

// I18n::category reads from translations JSON
$catRow = $pdo->query("SELECT * FROM categories WHERE id = {$testCatId}")->fetch();
I18n::setLocale('sk');
$trSk = I18n::category($catRow);
assert_equals('Záhrada', $trSk['name'], 'CatI18n: I18n::category returns SK translated name');

I18n::setLocale('de');
$trDe = I18n::category($catRow);
assert_equals('Garten', $trDe['name'], 'CatI18n: I18n::category returns DE translated name');

I18n::setLocale('en');
$trEn = I18n::category($catRow);
assert_equals('Garden', $trEn['name'], 'CatI18n: I18n::category keeps base name for EN (no translation entry)');

// ============================================================
// TEST: Customer Accounts (register, login, reset, addresses, orders)
// ============================================================
echo "\n--- Test: Customer Accounts ---\n";

// Customer tables for test DB (mirror of schema-build.php)
$pdo->exec("CREATE TABLE IF NOT EXISTS customers (
    id INTEGER PRIMARY KEY, email TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL,
    name TEXT NOT NULL DEFAULT '', phone TEXT DEFAULT '', locale TEXT NOT NULL DEFAULT 'en',
    status TEXT NOT NULL DEFAULT 'active', email_verified_at TEXT DEFAULT NULL,
    last_login_at TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now'))
)");
$catalogPricingB2bMigration->up($pdo, true);

$pdo->exec("CREATE TABLE IF NOT EXISTS customer_addresses (
    id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL,
    type TEXT NOT NULL DEFAULT 'shipping', is_default INTEGER NOT NULL DEFAULT 0,
    name TEXT NOT NULL DEFAULT '', company TEXT DEFAULT '', vat_id TEXT DEFAULT '',
    street TEXT NOT NULL DEFAULT '', city TEXT NOT NULL DEFAULT '',
    zip TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '', phone TEXT DEFAULT '',
    created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL,
    token TEXT UNIQUE NOT NULL, expires_at TEXT NOT NULL, used_at TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
)");
try { $pdo->exec("ALTER TABLE orders ADD COLUMN customer_id INTEGER DEFAULT NULL"); } catch (\PDOException $e) {}

// --- Register ---
[$ok, $aliceId, $err] = Customers::register('alice@test.local', 'Passw0rd!', 'Alice', ['locale' => 'sk']);
assert_true($ok === true && $aliceId > 0, 'Customer: register returns [true, id, null]');
assert_true($err === null, 'Customer: register error is null on success');

// Register duplicate email
[$ok2, $id2, $err2] = Customers::register('alice@test.local', 'Another1!', 'Bob');
assert_true($ok2 === false && $id2 === null && $err2 === 'email_taken', 'Customer: duplicate email rejected');

// Register weak password
[$ok3, , $err3] = Customers::register('weak@test.local', '123', 'Weak');
assert_true($ok3 === false && $err3 === 'weak_password', 'Customer: weak password (<8 chars) rejected');

// Register invalid email
[$ok4, , $err4] = Customers::register('not-an-email', 'Passw0rd!', 'X');
assert_true($ok4 === false && $err4 === 'invalid_email', 'Customer: invalid email rejected');

// --- byId / byEmail ---
$row = Customers::byId($aliceId);
assert_true(is_array($row) && $row['email'] === 'alice@test.local', 'Customer: byId returns row');
assert_true(!isset($row['password_hash']), 'Customer: byId does NOT expose password_hash');
assert_equals('sk', $row['locale'], 'Customer: register stored locale from extras');

// --- Login success ---
[$lok, $lrow, $lerr] = Customers::login('alice@test.local', 'Passw0rd!');
assert_true($lok === true && $lrow['id'] === $aliceId, 'Customer: login success returns row');
assert_true(!isset($lrow['password_hash']), 'Customer: login return row strips password_hash');
assert_true(Customers::isLoggedIn(), 'G4: fresh customer session is accepted');
$_SESSION['customer_last_seen'] = time() - 1801;
assert_true(!Customers::isLoggedIn(), 'G4: idle customer session expires after 30 minutes');
[$lok, $lrow, $lerr] = Customers::login('alice@test.local', 'Passw0rd!');
assert_true($lok, 'G4: customer can log in again after idle expiry');

// Login wrong password
[$lok2, , $lerr2] = Customers::login('alice@test.local', 'Wrong!Pw1');
assert_true($lok2 === false && $lerr2 === 'invalid_credentials', 'Customer: wrong password rejected');

// Login non-existent email
[$lok3, , $lerr3] = Customers::login('ghost@test.local', 'Passw0rd!');
assert_true($lok3 === false && $lerr3 === 'invalid_credentials', 'Customer: non-existent email rejected');
$pdo->prepare("UPDATE customers SET status = 'blocked' WHERE id = ?")->execute([$aliceId]);
assert_true(!Customers::isLoggedIn(), 'G4: blocking an account invalidates its existing session');
[$blockedLogin, , $blockedErr] = Customers::login('alice@test.local', 'Passw0rd!');
assert_true(!$blockedLogin && $blockedErr === 'invalid_credentials', 'G4: blocked account uses the generic login error');
$pdo->prepare("UPDATE customers SET status = 'active' WHERE id = ?")->execute([$aliceId]);
[$activeAgain] = Customers::login('alice@test.local', 'Passw0rd!');
assert_true($activeAgain, 'G4: restored active account can authenticate again');

// --- Addresses ---
$addrId = Customers::addAddress($aliceId, [
    'type' => 'shipping', 'is_default' => 1,
    'name' => 'Alice Doe', 'street' => 'Main 1', 'city' => 'Bratislava',
    'zip' => '81101', 'country' => 'sk', 'phone' => '+421900000000',
]);
assert_true($addrId > 0, 'Customer: addAddress returns new id');
$addrs = Customers::addresses($aliceId);
assert_equals(1, count($addrs), 'Customer: 1 address listed');
assert_equals('SK', $addrs[0]['country'], 'Customer: country uppercased');
assert_equals(1, (int)$addrs[0]['is_default'], 'Customer: first address is default');

// Second shipping address with is_default=1 → previous unset
$addrId2 = Customers::addAddress($aliceId, [
    'type' => 'shipping', 'is_default' => 1,
    'name' => 'Alice Doe', 'street' => 'Second 2', 'city' => 'Kosice',
    'zip' => '04001', 'country' => 'SK',
]);
$shippingAddrs = Customers::addresses($aliceId, 'shipping');
$defaults = array_filter($shippingAddrs, fn($a) => (int)$a['is_default'] === 1);
assert_equals(1, count($defaults), 'Customer: only one default shipping address');
assert_equals($addrId2, (int)array_values($defaults)[0]['id'], 'Customer: newest addr is default');

// deleteAddress ownership
$ok = Customers::deleteAddress($aliceId, $addrId);
assert_true($ok === true, 'Customer: deleteAddress succeeds for owner');
$after = Customers::addresses($aliceId);
assert_equals(1, count($after), 'Customer: addresses reduced to 1 after delete');

// deleteAddress foreign-owner fails
$okForeign = Customers::deleteAddress(99999, $addrId2);
assert_true($okForeign === false, 'Customer: deleteAddress fails for non-owner');

// --- update profile ---
$upd = Customers::update($aliceId, ['name' => 'Alice Renamed', 'phone' => '+421911111111']);
assert_true($upd === true, 'Customer: update(name, phone) returns true');
$reloaded = Customers::byId($aliceId);
assert_equals('Alice Renamed', $reloaded['name'], 'Customer: name updated');

// --- changePassword ---
$preChangeSessionHash = (string)($_SESSION['customer_auth_hash'] ?? '');
[$cok, $cerr] = Customers::changePassword($aliceId, 'Passw0rd!', 'NewPassw0rd!');
assert_true($cok === true && $cerr === null, 'Customer: changePassword with correct old pw succeeds');
assert_true((string)($_SESSION['customer_auth_hash'] ?? '') !== $preChangeSessionHash, 'G4: password change rotates the session credential fingerprint');
$_SESSION['customer_auth_hash'] = $preChangeSessionHash;
assert_true(!Customers::isLoggedIn(), 'G4: a session bearing the previous password fingerprint is rejected');
[$lok4, , ] = Customers::login('alice@test.local', 'NewPassw0rd!');
assert_true($lok4 === true, 'Customer: login works with new password');
[$lok5, , ] = Customers::login('alice@test.local', 'Passw0rd!');
assert_true($lok5 === false, 'Customer: old password no longer works');

// changePassword wrong old pw
[$cok2, $cerr2] = Customers::changePassword($aliceId, 'Wrong!Old1', 'Another1!');
assert_true($cok2 === false && $cerr2 === 'invalid_current_password', 'Customer: wrong old pw rejected');

// --- Password reset flow ---
$tok = Customers::startPasswordReset('alice@test.local');
assert_true(is_string($tok) && strlen($tok) >= 32, 'Customer: startPasswordReset returns 32+ char token');
$replacementTok = Customers::startPasswordReset('alice@test.local');
assert_true(Customers::verifyResetToken($tok) === null, 'G4: issuing a new reset token invalidates the previous token');
$tok = $replacementTok;
$noTok = Customers::startPasswordReset('ghost@test.local');
assert_true($noTok === null, 'Customer: startPasswordReset for unknown email returns null');

$vid = Customers::verifyResetToken($tok);
assert_equals($aliceId, $vid, 'Customer: verifyResetToken returns customer id for valid token');
$badId = Customers::verifyResetToken('invalid-token-123');
assert_true($badId === null, 'Customer: verifyResetToken returns null for unknown token');

[$rok, $rerr] = Customers::completePasswordReset($tok, 'FromReset1!');
assert_true($rok === true && $rerr === null, 'Customer: completePasswordReset succeeds');
[$lok6, , ] = Customers::login('alice@test.local', 'FromReset1!');
assert_true($lok6 === true, 'Customer: login works with reset password');

// Reuse token — marked used
[$rok2, $rerr2] = Customers::completePasswordReset($tok, 'Another1!');
assert_true($rok2 === false && $rerr2 === 'invalid_or_expired_token', 'Customer: reset token cannot be reused');

// --- P4 security: reset token stored hashed (sha256), not plaintext (#12) ---
$freshTok = Customers::startPasswordReset('alice@test.local');
$storedTok = $pdo->query("SELECT token FROM password_reset_tokens ORDER BY id DESC LIMIT 1")->fetchColumn();
assert_true($storedTok === hash('sha256', $freshTok), 'Security: reset token stored as sha256 hash in DB');
assert_true($storedTok !== $freshTok, 'Security: plaintext reset token NOT stored in DB');
assert_equals($aliceId, Customers::verifyResetToken($freshTok), 'Security: verifyResetToken accepts plaintext, matches stored hash');

// --- Orders scoping ---
$pdo->exec("INSERT INTO orders (order_number, status, customer_name, customer_email, payment_method, subtotal, total, customer_id) VALUES ('C-100', 'paid', 'Alice Renamed', 'alice@test.local', 'cod', 1000, 1000, {$aliceId})");
$pdo->exec("INSERT INTO orders (order_number, status, customer_name, customer_email, payment_method, subtotal, total, customer_id) VALUES ('C-101', 'pending', 'Alice Renamed', 'alice@test.local', 'cod', 500, 500, {$aliceId})");
$pdo->exec("INSERT INTO orders (order_number, status, customer_name, customer_email, payment_method, subtotal, total, customer_id) VALUES ('GUEST-1', 'paid', 'Guest', 'guest@test.local', 'cod', 100, 100, NULL)");

$myOrders = Customers::orders($aliceId);
assert_equals(2, count($myOrders), 'Customer: orders() returns only own rows');
$nums = array_column($myOrders, 'order_number');
assert_true(in_array('C-100', $nums, true) && in_array('C-101', $nums, true), 'Customer: both owned orders listed');
assert_true(!in_array('GUEST-1', $nums, true), 'Customer: guest order NOT leaked to customer');
assert_equals(2, Customers::orderCount($aliceId), 'Customer: orderCount returns 2');

// Fetch specific order by customer scope
$o100 = Customers::order($aliceId, (int)$pdo->query("SELECT id FROM orders WHERE order_number='C-100'")->fetchColumn());
assert_true(is_array($o100) && $o100['order_number'] === 'C-100', 'Customer: order() returns own order');

// Scoped access to guest order returns null
$guestOrderId = (int)$pdo->query("SELECT id FROM orders WHERE order_number='GUEST-1'")->fetchColumn();
$blocked = Customers::order($aliceId, $guestOrderId);
assert_true($blocked === null, 'Customer: order() blocks access to guest/other-customer order');

// ============================================================
// TEST: G6 catalog, price lists, promotions and B2B rules
// ============================================================
echo "\n--- Test: G6 Catalog / Pricing / B2B ---\n";
Bootstrap::initStoreContext('/', '', 'default');
$g6Product = $pdo->query("SELECT * FROM products WHERE store_id = 'default' AND status = 'active' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert_true(is_array($g6Product), 'G6: active product fixture exists');
$g6ProductId = (int)$g6Product['id'];
$pdo->exec("INSERT INTO categories (slug, name, path, depth, store_id) VALUES ('g6-secondary', 'G6 Secondary', 'g6-secondary', 0, 'default')");
$g6SecondaryCategory = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO product_categories (product_id, category_id, is_primary, sort_order) VALUES (?, ?, 0, 10)")
    ->execute([$g6ProductId, $g6SecondaryCategory]);
$g6CategoryResult = Products::byCategory($g6SecondaryCategory);
assert_true(in_array($g6ProductId, array_map('intval', array_column($g6CategoryResult['products'], 'id')), true), 'G6: additional category participates in catalog listings');
assert_true(in_array($g6SecondaryCategory, Products::categoryIds($g6ProductId), true), 'G6: product exposes all category assignments');

$pdo->exec("INSERT INTO brands (store_id, code, name, manufacturer_name) VALUES ('default', 'g6-brand', 'G6 Brand', 'G6 Manufacturing s.r.o.')");
$g6BrandId = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE products SET brand_id=?, gtin='8580000000001', mpn='G6-MPN', cost_price=700, length_mm=300, width_mm=200, height_mm=100 WHERE id=?")
    ->execute([$g6BrandId, $g6ProductId]);
$g6CatalogRow = Products::byId($g6ProductId);
assert_equals('8580000000001', $g6CatalogRow['gtin'], 'G6: GTIN is a first-class catalog field');
assert_equals('G6-MPN', $g6CatalogRow['mpn'], 'G6: manufacturer part number is persisted');
assert_equals(700, (int)$g6CatalogRow['cost_price'], 'G6: cost basis remains integer cents');
$g6Margin = Products::margin(1234, 700);
assert_equals(534, $g6Margin['amount'], 'G6: margin amount remains deterministic integer cents');
assert_equals(4327, $g6Margin['bps'], 'G6: margin ratio uses deterministic basis points');

$pdo->exec("INSERT INTO customer_groups (store_id, code, name, discount_bps) VALUES ('default', 'trade', 'Trade', 500)");
$g6GroupId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO companies (store_id, code, legal_name, registration_number, vat_id, vat_country, vat_validated_at, approval_status, credit_limit, payment_terms_days, invoice_purchase)
    VALUES ('default', 'acme', 'ACME Trade s.r.o.', '12345678', 'SK1234567890', 'SK', datetime('now'), 'approved', 5000, 30, 1)");
$g6CompanyId = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE customers SET customer_group_id=?, company_id=? WHERE id=?")
    ->execute([$g6GroupId, $g6CompanyId, $aliceId]);
$g6Profile = Customers::commerceProfile($aliceId);
assert_equals('trade', $g6Profile['customer_group_code'], 'G6: customer resolves its store-scoped group');
assert_equals('approved', $g6Profile['approval_status'], 'G6: company approval is explicit');
assert_equals(30, (int)$g6Profile['payment_terms_days'], 'G6: payment terms are explicit days');
StoreContext::activate($deContext);
assert_true(Customers::commerceProfile($aliceId) === null, 'G6: company and customer-group profile cannot cross stores');
[$g6IsolatedRegistered, $g6IsolatedCustomerId] = Customers::register('isolated-g6@test.local', 'Passw0rd!', 'Isolated G6');
assert_true($g6IsolatedRegistered && $g6IsolatedCustomerId > 0, 'G6: customer registration succeeds in a non-default store');
$g6RegisteredStore = $pdo->prepare('SELECT store_id FROM customers WHERE id = ?');
$g6RegisteredStore->execute([$g6IsolatedCustomerId]);
assert_equals('isolated', $g6RegisteredStore->fetchColumn(), 'G6: registration persists the active store identity');
Bootstrap::initStoreContext('/', '', 'default');

$pdo->prepare("INSERT INTO price_lists (store_id, code, name, currency, customer_group_id, priority) VALUES ('default', 'trade-eur', 'Trade EUR', 'eur', ?, 100)")
    ->execute([$g6GroupId]);
$g6PriceListId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO price_list_prices (price_list_id, product_id, min_qty, price, compare_price) VALUES (?, ?, 1, 1234, 1500)")
    ->execute([$g6PriceListId, $g6ProductId]);
$g6Priced = Pricing::applyPriceList($g6CatalogRow, null, 1, $aliceId, '2026-08-01 12:00:00');
assert_equals(1234, (int)$g6Priced['price'], 'G6: store/currency/customer-group price list resolves integer cents');
assert_equals('trade-eur', $g6Priced['price_list_snapshot']['code'], 'G6: selected price-list code is snapshotted');
$g6GuestPrice = Pricing::applyPriceList($g6CatalogRow, null, 1, null, '2026-08-01 12:00:00');
assert_equals((int)$g6CatalogRow['price'], (int)$g6GuestPrice['price'], 'G6: group price list does not leak to guests');

$pdo->prepare("INSERT INTO promotions (store_id, code, name, scope, priority, stacking, conditions, actions)
    VALUES ('default', 'trade-ten', 'Trade ten percent', 'catalog', 10, 'exclusive', ?, ?)")
    ->execute([
        json_encode(['product_ids' => [$g6ProductId], 'customer_group_ids' => [$g6GroupId]]),
        json_encode(['type' => 'discount_percent', 'bps' => 1000]),
    ]);
$g6Promoted = Promotions::applyToProduct($g6Priced, 1, $aliceId, '2026-08-01 12:00:00');
assert_equals(1111, (int)$g6Promoted['price'], 'G6: promotion basis points round deterministically in cents');
assert_equals('trade-ten', $g6Promoted['promotion_snapshot']['applied'][0]['code'], 'G6: applied promotion is snapshotted');
$pdo->prepare("INSERT INTO variants (product_id, sku, name, stock, price_delta, media, weight_g, length_mm, width_mm, height_mm, availability)
    VALUES (?, 'G6-VARIANT', 'G6 Variant', 5, 0, '[\"/uploads/g6.webp\"]', 900, 400, 300, 200, 'active')")
    ->execute([$g6ProductId]);
$g6VariantId = (int)$pdo->lastInsertId();
$previousCart = $_SESSION['cart'] ?? [];
$_SESSION['customer_id'] = $aliceId;
$_SESSION['cart'] = ['v_' . $g6VariantId => ['product_id' => $g6ProductId, 'variant_id' => $g6VariantId, 'qty' => 1]];
$g6VariantCart = Cart::enriched();
assert_equals(900, (int)$g6VariantCart['items'][0]['weight_g'], 'G6: variant weight overrides product logistics');
assert_equals(400, (int)$g6VariantCart['items'][0]['length_mm'], 'G6: variant dimensions are resolved into checkout');
assert_equals('trade-eur', $g6VariantCart['items'][0]['attrs']['_pricing']['price_list']['code'], 'G6: cart line carries its price-list snapshot');
$_SESSION['cart'] = $previousCart;

foreach ([
    ['g6-free-shipping', 20, 'stack', ['min_subtotal' => 1000], ['type' => 'free_shipping']],
    ['g6-buy-x-get-y', 30, 'stack', ['buy_product_id' => $g6ProductId, 'buy_qty' => 2], ['type' => 'buy_x_get_y', 'product_id' => $g6ProductId, 'qty' => 1]],
    ['g6-gift', 40, 'stack', ['min_subtotal' => 1000], ['type' => 'gift', 'product_id' => $g6ProductId, 'qty' => 1]],
] as [$code, $priority, $stacking, $conditions, $actions]) {
    $stmt = $pdo->prepare("INSERT INTO promotions (store_id, code, name, scope, priority, stacking, conditions, actions) VALUES ('default', ?, ?, 'cart', ?, ?, ?, ?)");
    $stmt->execute([$code, $code, $priority, $stacking, json_encode($conditions), json_encode($actions)]);
}
$g6CartItems = [['product_id' => $g6ProductId, 'variant_id' => null, 'qty' => 3, 'price' => 1111]];
$g6CartActions = Promotions::cartActions($g6CartItems, 3333, $aliceId, '2026-08-01 12:00:00');
assert_true(in_array('free_shipping', array_column($g6CartActions, 'action'), true), 'G6: automatic free-shipping promotion resolves');
$g6Bxgy = array_values(array_filter($g6CartActions, static fn(array $rule): bool => $rule['action'] === 'buy_x_get_y'))[0] ?? [];
assert_equals(1111, (int)($g6Bxgy['discount'] ?? 0), 'G6: buy 2 get 1 of the same product needs 3 units in cart and discounts one');
$g6Gift = array_values(array_filter($g6CartActions, static fn(array $rule): bool => $rule['action'] === 'gift'))[0] ?? [];
assert_equals($g6ProductId, (int)($g6Gift['gift']['product_id'] ?? 0), 'G6: gift entitlement is explicit without silently mutating stock');

$invoiceQuote = PaymentService::selectMethod('invoice', 'SK', 'eur', 3000, 'gls', $aliceId);
assert_true($invoiceQuote !== null, 'G6: approved company can purchase on invoice within credit limit');
assert_equals(30, (int)$invoiceQuote['payment_terms_days'], 'G6: invoice quote freezes payment terms');
assert_true(PaymentService::selectMethod('invoice', 'SK', 'eur', 4000, 'gls', $aliceId) === null, 'G6: outstanding exposure plus order amount enforces credit limit');
$pdo->prepare("UPDATE companies SET approval_status='pending' WHERE id=?")->execute([$g6CompanyId]);
assert_true(PaymentService::selectMethod('invoice', 'SK', 'eur', 100, 'gls', $aliceId) === null, 'G6: unapproved company cannot purchase on invoice');
$pdo->prepare("UPDATE companies SET approval_status='approved' WHERE id=?")->execute([$g6CompanyId]);
assert_true(PaymentService::selectMethod('invoice', 'SK', 'eur', 100, 'gls', null) === null, 'G6: guest cannot purchase on invoice');

// ============================================================
// TEST: Product Reviews (P7.1)
// ============================================================
echo "\n--- Test: Product Reviews ---\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS product_reviews (
    id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL, customer_id INTEGER DEFAULT NULL,
    author_name TEXT NOT NULL DEFAULT '', rating INTEGER NOT NULL,
    title TEXT NOT NULL DEFAULT '', body TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
)");

// --- submit validation ---
[$rok, $rid, $rerr] = Reviews::submit(1, ['rating' => 5, 'author_name' => 'Alice', 'title' => 'Great', 'body' => 'Loved it']);
assert_true($rok === true && $rid > 0 && $rerr === null, 'Reviews: valid submit returns [true, id, null]');

[$rok2, , $rerr2] = Reviews::submit(1, ['rating' => 6, 'author_name' => 'A', 'body' => 'too high']);
assert_true($rok2 === false && $rerr2 === 'invalid_rating', 'Reviews: rating > 5 rejected');

[$rok3, , $rerr3] = Reviews::submit(1, ['rating' => 0, 'author_name' => 'A', 'body' => 'too low']);
assert_true($rok3 === false && $rerr3 === 'invalid_rating', 'Reviews: rating < 1 rejected');

[$rok4, , $rerr4] = Reviews::submit(1, ['rating' => 4, 'author_name' => 'A', 'body' => 'ab']);
assert_true($rok4 === false && $rerr4 === 'body_too_short', 'Reviews: body < 3 chars rejected');

[$rok5, , $rerr5] = Reviews::submit(1, ['rating' => 4, 'author_name' => '', 'body' => 'no author']);
assert_true($rok5 === false && $rerr5 === 'author_required', 'Reviews: empty author rejected');

[$rok6, , $rerr6] = Reviews::submit(0, ['rating' => 4, 'author_name' => 'A', 'body' => 'no product']);
assert_true($rok6 === false && $rerr6 === 'invalid_product', 'Reviews: invalid product_id rejected');

// --- pending / moderation ---
$pendingBefore = Reviews::pending();
assert_true(count($pendingBefore) >= 1, 'Reviews: submitted review appears in pending queue');

$one = Reviews::byId($rid);
assert_true(is_array($one) && $one['status'] === 'pending', 'Reviews: new review starts as pending');

// approved() excludes pending
assert_equals(0, count(Reviews::approved(1)), 'Reviews: approved() empty while review is pending');

// aggregate over approved only — still 0
$aggPending = Reviews::aggregate(1);
assert_equals(0, $aggPending['count'], 'Reviews: aggregate count 0 while pending');
assert_equals(0.0, $aggPending['avg'], 'Reviews: aggregate avg 0.0 while pending');

// --- approve and verify visibility ---
assert_true(Reviews::setStatus($rid, 'approved'), 'Reviews: setStatus approved returns true');
$approved = Reviews::approved(1);
assert_equals(1, count($approved), 'Reviews: approved() lists the approved review');
assert_equals(5, (int)$approved[0]['rating'], 'Reviews: approved review keeps its rating');

// add a second approved review (rating 3) → avg = 4.0
[$r2ok, $r2id] = Reviews::submit(1, ['rating' => 3, 'author_name' => 'Bob', 'body' => 'It was ok']);
Reviews::setStatus($r2id, 'approved');
$agg = Reviews::aggregate(1);
assert_equals(2, $agg['count'], 'Reviews: aggregate counts both approved');
assert_equals(4.0, $agg['avg'], 'Reviews: aggregate avg = (5+3)/2 = 4.0');

// rejected review is excluded from aggregate + approved
[$r3ok, $r3id] = Reviews::submit(1, ['rating' => 1, 'author_name' => 'Troll', 'body' => 'spam spam']);
Reviews::setStatus($r3id, 'rejected');
$agg2 = Reviews::aggregate(1);
assert_equals(2, $agg2['count'], 'Reviews: rejected review excluded from aggregate');
assert_equals(0, count(array_filter(Reviews::approved(1), fn($r) => (int)$r['id'] === $r3id)), 'Reviews: rejected review not in approved()');

// setStatus rejects unknown status + missing id
assert_true(Reviews::setStatus($rid, 'bogus') === false, 'Reviews: unknown status rejected');
assert_true(Reviews::setStatus(999999, 'approved') === false, 'Reviews: setStatus on missing id returns false');

// per-product isolation: product 2 has no reviews
assert_equals(0, Reviews::aggregate(2)['count'], 'Reviews: aggregate isolated per product');

// --- P7.2: api.php review_submit endpoint ---
if (!defined('BB_TEST_LOAD_API_ONLY')) {
    define('BB_TEST_LOAD_API_ONLY', true);
    require_once TEST_CLI_DIR . '/../api.php';
}
// A real product is needed (handler verifies Products::byId). Product 501/502 exist from earlier seed;
// fall back to creating one if not present in this DB position.
$apiProdId = (int)($pdo->query("SELECT id FROM products LIMIT 1")->fetchColumn() ?: 0);
assert_true($apiProdId > 0, 'Reviews API: a product exists to attach a review to');

$submitReview = function (array $body, ?int $customerId = null) use ($apiProdId) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SESSION['csrf_token'] = 'rv-test-token';
    if ($customerId !== null) { $_SESSION['customer_id'] = $customerId; }
    else { unset($_SESSION['customer_id']); }
    $_POST = array_merge(['csrf_token' => 'rv-test-token', 'product_id' => $apiProdId], $body);
    $res = apiReviewSubmit();
    $_POST = [];
    unset($_SESSION['customer_id']);
    return $res;
};

// Guest submit → ok, pending
$ar = $submitReview(['rating' => 5, 'author_name' => 'API Guest', 'body' => 'Solid product']);
assert_true(!empty($ar['ok']) && ($ar['status'] ?? '') === 'pending', 'Reviews API: guest submit returns ok + pending');
$arRow = Reviews::byId((int)$ar['id']);
assert_true($arRow['status'] === 'pending' && $arRow['customer_id'] === null, 'Reviews API: guest review stored pending, no customer_id');

// Validation error bubbles up (rating out of range)
$arBad = $submitReview(['rating' => 9, 'author_name' => 'X', 'body' => 'nope']);
assert_true(($arBad['ok'] ?? null) === false && $arBad['error'] === 'invalid_rating', 'Reviews API: invalid rating rejected');

// Missing author for guest
$arNoAuthor = $submitReview(['rating' => 4, 'body' => 'no name given']);
assert_true(($arNoAuthor['ok'] ?? null) === false && $arNoAuthor['error'] === 'author_required', 'Reviews API: guest without author rejected');

// Invalid product id
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['csrf_token'] = 'rv-test-token';
$_POST = ['csrf_token' => 'rv-test-token', 'product_id' => 999999, 'rating' => 5, 'author_name' => 'Y', 'body' => 'ghost product'];
$arGhost = apiReviewSubmit();
$_POST = [];
assert_true(($arGhost['ok'] ?? null) === false && $arGhost['error'] === 'invalid_product', 'Reviews API: non-existent product rejected');

// Logged-in customer → review attributed + name taken from account
$cust = Customers::byId($aliceId);
$arCust = $submitReview(['rating' => 4, 'body' => 'as a registered customer'], $aliceId);
assert_true(!empty($arCust['ok']), 'Reviews API: logged-in customer submit ok');
$arCustRow = Reviews::byId((int)$arCust['id']);
assert_equals($aliceId, (int)$arCustRow['customer_id'], 'Reviews API: review attributed to logged-in customer');
assert_equals($cust['name'], $arCustRow['author_name'], 'Reviews API: author_name taken from customer account');

// CSRF rejection
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['csrf_token'] = 'rv-test-token';
$_POST = ['csrf_token' => 'WRONG', 'product_id' => $apiProdId, 'rating' => 5, 'author_name' => 'Z', 'body' => 'csrf attempt'];
$arCsrf = apiReviewSubmit();
$_POST = [];
assert_true(isset($arCsrf['error']), 'Reviews API: invalid CSRF token rejected');

// ============================================================
// TEST: Wishlist (P7.3)
// ============================================================
echo "\n--- Test: Wishlist ---\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS wishlist_items (
    id INTEGER PRIMARY KEY, owner_key TEXT NOT NULL, customer_id INTEGER DEFAULT NULL,
    product_id INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(owner_key, product_id)
)");


$wlProd = (int)($pdo->query("SELECT id FROM products LIMIT 1")->fetchColumn() ?: 0);
$wlProd2 = (int)($pdo->query("SELECT id FROM products ORDER BY id LIMIT 1 OFFSET 1")->fetchColumn() ?: 0);
assert_true($wlProd > 0, 'Wishlist: a product exists to save');

$guest = 's:wltest';
$cust  = 'c:7001';

// add + has + count (explicit owner key, no session dependency)
assert_true(Wishlist::add($wlProd, $guest), 'Wishlist: add returns true on new row');
assert_true(Wishlist::has($wlProd, $guest), 'Wishlist: has true after add');
assert_equals(1, Wishlist::count($guest), 'Wishlist: count 1 after add');

// add is idempotent — second add for same product returns false, count unchanged
assert_true(Wishlist::add($wlProd, $guest) === false, 'Wishlist: duplicate add returns false');
assert_equals(1, Wishlist::count($guest), 'Wishlist: count still 1 after duplicate add');

// invalid product id rejected
assert_true(Wishlist::add(0, $guest) === false, 'Wishlist: add invalid product_id rejected');

// per-owner isolation — customer owner does not see guest item
assert_true(Wishlist::has($wlProd, $cust) === false, 'Wishlist: per-owner isolation');
assert_equals(0, Wishlist::count($cust), 'Wishlist: other owner count 0');

// toggle: present → removed, absent → added
assert_equals('removed', Wishlist::toggle($wlProd, $guest), 'Wishlist: toggle removes when present');
assert_equals(0, Wishlist::count($guest), 'Wishlist: count 0 after toggle-remove');
assert_equals('added', Wishlist::toggle($wlProd, $guest), 'Wishlist: toggle adds when absent');
assert_equals(1, Wishlist::count($guest), 'Wishlist: count 1 after toggle-add');

// remove returns true when a row is deleted, false when nothing to delete
assert_true(Wishlist::remove($wlProd, $guest), 'Wishlist: remove returns true when present');
assert_true(Wishlist::remove($wlProd, $guest) === false, 'Wishlist: remove returns false when absent');

// ids ordering — newest first
Wishlist::add($wlProd, $guest);
if ($wlProd2 > 0) { Wishlist::add($wlProd2, $guest); }
$ids = Wishlist::ids($guest);
if ($wlProd2 > 0) {
    assert_equals($wlProd2, $ids[0] ?? 0, 'Wishlist: ids newest-first');
    assert_equals(2, count($ids), 'Wishlist: ids returns both');
}

// list() returns enriched, active products only
$listed = Wishlist::list($guest);
assert_true(count($listed) >= 1 && isset($listed[0]['name']), 'Wishlist: list returns enriched product rows');

// G3: listing enrichment preserves payloads with a constant query budget.
$g3PreviousCustomerId = $_SESSION['customer_id'] ?? null;
$g3Products = $pdo->query("SELECT * FROM products WHERE status = 'active' ORDER BY id LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
$g3LocalizedProducts = array_map([I18n::class, 'product'], $g3Products);
unset($_SESSION['customer_id']);
Db::init($pdo, 'sqlite');
$g3At = gmdate('Y-m-d H:i:s');
$g3CategoryIds = Products::categoryIdsMany($g3LocalizedProducts);
$g3Rules = Promotions::catalogRules($g3At);
$g3CommerceParity = function (?int $customerId) use ($g3LocalizedProducts, $g3CategoryIds, $g3Rules, $g3At): array {
    $profile = Customers::commerceProfile($customerId);
    $rows = Pricing::priceListRows($g3LocalizedProducts, $profile, 1, $g3At);
    $legacy = [];
    $batch = [];
    foreach ($g3LocalizedProducts as $product) {
        $productId = (int)$product['id'];
        $product['category_ids'] = Products::categoryIds($productId);
        $legacy[] = Promotions::applyToProduct(Pricing::applyPriceList($product, null, 1, $customerId, $g3At), 1, $customerId, $g3At);
        $product['category_ids'] = $g3CategoryIds[$productId] ?? [];
        $batch[] = Promotions::applyCatalogRules(
            Pricing::applyPriceListContext($product, $rows[$productId] ?? null, $profile),
            $g3Rules,
            $profile
        );
    }
    return [$legacy, $batch];
};
[$g3GuestLegacyCommerce, $g3GuestBatchCommerce] = $g3CommerceParity(null);
[$g3CustomerLegacyCommerce, $g3CustomerBatchCommerce] = $g3CommerceParity($aliceId);
assert_equals($g3GuestLegacyCommerce, $g3GuestBatchCommerce, 'G3 batch enrichment: guest category, price-list and promotion resolution preserve canonical behavior');
assert_equals($g3CustomerLegacyCommerce, $g3CustomerBatchCommerce, 'G3 batch enrichment: customer category, price-list and promotion resolution preserve canonical behavior');
$g3ExpectedGuest = array_map(fn(array $product): array => Products::enrich($product), $g3LocalizedProducts);
$_SESSION['customer_id'] = $aliceId;
$g3ExpectedCustomer = array_map(fn(array $product): array => Products::enrich($product), $g3LocalizedProducts);
unset($_SESSION['customer_id']);
$g3ExpectedWishlist = Wishlist::list($guest);

$g3Counter = new QueryCountingPDO('sqlite:' . $testDbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
Db::init($g3Counter, 'sqlite');
$g3Counter->resetQueryCount();
$g3GuestBatch = Products::enrichMany($g3LocalizedProducts);
$g3GuestQueries = $g3Counter->queryCount();
assert_equals($g3ExpectedGuest, $g3GuestBatch, 'G3 batch enrichment: guest payload and order match one-by-one enrichment');
assert_true($g3GuestQueries <= 4, "G3 query budget: guest listing uses at most 4 enrichment queries ({$g3GuestQueries})");

$_SESSION['customer_id'] = $aliceId;
$g3Counter->resetQueryCount();
$g3CustomerBatch = Products::enrichMany($g3LocalizedProducts);
$g3CustomerQueries = $g3Counter->queryCount();
assert_equals($g3ExpectedCustomer, $g3CustomerBatch, 'G3 batch enrichment: logged-in payload and customer pricing match one-by-one enrichment');
assert_true($g3CustomerQueries <= 5, "G3 query budget: logged-in listing uses at most 5 enrichment queries ({$g3CustomerQueries})");

unset($_SESSION['customer_id']);
$g3Counter->resetQueryCount();
$g3Wishlist = Wishlist::list($guest);
$g3WishlistQueries = $g3Counter->queryCount();
assert_equals($g3ExpectedWishlist, $g3Wishlist, 'G3 wishlist batch: payload and newest-first order are preserved');
assert_true($g3WishlistQueries <= 5, "G3 query budget: wishlist uses at most 5 queries ({$g3WishlistQueries})");
Db::init($pdo, 'sqlite');
if ($g3PreviousCustomerId === null) {
    unset($_SESSION['customer_id']);
} else {
    $_SESSION['customer_id'] = $g3PreviousCustomerId;
}

// merge: fold guest session wishlist into a customer wishlist
$mergeSess = 's:mergeme';
$mergeCust = 8002;
Wishlist::add($wlProd, $mergeSess);
$moved = Wishlist::merge($mergeSess, $mergeCust);
assert_equals(1, $moved, 'Wishlist: merge moved 1 row');
assert_true(Wishlist::has($wlProd, 'c:' . $mergeCust), 'Wishlist: merged item now under customer key');
assert_equals(0, Wishlist::count($mergeSess), 'Wishlist: session wishlist emptied after merge');

// --- P7.3: api.php wishlist_toggle / wishlist endpoints ---
// (api.php already loaded above for the reviews API tests)
$wlToggle = function (int $pid) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SESSION['csrf_token'] = 'wl-test-token';
    unset($_SESSION['customer_id']);
    $_POST = ['csrf_token' => 'wl-test-token', 'product_id' => $pid];
    $res = apiWishlistToggle();
    $_POST = [];
    return $res;
};

$wa1 = $wlToggle($wlProd);
assert_true(!empty($wa1['ok']) && $wa1['state'] === 'added' && $wa1['in_list'] === true, 'Wishlist API: toggle adds (state=added)');
$wa2 = $wlToggle($wlProd);
assert_true(!empty($wa2['ok']) && $wa2['state'] === 'removed' && $wa2['in_list'] === false, 'Wishlist API: toggle removes (state=removed)');

// invalid product rejected
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['csrf_token'] = 'wl-test-token';
$_POST = ['csrf_token' => 'wl-test-token', 'product_id' => 999999];
$waGhost = apiWishlistToggle();
$_POST = [];
assert_true(($waGhost['ok'] ?? null) === false && $waGhost['error'] === 'invalid_product', 'Wishlist API: invalid product rejected');

// CSRF rejection
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['csrf_token'] = 'wl-test-token';
$_POST = ['csrf_token' => 'WRONG', 'product_id' => $wlProd];
$waCsrf = apiWishlistToggle();
$_POST = [];
assert_true(isset($waCsrf['error']), 'Wishlist API: invalid CSRF token rejected');

// read endpoint reflects current owner's list
$wlToggle($wlProd); // add back
$waList = apiWishlist();
assert_true(!empty($waList['ok']) && in_array($wlProd, $waList['ids'], true), 'Wishlist API: wishlist read returns saved id');

// ============================================================
// TEST: RMA / Returns
// ============================================================
echo "\n--- Test: RMA / Returns ---\n";

// Seed stock and create a delivered order owned by a customer (alice).
$rmaProd = Products::bySku($testProduct['sku']) ?: $testProduct;
$rmaHasStock = (int)$pdo->query("SELECT COUNT(*) FROM warehouse_stock WHERE sku = " . $pdo->quote($rmaProd['sku']))->fetchColumn();
if ($rmaHasStock === 0) {
    $pdo->prepare("INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (:wh,:sku,100,0)")
        ->execute([':wh' => $whId, ':sku' => $rmaProd['sku']]);
} else {
    $pdo->prepare("UPDATE warehouse_stock SET qty = qty + 50 WHERE sku = :sku")->execute([':sku' => $rmaProd['sku']]);
}
$rmaCustomer = [
    'name' => 'Alice', 'email' => 'alice@example.com', 'phone' => '',
    'address' => ['street' => 'A 1', 'city' => 'BA', 'zip' => '81101', 'country' => 'SK'],
    'currency' => 'eur', 'shipping_cost' => 0, 'tax_amount' => 0,
    'customer_id' => $aliceId,
];
$rmaCart = [[
    'product_id' => $rmaProd['id'], 'variant_id' => null, 'qty' => 2,
    'price' => $rmaProd['price'], 'sku' => $rmaProd['sku'], 'name' => $rmaProd['name'], 'variant_name' => '',
]];
$rmaOrderId = Orders::create($rmaCart, $rmaCustomer, 'cod');
Fulfillment::shipOrder($rmaOrderId);
Orders::updateStatus($rmaOrderId, 'delivered');
$rmaItems = Orders::items($rmaOrderId);
$rmaOi1 = (int)$rmaItems[0]['id'];
assert_equals('delivered', Orders::byId($rmaOrderId)['status'], 'RMA: seed order is delivered');

// A second, still-pending order (not returnable)
$rmaPendingOrderId = Orders::create($rmaCart, $rmaCustomer, 'cod');

// Guards: invalid order
assert_equals('invalid_order', Rma::create(0, $aliceId, 'x', [$rmaOi1 => 1])[2], 'RMA: invalid order id rejected');
assert_equals('invalid_order', Rma::create(999999, $aliceId, 'x', [$rmaOi1 => 1])[2], 'RMA: non-existent order rejected');

// Guard: pending order not returnable
assert_equals('order_not_returnable', Rma::create($rmaPendingOrderId, $aliceId, 'x', [$rmaOi1 => 1])[2], 'RMA: non-delivered order rejected');

// Guard: ownership
assert_equals('not_owner', Rma::create($rmaOrderId, $aliceId + 99999, 'x', [$rmaOi1 => 1])[2], 'RMA: order owned by another customer rejected');

// Guard: reason required
assert_equals('reason_required', Rma::create($rmaOrderId, $aliceId, '   ', [$rmaOi1 => 1])[2], 'RMA: empty reason rejected');

// Guard: no items
assert_equals('no_items', Rma::create($rmaOrderId, $aliceId, 'damaged', [])[2], 'RMA: empty item set rejected');
assert_equals('no_items', Rma::create($rmaOrderId, $aliceId, 'damaged', [999999 => 1])[2], 'RMA: foreign order_item rejected → no_items');

// Valid first partial request.
[$rOk, $rId, $rErr] = Rma::create($rmaOrderId, $aliceId, 'Box arrived crushed', [$rmaOi1 => 1]);
assert_true($rOk === true && $rId > 0 && $rErr === null, 'RMA: valid create returns ok + id');
$rmaRow = Rma::byId($rId);
assert_equals('requested', $rmaRow['status'], 'RMA: new request lands as requested');
assert_equals($aliceId, (int)$rmaRow['customer_id'], 'RMA: request attributed to customer');
$rmaLineItems = Rma::items($rId);
assert_equals(1, count($rmaLineItems), 'RMA: one line item stored');
assert_equals(1, (int)$rmaLineItems[0]['quantity'], 'RMA: first partial quantity stored');

// A second partial RMA can remain open for the unclaimed quantity.
[$rOk2, $rId2, $rErr2] = Rma::create($rmaOrderId, $aliceId, 'second parcel', [$rmaOi1 => 99]);
assert_true($rOk2 === true && $rId2 > 0 && $rErr2 === null, 'RMA: second partial request is allowed');
assert_equals(1, (int)Rma::items($rId2)[0]['quantity'], 'RMA: second request is clamped to remaining quantity');
assert_equals('no_items', Rma::create($rmaOrderId, $aliceId, 'over claim', [$rmaOi1 => 1])[2], 'RMA: claimed quantities cannot exceed ordered quantity');

// openForOrder finds the open request
assert_true(Rma::openForOrder($rmaOrderId) !== null, 'RMA: openForOrder returns the open request');

// forCustomer lists it
$rmaCustList = Rma::forCustomer($aliceId);
assert_true(count($rmaCustList) >= 2 && (int)$rmaCustList[0]['id'] === $rId2, 'RMA: forCustomer returns all partial requests newest first');

// Transition guards
assert_true(Rma::setStatus($rId, 'refunded') === false, 'RMA: requested → refunded blocked (illegal jump)');
assert_true(Rma::setStatus($rId, 'approved') === true, 'RMA: requested → approved allowed');
assert_equals('approved', Rma::byId($rId)['status'], 'RMA: status now approved');
$label = Rma::recordLabel($rId, 'http-carrier', 'RET-001', 'label-rma-1', 'TRACK-RET-1', 'https://carrier.test/labels/RET-001.pdf');
assert_equals('RET-001', (string)$label['label_reference'], 'RMA: return label reference is audited');
assert_equals((int)$label['id'], (int)Rma::recordLabel($rId, 'http-carrier', 'RET-001', 'label-rma-1')['id'], 'RMA: return label creation is idempotent');
$labelConflict = false;
try { Rma::recordLabel($rId, 'http-carrier', 'RET-DIFFERENT', 'label-rma-1'); } catch (LogicException $e) { $labelConflict = true; }
assert_true($labelConflict, 'RMA: label idempotency key rejects changed input');
$rmaStockBeforeReceipt = (int)$pdo->query("SELECT SUM(qty) FROM warehouse_stock WHERE sku=" . $pdo->quote($rmaProd['sku']))->fetchColumn();
assert_true(Rma::receive($rId, [$rmaLineItems[0]['id'] => ['quantity' => 1, 'disposition' => 'restock']], 'receipt-rma-1', 'parcel in'), 'RMA: partial receipt with restock succeeds');
assert_true(Rma::receive($rId, [$rmaLineItems[0]['id'] => ['quantity' => 1, 'disposition' => 'restock']], 'receipt-rma-1', 'replay') === false, 'RMA: receipt replay is idempotent');
$receiptConflict = false;
try { Rma::receive($rId, [$rmaLineItems[0]['id'] => ['quantity' => 1, 'disposition' => 'discard']], 'receipt-rma-1'); }
catch (LogicException $e) { $receiptConflict = true; }
assert_true($receiptConflict, 'RMA: receipt idempotency key rejects changed input');
assert_equals($rmaStockBeforeReceipt + 1, (int)$pdo->query("SELECT SUM(qty) FROM warehouse_stock WHERE sku=" . $pdo->quote($rmaProd['sku']))->fetchColumn(), 'RMA: accepted restock returns exactly one shipped unit');
assert_equals('received', Rma::byId($rId)['status'], 'RMA: fully received partial request advances to received');
assert_equals('parcel in', (string)$pdo->query("SELECT note FROM return_receipts WHERE rma_id={$rId}")->fetchColumn(), 'RMA: receipt note persisted');
assert_true(Rma::setStatus($rId, 'refunded') === true, 'RMA: received → refunded allowed');
assert_true(Rma::setStatus($rId, 'approved') === false, 'RMA: refunded is terminal');

// Unknown id / status
assert_true(Rma::setStatus(999999, 'approved') === false, 'RMA: setStatus on unknown id false');
assert_true(Rma::setStatus($rId, 'bogus') === false, 'RMA: setStatus with unknown status false');

// The independent second partial request remains open and can choose a non-stock disposition plus exchange.
assert_true(Rma::openForOrder($rmaOrderId) !== null, 'RMA: another partial request remains open');
assert_true(Rma::setStatus($rId2, 'approved') === true, 'RMA: second partial request is approved independently');
$rmaLine2 = Rma::items($rId2)[0];
$rmaStockBeforeDiscard = (int)$pdo->query("SELECT SUM(qty) FROM warehouse_stock WHERE sku=" . $pdo->quote($rmaProd['sku']))->fetchColumn();
assert_true(Rma::receive($rId2, [$rmaLine2['id'] => ['quantity' => 1, 'disposition' => 'discard']], 'receipt-rma-2'), 'RMA: discard receipt succeeds');
assert_equals($rmaStockBeforeDiscard, (int)$pdo->query("SELECT SUM(qty) FROM warehouse_stock WHERE sku=" . $pdo->quote($rmaProd['sku']))->fetchColumn(), 'RMA: discard does not change on-hand stock');
$exchangeId = Rma::createExchange($rId2, (int)$rmaLine2['id'], 1, (int)$rmaProd['id'], null, 'exchange-rma-2');
assert_true($exchangeId > 0, 'RMA: received item can be reserved for an exchange');
$exchangeOverClaim = false;
try { Rma::createExchange($rId2, (int)$rmaLine2['id'], 1, (int)$rmaProd['id'], null, 'exchange-rma-2-over'); }
catch (LogicException $e) { $exchangeOverClaim = true; }
assert_true($exchangeOverClaim, 'RMA: exchange cannot reuse refunded or already exchanged quantity');
assert_true(Rma::setStatus($rId2, 'refunded') === true, 'RMA: exchanged request can be closed');
assert_true(Rma::openForOrder($rmaOrderId) === null, 'RMA: no open request after every partial request is terminal');

// Code review H5: both units came back through RMA (one restocked, one discarded) — the
// order-level return must not put them on stock a second time.
$rmaStockBeforeOrderReturn = (int)$pdo->query("SELECT SUM(qty) FROM warehouse_stock WHERE sku=" . $pdo->quote($rmaProd['sku']))->fetchColumn();
OrderWorkflow::transition($rmaOrderId, 'returned', 'H5 whole order returned after RMA receipts');
assert_equals($rmaStockBeforeOrderReturn, (int)$pdo->query("SELECT SUM(qty) FROM warehouse_stock WHERE sku=" . $pdo->quote($rmaProd['sku']))->fetchColumn(), 'H5: order return after RMA receipts does not restock the same units twice');

// H5 reverse order: the order-level return restocks first, a later RMA restock adds nothing.
$h5OrderId = Orders::create($rmaCart, $rmaCustomer, 'cod');
Fulfillment::shipOrder($h5OrderId);
Orders::updateStatus($h5OrderId, 'delivered');
$h5Stock = static fn(): int => (int)$pdo->query("SELECT SUM(qty) FROM warehouse_stock WHERE sku=" . $pdo->quote($rmaProd['sku']))->fetchColumn();
$h5Before = $h5Stock();
[, $h5RmaId] = Rma::create($h5OrderId, $aliceId, 'H5 reverse', [(int)Orders::items($h5OrderId)[0]['id'] => 2]);
Rma::setStatus($h5RmaId, 'approved');
OrderWorkflow::transition($h5OrderId, 'returned', 'H5 whole order returned first');
assert_equals($h5Before + 2, $h5Stock(), 'H5: order-level return restocks the shipped units once');
Rma::receive($h5RmaId, [(int)Rma::items($h5RmaId)[0]['id'] => ['quantity' => 2, 'disposition' => 'restock']], 'receipt-h5-reverse');
assert_equals($h5Before + 2, $h5Stock(), 'H5: RMA restock after the order-level return does not restock again');

// ============================================================
// TEST: Admin customer management (P7.5)
// ============================================================
echo "\n--- Test: Admin Customer Management ---\n";

// adminList returns the seeded customer (alice) and supports search
$allCust = Customers::adminList('', 100);
assert_true(count($allCust) >= 1, 'AdminCustomers: list returns customers');
$byEmail = Customers::adminList('alice@test.local', 50);
assert_true(count($byEmail) >= 1 && (int)$byEmail[0]['id'] === $aliceId, 'AdminCustomers: search by email finds alice');
$byName = Customers::adminList('Alice', 50);
assert_true(count($byName) >= 1, 'AdminCustomers: search by name matches');
$byId = Customers::adminList((string)$aliceId, 50);
assert_true(count($byId) >= 1 && (int)$byId[0]['id'] === $aliceId, 'AdminCustomers: numeric query matches id');
$noHit = Customers::adminList('no-such-person@nowhere.test', 50);
assert_equals(0, count($noHit), 'AdminCustomers: non-matching search returns empty');

// setStatus: block then reactivate
assert_equals('active', Customers::byId($aliceId)['status'], 'AdminCustomers: alice starts active');
assert_true(Customers::setStatus($aliceId, 'blocked') === true, 'AdminCustomers: block returns true');
assert_equals('blocked', Customers::byId($aliceId)['status'], 'AdminCustomers: status now blocked');
assert_true(Customers::setStatus($aliceId, 'active') === true, 'AdminCustomers: reactivate returns true');
assert_equals('active', Customers::byId($aliceId)['status'], 'AdminCustomers: status back to active');

// setStatus guards: invalid status, unknown id, anonymized terminal
assert_true(Customers::setStatus($aliceId, 'bogus') === false, 'AdminCustomers: invalid status rejected');
assert_true(Customers::setStatus(0, 'blocked') === false, 'AdminCustomers: invalid id rejected');
assert_true(Customers::setStatus(999999, 'blocked') === false, 'AdminCustomers: unknown customer rejected');
$pdo->prepare("UPDATE customers SET status = 'anonymized' WHERE id = ?")->execute([$aliceId]);
assert_true(Customers::setStatus($aliceId, 'active') === false, 'AdminCustomers: anonymized account cannot be reactivated');
$pdo->prepare("UPDATE customers SET status = 'active' WHERE id = ?")->execute([$aliceId]); // restore for later tests

// ============================================================
// TEST: Migrations Framework
// ============================================================
echo "\n--- Test: Migrations Framework ---\n";

assert_equals("DEFAULT ''", MigrationSql::defaultClause(true, ''), 'Migrate: SQLite text default keeps literal syntax');
assert_equals("DEFAULT ('')", MigrationSql::defaultClause(false, ''), 'Migrate: MySQL text default uses expression syntax');

$migDir = TEST_CLI_DIR . '/../db/_test_migrations';
$migLock = TEST_CLI_DIR . '/../db/.test-migrations.lock';
if (is_dir($migDir)) {
    foreach (glob($migDir . '/*') ?: [] as $f) @unlink($f);
    @rmdir($migDir);
}
mkdir($migDir, 0750, true);

// Use a separate in-memory DB so we can drop schema_migrations cleanly
$migDb = new PDO('sqlite::memory:');
$migDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$migDb->exec('PRAGMA foreign_keys = ON');

$writeMig = function (string $name, string $body) use ($migDir): string {
    $path = $migDir . '/' . $name . '.php';
    $code = "<?php\nreturn new class {\n    public function up(PDO \$db, bool \$isSqlite): void {\n        {$body}\n    }\n};\n";
    file_put_contents($path, $code);
    return $path;
};

// Test 1: Apply pending migrations on a fresh DB
$writeMig('20260101000001_create_widgets', "\$db->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');");
$writeMig('20260101000002_create_gizmos',  "\$db->exec('CREATE TABLE gizmos (id INTEGER PRIMARY KEY, label TEXT)');");
$mig = new Migrator($migDb, $migDir, $migLock);
$applied = $mig->apply(static fn($m) => null);
assert_equals(2, count($applied), 'Migrate: 2 pending migrations applied on fresh DB');
$tables = $migDb->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
assert_true(in_array('widgets', $tables, true) && in_array('gizmos', $tables, true), 'Migrate: both tables created');
assert_true(in_array('schema_migrations', $tables, true), 'Migrate: schema_migrations table auto-created');

// Test 2: Re-apply is a no-op
$applied2 = $mig->apply(static fn($m) => null);
assert_equals(0, count($applied2), 'Migrate: re-run skips already-applied migrations');

// Test 3: Status reports applied + pending + checksum_ok
$writeMig('20260101000003_add_thingies', "\$db->exec('CREATE TABLE thingies (id INTEGER PRIMARY KEY)');");
$status = $mig->status();
assert_equals(2, count($status['applied']), 'Migrate: status applied=2');
assert_equals(1, count($status['pending']), 'Migrate: status pending=1');
assert_equals(0, count($status['missing']), 'Migrate: status missing=0');
$firstApplied = array_keys($status['applied'])[0];
assert_true($status['applied'][$firstApplied]['checksum_ok'] === true, 'Migrate: applied checksum_ok = true');

// Test 4: Checksum mismatch is a hard error
file_put_contents(
    $migDir . '/20260101000001_create_widgets.php',
    "<?php\nreturn new class {\n    public function up(PDO \$db, bool \$isSqlite): void {\n        // tampered\n    }\n};\n"
);
$thrown = false;
try {
    $mig->apply(static fn($m) => null);
} catch (\Throwable $e) {
    $thrown = (strpos($e->getMessage(), 'modified after being applied') !== false);
}
assert_true($thrown, 'Migrate: tampered migration triggers checksum hard fail');

// Restore the original tampered file via baseline reset (for next tests)
$writeMig('20260101000001_create_widgets', "\$db->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');");
// Re-record correct checksum for the now-restored file
$correct = $mig->fileChecksum($migDir . '/20260101000001_create_widgets.php');
$migDb->prepare("UPDATE schema_migrations SET checksum = ? WHERE name = ?")
      ->execute([$correct, '20260101000001_create_widgets']);

// Test 4b: explicitly approved driver-portability rewrite accepts only its exact old→new checksum pair
$legacyDir = $migDir . '/legacy';
mkdir($legacyDir, 0750, true);
$legacyName = '20260424000000_baseline';
$legacyPath = $legacyDir . '/' . $legacyName . '.php';
copy(TEST_CLI_DIR . '/../migrations/' . $legacyName . '.php', $legacyPath);
$legacyDb = new PDO('sqlite::memory:');
$legacyDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacyMig = new Migrator($legacyDb, $legacyDir, $migLock . '.legacy');
$legacyMig->ensureMigrationsTable();
$legacyDb->prepare("INSERT INTO schema_migrations (name, checksum, duration_ms) VALUES (?, ?, 0)")
         ->execute([$legacyName, '0733ee8ec1141fe01a63fc86ff0f8a0efff517b7873f847759bcfe0e63500b29']);
$legacyStatus = $legacyMig->status();
assert_true($legacyStatus['applied'][$legacyName]['checksum_ok'] === true, 'Migrate: exact approved legacy checksum maps to driver-portable rewrite');
assert_equals(0, count($legacyMig->apply(static fn($m) => null)), 'Migrate: approved legacy checksum passes apply verification');
$legacyDb->prepare("UPDATE schema_migrations SET checksum = ? WHERE name = ?")
         ->execute(['a3ca08f0ad32a92cfa750126363c55ff7b6504e0c0b2819d05c5e8be227e248e', $legacyName]);
assert_equals(0, count($legacyMig->apply(static fn($m) => null)), 'Migrate: published intermediate checksum maps to final rewrite');
file_put_contents($legacyPath, "\n// unapproved third version\n", FILE_APPEND);
$legacyRejected = false;
try {
    $legacyMig->apply(static fn($m) => null);
} catch (\Throwable $e) {
    $legacyRejected = strpos($e->getMessage(), 'modified after being applied') !== false;
}
assert_true($legacyRejected, 'Migrate: legacy checksum does not allow an unapproved third version');
$legacyDb = null;
@unlink($legacyPath);
@rmdir($legacyDir);
@unlink($migLock . '.legacy');

// Test 5: Failure mid-migration rolls back, marker not inserted
$writeMig('20260101000004_will_fail', "\$db->exec('CREATE TABLE temp_ok (id INTEGER PRIMARY KEY)'); \$db->exec('CREATE TABLE temp_ok (id INTEGER PRIMARY KEY)');");
$migFailed = false;
try {
    $mig->apply(static fn($m) => null);
} catch (\Throwable $e) {
    $migFailed = true;
}
assert_true($migFailed, 'Migrate: failing migration throws');
$marker = $migDb->prepare("SELECT 1 FROM schema_migrations WHERE name = ?");
$marker->execute(['20260101000004_will_fail']);
assert_true(!$marker->fetchColumn(), 'Migrate: failed migration not recorded in schema_migrations');
@unlink($migDir . '/20260101000004_will_fail.php');

// Test 6: Baseline marks files as applied without running them
$migDb2 = new PDO('sqlite::memory:');
$migDb2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mig2 = new Migrator($migDb2, $migDir, $migLock);
$marked = $mig2->baseline(static fn($m) => null);
assert_true(count($marked) >= 3, 'Migrate: baseline marked all existing files');
$widgetTables = $migDb2->query("SELECT name FROM sqlite_master WHERE type='table' AND name='widgets'")->fetchAll();
assert_equals(0, count($widgetTables), 'Migrate: baseline did NOT run migrations (no widgets table)');
// Re-baseline is idempotent
$marked2 = $mig2->baseline(static fn($m) => null);
assert_equals(0, count($marked2), 'Migrate: re-baseline is idempotent');

// Test 7: create() generates valid migration stub
$stubPath = $mig->create('add example column');
assert_true(file_exists($stubPath), 'Migrate: create() writes the stub file');
assert_true(strpos(file_get_contents($stubPath), 'public function up(PDO $db, bool $isSqlite)') !== false,
    'Migrate: stub contains expected up() signature');
assert_true(preg_match('/\d{14}_add_example_column\.php$/', basename($stubPath)) === 1,
    'Migrate: stub filename has timestamp prefix and slugified name');
// PHP syntax check on the stub
$rc = -1;
$out = [];
exec('"' . PHP_BINARY . '" -l ' . escapeshellarg($stubPath) . ' 2>&1', $out, $rc);
assert_equals(0, $rc, 'Migrate: generated stub passes php -l');
@unlink($stubPath);

// Test 8: missing file detection in status
$migDb3 = new PDO('sqlite::memory:');
$migDb3->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mig3 = new Migrator($migDb3, $migDir, $migLock);
$mig3->ensureMigrationsTable();
$migDb3->prepare("INSERT INTO schema_migrations (name, checksum, duration_ms) VALUES (?, ?, 0)")
       ->execute(['99990101000000_ghost', 'deadbeef']);
$status3 = $mig3->status();
assert_equals(1, count($status3['missing']), 'Migrate: status detects missing file (in DB but no file)');
assert_equals('99990101000000_ghost', $status3['missing'][0], 'Migrate: missing entry has correct name');

// Test 9: Baseline migration produces full v1.4.0 schema
$baselineDb = new PDO('sqlite::memory:');
$baselineDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$baselineMig = new Migrator($baselineDb, TEST_CLI_DIR . '/../migrations', $migLock);
$baselineApplied = $baselineMig->apply(static fn($m) => null);
assert_true(count($baselineApplied) >= 1, 'Migrate: baseline migration applied on fresh DB');
assert_true(in_array('20260424000000_baseline', $baselineApplied, true), 'Migrate: baseline name in applied list');
$realTables = $baselineDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE '%_fts%' ORDER BY name")
                          ->fetchAll(PDO::FETCH_COLUMN);
$expected = ['categories', 'customers', 'orders', 'products', 'warehouses', 'shipping_labels', 'payment_transactions', 'email_log'];
foreach ($expected as $t) {
    assert_true(in_array($t, $realTables, true), "Migrate: baseline created table '{$t}'");
}

// Cleanup migration test artifacts
$migDb = $migDb2 = $migDb3 = $baselineDb = null;
foreach (glob($migDir . '/*') ?: [] as $f) @unlink($f);
@rmdir($migDir);
@unlink($migLock);

// ============================================================
// TEST: Invoices (InvoiceService + MinimalPdf + backfill)
// ============================================================
echo "\n--- Test: Invoices ---\n";

// Apply invoices migration into the test DB (baseline already created orders/customers)
$invMigFile = TEST_CLI_DIR . '/../migrations/20260424221721_create_invoices.php';
if (file_exists($invMigFile)) {
    $invMig = require $invMigFile;
    $invMig->up(Db::get(), Db::isSqlite());
}
// Apply add_vat_columns migration so invoices.vat_scenario etc. exist
$vatMigFile = TEST_CLI_DIR . '/../migrations/20260425013405_add_vat_columns.php';
if (file_exists($vatMigFile)) {
    $vatMig = require $vatMigFile;
    $vatMig->up(Db::get(), Db::isSqlite());
}
// Apply invoices(order_id) unique-index migration (bug #9) so all invoice
// tests below run with the production constraint active
$uqMigFile = TEST_CLI_DIR . '/../migrations/20260611220000_add_invoices_order_unique.php';
if (file_exists($uqMigFile)) {
    $uqMig = require $uqMigFile;
    $uqMig->up(Db::get(), Db::isSqlite());
}
$financialMigFile = TEST_CLI_DIR . '/../migrations/20260801141000_financial_snapshots.php';
if (file_exists($financialMigFile)) {
    $financialMig = require $financialMigFile;
    $financialMig->up(Db::get(), Db::isSqlite());
}
$storeScopeMigration->up(Db::get(), true);
$returnsCreditMigration->up(Db::get(), true);
// Invoice/credit-note counters per seller and document type (InvoiceService::allocateNumber), applied twice: idempotent.
$invoiceCounterMigration = require TEST_CLI_DIR . '/../migrations/20261016140500_invoice_number_counters.php';
$invoiceCounterMigration->up(Db::get(), true);
$invoiceCounterMigration->up(Db::get(), true);
assert_true(in_array('store_id', array_column(Db::get()->query('PRAGMA table_info(invoice_number_counters)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true),
    'Invoice counter migration: counters are keyed by store and document type, idempotently');

// Seed a minimal paid order
$db = Db::get();
$db->exec("DELETE FROM invoices WHERE 1=1");
$db->exec("DELETE FROM invoice_counters WHERE 1=1");
$db->exec("INSERT INTO orders (id, order_number, status, customer_name, customer_email, customer_phone, shipping_addr, payment_method, subtotal, discount_amount, shipping_cost, tax_amount, total, currency, locale, created_at) VALUES (5001, 'INV-5001', 'paid', 'Ján Test', 'jan@example.sk', '+421', '" . addslashes(json_encode(['street'=>'Hl. 1','city'=>'BA','zip'=>'811 01','country'=>'SK'])) . "', 'bank_transfer', 10000, 0, 500, 2100, 12600, 'eur', 'sk', datetime('now'))");
$db->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (5001, 1, 'SKU-T', 'Test produkt', 1, 10000, 10000)");

// 1. Issue invoice
$inv1 = InvoiceService::issue(5001);
assert_true(!empty($inv1['number']), 'Invoice: issued and has number');
assert_equals(8, strlen((string)$inv1['number']), 'Invoice: number is 8 chars (YYYYNNNN)');
assert_equals(12600, (int)$inv1['total_gross'], 'Invoice: total_gross matches order.total');
assert_equals('eur', (string)$inv1['currency'], 'Invoice: currency from order');
assert_equals(64, strlen((string)$inv1['download_token']), 'Invoice: download_token is 64 hex chars');
assert_true(ctype_xdigit((string)$inv1['download_token']), 'Invoice: download_token is hex');
assert_equals('default', $inv1['store_id'], 'Invoice: issued invoice stores active store ownership');
StoreContext::activate($deContext);
assert_true(InvoiceService::byOrderId(5001) === null, 'Store isolation: second store cannot read default invoice by order');
assert_true(InvoiceService::byToken((string)$inv1['download_token']) === null, 'Store isolation: second store cannot use default invoice token');
Bootstrap::initStoreContext('/', '', 'default');

// 2. PDF written to disk
$pdfPath = InvoiceService::pdfAbsolutePath($inv1);
assert_true(file_exists($pdfPath), 'Invoice: PDF file written to disk');
assert_true(filesize($pdfPath) > 500, 'Invoice: PDF size > 500 bytes');
assert_equals('%PDF-1.4', substr((string)file_get_contents($pdfPath), 0, 8), 'Invoice: PDF has %PDF-1.4 magic header');
$appRootNormalized = strtolower(str_replace('\\', '/', realpath(TEST_CLI_DIR . '/..') ?: dirname(TEST_CLI_DIR)));
$pdfPathNormalized = strtolower(str_replace('\\', '/', realpath($pdfPath) ?: $pdfPath));
assert_true(!str_starts_with($pdfPathNormalized, $appRootNormalized . '/'), 'Invoice: PDF is stored outside the application root');
foreach (['../config.php', '/tmp/stolen.pdf', 'C:\\Windows\\stolen.pdf', '\\\\server\\share\\stolen.pdf', '2026/07/file.pdf:payload', '2026/07/not-a-pdf.txt', "2026/07/bad\0.pdf"] as $badInvoicePath) {
    $invoicePathRejected = false;
    try { InvoiceService::pdfAbsolutePath(['pdf_path' => $badInvoicePath]); } catch (RuntimeException $e) { $invoicePathRejected = true; }
    assert_true($invoicePathRejected, "Invoice: unsafe PDF path rejected ({$badInvoicePath})");
}
$legacyInvoicePath = InvoiceService::pdfAbsolutePath(['pdf_path' => 'storage/invoices/2026/07/legacy.pdf']);
assert_true(str_ends_with(str_replace('\\', '/', $legacyInvoicePath), '/2026/07/legacy.pdf'), 'Invoice: legacy DB path maps inside private invoice root');

// 3. Idempotency — issuing again returns same invoice
$inv2 = InvoiceService::issue(5001);
assert_equals((int)$inv1['id'], (int)$inv2['id'], 'Invoice: issue() is idempotent (same id on re-issue)');
assert_equals($inv1['number'], $inv2['number'], 'Invoice: number stable on re-issue');

// 4. Number monotonicity (per year+prefix)
$db->exec("INSERT INTO orders (id, order_number, status, customer_name, customer_email, shipping_addr, payment_method, subtotal, discount_amount, shipping_cost, tax_amount, total, currency, locale, created_at) VALUES (5002, 'INV-5002', 'paid', 'X', 'x@y.com', '{}', 'bank_transfer', 1000, 0, 0, 0, 1000, 'eur', 'sk', datetime('now'))");
$db->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (5002, 1, 'X', 'X', 1, 1000, 1000)");
$inv3 = InvoiceService::issue(5002);
assert_true((int)$inv3['number'] > (int)$inv1['number'], 'Invoice: numbers are monotonic');

// 5. Lookup by token
$found = InvoiceService::byToken((string)$inv1['download_token']);
assert_true($found !== null, 'Invoice: byToken returns row for valid token');
assert_equals((int)$inv1['id'], (int)($found['id'] ?? 0), 'Invoice: lookup returns correct invoice');
$bad = InvoiceService::byToken(str_repeat('0', 64));
assert_true($bad === null, 'Invoice: byToken returns null for unknown token');

// 6. Reverse-charge detection
$rcOn = InvoiceService::isReverseCharge(
    ['country' => 'SK'],
    ['country' => 'DE', 'ic_dph' => 'DE123456789']
);
assert_true($rcOn, 'Invoice: reverse-charge ON for SK seller, DE customer with VAT ID');
$rcOff = InvoiceService::isReverseCharge(
    ['country' => 'SK'],
    ['country' => 'DE', 'ic_dph' => '']
);
assert_true(!$rcOff, 'Invoice: reverse-charge OFF when customer has no VAT ID');
$rcSame = InvoiceService::isReverseCharge(
    ['country' => 'SK'],
    ['country' => 'SK', 'ic_dph' => 'SK1234']
);
assert_true(!$rcSame, 'Invoice: reverse-charge OFF for same-country sale');

// 7. Backfill CLI logic — re-runnable, idempotent (scoped to id >= 5003 to ignore pre-existing test orders)
$db->exec("INSERT INTO orders (id, order_number, status, customer_name, customer_email, shipping_addr, payment_method, subtotal, discount_amount, shipping_cost, tax_amount, total, currency, locale, created_at) VALUES (5003, 'INV-5003', 'paid', 'Y', 'y@z.com', '{}', 'card', 5000, 0, 0, 0, 5000, 'eur', 'sk', datetime('now'))");
$db->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (5003, 1, 'Y', 'Y', 1, 5000, 5000)");
$missingStmt = $db->query("SELECT o.id FROM orders o LEFT JOIN invoices i ON i.order_id = o.id WHERE o.status = 'paid' AND i.id IS NULL AND o.id >= 5003");
$missing = $missingStmt->fetchAll(PDO::FETCH_COLUMN);
assert_equals(1, count($missing), 'Backfill: detects 1 paid order without invoice');
assert_equals(5003, (int)$missing[0], 'Backfill: correct order id detected');
InvoiceService::issue(5003);
$missing2 = $db->query("SELECT o.id FROM orders o LEFT JOIN invoices i ON i.order_id = o.id WHERE o.status = 'paid' AND i.id IS NULL AND o.id >= 5003")->fetchAll(PDO::FETCH_COLUMN);
assert_equals(0, count($missing2), 'Backfill: 0 missing after issue (idempotent rerun = no-op)');

// 8. Invoice rejects non-existent order
$threw = false;
try { InvoiceService::issue(99999); } catch (\Throwable $e) { $threw = true; }
assert_true($threw, 'Invoice: throws on non-existent order');

// 9. Invoice MailService attachment plumbing
LogMail::clearCaptured();
// Explicit log file in a fixture: the default would create the private runtime logs/ directory.
MailService::useAdapter(new LogMail(['log_file' => test_fixture_path('invoice-mail') . '/emails.log']));
$pdfBytes = file_get_contents($pdfPath);
$res = MailService::send('invoice_issued', 'jan@example.sk', 'sk', [
    'order_number'   => 'INV-5001',
    'invoice_number' => $inv1['number'],
    'amount'         => '126,00 €',
], 5001, [['filename' => $inv1['number'].'.pdf', 'data' => $pdfBytes, 'content_type' => 'application/pdf']]);
assert_equals('sent', (string)$res['status'], 'MailService: invoice email sent via LogMail');
$captured = LogMail::lastCaptured();
assert_true(is_array($captured['attachments'] ?? null), 'MailService: attachments propagated to adapter');
assert_equals(1, count($captured['attachments']), 'MailService: 1 attachment captured');
assert_equals('application/pdf', $captured['attachments'][0]['content_type'], 'MailService: attachment content_type=application/pdf');
assert_equals(strlen($pdfBytes), strlen($captured['attachments'][0]['data']), 'MailService: attachment data length matches');
MailService::useAdapter(null);

// ============================================================
// TEST: Backup & Restore (BackupService + cli/restore.php)
// ============================================================
echo "\n--- Test 50: Backup & Restore ---\n";

// Use a private isolated SQLite + storage root for the backup tests
$backupRoot = sys_get_temp_dir() . '/bareboneseshop-backup-test';
if (is_dir($backupRoot)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backupRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($backupRoot);
}
mkdir($backupRoot, 0775, true);
$liveBackupDb = $backupRoot . '/live.db';

$backupPdo = new PDO('sqlite:' . $liveBackupDb);
$backupPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$backupPdo->exec('PRAGMA journal_mode=WAL');
$backupPdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, email TEXT)');
$backupPdo->exec("INSERT INTO customers (email) VALUES ('a@x.sk'), ('b@x.sk')");
$backupPdo->exec('CREATE TABLE schema_migrations (name TEXT PRIMARY KEY, checksum TEXT NOT NULL DEFAULT \'\', applied_at TEXT, duration_ms INTEGER DEFAULT 0)');
$backupPdo->exec("INSERT INTO schema_migrations (name, applied_at) VALUES ('20260101_first', datetime('now'))");

$backupCfg = [
    'enabled' => true, 'storage' => 'local', 'compression' => false,
    'retention' => ['daily' => 7, 'weekly' => 4, 'monthly' => 6, 'minimum_kept' => 3],
    'auto_pre_migration' => true, 'auto_gc_on_backup' => false,
    'wal_checkpoint' => true,
    'log_file' => 'backup.log', 'log_max_bytes' => 10485760, 'log_max_files' => 5,
];
BackupService::init($backupPdo, $backupCfg, $backupRoot);

// 50.1 — config defaults loaded
$cfgLoaded = BackupService::config();
assert_true($cfgLoaded['enabled'] === true, 'Backup: config enabled flag honoured');
assert_equals(false, $cfgLoaded['compression'], 'Backup: compression default OFF');
assert_equals(7, (int)$cfgLoaded['retention']['daily'], 'Backup: daily retention=7');

// 50.2 — create() produces a backup with sha256, size, schema_version
$created = BackupService::create(['label' => 'manual']);
assert_true(is_string($created['remote_path']) && str_ends_with($created['remote_path'], '.db'), 'Backup: create returned remote_path');
assert_true($created['size_bytes'] > 0, 'Backup: file size > 0');
assert_equals(64, strlen($created['sha256']), 'Backup: sha256 is 64 hex chars');
assert_equals('20260101_first', $created['schema_version'], 'Backup: schema_version captured');
assert_equals('manual', $created['label'], 'Backup: label persisted in meta');

// 50.3 — meta JSON sidecar written
$absBackup = $backupRoot . '/storage/backups/' . $created['remote_path'];
assert_true(is_file($absBackup), 'Backup: .db file exists on disk');
assert_true(is_file($absBackup . '.meta.json'), 'Backup: .meta.json sidecar exists');
$metaOnDisk = json_decode((string)file_get_contents($absBackup . '.meta.json'), true);
assert_equals($created['sha256'], (string)($metaOnDisk['sha256'] ?? ''), 'Backup: meta sha256 matches result');

// 50.4 — sha256 verifies against actual file
$actualSha = hash_file('sha256', $absBackup);
assert_equals($created['sha256'], $actualSha, 'Backup: sha256 matches hash_file');

// 50.5 — list() returns at least one row
$listed = BackupService::list();
assert_greater(count($listed), 0, 'Backup: list() includes the created backup');

$localBackup = new LocalBackupStorage($backupRoot . '/storage/backups');
assert_true(str_ends_with(str_replace('\\', '/', $localBackup->localPath('2026/04/file.db')), '/2026/04/file.db'), 'LocalBackupStorage: valid relative path is preserved');
foreach (['', "bad\0name", "bad\tname", '/tmp/file', '\\server\\share', 'D:\\backup.db', '2026/file.db:payload', '2026/../file', '2026\\mixed/file'] as $badPath) {
    $localRejected = $listRejected = false;
    try { $localBackup->localPath($badPath); } catch (RuntimeException $e) { $localRejected = true; }
    if ($badPath !== '') {
        try { $localBackup->list($badPath); } catch (RuntimeException $e) { $listRejected = true; }
    } else {
        $listRejected = is_array($localBackup->list());
    }
    assert_true($localRejected, 'LocalBackupStorage: localPath rejects ' . json_encode($badPath));
    assert_true($listRejected, $badPath === '' ? 'LocalBackupStorage: empty list prefix remains valid' : 'LocalBackupStorage: list prefix rejects ' . json_encode($badPath));
}

file_put_contents($backupRoot . '/storage/backups/blocked', 'not a directory');
$backupMkdirFailure = false;
try { $localBackup->put($liveBackupDb, 'blocked/file.db', []); } catch (RuntimeException $e) { $backupMkdirFailure = true; }
assert_true($backupMkdirFailure, 'LocalBackupStorage: mkdir failure throws');
$backupCopyFailure = false;
try { $localBackup->put($backupRoot . '/missing-source.db', 'copy/file.db', []); } catch (RuntimeException $e) { $backupCopyFailure = true; }
assert_true($backupCopyFailure, 'LocalBackupStorage: copy failure throws');

$backupSibling = $backupRoot . '/storage/backups-sibling';
@mkdir($backupSibling);
$backupLink = $backupRoot . '/storage/backups/escape-link';
if (@symlink($backupSibling, $backupLink)) {
    $backupSymlinkPathRejected = $backupSymlinkListRejected = false;
    try { $localBackup->localPath('escape-link/escaped.db'); } catch (RuntimeException $e) { $backupSymlinkPathRejected = true; }
    try { $localBackup->list('escape-link'); } catch (RuntimeException $e) { $backupSymlinkListRejected = true; }
    assert_true($backupSymlinkPathRejected, 'LocalBackupStorage: localPath rejects sibling-prefix symlink escape');
    assert_true($backupSymlinkListRejected, 'LocalBackupStorage: list rejects symlinked prefix escape');
    @unlink($backupLink);
} else {
    echo "  [SKIP] LocalBackupStorage symlink regressions (symlink unavailable)\n";
}
@rmdir($backupSibling);

$mountedRoot = $backupRoot . '/mounted-offsite';
mkdir($mountedRoot, 0775, true);
$missingMountSentinelRejected = false;
try { new MountedBackupStorage($mountedRoot); } catch (RuntimeException $e) { $missingMountSentinelRejected = true; }
assert_true($missingMountSentinelRejected, 'MountedBackupStorage: missing mount sentinel fails closed');
file_put_contents($mountedRoot . '/.bbe-offsite-backup-target', "operator-provisioned\n");
$mountedStorage = new MountedBackupStorage($mountedRoot);
unlink($mountedRoot . '/.bbe-offsite-backup-target');
$disappearedMountRejected = false;
try { $mountedStorage->list(); } catch (RuntimeException $e) { $disappearedMountRejected = true; }
assert_true($disappearedMountRejected, 'MountedBackupStorage: disappearing mount sentinel fails closed');
file_put_contents($mountedRoot . '/.bbe-offsite-backup-target', "operator-provisioned\n");
$storageNeutralSource = (string)file_get_contents(TEST_CLI_DIR . '/../src/BackupService.php');
assert_true(!str_contains($storageNeutralSource, '->localPath('), 'BackupService: backup and restore are storage-neutral');

// 50.6 — second create() within same second gets a -1 suffix
$created2 = BackupService::create(['label' => 'manual']);
assert_true($created['remote_path'] !== $created2['remote_path'], 'Backup: second create() gets unique path');

// 50.7 — gz compression opt-in produces a .gz sidecar
$createdGz = BackupService::create(['label' => 'manual', 'compress' => true]);
$gzPath = $backupRoot . '/storage/backups/' . $createdGz['remote_path'] . '.gz';
assert_true(is_file($gzPath), 'Backup: --gz produces .db.gz sidecar');
assert_true($createdGz['compressed'] === true, 'Backup: compressed=true in meta');
$gzMeta = json_decode((string)file_get_contents($gzPath . '.meta.json'), true);
assert_equals(hash_file('sha256', $gzPath), (string)($gzMeta['sha256'] ?? ''), 'Backup: gzip sidecar has its own checksum metadata');
assert_equals($createdGz['sha256'], (string)($gzMeta['source_sha256'] ?? ''), 'Backup: gzip metadata links to source snapshot checksum');

// 50.8 — GC keeps minimum_kept newest backups
$gcCfg = $backupCfg;
$gcCfg['retention'] = ['daily' => 1, 'weekly' => 1, 'monthly' => 1, 'minimum_kept' => 2];
$gcRoot = $backupRoot . '/_gc';
mkdir($gcRoot . '/storage/backups/2026/03/01', 0775, true);
mkdir($gcRoot . '/storage/backups/2026/03/15', 0775, true);
mkdir($gcRoot . '/storage/backups/2026/04/01', 0775, true);
mkdir($gcRoot . '/storage/backups/2026/04/25', 0775, true);
$gcPdo = new PDO('sqlite:' . $gcRoot . '/live.db');
$gcPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$gcPdo->exec('CREATE TABLE x (id INTEGER PRIMARY KEY)');
BackupService::init($gcPdo, $gcCfg, $gcRoot);
foreach ([
    ['2026/03/01/eshop-20260301-120000.db', '2026-03-01T12:00:00+00:00', 'auto'],
    ['2026/03/15/eshop-20260315-120000.db', '2026-03-15T12:00:00+00:00', 'auto'],
    ['2026/04/01/eshop-20260401-120000.db', '2026-04-01T12:00:00+00:00', 'auto'],
    ['2026/04/25/eshop-20260425-120000.db', '2026-04-25T12:00:00+00:00', 'auto'],
] as $tup) {
    [$rp, $ts, $lbl] = $tup;
    file_put_contents($gcRoot . '/storage/backups/' . $rp, str_repeat("\0", 32));
    file_put_contents($gcRoot . '/storage/backups/' . $rp . '.meta.json', json_encode(['timestamp' => $ts, 'label' => $lbl, 'sha256' => '0', 'size_bytes' => 32]));
    @touch($gcRoot . '/storage/backups/' . $rp, strtotime($ts));
}
$gcRes = BackupService::gc();
assert_greater($gcRes['kept'], 1, 'Backup: GC kept at least minimum_kept');
assert_true(count($gcRes['deleted']) >= 1, 'Backup: GC deleted at least one stale backup');

// 50.9 — Re-init service back on the original backupPdo for restore tests
BackupService::init($backupPdo, $backupCfg, $backupRoot);

// Mutate live DB then restore — verify rollback
$backupPdo->exec("INSERT INTO customers (email) VALUES ('c@x.sk'), ('d@x.sk')");
$beforeRestore = (int)$backupPdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
assert_equals(4, $beforeRestore, 'Backup: live mutated to 4 customers before restore');

$backupPdo = null; // release file handle on Windows so restore can swap
$res = BackupService::restore($created['remote_path']);
assert_equals('ok', (string)$res['integrity'], 'Restore: integrity_check returns ok');
assert_true(is_string($res['snapshot']) && str_ends_with($res['snapshot'], '.db'), 'Restore: pre-restore-snapshot taken');
$verifyPdo = new PDO('sqlite:' . $liveBackupDb);
$verifyPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$afterRestore = (int)$verifyPdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
assert_equals(2, $afterRestore, 'Restore: customers count rolled back from 4 to 2');
$verifyPdo = null;

// 50.10 — Tampered backup is rejected (sha256 mismatch)
$absSrc = $backupRoot . '/storage/backups/' . $created2['remote_path'];
file_put_contents($absSrc, str_repeat('X', 16), FILE_APPEND);
$tampered = false;
try {
    BackupService::restore($created2['remote_path']);
} catch (\Throwable $e) {
    $tampered = preg_match('/(?:size|sha256) mismatch/i', $e->getMessage()) === 1;
}
assert_true($tampered, 'Restore: tampered backup rejected by metadata verification');

$missingMetaBackup = BackupService::create(['label' => 'manual']);
$missingMetaPath = $backupRoot . '/storage/backups/' . $missingMetaBackup['remote_path'] . '.meta.json';
unlink($missingMetaPath);
$missingMetaRejected = false;
try { BackupService::restore($missingMetaBackup['remote_path'], ['skip_snapshot' => true]); } catch (RuntimeException $e) {
    $missingMetaRejected = str_contains($e->getMessage(), 'metadata is required');
}
assert_true($missingMetaRejected, 'Restore: backup without metadata is rejected');

$wrongIdentityBackup = BackupService::create(['label' => 'manual']);
$wrongIdentityPath = $backupRoot . '/storage/backups/' . $wrongIdentityBackup['remote_path'] . '.meta.json';
$wrongIdentityMeta = json_decode((string)file_get_contents($wrongIdentityPath), true);
$wrongIdentityMeta['remote_path'] = 'other/backup.db';
file_put_contents($wrongIdentityPath, json_encode($wrongIdentityMeta));
$wrongIdentityRejected = false;
try { BackupService::restore($wrongIdentityBackup['remote_path'], ['skip_snapshot' => true]); } catch (RuntimeException $e) {
    $wrongIdentityRejected = str_contains($e->getMessage(), 'identity does not match');
}
assert_true($wrongIdentityRejected, 'Restore: metadata from another backup is rejected');
assert_equals(0, count(glob($liveBackupDb . '.restore-*.tmp') ?: []), 'Restore: failed validation leaves no staged temp files');

$storageNeutral = new class($mountedStorage) implements BackupStorage {
    public function __construct(private BackupStorage $inner) { }
    public function put(string $localPath, string $remotePath, array $meta): void { $this->inner->put($localPath, $remotePath, $meta); }
    public function get(string $remotePath, string $localPath): void { $this->inner->get($remotePath, $localPath); }
    public function delete(string $remotePath): void { $this->inner->delete($remotePath); }
    public function list(string $prefix = ''): array { return $this->inner->list($prefix); }
};
$drillDbPath = $backupRoot . '/restore-drill.db';
$drillPdo = new PDO('sqlite:' . $drillDbPath);
$drillPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$drillPdo->exec('CREATE TABLE restore_probe (value TEXT NOT NULL)');
$drillPdo->exec("INSERT INTO restore_probe (value) VALUES ('backup-state')");
$drillPdo->exec('CREATE TABLE schema_migrations (name TEXT PRIMARY KEY)');
BackupService::init($drillPdo, $backupCfg, $backupRoot, $storageNeutral);
$drillBackup = BackupService::create(['label' => 'restore-drill']);
$drillPdo->exec("UPDATE restore_probe SET value = 'mutated-state'");
$drillPdo = null;
$drillResult = BackupService::restore($drillBackup['remote_path'], ['skip_snapshot' => true]);
$drillVerify = new PDO('sqlite:' . $drillDbPath);
assert_equals('backup-state', (string)$drillVerify->query('SELECT value FROM restore_probe')->fetchColumn(), 'Restore drill: downloaded off-site snapshot replaced disposable live DB');
assert_equals('ok', (string)$drillResult['integrity'], 'Restore drill: downloaded snapshot passes integrity_check');
$drillVerify = null;

// 50.11 — Migrator preApplyHook fires once before pending migrations
$mTmp = $backupRoot . '/_mig';
@mkdir($mTmp, 0775, true);
$mDb = $mTmp . '/m.db';
@unlink($mDb);
$mPdo = new PDO('sqlite:' . $mDb);
$mPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mDir = $mTmp . '/migrations';
@mkdir($mDir);
file_put_contents($mDir . '/20260101000000_a.php', "<?php\nreturn new class { public function up(PDO \$db, bool \$is): void { \$db->exec('CREATE TABLE a (id INTEGER PRIMARY KEY)'); } };\n");
$migrator = new Migrator($mPdo, $mDir, $mTmp . '/m.lock');
$hookFired = 0;
$migrator->apply(static fn ($m) => null, function () use (&$hookFired) { $hookFired++; });
assert_equals(1, $hookFired, 'Migrator: preApplyHook fired exactly once for pending migrations');

// 50.12 — preApplyHook NOT called when nothing pending
$hookFired2 = 0;
$migrator->apply(static fn ($m) => null, function () use (&$hookFired2) { $hookFired2++; });
assert_equals(0, $hookFired2, 'Migrator: preApplyHook skipped when no pending migrations');

// 50.13 — preApplyHook throwing aborts the apply (no migration recorded)
file_put_contents($mDir . '/20260102000000_b.php', "<?php\nreturn new class { public function up(PDO \$db, bool \$is): void { \$db->exec('CREATE TABLE b (id INTEGER PRIMARY KEY)'); } };\n");
$throwCaught = false;
try {
    $migrator->apply(static fn ($m) => null, function () { throw new RuntimeException('hook says no'); });
} catch (\Throwable $e) {
    $throwCaught = true;
}
assert_true($throwCaught, 'Migrator: preApplyHook throw propagates');
$bExists = (bool)$mPdo->query("SELECT name FROM sqlite_master WHERE name='b'")->fetchColumn();
assert_true(!$bExists, 'Migrator: preApplyHook throw prevents the migration from running');

// 50.14 — buildRemotePath uses Y/m/d/eshop-YmdHis.db
$rp = BackupService::buildRemotePath(new DateTimeImmutable('2026-04-25T14:30:12+00:00'), 0);
assert_equals('2026/04/25/eshop-20260425-143012.db', $rp, 'Backup: buildRemotePath layout');

// Cleanup backup test artifacts (releases Windows locks)
BackupService::reset();
$mPdo = null;
$gcPdo = null;
usleep(100000);
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backupRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
}
@rmdir($backupRoot);

// ============================================================
// TEST 51: VAT — config/vat-rates, scenarios, computeLine
// ============================================================
echo "\n--- Test 51: VAT (rates / scenarios / computeLine) ---\n";

VatService::reset();
VatService::init(realpath(TEST_CLI_DIR . '/..'));

// 51.1 — current SK rate is 23%
assert_equals(23.0, VatService::rate('SK', 'standard'), 'VAT: SK current=23%');
// 51.2 — SK historical 2024 = 20%
assert_equals(20.0, VatService::rate('SK', 'standard', '2024-06-01'), 'VAT: SK 2024-06 historical=20%');
// 51.3 — CZ current reduced=12%
assert_equals(12.0, VatService::rate('CZ', 'reduced'), 'VAT: CZ reduced current=12%');
// 51.4 — CZ historical 2023 reduced=15%
assert_equals(15.0, VatService::rate('CZ', 'reduced', '2023-06-01'), 'VAT: CZ 2023 reduced=15%');
// 51.5 — class resolver: books → reduced
assert_equals('reduced', VatService::resolveClass('books'), 'VAT: alias books→reduced');
// 51.6 — class resolver: unknown → standard
assert_equals('standard', VatService::resolveClass('weirdo'), 'VAT: alias unknown→standard');
// 51.7 — isEuCountry
assert_true(VatService::isEuCountry('DE'), 'VAT: DE is EU');
assert_true(!VatService::isEuCountry('US'), 'VAT: US is not EU');

// 51.8 — domestic scenario
$sc = VatService::resolveScenario(['seller_country'=>'SK','customer_country'=>'SK']);
assert_equals('domestic', $sc['scenario'], 'VAT scenario: domestic same country');
// 51.9 — reverse_charge (B2B + valid VAT ID + cross-border EU)
$sc = VatService::resolveScenario(['seller_country'=>'SK','customer_country'=>'DE','customer_vat_id_valid'=>true,'is_b2b'=>true]);
assert_equals('reverse_charge', $sc['scenario'], 'VAT scenario: reverse_charge B2B intra-EU');
assert_equals('vat.reverse_charge_note', $sc['note_key'], 'VAT scenario: reverse_charge note_key');
// 51.10 — OSS scenario
$sc = VatService::resolveScenario(['seller_country'=>'SK','seller_oss_enabled'=>true,'customer_country'=>'DE']);
assert_equals('oss', $sc['scenario'], 'VAT scenario: OSS cross-border B2C');
assert_equals('DE', $sc['rate_country'], 'VAT scenario: OSS uses customer-country rate');
// 51.11 — sub-threshold (OSS off)
$sc = VatService::resolveScenario(['seller_country'=>'SK','seller_oss_enabled'=>false,'customer_country'=>'DE']);
assert_equals('domestic_subthreshold', $sc['scenario'], 'VAT scenario: sub-threshold uses seller rate');
assert_equals('SK', $sc['rate_country'], 'VAT scenario: sub-threshold rate_country=SK');
// 51.12 — export
$sc = VatService::resolveScenario(['seller_country'=>'SK','customer_country'=>'US']);
assert_equals('export', $sc['scenario'], 'VAT scenario: export outside EU');
// 51.12b — non-VAT payer
$sc = VatService::resolveScenario(['seller_country'=>'SK','seller_vat_registered'=>false,'customer_country'=>'SK']);
assert_equals('non_vat_payer', $sc['scenario'], 'VAT scenario: non-VAT payer');
assert_equals('vat.non_vat_payer_note', $sc['note_key'], 'VAT scenario: non-VAT payer note_key');
assert_true(!$sc['applies_vat'], 'VAT scenario: non-VAT payer applies no VAT');

// 51.13 — computeLine gross-inclusive 23%
$ln = VatService::computeLine(1230, 1, 23.0, false);
assert_equals(1230, $ln['gross'], 'VAT line: gross');
assert_equals(1000, $ln['net'],   'VAT line: net');
assert_equals(230,  $ln['vat_amount'], 'VAT line: vat_amount');
// 51.14 — computeLine reverse-charge zero VAT
$ln = VatService::computeLine(2460, 1, 23.0, true);
assert_equals(0,    $ln['vat_amount'], 'VAT line: reverse_charge vat=0');
assert_equals(2460, $ln['net'],        'VAT line: reverse_charge net=gross');

// 51.15 — one deterministic snapshot covers products, discount, shipping and fee
$snapshotItems = [
    ['product_id'=>1, 'sku'=>'STD', 'price'=>12300, 'qty'=>1, 'vat_class'=>'standard'],
    ['product_id'=>2, 'sku'=>'RED', 'price'=>11000, 'qty'=>1, 'vat_class'=>'reduced'],
];
$snapshotContext = ['seller_country'=>'SK', 'customer_country'=>'SK'];
$snapshotAmounts = ['discount'=>2330, 'shipping'=>600, 'payment_fee'=>120, 'currency'=>'eur', 'effective_date'=>'2026-08-01'];
$financialSnapshot = VatService::buildSnapshot($snapshotItems, $snapshotContext, $snapshotAmounts);
assert_equals(1, $financialSnapshot['version'], 'VAT snapshot: versioned contract');
assert_equals(21690, $financialSnapshot['totals']['gross'], 'VAT snapshot: gross includes discounted products, shipping and fee');
assert_equals(3763, $financialSnapshot['totals']['vat'], 'VAT snapshot: mixed-rate VAT is deterministic');
assert_equals(17927, $financialSnapshot['totals']['net'], 'VAT snapshot: net + VAT = gross');
assert_equals(2182, $financialSnapshot['breakdown']['23']['vat'], 'VAT snapshot: shipping follows standard product rate');
assert_equals(1581, $financialSnapshot['breakdown']['19']['vat'], 'VAT snapshot: coupon lowers reduced-rate tax base');
assert_equals(120, $financialSnapshot['breakdown']['0']['net'], 'VAT snapshot: payment fee remains exempt');
assert_equals($financialSnapshot, VatService::buildSnapshot($snapshotItems, $snapshotContext, $snapshotAmounts), 'VAT snapshot: identical inputs produce identical snapshot and fingerprint');
VatService::validateSnapshot($financialSnapshot);
$tamperedSnapshot = $financialSnapshot;
$tamperedSnapshot['totals']['gross']++;
$tamperRejected = false;
try { VatService::validateSnapshot($tamperedSnapshot); }
catch (InvalidArgumentException $e) { $tamperRejected = true; }
assert_true($tamperRejected, 'VAT snapshot: fingerprint rejects changed totals');

// 51.16 — reverse charge freezes every component at zero VAT
$reverseSnapshot = VatService::buildSnapshot($snapshotItems, [
    'seller_country'=>'SK', 'customer_country'=>'DE', 'customer_vat_id_valid'=>true, 'is_b2b'=>true,
], ['shipping'=>500, 'currency'=>'eur', 'effective_date'=>'2026-08-01']);
assert_equals('reverse_charge', $reverseSnapshot['scenario'], 'VAT snapshot: reverse-charge scenario frozen');
assert_equals(0, $reverseSnapshot['totals']['vat'], 'VAT snapshot: reverse-charge products and shipping have zero VAT');
assert_equals($reverseSnapshot['totals']['gross'], $reverseSnapshot['totals']['net'], 'VAT snapshot: reverse-charge net equals gross');

// ============================================================
// TEST 52: VAT — VatIdValidator + cache
// ============================================================
echo "\n--- Test 52: VAT (VatIdValidator + cache) ---\n";

// Ensure vat_id_cache table exists on the shared $pdo
$pdo->exec("
    CREATE TABLE IF NOT EXISTS vat_id_cache (
        vat_id        TEXT PRIMARY KEY,
        country       TEXT NOT NULL,
        status        TEXT NOT NULL,
        trader_name   TEXT,
        trader_addr   TEXT,
        checked_at    TEXT NOT NULL DEFAULT (datetime('now')),
        ttl_seconds   INTEGER NOT NULL DEFAULT 86400,
        raw_response  TEXT
    )
");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_vat_id_cache_status ON vat_id_cache(status)");

// Re-bind Db singleton — earlier Migrator/Backup tests may have swapped it to a different handle
Db::init($pdo, 'sqlite');

// Inject deterministic mock validator via DI helper if exposed; otherwise
// validate via direct cache flow + MockVatIdValidator
$mock = new MockVatIdValidator([
    'DE123456789' => ['status'=>'valid','country'=>'DE','trader_name'=>'Foo GmbH'],
    'AT654321'    => ['status'=>'invalid'],
    'PL5551234567'=> ['status'=>'unverified'],
]);

// 52.1 — DE valid is fresh (uncached)
$r = VatService::validateVatId('DE123456789', $mock);
assert_equals('valid', $r['status'], 'VAT id: DE valid');
assert_equals(false,   (bool)($r['cached'] ?? false), 'VAT id: DE first call uncached');
// 52.2 — DE replay is cached
$r = VatService::validateVatId('DE123456789', $mock);
assert_equals(true,    (bool)($r['cached'] ?? false), 'VAT id: DE replay cached');
// 52.3 — AT invalid cached 24h
$r = VatService::validateVatId('AT654321', $mock);
assert_equals('invalid', $r['status'], 'VAT id: AT invalid');
$row = $pdo->query("SELECT ttl_seconds FROM vat_id_cache WHERE vat_id='AT654321'")->fetch();
assert_equals(86400, (int)$row['ttl_seconds'], 'VAT id: invalid TTL=24h');
// 52.4 — PL unverified cached 1h
$r = VatService::validateVatId('PL5551234567', $mock);
assert_equals('unverified', $r['status'], 'VAT id: PL unverified');
$row = $pdo->query("SELECT ttl_seconds FROM vat_id_cache WHERE vat_id='PL5551234567'")->fetch();
assert_equals(3600, (int)$row['ttl_seconds'], 'VAT id: unverified TTL=1h');
// 52.5 — Bad shape → invalid (heuristic)
$r = VatService::validateVatId('garbage', $mock);
assert_equals('invalid', $r['status'], 'VAT id: garbage→invalid');

// ============================================================
// TEST 53: VAT — Cart vatContext + Orders snapshot
// ============================================================
echo "\n--- Test 53: VAT (Cart + Orders persistence) ---\n";

// Need a product with vat_class
$pdo->exec("PRAGMA foreign_keys = OFF");
$pdo->exec("DELETE FROM order_items");
$pdo->exec("DELETE FROM orders");
$pdo->exec("DELETE FROM products");
$pdo->exec("DELETE FROM categories");
$pdo->exec("PRAGMA foreign_keys = ON");
$pdo->exec("INSERT INTO categories (id,name,slug) VALUES (1,'X','x')");
$pdo->exec("INSERT INTO products (id,category_id,name,slug,sku,price,currency,status,vat_class,stock) VALUES
    (501,1,'Speaker','speaker','SKU-S',12300,'eur','active','standard',999),
    (502,1,'Book','book','SKU-B',  3600,'eur','active','reduced',999)");

$pdo->exec("INSERT INTO product_related_products (product_id, related_product_id, sort_order) VALUES (501, 502, 0)");
$curatedRelated = Products::curatedRelated(501, 4);
assert_equals(1, count($curatedRelated), 'Products: curated related returns selected products');
assert_equals('SKU-B', $curatedRelated[0]['sku'], 'Products: curated related preserves configured product');

// Seed warehouse stock so Warehouse::reserve in Orders::create succeeds
$pdo->exec("DELETE FROM warehouse_stock");
$pdo->exec("INSERT OR IGNORE INTO warehouses (id,code,name,priority,is_default) VALUES (1,'main','Main',100,1)");
$pdo->exec("INSERT INTO warehouse_stock (warehouse_id,sku,qty,reserved) VALUES (1,'SKU-S',999,0),(1,'SKU-B',999,0)");

// Initialize VatService config so rate() resolves SK 23% etc.
VatService::reset();
VatService::init(dirname(TEST_CLI_DIR));

$_SESSION = [];
$_SESSION['cart'] = [
    'p_501' => ['product_id'=>501,'variant_id'=>null,'qty'=>2],
    'p_502' => ['product_id'=>502,'variant_id'=>null,'qty'=>1],
];

// Cart::enriched expects raw context (seller/customer country) and resolves via VatService internally
$ctx = ['seller_country'=>'SK','customer_country'=>'SK','is_b2b'=>false,'customer_vat_id_valid'=>false,'seller_oss_enabled'=>false];
$cart = Cart::enriched($ctx);
// 53.1 — items have per-line VAT
$item0 = $cart['items'][0];
assert_true(isset($item0['vat_amount']), 'Cart: per-line vat_amount present');
assert_true(((int)$item0['vat_amount']) > 0, 'Cart: per-line vat_amount > 0 (domestic)');

// 53.2 — Orders::create persists scenario + per-line VAT
$customer = [
    'name'=>'Test','email'=>'t@x.com','phone'=>'',
    'address'=>['country'=>'SK','street'=>'X','city'=>'X','zip'=>'00000'],
    'currency'=>'eur','locale'=>'sk','shipping_cost'=>0,'tax_amount'=>0,
    'vat_scenario'=>'domestic',
];
$orderId = Orders::create($cart['items'], $customer, 'cash');
$order = $pdo->query("SELECT vat_scenario, vat_total, net_total FROM orders WHERE id={$orderId}")->fetch();
assert_equals('domestic', $order['vat_scenario'], 'Orders: vat_scenario persisted');
assert_true((int)$order['vat_total']  > 0, 'Orders: vat_total persisted');
assert_true((int)$order['net_total']  > 0, 'Orders: net_total persisted');

// 53.3 — order_items carry vat_class + vat_rate + vat_amount + net_amount
$it = $pdo->query("SELECT vat_class, vat_rate, vat_amount, net_amount FROM order_items WHERE order_id={$orderId} ORDER BY id ASC LIMIT 1")->fetch();
assert_equals('standard', $it['vat_class'], 'Orders: order_items.vat_class');
assert_true((int)$it['vat_rate']   > 0, 'Orders: order_items.vat_rate');
assert_true((int)$it['vat_amount'] > 0, 'Orders: order_items.vat_amount');
assert_true((int)$it['net_amount'] > 0, 'Orders: order_items.net_amount');

// 53.4 — Reverse-charge → cart items vat=0
$ctxRC = ['seller_country'=>'SK','customer_country'=>'DE','is_b2b'=>true,'customer_vat_id_valid'=>true,'seller_oss_enabled'=>false];
$cartRC = Cart::enriched($ctxRC);
assert_equals(0, (int)$cartRC['items'][0]['vat_amount'], 'Cart: reverse_charge vat=0');

// 53.5 — Non-VAT payer → cart items vat=0 and note key is carried
$ctxNonVat = ['seller_country'=>'SK','seller_vat_registered'=>false,'customer_country'=>'SK','is_b2b'=>false,'customer_vat_id_valid'=>false,'seller_oss_enabled'=>false];
$cartNonVat = Cart::enriched($ctxNonVat);
assert_equals('non_vat_payer', $cartNonVat['vat_scenario'], 'Cart: non-VAT payer scenario');
assert_equals(0, (int)$cartNonVat['vat_total'], 'Cart: non-VAT payer vat_total=0');
assert_equals('vat.non_vat_payer_note', $cartNonVat['reverse_charge_note_key'], 'Cart: non-VAT payer note key');
