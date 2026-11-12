<?php

// Review round 33 (medium 4 + billing lows, goal #432): on a shared computer a guest's half-typed checkout was mixed
// into the saved address of the member who signed in next (the guest's name and city, the member's street, while the
// picker showed the member's address). Also: a saved billing address lost the company's IČO and DIČ; the account
// offered no billing country outside the delivery zones; a JSON billing_address string was silently ignored; bytes
// that are not UTF-8 ended in a generic error (on SQLite the order kept no address); the billing fields lacked
// required / aria-invalid and the toggle aria-expanded; addresses were formatted differently on every page; billed
// elsewhere, the carrier label lost the company; a pick-up order's invoice named the home address as delivery address.
echo "\n--- Audit round 33: billing address on a shared computer, saved IDs, refusals, formatting ---\n";

Bootstrap::initStoreContext('/', '', 'default');
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
$r33bSession = $_SESSION;
$r33bMember = 0;
$r33bFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R33 billing {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
try {
    $pdo->prepare("INSERT INTO customers (store_id, email, password_hash, name, phone, status) VALUES ('default', ?, ?, ?, ?, 'active')")
        ->execute(['r33b-member@example.test', 'r33b-hash-member', 'R33B Member', '+421900000433']);
    $r33bMember = (int)$pdo->lastInsertId();
    Customers::addAddress($r33bMember, ['type' => 'shipping', 'is_default' => true, 'name' => 'R33B Member', 'street' => 'Členská 4', 'city' => 'Trnava', 'zip' => '91701', 'country' => 'SK']);
    $r33bLogin = static function () use ($r33bMember): void {
        $_SESSION['customer_id'] = $r33bMember;
        $_SESSION['customer_last_seen'] = time();
        $_SESSION['customer_auth_hash'] = hash('sha256', 'r33b-hash-member');
    };
    $r33bPicked = static fn(array $state): array => array_column(array_filter($state['addresses'], static fn(array $a): bool => $a['selected']), 'label');

    // 1. A guest types an address (the draft is kept for a reload), walks away; a member signs in at the same computer.
    foreach (['customer_id', 'customer_last_seen', 'customer_auth_hash', CheckoutService::DRAFT_KEY] as $r33bKey) unset($_SESSION[$r33bKey]);
    CheckoutService::formState(['name' => 'R33B Guest', 'email' => 'r33b-guest@example.test', 'street' => '', 'city' => 'Guestville', 'zip' => '01001', 'country' => 'SK',
        'billing_different' => '1', 'billing_name' => 'R33B Guest Billing', 'billing_street' => 'Hosťovská 7', 'billing_city' => 'Guestville', 'billing_zip' => '01001', 'billing_country' => 'SK'], true, true);
    $r33bLogin();
    $r33bState = CheckoutService::formState([], false);
    $r33bValues = $r33bState['values'];
    assert_equals(['R33B Member', 'r33b-member@example.test', 'Členská 4', 'Trnava', '91701', '', '', false],
        [$r33bValues['name'], $r33bValues['email'], $r33bValues['street'], $r33bValues['city'], $r33bValues['zip'], $r33bValues['billing_name'], $r33bValues['billing_street'], $r33bState['billing_different']],
        'R33 billing: a guest\'s draft is not mixed into the member who signs in next (was the guest\'s name and city with the member\'s street)');
    assert_true(count($r33bPicked($r33bState)) === 1, 'R33 billing: the picker marks the member\'s saved address the fields hold');

    // The member's own draft still survives a reload; the picker no longer claims a saved address it does not hold.
    CheckoutService::formState(['name' => 'R33B Member', 'email' => 'r33b-member@example.test', 'street' => 'Iná 9', 'city' => 'Nitra', 'zip' => '94901', 'country' => 'SK'], true, true);
    $r33bState = CheckoutService::formState([], false);
    assert_true($r33bState['values']['street'] === 'Iná 9' && $r33bState['values']['city'] === 'Nitra' && $r33bPicked($r33bState) === [],
        'R33 billing: the member\'s own draft is kept on reload and the picker does not mark a saved address over it');
    unset($_SESSION[CheckoutService::DRAFT_KEY]);

    // 2. A saved billing address keeps the company's IČO and DIČ and prefills them (checkout "save address" too).
    GuestCheckoutAccount::rememberAddress($r33bMember, ['name' => 'R33B Member', 'street' => 'Členská 4', 'city' => 'Trnava', 'zip' => '91701', 'country' => 'SK',
        'company' => 'R33B Firma s.r.o.', 'ico' => '36123456', 'dic' => '2020123456', 'vat_id' => 'SK2020123456',
        'billing_different' => '1', 'billing_name' => 'R33B Účtáreň', 'billing_street' => 'Fakturačná 3', 'billing_city' => 'Bratislava', 'billing_zip' => '81101', 'billing_country' => 'SK']);
    $r33bSaved = Customers::addresses($r33bMember, 'billing')[0] ?? [];
    assert_equals(['R33B Firma s.r.o.', '36123456', '2020123456', 'SK2020123456'],
        [(string)($r33bSaved['company'] ?? ''), (string)($r33bSaved['ico'] ?? ''), (string)($r33bSaved['dic'] ?? ''), (string)($r33bSaved['vat_id'] ?? '')],
        'R33 billing: the saved billing address keeps the IČO and DIČ (was company and IČ DPH only)');
    $r33bValues = CheckoutService::formState([], false)['values'];
    assert_equals(['Fakturačná 3', 'R33B Firma s.r.o.', '36123456', '2020123456'], [$r33bValues['billing_street'], $r33bValues['company'], $r33bValues['ico'], $r33bValues['dic']],
        'R33 billing: the next checkout prefills the whole company from the saved billing address');
    $r33bAccount = (new ReflectionMethod(AccountViewModel::class, 'addresses'))->invoke(null, $r33bMember, ['type' => 'billing'], []);
    assert_true(in_array('US', array_column($r33bAccount['address_country']['options'], 'code'), true)
        && in_array('ico', array_column($r33bAccount['address_fields'], 'name'), true) && Customers::addressErrors(['type' => 'billing', 'name' => 'N', 'street' => 'S', 'city' => 'C', 'zip' => '1', 'country' => 'US']) === [],
        'R33 billing: the account offers the billing countries checkout accepts (also outside the EU) and the IČO/DIČ fields');

    // 3. Refusals: a JSON billing_address string, bytes that are not UTF-8 (delivery and billing field), named fields.
    $r33bCode = OrderAddress::billingFromInput(['billing_address' => 'Fakturačná 3, Bratislava'])['error_code'];
    assert_equals('checkout.error.billing_address_required', $r33bCode, 'R33 billing: a billing_address that is a string is refused (was ignored, billed to the delivery address)');
    if (!function_exists('checkoutExecute')) require_once dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php';
    $r33bBase = ['name' => 'R33B Bytes', 'email' => 'r33b-bytes@example.test', 'street' => 'Hlavná 1', 'city' => 'Košice', 'zip' => '04001', 'country' => 'SK'];
    $r33bOrders = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $r33bStreet = checkoutExecute(['street' => "Hlavn\xE1 1"] + $r33bBase);
    $r33bStatus = http_response_code();
    $r33bBilling = checkoutExecute(['billing_different' => '1', 'billing_name' => 'R33B', 'billing_street' => 'Fakturačná 3', 'billing_city' => "Ko\xB9ice", 'billing_zip' => '04001', 'billing_country' => 'SK'] + $r33bBase);
    http_response_code(200);
    assert_equals([['checkout.error.field_invalid', 'street'], 422, ['checkout.error.field_invalid', 'billing_city'], $r33bOrders],
        [[$r33bStreet['error_code'] ?? null, $r33bStreet['field'] ?? null], $r33bStatus, [$r33bBilling['error_code'] ?? null, $r33bBilling['field'] ?? null], (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()],
        'R33 billing: a field that is not UTF-8 is refused at the field (422), delivery and billing alike, and no order is stored');
    I18n::setLocale('sk');
    assert_true(str_contains((string)(checkoutExecute(['billing_different' => '1', 'billing_name' => 'R33B', 'billing_street' => 'F 3', 'billing_city' => "Ko\xB9ice", 'billing_zip' => '1', 'billing_country' => 'SK'] + $r33bBase)['error'] ?? ''), 'Fakturačná adresa: Mesto'),
        'R33 billing: the message names the billing field');
    http_response_code(200);

    // 4. The form: required while billing elsewhere, aria-invalid on the field a refusal is about, aria-expanded toggle.
    $r33bView = CheckoutService::billingView(['billing_different' => true, 'billing_addresses' => [], 'billing_country' => 'SK', 'billing_countries' => ['SK' => 'Slovensko'],
        'values' => ['billing_name' => 'R33B', 'billing_street' => '', 'billing_city' => 'Košice', 'billing_zip' => '']], ['error_code' => 'checkout.error.billing_address_required']);
    assert_equals(['name' => false, 'street' => true, 'city' => false, 'zip' => true], $r33bView['billing_invalid'], 'R33 billing: a missing billing address marks the empty fields invalid');
    assert_equals(['name' => false, 'street' => false, 'city' => true, 'zip' => false],
        CheckoutService::billingView(['billing_different' => true, 'billing_addresses' => [], 'billing_country' => 'SK', 'billing_countries' => [], 'values' => []], ['field' => 'billing_city'])['billing_invalid'],
        'R33 billing: a refusal naming a billing field marks that field');
    $r33bTpl = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/themes/default/blocks/storefront/checkout.html');
    $r33bJs = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/themes/default/assets/storefront.js');
    assert_true(str_contains($r33bTpl, 'aria-controls="checkout-billing-fields" aria-expanded="{{#if billing_different}}true{{else}}false{{/if}}"')
        && substr_count($r33bTpl, '{{#if billing_different}} required{{/if}}{{#if billing_invalid.') === 4 && str_contains($r33bJs, "toggle.setAttribute('aria-expanded'")
        && str_contains($r33bJs, 'input.required = toggle.checked'), 'R33 billing: the billing fields are required while shown and the toggle reports aria-expanded (no-JS and JS)');

    // 5. One address format everywhere; billed elsewhere the parcel still names the company; a pick-up point is the
    // delivery address of documents and e-mails, not the home address the order also carries.
    $r33bOrder = ['customer_name' => 'R33B Buyer', 'customer_phone' => '+421900000433', 'shipping_addr' => json_encode(['street' => 'Doručovacia 1', 'city' => 'Žilina', 'zip' => '01001', 'country' => 'SK']),
        'billing_addr' => json_encode(['name' => 'R33B Účtáreň', 'street' => 'Fakturačná 3', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK', 'company' => 'R33B Firma s.r.o.', 'ico' => '36123456']),
        'currency' => 'eur', 'subtotal' => 2000, 'total' => 2000, 'payment_method' => 'cod', 'shipping_rate_snapshot' => json_encode(['carrier' => 'gls', 'method_code' => 'gls'])];
    $r33bRecipient = OrderAddress::recipientAddress($r33bOrder);
    $r33bNote = DeliveryNote::recipientLines(['order' => $r33bOrder, 'address' => $r33bRecipient]);
    assert_equals(['R33B Buyer', 'R33B Firma s.r.o.', 'Doručovacia 1', '01001 Žilina', 'Slovensko', '+421900000433'], $r33bNote,
        'R33 billing: billed elsewhere, the label and delivery note name the company the parcel goes to (was dropped)');
    assert_equals(array_slice($r33bNote, 0, 5), AccountViewModel::orderSummary(['shipping_addr' => json_encode($r33bRecipient)] + $r33bOrder, [])['address'],
        'R33 billing: the account order detail formats the address like the delivery note, e-mails and admin (country by name)');
    $r33bAdmin = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Orders.php');
    assert_true(str_contains($r33bAdmin, 'OrderAddress::lines($addr)') && !str_contains($r33bAdmin, "(\$addr['street'] ?? '') . ', '"), 'R33 billing: the admin order detail uses the same address lines');
    $r33bPoint = ['shipping_rate_snapshot' => json_encode(['carrier' => 'packeta', 'pickup_point' => ['id' => '4321', 'name' => 'Z-BOX Žilina Mirage', 'address' => 'Námestie A. Hlinku 7, 01001 Žilina']])] + $r33bOrder;
    $r33bShop = ['shipping_rate_snapshot' => json_encode(['carrier' => 'pickup', 'method_code' => 'pickup'])] + $r33bOrder;
    $r33bSnapshot = new ReflectionMethod(InvoiceService::class, 'customerSnapshot');
    assert_equals([['Z-BOX Žilina Mirage', 'Námestie A. Hlinku 7, 01001 Žilina'], null, 'Doručovacia 1'],
        [[($r33bSnapshot->invoke(null, $r33bPoint)['delivery']['name'] ?? ''), ($r33bSnapshot->invoke(null, $r33bPoint)['delivery']['street'] ?? '')],
            $r33bSnapshot->invoke(null, $r33bShop)['delivery'] ?? null, $r33bSnapshot->invoke(null, $r33bOrder)['delivery']['street'] ?? ''],
        'R33 billing: the invoice of a pick-up order names the pick-up point as delivery address, none for collection at the shop (was the home address)');
    assert_true(in_array('Z-BOX Žilina Mirage', OrderAddress::mailVars($r33bPoint, 'sk')['delivery_address'], true) && OrderAddress::mailVars($r33bShop, 'sk')['delivery_address'] === [],
        'R33 billing: the e-mails name the pick-up point as delivery address too');
} catch (Throwable $e) {
    $r33bFail('scenario', $e);
} finally {
    $_SESSION = $r33bSession;
    http_response_code(200);
    if ($r33bMember > 0) {
        $pdo->prepare('DELETE FROM customer_addresses WHERE customer_id = ?')->execute([$r33bMember]);
        $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$r33bMember]);
    }
}
