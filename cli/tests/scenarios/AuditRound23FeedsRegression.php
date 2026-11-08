<?php

// Review round 23 (2026-11-08), medium "feeds" (goal #366), reproduced by the reviewer:
// - a CSV supplier feed's price of only spaces was read as 0: an existing 10 € product became free and a new one was
//   created free, with the sync "ok" (the CSV import, the admin and XML feeds already treated it as missing).
// - one C0 control character (\x01, \x0B) in a product name made the whole Google feed invalid XML.
// - a HU store of the shared EUR catalog listed a simple product without a HUF price "in stock, 10.00 EUR"; the cart
//   refuses it.
// Low: a feed's link to a variant that cannot be bought now opened the page at another variant's price; a CSV slug
// with a quote ended the href attribute of the XML sitemap.
echo "\n--- R23: blank CSV feed prices are missing, the Google feed is valid XML and offers what the store sells ---\n";

[$r23fSavedDb, $r23fSavedDriver] = [Db::get(), Db::driver()];
$r23fStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r23fSavedStores = $r23fStoresProp->getValue();
$r23fPrevious = StoreContext::current();
$r23fLocale = I18n::locale();
$r23fGet = $_GET;
$r23fLock = sys_get_temp_dir() . '/bbe-r23f-' . getmypid() . '.lock';
$r23fDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
try {
    (new Migrator($r23fDb, TEST_CLI_DIR . '/../migrations', $r23fLock))->apply(static fn($m) => null);
    Db::init($r23fDb, 'sqlite');
    $r23fSample = json_decode((string)file_get_contents(dirname(TEST_CLI_DIR) . '/stores.example.json'), true);
    $r23fStoresProp->setValue(null, $r23fSample);
    StoreContext::activate(StoreContext::resolve($r23fSample, null, null, 'default', 'storefront'));
    I18n::setLocale('en');
    $r23fPrice = static fn(string $sku) => $r23fDb->query('SELECT price FROM products WHERE sku = ' . $r23fDb->quote($sku))->fetchColumn();

    // A CSV supplier feed: a price cell of spaces is a missing price.
    $r23fWh = Warehouse::save(['code' => 'r23f', 'name' => 'R23F', 'is_default' => 0]);
    $r23fFeed = Warehouse::feedById(Warehouse::saveFeed(['code' => 'r23f-csv', 'name' => 'r23f-csv', 'warehouse_id' => $r23fWh,
        'url' => 'https://example.com/r23f.csv', 'format' => 'csv', 'import_mode' => FeedImport::MODE_UPSERT,
        'field_map' => ['SKU' => 'sku', 'NAME' => 'name', 'PRICE' => ['field' => 'price', 'transform' => 'price_to_cents'], 'STOCK' => 'stock']]));
    FeedImport::applyItems($r23fFeed, [['SKU' => 'R23F-OLD', 'NAME' => 'R23F old', 'PRICE' => '10.00', 'STOCK' => '1']]);
    $r23fRun = FeedImport::applyItems($r23fFeed, [['SKU' => 'R23F-OLD', 'NAME' => 'R23F old', 'PRICE' => '   ', 'STOCK' => '4'],
        ['SKU' => 'R23F-NEW', 'NAME' => 'R23F new', 'PRICE' => " \t ", 'STOCK' => '1']]);
    assert_equals([1000, false], [(int)$r23fPrice('R23F-OLD'), $r23fPrice('R23F-NEW')],
        'R23 CSV feed: a price of spaces keeps the existing product\'s 10 € and creates no free product (it was 0 and a new free one)');
    assert_equals(['Item #1: Missing required field: price'], $r23fRun['errors'],
        'R23 CSV feed: the product not created for its missing price is reported');

    // The Google feed: a control character in a name, and an item the HU store does not sell.
    $r23fDb->exec("INSERT INTO categories (store_id, name, slug, path) VALUES ('default', 'R23F', 'r23f', 'r23f')");
    $r23fCategory = (int)$r23fDb->lastInsertId();
    $r23fDb->prepare("INSERT INTO products (store_id, category_id, sku, name, slug, price, currency, stock, status) VALUES
        ('default', ?, 'R23F-CTRL', ?, 'r23f-ctrl', 1500, 'eur', 3, 'active'),
        ('default', ?, 'R23F-QUOTE', 'R23F quote', 'r23f-\"quote', 900, 'eur', 3, 'active'),
        ('default', ?, 'R23F-TEE', 'R23F tee', 'r23f-tee', 1200, 'eur', 0, 'active')")
        ->execute([$r23fCategory, "R23F\x01 control\x0B name", $r23fCategory, $r23fCategory]);
    $r23fTee = (int)$r23fDb->query("SELECT id FROM products WHERE sku = 'R23F-TEE'")->fetchColumn();
    $r23fVariant = $r23fDb->prepare("INSERT INTO variants (product_id, sku, name, attrs, price_delta, price_absolute, stock, sort_order) VALUES (?, ?, ?, '{}', 0, ?, ?, ?)");
    $r23fVariant->execute([$r23fTee, 'R23F-TEE-S', 'S', null, 5, 1]);
    $r23fVariant->execute([$r23fTee, 'R23F-TEE-XL', 'XL', 1900, 0, 2]);
    $r23fXl = (int)$r23fDb->query("SELECT id FROM variants WHERE sku = 'R23F-TEE-XL'")->fetchColumn();

    $r23fItems = static function (): ?array {
        $xml = @simplexml_load_string(MarketingFeed::google('https://r23f.example.test', I18n::locale()));
        if ($xml === false) return null;
        $items = [];
        foreach ($xml->channel->item as $item) {
            $g = $item->children('http://base.google.com/ns/1.0');
            $items[(string)$g->id] = [(string)$g->title, (string)$g->price, (string)$g->availability];
        }
        return $items;
    };
    $r23fSk = $r23fItems();
    assert_equals(['R23F control name', '15.00 EUR', 'in stock'], $r23fSk['R23F-CTRL'] ?? null,
        'R23 Google feed: a name with \x01 and \x0B is valid XML without them (the whole feed was refused)');
    assert_equals(["x y\t\n\r", 'ok ?'], [MarketingFeed::xmlText("x\x00\x1F y\t\n\r\x0C"), MarketingFeed::xmlText("ok \xFF")],
        'R23 feeds: xmlText drops the C0 controls but tab and line breaks, and broken UTF-8');

    StoreContext::activate(StoreContext::resolve($r23fSample, '/hu/', '', 'hu', 'storefront'));
    $r23fHu = $r23fItems() ?? [];
    assert_true(!isset($r23fHu['R23F-CTRL']) && !isset($r23fHu['R23F-QUOTE']),
        'R23 Google feed: the HU store does not offer a simple product without a HUF price (it was "in stock, 15.00 EUR") ('
        . json_encode($r23fHu) . ')');

    // The feed links the XL variant out of stock at 19.00; its page now shows that price, not the S variant's 12.00.
    StoreContext::activate(StoreContext::resolve($r23fSample, null, null, 'default', 'storefront'));
    $_GET = ['variant' => (string)$r23fXl];
    $r23fPage = StorefrontViewModel::product($r23fDb->query("SELECT * FROM products WHERE id = {$r23fTee}")->fetch())['product'];
    assert_equals([['R23F-TEE-XL'], false], [array_values(array_column(array_filter($r23fPage['variants'], static fn(array $v): bool => $v['selected']), 'sku')),
        $r23fPage['variants'][1]['availability']['purchasable']], 'R23 variant link: the page opens on the out-of-stock XL variant the feed links');
    assert_true(str_contains((string)$r23fPage['price']['formatted'], '19'),
        'R23 variant link: at the XL variant\'s price (it showed the S variant\'s) (' . $r23fPage['price']['formatted'] . ')');
    $_GET = [];

    // The sitemap stays well-formed with a quote in a slug.
    $r23fSitemap = Sitemap::xml('https://r23f.example.test');
    assert_true(@simplexml_load_string($r23fSitemap) !== false && str_contains($r23fSitemap, 'r23f-&quot;quote'),
        'R23 sitemap: a slug with a quote is escaped in <loc> and href (it broke the XML)');
} catch (Throwable $e) {
    assert_true(false, 'R23 feeds (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $_GET = $r23fGet;
    Db::init($r23fSavedDb, $r23fSavedDriver);
    $r23fStoresProp->setValue(null, $r23fSavedStores);
    StoreContext::activate($r23fPrevious);
    I18n::setLocale($r23fLocale);
    @unlink($r23fLock);
}
