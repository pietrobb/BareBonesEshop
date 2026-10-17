<?php

// TEST: Staging deploy preflight
// ============================================================
echo "\n--- Test: Staging Deploy Preflight ---\n";

require_once TEST_CLI_DIR . '/staging-preflight.php';
$preflight = runStagingPreflight(['cli/staging-preflight.php']);
assert_true(is_array($preflight['checks'] ?? null), 'Staging preflight: returns checks');
assert_true(count($preflight['checks']) > 0, 'Staging preflight: emits deploy checks');
assert_true(in_array($preflight['status'] ?? '', ['GO', 'NO-GO'], true), 'Staging preflight: status is GO or NO-GO');
assert_true(in_array('php cli/migrate.php', $preflight['recommended_commands'] ?? [], true), 'Staging preflight: recommends migrations');
assert_true(in_array('php cli/cache-warm.php', $preflight['recommended_commands'] ?? [], true), 'Staging preflight: recommends cache warm');
$preflightNames = array_column($preflight['checks'], 'name');
$preflightDbChecks = ($cfg['db_driver'] ?? 'sqlite') === 'mysql' ? ['db_host', 'db_name', 'db_user'] : ['sqlite_path'];
foreach (array_merge($preflightDbChecks, ['jobs_dir', 'private_cache_dir', 'invoice_storage_dir', 'backup_storage_dir', 'backup_log_file', 'metrics_log_file', 'gdpr_salt_file']) as $privateCheck) {
    assert_true(in_array($privateCheck, $preflightNames, true), "Staging preflight: checks {$privateCheck}");
}

// ============================================================
// TEST 54: VAT — InvoiceService scenario + breakdown grouping
// ============================================================
echo "\n--- Test 54: VAT (InvoiceService) ---\n";

// Mark order paid then issue invoice
$pdo->exec("UPDATE orders SET status='paid', tax_amount=" . (int)$order['vat_total'] . " WHERE id={$orderId}");

$inv = InvoiceService::issue($orderId);
// 54.1 — vat_scenario stored on invoice
$row = $pdo->query("SELECT vat_scenario, reverse_charge_note_key, total_vat FROM invoices WHERE id={$inv['id']}")->fetch();
assert_equals('domestic', $row['vat_scenario'], 'Invoice: vat_scenario stored');
// 54.2 — total_vat matches order
assert_equals((int)$order['vat_total'], (int)$row['total_vat'], 'Invoice: total_vat = order.vat_total');
// 54.3 — vat_breakdown groups by per-line rate (multi-rate present: 23 + 19)
$breakdown = json_decode($pdo->query("SELECT vat_breakdown FROM invoices WHERE id={$inv['id']}")->fetchColumn() ?: '[]', true) ?: [];
assert_true(count($breakdown) >= 1, 'Invoice: vat_breakdown has at least 1 rate group');

// ============================================================
// TEST 54b: P3 — invoice VAT correctness (coupon base, shipping VAT,
// mixed-rate recap, net+VAT=gross invariant). Synthetic order with two
// VAT rates (23% + 10%), a 10% coupon, and shipping at the standard rate.
// ============================================================
echo "\n--- Test 54b: P3 invoice VAT correctness ---\n";

$db->exec("PRAGMA foreign_keys = OFF");
$db->exec("DELETE FROM order_items WHERE order_id IN (5004, 5005)");
$db->exec("DELETE FROM orders WHERE id IN (5004, 5005)");
// subtotal 23300 (12300@23% + 11000@10%), coupon 2330, shipping 600
// total = (23300 - 2330) + 600 = 21570
$db->exec("INSERT INTO orders (id, order_number, status, customer_name, customer_email, shipping_addr, payment_method, subtotal, discount_amount, shipping_cost, tax_amount, total, currency, locale, vat_scenario, created_at) VALUES (5004, 'INV-5004', 'paid', 'Mix Rate', 'mix@test.sk', '" . addslashes(json_encode(['street'=>'A 1','city'=>'BA','zip'=>'81101','country'=>'SK'])) . "', 'bank_transfer', 23300, 2330, 600, 4200, 21570, 'eur', 'sk', 'domestic', datetime('now'))");
$db->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, vat_class, vat_rate, vat_amount, net_amount) VALUES (5004, 501, 'SKU-A', 'Standard 23%', 1, 12300, 12300, 'standard', 23, 2300, 10000)");
$db->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, vat_class, vat_rate, vat_amount, net_amount) VALUES (5004, 502, 'SKU-B', 'Reduced 10%', 1, 11000, 11000, 'reduced', 10, 1000, 10000)");
$db->exec("PRAGMA foreign_keys = ON");

$inv4 = InvoiceService::issue(5004);
$bd4  = json_decode((string)$inv4['vat_breakdown'], true) ?: [];

// (c) mixed-rate: two rate groups present
assert_true(count($bd4) >= 2, 'P3: invoice has >1 VAT rate group (23% + 10%)');
assert_true(isset($bd4['23']) && isset($bd4['10']), 'P3: rate groups keyed 23 and 10');

// (a) coupon reduces the VAT BASE per rate: 10% line net drops 10000 -> 9000
assert_equals(9000, (int)$bd4['10']['net'], 'P3: coupon proportionally lowers 10% net base');
assert_equals(900,  (int)$bd4['10']['vat'], 'P3: 10% VAT recomputed on discounted base');

// (b) shipping carries standard-rate VAT, folded into the 23% group
//     products 23%: net 9000 / vat 2070; + shipping 600 -> net 488 / vat 112
assert_equals(9488, (int)$bd4['23']['net'], 'P3: 23% net = discounted product + shipping net');
assert_equals(2182, (int)$bd4['23']['vat'], 'P3: 23% VAT includes shipping VAT');

// (d) net + VAT = gross == order.total (hard invariant)
assert_equals(21570, (int)$inv4['total_gross'], 'P3: total_gross == order.total');
assert_equals(3082,  (int)$inv4['total_vat'],   'P3: total_vat = sum of group VAT');
assert_equals(18488, (int)$inv4['total_net'],   'P3: total_net = gross - VAT');
assert_equals((int)$inv4['total_gross'], (int)$inv4['total_net'] + (int)$inv4['total_vat'],
    'P3: net + VAT = gross invariant holds');
// groups reconcile exactly to gross
$sumGroup = 0;
foreach ($bd4 as $g) { $sumGroup += (int)$g['net'] + (int)$g['vat']; }
assert_equals(21570, $sumGroup, 'P3: sum(net+VAT) across rate groups == order.total');

// reverse-charge: shipping and products all 0% VAT, net = gross
$db->exec("PRAGMA foreign_keys = OFF");
$db->exec("INSERT INTO orders (id, order_number, status, customer_name, customer_email, shipping_addr, payment_method, subtotal, discount_amount, shipping_cost, tax_amount, total, currency, locale, vat_scenario, created_at) VALUES (5005, 'INV-5005', 'paid', 'RC', 'rc@test.de', '" . addslashes(json_encode(['street'=>'B 2','city'=>'Berlin','zip'=>'10115','country'=>'DE','ic_dph'=>'DE811907980'])) . "', 'bank_transfer', 10000, 0, 500, 0, 10500, 'eur', 'de', 'reverse_charge', datetime('now'))");
$db->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, vat_class, vat_rate, vat_amount, net_amount) VALUES (5005, 501, 'SKU-RC', 'RC item', 1, 10000, 10000, 'standard', 0, 0, 10000)");
$db->exec("PRAGMA foreign_keys = ON");
$inv5 = InvoiceService::issue(5005);
assert_equals(0, (int)$inv5['total_vat'], 'P3: reverse-charge invoice has zero VAT');
assert_equals(10500, (int)$inv5['total_net'], 'P3: reverse-charge net == gross (incl. shipping)');
assert_equals((int)$inv5['total_gross'], (int)$inv5['total_net'] + (int)$inv5['total_vat'],
    'P3: reverse-charge net + VAT = gross invariant');

// ============================================================
// TEST 55: P0 regression — HTTP checkout path (apiCheckout)
// All five P0 bugs (#1 per-line VAT=0, #2 double VAT, #3 dead VIES,
// #4 mock webhook bypass, #5 undefined $cfg) lived in api.php and
// slipped past tests that called VatService/Cart/Orders directly.
// These tests exercise the exact apiCheckout() path end-to-end.
// ============================================================
echo "\n--- Test 55: P0 regression (HTTP checkout path) ---\n";

if (!function_exists('apiCheckout')) {
    define('BB_TEST_LOAD_API_ONLY', true);
    require_once TEST_CLI_DIR . '/../src/Entrypoints/Api.php';
}

// Deterministic offline payment method, zero fees, available for SK + DE
$pdo->exec("INSERT OR IGNORE INTO payment_methods (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order) VALUES ('p0pay','manual','P0 Pay','','offline',0,0,0,0,0,0,1,98)");
$pdo->exec("INSERT OR IGNORE INTO payment_method_countries (method_code, country) VALUES ('p0pay','SK'),('p0pay','DE')");

$p0Checkout = function (array $overrides) {
    // apiActiveStore() resolves the seller store by locale (languages[0]).
    // Default CLI locale 'en' would pick the US store → seller US, no EU VAT.
    I18n::setLocale('sk');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SESSION['csrf_token'] = 'p0-regression-test';
    $_SESSION['cart'] = $overrides['__cart'] ?? [
        'p_501' => ['product_id' => 501, 'variant_id' => null, 'qty' => 2],
        'p_502' => ['product_id' => 502, 'variant_id' => null, 'qty' => 1],
    ];
    unset($overrides['__cart']);
    $country = $overrides['country'] ?? 'SK';
    // Pick whatever shipping method is actually offered for the country
    // (DB-driven rates for SK, legacy JSON fallback for DE) — the test
    // targets VAT correctness, not a specific carrier.
    $rates = ShippingService::calculateRates($country, 0, 28200, false, 0);
    $_POST = array_merge([
        'csrf_token'      => 'p0-regression-test',
        'name'            => 'P0 Regression',
        'email'           => 'p0@test.com',
        'phone'           => '',
        'street'          => 'Test Street 1',
        'city'            => 'Bratislava',
        'zip'             => '811 01',
        'country'         => $country,
        'shipping_method' => (string)($rates[0]['method_code'] ?? ''),
        'payment_method'  => 'p0pay',
    ], $overrides);
    $res = apiCheckout();
    $_POST = [];
    return $res;
};

// 55.1 — Bug #1: domestic B2C via HTTP path persists per-line VAT > 0
$r = $p0Checkout(['country' => 'SK']);
assert_true(!empty($r['success']), 'P0 #1: domestic HTTP checkout succeeds (' . ($r['error'] ?? 'ok') . ')');
$o = $pdo->query("SELECT * FROM orders WHERE id=" . (int)($r['order_id'] ?? 0))->fetch();
assert_equals('domestic', (string)($o['vat_scenario'] ?? ''), 'P0 #1: HTTP checkout scenario=domestic');
assert_true((int)($o['vat_total'] ?? 0) > 0, 'P0 #1: HTTP checkout vat_total > 0');
$minLineVat = $pdo->query("SELECT MIN(vat_amount) AS v FROM order_items WHERE order_id=" . (int)($r['order_id'] ?? 0))->fetch();
assert_true((int)($minLineVat['v'] ?? 0) > 0, 'P0 #1: every order_item has vat_amount > 0');

// 55.2 — Bug #2: gross-inclusive prices — extracted tax must NOT inflate total
assert_equals((int)$o['subtotal'] + (int)$o['shipping_cost'], (int)$o['total'],
    'P0 #2: total == gross subtotal + shipping (extracted VAT not added on top)');
assert_true((int)$o['tax_amount'] > 0, 'P0 #2: extracted tax still recorded for reporting');
$checkoutSnapshot = json_decode((string)($o['financial_snapshot'] ?? ''), true);
assert_true(is_array($checkoutSnapshot), 'G1: HTTP checkout persists canonical financial snapshot');
VatService::validateSnapshot($checkoutSnapshot);
assert_equals((int)$o['total'], (int)$checkoutSnapshot['totals']['gross'], 'G1: quote snapshot gross = order total');
assert_equals((int)$o['tax_amount'], (int)$checkoutSnapshot['totals']['vat'], 'G1: quote snapshot VAT = order tax amount');
assert_equals((int)$o['vat_total'], (int)$checkoutSnapshot['totals']['vat'], 'G1: quote snapshot VAT = order VAT total');
assert_equals((int)$o['net_total'], (int)$checkoutSnapshot['totals']['net'], 'G1: quote snapshot net = order net total');

$pdo->exec("UPDATE orders SET status='paid' WHERE id=" . (int)$o['id']);
$snapshotInvoice = InvoiceService::issue((int)$o['id']);
$invoiceSnapshot = json_decode((string)($snapshotInvoice['financial_snapshot'] ?? ''), true);
assert_equals($checkoutSnapshot, $invoiceSnapshot, 'G1: invoice copies the exact order financial snapshot');
assert_equals((int)$checkoutSnapshot['totals']['gross'], (int)$snapshotInvoice['total_gross'], 'G1: invoice gross = snapshot gross');
assert_equals((int)$checkoutSnapshot['totals']['vat'], (int)$snapshotInvoice['total_vat'], 'G1: invoice VAT = snapshot VAT');

// G8 — received item refund produces an immutable, tax-linked credit note.
$creditOrderId = (int)$o['id'];
$creditOrderItems = Orders::items($creditOrderId);
$creditOrderItem = $creditOrderItems[0];
$pdo->prepare("UPDATE orders SET status='delivered', payment_id='' WHERE id=:id")->execute([':id' => $creditOrderId]);
[$creditRmaOk, $creditRmaId] = Rma::create($creditOrderId, null, 'Item return for credit', [(int)$creditOrderItem['id'] => 1]);
assert_true($creditRmaOk && $creditRmaId > 0, 'G8 credit: partial RMA created');
assert_true(Rma::setStatus($creditRmaId, 'approved'), 'G8 credit: RMA approved');
$creditRmaItem = Rma::items($creditRmaId)[0];
assert_true(Rma::receive($creditRmaId, [(int)$creditRmaItem['id'] => ['quantity' => 1, 'disposition' => 'repair']], 'credit-receipt-1'), 'G8 credit: received quantity recorded without stock mutation');
$creditNote = InvoiceService::credit((int)$snapshotInvoice['id'], $creditRmaId, [(int)$creditRmaItem['id'] => 1], 'credit-note-1');
assert_equals('credit_note', (string)$creditNote['type'], 'G8 credit: document type is credit_note');
assert_equals((int)$snapshotInvoice['id'], (int)$creditNote['refers_to_invoice_id'], 'G8 credit: source invoice link is immutable');
assert_true((int)$creditNote['total_gross'] < 0, 'G8 credit: credited gross is negative integer cents');
assert_equals($checkoutSnapshot, json_decode((string)$creditNote['financial_snapshot'], true), 'G8 credit: source financial snapshot is preserved exactly');
$creditAuditSnapshot = json_decode((string)$creditNote['credit_snapshot'], true);
assert_equals((string)$checkoutSnapshot['fingerprint'], (string)$creditAuditSnapshot['source_fingerprint'], 'G8 credit: audit snapshot pins source fingerprint');
assert_equals((int)$creditNote['id'], (int)InvoiceService::credit((int)$snapshotInvoice['id'], $creditRmaId, [(int)$creditRmaItem['id'] => 1], 'credit-note-1')['id'], 'G8 credit: idempotency returns original credit note');
$creditReplayConflict = false;
try { InvoiceService::credit((int)$snapshotInvoice['id'], $creditRmaId, [(int)$creditRmaItem['id'] => 2], 'credit-note-1'); }
catch (LogicException $e) { $creditReplayConflict = true; }
assert_true($creditReplayConflict, 'G8 credit: idempotency key rejects changed quantities');
$creditLine = $pdo->query("SELECT * FROM credit_note_items WHERE credit_note_id=" . (int)$creditNote['id'])->fetch(PDO::FETCH_ASSOC);
assert_equals(1, (int)$creditLine['quantity'], 'G8 credit: item quantity is persisted');
assert_equals(-(int)$creditLine['gross'], (int)$creditNote['total_gross'], 'G8 credit: line gross reconciles exactly to document gross');
Rma::completeRefund((int)$creditNote['refund_id'], 'manual-refund-1');
assert_equals('succeeded', (string)Rma::refundById((int)$creditNote['refund_id'])['status'], 'G8 refund: manual payment completion is audited');
assert_equals(1, (int)Rma::items($creditRmaId)[0]['refunded_quantity'], 'G8 refund: exact item quantity is marked refunded');
assert_equals('refunded', (string)Rma::byId($creditRmaId)['status'], 'G8 refund: fully settled RMA closes');
$creditOverClaim = false;
try { InvoiceService::credit((int)$snapshotInvoice['id'], $creditRmaId, [(int)$creditRmaItem['id'] => 1], 'credit-note-over'); }
catch (LogicException $e) { $creditOverClaim = true; }
assert_true($creditOverClaim, 'G8 credit: an item cannot be credited twice');

// 55.3 — Bug #3: B2B reverse charge via HTTP path (VIES served from cache, no network)
$pdo->exec("DELETE FROM vat_id_cache");
$pdo->exec("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, trader_addr, checked_at, ttl_seconds, raw_response) VALUES ('DE811907980','DE','valid',NULL,NULL,datetime('now'),86400,NULL)");
$r2 = $p0Checkout(['country' => 'DE', 'company' => 'P0 Kunde GmbH', 'vat_id' => 'DE811907980']);
assert_true(!empty($r2['success']), 'P0 #3: B2B DE HTTP checkout succeeds (' . ($r2['error'] ?? 'ok') . ')');
$o2 = $pdo->query("SELECT * FROM orders WHERE id=" . (int)($r2['order_id'] ?? 0))->fetch();
assert_equals('reverse_charge', (string)($o2['vat_scenario'] ?? ''), 'P0 #3: VIES-validated B2B → reverse_charge via HTTP path');
assert_equals(0, (int)($o2['vat_total'] ?? -1), 'P0 #3: reverse charge vat_total == 0');
assert_equals((int)$o2['subtotal'] + (int)$o2['shipping_cost'], (int)$o2['total'],
    'P0 #3: reverse charge total has no VAT added');
// Code review M1: reverse charge charges the seller's domestic NET price, not the VAT-inclusive gross.
$crmNet = static function (int $gross, float $rate): int {
    $den = 10000 + (int)round($rate * 100);
    return intdiv($gross * 10000 + intdiv($den, 2), $den);
};
$crmExpected = [];
foreach ($pdo->query("SELECT sku, unit_price, vat_rate FROM order_items WHERE order_id=" . (int)($r['order_id'] ?? 0))->fetchAll() as $crmLine) {
    $crmExpected[$crmLine['sku']] = $crmNet((int)$crmLine['unit_price'], (float)$crmLine['vat_rate']);
}
$crmCharged = [];
foreach ($pdo->query("SELECT sku, unit_price FROM order_items WHERE order_id=" . (int)($r2['order_id'] ?? 0))->fetchAll() as $crmLine) {
    $crmCharged[$crmLine['sku']] = (int)$crmLine['unit_price'];
}
ksort($crmExpected);
ksort($crmCharged);
assert_equals($crmExpected, $crmCharged, 'CR-M1: reverse charge unit prices are the domestic prices without Slovak VAT');
assert_equals((int)$o2['subtotal'] + (int)$o2['shipping_cost'], (int)$o2['total'], 'CR-M1: reverse charge total = net subtotal + net shipping');
// The CR-M checkouts below come from the same test IP; a fresh rate store keeps them under the checkout throttle.
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
// The P0 cart ships free (pricing.json: free shipping over 50 €); one SKU-B (30,25 €) pays a DE parcel rate.
$crmDeRates = ShippingService::calculateRates('DE', 0, 3025, false, 0);
$crmPaid = array_values(array_filter($crmDeRates, static fn(array $rate): bool => (int)$rate['price'] > 0))[0] ?? null;
// Promotions left active by earlier scenarios (G6 free shipping over 10 €) would zero the rate; pause them for this order.
$crmActivePromotions = $pdo->query("SELECT id FROM promotions WHERE active = 1")->fetchAll(PDO::FETCH_COLUMN);
$pdo->exec("UPDATE promotions SET active = 0");
$r3 = $p0Checkout(['country' => 'DE', 'company' => 'P0 Kunde GmbH', 'vat_id' => 'DE811907980', 'shipping_method' => (string)($crmPaid['method_code'] ?? ''),
    '__cart' => ['p_502' => ['product_id' => 502, 'variant_id' => null, 'qty' => 1]]]);
if ($crmActivePromotions) $pdo->exec("UPDATE promotions SET active = 1 WHERE id IN (" . implode(',', array_map('intval', $crmActivePromotions)) . ")");
$o3 = $pdo->query("SELECT * FROM orders WHERE id=" . (int)($r3['order_id'] ?? 0))->fetch() ?: [];
$crmRateSnapshot = json_decode((string)($o3['shipping_rate_snapshot'] ?? ''), true) ?: [];
assert_true(!empty($r3['success']) && (int)($crmRateSnapshot['price'] ?? 0) > 0 && ($o3['vat_scenario'] ?? '') === 'reverse_charge',
    'CR-M1: reverse charge order with a paid shipping rate (' . ($r3['error'] ?? 'ok') . ')');
assert_equals($crmNet((int)($crmRateSnapshot['price'] ?? 0), VatService::rate('SK', 'standard')), (int)($o3['shipping_cost'] ?? -1),
    'CR-M1: reverse charge shipping is charged without Slovak VAT');
// Code review M2: a valid VAT ID of the seller's own country is not grounds for reverse charge.
$pdo->exec("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, trader_addr, checked_at, ttl_seconds, raw_response) VALUES ('SK2020273893','SK','valid',NULL,NULL,datetime('now'),86400,NULL)");
$r4 = $p0Checkout(['country' => 'DE', 'company' => 'P0 Domaca s.r.o.', 'vat_id' => 'SK2020273893']);
$o4 = $pdo->query("SELECT * FROM orders WHERE id=" . (int)($r4['order_id'] ?? 0))->fetch() ?: [];
assert_true(!empty($r4['success']) && ($o4['vat_scenario'] ?? '') !== 'reverse_charge' && (int)($o4['vat_total'] ?? 0) > 0,
    'CR-M2: a Slovak VAT ID buying from a Slovak seller pays VAT, no reverse charge (' . ($r4['error'] ?? ($o4['vat_scenario'] ?? '')) . ')');
// Code review M4: an exclusive coupon is not added on top of automatic promotions; a cancelled order gives the coupon use back.
$pdo->exec("INSERT INTO promotions (store_id, code, name, scope, coupon_code, priority, stacking, conditions, actions, active)
    VALUES ('default', 'CRM4AUTO', 'CRM4 automatic', 'cart', NULL, 10, 'stack', '{}', '{\"type\":\"discount_fixed\",\"amount\":500}', 1),
           ('default', 'CRM4COUPON', 'CRM4 exclusive coupon', 'cart', 'CRM4EXCL', 20, 'exclusive', '{\"max_uses\":1}', '{\"type\":\"discount_fixed\",\"amount\":1000}', 1)");
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
try {
    $r5 = $p0Checkout(['country' => 'SK', 'coupon_code' => 'CRM4EXCL']);
    $o5 = $pdo->query("SELECT * FROM orders WHERE id=" . (int)($r5['order_id'] ?? 0))->fetch() ?: [];
    assert_true(!empty($r5['success']) && (int)($o5['discount_amount'] ?? -1) === 1000,
        'CR-M4: an exclusive coupon (10 €) replaces the automatic 5 € discount instead of adding to it (' . ($r5['error'] ?? ($o5['discount_amount'] ?? '')) . ')');
    if ((int)($r5['order_id'] ?? 0) > 0) OrderWorkflow::transition((int)$r5['order_id'], 'cancelled', 'CR-M4 cancel');
    assert_equals(0, (int)$pdo->query("SELECT COUNT(*) FROM coupon_uses WHERE order_id=" . (int)($r5['order_id'] ?? 0))->fetchColumn(), 'CR-M4: cancelling the order removes its coupon use');
    $r6 = $p0Checkout(['country' => 'SK', 'coupon_code' => 'CRM4EXCL']);
    assert_true(!empty($r6['success']), 'CR-M4: a single-use coupon from a cancelled order can be used again (' . ($r6['error'] ?? 'ok') . ')');
    $pdo->exec("UPDATE promotions SET actions = '{\"type\":\"discount_fixed\",\"amount\":300}', conditions = '{}' WHERE code = 'CRM4COUPON'");
    $r7 = $p0Checkout(['country' => 'SK', 'coupon_code' => 'CRM4EXCL']);
    assert_true(empty($r7['success']) && ($r7['error_code'] ?? '') === 'coupon_not_combinable',
        'CR-M4: an exclusive coupon worth less than the automatic discount is refused, not summed (' . ($r7['error'] ?? 'placed') . ')');
} finally {
    $pdo->exec("DELETE FROM promotions WHERE code IN ('CRM4AUTO', 'CRM4COUPON')");
    // Later scenarios share this session; give them a checkout throttle untouched by the CR-M checkouts.
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
}

// 55.4 — Bug #4: mock webhook routing gate is closed in the shipped config template
$prodCfg = require dirname(TEST_CLI_DIR) . '/config.example.php';
assert_true(($prodCfg['allow_mock_webhook'] ?? true) === false, 'P0 #4: allow_mock_webhook ships as false');
assert_true(($prodCfg['adapters']['payment'] ?? '') !== 'MockPayment', 'P0 #4: shipped payment adapter is not MockPayment');
$mockRoutable = (($prodCfg['adapters']['payment'] ?? '') === 'MockPayment' || !empty($prodCfg['allow_mock_webhook']));
assert_true(!$mockRoutable, 'P0 #4: webhook_payment provider=mock not routable in production config');

// 55.5 — Bug #4: MockPayment signature is a real secret comparison
$mpSig = new MockPayment(['mock_webhook_secret' => 'test-mock-secret-123']);
$mpPayloadSig = hash_hmac('sha256', 'payload', 'test-mock-secret-123');
assert_true(!(new MockPayment([]))->verifySignature('payload', hash_hmac('sha256', 'payload', 'mock_sig')), 'G3: predictable default mock signature is rejected');
assert_true(!(new MockPayment(['mock_webhook_secret' => 'short']))->verifySignature('payload', hash_hmac('sha256', 'payload', 'short')), 'G3: weak mock webhook secret fails closed');
assert_true($mpSig->verifySignature('payload', $mpPayloadSig), 'P0 #4: correct raw-payload HMAC accepted');
assert_true(!$mpSig->verifySignature('tampered', $mpPayloadSig), 'G3: mock signature is bound to the raw payload');
assert_true(!$mpSig->verifySignature('payload', 'wrong-sig'), 'P0 #4: wrong webhook signature rejected');
assert_true(!$mpSig->verifySignature('payload', ''), 'P0 #4: empty webhook signature rejected');

// 55.6 — OSS consumes the issued invoice snapshot, including discount + shipping
$ossContext = [
    'seller_country'=>'SK', 'seller_oss_enabled'=>true, 'customer_country'=>'DE',
    'customer_vat_id_valid'=>false, 'is_b2b'=>false,
];
$_SESSION['cart'] = [
    'p_501' => ['product_id'=>501, 'variant_id'=>null, 'qty'=>1],
    'p_502' => ['product_id'=>502, 'variant_id'=>null, 'qty'=>1],
];
$ossCart = Cart::enriched($ossContext);
$ossSnapshot = VatService::buildSnapshot($ossCart['items'], $ossContext, [
    'discount'=>2330, 'shipping'=>600, 'payment_fee'=>0, 'currency'=>'eur', 'effective_date'=>date('Y-m-d'),
]);
$ossOrderId = Orders::create($ossCart['items'], [
    'name'=>'OSS Buyer', 'email'=>'oss@example.de', 'phone'=>'',
    'address'=>['street'=>'A 1', 'city'=>'Berlin', 'zip'=>'10115', 'country'=>'DE'],
    'currency'=>'eur', 'locale'=>'de', 'shipping_cost'=>600, 'payment_fee'=>0,
    'tax_amount'=>$ossSnapshot['totals']['vat'], 'coupon_discount'=>2330,
    'vat_scenario'=>'oss', 'financial_snapshot'=>$ossSnapshot,
], 'p0pay');
$pdo->exec("UPDATE orders SET status='paid' WHERE id={$ossOrderId}");
$ossInvoice = InvoiceService::issue($ossOrderId);
assert_equals($ossSnapshot, json_decode((string)$ossInvoice['financial_snapshot'], true), 'G1: OSS invoice preserves exact checkout snapshot');
if (!function_exists('collect')) {
    define('BB_TEST_LOAD_OSS_ONLY', true);
    require_once TEST_CLI_DIR . '/oss-report.php';
}
$ossRows = collect(date('Y-m-d'), date('Y-m-d'), null);
$ossByRate = [];
foreach ($ossRows as $ossRow) {
    if (($ossRow['consumption_country'] ?? '') === 'DE') $ossByRate[(string)$ossRow['vat_rate']] = $ossRow;
}
foreach ($ossSnapshot['breakdown'] as $rate => $group) {
    assert_true(isset($ossByRate[(string)(float)$rate]), "G1: OSS contains snapshot rate {$rate}");
    $reported = $ossByRate[(string)(float)$rate] ?? [];
    assert_equals((int)$group['net'], (int)($reported['net'] ?? -1), "G1: OSS net matches snapshot rate {$rate}");
    assert_equals((int)$group['vat'], (int)($reported['vat'] ?? -1), "G1: OSS VAT matches snapshot rate {$rate}");
    assert_equals((int)$group['gross'], (int)($reported['gross'] ?? -1), "G1: OSS gross matches snapshot rate {$rate}");
}

echo "\n--- VAT test suite complete ---\n";

// Re-init the global Db singleton on the main test DB so any later tests still work
Db::init($pdo, 'sqlite');

// ============================================================
// TEST 56: P1 regression — Warehouse::reserve oversell guard
// Bug #6: reserve() computed availability in PHP from a SELECT
// snapshot, then unconditionally UPDATEd reserved — two parallel
// checkouts could both grab the last piece. The UPDATE is now
// guarded by (qty - reserved) >= take. Bonus: a failed reserve
// used to leak partial allocations; it must release them.
// ============================================================
echo "\n--- Test 56: P1 regression (Warehouse::reserve) ---\n";

$pdo->exec("DELETE FROM warehouse_stock");
$pdo->exec("DELETE FROM warehouses WHERE id IN (91,92)");
$pdo->exec("INSERT INTO warehouses (id,code,name,priority,is_default) VALUES (91,'p1main','P1 Main',900,0),(92,'p1aux','P1 Aux',800,0)");
$pdo->exec("INSERT INTO warehouse_stock (warehouse_id,sku,qty,reserved) VALUES (91,'SKU-R',3,0),(92,'SKU-R',2,0)");

// 56.1 — happy path: drains priority warehouse first, splits remainder
$reserveAllocs = Warehouse::reserve('SKU-R', 4);
assert_equals(2, count($reserveAllocs), 'Reserve: split across 2 warehouses');
assert_equals(3, (int)$reserveAllocs[0]['qty'], 'Reserve: priority warehouse drained first');
$whR1 = (int)$pdo->query("SELECT reserved FROM warehouse_stock WHERE warehouse_id=91 AND sku='SKU-R'")->fetchColumn();
$whR2 = (int)$pdo->query("SELECT reserved FROM warehouse_stock WHERE warehouse_id=92 AND sku='SKU-R'")->fetchColumn();
assert_equals(3, $whR1, 'Reserve: wh1 reserved=3');
assert_equals(1, $whR2, 'Reserve: wh2 reserved=1');

// 56.2 — oversell: only 1 piece left in total, asking for 2 must throw
// AND must not leak the partial allocation it grabbed before failing
$reserveThrew = false;
try { Warehouse::reserve('SKU-R', 2); } catch (\RuntimeException $e) { $reserveThrew = true; }
assert_true($reserveThrew, 'Reserve: oversell throws RuntimeException');
$whR1 = (int)$pdo->query("SELECT reserved FROM warehouse_stock WHERE warehouse_id=91 AND sku='SKU-R'")->fetchColumn();
$whR2 = (int)$pdo->query("SELECT reserved FROM warehouse_stock WHERE warehouse_id=92 AND sku='SKU-R'")->fetchColumn();
assert_equals(3, $whR1, 'Reserve: failed reserve leaves wh1 untouched');
assert_equals(1, $whR2, 'Reserve: failed reserve rolls back partial allocation on wh2');

// 56.3 — the atomic guard itself: a stale-snapshot UPDATE asking for more
// than is available must affect 0 rows (this is what closes the race)
$guardStmt = $pdo->prepare("UPDATE warehouse_stock SET reserved = reserved + :take
    WHERE warehouse_id = 92 AND sku = 'SKU-R' AND (qty - reserved) >= :take2");
$guardStmt->bindValue(':take', 2, PDO::PARAM_INT);
$guardStmt->bindValue(':take2', 2, PDO::PARAM_INT);
$guardStmt->execute();
assert_equals(0, $guardStmt->rowCount(), 'Reserve guard: conditional UPDATE refuses oversell (avail=1, take=2)');
$guardStmt->bindValue(':take', 1, PDO::PARAM_INT);
$guardStmt->bindValue(':take2', 1, PDO::PARAM_INT);
$guardStmt->execute();
assert_equals(1, $guardStmt->rowCount(), 'Reserve guard: conditional UPDATE allows take within availability');
$pdo->exec("UPDATE warehouse_stock SET reserved = reserved - 1 WHERE warehouse_id = 92 AND sku = 'SKU-R'");

// 56.4 — release returns reservations symmetrically
Warehouse::release('SKU-R', $reserveAllocs);
$whSum = (int)$pdo->query("SELECT SUM(reserved) FROM warehouse_stock WHERE sku='SKU-R'")->fetchColumn();
assert_equals(0, $whSum, 'Release: all reservations returned');

// ============================================================
// TEST 57: P1 regression — return flow must not eat foreign reservations
// Bug #7: handleReturn restored stock via the release path, which
// decremented warehouse_stock.reserved. But a returned order's own
// reservation was already consumed at 'shipped' — whatever 'reserved'
// remains belongs to OTHER open orders. A physical return must only
// increment qty. Cancel (never shipped) must keep releasing 'reserved'.
// ============================================================
echo "\n--- Test 57: P1 regression (return flow vs foreign reservations) ---\n";

$pdo->exec("INSERT INTO products (id,category_id,name,slug,sku,price,currency,status,vat_class,stock) VALUES
    (503,1,'Returner','returner','SKU-RT',5000,'eur','active','standard',10)");
$pdo->exec("DELETE FROM warehouse_stock WHERE sku='SKU-RT'");
$pdo->exec("INSERT INTO warehouse_stock (warehouse_id,sku,qty,reserved) VALUES (1,'SKU-RT',10,0)");

$rtItem = fn(int $qty) => [[
    'product_id' => 503, 'variant_id' => null, 'qty' => $qty,
    'price' => 5000, 'sku' => 'SKU-RT', 'name' => 'Returner', 'variant_name' => '',
]];
$rtCustomer = [
    'name' => 'RT', 'email' => 'rt@test.com',
    'address' => ['street' => 'X 1', 'city' => 'BA', 'zip' => '81101', 'country' => 'SK'],
    'currency' => 'eur', 'shipping_cost' => 0, 'tax_amount' => 0,
];

$rtA = Orders::create($rtItem(2), $rtCustomer, 'stripe');   // order under test (will ship + return)
$rtB = Orders::create($rtItem(3), $rtCustomer, 'stripe');   // foreign open order (holds reservation)
$rtStock = fn() => $pdo->query("SELECT qty, reserved FROM warehouse_stock WHERE warehouse_id=1 AND sku='SKU-RT'")->fetch(PDO::FETCH_ASSOC);

$s = $rtStock();
assert_equals(5, (int)$s['reserved'], 'Return: A(2)+B(3) reserved=5');

// Ship A: its reservation is consumed (reserved -2, qty -2)
OrderWorkflow::transition($rtA, 'paid');
OrderWorkflow::transition($rtA, 'shipped');
$s = $rtStock();
assert_equals(8, (int)$s['qty'], 'Return: shipped decrements qty (10→8)');
assert_equals(3, (int)$s['reserved'], 'Return: shipped consumed own reservation (5→3)');

// Return A: goods come back as qty; B's reservation must stay intact
OrderWorkflow::transition($rtA, 'returned');
$s = $rtStock();
assert_equals(10, (int)$s['qty'], 'Return: qty restored (8→10)');
assert_equals(3, (int)$s['reserved'], 'Return: foreign reservation NOT eaten (reserved stays 3)');
assert_equals(7, (int)$pdo->query("SELECT stock FROM products WHERE id=503")->fetchColumn(), 'Return: products.stock = qty - foreign reserved = 7');

// Idempotency: second restore is a no-op (stock_restored guard now also covers the qty increment)
Orders::restoreStock($rtA, 'return');
$s = $rtStock();
assert_equals(10, (int)$s['qty'], 'Return: double restore idempotent (qty)');
assert_equals(3, (int)$s['reserved'], 'Return: double restore idempotent (reserved)');

// Cancel branch: B cancelled before shipping → releases its reservation, qty untouched
OrderWorkflow::transition($rtB, 'paid');
OrderWorkflow::transition($rtB, 'cancelled');
$s = $rtStock();
assert_equals(10, (int)$s['qty'], 'Cancel: qty untouched (still 10)');
assert_equals(0, (int)$s['reserved'], 'Cancel: releases own reservation (3→0)');
assert_equals(10, (int)$pdo->query("SELECT stock FROM products WHERE id=503")->fetchColumn(), 'Cancel: products.stock synced back to 10');

// ============================================================
// TEST 58: P1 regression — stock_restored column replaces notes heuristic
// Bug #11: the double-restore guard was notes LIKE '%stock_restored%' —
// any order whose notes happened to contain that text (customer note,
// admin comment) silently SKIPPED its stock restore. Now a dedicated
// orders.stock_restored flag is claimed atomically (0 → 1).
// ============================================================
echo "\n--- Test 58: P1 regression (stock_restored flag) ---\n";

// 58.1 — restored orders carry the flag, not a notes marker
assert_equals(1, (int)$pdo->query("SELECT stock_restored FROM orders WHERE id={$rtA}")->fetchColumn(), 'Flag: returned order has stock_restored=1');
assert_equals(1, (int)$pdo->query("SELECT stock_restored FROM orders WHERE id={$rtB}")->fetchColumn(), 'Flag: cancelled order has stock_restored=1');
assert_true(!str_contains((string)$pdo->query("SELECT notes FROM orders WHERE id={$rtA}")->fetchColumn(), 'stock_restored'), 'Flag: notes no longer abused as marker');

// 58.2 — red herring: customer note containing the literal marker text
// must NOT block the restore (the old heuristic skipped it)
$rtCustomerC = $rtCustomer;
$rtCustomerC['notes'] = 'please gift-wrap; ref stock_restored ticket #42';
$rtC = Orders::create($rtItem(2), $rtCustomerC, 'stripe');
$s = $rtStock();
assert_equals(2, (int)$s['reserved'], 'Flag: order C reserved 2');
OrderWorkflow::transition($rtC, 'cancelled');
$s = $rtStock();
assert_equals(0, (int)$s['reserved'], 'Flag: restore runs despite marker text in notes (old heuristic would skip)');
assert_equals(1, (int)$pdo->query("SELECT stock_restored FROM orders WHERE id={$rtC}")->fetchColumn(), 'Flag: order C stock_restored=1');

// 58.3 — atomic claim: direct second restore is refused by the flag
Orders::restoreStock($rtC);
$s = $rtStock();
assert_equals(0, (int)$s['reserved'], 'Flag: repeated restore is a no-op (reserved stays 0)');
assert_equals(10, (int)$s['qty'], 'Flag: repeated restore is a no-op (qty stays 10)');

// ============================================================
// TEST 59: P1 regression — invoices(order_id) UNIQUE + race-safe issue
// Bug #9: InvoiceService::issue had a check-then-insert race — two
// concurrent issue() calls could both pass the byOrderId() null-check
// and INSERT, creating two invoices (two numbers, two PDFs) for one
// order. The DB now enforces one type='invoice' row per order via a
// partial unique index; issue() catches the violation (SQLSTATE 23000)
// and returns the winner's invoice instead of throwing.
// ============================================================
echo "\n--- Test 59: P1 regression (invoice UNIQUE order_id) ---\n";

// Seed a fresh paid order (invoices table + unique index exist from the
// Invoices test section; counters survive — numbers just keep growing)
$pdo->exec("INSERT INTO orders (id, order_number, status, customer_name, customer_email, shipping_addr, payment_method, subtotal, discount_amount, shipping_cost, tax_amount, total, currency, locale, created_at) VALUES (5901, 'INV-5901', 'paid', 'Race Test', 'race@example.sk', '{}', 'card', 2000, 0, 0, 0, 2000, 'eur', 'sk', datetime('now'))");
$pdo->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (5901, 501, 'SKU-S', 'Race produkt', 1, 2000, 2000)");

// 59.1 — idempotent issue (early-return path)
$ri1 = InvoiceService::issue(5901);
$ri2 = InvoiceService::issue(5901);
assert_equals((int)$ri1['id'], (int)$ri2['id'], 'UQ: re-issue returns same invoice id');

// 59.2 — the race's losing writer: raw duplicate INSERT for the same order
// must be rejected by the unique index with SQLSTATE 23000
$dupCode = null;
try {
    $pdo->exec("INSERT INTO invoices (order_id, number, issued_at, pdf_path, download_token, type) VALUES (5901, 'DUP-0001', '2026-06-11', 'x.pdf', '" . str_repeat('a', 64) . "', 'invoice')");
} catch (\PDOException $e) { $dupCode = (string)$e->getCode(); }
assert_equals('23000', $dupCode, 'UQ: duplicate invoice INSERT raises SQLSTATE 23000');
assert_equals(1, (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE order_id=5901 AND type='invoice'")->fetchColumn(), 'UQ: still exactly 1 invoice for order');

// 59.3 — credit note for the same order is NOT blocked (index is partial)
$pdo->exec("INSERT INTO invoices (order_id, number, issued_at, pdf_path, download_token, type) VALUES (5901, 'CN-0001', '2026-06-11', 'cn.pdf', '" . str_repeat('b', 64) . "', 'credit_note')");
assert_equals(2, (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE order_id=5901")->fetchColumn(), 'UQ: credit_note for same order allowed');

// 59.4 — byOrderId prefers the live invoice over non-invoice rows
$pref = InvoiceService::byOrderId(5901);
assert_equals((int)$ri1['id'], (int)($pref['id'] ?? 0), 'UQ: byOrderId prefers type=invoice row');

// 59.5 — migration dedupe: pre-existing duplicates (the bug's damage) are
// voided (kept for audit as type='duplicate', status='cancelled'), lowest
// id survives, index is created. Isolated scratch DB — DDL on the live
// test DB trips SQLITE_LOCKED from open statement handles.
$uqDb = new PDO('sqlite::memory:');
$uqDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$uqDb->exec("CREATE TABLE invoices (
    id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL,
    number TEXT NOT NULL UNIQUE, type TEXT NOT NULL DEFAULT 'invoice',
    status TEXT NOT NULL DEFAULT 'issued'
)");
$uqDb->exec("INSERT INTO invoices (order_id, number, type) VALUES
    (7001, '20260001', 'invoice'),
    (7001, '20260002', 'invoice'),
    (7002, '20260003', 'invoice'),
    (7001, '20260004', 'credit_note')");
$uqMig->up($uqDb, true);
assert_equals(1, (int)$uqDb->query("SELECT COUNT(*) FROM invoices WHERE order_id=7001 AND type='invoice'")->fetchColumn(), 'UQ: migration dedupe leaves 1 live invoice');
assert_equals('20260001', (string)$uqDb->query("SELECT number FROM invoices WHERE order_id=7001 AND type='invoice'")->fetchColumn(), 'UQ: dedupe keeps lowest id (original invoice)');
$voided = $uqDb->query("SELECT type, status FROM invoices WHERE number='20260002'")->fetch(PDO::FETCH_ASSOC);
assert_equals('duplicate', (string)$voided['type'], 'UQ: dup row voided as type=duplicate');
assert_equals('cancelled', (string)$voided['status'], 'UQ: dup row status=cancelled');
assert_equals('credit_note', (string)$uqDb->query("SELECT type FROM invoices WHERE number='20260004'")->fetchColumn(), 'UQ: credit_note untouched by dedupe');
$uqMig->up($uqDb, true); // idempotent re-run must not throw
$dupCode2 = null;
try {
    $uqDb->exec("INSERT INTO invoices (order_id, number, type) VALUES (7001, '20260005', 'invoice')");
} catch (\PDOException $e) { $dupCode2 = (string)$e->getCode(); }
assert_equals('23000', $dupCode2, 'UQ: index active after migration (dup INSERT blocked)');
$uqDb = null;

// ============================================================
// TEST: Bootstrap shared front-controller helpers (P5)
// ============================================================
echo "\n--- Test: Bootstrap shared helpers (P5) ---\n";

// stores() returns the decoded stores.json, byte-for-byte parity with a direct decode
$bsStoresDirect = json_decode((string)file_get_contents(TEST_CLI_DIR . '/../stores.json'), true) ?: [];
assert_equals($bsStoresDirect, Bootstrap::stores(), 'Bootstrap: stores() equals direct stores.json decode');
assert_true(Bootstrap::stores() === Bootstrap::stores(), 'Bootstrap: stores() returns stable cached value across calls');

// initLocale() parity with I18n::detectLocale + applies the locale
$savedLocale = I18n::locale();
assert_equals(I18n::detectLocale('/de/products'), Bootstrap::initLocale('/de/products'), 'Bootstrap: initLocale parity with I18n::detectLocale (/de)');
assert_equals('de', I18n::locale(), 'Bootstrap: initLocale applied de locale');
assert_equals('sk', Bootstrap::initLocale('/'), 'Bootstrap: initLocale uses the active store default without a route prefix');
assert_equals('sk', I18n::locale(), 'Bootstrap: initLocale applies the active store default before the system fallback');
I18n::setLocale($savedLocale); // restore for downstream tests

// CR-A (code review 2026-10-15): the bundled vertical-specific category dictionary left the core. An untranslated
// category keeps its base name instead of a hardcoded vertical translation.
assert_true(!method_exists('Bootstrap', 'catTranslations'), 'CR-A: Bootstrap has no legacy auto-parts category dictionary');
assert_true(!is_file(TEST_CLI_DIR . '/../data/category-translations.php'), 'CR-A: data/category-translations.php is gone');

// hreflangAlternates() — alternates keyed by locale, prefix stripping, x-default
$hl = Bootstrap::hreflangAlternates('/de/products');
assert_equals('/de', $hl['alternates']['de'] ?? null, 'Bootstrap: hreflang de alternate maps to /de prefix');
assert_equals('/cs', $hl['alternates']['cs'] ?? null, 'Bootstrap: hreflang cs alternate maps to /cs prefix');
assert_equals('', $hl['alternates']['sk'] ?? 'MISSING', 'Bootstrap: hreflang sk alternate maps to empty prefix');
assert_equals('/products', $hl['pathNoPrefix'], 'Bootstrap: hreflang strips matched /de store prefix from path');
$hlRoot = Bootstrap::hreflangAlternates('/');
assert_equals('/', $hlRoot['pathNoPrefix'], 'Bootstrap: hreflang keeps / for unprefixed path');

// ============================================================
// TEST: SEO pack — sitemap.xml, robots.txt, JSON-LD (P6)
// ============================================================
echo "\n--- Test: SEO pack — sitemap / robots / JSON-LD (P6) ---\n";

// Known active rows so the sitemap has concrete category + product paths
$pdo->exec("INSERT INTO categories (slug, name, path, depth, sort_order, meta) VALUES ('seo-cat', 'SEO Cat', 'seo-cat', 0, 999, '{}')");
$seoCatId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO products (sku, slug, product_type, name, category_id, price, stock, status, summary, filter_tags)
    VALUES ('SEO-1', 'seo-prod', 'simple', 'SEO Prod', {$seoCatId}, 1000, 3, 'active', '', '')");

// --- Sitemap::paths() collects canonical prefix-less paths ---
$smPaths = Sitemap::paths();
assert_true(in_array('/', $smPaths, true), 'Sitemap: paths() always includes homepage');
assert_true(in_array('/category/seo-cat', $smPaths, true), 'Sitemap: paths() includes category route');
assert_true(in_array('/product/seo-prod', $smPaths, true), 'Sitemap: paths() includes active product route');

// --- Sitemap::xml() is well-formed and locale-complete ---
$smXml = Sitemap::xml('https://shop.example');
$smDoc = new DOMDocument();
assert_true($smDoc->loadXML($smXml) !== false, 'Sitemap: xml() is well-formed XML');
assert_true(str_contains($smXml, '<loc>https://shop.example/</loc>'), 'Sitemap: xml() contains absolute homepage loc');
assert_true(str_contains($smXml, 'hreflang="de"') && str_contains($smXml, 'hreflang="cs"'), 'Sitemap: xml() emits hreflang alternates');
assert_true(str_contains($smXml, 'hreflang="x-default"'), 'Sitemap: xml() emits x-default alternate');
assert_true(str_contains($smXml, 'https://shop.example/product/seo-prod'), 'Sitemap: xml() includes product URL');

// --- Robots::txt() — Sitemap directive + private-path disallows ---
$rb = Robots::txt('https://shop.example');
assert_true(str_contains($rb, 'User-agent: *'), 'Robots: declares wildcard user-agent');
assert_true(str_contains($rb, 'Sitemap: https://shop.example/sitemap.xml'), 'Robots: absolute Sitemap directive');
assert_true(str_contains($rb, 'Disallow: /admin'), 'Robots: disallows admin');
assert_true(str_contains($rb, 'Disallow: /checkout'), 'Robots: disallows checkout');
assert_true(str_contains($rb, 'Allow: /'), 'Robots: allows public root');

// --- JsonLd::organization() — default store identity ---
$ldOrg = JsonLd::organization('https://shop.example', Bootstrap::stores());
assert_equals('Organization', $ldOrg['@type'], 'JsonLd: organization @type');
assert_equals('Woodle s.r.o.', $ldOrg['name'], 'JsonLd: organization name from default store legal');
assert_equals('https://shop.example/', $ldOrg['url'], 'JsonLd: organization absolute url');

// --- JsonLd::webSite() — SearchAction sitelinks searchbox ---
$ldWs = JsonLd::webSite('https://shop.example', Bootstrap::stores());
assert_equals('WebSite', $ldWs['@type'], 'JsonLd: webSite @type');
assert_equals('SearchAction', $ldWs['potentialAction']['@type'], 'JsonLd: webSite has SearchAction');
assert_true(str_contains($ldWs['potentialAction']['target']['urlTemplate'], '{search_term_string}'), 'JsonLd: SearchAction target carries search_term_string');

// --- JsonLd::breadcrumbList() — ordered trail, leaf has no item ---
$ldBc = JsonLd::breadcrumbList([
    ['name' => 'Home', 'url' => '/'],
    ['name' => 'Kitchen', 'url' => '/category/kitchen'],
    ['name' => 'Chef Knife'],
], 'https://shop.example');
assert_equals('BreadcrumbList', $ldBc['@type'], 'JsonLd: breadcrumb @type');
assert_equals(3, count($ldBc['itemListElement']), 'JsonLd: breadcrumb has all items');
assert_equals(1, $ldBc['itemListElement'][0]['position'], 'JsonLd: breadcrumb positions are 1-based');
assert_equals('https://shop.example/category/kitchen', $ldBc['itemListElement'][1]['item'], 'JsonLd: breadcrumb ancestor has absolute item URL');
assert_true(!isset($ldBc['itemListElement'][2]['item']), 'JsonLd: breadcrumb leaf omits item (current page)');

// --- JsonLd::render() — nonce'd script wrapper with valid JSON payload ---
$ldHtml = JsonLd::render($ldOrg, 'nonce-xyz');
assert_true(str_contains($ldHtml, 'type="application/ld+json"'), 'JsonLd: render emits ld+json script');
assert_true(str_contains($ldHtml, 'nonce="nonce-xyz"'), 'JsonLd: render carries CSP nonce');
$ldPayload = substr($ldHtml, strpos($ldHtml, '>') + 1, -strlen('</script>'));
assert_true(json_decode($ldPayload) !== null, 'JsonLd: render payload is valid JSON');

// --- S1.3: admin CSP nonce alignment (Idea #1) ---------------------------
$adminSrc = entrypointSource('admin.php');
$securitySrc = file_get_contents(TEST_CLI_DIR . '/../src/Security.php') ?: '';

// (a) the admin.php unsafe-inline CSP override line must be gone — admin now
//     inherits the nonce-based CSP from Security::headers().
assert_true(
    !str_contains($adminSrc, "script-src 'self' 'unsafe-inline'"),
    'Admin CSP: no unsafe-inline script-src override in admin.php'
);

// (b) grep-guard — no inline event handlers (on*=) remain anywhere in admin.php;
//     CSP nonce does not authorize inline handler attributes, so any survivor
//     would silently break under the inherited policy.
assert_true(
    preg_match('/\son(click|submit|change|input|load|mouse\w+|key\w+)\s*=\s*["\']/i', $adminSrc) === 0,
    'Admin CSP: no inline on*= event handlers in admin.php'
);

$renderSources = glob(TEST_CLI_DIR . '/../*.php') ?: [];
$renderSources = array_merge(
    $renderSources,
    glob(TEST_CLI_DIR . '/../themes/*/blocks/*.html') ?: [],
    glob(TEST_CLI_DIR . '/../themes/*/blocks/*/*.html') ?: []
);
$inlineHandlerPattern = '/\son(click|submit|change|input|load|mouse\w+|key\w+)\s*=\s*["\']/i';
foreach ($renderSources as $renderSource) {
    $source = file_get_contents($renderSource) ?: '';
    assert_true(
        preg_match($inlineHandlerPattern, $source) === 0,
        'Storefront CSP: no inline event handlers in ' . basename($renderSource)
    );
}

// (c) admin inline <script> blocks still carry the request nonce.
assert_true(
    str_contains($adminSrc, '<script nonce="<?= Security::cspNonce() ?>">'),
    'Admin CSP: inline scripts carry Security::cspNonce()'
);

// (d) the delegated nonce'd listener layer + data-* hooks are present.
assert_true(
    str_contains($adminSrc, "document.addEventListener('submit'") &&
    str_contains($adminSrc, 'form[data-confirm]'),
    'Admin CSP: delegated data-confirm submit listener present'
);
assert_true(
    str_contains($adminSrc, 'data-action="add-option"') &&
    str_contains($adminSrc, 'data-action="filter-cats"') &&
    str_contains($adminSrc, 'data-action="toggle-cats"'),
    'Admin CSP: data-action hooks replace inline function-call handlers'
);

$storefrontSrc = entrypointSource('index.php');
$storefrontThemeSrc = '';
foreach (glob(TEST_CLI_DIR . '/../themes/default/blocks/storefront/*.html') ?: [] as $block) $storefrontThemeSrc .= file_get_contents($block) ?: '';
$storefrontJsSrc = file_get_contents(TEST_CLI_DIR . '/../themes/default/assets/storefront.js') ?: '';
$accountSrc = entrypointSource('account.php')
    . (file_get_contents(TEST_CLI_DIR . '/../themes/default/blocks/storefront/account-page.html') ?: '')
    . $storefrontJsSrc;
assert_true(
    str_contains($storefrontThemeSrc, 'data-storefront-action="cart-add"') &&
    str_contains($storefrontJsSrc, "event.target.closest('[data-storefront-action=\"cart-add\"]')") &&
    str_contains($storefrontThemeSrc, 'data-submit-on-change') &&
    str_contains($storefrontThemeSrc, 'data-product-tab'),
    'Storefront CSP: delegated cart, form-change, and product-tab hooks are present'
);
assert_true(
    str_contains($accountSrc, 'data-confirm="') &&
    str_contains($accountSrc, "event.target.closest('form[data-confirm]')"),
    'Account CSP: delegated data-confirm submit listener is present'
);

// (e) Security::headers() builds a nonce-based script-src with NO unsafe-inline.
assert_true(
    str_contains($securitySrc, "script-src 'self' 'nonce-\$nonce'"),
    'Admin CSP: Security script-src is nonce-based'
);
assert_true(
    preg_match('/script-src[^;"]*\'unsafe-inline\'/', $securitySrc) === 0,
    'Admin CSP: Security script-src has no unsafe-inline'
);

// (f) runtime — cspNonce() yields a non-empty per-request token.
assert_true(
    Security::cspNonce() !== '' && Security::cspNonce() === Security::cspNonce(),
    'Admin CSP: cspNonce() returns a stable non-empty per-request nonce'
);

// ============================================================
// TEST: Audit v1.12 remediation — Sprint A (F1, F3, F4, F8, F9)
// ============================================================
echo "\n--- Audit v1.12 remediation (Sprint A) ---\n";

// --- F1: Import::parsePriceToCents must be PUBLIC so FeedImport price_stock
//     mode (FeedImport.php:95) can call it cross-class without a fatal Error.
assert_true(
    (new ReflectionMethod(Import::class, 'parsePriceToCents'))->isPublic(),
    'F1: Import::parsePriceToCents is public (FeedImport cross-class call no longer fatals)'
);
assert_equals(1990, Import::parsePriceToCents('19,90'), 'F1: parsePriceToCents parses "19,90" → 1990');
assert_equals(2500, Import::parsePriceToCents(2500), 'F1: parsePriceToCents passes int cents through unchanged');

// --- F3: Security::safeLocalPath blocks open-redirect vectors.
assert_equals('account.php', Security::safeLocalPath('//evil.com', 'account.php'), 'F3: protocol-relative //evil.com rejected');
assert_equals('account.php', Security::safeLocalPath('http://evil.com', 'account.php'), 'F3: absolute http URL rejected');
assert_equals('account.php', Security::safeLocalPath('javascript:alert(1)', 'account.php'), 'F3: javascript: scheme rejected');
assert_equals('account.php', Security::safeLocalPath('/\\evil.com', 'account.php'), 'F3: backslash protocol-relative /\\ rejected');
assert_equals('account.php', Security::safeLocalPath('\\\\evil.com', 'account.php'), 'F3: backslash host \\\\evil rejected');
assert_equals('account.php', Security::safeLocalPath("acc\r\nSet-Cookie: x", 'account.php'), 'F3: CRLF header-injection rejected');
assert_equals('account.php', Security::safeLocalPath('', 'account.php'), 'F3: empty value falls back');
assert_equals('account.php?p=privacy', Security::safeLocalPath('account.php?p=privacy', 'fb'), 'F3: same-site relative path allowed');
assert_equals('/account?x=1', Security::safeLocalPath('/account?x=1', 'fb'), 'F3: single-leading-slash local path allowed');
assert_true(str_contains(entrypointSource('index.php'), 'Security::safeLocalPath('), 'F3: storefront cart redirect uses the same open-redirect boundary');
assert_equals('https://shop.example/base', Security::appUrl(['app_url' => 'https://shop.example/base/']), 'G3: configured app_url is normalized');
foreach (['', '//evil.example', 'javascript:alert(1)', 'https://user:pass@shop.example', 'https://shop.example/?next=evil', "https://shop.example\r\n.evil"] as $badAppUrl) {
    $appUrlRejected = false;
    try {
        Security::appUrl(['app_url' => $badAppUrl]);
    } catch (\RuntimeException $e) {
        $appUrlRejected = true;
    }
    assert_true($appUrlRejected, 'G3: invalid app_url rejected: ' . json_encode($badAppUrl));
}

// --- F4: Orders::create rejects qty<=0 / negative price; stock never inflates.
$f4Before = (int)$pdo->query("SELECT stock FROM products WHERE id = " . (int)$testProduct['id'])->fetchColumn();
$f4Rejected = false;
try {
    Orders::create([[
        'product_id'   => $testProduct['id'], 'variant_id' => null,
        'qty'          => -3, 'price' => $testProduct['price'],
        'sku'          => $testProduct['sku'], 'name' => $testProduct['name'], 'variant_name' => '',
    ]], $customer, 'stripe');
} catch (\RuntimeException $e) {
    $f4Rejected = true;
}
$f4After = (int)$pdo->query("SELECT stock FROM products WHERE id = " . (int)$testProduct['id'])->fetchColumn();
assert_true($f4Rejected, 'F4: order with only a negative-qty item is rejected (RuntimeException)');
assert_equals($f4Before, $f4After, 'F4: negative qty does NOT inflate product stock');

// --- F8: apiCookieConsent must CSRF-validate, and the banner must send the token.
$f8Api = entrypointSource('api/v1/index.php');
assert_true(
    (bool)preg_match('/function apiCookieConsent\(\).*?Security::csrfValidate\(\)/s', $f8Api),
    'F8: apiCookieConsent() calls Security::csrfValidate()'
);
assert_true(
    str_contains($storefrontJsSrc, "form.append('csrf_token'"),
    'F8: cookie-consent banner JS sends the csrf_token field'
);

// --- F9: Security::csvCell neutralizes spreadsheet formula injection.
assert_equals("'=1+1",       Security::csvCell('=1+1'),       'F9: leading = formula neutralized');
assert_equals("'+cmd",       Security::csvCell('+cmd'),       'F9: leading + non-numeric neutralized');
assert_equals("'@SUM(A1)",   Security::csvCell('@SUM(A1)'),   'F9: leading @ neutralized');
assert_equals("'-cmd|calc",  Security::csvCell('-cmd|calc'),  'F9: leading-dash command neutralized');
assert_equals('-500',        Security::csvCell('-500'),       'F9: legitimate negative number left intact');
assert_equals('1990',        Security::csvCell(1990),         'F9: numeric cell unchanged');
assert_equals('Chef Knife',  Security::csvCell('Chef Knife'), 'F9: plain text unchanged');

// ============================================================
// TEST: Audit v1.12 remediation — Sprint B (F2, F5, F6, F7)
// ============================================================
echo "\n--- Audit v1.12 remediation (Sprint B) ---\n";

// --- F2: coupon order VAT/net snapshot scaled to the POST-coupon gross.
//     Seed a self-contained product+stock so we control the numbers exactly.
$f2Cat = (int)$pdo->query("SELECT id FROM categories ORDER BY id LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO products (sku, slug, product_type, name, category_id, price, stock, status, summary, filter_tags) VALUES ('F2-VAT-SKU','f2-vat-sku','simple','F2 VAT Test',:cat,1200,50,'active','','')")
    ->execute([':cat' => $f2Cat]);
$f2Pid = (int)$pdo->lastInsertId();
$f2Wh  = (int)$pdo->query("SELECT id FROM warehouses WHERE is_default = 1")->fetchColumn();
$pdo->prepare("INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (:wh,'F2-VAT-SKU',50,0)")
    ->execute([':wh' => $f2Wh]);

$f2Cart = [[
    'product_id' => $f2Pid, 'variant_id' => null, 'qty' => 1, 'price' => 1200,
    'sku' => 'F2-VAT-SKU', 'name' => 'F2 VAT Test', 'variant_name' => '',
    'vat_amount' => 200, 'net_amount' => 1000, 'vat_rate' => 20.0, 'vat_class' => 'standard',
]];
$f2Customer = [
    'name' => 'F2 Tester', 'email' => 'f2@test.com', 'phone' => '',
    'address' => ['street' => 'x', 'city' => 'y', 'zip' => '00000', 'country' => 'SK'],
    'currency' => 'eur', 'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 300,
];
// gross subtotal 1200, coupon 300 → discounted gross 900;
// scaled VAT = round(200 * 900/1200) = 150; net = 900 - 150 = 750.
$f2OrderId = Orders::create($f2Cart, $f2Customer, 'cod');
$f2Order   = Orders::byId($f2OrderId);
assert_equals(150, (int)$f2Order['vat_total'], 'F2: VAT_total scaled to post-coupon gross (200→150)');
assert_equals(750, (int)$f2Order['net_total'], 'F2: net_total = discounted gross − scaled VAT');
assert_equals(900, (int)$f2Order['vat_total'] + (int)$f2Order['net_total'], 'F2: invariant net+vat == discounted gross');

// --- F5: syncAll wraps each feed's sync() in try/catch so one bad feed does
//     not abort the whole run (structural guard — network-free).
$f5Src = file_get_contents(TEST_CLI_DIR . '/../src/FeedImport.php') ?: '';
assert_true(
    (bool)preg_match('/foreach\s*\(\$feeds as \$feed\).*?try\s*\{.*?self::sync(?:InStore)?\(.*?\}\s*catch\s*\(\\\\Throwable/s', $f5Src),
    'F5: syncAll isolates per-feed sync() failures in try/catch'
);

// --- F6: isDue uses a real cron evaluator (cronField/cronMatches), not the
//     old "*\/N"-only heuristic that ran fixed-time schedules hourly.
$cf = new ReflectionMethod(FeedImport::class, 'cronField');
assert_true($cf->invoke(null, '*', 5),       'F6: cronField "*" matches any value');
assert_true($cf->invoke(null, '*/15', 30),   'F6: cronField "*/15" matches 30');
assert_true(!$cf->invoke(null, '*/15', 7),   'F6: cronField "*/15" rejects 7');
assert_true($cf->invoke(null, '1-5', 3),     'F6: cronField range "1-5" matches 3');
assert_true(!$cf->invoke(null, '1-5', 6),    'F6: cronField range "1-5" rejects 6');
assert_true($cf->invoke(null, '0,15,30,45', 30), 'F6: cronField list matches 30');
assert_true($cf->invoke(null, '8-18/2', 10), 'F6: cronField step-range "8-18/2" matches 10');
assert_true(!$cf->invoke(null, '8-18/2', 9), 'F6: cronField step-range "8-18/2" rejects 9');

$cm = new ReflectionMethod(FeedImport::class, 'cronMatches');
$f6Midnight = mktime(0, 0, 0, 6, 2, 2026); // 2026-06-02 00:00
$f6Noon     = mktime(12, 0, 0, 6, 2, 2026);
assert_true($cm->invoke(null, ['0', '0', '*', '*', '*'], $f6Midnight), 'F6: cronMatches "0 0 * * *" at midnight');
assert_true(!$cm->invoke(null, ['0', '0', '*', '*', '*'], $f6Noon),    'F6: cronMatches "0 0 * * *" rejects noon');

$isDue = new ReflectionMethod(FeedImport::class, 'isDue');
assert_true($isDue->invoke(null, '*/15 * * * *', date('Y-m-d H:i:s', time() - 3600)), 'F6: "*/15 * * * *" due after 1h');
assert_true(!$isDue->invoke(null, '0 0 * * *', date('Y-m-d H:i:s')), 'F6: daily schedule not due when last_sync is now');

// --- F7: JobQueue::markProcessing is an atomic claim — a second claim of the
//     same file (already moved by another worker) returns '' instead of a path.
$f7JobId = JobQueue::push('f7_claim_test', ['x' => 1], 1);
$f7Pulled = null;
foreach (JobQueue::pull(200) as $jb) {
    if (($jb['type'] ?? '') === 'f7_claim_test') { $f7Pulled = $jb; break; }
}
assert_true($f7Pulled !== null, 'F7: test job pulled from queue');
$f7First = JobQueue::markProcessing($f7Pulled);
assert_true($f7First !== '' && file_exists($f7First), 'F7: first markProcessing claims the job (non-empty path)');
$f7Second = JobQueue::markProcessing($f7Pulled);
assert_equals('', $f7Second, 'F7: second markProcessing of an already-claimed job returns "" (no double-run)');
JobQueue::complete($f7First);
