<?php
$pdo ??= test_commerce_fixture();

// Review round 13 (R39), low findings of VAT and shipping (goal #486):
// 1. VIES holder check: a company named by its legal form only ("s.r.o.") matched any holder, one shared word was enough,
//    "MUELLER" did not match "Müller", and a VAT ID of another state than the billing one was refused (art. 138 allows it).
// 2. Downloads' country: the evidence (billing, goods sent to an address, IP country) decides; a contradiction asks.
// 3. OSS is the VAT payer's: a second store of the same IČ DPH with OSS off charged Slovak VAT and the doctor said PASS.
// 4. EUR rates for the OSS threshold were static; the ECB daily rates are fetched and stored (EurRates).
// 5. In another currency one active method of a carrier let in the carrier's every built-in rate.
echo "\n--- R39 VAT low: VIES holder, residence evidence, OSS per VAT payer, ECB rates, one method ≠ the carrier ---\n";

Bootstrap::initStoreContext('/', '', 'default');
(require TEST_CLI_DIR . '/../migrations/20261116110000_eur_exchange_rates.php')->up($pdo, Db::isSqlite());
try {
    // 1. VIES holder.
    $pdo->exec("DELETE FROM vat_id_cache WHERE vat_id LIKE 'DE3939%' OR vat_id LIKE 'SK3939%'");
    $r39vCache = $pdo->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES (?, ?, 'valid', ?, ?, 86400)");
    $r39vCache->execute(['DE393939390', 'DE', 'Müller Handels GmbH', gmdate('Y-m-d H:i:s')]);
    $r39vCache->execute(['SK3939393939', 'SK', 'Alpha Beta Gamma s.r.o.', gmdate('Y-m-d H:i:s')]);
    $r39vHolder = [
        ['DE393939390', 'MUELLER Handels GmbH', null, '"MUELLER" is "Müller"'],
        ['DE393939390', 'Mueller Handels', null, 'the shorter form without the legal form'],
        ['SK3939393939', 's.r.o.', 'name', 'a company named by its legal form only'],
        ['SK3939393939', 'Alpha Zeta s.r.o.', 'name', 'one shared word'],
        ['SK3939393939', 'Gamma Alpha', 'name', 'the holder\'s words in another order'],
        ['SK3939393939', 'Alpha Beta', null, 'the holder\'s leading words (control)'],
    ];
    foreach ($r39vHolder as [$r39vId, $r39vCompany, $r39vExpected, $r39vCase]) {
        $r39vGot = VatService::vatIdHolderMismatch($r39vId, $r39vCompany);
        assert_true($r39vGot === $r39vExpected, "R39 VIES holder: {$r39vCase} → " . json_encode($r39vExpected) . ' (got ' . json_encode($r39vGot) . ')');
    }
    assert_true((new ReflectionMethod(VatService::class, 'vatIdHolderMismatch'))->getNumberOfParameters() === 2,
        'R39 VIES holder: the billing country is no condition (art. 138: identified in any other member state)');

    // 2. Residence evidence (CheckoutService::digitalResidence()).
    $r39vResidence = static fn(bool $digitalOnly, string $billing, string $delivery, bool $collected, string $declared = '', string $ip = ''): array
        => CheckoutService::digitalResidence($digitalOnly, $billing, $delivery, $collected, $declared, $ip);
    $r39vGift = $r39vResidence(false, 'LU', 'SK', false);
    assert_true($r39vGift['required'] && $r39vGift['choices'] === ['LU', 'SK'],
        'R39 residence: billed in LU, goods sent to SK — the evidence contradicts, the buyer is asked (got ' . json_encode($r39vGift) . ')');
    assert_true($r39vResidence(false, 'LU', 'SK', false, 'LU')['country'] === 'LU' && $r39vResidence(false, 'LU', 'SK', false, 'SK')['country'] === 'SK'
        && $r39vResidence(false, 'LU', 'SK', false, 'AT')['required'], 'R39 residence: the declared country decides, only one the evidence names');
    $r39vPickup = $r39vResidence(false, 'AT', 'AT', true);
    assert_true($r39vPickup['country'] === 'AT' && !$r39vPickup['required'] && !isset($r39vPickup['evidence']['delivery']),
        'R39 residence: collected in the Bratislava store, an Austrian is supplied the e-book in AT (collection is no evidence; got ' . json_encode($r39vPickup) . ')');
    $r39vIpOnly = $r39vResidence(true, 'LU', '', false, '', 'DE');
    assert_true($r39vIpOnly['required'] && $r39vIpOnly['choices'] === ['LU', 'DE'] && $r39vResidence(true, 'LU', '', false, '', 'LU')['country'] === 'LU' && !$r39vResidence(true, 'LU', '', false, '', 'LU')['required'],
        'R39 residence: a digital-only order is checked against the IP country (got ' . json_encode($r39vIpOnly) . ')');
    assert_true(!$r39vResidence(true, 'LU', '', false, '', 'T1')['required'] && !$r39vResidence(true, 'LU', '', false, '', 'xx')['required'],
        'R39 residence: an IP "country" the shop does not sell to (Tor, unknown) is no evidence');
    // R40: and only from a trusted proxy (AuditRound40VatLowRegression).
    $r39vCfg = new ReflectionProperty(Security::class, 'cfg');
    $r39vCfgBefore = $r39vCfg->getValue();
    $r39vCfg->setValue(null, ['trusted_proxies' => ['10.39.0.1']] + (array)(require AppConfig::path(dirname(TEST_CLI_DIR))));
    [$_SERVER['HTTP_CF_IPCOUNTRY'], $r39vRemote, $_SERVER['REMOTE_ADDR']] = ['de', $_SERVER['REMOTE_ADDR'] ?? null, '10.39.0.1'];
    assert_true(CheckoutService::ipCountry([]) === '' && CheckoutService::ipCountry(['vat_ip_country_header' => 'CF-IPCountry']) === 'DE',
        'R39 residence: the IP country comes only from the header the store names (a client could send any)');
    unset($_SERVER['HTTP_CF_IPCOUNTRY']);
    if ($r39vRemote === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $r39vRemote;
    $r39vCfg->setValue(null, $r39vCfgBefore);

    // 3. OSS of the VAT payer.
    $r39vStores = [
        'a' => ['name' => 'R39 A', 'oss_enabled' => true, 'currency' => 'EUR', 'legal' => ['ic_dph' => 'SK3939393939', 'country' => 'SK']],
        'b' => ['name' => 'R39 B', 'oss_enabled' => false, 'currency' => 'EUR', 'legal' => ['ic_dph' => 'SK 3939393939', 'country' => 'SK']],
        'c' => ['name' => 'R39 C', 'oss_enabled' => false, 'currency' => 'EUR', 'legal' => ['ic_dph' => 'SK3939393940', 'country' => 'SK']],
    ];
    assert_true(OssThreshold::sellerOssEnabled('b', $r39vStores['b'], $r39vStores) && !OssThreshold::sellerOssEnabled('c', $r39vStores['c'], $r39vStores),
        'R39 OSS per VAT payer: a store of the same IČ DPH is in OSS with its own switch off; another VAT payer is not');
    $r39vCheck = Setup::ossThresholdCheck($pdo, 'b', $r39vStores['b'], $r39vStores);
    assert_true($r39vCheck['status'] !== 'pass' && str_contains($r39vCheck['message'], 'same VAT ID'),
        'R39 OSS per VAT payer: the doctor no longer says PASS for the store with OSS off (got ' . $r39vCheck['status'] . ': ' . $r39vCheck['message'] . ')');

    // 4. ECB rates.
    $pdo->exec('DELETE FROM eur_exchange_rates');
    // R40: a past date (a future one is refused) and forced (a first feed of three currencies is refused otherwise).
    $r39vDay = gmdate('Y-m-d', ShopTime::now() - 86400);
    EurRates::setFixtureTransport(static fn(string $url): string => '<?xml version="1.0" encoding="UTF-8"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
<gesmes:subject>Reference rates</gesmes:subject><Cube><Cube time="' . $r39vDay . '"><Cube currency="USD" rate="1.25"/><Cube currency="CZK" rate="25.000"/><Cube currency="HUF" rate="400"/></Cube></Cube></gesmes:Envelope>');
    EurRates::reset();
    $r39vRefreshed = EurRates::refresh($pdo, true);
    EurRates::reset();
    $r39vCurrent = EurRates::current();
    assert_true($r39vRefreshed === ['as_of' => $r39vDay, 'count' => 3] && $r39vCurrent['as_of'] === $r39vDay
        && abs(($r39vCurrent['rates']['czk'] ?? 0) - 0.04) < 1e-9 && abs(($r39vCurrent['rates']['usd'] ?? 0) - 0.8) < 1e-9,
        'R39 EUR rates: the ECB daily cube is stored and used for the threshold (got ' . json_encode([$r39vRefreshed, $r39vCurrent]) . ')');
    EurRates::setFixtureTransport(static fn(string $url): string => '<html>maintenance</html>');
    $r39vFailed = false;
    try { EurRates::refresh($pdo); } catch (RuntimeException $r39vE) { $r39vFailed = true; }
    EurRates::reset();
    assert_true($r39vFailed && EurRates::current()['as_of'] === $r39vDay, 'R39 EUR rates: a broken feed fails loudly and keeps the stored rates');

    // 5. One allowed method of a carrier is not the carrier.
    $r39vZone = (string)$pdo->query("SELECT c.zone_code FROM shipping_zone_countries c JOIN shipping_zones z ON z.code = c.zone_code AND z.store_id = c.store_id
        WHERE c.store_id = 'default' AND c.country = 'AT' AND z.active = 1 ORDER BY c.id LIMIT 1")->fetchColumn();
    $r39vAdapter = new class implements ShippingAdapter {
        public function getRates(array $params): array {
            return [['carrier' => 'r39c', 'method_code' => 'r39c-box', 'label' => 'R39C box', 'price' => 300, 'cod_surcharge' => 0, 'delivery_days' => '2', 'cod' => false],
                ['carrier' => 'r39c', 'method_code' => 'r39c-home', 'label' => 'R39C home', 'price' => 600, 'cod_surcharge' => 0, 'delivery_days' => '2', 'cod' => false]];
        }
        public function generateLabel(array $order): array { throw new LogicException('no labels'); }
        public function trackShipment(string $awb): array { return []; }
        public function supportsCOD(): bool { return false; }
        public function carrierId(): string { return 'r39c'; }
    };
    $r39vMethod = $pdo->prepare("INSERT INTO shipping_methods (store_id, code, carrier, name, service_type, supports_cod, supports_pickup, active, sort_order) VALUES ('default', ?, 'r39c', ?, 'parcel', 0, 0, ?, 5)");
    $r39vMethod->execute(['r39c-box', 'R39C box', 1]);
    $r39vMethod->execute(['r39c-home', 'R39C home', 0]);
    $pdo->prepare("INSERT INTO shipping_rates (store_id, method_code, carrier, country, zone_code, weight_from_g, weight_to_g, price, cod_surcharge, sort_order) VALUES ('default', 'r39c-box', 'r39c', 'AT', ?, 0, 99999, 300, 0, 5)")->execute([$r39vZone]);
    ShippingService::setAdapters(['r39c' => $r39vAdapter]);
    $r39vOther = StoreContext::current()->currency() === 'CZK' ? 'EUR' : 'CZK';
    $r39vCodes = array_values(array_map(static fn(array $r): string => (string)($r['method_code'] ?? ''), ShippingService::calculateRates('AT', 1000, 1000, false, 0, 0, $r39vOther)));
    assert_true(in_array('r39c-box', $r39vCodes, true) && !in_array('r39c-home', $r39vCodes, true),
        "R39 shipping: in {$r39vOther} the active R39C box is offered at the carrier's price, its inactive home delivery is not (got: " . implode(',', $r39vCodes) . ')');
} catch (Throwable $r39vE) {
    assert_true(false, 'R39 VAT low (' . get_class($r39vE) . ': ' . $r39vE->getMessage() . ' @ ' . basename($r39vE->getFile()) . ':' . $r39vE->getLine() . ')');
} finally {
    EurRates::setFixtureTransport(null);
    EurRates::reset();
    ShippingService::setAdapters([]);
    $pdo->exec('DELETE FROM eur_exchange_rates');
    $pdo->exec("DELETE FROM vat_id_cache WHERE vat_id LIKE 'DE3939%' OR vat_id LIKE 'SK3939%'");
    $pdo->exec("DELETE FROM shipping_rates WHERE store_id = 'default' AND method_code LIKE 'r39c-%'");
    $pdo->exec("DELETE FROM shipping_methods WHERE store_id = 'default' AND code LIKE 'r39c-%'");
}
