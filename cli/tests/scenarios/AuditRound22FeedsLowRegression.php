<?php

// Review round 22 (2026-11-07), low "import and feeds":
// - "price kept" was listed for SKUs not in the catalog (skipped anyway) and when the price was updated and only the
//   compare price was refused; a supplier feed filled the error list with it.
// - "decimal" on a mapping was saved unchecked ("decimal": ";" refused every price at the next sync) and documented only
//   in the CHANGELOG.
// - a CSV import's ambiguous price advised declaring "the feed's decimal mark", which a CSV import does not have.
// - with the decimal mark "," a "0.500" was read as 500,00 (grouped digits starting with a zero).
// - the feed status and the refused items were shown in English in the SK and HU admin.
echo "\n--- R22: feed messages only where a price was kept, \"decimal\" checked and documented, translated feed status ---\n";

$r22fRefused = static function (callable $call): string {
    try {
        $call();
        return '';
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
};
assert_equals(['Not a price: 0.500', 150000, 50, 'Not a price: 0.500.000', 50000000],
    [$r22fRefused(static fn() => Import::parsePriceToCents('0.500', ',')), Import::parsePriceToCents('1.500', ','), Import::parsePriceToCents('0,500', ','),
        $r22fRefused(static fn() => Import::parsePriceToCents('0.500.000')), Import::parsePriceToCents('500.000,00')],
    'R22 prices: with the decimal mark "," a "0.500" is refused (it was 500,00); grouped digits never start with a zero');

assert_equals([null, 'P', 'Q', 'W'], [
    FeedImport::badDecimalMapping(['SKU' => 'sku', 'P' => ['field' => 'price', 'transform' => 'price_to_cents', 'decimal' => ',']]),
    FeedImport::badDecimalMapping(['P' => ['field' => 'price', 'transform' => 'price_to_cents', 'decimal' => ';']]),
    FeedImport::badDecimalMapping(['Q' => ['field' => 'price', 'transform' => 'price_to_cents', 'decimal' => null]]),
    FeedImport::badDecimalMapping(['W' => ['field' => 'weight_g', 'transform' => 'weight_to_grams', 'decimal' => ',']]),
], 'R22 feeds: a "decimal" other than "," or ".", or one on a mapping that is not price_to_cents, is found');
$r22fOps = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Operations.php');
$r22fReadme = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/README.md');
assert_true(str_contains($r22fOps, 'FeedImport::badDecimalMapping($fieldMap)') && str_contains($r22fOps, "I18n::t('admin.feed.bad_decimal')")
    && str_contains($r22fReadme, '"transform": "price_to_cents", "decimal": ","') && str_contains($r22fReadme, 'A CSV import has no'),
    'R22 feeds: saving a feed refuses an unknown "decimal", and the README documents it');

[$r22fSavedDb, $r22fSavedDriver] = [Db::get(), Db::driver()];
$r22fStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r22fSavedStores = $r22fStoresProp->getValue();
$r22fPrevious = StoreContext::current();
$r22fLocale = I18n::locale();
$r22fLock = sys_get_temp_dir() . '/bbe-r22f-' . getmypid() . '.lock';
$r22fDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
try {
    (new Migrator($r22fDb, TEST_CLI_DIR . '/../migrations', $r22fLock))->apply(static fn($m) => null);
    Db::init($r22fDb, 'sqlite');
    $r22fSample = json_decode((string)file_get_contents(dirname(TEST_CLI_DIR) . '/stores.example.json'), true);
    $r22fStoresProp->setValue(null, $r22fSample);
    StoreContext::activate(StoreContext::resolve($r22fSample, null, null, 'default', 'storefront'));
    $r22fPrice = static fn(string $sku): array => array_map('intval', (array)$r22fDb->query('SELECT price, compare_price FROM products WHERE sku = '
        . $r22fDb->quote($sku))->fetch(PDO::FETCH_NUM));

    // The CSV import's ambiguous price no longer points at a feed setting.
    $r22fCsv = tempnam(sys_get_temp_dir(), 'r22f') . '.csv';
    file_put_contents($r22fCsv, "sku,name,price,category\nR22F-CSV,R22F CSV,199.000,R22F\n");
    $r22fCsvRun = Import::csv($r22fCsv);
    @unlink($r22fCsv);
    assert_equals(['Line 2 (SKU: R22F-CSV): Not a price: 199.000 (ambiguous: write e.g. 199.00 or 199000)'], $r22fCsvRun['errors'],
        'R22 import: a CSV row\'s ambiguous price does not advise declaring a feed\'s decimal mark');

    $r22fWh = Warehouse::save(['code' => 'r22f', 'name' => 'R22F', 'is_default' => 0]);
    $r22fFeed = static fn(string $code, string $mode): array => Warehouse::feedById(Warehouse::saveFeed(['code' => $code, 'name' => $code,
        'warehouse_id' => $r22fWh, 'url' => 'https://example.com/r22f.xml', 'format' => 'xml', 'import_mode' => $mode,
        'field_map' => ['SKU' => 'sku', 'NAME' => 'name', 'PRICE' => 'price', 'OLD' => 'compare_price', 'STOCK' => 'stock']]));
    $r22fUpsert = $r22fFeed('r22f-upsert', FeedImport::MODE_UPSERT);
    FeedImport::applyItems($r22fUpsert, [['SKU' => 'R22F-A', 'NAME' => 'R22F A', 'PRICE' => '10.00', 'OLD' => '15.00', 'STOCK' => '1']]);

    // Upsert: the price is updated and only the compare price is refused, so the compare price is what was kept.
    $r22fUp = FeedImport::applyItems($r22fUpsert, [['SKU' => 'R22F-A', 'NAME' => 'R22F A', 'PRICE' => '12.00', 'OLD' => '19.000', 'STOCK' => '1'],
        ['SKU' => 'R22F-NEW', 'NAME' => 'R22F new', 'PRICE' => '2.000', 'STOCK' => '1']]);
    assert_equals([[1200, 1500], 2], [$r22fPrice('R22F-A'), $r22fUp['prices_refused']], 'R22 feed upsert: the price is updated, the refused compare price kept');
    assert_true(str_starts_with($r22fUp['errors'][0] ?? '', 'Item #0 (SKU R22F-A): price refused, compare price kept: Not a price: 19.000 (ambiguous')
        && str_ends_with($r22fUp['errors'][0] ?? '', 'or declare the feed\'s decimal mark: "transform": "price_to_cents", "decimal": "," or ".")')
        && str_starts_with($r22fUp['errors'][1] ?? '', 'Item #1 (SKU R22F-NEW): price refused, product not created'),
        'R22 feed upsert: it says the compare price was kept (it said "price kept"), a feed\'s ambiguous price names "decimal" ('
        . implode(' | ', $r22fUp['errors']) . ')');

    // Price and stock: an unknown SKU is skipped without a "price kept"; its compare price is never written, so not reported.
    $r22fPs = FeedImport::applyItems($r22fFeed('r22f-ps', FeedImport::MODE_PRICE_STOCK), [
        ['SKU' => 'R22F-GONE', 'NAME' => 'x', 'PRICE' => '199.000', 'STOCK' => '1'],
        ['SKU' => 'R22F-A', 'NAME' => 'R22F A', 'PRICE' => '11.00', 'OLD' => '19.000', 'STOCK' => '1'],
        ['SKU' => 'R22F-A', 'NAME' => 'R22F A', 'PRICE' => '199.000', 'STOCK' => '1']]);
    assert_equals([1, 1, 1, 1100], [$r22fPs['skipped'], $r22fPs['prices_refused'], count($r22fPs['errors']), $r22fPrice('R22F-A')[0]],
        'R22 feed price_stock: no message for a SKU not in the catalog nor for a compare price it never writes (was 3 messages)');
    assert_true(str_starts_with($r22fPs['errors'][0] ?? '', 'Item #2 (SKU R22F-A): price refused, price kept'),
        'R22 feed price_stock: "price kept" only where the price was refused (' . ($r22fPs['errors'][0] ?? '') . ')');

    // The admin shows the status and every message of these syncs in its language; an unknown line stays as it is.
    $r22fLines = array_merge($r22fUp['errors'], $r22fPs['errors'], ['CSV row 7: column count does not match the header', 'Item #3: Price must not be negative']);
    foreach (['sk', 'hu', 'cs', 'de', 'pl', 'es'] as $r22fLang) {
        I18n::setLocale($r22fLang);
        $r22fSame = array_filter($r22fLines, static fn(string $line): bool => str_contains(FeedMessages::error($line), 'price refused')
            || str_contains(FeedMessages::error($line), 'Not a price') || str_contains(FeedMessages::error($line), 'column count')
            || str_contains(FeedMessages::error($line), 'must not'));
        assert_equals([], array_values($r22fSame), "R22 admin {$r22fLang}: every refused feed item is shown translated");
        foreach (['ok', 'partial: 3 errors (2 prices refused)', 'stopped after 101 errors', 'error: download failed'] as $r22fStatus) {
            assert_true(FeedMessages::status($r22fStatus) !== $r22fStatus || $r22fStatus === 'ok', "R22 admin {$r22fLang}: the status \"{$r22fStatus}\" is translated");
        }
    }
    I18n::setLocale('sk');
    assert_equals(['Čiastočne: 3 chýb (2 cien odmietnutých)', 'Chyba: 404 from supplier',
        'Položka č. 0 (SKU R22F-A): cena odmietnutá, porovnávacia cena ponechaná: Nie je cena: 19.000 (nejednoznačné: napíšte napr. 199.00 alebo 199000, '
        . 'alebo uveďte desatinný oddeľovač feedu: "transform": "price_to_cents", "decimal": "," alebo ".")', 'something unforeseen'],
        [FeedMessages::status('partial: 3 errors (2 prices refused)'), FeedMessages::status('error: 404 from supplier'),
            FeedMessages::error($r22fUp['errors'][0] ?? ''), FeedMessages::error('something unforeseen')],
        'R22 admin sk: status and refused items in Slovak, an unknown line as it is');
    I18n::setLocale('en');
    assert_equals($r22fLines, array_map(FeedMessages::error(...), $r22fLines), 'R22 admin en: the English admin shows the messages unchanged');
    assert_true(substr_count($r22fOps, 'FeedMessages::status(') === 3 && str_contains($r22fOps, 'FeedMessages::error((string)$feedError)')
        && str_contains($r22fOps, 'array_map(FeedMessages::error(...), $result[\'errors\'] ?? [])'),
        'R22 admin: the feed list, the feed page and "Sync now" show status and errors through FeedMessages');
} finally {
    I18n::setLocale($r22fLocale);
    Db::init($r22fSavedDb, $r22fSavedDriver);
    @unlink($r22fLock);
    $r22fStoresProp->setValue(null, $r22fSavedStores);
    StoreContext::activate($r22fPrevious);
}
