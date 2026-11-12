<?php

// Review round 34 (medium 1): editing the lines of a pending order re-priced the VAT ID as unverified unless the whole
// order was reverse charge, and compared only the whole order's scenario. An e-book reverse charged for a German
// business next to goods taxed at home then silently paid OSS German VAT (or Slovak VAT) after the edit, and an order
// of a business with a Slovak VAT ID delivered to Czechia (Slovak VAT, no OSS) could not be edited at all.
echo "\n--- Audit round 34: admin line edit keeps the VAT of every line ---\n";

Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
$r34eStores = Bootstrap::stores();
$r34eOss = $r34eStores;
$r34eOss['stores']['default']['oss_enabled'] = true;
$r34eOss['stores']['default']['legal']['country'] = 'SK';
$r34eStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r34eStoresProp->setValue(null, $r34eOss);
Bootstrap::initStoreContext('/', '', 'default');
$r34eCart = $_SESSION['cart'] ?? null;
$r34eIds = [];
try {
    $r34eWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (99341, 'default', 1, 'R34E e-book', 'r34e-ebook', 'R34E-EBOOK', 'downloadable', 1230, 'eur', 'active', 0, 'continue')");
    $pdo->exec("INSERT INTO digital_assets (sku, asset_key, label, storage_path, download_name, content_type, download_limit, expiry_seconds, active)
        VALUES ('R34E-EBOOK', 'pdf', 'PDF', 'r34e.pdf', 'r34e.pdf', 'application/pdf', 2, 3600, 1)");
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (99342, 'default', 1, 'R34E book', 'r34e-book', 'R34E-BOOK', 'simple', 1000, 'eur', 'active', 20, 'deny')");
    $pdo->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', :wh, 'R34E-BOOK', 20, 0)")->execute([':wh' => $r34eWarehouse]);
    foreach (['DE811907980' => 'DE', 'SK2020343434' => 'SK'] as $r34eVatId => $r34eCountry) {
        $pdo->prepare("DELETE FROM vat_id_cache WHERE vat_id = :id")->execute([':id' => $r34eVatId]);
        $pdo->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES (:id, :c, 'valid', 'R34E GmbH', :at, 86400)")
            ->execute([':id' => $r34eVatId, ':c' => $r34eCountry, ':at' => gmdate('Y-m-d H:i:s', time() - 60)]);
    }
    $_SESSION['cart'] = [];
    $r34eSupplies = static function (int $orderId): array {
        $snapshot = json_decode((string)(Orders::byId($orderId)['financial_snapshot'] ?? ''), true) ?: [];
        $out = [];
        foreach ((array)($snapshot['components'] ?? []) as $c) {
            if (($c['type'] ?? '') === 'product') $out[(string)$c['sku']] = [(int)$c['quantity'], (int)$c['rate_basis_points'], $c['supply'] ?? null, $c['rate_country'] ?? null];
        }
        ksort($out);
        return $out;
    };
    $r34eExpire = static fn() => $pdo->exec("UPDATE vat_id_cache SET ttl_seconds = 0 WHERE vat_id IN ('DE811907980', 'SK2020343434')");
    $r34eFresh = static fn() => $pdo->exec("UPDATE vat_id_cache SET ttl_seconds = 86400 WHERE vat_id IN ('DE811907980', 'SK2020343434')");
    $r34eRate = static fn(string $country): int => (int)round(VatService::rate($country, 'standard') * 100);

    // A German business billed in Berlin, the printed book delivered in Bratislava: goods Slovak VAT, the e-book RC.
    $r34eDe = ManualOrder::create(ManualOrder::parseLines("R34E-BOOK 1\nR34E-EBOOK 1")['lines'], ['name' => 'R34E Empfang', 'email' => 'r34e-de@example.com',
        'country' => 'SK', 'street' => 'Dodacia 1', 'city' => 'Bratislava', 'zip' => '81101', 'company' => 'R34E GmbH', 'vat_id' => 'DE811907980',
        'shipping_method' => 'packeta', 'payment_method' => 'bank_transfer', 'locale' => 'de', 'billing_different' => '1', 'billing_name' => 'R34E Buchhaltung',
        'billing_street' => 'Rechnungsweg 2', 'billing_city' => 'Berlin', 'billing_zip' => '10115', 'billing_country' => 'DE'], false);
    $r34eDeId = (int)($r34eDe['order_id'] ?? 0);
    $r34eIds[] = $r34eDeId;
    $r34eDeBefore = $r34eSupplies($r34eDeId);
    assert_equals([true, VatService::SCENARIO_DOMESTIC, [0, VatService::SCENARIO_REVERSE_CHARGE, 'DE']], [$r34eDe['ok'], (string)(Orders::byId($r34eDeId)['vat_scenario'] ?? ''),
        array_slice($r34eDeBefore['R34E-EBOOK'] ?? [], 1)],
        'R34 edit setup: goods at home, the e-book of the German business reverse charged (' . ($r34eDe['error'] ?? 'ok') . ' ' . ($r34eDe['detail'] ?? '') . ')');
    // VIES is not asked again: the cache has expired since the order was placed.
    $r34eExpire();
    $r34eDeEdit = ManualOrder::editItems($r34eDeId, ManualOrder::parseLines("R34E-BOOK 2\nR34E-EBOOK 1")['lines']);
    $r34eDeAfter = $r34eSupplies($r34eDeId);
    assert_equals([true, [1, 0, VatService::SCENARIO_REVERSE_CHARGE, 'DE'], [2, $r34eRate('SK'), null, null]],
        [$r34eDeEdit['ok'], $r34eDeAfter['R34E-EBOOK'] ?? null, $r34eDeAfter['R34E-BOOK'] ?? null],
        'R34 edit: the e-book stays reverse charged after the goods change (was OSS DE ' . $r34eRate('DE') . ' bp, ' . ($r34eDeEdit['error'] ?? 'ok') . ')');
    // A line whose treatment can no longer be reproduced is refused and nothing changes.
    $r34eSnap = json_decode((string)(Orders::byId($r34eDeId)['financial_snapshot'] ?? ''), true);
    foreach ($r34eSnap['components'] as $i => $c) if (($c['sku'] ?? '') === 'R34E-EBOOK') $r34eSnap['components'][$i]['rate_country'] = 'AT';
    $pdo->prepare("UPDATE orders SET financial_snapshot = :s WHERE id = :id")->execute([':s' => json_encode($r34eSnap), ':id' => $r34eDeId]);
    $r34eRefused = ManualOrder::editItems($r34eDeId, ManualOrder::parseLines("R34E-BOOK 1\nR34E-EBOOK 1")['lines']);
    assert_equals([false, 'admin.order.edit.vat_changed', 2], [$r34eRefused['ok'], $r34eRefused['error'], (int)($r34eSupplies($r34eDeId)['R34E-BOOK'][0] ?? 0)],
        'R34 edit: a line that would be supplied differently is refused, not re-taxed silently');

    // Adding a download to a goods-only order of the German business: the e-book is reverse charged like a new order.
    $r34eFresh();
    $r34eGoods = ManualOrder::create(ManualOrder::parseLines('R34E-BOOK 1')['lines'], ['name' => 'R34E Empfang', 'email' => 'r34e-de2@example.com',
        'country' => 'SK', 'street' => 'Dodacia 1', 'city' => 'Bratislava', 'zip' => '81101', 'company' => 'R34E GmbH', 'vat_id' => 'DE811907980',
        'shipping_method' => 'packeta', 'payment_method' => 'bank_transfer', 'locale' => 'de', 'billing_different' => '1', 'billing_name' => 'R34E Buchhaltung',
        'billing_street' => 'Rechnungsweg 2', 'billing_city' => 'Berlin', 'billing_zip' => '10115', 'billing_country' => 'DE'], false);
    $r34eGoodsId = (int)($r34eGoods['order_id'] ?? 0);
    $r34eIds[] = $r34eGoodsId;
    $r34eExpire();
    $r34eAdded = ManualOrder::editItems($r34eGoodsId, ManualOrder::parseLines("R34E-BOOK 1\nR34E-EBOOK 1")['lines']);
    assert_equals([true, [1, 0, VatService::SCENARIO_REVERSE_CHARGE, 'DE']], [$r34eAdded['ok'], $r34eSupplies($r34eGoodsId)['R34E-EBOOK'] ?? null],
        'R34 edit: an e-book added for a business whose VAT ID checked out valid is reverse charged (' . ($r34eAdded['error'] ?? 'ok') . ')');

    // A business with a Slovak VAT ID, goods delivered to Czechia: Slovak VAT, and the edit keeps it (was refused).
    $r34eFresh();
    $r34eSk = ManualOrder::create(ManualOrder::parseLines('R34E-BOOK 1')['lines'], ['name' => 'R34E Příjemce', 'email' => 'r34e-sk@example.com',
        'country' => 'CZ', 'street' => 'Dodací 1', 'city' => 'Praha', 'zip' => '11000', 'company' => 'R34E s.r.o.', 'vat_id' => 'SK2020343434',
        'shipping_method' => 'packeta', 'payment_method' => 'bank_transfer', 'locale' => 'sk'], false);
    $r34eSkId = (int)($r34eSk['order_id'] ?? 0);
    $r34eIds[] = $r34eSkId;
    $r34eExpire();
    $r34eSkEdit = ManualOrder::editItems($r34eSkId, ManualOrder::parseLines('R34E-BOOK 3')['lines']);
    assert_equals([true, true, VatService::SCENARIO_DOMESTIC, [3, $r34eRate('SK'), null, null]],
        [$r34eSk['ok'], $r34eSkEdit['ok'], (string)(Orders::byId($r34eSkId)['vat_scenario'] ?? ''), $r34eSupplies($r34eSkId)['R34E-BOOK'] ?? null],
        'R34 edit: the order of a Slovak VAT ID delivered to CZ is edited and keeps Slovak VAT (was refused: ' . ($r34eSkEdit['error'] ?? 'ok') . ')');
} catch (Throwable $e) {
    assert_true(false, 'R34 edit VAT (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
foreach ($r34eIds as $r34eId) if ($r34eId > 0) OrderWorkflow::transition($r34eId, 'cancelled', 'R34 edit VAT fixture');
$r34eStoresProp->setValue(null, $r34eStores);
Bootstrap::initStoreContext('/', '', 'default');
if ($r34eCart === null) unset($_SESSION['cart']); else $_SESSION['cart'] = $r34eCart;
