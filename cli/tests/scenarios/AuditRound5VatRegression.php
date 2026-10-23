<?php

// Audit round 5 — VAT and documents: OSS report nets credit notes; a non-EU country zero-rates only when backed by
// a shipment; category rates (SK/CZ books, medicines) and the EE / RO 2025 changes; the payment (COD) fee carries the
// goods' VAT; automatic free shipping is earned by the paid subtotal in the rule's currency; VIES answers with
// namespaced tags parse and every check is kept as evidence; the invoice PDF lines add up to its totals and name the
// legal ground of an exemption; a credit note names its invoice; buy-X-get-Y discounts sit on the free product's
// lines; in-store pickup is supplied in the seller's country; the date of supply is the payment / shipment date;
// the invoice e-mail has its amount, dates, link and seller in every language; sales reports count money received.
echo "\n--- Audit round 5: VAT and documents ---\n";
require_once TEST_CLI_DIR . '/../src/Http/ApiHandlers.php';
require_once dirname(TEST_CLI_DIR) . '/adapters/contrib/ViesVatIdValidator.php';
Bootstrap::initStoreContext('/', '', 'default');

$r5Order = static function (array $fields) use ($pdo): int {
    $fields += ['order_number' => 'R5V-' . bin2hex(random_bytes(5)), 'customer_name' => 'R5 Buyer', 'customer_email' => 'r5v@example.test',
        'customer_phone' => '', 'shipping_addr' => '{}', 'subtotal' => 0, 'shipping_cost' => 0, 'tax_amount' => 0, 'discount_amount' => 0,
        'total' => 0, 'currency' => 'eur', 'status' => 'paid', 'payment_status' => 'paid', 'payment_method' => 'bank', 'store_id' => 'default',
        'created_at' => gmdate('Y-m-d H:i:s')];
    $pdo->prepare('INSERT INTO orders (' . implode(', ', array_keys($fields)) . ') VALUES (' . implode(', ', array_fill(0, count($fields), '?')) . ')')
        ->execute(array_values($fields));
    return (int)$pdo->lastInsertId();
};

// --- Rates: product categories and the 2025 changes -------------------------------------------------------------
assert_equals(5.0, VatService::rate('SK', 'books', '2026-03-01'), 'R5 VAT: SK books 5 % from 2025');
assert_equals(5.0, VatService::rate('SK', 'medicines', '2026-03-01'), 'R5 VAT: SK medicines 5 % from 2025');
assert_equals(10.0, VatService::rate('SK', 'books', '2024-06-01'), 'R5 VAT: SK books 10 % before 2025');
assert_equals(23.0, VatService::rate('SK', 'standard', '2026-03-01'), 'R5 VAT: SK standard unchanged');
assert_equals(0.0, VatService::rate('CZ', 'books', '2026-03-01'), 'R5 VAT: CZ books 0 %');
assert_equals(24.0, VatService::rate('EE', 'standard', '2025-08-01'), 'R5 VAT: EE 24 % from July 2025');
assert_equals(22.0, VatService::rate('EE', 'standard', '2025-06-01'), 'R5 VAT: EE 22 % until June 2025');
assert_equals(21.0, VatService::rate('RO', 'standard', '2025-09-01'), 'R5 VAT: RO 21 % from August 2025');
assert_equals(11.0, VatService::rate('RO', 'reduced', '2025-09-01'), 'R5 VAT: RO reduced 11 % from August 2025');
assert_equals(19.0, VatService::rate('RO', 'standard', '2025-07-01'), 'R5 VAT: RO 19 % until July 2025');
// Book and medicine rates outside SK/CZ (checked 2026-10-23 against the EC tables and national authorities).
foreach (['FR' => 5.5, 'IT' => 4.0, 'ES' => 4.0, 'PL' => 5.0, 'IE' => 0.0, 'HU' => 5.0, 'BE' => 6.0, 'SE' => 6.0, 'LT' => 5.0] as $r5Country => $r5Books) {
    assert_equals($r5Books, VatService::rate($r5Country, 'books', '2026-10-23'), "R5 VAT: {$r5Country} books {$r5Books} %");
}
assert_equals([4.0, 0.0, 19.0, 10.0], [VatService::rate('ES', 'medicines', '2026-10-23'), VatService::rate('IE', 'medicines', '2026-10-23'),
    VatService::rate('DE', 'medicines', '2026-10-23'), VatService::rate('FR', 'medicines', '2026-10-23')], 'R5 VAT: medicines ES 4 %, IE 0 %, DE standard 19 %, FR non-reimbursed 10 %');
assert_equals([13.5, 14.0, 10.0], [VatService::rate('FI', 'reduced', '2026-01-01'), VatService::rate('FI', 'books', '2025-06-01'), VatService::rate('FI', 'books', '2024-06-01')],
    'R5 VAT: FI reduced 13.5 % from 2026, books 14 % in 2025 and 10 % before');
assert_equals([12.0, 9.0, 5.0], [VatService::rate('LT', 'reduced', '2026-01-01'), VatService::rate('LT', 'books', '2025-12-31'), VatService::rate('RO', 'books', '2025-07-01')],
    'R5 VAT: LT 9 % rate replaced by 12 % in 2026 (books 9 % before); RO books 5 % before August 2025');

// --- A declared non-EU country alone does not zero-rate --------------------------------------------------------
$r5Ctx = ['seller_country' => 'SK', 'seller_vat_registered' => true, 'seller_oss_enabled' => true, 'customer_country' => 'US',
    'customer_vat_id_valid' => false, 'is_b2b' => false];
$r5Declared = VatService::resolveScenario($r5Ctx + ['customer_country_verified' => false]);
assert_equals(['domestic', true, 'SK'], [$r5Declared['scenario'], $r5Declared['applies_vat'], $r5Declared['rate_country']],
    'R5 VAT: digital order with only a declared US country keeps the seller\'s VAT');
$r5Shipped = VatService::resolveScenario($r5Ctx + ['customer_country_verified' => true]);
assert_equals(['export', false], [$r5Shipped['scenario'], $r5Shipped['applies_vat']], 'R5 VAT: goods shipped to the US are an export');

// --- In-store pickup is supplied in the seller's country ---------------------------------------------------------
assert_equals('SK', vatSupplyCountry(['carrier' => 'pickup', 'method_code' => 'pickup'], 'DE', 'SK'), 'R5 VAT: pickup is taxed in the seller\'s country');
assert_equals('DE', vatSupplyCountry(['carrier' => 'packeta', 'method_code' => 'packeta'], 'DE', 'SK'), 'R5 VAT: a dispatched parcel follows the customer\'s country');
assert_equals('DE', vatSupplyCountry(null, 'DE', 'SK'), 'R5 VAT: no rate keeps the customer\'s country');

// --- Payment fee carries the goods' VAT; buy-X-get-Y discount stays on the free lines ---------------------------
$r5Domestic = ['seller_country' => 'SK', 'seller_vat_registered' => true, 'seller_oss_enabled' => false, 'customer_country' => 'SK',
    'customer_vat_id_valid' => false, 'is_b2b' => false];
$r5Items = [
    ['product_id' => 9101, 'sku' => 'R5-LAMP', 'price' => 5000, 'qty' => 1, 'total' => 5000, 'vat_class' => 'standard'],
    ['product_id' => 9102, 'sku' => 'R5-BOOK', 'price' => 1000, 'qty' => 2, 'total' => 2000, 'vat_class' => 'books'],
];
$r5Lines = Promotions::lineDiscounts([['action' => 'buy_x_get_y', 'discount' => 1000, 'product_id' => 9102], ['action' => 'discount_fixed', 'discount' => 300]], $r5Items);
assert_equals([1 => 1000], $r5Lines, 'R5 VAT: buy-X-get-Y discount belongs to the free product\'s line');
$r5Snapshot = VatService::buildSnapshot($r5Items, $r5Domestic, ['discount' => 1000, 'line_discounts' => $r5Lines, 'shipping' => 0,
    'payment_fee' => 123, 'currency' => 'eur', 'effective_date' => '2026-03-01']);
VatService::validateSnapshot($r5Snapshot);
$r5Components = $r5Snapshot['components'];
assert_equals([0, 1000], [$r5Components[0]['discount'], $r5Components[1]['discount']], 'R5 VAT: the free book is discounted, the lamp keeps its price');
assert_equals([2300, 500], [$r5Components[0]['rate_basis_points'], $r5Components[1]['rate_basis_points']], 'R5 VAT: lamp 23 %, book 5 %');
$r5Fee = array_values(array_filter($r5Components, static fn(array $c): bool => $c['type'] === 'payment_fee'))[0] ?? [];
assert_equals([2300, 100, 23], [$r5Fee['rate_basis_points'] ?? null, $r5Fee['net'] ?? null, $r5Fee['vat'] ?? null], 'R5 VAT: COD fee 1.23 carries 23 % VAT');
assert_equals(6123, (int)$r5Snapshot['totals']['gross'], 'R5 VAT: snapshot total = goods − free unit + fee');
// The discount used to be spread over both lines: the lamp lost VAT base it never discounted.
assert_equals(4065 + 952 + 100, (int)$r5Snapshot['totals']['net'], 'R5 VAT: net = lamp 40.65 + book 9.52 + fee 1.00');

// --- Automatic free shipping: paid subtotal, rule currency -------------------------------------------------------
$r5PricingConfig = (new ReflectionProperty(Pricing::class, 'config'))->getValue();
Pricing::setConfig(['cart_rules' => [['name' => 'Free from 50', 'active' => true, 'type' => 'automatic',
    'conditions' => ['min_subtotal' => 5000], 'action' => 'free_shipping']]]);
$r5StoreCurrency = strtolower((string)(StoreContext::current()->config()['currency'] ?? 'eur'));
$r5Other = $r5StoreCurrency === 'czk' ? 'eur' : 'czk';
assert_true(Pricing::freeShippingEarned(5000, [], null, $r5StoreCurrency), 'R5 VAT: 50.00 paid earns free shipping');
assert_true(!Pricing::freeShippingEarned(6000 - 1500, [], null, $r5StoreCurrency), 'R5 VAT: 60.00 less a 15.00 coupon does not');
assert_true(!Pricing::freeShippingEarned(500000, [], null, $r5Other), 'R5 VAT: 5000 in another currency is not compared with 50.00');
Pricing::setConfig(['cart_rules' => [['name' => 'Free from 1000 CZK', 'active' => true, 'type' => 'automatic',
    'conditions' => ['min_subtotal' => 100000, 'currency' => $r5Other], 'action' => 'free_shipping']]]);
assert_true(Pricing::freeShippingEarned(100000, [], null, $r5Other), 'R5 VAT: a rule with its own currency applies to that currency');
assert_true(!Pricing::freeShippingEarned(100000, [], null, $r5StoreCurrency), 'R5 VAT: ... and not to another one');
Pricing::setConfig(is_array($r5PricingConfig) ? $r5PricingConfig : []);

// --- VIES: namespaced answer, faults, evidence -------------------------------------------------------------------
$r5Valid = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns2:checkVatApproxResponse xmlns:ns2="urn:ec.europa.eu:taxud:vies:services:checkVat:types">'
    . '<ns2:countryCode>DE</ns2:countryCode><ns2:vatNumber>123456789</ns2:vatNumber><ns2:valid>true</ns2:valid><ns2:traderName>R5 GmbH</ns2:traderName>'
    . '<ns2:traderAddress>Berlin</ns2:traderAddress><ns2:requestIdentifier>WAPIAAAAX1</ns2:requestIdentifier><ns2:name>R5 GmbH</ns2:name><ns2:address>Berlin</ns2:address></ns2:checkVatApproxResponse></soap:Body></soap:Envelope>';
$r5Parsed = ViesVatIdValidator::parse($r5Valid, 'DE');
assert_equals(['valid', 'R5 GmbH', 'WAPIAAAAX1'], [$r5Parsed['status'], $r5Parsed['trader_name'], $r5Parsed['request_identifier']], 'R5 VAT: VIES <ns2:valid>true is valid');
assert_equals('invalid', ViesVatIdValidator::parse(str_replace('<ns2:valid>true', '<ns2:valid>false', $r5Valid), 'DE')['status'], 'R5 VAT: VIES <ns2:valid>false is invalid');
$r5Fault = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultcode>soap:Server</faultcode><faultstring>MS_UNAVAILABLE</faultstring></soap:Fault></soap:Body></soap:Envelope>';
assert_equals('unverified', ViesVatIdValidator::parse($r5Fault, 'DE')['status'], 'R5 VAT: a VIES fault is unverified, never invalid');
$r5Validator = new class implements VatIdValidator {
    public function check(string $vatId): array {
        return ViesVatIdValidator::parse('<ns2:valid>true</ns2:valid><ns2:name>R5 Evidence</ns2:name><ns2:requestIdentifier>WAPIR5EV</ns2:requestIdentifier>', 'DE');
    }
};
$pdo->exec("DELETE FROM vat_id_cache WHERE vat_id = 'DE999000111'");
VatService::validateVatId('DE999000111', $r5Validator);
$pdo->exec("DELETE FROM vat_id_cache WHERE vat_id = 'DE999000111'");
VatService::validateVatId('DE999000111', $r5Validator);
$r5Evidence = $pdo->query("SELECT status, trader_name, request_identifier FROM vat_id_checks WHERE vat_id = 'DE999000111' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
assert_equals(2, count($r5Evidence), 'R5 VAT: every live VIES check is kept, the cache overwrite loses none');
assert_equals(['valid', 'R5 Evidence', 'WAPIR5EV'], array_values($r5Evidence[0] ?? []), 'R5 VAT: the evidence keeps status, name and VIES request id');

// --- OSS: a credit note reduces the period ---------------------------------------------------------------------
if (!function_exists('collect')) {
    define('BB_TEST_LOAD_OSS_ONLY', true);
    require_once TEST_CLI_DIR . '/oss-report.php';
}
$r5OssOrder = $r5Order(['total' => 6000, 'subtotal' => 6000]);
$r5Invoice = $pdo->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, delivery_date, due_date, pdf_path, total_net, total_vat, total_gross, currency,
        vat_breakdown, customer_snapshot, seller_snapshot, payment_method, locale, status, download_token, vat_scenario, type, refers_to_invoice_id)
    VALUES (?, 'default', ?, '2031-02-10', '2031-02-10', '2031-02-24', 'x.pdf', ?, ?, ?, 'eur', ?, '{\"country\":\"DE\"}', '{}', 'bank', 'de', 'issued', ?, 'oss', ?, ?)");
$r5Invoice->execute([$r5OssOrder, 'R5OSS-' . bin2hex(random_bytes(3)), 5042, 958, 6000, '{"19":{"net":5042,"vat":958}}', bin2hex(random_bytes(8)), 'invoice', null]);
$r5SourceId = (int)$pdo->lastInsertId();
$r5Invoice->execute([$r5OssOrder, 'R5OSSC-' . bin2hex(random_bytes(3)), -1681, -319, -2000, '{"19":{"net":1681,"vat":319}}', bin2hex(random_bytes(8)), 'credit_note', $r5SourceId]);
$r5Oss = array_values(array_filter(collect('2031-02-01', '2031-02-28', null), static fn(array $row): bool => $row['consumption_country'] === 'DE'));
assert_equals([[3361, 639, 4000, 2]], array_map(static fn(array $row): array => [$row['net'], $row['vat'], $row['gross'], $row['invoice_count']], $r5Oss),
    'R5 VAT: OSS DE 19 % = 60 € invoice − 20 € credit note = 40 €');

// --- Invoice PDF lines add up to the totals; legal wording -------------------------------------------------------
$r5Invoice = ['total_net' => (int)$r5Snapshot['totals']['net'], 'total_gross' => (int)$r5Snapshot['totals']['gross']];
$r5Rows = InvoiceService::invoiceRows($r5Invoice, [], [['name' => 'Lamp'], ['name' => 'Book']], $r5Snapshot);
assert_equals([(int)$r5Snapshot['totals']['net'], (int)$r5Snapshot['totals']['gross']], [array_sum(array_column($r5Rows, 'net')), array_sum(array_column($r5Rows, 'gross'))],
    'R5 VAT: PDF lines add up to the invoice net and gross');
assert_equals(['Lamp', 'Book', 'discount', 'payment_fee'], array_map(static fn(array $row): string => $row['name'] ?? $row['label'], $r5Rows),
    'R5 VAT: PDF shows the goods, the discount and the payment fee');
assert_equals([4065, 5000, 1], [$r5Rows[0]['net'], $r5Rows[0]['gross'], $r5Rows[0]['qty']], 'R5 VAT: lamp line carries its price without VAT (40.65) and with VAT');
assert_equals([-953, -1000], [$r5Rows[2]['net'], $r5Rows[2]['gross']], 'R5 VAT: the free book unit is a 5 % discount line');
$r5Legacy = InvoiceService::invoiceRows(['total_net' => 9000, 'total_gross' => 11010], ['discount_amount' => 1000, 'shipping_cost' => 390, 'payment_fee' => 100],
    [['name' => 'Legacy', 'quantity' => 1, 'total' => 11500, 'vat_rate' => 23]], null);
assert_equals([9000, 11010], [array_sum(array_column($r5Legacy, 'net')), array_sum(array_column($r5Legacy, 'gross'))],
    'R5 VAT: a legacy invoice without snapshot still adds up (rounding line)');
$r5Labels = (new ReflectionMethod(InvoiceService::class, 'loadInvoiceLabels'));
$r5En = array_keys($r5Labels->invoke(null, 'en'));
foreach (['sk', 'cs', 'pl', 'hu', 'de', 'es', 'en'] as $r5Locale) {
    $r5Missing = array_diff($r5En, array_keys($r5Labels->invoke(null, $r5Locale)));
    assert_equals([], array_values($r5Missing), "R5 VAT: invoice labels complete in {$r5Locale}");
}
assert_true(str_contains((string)InvoiceService::vatNotice(['reverse_charge_note_key' => 'vat.reverse_charge_note'], 'sk', false, 'SK'), '§ 43'), 'R5 VAT: intra-EU goods cite § 43 (exemption)');
assert_true(str_contains((string)InvoiceService::vatNotice(['reverse_charge_note_key' => 'vat.reverse_charge_note'], 'sk', true, 'SK'), 'čl. 196'), 'R5 VAT: B2B e-services cite the reverse charge (Art. 196)');
assert_true(str_contains((string)InvoiceService::vatNotice(['reverse_charge_note_key' => 'vat.export_note'], 'sk', false, 'SK'), '§ 47'), 'R5 VAT: export cites § 47');
assert_equals(null, InvoiceService::vatNotice(['reverse_charge_note_key' => ''], 'sk', false, 'SK'), 'R5 VAT: domestic invoice has no notice');

// --- Date of supply and the invoice e-mail -----------------------------------------------------------------------
$r5PaidAt = gmdate('Y-m-d H:i:s', strtotime('-3 days 12:00'));
$r5SupplyOrder = $r5Order(['total' => 1230, 'subtotal' => 1230, 'created_at' => gmdate('Y-m-d H:i:s', strtotime('-5 days 12:00'))]);
$pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, status, amount, currency, idempotency_key, created_at) VALUES (?, 'default', 'manual', 'paid', 1230, 'eur', ?, ?)")
    ->execute([$r5SupplyOrder, 'r5v-paid-' . $r5SupplyOrder, $r5PaidAt]);
$r5Issued = InvoiceService::issue($r5SupplyOrder);
assert_equals(date('Y-m-d', strtotime($r5PaidAt . ' UTC')), (string)$r5Issued['delivery_date'], 'R5 VAT: date of supply is the payment date, not the late issue date');
$r5Vars = InvoiceService::mailVars($r5Issued, Orders::byId($r5SupplyOrder));
$r5LinkToken = substr((string)strrchr($r5Vars['invoice_download_url'], '#'), 1);
assert_true($r5Vars['total_gross_formatted'] !== '' && $r5Vars['issued_at'] !== '' && InvoiceService::tokenHash($r5LinkToken) === (string)InvoiceService::byOrderId($r5SupplyOrder)['download_token'],
    'R5 VAT: invoice e-mail has the amount, the issue date and the online link');
foreach (['sk', 'cs', 'pl', 'hu', 'de', 'es', 'en'] as $r5Locale) {
    $r5Template = (string)file_get_contents(dirname(TEST_CLI_DIR) . "/templates/emails/{$r5Locale}/invoice_issued.html")
        . (string)file_get_contents(dirname(TEST_CLI_DIR) . "/templates/emails/{$r5Locale}/invoice_issued.subject.txt");
    preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $r5Template, $r5Placeholders);
    assert_equals([], array_values(array_diff(array_unique($r5Placeholders[1]), array_keys($r5Vars))), "R5 VAT: invoice e-mail ({$r5Locale}) gets every variable it prints");
}

// --- Sales reports count money received --------------------------------------------------------------------------
$r5Store = 'r5vat';
$r5Now = gmdate('Y-m-d H:i:s');
$r5Order(['store_id' => $r5Store, 'total' => 1000, 'status' => 'delivered', 'payment_status' => 'paid', 'created_at' => $r5Now]);
$r5Order(['store_id' => $r5Store, 'total' => 2000, 'status' => 'pending', 'payment_status' => 'pending', 'created_at' => $r5Now]);
$r5Order(['store_id' => $r5Store, 'total' => 2500, 'status' => 'processing', 'payment_status' => 'failed', 'created_at' => $r5Now]);
$r5Partial = $r5Order(['store_id' => $r5Store, 'total' => 3000, 'status' => 'delivered', 'payment_status' => 'partially_refunded', 'created_at' => $r5Now]);
$r5Order(['store_id' => $r5Store, 'total' => 4000, 'status' => 'delivered', 'payment_status' => 'refunded', 'created_at' => $r5Now]);
$pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, status, amount, currency, idempotency_key) VALUES (?, ?, 'manual', 'partially_refunded', 1000, 'eur', ?)")
    ->execute([$r5Partial, $r5Store, 'r5v-refund-' . $r5Partial]);
$r5Totals = SalesReport::totals($r5Store, SalesReport::sinceUtc(1));
assert_equals(['eur' => ['orders' => 2, 'revenue' => 3000, 'aov' => 1500]], $r5Totals,
    'R5 VAT: revenue = paid 10 € + (30 € − 10 € refunded); unpaid, failed and refunded orders are not revenue');
$r5Trend = SalesReport::trend($r5Store, 1);
assert_equals(3000, (int)array_sum(array_column($r5Trend['eur'] ?? [], 'revenue')), 'R5 VAT: daily trend counts the same money');
