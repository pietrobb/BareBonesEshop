<?php

// Review round 35 (2026-11-13), VAT lows (goal #448): a manual order billed to another address lost the "not verified
// in VIES" marker of its IČ DPH; an e-book next to goods reverse charged in the same state was summed into the goods'
// group and the invoice cited only art. 138; a Slovak company's e-book next to goods exported to the USA was zero-rated
// with them instead of paying the seller's VAT.
echo "\n--- R35: VAT lows — unverified VAT ID on manual orders, RC goods + e-book, SK company export ---\n";

Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
$r35vStores = Bootstrap::stores();
$r35vStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r35vOss = $r35vStores;
$r35vOss['stores']['default']['oss_enabled'] = true;
$r35vOss['stores']['default']['legal']['country'] = 'SK';
$r35vStoresProp->setValue(null, $r35vOss);
Bootstrap::initStoreContext('/', '', 'default');
$r35vCart = $_SESSION['cart'] ?? null;
$r35vOrders = [];
$r35vFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R35 VAT lows {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};

try {
    $r35vWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->exec("DELETE FROM products WHERE id IN (99351, 99352)");
    $pdo->exec("DELETE FROM digital_assets WHERE sku = 'R35V-EBOOK'");
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (99351, 'default', 1, 'R35V e-book', 'r35v-ebook', 'R35V-EBOOK', 'downloadable', 1230, 'eur', 'active', 0, 'continue')");
    $pdo->exec("INSERT INTO digital_assets (sku, asset_key, label, storage_path, download_name, content_type, download_limit, expiry_seconds, active)
        VALUES ('R35V-EBOOK', 'pdf', 'PDF', 'r35v.pdf', 'r35v.pdf', 'application/pdf', 2, 3600, 1)");
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (99352, 'default', 1, 'R35V book', 'r35v-book', 'R35V-BOOK', 'simple', 1000, 'eur', 'active', 20, 'deny')");
    $pdo->prepare("DELETE FROM warehouse_stock WHERE sku = 'R35V-BOOK'")->execute();
    $pdo->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', :wh, 'R35V-BOOK', 20, 0)")->execute([':wh' => $r35vWarehouse]);
    foreach (['DE811907981' => ['DE', 'valid'], 'SK2020353535' => ['SK', 'valid'], 'DE999999935' => ['DE', 'unverified']] as $r35vId => [$r35vCountry, $r35vStatus]) {
        $pdo->prepare("DELETE FROM vat_id_cache WHERE vat_id = :id")->execute([':id' => $r35vId]);
        $pdo->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES (:id, :c, :s, 'R35V GmbH', :at, 86400)")
            ->execute([':id' => $r35vId, ':c' => $r35vCountry, ':s' => $r35vStatus, ':at' => gmdate('Y-m-d H:i:s', time() - 60)]);
    }
    $_SESSION['cart'] = ['p_99351' => ['product_id' => 99351, 'variant_id' => null, 'qty' => 1], 'p_99352' => ['product_id' => 99352, 'variant_id' => null, 'qty' => 1]];
    $r35vComponents = static function (array $vat): array {
        $items = array_values(array_filter(Cart::enriched($vat['context'])['items'], static fn(array $i): bool => !empty($i['available'])));
        $out = [];
        foreach ((array)(VatService::buildSnapshot($items, $vat['context'])['components'] ?? []) as $c) {
            if (($c['type'] ?? '') === 'product') $out[(string)$c['sku']] = [(int)$c['rate_basis_points'], $c['supply'] ?? null, $c['rate_country'] ?? null];
        }
        ksort($out);
        return $out;
    };

    // 1. A German business, the book delivered to Germany (art. 138) and an e-book: the e-book is its own RC group.
    $r35vRc = CheckoutService::vatContext('DE', 'DE811907981', 'packeta', true, null, 'DE');
    assert_equals([VatService::SCENARIO_REVERSE_CHARGE, ['country' => 'DE', 'scheme' => VatService::SCENARIO_REVERSE_CHARGE]],
        [(string)$r35vRc['scenario']['scenario'], VatService::digitalSupply($r35vRc['context'])],
        'R35 VAT: next to goods reverse charged in Germany, the e-book of the German business is a reverse-charged service there');
    assert_equals(['R35V-BOOK' => [0, null, null], 'R35V-EBOOK' => [0, VatService::SCENARIO_REVERSE_CHARGE, 'DE']], $r35vComponents($r35vRc),
        'R35 VAT: the e-book is grouped apart from the goods ("0 DE RC"), both at 0 % (was: one goods group)');
    $r35vNotices = InvoiceService::vatNotices(['reverse_charge_note_key' => 'vat.reverse_charge_note'], 'en',
        ['rates' => ['0' => ['net' => 813, 'vat' => 0], '0 DE RC' => ['net' => 1000, 'vat' => 0, 'country' => 'DE', 'supply' => 'reverse_charge']]], 'SK');
    assert_true(count($r35vNotices) === 2 && str_contains($r35vNotices[0], 'Article 138') && str_contains($r35vNotices[1], 'Article 196'),
        'R35 VAT: such an invoice cites art. 138 for the goods and art. 196 for the e-book (was: only art. 138)');

    // 2. A Slovak company (valid SK VAT ID), the goods exported to the USA: the e-book pays Slovak VAT (art. 44).
    $r35vExport = CheckoutService::vatContext('US', 'SK2020353535', 'packeta', true, null, '');
    $r35vSk = (int)round(VatService::rate('SK', VatService::digitalCategory('standard')) * 100);
    assert_equals([VatService::SCENARIO_EXPORT, ['R35V-BOOK' => [0, null, null], 'R35V-EBOOK' => [$r35vSk, VatService::SCENARIO_DOMESTIC, 'SK']]],
        [(string)$r35vExport['scenario']['scenario'], $r35vComponents($r35vExport)],
        'R35 VAT: a Slovak company\'s e-book next to goods exported to the USA pays the Slovak rate (was 0 % with the export)');
    // A consumer there keeps the zero-rated e-book next to the exported goods (billing and delivery both outside the EU).
    $r35vConsumer = CheckoutService::vatContext('US', '', 'packeta', false, null, '');
    assert_equals(['R35V-BOOK' => [0, null, null], 'R35V-EBOOK' => [0, null, null]], $r35vComponents($r35vConsumer),
        'R35 VAT: a consumer\'s e-book next to goods exported to the USA stays zero-rated with them');
} catch (Throwable $e) { $r35vFail('supply', $e); }

// 3. A manual order billed elsewhere keeps the "not verified in VIES" marker of its IČ DPH on the billing address.
try {
    $_SESSION['cart'] = [];
    $r35vManual = ManualOrder::create(ManualOrder::parseLines('R35V-BOOK 1')['lines'], ['name' => 'R35V Empfang', 'email' => 'r35v-manual@example.com',
        'country' => 'SK', 'street' => 'Dodacia 1', 'city' => 'Bratislava', 'zip' => '81101', 'company' => 'R35V GmbH', 'vat_id' => 'DE999999935',
        'shipping_method' => 'packeta', 'payment_method' => 'bank_transfer', 'locale' => 'de', 'billing_different' => '1', 'billing_name' => 'R35V Buchhaltung',
        'billing_street' => 'Rechnungsweg 2', 'billing_city' => 'Berlin', 'billing_zip' => '10115', 'billing_country' => 'DE'], false);
    $r35vManualId = (int)($r35vManual['order_id'] ?? 0);
    if ($r35vManualId > 0) $r35vOrders[] = $r35vManualId;
    $r35vOrder = Orders::byId($r35vManualId) ?? [];
    $r35vBilling = OrderAddress::billing($r35vOrder);
    $r35vSnapshot = (new ReflectionMethod(InvoiceService::class, 'customerSnapshot'))->invoke(null, $r35vOrder);
    assert_equals([true, 'DE999999935', '1', true], [$r35vManual['ok'], $r35vBilling['ic_dph'] ?? null, $r35vBilling['ic_dph_unverified'] ?? null, $r35vSnapshot['ic_dph_unverified'] ?? null],
        'R35 VAT: a manual order billed to Berlin keeps its IČ DPH as not verified in VIES on the invoice (was: printed as verified; ' . ($r35vManual['error'] ?? 'ok') . ')');
} catch (Throwable $e) { $r35vFail('manual order', $e); }

$r35vStoresProp->setValue(null, $r35vStores);
Bootstrap::initStoreContext('/', '', 'default');
if ($r35vCart === null) unset($_SESSION['cart']); else $_SESSION['cart'] = $r35vCart;
