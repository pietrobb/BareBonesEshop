<?php

// Round 7 "money and checkout": under export and reverse charge the order is charged without the seller's domestic
// VAT, but the checkout summary, the cart page and the shipping/payment API priced the gross catalog amounts, and
// judged free-shipping thresholds on them. The buyer saw "free shipping" and one total, the order charged another.
// Also: Enter in a checkout field submitted the first button, "use saved address", replacing the typed address.
echo "\n--- R7 money: previews priced in the order's VAT scenario (export, reverse charge) ---\n";
ConsentService::setDefinitionsForTesting([]);
I18n::setLocale('sk');
$r7vPricing = (new ReflectionProperty(Pricing::class, 'config'))->getValue();
$r7vActivePromotions = $pdo->query("SELECT id FROM promotions WHERE active = 1")->fetchAll(PDO::FETCH_COLUMN);
$pdo->exec("UPDATE promotions SET active = 0");
Pricing::setConfig(['cart_rules' => []]);
// 123 € goods (domestic VAT included), a parcel method free from 110 € that costs 6,15 €, and a fixed 1,23 € fee.
// Exported to Norway the goods are charged net (100 € at 23 %), below the threshold, so shipping is charged too.
$pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (97304, 'default', 1, 'R7V goods', 'r7v-goods', 'R7V-GOODS', 'simple', 12300, 'eur', 'active', 0, 'backorder')");
$pdo->exec("INSERT INTO shipping_zones (store_id, code, name) VALUES ('default', 'r7vworld', 'R7V world')");
$pdo->exec("INSERT INTO shipping_zone_countries (store_id, zone_code, country) VALUES ('default', 'r7vworld', 'NO')");
$pdo->exec("INSERT INTO shipping_methods (code, carrier, name, service_type, supports_cod, supports_pickup, free_above, sort_order) VALUES ('r7vship', 'gls', 'R7V parcel', 'parcel', 0, 0, 11000, 1)");
$pdo->exec("INSERT INTO shipping_rates (carrier, country, zone_code, method_code, weight_from_g, weight_to_g, price, cod_surcharge, sort_order)
    VALUES ('gls', '', 'r7vworld', 'r7vship', 0, 999999, 615, 0, 1), ('gls', '', 'near_eu', 'r7vship', 0, 999999, 615, 0, 1)");
$pdo->exec("INSERT INTO payment_methods (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order)
    VALUES ('r7vfix', 'manual', 'R7V fixed', '', 'offline', 0, 0, 123, 0, NULL, 0, 1, 1)");
$pdo->exec("INSERT INTO payment_method_countries (method_code, country) VALUES ('r7vfix', 'NO'), ('r7vfix', 'DE')");
$pdo->exec("DELETE FROM vat_id_cache WHERE vat_id = 'DE136695976'");
$pdo->exec("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, trader_addr, checked_at, ttl_seconds, raw_response)
    VALUES ('DE136695976', 'DE', 'valid', NULL, NULL, datetime('now'), 86400, NULL)");
$r7vGet = static function (callable $handler, array $query): array {
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    Cart::clear();
    Cart::add(97304, null, 1);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = $query;
    $result = $handler();
    $_GET = [];
    Cart::clear();
    return $result;
};
try {
    // 1. The order exported to Norway: net goods, below the threshold, net shipping and net fixed fee.
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    Cart::clear();
    Cart::add(97304, null, 1);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.9.74';
    $_SESSION['csrf_token'] = 'r7v-checkout';
    $_POST = ['csrf_token' => 'r7v-checkout', 'name' => 'R7V Buyer', 'email' => 'r7v-buyer@example.com', 'phone' => '', 'country' => 'NO',
        'street' => 'Karl Johans gate 1', 'city' => 'Oslo', 'zip' => '0154', 'shipping_method' => 'r7vship', 'payment_method' => 'r7vfix'];
    $r7vResult = apiCheckout();
    $_POST = [];
    Cart::clear();
    $r7vOrder = Orders::byId((int)($r7vResult['order_id'] ?? 0)) ?: [];
    $r7vMoney = [];
    foreach ($r7vOrder ? ['subtotal', 'shipping_cost', 'payment_fee', 'total'] : [] as $column) $r7vMoney[$column] = (int)$r7vOrder[$column];
    $r7vNet = [Cart::netOfDomesticVat(12300, 'SK'), Cart::netOfDomesticVat(615, 'SK'), Cart::netOfDomesticVat(123, 'SK')];
    assert_true(($r7vOrder['vat_scenario'] ?? '') === 'export' && $r7vNet[0] < 11000,
        'R7 VAT preview setup: the Norway order is an export and its net goods stay below the 110 € threshold (' . ($r7vResult['error'] ?? json_encode($r7vMoney)) . ')');
    assert_equals(['subtotal' => $r7vNet[0], 'shipping_cost' => $r7vNet[1], 'payment_fee' => $r7vNet[2], 'total' => array_sum($r7vNet)], $r7vMoney,
        'R7 VAT preview setup: the export order charges goods, shipping and the fixed fee without domestic VAT');

    // 2. The shipping and payment API price the same scenario: net goods, shipping charged (not free), net fee.
    $r7vRates = $r7vGet('apiShippingRates', ['country' => 'NO']);
    assert_equals(['export', $r7vMoney['subtotal'] ?? null, $r7vMoney['shipping_cost'] ?? null],
        [$r7vRates['vat_scenario'] ?? null, $r7vRates['goods_subtotal'] ?? null, array_column($r7vRates['methods'] ?? [], 'price', 'method_code')['r7vship'] ?? null],
        'R7 VAT preview API: shipping rates show the export goods and the shipping the order charges, not free shipping on the gross amount');
    $r7vPayments = $r7vGet('apiPaymentMethods', ['country' => 'NO', 'currency' => 'eur', 'shipping_method' => 'r7vship',
        'amount' => (string)(($r7vMoney['subtotal'] ?? 0) + ($r7vMoney['shipping_cost'] ?? 0))]);
    assert_equals($r7vMoney['payment_fee'] ?? null, array_column($r7vPayments['methods'] ?? [], 'fee', 'code')['r7vfix'] ?? null,
        'R7 VAT preview API: payment methods show the fee the export order charges');
    // Reverse charge needs the buyer's VAT ID; the API takes it as ?vat_id= (VIES result cached as valid here).
    $r7vRc = $r7vGet('apiShippingRates', ['country' => 'DE', 'vat_id' => 'de 136.695.976']);
    $r7vB2c = $r7vGet('apiShippingRates', ['country' => 'DE']);
    assert_equals(['reverse_charge', $r7vNet[0], $r7vNet[1]],
        [$r7vRc['vat_scenario'] ?? null, $r7vRc['goods_subtotal'] ?? null, array_column($r7vRc['methods'] ?? [], 'price', 'method_code')['r7vship'] ?? null],
        'R7 VAT preview API: a valid VAT ID of another member state previews reverse charge with net goods and shipping');
    assert_true(($r7vB2c['vat_scenario'] ?? '') !== 'reverse_charge' && ($r7vB2c['goods_subtotal'] ?? null) === 12300,
        'R7 VAT preview API: without a VAT ID the same cart keeps the VAT-inclusive price');

    // 3. The HTML checkout summary (real renderCheckout, child process on this database) after the country refresh.
    $r7vRoot = test_fixture_path('r7-vat-preview');
    mkdir($r7vRoot);
    file_put_contents($r7vRoot . '/preview.php', <<<'PHP'
<?php
[, $root, $dbPath] = $argv;
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
Pricing::setConfig(['cart_rules' => []]);
ConsentService::setDefinitionsForTesting([]);
I18n::setLocale('sk');
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
require $root . '/src/Entrypoints/Storefront/CheckoutPages.php';
$t = static fn(string $key): string => $key;
Security::sessionStart(); // as the storefront bootstrap does before the cart is read
$_SESSION = ['cart' => ['p_97304' => ['product_id' => 97304, 'variant_id' => null, 'qty' => 1]], 'csrf_token' => 'r7v-preview'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.9.75';
$_SERVER['HTTP_HOST'] = 'localhost';
$_POST = ['checkout_refresh' => '1', 'csrf_token' => 'r7v-preview', 'name' => 'R7V Preview', 'email' => 'r7v-preview@example.com',
    'street' => 'Karl Johans gate 1', 'city' => 'Oslo', 'zip' => '0154', 'country' => 'NO', 'shipping_method' => 'r7vship', 'payment' => 'r7vfix'];
$html = renderCheckout();
$summary = ['subtotal' => preg_match('~id="checkout-subtotal" value="(\d+)" data-vat-scenario="([a-z_]*)"~', $html, $m) ? [(int)$m[1], $m[2]] : null];
// Enter in a text field submits the form's first submit button: it must not be "use saved address".
$summary['enter'] = preg_match('~<form[^>]*data-checkout-form[^>]*>.*?<button[^>]*name="([a-z_]+)" value="([a-z0-9]+)"~s', $html, $m) ? $m[1] . '=' . $m[2] : null;
foreach (['shipping', 'payment', 'total'] as $id) {
    $summary[$id] = preg_match('~id="summary-' . $id . '">([^<]*)<~', $html, $m) ? $m[1] : null;
}
echo 'R7VRESULT' . json_encode($summary);
PHP);
    $r7vProcess = proc_open([PHP_BINARY, $r7vRoot . '/preview.php', dirname(TEST_CLI_DIR), $testDbPath], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r7vPipes);
    fclose($r7vPipes[0]);
    $r7vOut = stream_get_contents($r7vPipes[1]) . stream_get_contents($r7vPipes[2]);
    fclose($r7vPipes[1]);
    fclose($r7vPipes[2]);
    proc_close($r7vProcess);
    @unlink($r7vRoot . '/preview.php');
    @rmdir($r7vRoot);
    $r7vAt = strrpos($r7vOut, 'R7VRESULT');
    $r7vSummary = $r7vAt === false ? null : json_decode(substr($r7vOut, $r7vAt + 9), true);
    $r7vEnter = is_array($r7vSummary) ? $r7vSummary['enter'] ?? null : null;
    if (is_array($r7vSummary)) unset($r7vSummary['enter']);
    assert_equals(['subtotal' => [$r7vMoney['subtotal'] ?? null, 'export'], 'shipping' => I18n::price($r7vMoney['shipping_cost'] ?? 0, 'eur'),
        'payment' => I18n::price($r7vMoney['payment_fee'] ?? 0, 'eur'), 'total' => I18n::price($r7vMoney['total'] ?? 0, 'eur')], $r7vSummary,
        'R7 VAT preview: the checkout summary for an export shows the goods, shipping, fee and total the order charges ('
        . substr(trim(strip_tags($r7vSummary === null ? $r7vOut : '')), -300) . ')');
    assert_equals('checkout_refresh=1', $r7vEnter,
        'R7 checkout Enter: the form\'s default (first) submit button re-prices the typed address instead of loading a saved one');
    // A shipping method picked while the rates load is kept: the choice is read when they arrive, not when requested.
    $r7vJs = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/themes/default/assets/storefront.js');
    $r7vRefresh = substr($r7vJs, (int)strpos($r7vJs, 'async function refreshCheckoutShipping'));
    $r7vRefresh = substr($r7vRefresh, 0, (int)strpos($r7vRefresh, "\n  }\n") ?: (int)strpos($r7vRefresh, "\r\n  }\r\n"));
    $r7vFetchAt = strpos($r7vRefresh, "fetchCheckoutJson('shipping_rates'");
    $r7vCheckedAt = strpos($r7vRefresh, 'input[name="shipping_method"]:checked');
    assert_true($r7vFetchAt !== false && $r7vCheckedAt !== false && $r7vCheckedAt > $r7vFetchAt && !str_contains(substr($r7vRefresh, 0, $r7vFetchAt), ':checked'),
        'R7 checkout shipping race: the selected rate is read after the rates arrive, so a choice made while loading is not reverted');
    // A changed country or VAT ID that switches the VAT scenario re-renders the whole order on the server.
    assert_true(str_contains($r7vRefresh, 'repriceCheckout(result.vat_scenario)') && str_contains($r7vJs, "vat_id: checkoutVatId()"),
        'R7 VAT preview JS: the rate/fee requests carry the VAT ID and a scenario change re-prices the checkout');

    // 4. Previews price what checkout orders: a sold-out parcel line left in the cart is not ordered, so an e-book
    // with it is a digital-only order (no shipping), as execute() treats it.
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (97305, 'default', 1, 'R7V e-book', 'r7v-ebook', 'R7V-EBOOK', 'digital', 500, 'eur', 'active', 0, 'deny'),
               (97306, 'default', 1, 'R7V sold out', 'r7v-soldout', 'R7V-SOLDOUT', 'simple', 900, 'eur', 'active', 0, 'deny')");
    $r7vMixed = static function (callable $handler, array $query): array {
        Security::setRateDbForTesting(new PDO('sqlite::memory:'));
        $_SESSION['cart'] = ['p_97305' => ['product_id' => 97305, 'variant_id' => null, 'qty' => 1],
            'p_97306' => ['product_id' => 97306, 'variant_id' => null, 'qty' => 2]];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = $query;
        $result = $handler();
        $_GET = [];
        Cart::clear();
        return $result;
    };
    $r7vMixedLines = (static function (): array { $_SESSION['cart'] = ['p_97305' => ['product_id' => 97305, 'variant_id' => null, 'qty' => 1],
        'p_97306' => ['product_id' => 97306, 'variant_id' => null, 'qty' => 2]]; $c = Cart::enriched(); Cart::clear(); return array_column($c['items'], 'available', 'product_id'); })();
    assert_equals([97305 => true, 97306 => false], $r7vMixedLines, 'R7 preview setup: the e-book is available, the sold-out parcel line is not');
    $r7vMixedRates = $r7vMixed('apiShippingRates', ['country' => 'SK']);
    assert_equals(['digital', ['digital']], [$r7vMixedRates['delivery_type'] ?? null, array_column($r7vMixedRates['methods'] ?? [], 'method_code')],
        'R7 preview API: a sold-out parcel line does not make the e-book order physical (shipping rates offered, never charged)');
} finally {
    $_POST = [];
    $_GET = [];
    Pricing::setConfig($r7vPricing);
    if ($r7vActivePromotions) $pdo->exec('UPDATE promotions SET active = 1 WHERE id IN (' . implode(',', array_map('intval', $r7vActivePromotions)) . ')');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}
