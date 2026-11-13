<?php

// Review round 35 (low, goal #449): the 10 000 EUR OSS threshold was only a doctor warning, never applied at checkout,
// and its count left out sales in another currency and cross-border e-books on invoices of scenario "domestic" (goods
// supplied at home, the download billed to a consumer abroad).
echo "\n--- Audit round 35: OSS threshold — other currencies, e-books billed abroad, applied at checkout ---\n";

Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
$r35oStores = Bootstrap::stores();
$r35oStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r35oOff = $r35oStores;
$r35oOff['stores']['default']['oss_enabled'] = false;
$r35oOff['stores']['default']['legal']['country'] = 'SK';
$r35oStoresProp->setValue(null, $r35oOff);
Bootstrap::initStoreContext('/', '', 'default');
$r35oCart = $_SESSION['cart'] ?? null;
$r35oOrders = [];
try {
    // Nothing of other tests counts this year or the last.
    $year = (int)ShopTime::date('Y');
    $r35oStashed = $pdo->query("SELECT id, issued_at FROM invoices WHERE issued_at >= '" . sprintf('%04d-01-01', $year - 1) . "'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $pdo->exec("UPDATE invoices SET issued_at = '2001-01-01 00:00:00' WHERE issued_at >= '" . sprintf('%04d-01-01', $year - 1) . "'");
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (99381, 'default', 1, 'R35O lamp', 'r35o-lamp', 'R35O-LAMP', 'simple', 12300, 'eur', 'active', 10, 'continue')");
    $r35oInvoice = static function (string $number, int $net, string $scenario, string $currency, array $billing = [], int $digitalNet = 0) use ($pdo, $year, &$r35oOrders): void {
        $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, billing_addr, subtotal, shipping_cost, tax_amount, discount_amount,
                total, currency, status, payment_status, payment_method, shipping_method, store_id, created_at)
            VALUES (?, 'R35O', 'r35o@example.test', '', '{\"country\":\"SK\"}', ?, ?, 0, 0, 0, ?, ?, 'delivered', 'paid', 'bank_transfer', 'pickup', 'default', " . Db::now() . ")")
            ->execute([$number, $billing !== [] ? json_encode($billing) : null, $net, $net, $currency]);
        $orderId = $r35oOrders[] = (int)$pdo->lastInsertId();
        if ($digitalNet > 0) {
            $pdo->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, net_amount, digital) VALUES (?, 99381, 'R35O-EBOOK', 'R35O e-book', 1, ?, ?, ?, 1)")
                ->execute([$orderId, $digitalNet, $digitalNet, $digitalNet]);
        }
        $pdo->prepare("INSERT INTO invoices (order_id, number, issued_at, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, vat_scenario)
            VALUES (?, ?, ?, '', ?, 0, ?, ?, 'issued', ?, ?)")
            ->execute([$orderId, $number, sprintf('%04d-03-15 10:00:00', $year), $net, $net, $currency, 'r35o-' . $number, $scenario]);
    };
    $r35oStore = $r35oOff['stores']['default'];
    $r35oCount = static fn(): array => OssThreshold::worstYear($pdo, 'default', $r35oStore, ['default' => $r35oStore]);
    $r35oCheck = static fn(): array => Setup::ossThresholdCheck($pdo, 'default', $r35oStore, ['default' => $r35oStore]);

    $r35oInvoice('R35O-1', 600000, 'domestic_subthreshold', 'eur');               // 6 000 EUR
    $r35oInvoice('R35O-2', 5000000, 'domestic_subthreshold', 'czk');              // 50 000 CZK ≈ 2 000 EUR
    $r35oInvoice('R35O-3', 900000, 'domestic', 'eur');                            // a domestic sale does not count
    $r35oInvoice('R35O-4', 400000, 'domestic', 'eur', ['name' => 'R35O GmbH', 'country' => 'AT', 'company' => 'R35O GmbH', 'ic_dph' => 'ATU12345678'], 300000); // a business: no
    $czk = (float)VatService::config()['eur_exchange_rates']['CZK'];
    assert_equals(600000 + (int)round(5000000 * $czk), $r35oCount()['sum'],
        'R35 OSS: a CZK distance sale counts converted to EUR (was left out), a domestic sale and a business\'s e-book do not');
    VatService::reset();
    assert_true(!OssThreshold::exceeded(), 'R35 OSS: under 10 000 EUR the threshold is not exceeded');

    // An e-book billed to a consumer in Austria next to goods collected in the shop (invoice scenario "domestic").
    $r35oInvoice('R35O-5', 400000, 'domestic', 'eur', ['name' => 'R35O Konsument', 'country' => 'AT'], 250000);
    $r35oSum = $r35oCount()['sum'];
    assert_equals(600000 + (int)round(5000000 * $czk) + 250000, $r35oSum, 'R35 OSS: the e-book of a consumer billed to Austria on a "domestic" invoice counts (was left out)');
    $r35oWarn = $r35oCheck();
    assert_true($r35oWarn['status'] === 'warn' && str_contains($r35oWarn['message'], 'checkout now charges it') && str_contains($r35oWarn['action'], 'Admin → Stores'),
        'R35 OSS: above 10 000 EUR the doctor warns that checkout charges the buyer\'s VAT and to register for OSS');

    // Above the threshold the checkout charges an Austrian consumer Austrian VAT although OSS is off.
    $_SESSION['cart'] = ['p_99381' => ['product_id' => 99381, 'variant_id' => null, 'qty' => 1]];
    VatService::reset();
    $r35oVat = CheckoutService::vatContext('AT', '', 'gls', false);
    assert_equals([VatService::SCENARIO_OSS, 'AT'], [(string)$r35oVat['scenario']['scenario'], (string)$r35oVat['scenario']['rate_country']],
        'R35 OSS: past the threshold a consumer in Austria pays Austrian VAT at checkout (was: Slovak VAT, only a warning)');
    assert_equals([VatService::SCENARIO_DOMESTIC, 'SK'], array_values(array_intersect_key(CheckoutService::vatContext('SK', '', 'gls', false)['scenario'], ['scenario' => 1, 'rate_country' => 1])),
        'R35 OSS: a Slovak consumer still pays Slovak VAT');

    // A currency without a conversion rate is reported, not guessed.
    $pdo->prepare("DELETE FROM invoices WHERE number IN ('R35O-1', 'R35O-5')")->execute();
    $r35oInvoice('R35O-6', 100000, 'domestic_subthreshold', 'nok');
    $r35oNok = $r35oCheck();
    assert_true($r35oNok['status'] === 'warn' && str_contains($r35oNok['message'], 'NOK') && str_contains($r35oNok['action'], 'eur_exchange_rates'),
        'R35 OSS: sales in a currency without an EUR rate warn instead of counting as nothing');
    VatService::reset();
    assert_true(!OssThreshold::exceeded(), 'R35 OSS: back under the threshold the checkout follows the OSS setting again');

    $pdo->prepare("DELETE FROM invoices WHERE number LIKE 'R35O-%'")->execute();
    $restore = $pdo->prepare('UPDATE invoices SET issued_at = ? WHERE id = ?');
    foreach ($r35oStashed as $id => $issuedAt) $restore->execute([$issuedAt, $id]);
} catch (Throwable $e) {
    assert_true(false, 'R35 OSS (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
$r35oStoresProp->setValue(null, $r35oStores);
Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
if ($r35oCart === null) unset($_SESSION['cart']); else $_SESSION['cart'] = $r35oCart;
