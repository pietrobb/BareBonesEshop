<?php
/**
 * Round 13 (review 2026-11-03), PR "usability 1–7 + README": checkout offered bank transfer to a store without a valid
 * IBAN (UX1); the CLI install left the cz sample store public with an EUR-priced catalog and the keyless card method
 * active (UX2/UX3, covered in AuditInstallSmokeRegression and AuditRound5DeployRegression); the page after checkout
 * showed no summary of the order (UX4); guest orders stayed outside the account the buyer registered and verified
 * with the same e-mail (UX5); the home hero without a heading said "Sauna & Drevené výrobky" in every shop (UX6);
 * Admin → Stores and seller had no BIC field (UX7).
 */

echo "\n--- Audit R13: usability (IBAN, keyless card, order summary, guest orders, home heading, BIC) ---\n";

if (!function_exists('renderOrderDone')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Storefront/CheckoutPages.php';
$r13uFail = static fn(string $what, Throwable $e) => assert_true(false, "R13 UX {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
$r13uPrevStore = StoreContext::current();
$r13uStoreId = $r13uPrevStore->id();
$r13uWithLegal = static function (array $legal) use ($r13uStoreId): void {
    $stores = Bootstrap::stores();
    $stores['stores'][$r13uStoreId]['legal'] = $legal + (array)($stores['stores'][$r13uStoreId]['legal'] ?? []);
    StoreContext::activate(StoreContext::resolve($stores, null, null, $r13uStoreId));
};

// --- UX1: bank transfer only to a valid IBAN -----------------------------------------------------------------------
try {
    $r13uBankScope = StoreContext::current()->providerId();
    $pdo->prepare("UPDATE payment_methods SET active = 1 WHERE store_id = ? AND code = 'bank_transfer'")->execute([$r13uBankScope]);
    $r13uOffered = static fn(): bool => in_array('bank_transfer', array_column(PaymentService::availableMethods('SK', 'eur', 1000), 'code'), true);
    $r13uWithLegal(['iban' => 'SK31 1200 0000 1987 4263 7541']);
    assert_true($r13uOffered(), 'R13 UX1: a store with a valid IBAN offers bank transfer');
    foreach (['SK00 1100 0000 0012 3456 7890' => 'the sample IBAN with wrong check digits', '' => 'no IBAN'] as $r13uIban => $r13uWhy) {
        $r13uWithLegal(['iban' => $r13uIban]);
        assert_true(!$r13uOffered() && PaymentService::selectMethod('bank_transfer', 'SK', 'eur', 1000) === null,
            "R13 UX1: bank transfer is not offered (nor selectable) with {$r13uWhy}");
    }
} catch (Throwable $e) { $r13uFail('UX1', $e); } finally { StoreContext::activate($r13uPrevStore); }

// --- UX3: a fresh install switches off online methods whose shipped gateway has no keys -----------------------------
try {
    $r13uMem = new PDO('sqlite::memory:');
    $r13uMem->exec('CREATE TABLE payment_methods (store_id TEXT, code TEXT, provider TEXT, active INTEGER, requires_online INTEGER)');
    $r13uMem->exec("INSERT INTO payment_methods VALUES ('default', 'card', 'stripe', 1, 1), ('cz', 'card', 'stripe', 1, 1), ('default', 'bank_transfer', 'manual', 1, 0),
        ('default', 'ext', 'r13u_extension_gateway', 1, 1), ('pl', 'payu', 'payu', 0, 1)");
    $r13uOff = method_exists(PaymentReadiness::class, 'deactivateKeyless') ? PaymentReadiness::deactivateKeyless($r13uMem, []) : null;
    assert_equals(['cz:card', 'default:card'], $r13uOff, 'R13 UX3: without Stripe keys the card methods are switched off and reported');
    assert_equals(['default:bank_transfer', 'default:ext'], $r13uMem->query("SELECT store_id || ':' || code FROM payment_methods WHERE active = 1 ORDER BY store_id, code")->fetchAll(PDO::FETCH_COLUMN),
        'R13 UX3: offline methods and gateways whose credentials are not known here stay as they were');
    $r13uMem->exec("UPDATE payment_methods SET active = 1 WHERE provider = 'stripe'");
    $r13uKeys = ['stripe_secret_key' => 'sk_test_' . str_repeat('a', 24), 'stripe_webhook_secret' => 'whsec_' . str_repeat('b', 24)];
    assert_equals([], method_exists(PaymentReadiness::class, 'deactivateKeyless') ? PaymentReadiness::deactivateKeyless($r13uMem, $r13uKeys) : null,
        'R13 UX3: with Stripe keys the card methods stay on');
} catch (Throwable $e) { $r13uFail('UX3', $e); }

// --- UX4: the page after checkout summarises the order -------------------------------------------------------------
$r13uRequestBefore = [$_GET, $_POST, $_SERVER['REQUEST_METHOD'] ?? 'GET', $GLOBALS['t'] ?? null];
try {
    $GLOBALS['t'] = static fn(string $key): string => I18n::t($key);
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, store_id)
        VALUES (?, 'R13U Buyer', 'r13u-done@example.test', '+421900113004', ?, 2400, 390, 0, 0, 2790, 'eur', 'pending', 'pending', 'cod', '', ?)")
        ->execute(['R13U-' . bin2hex(random_bytes(4)), json_encode(['street' => 'Summary Street 4', 'city' => 'Trnava', 'zip' => '91701', 'country' => 'SK']), $r13uStoreId]);
    $r13uDone = Orders::byId((int)$pdo->lastInsertId());
    $pdo->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, 'R13U-SKU', 'R13U Summary Lamp', 2, 1200, 2400)")
        ->execute([(int)$r13uDone['id'], (int)$pdo->query('SELECT MIN(id) FROM products')->fetchColumn()]);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = [];
    $_GET = ['page' => 'order-done', 'order' => (string)$r13uDone['order_number'],
        'confirmation_token' => CheckoutSession::createConfirmation((int)$r13uDone['id'], CheckoutSession::browserSessionId())];
    $r13uPage = renderOrderDone((string)$r13uDone['order_number']);
    assert_true(str_contains($r13uPage, 'data-order-summary') && str_contains($r13uPage, 'R13U Summary Lamp') && str_contains($r13uPage, esc(I18n::price(2790, 'eur')))
        && str_contains($r13uPage, esc(I18n::price(390, 'eur'))) && str_contains($r13uPage, 'Summary Street 4'),
        'R13 UX4: the order-done page lists the items, shipping, total and delivery address');
    $_GET['confirmation_token'] = str_repeat('0', 64);
    assert_true(!str_contains(renderOrderDone((string)$r13uDone['order_number']), 'R13U Summary Lamp'), 'R13 UX4: without the confirmation token nothing of the order is shown');
} catch (Throwable $e) { $r13uFail('UX4', $e); } finally {
    [$_GET, $_POST, $_SERVER['REQUEST_METHOD'], $GLOBALS['t']] = $r13uRequestBefore;
}

// --- UX5: verified registration takes over the guest orders placed with the same e-mail ----------------------------
try {
    foreach ($GLOBALS as $r13uValue) if ($r13uValue instanceof PDOStatement) $r13uValue->closeCursor();
    (require TEST_CLI_DIR . '/../migrations/20261012210000_customer_email_verification.php')->up($pdo, true);
    $r13uEmail = 'r13u-guest-' . bin2hex(random_bytes(3)) . '@example.test';
    $r13uGuest = static function (string $email, string $store) use ($pdo): int {
        $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount, total, currency, status, payment_status, payment_method, payment_id, store_id)
            VALUES (?, 'R13U Guest', ?, '', '{}', 500, 0, 0, 0, 500, 'eur', 'pending', 'pending', 'cod', '', ?)")
            ->execute(['R13U-G-' . bin2hex(random_bytes(4)), $email, $store]);
        return (int)$pdo->lastInsertId();
    };
    $r13uMine = [$r13uGuest($r13uEmail, $r13uStoreId), $r13uGuest('  ' . strtoupper($r13uEmail) . ' ', $r13uStoreId)];
    $r13uOtherStore = $r13uGuest($r13uEmail, 'cz');
    $r13uStranger = $r13uGuest('r13u-someone-else@example.test', $r13uStoreId);
    [$r13uOk, $r13uCid] = Customers::register($r13uEmail, 'R13u-Strong-Pass', 'R13U Guest');
    $r13uOwner = static fn(int $id): ?int => ($v = $pdo->query('SELECT customer_id FROM orders WHERE id = ' . $id)->fetchColumn()) === null ? null : (int)$v;
    assert_true($r13uOk && $r13uOwner($r13uMine[0]) === null && $r13uOwner($r13uMine[1]) === null,
        'R13 UX5: an unverified sign-up with the e-mail does not take its guest orders');
    $r13uToken = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO customer_email_verifications (customer_id, email, token_hash, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([(int)$r13uCid, $r13uEmail, hash('sha256', $r13uToken), gmdate('Y-m-d H:i:s', time() + 3600)]);
    assert_equals((int)$r13uCid, Customers::verifyEmail($r13uToken), 'R13 UX5: the verification link verifies the account');
    assert_equals([(int)$r13uCid, (int)$r13uCid, null, null], [$r13uOwner($r13uMine[0]), $r13uOwner($r13uMine[1]), $r13uOwner($r13uOtherStore), $r13uOwner($r13uStranger)],
        'R13 UX5: after verification the guest orders of this store with the same e-mail (any case) are in the account; other stores and e-mails are not');
    assert_equals(2, count(array_filter(Customers::orders((int)$r13uCid), static fn(array $o): bool => in_array((int)$o['id'], $r13uMine, true))),
        'R13 UX5: My orders lists them');
} catch (Throwable $e) { $r13uFail('UX5', $e); }

// --- UX6: the home hero without a heading names the shop -------------------------------------------------------------
try {
    $r13uSection = new ReflectionMethod(Template::class, 'renderSection');
    $r13uSection->setAccessible(true);
    $r13uHero = (string)$r13uSection->invoke(null, ['type' => 'hero', 'id' => 'r13u-hero', 'settings' => ['variant' => 'default'], 'content' => [], 'data' => []], ['t' => I18n::all()]);
    $r13uName = (string)Template::branding()['name'];
    assert_true(str_contains($r13uHero, '<h1>' . esc($r13uName) . '</h1>') && !str_contains($r13uHero, 'Sauna'), "R13 UX6: a hero without its own heading shows the store name {$r13uName} ({$r13uHero})");
    foreach (glob(dirname(TEST_CLI_DIR) . '/lang/*.php') ?: [] as $r13uLang) {
        assert_true(!str_contains((string)file_get_contents($r13uLang), 'Sauna'), 'R13 UX6: no sample-shop slogan left in ' . basename($r13uLang));
    }
} catch (Throwable $e) { $r13uFail('UX6', $e); }

// --- UX7: BIC in Admin → Stores and seller ------------------------------------------------------------------------------
$r13uTmp = test_fixture_path('r13-ux');
mkdir($r13uTmp);
try {
    copy(dirname(TEST_CLI_DIR) . '/stores.example.json', $r13uTmp . '/stores.json');
    StoreSettings::setPathForTesting($r13uTmp . '/stores.json');
    $r13uLegal = StoreSettings::load()['stores']['default']['legal'];
    $r13uInput = ['store_name' => 'R13U Shop', 'iban' => 'SK31 1200 0000 1987 4263 7541'] + array_map('strval', $r13uLegal) + ['payment_terms_days' => '14', 'vat_registered' => '1'];
    assert_true(in_array('bic', StoreSettings::LEGAL_TEXT, true) && I18n::t('admin.stores.field.bic') !== 'admin.stores.field.bic', 'R13 UX7: the seller form has a labelled BIC field');
    $r13uSaved = StoreSettings::save('default', ['bic' => 'tatr skbx'] + $r13uInput);
    assert_true($r13uSaved['ok'] && (StoreSettings::load()['stores']['default']['legal']['bic'] ?? null) === 'TATRSKBX',
        'R13 UX7: the BIC is saved normalised (' . implode(' ', $r13uSaved['errors']) . ')');
    $r13uBefore = (string)file_get_contents($r13uTmp . '/stores.json');
    $r13uBad = StoreSettings::save('default', ['bic' => 'NOT-A-BIC'] + $r13uInput);
    assert_true(!$r13uBad['ok'] && in_array(I18n::t('admin.stores.err.bic'), $r13uBad['errors'], true) && $r13uBefore === (string)file_get_contents($r13uTmp . '/stores.json'),
        'R13 UX7: a malformed BIC is refused and nothing is saved');
    $r13uBt = BankTransferInstructions::forOrder(['payment_method' => 'bank_transfer', 'payment_status' => 'pending', 'status' => 'pending', 'id' => 1, 'total' => 1000,
        'currency' => 'eur', 'order_number' => 'R13U-BIC', 'created_at' => gmdate('Y-m-d H:i:s')], StoreSettings::load()['stores']['default']['legal']);
    assert_equals('TATRSKBX', $r13uBt['bic'] ?? null, 'R13 UX7: the saved BIC reaches the transfer instructions');
} catch (Throwable $e) { $r13uFail('UX7', $e); } finally {
    StoreSettings::setPathForTesting(null);
    @unlink($r13uTmp . '/stores.json');
    @rmdir($r13uTmp);
}
