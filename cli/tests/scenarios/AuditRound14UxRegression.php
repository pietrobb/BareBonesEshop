<?php
/**
 * Round 14 (review "kolo 6", 2026-11-03), PR "usability": a store's further language (?lang=xx) was lost after the
 * first click — storefront links carried no ?lang=, so the product link of the English page opened in the store
 * language and the English slug answered with a 301 to the store language's slug (UX2).
 */

echo "\n--- Audit R14: usability (language kept across links) ---\n";

$r14uFail = static fn(string $what, Throwable $e) => assert_true(false, "R14 UX {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');

// --- UX2: the active further language stays on every storefront link ----------------------------------------------
$r14uSavedLocale = I18n::locale();
$r14uSavedStore = StoreContext::current();
try {
    Bootstrap::initStoreContext('/en/', '', 'us');
    I18n::setLocale('es');
    assert_equals('es', Bootstrap::secondaryLocale(), 'R14 UX2 fixture: Spanish is a further language of the /en/ store');
    $r14uProduct = StorefrontViewModel::productUrl('r14u-producto');
    $r14uCategory = StorefrontViewModel::categoryUrl('r14u-categoria');
    assert_true(str_ends_with($r14uProduct, '/en/product/r14u-producto?lang=es') && str_contains($r14uCategory, 'lang=es'),
        "R14 UX2: product and category links of a Spanish page keep ?lang=es ({$r14uProduct}, {$r14uCategory})");
    assert_equals(Template::baseUrl() . '/en/cart?x=1&lang=es#top', Template::storeUrl('/cart?x=1#top'),
        'R14 UX2: the language goes into the query, before a #fragment');
    assert_equals('https://example.test/a', Template::storeUrl('https://example.test/a'), 'R14 UX2: an absolute URL is left alone');

    $r14uRedirect = SlugRepair::canonicalRedirect('r14u-product', 'r14u-producto', $r14uProduct, ['lang' => 'es', 'sort' => 'name_asc']);
    assert_true(is_string($r14uRedirect) && substr_count($r14uRedirect, 'lang=') === 1 && str_contains($r14uRedirect, 'sort=name_asc'),
        'R14 UX2: the redirect to another language\'s slug carries ?lang= once (' . var_export($r14uRedirect, true) . ')');

    $r14uHead = Seo::head('T', 'D', $r14uProduct);
    preg_match('#<link rel="canonical" href="([^"]*)"#', $r14uHead, $r14uCanonical);
    preg_match('#hreflang="en" href="([^"]*)"#', $r14uHead, $r14uEnglish);
    assert_true(str_ends_with((string)($r14uCanonical[1] ?? ''), '/en/product/r14u-producto?lang=es') && substr_count((string)$r14uCanonical[1], 'lang=') === 1
        && str_ends_with((string)($r14uEnglish[1] ?? ''), '/en/product/r14u-producto'),
        'R14 UX2: the canonical keeps one ?lang=es and the store language\'s alternate has none (' . ($r14uCanonical[1] ?? '') . ' / ' . ($r14uEnglish[1] ?? '') . ')');

    assert_equals(Template::baseUrl() . '/en/order-done', Template::withOrderLang(Template::storeUrl('/order-done'), ['locale' => 'en']),
        'R14 UX2: a page about an order in the store language drops the browsing language');
    assert_equals('/a?x=1&lang=es', Bootstrap::withLang('/a?lang=de&x=1', 'es'), 'R14 UX2: withLang replaces a language already set');
    assert_equals('/a?x=1#f', Bootstrap::withoutLang('/a?x=1&lang=es#f'), 'R14 UX2: withoutLang keeps the rest of the URL');

    I18n::setLocale('en');
    assert_true(!str_contains(StorefrontViewModel::productUrl('r14u-product'), 'lang='), 'R14 UX2: the store language needs no ?lang=');
} catch (Throwable $e) { $r14uFail('UX2', $e); } finally {
    StoreContext::activate($r14uSavedStore);
    I18n::setLocale($r14uSavedLocale);
}

// --- UX3: the admin names payment methods, not their codes -------------------------------------------------------
try {
    I18n::setLocale('en');
    $r14uLabel = static fn(array $order): string => method_exists(PaymentService::class, 'adminLabel') ? PaymentService::adminLabel($order) : (string)($order['payment_method'] ?? '');
    assert_equals('Cash on delivery', $r14uLabel(['payment_method' => 'cod', 'payment_method_snapshot' => json_encode(['code' => 'cod', 'label' => 'Dobierka', 'service_type' => 'cod'])]),
        'R14 UX3: a seeded method name reads in the admin\'s language');
    assert_equals('Bank transfer', $r14uLabel(['payment_method' => 'bank_transfer', 'locale' => 'sk']), 'R14 UX3: an order without a snapshot shows its type\'s name in the admin\'s language');
    assert_equals('Platba v hotovosti u nás', $r14uLabel(['payment_method' => 'cash', 'payment_method_snapshot' => json_encode(['code' => 'cash', 'label' => 'Platba v hotovosti u nás'])]),
        'R14 UX3: a method the merchant named keeps its name');
    $r14uAdminSources = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Admin/Orders.php') . (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Admin/Operations.php');
    assert_true(!preg_match('/esc\(\$(order|o|incident)\[\'payment_method\'\]( \?\? \'\')?\)\s*\?>\s*<\/(span|td)>/', $r14uAdminSources)
        && substr_count($r14uAdminSources, 'PaymentService::adminLabel(') >= 4,
        'R14 UX3: the order lists, the order detail and the operations page print the method name');
} catch (Throwable $e) { $r14uFail('UX3', $e); } finally {
    I18n::setLocale($r14uSavedLocale);
}

// --- UX5: a checkout without a payment method says why and what to do ----------------------------------------------
$r14uDb = Db::get();
try {
    I18n::setLocale('en');
    $r14uCart = [['product_type' => 'physical', 'qty' => 1, 'weight_g' => 500]];
    $r14uRates = (array)(CheckoutService::deliveryQuote($r14uCart, 2000, 'SK', '')['rates'] ?? []);
    assert_true(count($r14uRates) >= 2, 'R14 UX5 fixture: the SK cart has at least two deliveries');
    [$r14uA, $r14uB] = [$r14uRates[0], $r14uRates[1]];
    $r14uScope = StoreContext::current()->providerId();
    $r14uMessage = static fn(string $shipping): string => method_exists(CheckoutService::class, 'paymentUnavailableMessage')
        ? CheckoutService::paymentUnavailableMessage($r14uCart, 2000, 'SK', 'eur', $shipping, null) : I18n::t('payment_unavailable');
    $r14uDb->beginTransaction();
    // Only cash on delivery, and only with delivery B (as with pickup + a store without an IBAN).
    $r14uDb->prepare('UPDATE payment_methods SET active = 0 WHERE store_id = ?')->execute([$r14uScope]);
    $r14uDb->prepare("INSERT INTO payment_methods (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, requires_online, active, sort_order)
        VALUES (?, 'r14u-cod', 'manual', 'R14U COD', '', 'cod', 0, NULL, 0, 0, 0, 1, 1)")->execute([$r14uScope]);
    $r14uDb->prepare("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES (?, 'r14u-cod', 'SK')")->execute([$r14uScope]);
    $r14uDb->prepare("INSERT INTO payment_method_shipping (store_id, method_code, shipping_method_code) VALUES (?, 'r14u-cod', ?)")->execute([$r14uScope, (string)$r14uB['method_code']]);
    assert_true(PaymentService::availableMethods('SK', 'eur', 2000, (string)$r14uA['method_code']) === [], 'R14 UX5 fixture: delivery A has no payment method');
    $r14uWithA = $r14uMessage((string)$r14uA['method_code']);
    assert_true(str_contains($r14uWithA, (string)$r14uA['label']) && str_contains($r14uWithA, (string)$r14uB['label']) && $r14uWithA !== I18n::t('payment_unavailable'),
        "R14 UX5: the buyer reads that the chosen delivery has no payment and which delivery has one ({$r14uWithA})");
    $r14uDb->prepare("UPDATE payment_methods SET active = 0 WHERE store_id = ? AND code = 'r14u-cod'")->execute([$r14uScope]);
    $r14uEmail = trim((string)(StoreContext::current()->legal()['email'] ?? ''));
    $r14uNone = $r14uMessage((string)$r14uA['method_code']);
    assert_true($r14uEmail !== '' && str_contains($r14uNone, $r14uEmail), "R14 UX5: with no payment at all the buyer gets the store's e-mail ({$r14uNone})");

    $r14uSources = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Storefront/CheckoutPages.php') . (string)file_get_contents(TEST_CLI_DIR . '/../src/Http/ApiHandlers.php')
        . (string)file_get_contents(TEST_CLI_DIR . '/../themes/default/assets/storefront.js') . (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Admin/Operations.php');
    assert_true(substr_count($r14uSources, 'CheckoutService::paymentUnavailableMessage(') === 2 && str_contains($r14uSources, 'result.unavailable || text.paymentUnavailable')
        && str_contains($r14uSources, "BankTransferInstructions::storeIban() === '' && array_filter(\$methods"),
        'R14 UX5: the checkout page, the payment-methods API (and its script) explain it, and admin → Payments says bank transfer is hidden without an IBAN');
} catch (Throwable $e) { $r14uFail('UX5', $e); } finally {
    if ($r14uDb->inTransaction()) $r14uDb->rollBack();
    I18n::setLocale($r14uSavedLocale);
}

// --- UX4: editor and fulfillment change their own password and TOTP (rendered admin, own SQLite database) -----------
$r14uTmp = test_fixture_path('r14-ux-admin');
mkdir($r14uTmp . '/sessions', 0777, true);
copy(dirname(TEST_CLI_DIR) . '/stores.example.json', $r14uTmp . '/stores.json');
$r14uDbPath = $r14uTmp . '/admin.sqlite';
$r14uKey = base64_encode(str_repeat('B', 32));
$r14uPreviousKey = getenv('BBE_ADMIN_IDENTITY_KEY');
$r14uSuiteDriver = Db::driver();
$r14uTokens = [];
$r14uIds = [];
try {
    foreach ($GLOBALS as $r14uValue) if ($r14uValue instanceof PDOStatement) $r14uValue->closeCursor();
    putenv('BBE_ADMIN_IDENTITY_KEY=' . $r14uKey);
    $r14uPdo = new PDO('sqlite:' . $r14uDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    try {
        Db::init($r14uPdo, 'sqlite');
        (new Migrator($r14uPdo, dirname(TEST_CLI_DIR) . '/migrations', $r14uTmp . '/migrate.lock'))->apply();
        foreach (['owner', 'editor', 'fulfillment'] as $r14uWho) {
            $r14uIds[$r14uWho] = AdminIdentity::create("r14u-{$r14uWho}", 'R14u-password-' . $r14uWho, $r14uWho, false);
            AdminIdentity::grantStore($r14uIds[$r14uWho], 'default', ['*']);
            $r14uTokens[$r14uWho] = (string)(AdminIdentity::authenticate("r14u-{$r14uWho}", 'R14u-password-' . $r14uWho, '', '127.0.14.80', 'R14U')['token'] ?? '');
        }
    } finally {
        Db::init($r14uDb, $r14uSuiteDriver);
    }
    file_put_contents($r14uTmp . '/admin.php', <<<'PHP'
<?php
[, $root, $dbPath, $dir, $key, $token, $getJson, $postJson] = $argv;
require $root . '/src/autoload.php';
putenv('BBE_ADMIN_IDENTITY_KEY=' . $key);
$db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Db::init($db, 'sqlite');
JobQueue::setDir($dir . '/jobs');
StoreSettings::setPathForTesting($dir . '/stores.json');
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate.sqlite'));
$post = json_decode($postJson, true);
ini_set('session.use_cookies', '0');
ini_set('session.use_strict_mode', '0');
session_save_path($dir . '/sessions');
session_id('r14u' . substr(hash('sha256', $token), 0, 16));
session_start();
$_SESSION['csrf_token'] = 'r14u';
$_SESSION['admin_identity_token'] = $token;
$_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.14.81';
$_SERVER['HTTP_USER_AGENT'] = 'R14U';
$_SERVER['REQUEST_URI'] = '/admin.php';
$_GET = json_decode($getJson, true);
$_POST = $post === [] ? [] : $post + ['csrf_token' => 'r14u'];
ob_start();
register_shutdown_function(static function(): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nR14URESULT" . json_encode(['code' => http_response_code() ?: 200, 'flash' => $_SESSION['flash'] ?? null, 'out' => $out], JSON_INVALID_UTF8_SUBSTITUTE);
});
require $root . '/src/Entrypoints/Admin.php';
PHP);
    $r14uRun = static function (string $who, array $get, array $post = []) use ($r14uTmp, $r14uDbPath, $r14uKey, &$r14uTokens): array {
        $process = proc_open([PHP_BINARY, $r14uTmp . '/admin.php', dirname(TEST_CLI_DIR), $r14uDbPath, $r14uTmp, $r14uKey, $r14uTokens[$who], json_encode($get + ['lang' => 'en']), json_encode($post)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $at = strrpos($stdout, 'R14URESULT');
        $result = $at === false ? null : json_decode(substr($stdout, $at + 10), true);
        return is_array($result) ? $result : ['code' => null, 'flash' => null, 'out' => substr($stderr . ' | ' . substr($stdout, -300), 0, 900)];
    };
    $r14uAccount = static function (string $who) use ($r14uDbPath, &$r14uIds): array {
        $stmt = (new PDO('sqlite:' . $r14uDbPath))->prepare('SELECT password_hash, mfa_required, totp_secret_encrypted FROM admin_accounts WHERE id = ?');
        $stmt->execute([$r14uIds[$who]]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    $r14uLabel = static fn(string $label, array $r): string => $label . ' (code=' . var_export($r['code'], true) . ' flash=' . json_encode($r['flash'] ?? null) . ' out=' . substr(strip_tags((string)$r['out']), 0, 160) . ')';

    foreach (['editor', 'fulfillment'] as $r14uWho) {
        $r = $r14uRun($r14uWho, ['p' => 'my_account']);
        assert_true($r['code'] === 200 && str_contains($r['out'], 'action=password') && str_contains($r['out'], 'action=mfa') && str_contains($r['out'], 'r14u-' . $r14uWho),
            $r14uLabel("R14 UX4: the {$r14uWho} opens My account with the password and TOTP forms", $r));
        assert_true(str_contains($r['out'], 'href="admin.php?p=my_account"'), "R14 UX4: the {$r14uWho}'s side menu links to My account");
        $r = $r14uRun($r14uWho, ['p' => 'admin_users']);
        assert_true($r['code'] === 403, $r14uLabel("R14 UX4: the {$r14uWho} still cannot open Admin users", $r));
    }

    // UX8: status lists offer only moves the role may make and the payment state allows.
    $r14uOrders = new PDO('sqlite:' . $r14uDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r14uOrders->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, store_id)
        VALUES (?, 'R14U Buyer', 'r14u-ux8@example.test', '+421900114008', ?, 2400, 390, 0, 0, 2790, 'eur', 'pending', 'pending', 'card', '', 'default')")
        ->execute(['R14U-' . bin2hex(random_bytes(4)), json_encode(['street' => 'Status Street 8', 'city' => 'Trnava', 'zip' => '91701', 'country' => 'SK'])]);
    $r14uOrderId = (int)$r14uOrders->lastInsertId();
    $r14uOrders = null;
    $r14uOptions = static fn(array $r, string $select): array => preg_match('#<select ' . $select . '[^>]*>(.*?)</select>#s', (string)$r['out'], $m) === 1
        ? (preg_match_all('#<option value="([a-z_]+)"#', $m[1], $o) ? $o[1] : []) : ['(no select)'];
    $r = $r14uRun('owner', ['p' => 'order', 'id' => (string)$r14uOrderId]);
    $r14uDetail = $r14uOptions($r, 'name="new_status"');
    assert_true(!in_array('paid', $r14uDetail, true) && in_array('cancelled', $r14uDetail, true),
        $r14uLabel('R14 UX8: an order whose card payment is not confirmed is not offered "paid" (' . json_encode($r14uDetail) . ')', $r));
    $r14uBulk = $r14uOptions($r14uRun('fulfillment', ['p' => 'orders']), 'id="bulk-status"');
    assert_true(!in_array('refunded', $r14uBulk, true) && in_array('shipped', $r14uBulk, true),
        'R14 UX8: fulfillment (no orders.refund) is not offered "refunded" in bulk (' . json_encode($r14uBulk) . ')');

    // TOTP: a wrong current password changes nothing; the right one enrolls a new secret, shown exactly once.
    $r = $r14uRun('editor', ['p' => 'my_account', 'action' => 'mfa'], ['current_password' => 'wrong']);
    assert_true(($r14uAccount('editor')['totp_secret_encrypted'] ?? null) === null && ($r['flash']['type'] ?? '') === 'err',
        $r14uLabel('R14 UX4: a new TOTP needs the current password', $r));
    $r14uRun('editor', ['p' => 'my_account', 'action' => 'mfa'], ['current_password' => 'R14u-password-editor', 'account_id' => (string)$r14uIds['owner']]);
    $r14uEditor = $r14uAccount('editor');
    assert_true((string)($r14uEditor['totp_secret_encrypted'] ?? '') !== '' && (int)($r14uEditor['mfa_required'] ?? 0) === 1 && ($r14uAccount('owner')['totp_secret_encrypted'] ?? null) === null,
        'R14 UX4: the editor enrolls its own TOTP (a posted account_id of another account is ignored)');
    $r = $r14uRun('editor', ['p' => 'my_account']);
    $r14uAgain = $r14uRun('editor', ['p' => 'my_account']);
    assert_true(preg_match('#data-my-account-enrollment>.*?<code>[A-Z2-7]{32}</code>#s', $r['out']) === 1 && !str_contains($r14uAgain['out'], 'data-my-account-enrollment'),
        $r14uLabel('R14 UX4: the new secret and recovery codes are shown once', $r));

    // Password: the fulfillment account changes its own, never the owner's.
    $r14uOwnerHash = (string)($r14uAccount('owner')['password_hash'] ?? '');
    $r = $r14uRun('fulfillment', ['p' => 'my_account', 'action' => 'password'], ['current_password' => 'wrong', 'password' => 'R14u-new-fulfillment-pw']);
    assert_true(password_verify('R14u-password-fulfillment', (string)($r14uAccount('fulfillment')['password_hash'] ?? '')),
        $r14uLabel('R14 UX4: a wrong current password does not change the password', $r));
    $r = $r14uRun('fulfillment', ['p' => 'my_account', 'action' => 'password'],
        ['current_password' => 'R14u-password-fulfillment', 'password' => 'R14u-new-fulfillment-pw', 'account_id' => (string)$r14uIds['owner']]);
    assert_true(password_verify('R14u-new-fulfillment-pw', (string)($r14uAccount('fulfillment')['password_hash'] ?? '')) && (string)($r14uAccount('owner')['password_hash'] ?? '') === $r14uOwnerHash,
        $r14uLabel('R14 UX4: the fulfillment account changes its own password (the owner\'s stays)', $r));
} catch (Throwable $e) { $r14uFail('UX4', $e); } finally {
    Db::init($r14uDb, $r14uSuiteDriver);
    $r14uPdo = null;
    if ($r14uPreviousKey === false) putenv('BBE_ADMIN_IDENTITY_KEY');
    else putenv('BBE_ADMIN_IDENTITY_KEY=' . $r14uPreviousKey);
}
