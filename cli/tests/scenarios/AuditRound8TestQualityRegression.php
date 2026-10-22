<?php

// Review round 8 (test quality): asserts that kill mutants which survived the previous suite.
echo "\n--- Review round 8: test quality ---\n";

// Mutant 1: a domestic B2B buyer with a valid VAT ID was switched to reverse charge (0 % VAT) and no test failed —
// the domestic case was only checked without a VAT ID. A supply inside one country always carries the seller's VAT.
foreach (['SK', 'CZ', 'DE'] as $r8qCountry) {
    $r8qSc = VatService::resolveScenario(['seller_country' => $r8qCountry, 'customer_country' => strtolower($r8qCountry),
        'is_b2b' => true, 'customer_vat_id_valid' => true, 'seller_oss_enabled' => true]);
    assert_equals('domestic', $r8qSc['scenario'], "R8 tests: domestic B2B with a valid VAT ID stays domestic ({$r8qCountry})");
    assert_true($r8qSc['applies_vat'] === true && $r8qSc['vat_rate'] === null && $r8qSc['note_key'] === '',
        "R8 tests: domestic B2B charges the seller's VAT per line, with no exemption note ({$r8qCountry})");
    assert_equals($r8qCountry, $r8qSc['rate_country'], "R8 tests: domestic B2B uses the seller-country rate ({$r8qCountry})");
}
// Reverse charge needs all three conditions; dropping any one of them must leave VAT on.
$r8qSc = VatService::resolveScenario(['seller_country' => 'SK', 'customer_country' => 'DE', 'is_b2b' => true, 'customer_vat_id_valid' => false]);
assert_equals('domestic_subthreshold', $r8qSc['scenario'], 'R8 tests: intra-EU B2B without a valid VAT ID is not reverse charge');
$r8qSc = VatService::resolveScenario(['seller_country' => 'SK', 'customer_country' => 'DE', 'is_b2b' => false, 'customer_vat_id_valid' => true]);
assert_equals('domestic_subthreshold', $r8qSc['scenario'], 'R8 tests: intra-EU B2C with a VAT ID is not reverse charge');
$r8qSc = VatService::resolveScenario(['seller_country' => 'SK', 'customer_country' => 'DE', 'is_b2b' => true, 'customer_vat_id_valid' => true]);
assert_true($r8qSc['scenario'] === 'reverse_charge' && $r8qSc['vat_rate'] === 0.0 && $r8qSc['applies_vat'] === false
    && $r8qSc['rate_country'] === 'DE', 'R8 tests: intra-EU B2B with a valid VAT ID is reverse charge at 0 % in the customer country');

assert_true(!InvoiceService::isReverseCharge(['country' => 'SK'], ['country' => 'sk', 'ic_dph' => 'SK2020123456']),
    'R8 tests: invoice to a domestic company with an IČ DPH is not reverse charge');
assert_true(!InvoiceService::isReverseCharge(['country' => 'SK'], ['country' => 'DE', 'ic_dph' => '  ']),
    'R8 tests: invoice without a VAT ID (blank) is not reverse charge');
assert_true(!InvoiceService::isReverseCharge(['country' => 'SK'], ['country' => 'US', 'ic_dph' => 'US123']),
    'R8 tests: invoice outside the EU is not reverse charge');
assert_true(!InvoiceService::isReverseCharge(['country' => ''], ['country' => 'DE', 'ic_dph' => 'DE123456789']),
    'R8 tests: invoice without a seller country is not reverse charge');
assert_true(InvoiceService::isReverseCharge(['country' => 'SK'], ['country' => 'de', 'ic_dph' => 'DE123456789']),
    'R8 tests: invoice to a German company with a VAT ID is reverse charge');

// Mutant 2: GDPR erase could keep the customer's name, phone and VAT ID — only the e-mail, password and addresses were checked.
$r8qDb = Db::get();
$r8qInsert = $r8qDb->prepare("INSERT INTO customers (store_id, email, password_hash, name, phone, vat_id, vat_country, vat_validated_at, status)
    VALUES ('default', ?, '!test!', ?, ?, ?, 'SK', '2026-10-01 00:00:00', 'active')");
$r8qInsert->execute(['r8q-erase@example.test', 'R8Q Erased Name', '+421 900 111 222', 'SK2020111222']);
$r8qSubject = (int)$r8qDb->lastInsertId();
$r8qInsert->execute(['r8q-keep@example.test', 'R8Q Erased Name', '+421 900 111 222', 'SK2020111222']);
$r8qOther = (int)$r8qDb->lastInsertId();
GdprService::anonymizeCustomer($r8qSubject, 'r8q-test');
$r8qRow = static fn(int $id): array => $r8qDb->query('SELECT email, name, phone, vat_id, vat_country, vat_validated_at, status FROM customers WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
$r8qErased = $r8qRow($r8qSubject);
assert_equals(['deleted-' . $r8qSubject . '@anonymized.local', '', '', null, null, null, 'anonymized'], array_values($r8qErased),
    'R8 tests: GDPR erase clears the account name, phone and VAT ID (' . json_encode($r8qErased) . ')');
assert_equals(['r8q-keep@example.test', 'R8Q Erased Name', '+421 900 111 222', 'SK2020111222', 'SK', '2026-10-01 00:00:00', 'active'], array_values($r8qRow($r8qOther)),
    'R8 tests: GDPR erase leaves another customer with the same name and phone untouched');

// Mutant 3: StripePayment::verifySignature had no direct test — any check in it could be removed.
$r8qSecret = 'whsec_r8q_0123456789abcdef';
$r8qStripe = new StripePayment(['stripe_webhook_secret' => $r8qSecret]);
$r8qBody = '{"id":"evt_r8q","type":"checkout.session.completed"}';
$r8qNow = (string)time();
$r8qSig = static fn(string $t, string $body = '', string $secret = ''): string => hash_hmac('sha256', $t . '.' . ($body !== '' ? $body : $r8qBody), $secret !== '' ? $secret : $r8qSecret);
$r8qGood = $r8qSig($r8qNow);
assert_true($r8qStripe->verifySignature($r8qBody, 't=' . $r8qNow . ',v1=' . $r8qGood), 'R8 tests: Stripe accepts a valid current signature');
assert_true($r8qStripe->verifySignature($r8qBody, 't=' . $r8qNow . ', v0=abc, v1=' . str_repeat('0', 64) . ', v1=' . strtoupper($r8qGood)),
    'R8 tests: Stripe accepts any one matching v1 among several (rolled secrets), case-insensitively');
$r8qRejected = [
    'tampered body' => [$r8qBody . ' ', 't=' . $r8qNow . ',v1=' . $r8qGood],
    'wrong secret' => [$r8qBody, 't=' . $r8qNow . ',v1=' . $r8qSig($r8qNow, '', 'whsec_r8q_other_secret_xx')],
    'signature over another timestamp' => [$r8qBody, 't=' . $r8qNow . ',v1=' . $r8qSig((string)((int)$r8qNow - 1))],
    'old timestamp' => [$r8qBody, 't=' . ((int)$r8qNow - 301) . ',v1=' . $r8qSig((string)((int)$r8qNow - 301))],
    'future timestamp' => [$r8qBody, 't=' . ((int)$r8qNow + 301) . ',v1=' . $r8qSig((string)((int)$r8qNow + 301))],
    'no timestamp' => [$r8qBody, 'v1=' . $r8qGood],
    'non-numeric timestamp' => [$r8qBody, 't=' . $r8qNow . 'x,v1=' . $r8qSig($r8qNow . 'x')],
    'two different timestamps' => [$r8qBody, 't=' . ((int)$r8qNow - 1000) . ',t=' . $r8qNow . ',v1=' . $r8qGood],
    'no v1' => [$r8qBody, 't=' . $r8qNow . ',v0=' . $r8qGood],
    'empty v1' => [$r8qBody, 't=' . $r8qNow . ',v1='],
    'empty header' => [$r8qBody, ''],
];
foreach ($r8qRejected as $r8qCase => [$r8qPayload, $r8qHeader]) {
    assert_true(!$r8qStripe->verifySignature($r8qPayload, $r8qHeader), "R8 tests: Stripe rejects a webhook with {$r8qCase}");
}
assert_true($r8qStripe->verifySignature($r8qBody, 't=' . ((int)$r8qNow - 290) . ',v1=' . $r8qSig((string)((int)$r8qNow - 290))),
    'R8 tests: Stripe accepts a signature inside the 5-minute tolerance');
foreach (['' => 'no secret', 'whsec_short' => 'a secret shorter than 16 characters', ' ' . $r8qSecret => 'a secret with surrounding whitespace'] as $r8qBad => $r8qCase) {
    $r8qBadSig = hash_hmac('sha256', $r8qNow . '.' . $r8qBody, (string)$r8qBad);
    assert_true(!(new StripePayment(['stripe_webhook_secret' => (string)$r8qBad]))->verifySignature($r8qBody, 't=' . $r8qNow . ',v1=' . $r8qBadSig),
        "R8 tests: Stripe rejects every webhook when configured with {$r8qCase}");
}

// Mutant 4: DigitalDelivery expiry and download-limit checks (off-by-one, removed clause) survived — only the ordinary cases were tested.
$r8qDb->exec("INSERT INTO digital_assets (sku, asset_key, label, storage_path, download_name) VALUES ('R8Q-DIGI', 'r8q-" . bin2hex(random_bytes(3)) . "', 'R8Q Guide', 'r8q/guide.pdf', 'guide.pdf')");
$r8qAsset = (int)$r8qDb->lastInsertId();
$r8qDb->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'r8q-digital', 'R8Q Digital', 'r8q-digital', 0)");
$r8qDb->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, product_type) VALUES ('default', ?, 'R8Q-DIGI', 'r8q-digi', 'R8Q Guide', 500, 0, 'active', 'downloadable')")->execute([(int)$r8qDb->lastInsertId()]);
$r8qProduct = (int)$r8qDb->lastInsertId();
$r8qOrder = static function (string $status = 'paid', string $paymentStatus = 'paid') use ($r8qDb): int {
    $r8qDb->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, store_id, created_at)
        VALUES (?, 'R8Q', 'r8q@example.test', '', '{}', 500, 0, 0, 0, 500, 'eur', ?, ?, 'manual', 'default', " . Db::now() . ")")
        ->execute(['R8Q-' . bin2hex(random_bytes(5)), $status, $paymentStatus]);
    return (int)$r8qDb->lastInsertId();
};
// Returns [entitlement id, raw token].
$r8qEntitlement = static function (?int $limit, int $count, ?int $expiresAt, ?int $orderId = null) use ($r8qDb, $r8qAsset, $r8qOrder, $r8qProduct): array {
    $token = bin2hex(random_bytes(32));
    $orderId ??= $r8qOrder();
    $r8qDb->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'R8Q-DIGI', 'R8Q Guide', 1, 500, 500)")->execute([$orderId, $r8qProduct]);
    $r8qDb->prepare("INSERT INTO digital_entitlements (order_id, order_item_id, asset_id, token_hash, status, download_count, download_limit, expires_at) VALUES (?, ?, ?, ?, 'active', ?, ?, ?)")
        ->execute([$orderId, (int)$r8qDb->lastInsertId(), $r8qAsset, hash('sha256', $token), $count, $limit, $expiresAt]);
    return [(int)$r8qDb->lastInsertId(), $token];
};
$r8qT = time();
$r8qRowOf = static fn(?int $limit, int $count, ?int $expiresAt): array => ['asset_active' => 1, 'payment_status' => 'paid', 'order_status' => 'paid', 'order_item_id' => 0,
    'download_limit' => $limit, 'download_count' => $count, 'expires_at' => $expiresAt];
assert_equals([true, true, false, true], [
    DigitalDelivery::downloadable($r8qRowOf(null, 99, null), $r8qT),
    DigitalDelivery::downloadable($r8qRowOf(3, 0, $r8qT), $r8qT),
    DigitalDelivery::downloadable($r8qRowOf(3, 0, $r8qT - 1), $r8qT),
    DigitalDelivery::downloadable($r8qRowOf(3, 2, $r8qT + 60), $r8qT),
], 'R8 tests: downloadable — no limit/expiry, expiring this second, expired a second ago, one download left');
assert_equals([false, false], [DigitalDelivery::downloadable($r8qRowOf(3, 3, null), $r8qT), DigitalDelivery::downloadable($r8qRowOf(3, 4, null), $r8qT)],
    'R8 tests: downloadable — limit reached or exceeded');

// consume(): counts up to the limit, never past it, never after expiry (expiry second itself still counts).
[$r8qLimited] = $r8qEntitlement(2, 1, null);
assert_equals([true, false], [DigitalDelivery::consume($r8qLimited, $r8qT), DigitalDelivery::consume($r8qLimited, $r8qT)],
    'R8 tests: consume takes the last download of the limit, then refuses');
assert_equals(2, (int)$r8qDb->query('SELECT download_count FROM digital_entitlements WHERE id = ' . $r8qLimited)->fetchColumn(), 'R8 tests: a refused consume does not count');
[$r8qUnlimited] = $r8qEntitlement(null, 50, null);
assert_true(DigitalDelivery::consume($r8qUnlimited, $r8qT), 'R8 tests: consume without a limit always counts');
[$r8qEdge] = $r8qEntitlement(5, 0, $r8qT);
[$r8qGone] = $r8qEntitlement(5, 0, $r8qT - 1);
assert_equals([true, false], [DigitalDelivery::consume($r8qEdge, $r8qT), DigitalDelivery::consume($r8qGone, $r8qT)],
    'R8 tests: consume in the expiry second counts; one second later it is refused');
[$r8qCancelled] = $r8qEntitlement(5, 0, null, $r8qOrder('cancelled', 'paid'));
[$r8qUnpaid] = $r8qEntitlement(5, 0, null, $r8qOrder('pending', 'pending'));
assert_equals([false, false], [DigitalDelivery::consume($r8qCancelled, $r8qT), DigitalDelivery::consume($r8qUnpaid, $r8qT)],
    'R8 tests: consume refuses a cancelled or unpaid order');

// resolve(): the link itself stops at expiry and at a spent limit (no resume pending).
[, $r8qOpen] = $r8qEntitlement(3, 2, $r8qT + 60);
[, $r8qSpent] = $r8qEntitlement(3, 3, $r8qT + 60);
[, $r8qExpired] = $r8qEntitlement(3, 0, $r8qT - 5);
[, $r8qForever] = $r8qEntitlement(null, 500, null);
assert_equals([true, false, false, true], [
    DigitalDelivery::resolve($r8qOpen) !== null, DigitalDelivery::resolve($r8qSpent) !== null,
    DigitalDelivery::resolve($r8qExpired) !== null, DigitalDelivery::resolve($r8qForever) !== null,
], 'R8 tests: resolve — one download left, limit spent, expired, unlimited');

// Empty checkout (the CI WebKit axe failure): the message already ends with a full stop, and the in-text link must not
// be told apart from the text by colour alone (axe link-in-text-block, 1.92:1 against #333).
$r8qEmpty = Template::render('storefront/checkout-empty', ['breadcrumbs' => [], 'message' => 'Váš košík je prázdny.', 'home_url' => '/', 'continue_label' => 'Pokračovať v nákupe', 't' => I18n::all()]);
assert_true(!str_contains($r8qEmpty, '..') && (bool)preg_match('/<a [^>]*text-decoration:\s*underline[^>]*>Pokračovať v nákupe<\/a>/u', $r8qEmpty),
    'R8 tests: the empty checkout has one full stop and an underlined continue-shopping link');

// Credit note vs exchange of the same returned units. An exchange locks the RMA; the credit note checked the
// quantities before its transaction and took no lock, so on MySQL both committed (refund and replacement for one unit),
// and on SQLite the credit failed with "database is locked". It now waits for the RMA and re-checks: a clean refusal.
require_once dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php';
foreach ($GLOBALS as $r8qValue) if ($r8qValue instanceof PDOStatement) $r8qValue->closeCursor();
$pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (97481, 'default', 1, 'R8Q credit race', 'r8q-credit-race', 'R8Q-CREDIT', 'simple', 1500, 'eur', 'active', 0, 'backorder')");
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
Cart::clear();
Cart::add(97481, null, 2);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['csrf_token'] = 'r8q-credit';
$_POST = ['csrf_token' => 'r8q-credit', 'name' => 'R8Q Buyer', 'email' => 'r8q-credit@example.com', 'country' => 'SK',
    'street' => 'Credit 1', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'packeta', 'payment_method' => 'bank_transfer'];
$r8qCheckout = apiCheckout();
$_POST = [];
Cart::clear();
$r8qCreditOrder = (int)($r8qCheckout['order_id'] ?? 0);
$pdo->exec("UPDATE orders SET status='paid', payment_status='paid', payment_id='' WHERE id={$r8qCreditOrder}");
$r8qInvoice = InvoiceService::issue($r8qCreditOrder);
$pdo->exec("UPDATE orders SET status='delivered' WHERE id={$r8qCreditOrder}");
[, $r8qRma] = Rma::create($r8qCreditOrder, null, 'R8Q credit race', [(int)Orders::items($r8qCreditOrder)[0]['id'] => 2]);
Rma::setStatus((int)$r8qRma, 'approved');
$r8qRmaItem = (int)Rma::items((int)$r8qRma)[0]['id'];
Rma::receive((int)$r8qRma, [$r8qRmaItem => ['quantity' => 2, 'disposition' => 'repair']], 'r8q-credit-receipt');
$r8qHolder = test_fixture_path('r8q-credit-race') . '.php';
file_put_contents($r8qHolder, <<<'PHP'
<?php
// Exchanges both received units in a transaction held open for 2 s, on the parent's database file and store.
[, $root, $file, $store, $rma, $item, $product] = $argv;
require $root . '/src/autoload.php';
StoreContext::activate(unserialize((string)file_get_contents($store), ['allowed_classes' => [StoreContext::class]]));
$db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_TIMEOUT => 10, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Db::init($db, 'sqlite');
$db->beginTransaction();
try {
    Rma::createExchange((int)$rma, (int)$item, 2, (int)$product, null, 'r8q-credit-race-x');
} catch (Throwable $e) {
    echo 'child: ', $e->getMessage(), ' | store ', StoreContext::current()->id(), ' | rma ', json_encode($db->query('SELECT store_id, status FROM rma_requests WHERE id = ' . (int)$rma)->fetch()), "\n";
    exit(1);
}
echo "held\n";
usleep(2000000);
$db->commit();
echo "committed\n";
PHP);
$r8qStore = $r8qHolder . '.store';
file_put_contents($r8qStore, serialize(StoreContext::current()));
$r8qFile = '';
foreach (Db::get()->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $r8qDbRow) if ($r8qDbRow['name'] === 'main') $r8qFile = (string)$r8qDbRow['file'];
assert_true(!Db::get()->inTransaction() && $r8qFile !== '', 'R8 tests: the credit race starts from committed data in a database file (file: ' . $r8qFile . ')');
$r8qProc = proc_open([PHP_BINARY, $r8qHolder, dirname(TEST_CLI_DIR), $r8qFile, $r8qStore, (string)$r8qRma, (string)$r8qRmaItem, '97481'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r8qPipes);
fclose($r8qPipes[0]);
$r8qHeld = trim((string)fgets($r8qPipes[1]));
try {
    InvoiceService::credit((int)$r8qInvoice['id'], (int)$r8qRma, [$r8qRmaItem => 2], 'r8q-credit-race-c');
    $r8qCredit = 'credited';
} catch (Throwable $e) {
    $r8qCredit = $e->getMessage();
}
$r8qHolderOut = trim((string)stream_get_contents($r8qPipes[1]) . stream_get_contents($r8qPipes[2]));
fclose($r8qPipes[1]);
fclose($r8qPipes[2]);
proc_close($r8qProc);
@unlink($r8qHolder);
@unlink($r8qStore);
$r8qCommitted = static fn(string $sql): int => (int)$pdo->query($sql)->fetchColumn();
assert_equals(['held', 'Credit quantity exceeds received uncommitted quantity', 'committed', 2, 0], [$r8qHeld, $r8qCredit, $r8qHolderOut,
    $r8qCommitted("SELECT COALESCE(SUM(quantity), 0) FROM return_exchanges WHERE rma_item_id = {$r8qRmaItem}"),
    $r8qCommitted("SELECT COALESCE(SUM(quantity), 0) FROM credit_note_items WHERE rma_item_id = {$r8qRmaItem}")],
    'R8 tests: a credit note started while an exchange of the same units is uncommitted waits for it and is refused (2 exchanged, 0 credited)');
