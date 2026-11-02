<?php

// Bookshop demo (#237): one book, three formats. A digital variant (the e-book) of a physical product (paperback,
// hardcover) is sold as digital content: no stock, quantity 1, no weight and no shipping, the digital-content consent,
// never reserved or released in the warehouse, and its download is an asset of the variant's SKU.
echo "\n--- Bookshop: digital variant of a physical product (e-book / paperback / hardcover) ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$bkFail = static function (string $what, Throwable $e): void {
    assert_true(false, "Bookshop {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$bkCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$bkWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
// Fixed ids: an auto-assigned id follows the highest fixed id of earlier scenarios and collides with later ones.
$pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, weight_g) VALUES
    (98801, 'default', {$bkCategory}, 'BK-TS', 'bk-tom-sawyer', 'simple', 'The Adventures of Tom Sawyer', 1290, 'eur', 0, 'active', 'deny', 400)");
$pdo->exec("INSERT INTO variants (id, product_id, sku, name, attrs, price_absolute, stock, sort_order, digital) VALUES
    (98811, 98801, 'BK-TS-EBOOK', 'eBook', '{\"format\":\"eBook\"}', 499, 0, 0, 1),
    (98812, 98801, 'BK-TS-PB', 'Paperback', '{\"format\":\"Paperback\"}', 1290, 0, 1, 0),
    (98813, 98801, 'BK-TS-HB', 'Hardcover', '{\"format\":\"Hardcover\"}', 2490, 0, 2, 0)");
Warehouse::setStock($bkWarehouse, 'BK-TS-PB', 5, 'count', 'test', 'bk-opening-pb');
Warehouse::setStock($bkWarehouse, 'BK-TS-HB', 2, 'count', 'test', 'bk-opening-hb');
$pdo->exec("INSERT INTO digital_assets (sku, storage_path, download_name, content_type) VALUES ('BK-TS-EBOOK', 'default/bk-tom-sawyer.epub', 'tom-sawyer.epub', 'application/epub+zip')");

try {
    assert_true(VariantAvailability::isPurchasable(98801, 98811), 'Bookshop: the e-book variant is for sale without stock');
    assert_true(VariantAvailability::isDigitalVariant(98811) && !VariantAvailability::isDigitalVariant(98812),
        'Bookshop: only the e-book variant is digital');
    assert_true(VariantAvailability::isDigitalItem(['product_id' => 98801, 'variant_id' => 98811])
        && !VariantAvailability::isDigitalItem(['product_id' => 98801, 'variant_id' => 98813]), 'Bookshop: an order line of the e-book is digital, of the hardcover not');
} catch (Throwable $e) { $bkFail('availability', $e); }

try {
    $_SESSION['cart'] = [];
    Cart::add(98801, 98811, 3);
    $bkCart = Cart::enriched();
    $bkLine = $bkCart['items'][0] ?? [];
    assert_equals([1, 'digital', 0, true], [(int)($bkLine['qty'] ?? 0), $bkLine['product_type'] ?? '', (int)($bkLine['weight_g'] ?? -1), (bool)($bkLine['available'] ?? false)],
        'Bookshop: the e-book line is one available digital unit without weight (it does not inherit the paperback weight)');
    assert_true(CheckoutService::isDigitalOnly($bkCart['items']), 'Bookshop: an e-book alone needs no shipping');
    assert_true(isset(CheckoutService::consentDefinitions('SK', $bkCart['items'])[ConsentService::DIGITAL_KEY]),
        'Bookshop: the e-book asks for the digital-content consent');

    Cart::add(98801, 98812, 2);
    $bkMixed = Cart::enriched();
    assert_true(!CheckoutService::isDigitalOnly($bkMixed['items']), 'Bookshop: e-book + paperback is shipped');
    assert_equals(2, ShippingService::physicalQuantity($bkMixed['items']), 'Bookshop: only the paperbacks count as parcel units');

    $bkOrder = Orders::create($bkMixed['items'], ['name' => 'Bookshop Reader', 'email' => 'bk@example.test', 'phone' => '', 'address' => [],
        'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur'], 'bank_transfer');
    assert_equals([3, 2], [Warehouse::totalAvailable('BK-TS-PB'), Warehouse::totalAvailable('BK-TS-HB')],
        'Bookshop: the order reserves the paperbacks, the e-book reserves nothing');
    assert_equals(0, (int)$pdo->query("SELECT COUNT(*) FROM warehouse_stock WHERE sku = 'BK-TS-EBOOK'")->fetchColumn(),
        'Bookshop: the e-book never enters the warehouse ledger');
    assert_true(DigitalDelivery::orderHasAssets($bkOrder), 'Bookshop: the e-book download belongs to the order through the variant SKU');
    assert_true(!Withdrawal::digitalOnly(['id' => $bkOrder]), 'Bookshop: an order with a paperback is not digital-only');
    Orders::restoreStock($bkOrder);
    assert_equals(5, Warehouse::totalAvailable('BK-TS-PB'), 'Bookshop: cancelling releases the paperbacks and skips the e-book');

    $_SESSION['cart'] = [];
    Cart::add(98801, 98811, 1);
    $bkDigital = Orders::create(Cart::enriched()['items'], ['name' => 'Bookshop Reader', 'email' => 'bk@example.test', 'phone' => '', 'address' => [],
        'shipping_cost' => 0, 'tax_amount' => 0, 'coupon_discount' => 0, 'currency' => 'eur'], 'bank_transfer');
    assert_true(Withdrawal::digitalOnly(['id' => $bkDigital]), 'Bookshop: an e-book order is digital-only (nothing is ever delivered by a carrier)');
    $_SESSION['cart'] = [];
} catch (Throwable $e) { $bkFail('cart and order', $e); }

// Listing: the product row holds no stock of its own; the card is listed by its variants.
// A stock count is mirrored into variants.stock by syncProductStock, as the admin and feed paths do.
$bkCount = static function (string $sku, int $qty, string $key) use ($bkWarehouse): void {
    Warehouse::setStock($bkWarehouse, $sku, $qty, 'count', 'test', $key);
    Warehouse::syncProductStock([$sku]);
};
try {
    $bkCount('BK-TS-PB', 5, 'bk-recount-pb');
    $bkCount('BK-TS-HB', 2, 'bk-recount-hb');
    $bkRow = $pdo->query('SELECT * FROM products WHERE id = 98801')->fetch(PDO::FETCH_ASSOC);
    $bkCard = StorefrontViewModel::productCard($bkRow);
    assert_equals([true, true, 7], [$bkCard['availability']['purchasable'], $bkCard['availability']['in_stock'], $bkCard['availability']['quantity']],
        'Bookshop listing: a book is in stock through its paperbacks (5) and hardcovers (2), not "out of stock" for an empty product row');
    $bkCount('BK-TS-PB', 0, 'bk-zero-pb');
    $bkCount('BK-TS-HB', 0, 'bk-zero-hb');
    $bkCard = StorefrontViewModel::productCard($bkRow);
    assert_equals([true, true, 0], [$bkCard['availability']['purchasable'], $bkCard['availability']['in_stock'], $bkCard['availability']['quantity']],
        'Bookshop listing: with the print editions sold out the e-book keeps the book for sale');
    $pdo->exec("UPDATE variants SET availability = 'disabled' WHERE id = 98811");
    $bkCard = StorefrontViewModel::productCard($bkRow);
    assert_true(!$bkCard['availability']['purchasable'] && !$bkCard['availability']['in_stock'], 'Bookshop listing: nothing to buy shows out of stock');
    $pdo->exec("UPDATE variants SET availability = 'active' WHERE id = 98811");
    $bkCount('BK-TS-PB', 5, 'bk-restock-pb');
    $bkCount('BK-TS-HB', 2, 'bk-restock-hb');
    $bkDetail = StorefrontViewModel::product($bkRow);
    assert_equals(['BK-TS-EBOOK' => true, 'BK-TS-PB' => false, 'BK-TS-HB' => false], array_column($bkDetail['product']['variants'] ?? [], 'digital', 'sku'),
        'Bookshop product page: each format says whether it is a download');
    assert_equals([true, true, true], array_map(static fn(array $v): bool => (bool)($v['availability']['in_stock'] ?? false), $bkDetail['product']['variants'] ?? []),
        'Bookshop product page: the e-book and both print editions are in stock');
} catch (Throwable $e) { $bkFail('listing', $e); }

try {
    assert_true(CatalogScope::ownsSku('BK-TS-EBOOK') && !CatalogScope::ownsSku('BK-NOPE'), 'Bookshop: a variant SKU of the catalog can carry a digital asset');
    assert_true(in_array('BK-TS-EBOOK', array_column(CatalogScope::digitalAssets(), 'sku'), true), 'Bookshop: the admin lists the e-book asset');
    $bkAsset = (int)$pdo->query("SELECT id FROM digital_assets WHERE sku = 'BK-TS-EBOOK'")->fetchColumn();
    assert_true(CatalogScope::setDigitalAssetActive($bkAsset, 1), 'Bookshop: the admin can switch the e-book asset');
} catch (Throwable $e) { $bkFail('digital assets', $e); }

try {
    $bkRows = [];
    foreach (ProductVariants::forEditor(98801) as $bkVariant) {
        $bkRows[(string)$bkVariant['id']] = ['sku' => $bkVariant['sku'], 'name' => $bkVariant['name'], 'attrs' => $bkVariant['attrs_text'],
            'price_absolute' => (string)$bkVariant['price_absolute'], 'price_delta' => '0', 'stock' => (string)$bkVariant['stock'],
            'stock_loaded' => (string)$bkVariant['stock'], 'availability' => 'active', 'inventory_policy' => 'inherit', 'sort_order' => (string)$bkVariant['sort_order']]
            + ((int)$bkVariant['digital'] === 1 ? ['digital' => '1'] : []);
    }
    $bkRows['98811']['stock'] = '7'; // a download has no stock to book, whatever the form says
    $bkRows['new'] = ['sku' => 'BK-TS-AUDIO', 'name' => 'Audiobook', 'attrs' => 'format=Audiobook', 'price_absolute' => '899', 'price_delta' => '0',
        'stock' => '0', 'availability' => 'active', 'inventory_policy' => 'inherit', 'sort_order' => '3', 'digital' => '1'];
    $bkValidated = ProductVariants::validate(98801, $bkRows);
    assert_true(is_array($bkValidated), 'Bookshop: the variant editor accepts the digital flag (' . (is_string($bkValidated) ? $bkValidated : 'ok') . ')');
    if (is_array($bkValidated)) {
        assert_equals([], ProductVariants::save(98801, $bkValidated, 'simple'), 'Bookshop: saving the variants books no stock for downloads');
        assert_equals(['BK-TS-AUDIO' => 1, 'BK-TS-EBOOK' => 1, 'BK-TS-HB' => 0, 'BK-TS-PB' => 0],
            array_map('intval', $pdo->query("SELECT sku, digital FROM variants WHERE product_id = 98801 ORDER BY sku")->fetchAll(PDO::FETCH_KEY_PAIR)),
            'Bookshop: the editor stores which variants are downloads');
        assert_equals(0, (int)$pdo->query("SELECT COUNT(*) FROM warehouse_stock WHERE sku IN ('BK-TS-EBOOK', 'BK-TS-AUDIO')")->fetchColumn(),
            'Bookshop: downloads stay out of the warehouse');
    }
} catch (Throwable $e) { $bkFail('variant editor', $e); }
