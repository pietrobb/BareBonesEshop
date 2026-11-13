<?php

// Review round 35 (2026-11-13), medium 2 (goal #446): when the store's own rates (shipping_rates) did not cover the
// country and weight, the checkout asked every carrier adapter for its built-in prices and ignored what the merchant set
// for that carrier: Packeta (10 kg limit, SK/CZ only) was offered for 24 kg and to Austria at 10.99 €, and in-store pickup
// (set up for Slovakia) appeared for an address abroad only once the cart was heavy. R35: a carrier the merchant set up
// as a shipping method is quoted by those settings only; built-in prices stay for carriers the store never set up.
echo "\n--- R35: built-in carrier prices do not override the merchant's shipping methods ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r35sMethods = static fn(string $country, int $weightG): array => array_values(array_unique(array_map(
    static fn(array $r): string => (string)($r['method_code'] ?? ''), ShippingService::calculateRates($country, $weightG, 1000))));
$r35sSaved = $pdo->query("SELECT code, active, max_weight_g FROM shipping_methods WHERE store_id = 'default' AND code IN ('packeta', 'pickup', 'gls')")->fetchAll(PDO::FETCH_ASSOC);
$r35sCarrier = new class implements ShippingAdapter {
    public function getRates(array $params): array { return [['carrier' => 'r35s-courier', 'label' => 'R35S courier', 'price' => 1500, 'cod_surcharge' => 0, 'delivery_days' => '2', 'cod' => false]]; }
    public function generateLabel(array $order): array { throw new LogicException('no labels'); }
    public function trackShipment(string $awb): array { return []; }
    public function supportsCOD(): bool { return false; }
    public function carrierId(): string { return 'r35s-courier'; }
};
try {
    ShippingService::setAdapters(['packeta' => new PacketaShipping(), 'pickup' => new PersonalPickup(), 'r35s-courier' => $r35sCarrier]);
    $pdo->exec("UPDATE shipping_methods SET active = 1 WHERE store_id = 'default' AND code IN ('packeta', 'pickup', 'gls')");
    $pdo->exec("UPDATE shipping_methods SET max_weight_g = 10000 WHERE store_id = 'default' AND code = 'packeta'");

    $cz24 = $r35sMethods('CZ', 24000);
    assert_true(!in_array('packeta', $cz24, true) && !in_array('pickup', $cz24, true),
        'R35 shipping: 24 kg to Czechia gets neither Packeta (10 kg limit) nor in-store pickup (set up for Slovakia) (got: ' . implode(',', $cz24) . ')');
    $at8 = $r35sMethods('AT', 8000);
    $at1 = $r35sMethods('AT', 1000);
    assert_true(!in_array('packeta', $at8, true) && !in_array('packeta', $at1, true),
        'R35 shipping: Packeta is not offered to Austria, where the merchant did not set it up (was 10.99 € at 8 kg; got: ' . implode(',', $at8) . ')');
    assert_true(!in_array('pickup', $at8, true) && !in_array('pickup', $at1, true) && in_array('gls', $at1, true),
        'R35 shipping: in-store pickup is offered abroad (or not) the same at any weight; the merchant\'s GLS rate still quotes Austria');
    $sk24 = $r35sMethods('SK', 24000);
    assert_true(in_array('pickup', $sk24, true) && !in_array('packeta', $sk24, true),
        'R35 shipping: 24 kg in Slovakia keeps the merchant\'s pickup and gets no Packeta over its limit');
    assert_true(in_array('r35s-courier', $at8, true) && in_array('r35s-courier', $cz24, true),
        'R35 shipping: a carrier the store never set up as a method is still quoted by its built-in prices');

    // A carrier the merchant switched off is not brought back by its built-in prices either.
    $pdo->exec("UPDATE shipping_methods SET active = 0 WHERE store_id = 'default' AND code = 'pickup'");
    assert_true(!in_array('pickup', $r35sMethods('SK', 1000), true) && !in_array('pickup', $r35sMethods('CZ', 24000), true),
        'R35 shipping: a switched-off pickup method is offered nowhere');
} catch (Throwable $e) {
    assert_true(false, 'R35 shipping (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    ShippingService::setAdapters([]);
    $r35sRestore = $pdo->prepare("UPDATE shipping_methods SET active = ?, max_weight_g = ? WHERE store_id = 'default' AND code = ?");
    foreach ($r35sSaved as $r35sRow) $r35sRestore->execute([$r35sRow['active'], $r35sRow['max_weight_g'], $r35sRow['code']]);
}
