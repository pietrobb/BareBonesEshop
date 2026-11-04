<?php

// Review round 12, low findings of the bookshop delta: the transfer reminder day ("+-2 days"), the VAT of an e-book
// (its own category; a download next to goods collected in the store is supplied where the consumer lives, OSS), the
// marketing feed and the catalog price rules of books whose stock and formats are on their variants, and a download
// variant without its file (not for sale, flagged in the variant editor and by the doctor).
echo "\n--- Audit round 12: low findings (reminder day, e-book VAT, feed, price rules, download without file) ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r12lFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R12 low {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};

// A negative day offset: "+-2 days" is not a relative date every timelib reads as minus two days.
try {
    $r12lNow = strtotime('2026-11-10 10:00:00 UTC');
    $r12lDay = static fn(int $days): string => (new DateTimeImmutable(ShopTime::date('Y-m-d', $r12lNow) . ' 12:00:00', new DateTimeZone('UTC')))->modify(sprintf('%+d days', $days))->format('Y-m-d');
    foreach ([-3, -1, 0, 2] as $r12lOffset) {
        assert_equals($r12lDay($r12lOffset) . ' 23:59:59', ShopTime::date('Y-m-d H:i:s', ShopTime::endOfDay($r12lNow, $r12lOffset)),
            "R12 low: ShopTime::endOfDay with {$r12lOffset} days ends that local day (the transfer reminder starts the day before)");
    }
} catch (Throwable $e) { $r12lFail('ShopTime', $e); }

// E-book VAT.
try {
    VatService::reset();
    assert_equals('ebooks', VatService::digitalCategory('books'), 'R12 low: a download of a book is taxed in its own category (ebooks)');
    assert_equals(['standard', 'standard'], [VatService::digitalCategory('standard'), VatService::digitalCategory('Standard')], 'R12 low: other classes stay as they are');
    assert_equals([5.0, 5.0, 7.0], [VatService::rate('SK', 'ebooks', '2026-11-02'), VatService::rate('SK', 'books', '2026-11-02'), VatService::rate('DE', 'ebooks', '2026-11-02')],
        'R12 low: without a separate e-book rate, ebooks takes the books rate (SK 5 %, DE books class 7 %)');
    $r12lPickup = ['seller_country' => 'SK', 'customer_country' => 'SK', 'seller_oss_enabled' => true, 'digital_customer_country' => 'DE', 'tax_provider' => 'vat'];
    assert_equals(['country' => 'DE', 'scheme' => 'oss'], VatService::digitalSupply($r12lPickup), 'R12 low: with OSS, a German consumer\'s e-book next to goods collected in Slovakia is taxed in Germany');
    assert_equals([null, null, null], [
        VatService::digitalSupply(['seller_oss_enabled' => false] + $r12lPickup),
        VatService::digitalSupply(['digital_customer_country' => 'SK'] + $r12lPickup),
        VatService::digitalSupply(['digital_customer_country' => 'US'] + $r12lPickup),
    ], 'R12 low: no OSS, a domestic buyer or a non-EU declared country keep the seller\'s rate');
    assert_equals('reverse_charge', VatService::digitalSupply(['is_b2b' => true, 'customer_vat_id_valid' => true] + $r12lPickup)['scheme'] ?? null,
        'R12 low: a business buyer with a valid VAT ID is not an OSS consumer (round 13: reverse charge)');

    $r12lLine = static fn(string $sku, string $type): array => ['product_id' => 1, 'variant_id' => null, 'sku' => $sku, 'price' => 1000, 'qty' => 1, 'total' => 1000,
        'available' => true, 'currency_mismatch' => false, 'product_type' => $type, 'vat_class' => 'books', 'currency' => 'eur',
        'price_formatted' => '', 'total_formatted' => ''];
    $r12lSummary = ['items' => [$r12lLine('R12L-EBOOK', 'digital'), $r12lLine('R12L-PB', 'simple')], 'subtotal' => 2000, 'currency' => 'eur'];
    $r12lCart = Cart::applyVat($r12lSummary, $r12lPickup);
    assert_equals([7.0, 5.0], array_map(static fn(array $i): float => (float)$i['vat_rate'], $r12lCart['items']),
        'R12 low: the cart taxes the e-book at the German rate and the collected paperback at the Slovak books rate');
    $r12lCart = Cart::applyVat($r12lSummary, ['digital_customer_country' => ''] + $r12lPickup);
    assert_equals([5.0, 5.0], array_map(static fn(array $i): float => (float)$i['vat_rate'], $r12lCart['items']), 'R12 low: without the buyer\'s country both lines stay Slovak');

    $r12lItems = Cart::applyVat($r12lSummary, $r12lPickup)['items'];
    $r12lSnapshot = VatService::buildSnapshot($r12lItems, $r12lPickup, ['currency' => 'eur', 'effective_date' => '2026-11-02']);
    $r12lProducts = array_values(array_filter($r12lSnapshot['components'], static fn(array $c): bool => $c['type'] === 'product'));
    assert_equals([[700, 'DE'], [500, null]], array_map(static fn(array $c): array => [(int)$c['rate_basis_points'], $c['rate_country'] ?? null], $r12lProducts),
        'R12 low: the order\'s financial snapshot charges the same rates and names the e-book\'s country');
    VatService::validateSnapshot($r12lSnapshot);
    assert_true(true, 'R12 low: the mixed-country snapshot passes its own invariants');

    // A country with a separate e-book rate.
    $r12lCfg = VatService::config();
    $r12lCfg['rates']['SK']['categories']['ebooks'] = 23;
    VatService::setConfig($r12lCfg);
    $r12lCart = Cart::applyVat($r12lSummary, ['digital_customer_country' => ''] + $r12lPickup);
    assert_equals([23.0, 5.0], array_map(static fn(array $i): float => (float)$i['vat_rate'], $r12lCart['items']),
        'R12 low: categories.ebooks taxes the e-book apart from the printed book');
} catch (Throwable $e) { $r12lFail('e-book VAT', $e); }
finally { VatService::reset(); }

// A book whose formats are variants: stock on the variants, an e-book variant, a catalog rule for downloads.
$r12lCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, weight_g) VALUES
    (97901, 'default', {$r12lCategory}, 'R12L-BOOK', 'r12l-book', 'simple', 'R12 Low Book', 1500, 'eur', 0, 'active', 'deny', 300)");
$pdo->exec("INSERT INTO variants (id, product_id, sku, name, attrs, price_absolute, stock, sort_order, digital) VALUES
    (97911, 97901, 'R12L-BOOK-EB', 'eBook', '{\"format\":\"eBook\"}', 600, 0, 0, 1),
    (97912, 97901, 'R12L-BOOK-PB', 'Paperback', '{\"format\":\"Paperback\"}', 1500, 3, 1, 0)");

try {
    $r12lCsv = fopen('php://memory', 'w+');
    MarketingFeed::writeMeta($r12lCsv, 'https://shop.example.test');
    rewind($r12lCsv);
    $r12lFeed = [];
    while (($r12lRow = fgetcsv($r12lCsv, null, ',', '"', '\\')) !== false) $r12lFeed[$r12lRow[0]] = $r12lRow[3] ?? '';
    fclose($r12lCsv);
    // R15: a product with variants is listed per variant (item_group_id R12L-BOOK), so the paperback carries the stock.
    assert_equals(['in stock', null], [$r12lFeed['R12L-BOOK-PB'] ?? null, $r12lFeed['R12L-BOOK'] ?? null],
        'R12 low: the feed lists a book with paperbacks in stock as in stock (its product row holds no stock)');
} catch (Throwable $e) { $r12lFail('marketing feed', $e); }

// Round 13: the price map those rules fed (cache/prices/*.json) was read by nothing and is gone; the doctor now says
// that a pricing.json catalog rule is not applied (AuditRound13LowRegression).
try {
    $r12lRule = ['name' => 'eBooks -50 %', 'active' => true, 'conditions' => ['product_type' => 'digital'], 'discount_type' => 'percent', 'discount_value' => 50];
    $r12lCheck = Setup::legacyPricingCheck(['catalog_rules' => [$r12lRule]]);
    assert_true($r12lCheck['status'] === 'warn' && str_contains($r12lCheck['message'], 'eBooks -50 %'),
        'R12 low: a pricing.json catalog rule on product_type "digital" is reported as not applied (no price used it)');
} catch (Throwable $e) { $r12lFail('catalog price rules', $e); }

// A download variant without its file.
try {
    $r12lStates = static fn(): array => VariantAvailability::variantStatesMany([97901])[97901] ?? [];
    assert_true(!VariantAvailability::isPurchasable(97901, 97911) && VariantAvailability::isPurchasable(97901, 97912),
        'R12 low: an e-book variant without its file is not for sale (the paperback is)');
    $_SESSION['cart'] = [];
    Cart::add(97901, 97911, 1);
    assert_true(empty(Cart::enriched()['items'][0]['available']), 'R12 low: a cart line of an e-book without its file cannot be checked out');
    $r12lDoctor = Setup::digitalFilesCheck($pdo);
    assert_true($r12lDoctor['status'] === 'warn' && (int)$r12lDoctor['message'] >= 1 && SetupChecklist::fixPage($r12lDoctor['name']) === 'digital_assets',
        'R12 low: the doctor (and the dashboard checklist, linking Digital files) names the e-book without a file');
    require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/CatalogEditor.php';
    $r12lEditor = productVariantsHtml(['id' => 97901]);
    assert_equals(1, substr_count($r12lEditor, 'data-variant-no-file'), 'R12 low: the variant editor flags the e-book row (and only it)');

    $pdo->exec("INSERT INTO digital_assets (sku, storage_path, download_name, content_type) VALUES ('R12L-BOOK-EB', 'default/r12l.epub', 'r12l.epub', 'application/epub+zip')");
    assert_true(VariantAvailability::isPurchasable(97901, 97911) && !empty(Cart::enriched()['items'][0]['available']) && ($r12lStates()['purchasable'] ?? false),
        'R12 low: with its file the e-book is for sale again');
    assert_true((int)Setup::digitalFilesCheck($pdo)['message'] === (int)$r12lDoctor['message'] - 1 && substr_count(productVariantsHtml(['id' => 97901]), 'data-variant-no-file') === 0,
        'R12 low: the doctor and the editor stop flagging it');
    $_SESSION['cart'] = [];
} catch (Throwable $e) { $r12lFail('download without file', $e); }
finally {
    $pdo->exec("DELETE FROM digital_assets WHERE sku = 'R12L-BOOK-EB'");
    $pdo->exec('DELETE FROM variants WHERE product_id = 97901');
    $pdo->exec('DELETE FROM products WHERE id = 97901');
}
