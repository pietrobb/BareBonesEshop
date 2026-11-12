<?php

// Review round 33 (medium 2 + low): an e-service is supplied where the consumer lives or the business is established.
// Since R31 the buyer gives that as the billing address, yet a download was taxed by the delivery country picked at
// checkout: an e-book for an Austrian consumer billed to Vienna paid 23 % Slovak VAT instead of 20 % Austrian, and a
// German business with a valid VAT ID paid Slovak VAT instead of reverse charge. Also: a Slovak VAT ID on a parcel to
// Czechia is no consumer's distance sale (no OSS), and editing the lines of a reverse-charge order billed to another
// address was refused because the VAT ID lives on the billing address.
echo "\n--- Audit round 33: digital place of supply by the billing country ---\n";

Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
$r33vStores = Bootstrap::stores();
$r33vOss = $r33vStores;
$r33vOss['stores']['default']['oss_enabled'] = true;
$r33vOss['stores']['default']['legal']['country'] = 'SK';
$r33vStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r33vStoresProp->setValue(null, $r33vOss);
Bootstrap::initStoreContext('/', '', 'default');
$r33vCart = $_SESSION['cart'] ?? null;
$r33vGet = $_GET;
$r33vFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R33 digital VAT {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
try {
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (99331, 'default', 1, 'R33V e-book', 'r33v-ebook', 'R33V-EBOOK', 'downloadable', 1230, 'eur', 'active', 0, 'continue')");
    $pdo->exec("INSERT INTO digital_assets (sku, asset_key, label, storage_path, download_name, content_type, download_limit, expiry_seconds, active)
        VALUES ('R33V-EBOOK', 'pdf', 'PDF', 'r33v.pdf', 'r33v.pdf', 'application/pdf', 2, 3600, 1)");
    foreach (['DE123456789' => 'DE', 'SK2020202020' => 'SK', 'CZ87654321' => 'CZ'] as $r33vId => $r33vCountry) {
        $pdo->prepare("DELETE FROM vat_id_cache WHERE vat_id = :id")->execute([':id' => $r33vId]);
        $pdo->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES (:id, :c, 'valid', 'R33V GmbH', :at, 86400)")
            ->execute([':id' => $r33vId, ':c' => $r33vCountry, ':at' => gmdate('Y-m-d H:i:s')]);
    }
    $_SESSION['cart'] = ['p_99331' => ['product_id' => 99331, 'variant_id' => null, 'qty' => 1]];
    $r33vScenario = static fn(array $vat): array => [(string)$vat['scenario']['scenario'], (string)$vat['scenario']['rate_country']];

    // A consumer: the download is taxed where the billing address is (OSS, Austrian rate), not where the form's
    // delivery country points; without a billing address the delivery country still stands for the residence.
    assert_equals(['oss', 'AT'], $r33vScenario(CheckoutService::vatContext('SK', '', '', false, null, 'AT')),
        'R33 digital VAT: an e-book billed to Austria is an OSS supply at the Austrian rate (was domestic SK 23 %)');
    assert_equals(['domestic', 'SK'], $r33vScenario(CheckoutService::vatContext('SK', '', '', false)),
        'R33 digital VAT: without a billing address the delivery country is the residence, as before');
    // A business with a valid VAT ID of another member state: reverse charge, even when the form says Slovakia.
    assert_equals(['reverse_charge', 'DE'], $r33vScenario(CheckoutService::vatContext('SK', 'DE123456789', '', true, null, 'DE')),
        'R33 digital VAT: an e-book for a German business with a valid VAT ID is reverse charge (was Slovak VAT)');
    // A business registered for VAT in Slovakia is established at home: Slovak VAT, never an OSS sale abroad.
    assert_equals(['domestic', 'SK'], $r33vScenario(CheckoutService::vatContext('SK', 'SK2020202020', '', true, null, 'AT')),
        'R33 digital VAT: a Slovak VAT ID billed to Austria pays Slovak VAT');

    // The JSON previews price with the billing country the form sends; one that checkout does not offer is ignored.
    if (!function_exists('apiShippingRates')) require_once dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['country' => 'SK', 'subtotal' => '1230', 'billing_country' => 'AT'];
    $r33vRates = apiShippingRates();
    $_GET = ['country' => 'SK', 'subtotal' => '1230', 'billing_country' => 'X1'];
    $r33vBogus = apiShippingRates();
    $_GET = ['country' => 'SK', 'currency' => 'eur', 'amount' => '1230', 'shipping_method' => 'digital', 'billing_country' => 'AT'];
    $r33vPayments = apiPaymentMethods();
    assert_equals(['oss', 'domestic'], [(string)($r33vRates['vat_scenario'] ?? ''), (string)($r33vBogus['vat_scenario'] ?? '')],
        'R33 digital VAT: the shipping preview prices by the billing country; an unknown one falls back to the delivery country');
    assert_true(!isset($r33vPayments['error']), 'R33 digital VAT: the payment preview accepts the billing country');

    // The checkout form re-prices when the billing country changes while the cart holds a download.
    $r33vJs = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/themes/default/assets/storefront.js');
    $r33vHtml = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/themes/default/blocks/storefront/checkout.html');
    assert_true(str_contains($r33vJs, 'billing_country: checkoutBillingCountry()') && str_contains($r33vJs, 'dataset.billingReprices')
        && str_contains($r33vHtml, 'data-billing-reprices'), 'R33 digital VAT: the checkout previews send the billing country and re-price on its change');
} catch (Throwable $e) { $r33vFail('checkout context', $e); }

// Goods: a Slovak VAT ID on a parcel to Czechia is a business, so no OSS distance sale; the Slovak rate applies.
assert_equals(['domestic', 'SK'], array_values(array_intersect_key(VatService::resolveScenario(['seller_country' => 'SK', 'customer_country' => 'CZ',
    'seller_oss_enabled' => true, 'is_b2b' => true, 'customer_vat_id_valid' => false, 'customer_vat_id_domestic' => true]), ['scenario' => 1, 'rate_country' => 1])),
    'R33 VAT: goods to CZ for a business with a Slovak VAT ID are taxed in Slovakia, not through OSS');
assert_equals('oss', VatService::resolveScenario(['seller_country' => 'SK', 'customer_country' => 'CZ', 'seller_oss_enabled' => true, 'is_b2b' => false])['scenario'],
    'R33 VAT: a Czech consumer still buys through OSS');

// Admin edit of a reverse-charge order billed to another address: the VAT ID is on the billing address.
try {
    $r33vWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (99332, 'default', 1, 'R33V book', 'r33v-book', 'R33V-BOOK', 'simple', 1000, 'eur', 'active', 10, 'deny')");
    $pdo->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', :wh, 'R33V-BOOK', 10, 0)")->execute([':wh' => $r33vWarehouse]);
    $_SESSION['cart'] = [];
    $r33vBuyer = ['name' => 'R33V Příjemce', 'email' => 'r33v-b2b@example.com', 'country' => 'CZ', 'street' => 'Dodací 1', 'city' => 'Praha', 'zip' => '11000',
        'company' => 'R33V s.r.o.', 'vat_id' => 'CZ87654321', 'shipping_method' => 'packeta', 'payment_method' => 'bank_transfer', 'locale' => 'cs',
        'billing_different' => '1', 'billing_name' => 'R33V Účtárna', 'billing_street' => 'Fakturační 2', 'billing_city' => 'Brno', 'billing_zip' => '60200', 'billing_country' => 'CZ'];
    $r33vCreated = ManualOrder::create(ManualOrder::parseLines('R33V-BOOK 1')['lines'], $r33vBuyer, false);
    $r33vId = (int)($r33vCreated['order_id'] ?? 0);
    $r33vOrder = Orders::byId($r33vId) ?? [];
    assert_equals([true, VatService::SCENARIO_REVERSE_CHARGE, 'CZ87654321', ''], [$r33vCreated['ok'], (string)($r33vOrder['vat_scenario'] ?? ''),
        (string)(OrderAddress::billing($r33vOrder)['ic_dph'] ?? ''), (string)(OrderAddress::delivery($r33vOrder)['ic_dph'] ?? '')],
        'R33 edit setup: the business order billed elsewhere is reverse charge, its VAT ID on the billing address (' . ($r33vCreated['error'] ?? 'ok') . ' ' . ($r33vCreated['detail'] ?? '') . ')');
    $r33vEdit = ManualOrder::editItems($r33vId, ManualOrder::parseLines('R33V-BOOK 2')['lines']);
    $r33vEdited = Orders::byId($r33vId) ?? [];
    assert_equals([true, VatService::SCENARIO_REVERSE_CHARGE, 0, 2 * (int)($r33vOrder['subtotal'] ?? -1)],
        [$r33vEdit['ok'], (string)($r33vEdited['vat_scenario'] ?? ''), (int)($r33vEdited['vat_total'] ?? -1), (int)($r33vEdited['subtotal'] ?? -1)],
        'R33 edit: the lines of a reverse-charge order billed to another address change and stay reverse charge (was refused: ' . ($r33vEdit['error'] ?? 'ok') . ')');
    if ($r33vId > 0) OrderWorkflow::transition($r33vId, 'cancelled', 'R33 digital VAT fixture');
} catch (Throwable $e) { $r33vFail('admin edit', $e); }

// Capability review (run 432): an e-book next to a printed book took the goods' treatment whenever the billing country
// worked out domestic, and was skipped when the goods carried no VAT. A parcel to Austria billed to a Slovak consumer
// charged the e-book Austrian VAT and the OSS report listed it under AT; a parcel to Switzerland billed to Vienna
// zero-rated an e-book supplied in Austria.
if (!function_exists('collect')) {
    if (!defined('BB_TEST_LOAD_OSS_ONLY')) define('BB_TEST_LOAD_OSS_ONLY', true);
    require_once TEST_CLI_DIR . '/oss-report.php';
}
try {
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (99333, 'default', 1, 'R33V print', 'r33v-print', 'R33V-PRINT', 'simple', 1230, 'eur', 'active', 10, 'continue')");
    $_SESSION['cart'] = ['p_99331' => ['product_id' => 99331, 'variant_id' => null, 'qty' => 1], 'p_99333' => ['product_id' => 99333, 'variant_id' => null, 'qty' => 1]];
    $r33vMixed = static function (string $delivery, string $billing): array {
        $vat = CheckoutService::vatContext($delivery, '', '', false, null, $billing);
        $items = array_values(array_filter(Cart::enriched($vat['context'])['items'], static fn(array $i): bool => !empty($i['available'])));
        $snapshot = VatService::buildSnapshot($items, $vat['context'], ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
        $byId = [];
        foreach ($snapshot['components'] as $c) if (($c['type'] ?? '') === 'product') $byId[(int)$c['product_id']] = $c;
        $cart = [];
        foreach ($items as $i) $cart[(int)$i['product_id']] = [(int)$i['price'], (int)round((float)$i['vat_rate'] * 100)];
        return ['snapshot' => $snapshot, 'ebook' => $byId[99331], 'print' => $byId[99333], 'cart' => $cart];
    };
    $r33vRate = static fn(string $country): int => (int)round(VatService::rate($country, 'standard') * 100);
    $r33vTag = static fn(array $c): array => [(int)$c['rate_basis_points'], $c['rate_country'] ?? null, $c['supply'] ?? null];

    // Parcel to Austria under OSS, billed to a Slovak consumer: the printed book pays Austrian VAT, the e-book Slovak.
    $r33vAtSk = $r33vMixed('AT', 'SK');
    assert_equals(['oss', [$r33vRate('AT'), null, null], [$r33vRate('SK'), 'SK', 'domestic']],
        [$r33vAtSk['snapshot']['scenario'], $r33vTag($r33vAtSk['print']), $r33vTag($r33vAtSk['ebook'])],
        'R33 mixed cart: a parcel to AT billed to an SK consumer — the e-book pays Slovak VAT, tagged domestic (was the Austrian rate, untagged)');
    assert_equals([1230, $r33vRate('SK')], $r33vAtSk['cart'][99331], 'R33 mixed cart: the cart prices the e-book at the Slovak rate too');

    // Parcel to Switzerland (export), billed to an Austrian consumer: goods zero-rated, the e-book Austrian VAT via OSS.
    $r33vChAt = $r33vMixed('CH', 'AT');
    assert_equals(['export', [0, null, null], [$r33vRate('AT'), 'AT', 'oss']],
        [$r33vChAt['snapshot']['scenario'], $r33vTag($r33vChAt['print']), $r33vTag($r33vChAt['ebook'])],
        'R33 mixed cart: an export billed to an AT consumer — the e-book is an Austrian OSS supply (was zero-rated)');
    assert_equals([1230, $r33vRate('AT')], $r33vChAt['cart'][99331], 'R33 mixed cart: the e-book next to exported goods keeps its gross price and VAT');
    assert_true($r33vChAt['cart'][99333][0] < 1230 && $r33vChAt['cart'][99333][1] === 0, 'R33 mixed cart: the exported book is still charged without VAT');

    // Billed outside the EU next to exported goods: nothing backs an EU residence, the e-book is zero-rated like them.
    $r33vChUs = $r33vMixed('CH', 'US');
    assert_equals([0, null, null], $r33vTag($r33vChUs['ebook']), 'R33 mixed cart: an export billed to the US leaves the e-book zero-rated');
    // The handled direction is unchanged: a domestic parcel billed to Austria taxes the e-book under OSS.
    assert_equals([$r33vRate('AT'), 'AT', 'oss'], $r33vTag($r33vMixed('SK', 'AT')['ebook']), 'R33 mixed cart: a parcel at home billed to AT keeps the e-book under OSS');

    // The OSS report lists the Austrian goods alone for the first order, and the Austrian e-book for the export.
    $r33vReport = static function (): array {
        $rows = [];
        $day = ShopTime::date('Y-m-d');
        foreach (collect($day, $day, null) as $row) $rows[$row['consumption_country'] . '|' . $row['vat_rate']] = [$row['net'], $row['vat']];
        return $rows;
    };
    $r33vIssue = static function (string $number, array $snapshot, string $delivery, string $billing) use ($pdo): void {
        $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, billing_addr, subtotal, shipping_cost, tax_amount,
                discount_amount, payment_fee, total, currency, status, payment_status, payment_method, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
            VALUES (?, 'R33V Mixed', 'r33v-mixed@example.test', '', ?, ?, ?, 0, 0, 0, 0, ?, 'eur', 'delivered', 'paid', 'bank_transfer', 'dpd', ?, ?, 'default', " . Db::now() . ")")
            ->execute([$number, json_encode(['street' => 'Lieferstraße 1', 'city' => 'Wien', 'zip' => '1010', 'country' => $delivery]),
                json_encode(['name' => 'R33V Plátca', 'street' => 'Fakturačná 9', 'city' => 'Bratislava', 'zip' => '81101', 'country' => $billing]),
                (int)$snapshot['totals']['subtotal'], (int)$snapshot['totals']['gross'], (string)$snapshot['scenario'],
                json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ]);
        InvoiceService::issue((int)$pdo->lastInsertId());
    };
    $r33vDelta = static function (array $before, array $after): array {
        $delta = [];
        foreach ($after + $before as $key => $_) {
            $change = [($after[$key][0] ?? 0) - ($before[$key][0] ?? 0), ($after[$key][1] ?? 0) - ($before[$key][1] ?? 0)];
            if ($change !== [0, 0]) $delta[$key] = $change;
        }
        return $delta;
    };
    $r33vAt = (string)((float)$r33vRate('AT') / 100);
    $r33vBefore = $r33vReport();
    $r33vIssue('R33V-MIX-1', $r33vAtSk['snapshot'], 'AT', 'SK');
    assert_equals(["AT|{$r33vAt}" => [(int)$r33vAtSk['print']['net'], (int)$r33vAtSk['print']['vat']]], $r33vDelta($r33vBefore, $r33vReport()),
        'R33 mixed cart: the OSS report counts only the Austrian goods; the Slovak e-book is no OSS supply (was in the AT bucket)');
    $r33vBefore = $r33vReport();
    $r33vIssue('R33V-MIX-2', $r33vChAt['snapshot'], 'CH', 'AT');
    assert_equals(["AT|{$r33vAt}" => [(int)$r33vChAt['ebook']['net'], (int)$r33vChAt['ebook']['vat']]], $r33vDelta($r33vBefore, $r33vReport()),
        'R33 mixed cart: the e-book next to an export is reported as an Austrian OSS supply');
} catch (Throwable $e) { $r33vFail('mixed cart', $e); }

$r33vStoresProp->setValue(null, $r33vStores);
Bootstrap::initStoreContext('/', '', 'default');
$_GET = $r33vGet;
if ($r33vCart === null) unset($_SESSION['cart']); else $_SESSION['cart'] = $r33vCart;
