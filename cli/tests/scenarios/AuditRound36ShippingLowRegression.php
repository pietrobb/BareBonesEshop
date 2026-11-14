<?php

// Review round 36 (2026-11-13), low (goal #458):
// 1. For a cart charged in another currency than the store's price lists the carriers' built-in prices apply (R8), but
//    only the method's maximum weight was checked, not its rates' weight bands: GLS was offered for 8 kg to Austria
//    where its rates end at 5 kg. R36: a method's rate band (weight, volume) must cover the cart in that currency too.
// 2. The checkout offered cash on delivery with a delivery method set to "no cash on delivery"; the order then refused it.
// 3. The shipping settings page explains which prices apply where and what "Standard"/"Express" are, and its tables
//    scroll inside the card instead of pushing the page sideways on a desktop.
echo "\n--- R36: weight bands in another currency, no COD with a no-COD method, shipping settings page ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r36wZone = (string)$pdo->query("SELECT c.zone_code FROM shipping_zone_countries c JOIN shipping_zones z ON z.code = c.zone_code AND z.store_id = c.store_id
    WHERE c.store_id = 'default' AND c.country = 'AT' AND z.active = 1 ORDER BY c.id LIMIT 1")->fetchColumn();
$r36wHomeZone = (string)$pdo->query("SELECT c.zone_code FROM shipping_zone_countries c JOIN shipping_zones z ON z.code = c.zone_code AND z.store_id = c.store_id
    WHERE c.store_id = 'default' AND c.country = 'SK' AND z.active = 1 ORDER BY c.id LIMIT 1")->fetchColumn();
$r36wCourier = new class implements ShippingAdapter {
    public function getRates(array $params): array { return [['carrier' => 'r36w', 'method_code' => 'r36w', 'label' => 'R36W courier', 'price' => 900, 'cod_surcharge' => 0, 'delivery_days' => '2', 'cod' => false]]; }
    public function generateLabel(array $order): array { throw new LogicException('no labels'); }
    public function trackShipment(string $awb): array { return []; }
    public function supportsCOD(): bool { return true; }
    public function carrierId(): string { return 'r36w'; }
};
$r36wCodes = static fn(string $country, int $weightG, ?string $currency): array => array_values(array_map(
    static fn(array $r): string => (string)($r['method_code'] ?? ''), ShippingService::calculateRates($country, $weightG, 1000, false, 0, 0, $currency)));
try {
    assert_true($r36wZone !== '' && $r36wHomeZone !== '', 'R36 shipping: the demo store has active zones for Austria and Slovakia');
    $pdo->exec("INSERT INTO shipping_methods (store_id, code, carrier, name, service_type, supports_cod, supports_pickup, active, sort_order) VALUES ('default', 'r36w', 'r36w', 'R36W courier', 'parcel', 1, 0, 1, 5)");
    $rate = $pdo->prepare("INSERT INTO shipping_rates (store_id, method_code, carrier, country, zone_code, weight_from_g, weight_to_g, price, cod_surcharge, sort_order) VALUES ('default', 'r36w', 'r36w', ?, ?, 0, 5000, 700, 100, 5)");
    $rate->execute(['AT', $r36wZone]);
    $rate->execute(['SK', $r36wHomeZone]);
    ShippingService::setAdapters(['r36w' => $r36wCourier]);

    $other = StoreContext::current()->currency() === 'CZK' ? 'EUR' : 'CZK';
    assert_true(!StoreContext::current()->usesProviderPrices($other), 'R36 shipping: the test currency is not the store price lists\' currency');
    $at3 = $r36wCodes('AT', 3000, $other);
    $at8 = $r36wCodes('AT', 8000, $other);
    assert_true(in_array('r36w', $at3, true) && !in_array('r36w', $at8, true),
        "R36 shipping: in {$other} a method whose Austrian rates end at 5 kg is offered for 3 kg but not for 8 kg (was: 8 kg offered; got 8 kg: " . implode(',', $at8) . ')');
    assert_true(!in_array('r36w', $r36wCodes('AT', 8000, null), true) && in_array('r36w', $r36wCodes('AT', 3000, null), true),
        'R36 shipping: the store\'s own currency keeps the same bands');

    // 2. Cash on delivery is offered with a method that takes it, and not with the same method set to "no COD".
    // (A COD payment limited to some delivery methods is allowed with this one too.)
    foreach ($pdo->query("SELECT code FROM payment_methods WHERE store_id = 'default' AND service_type = 'cod'")->fetchAll(PDO::FETCH_COLUMN) as $r36wCod) {
        $limited = $pdo->prepare("SELECT COUNT(*) FROM payment_method_shipping WHERE store_id = 'default' AND method_code = ?");
        $limited->execute([$r36wCod]);
        if ((int)$limited->fetchColumn() > 0) $pdo->prepare("INSERT INTO payment_method_shipping (store_id, method_code, shipping_method_code) VALUES ('default', ?, 'r36w')")->execute([$r36wCod]);
    }
    $payments = static fn(string $shipping): array => array_values(array_map(static fn(array $m): string => (string)($m['service_type'] ?? ''),
        PaymentService::availableMethods('SK', StoreContext::current()->currency(), 1000, $shipping)));
    $withCod = $payments('r36w');
    $pdo->exec("UPDATE shipping_methods SET supports_cod = 0 WHERE store_id = 'default' AND code = 'r36w'");
    $withoutCod = $payments('r36w');
    assert_true(in_array('cod', $withCod, true) && !in_array('cod', $withoutCod, true) && count($withoutCod) === count($withCod) - count(array_keys($withCod, 'cod', true)),
        'R36 shipping: checkout offers no cash on delivery with a delivery method set to "no COD", the other payments stay (got: ' . implode(',', $withoutCod) . ')');

    // 3. The settings page explains the prices, in every language, and its tables scroll inside their card.
    $page = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Operations.php');
    $shippingPage = substr($page, (int)strpos($page, 'function pageShipping(): void {'));
    $shippingPage = substr($shippingPage, 0, (int)strpos($shippingPage, "\nfunction ", 10));
    assert_true(substr_count($shippingPage, '<table>') === 4 && substr_count($shippingPage, '<div class="table-scroll"><table>') === 4,
        'R36 shipping: the four tables of the shipping settings page scroll inside their card (no sideways page on a desktop)');
    $explained = true;
    foreach (glob(dirname(TEST_CLI_DIR) . '/lang/*.php') ?: [] as $langFile) {
        $strings = require $langFile;
        foreach (['admin.shipping.help_rates', 'admin.shipping.help_legacy'] as $key) $explained = $explained && trim((string)($strings[$key] ?? '')) !== '';
        $explained = $explained && str_contains((string)($strings['admin.shipping.help_rates'] ?? ''), '{currency}') && str_contains((string)($strings['admin.shipping.help_legacy'] ?? ''), 'shipping.json');
    }
    assert_true($explained && str_contains($shippingPage, "'admin.shipping.help_rates'") && str_contains($shippingPage, "'admin.shipping.help_legacy'"),
        'R36 shipping: the page says which prices apply in which currency and that "Standard"/"Express" come from shipping.json, in every language');
} catch (Throwable $e) {
    assert_true(false, 'R36 shipping (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    ShippingService::setAdapters([]);
    $pdo->exec("DELETE FROM shipping_rates WHERE store_id = 'default' AND method_code = 'r36w'");
    $pdo->exec("DELETE FROM shipping_methods WHERE store_id = 'default' AND code = 'r36w'");
    $pdo->exec("DELETE FROM payment_method_shipping WHERE store_id = 'default' AND shipping_method_code = 'r36w'");
}
