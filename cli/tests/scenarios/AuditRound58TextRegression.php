<?php

// Review round 23 (2026-11-24, main @ bd743edb), lows:
//  - N2: the checkout's street, city, postcode, company and billing name, the address book and a manual admin order's name
//    accepted a value of invisible characters alone (U+200B, U+3164, a combining mark): trimmed with trim() only. Slovak and
//    Czech postcodes were not checked ("8110" passed).
//  - N4: control characters inside a product name ("\x01", ESC, DEL, U+2028) were stored from the product editor, the CSV
//    import and API v1; the editor's message named the field "name".
//  - admin-only attributes: "download  url" and "Download--URL" stayed public (separator runs), cached product and filter
//    JSON written before was returned as cached.
//  - UX: the default theme showed a language switcher with one language; the product type select read "Downloadable"
//    instead of the catalog.json name; a fresh shop's product editor required a main category without saying how to
//    get one; "pri 1 produktoch", "na 1 objednávkach", "po 1 chybách"; a decomposed "Ján Novák" became "ja-n-nova-k".
echo "\n--- Audit round 58: invisible address fields, postcodes, control characters in names, admin-only spellings, small UX ---\n";

$pdo ??= test_commerce_fixture();
$r58tRepo = dirname(TEST_CLI_DIR);
$r58tCall = static function (string $class, string $method, array $args) {
    $m = new ReflectionMethod($class, $method);
    $m->setAccessible(true);
    return $m->invokeArgs(null, $args);
};
$r58tFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R58 {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r58tLocale = I18n::locale();
Bootstrap::initStoreContext('/', '', 'default');
if (!function_exists('checkoutExecute')) require_once $r58tRepo . '/src/Http/ApiHandlers.php';
$r58tBlank = ["\u{200B}", "\u{3164}", "\u{0301}", " \u{200B}\u{00A0} "];

// --- N2: typedText() as before for real text ------------------------------------------------------------------------------
assert_equals(["Jan\u{00A0}Novák", "Nguye\u{0302}\u{0303}n", "\u{0645}\u{064F}\u{062D}\u{064E}\u{0645}\u{0651}\u{062F}", "\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947}", "👨\u{200D}👩\u{200D}👧"],
    array_map([Security::class, 'typedText'], ["Jan\u{00A0}Novák", "Nguye\u{0302}\u{0303}n", "\u{0645}\u{064F}\u{062D}\u{064E}\u{0645}\u{0651}\u{062F}", "\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947}", "👨\u{200D}👩\u{200D}👧"]),
    'R58 N2: an NBSP inside, decomposed Vietnamese, Arabic harakat, a Hindi virama and a ZWJ emoji stay text');

// --- N2: postcodes ----------------------------------------------------------------------------------------------------------
try {
assert_equals(['81101', '81101', '11000', null, null, null, '10115', '1010', 'SW1A 1AA'],
    [OrderAddress::postcode('81101', 'SK'), OrderAddress::postcode('811 01', 'sk'), OrderAddress::postcode("110\u{00A0}00", 'CZ'), OrderAddress::postcode('8110', 'SK'),
        OrderAddress::postcode('811 011', 'CZ'), OrderAddress::postcode('ABCDE', 'SK'), OrderAddress::postcode('10115', 'DE'), OrderAddress::postcode('1010', 'AT'), OrderAddress::postcode('SW1A 1AA', 'GB')],
    'R58 N2: a Slovak or Czech postcode is five digits, "811 01" stored as 81101; other countries\' are taken as given');
} catch (Throwable $e) { $r58tFail('postcodes', $e); }

// --- N2: checkout ------------------------------------------------------------------------------------------------------------
$r58tSession = $_SESSION ?? [];
try {
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    $r58tCategory = (int)$pdo->query("SELECT id FROM categories WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, weight_g) VALUES ('default', ?, 'R58T-PHYS', 'r58t-phys', 'R58 Physical', 1000, 50, 'active', 500)")
        ->execute([$r58tCategory ?: null]);
    $r58tWarehouse = (int)$pdo->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    if ($r58tWarehouse > 0) $pdo->prepare("INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, 'R58T-PHYS', 50, 0)")->execute([$r58tWarehouse]);
    unset($_SESSION['customer_id']);
    Cart::clear();
    Cart::add((int)$pdo->query("SELECT id FROM products WHERE sku = 'R58T-PHYS'")->fetchColumn(), null, 1);
    $r58tN = 0;
    $r58tCheckout = static function (array $fields) use (&$r58tN): string {
        $r58tN++;
        $result = checkoutExecute($fields + ['name' => 'R58 Buyer', 'email' => "r58t-{$r58tN}@example.test", 'country' => 'SK', 'street' => 'Hlavná 1', 'city' => 'Bratislava',
            'zip' => '81101', 'payment_method' => 'bank_transfer', 'shipping_method' => 'r58-none']);
        http_response_code(200);
        return (string)($result['error_code'] ?? 'other');
    };
    $r58tCodes = [];
    foreach (['street', 'city', 'zip'] as $r58tField) {
        foreach ($r58tBlank as $r58tValue) $r58tCodes[] = $r58tCheckout([$r58tField => $r58tValue]);
    }
    assert_equals(array_fill(0, 12, 'checkout.error.address_required'), $r58tCodes,
        'R58 N2 checkout: a street, city or postcode of invisible or combining characters alone is missing (was: ordered with a blank address)');
    assert_equals(['checkout.error.zip_invalid', 'checkout.error.zip_invalid'], [$r58tCheckout(['zip' => '8110']), $r58tCheckout(['zip' => '811 0A', 'country' => 'CZ'])],
        'R58 N2 checkout: a Slovak or Czech postcode that is not five digits is refused');
    assert_true(!in_array($r58tCheckout(['zip' => '811 01']), ['checkout.error.zip_invalid', 'checkout.error.address_required'], true)
        && !in_array($r58tCheckout(['zip' => '10115', 'country' => 'DE']), ['checkout.error.zip_invalid', 'checkout.error.address_required'], true),
        'R58 N2 checkout: "811 01" and a German postcode pass the address checks');
    Cart::clear();
} catch (Throwable $e) { $r58tFail('checkout', $e); } finally { $_SESSION = $r58tSession; }

// Billing name, street, postcode; the company.
try {
    $r58tBilling = static fn(array $fields): array => OrderAddress::billingFromInput($fields + ['billing_different' => '1', 'billing_name' => 'R58 Billing',
        'billing_street' => 'Fakturačná 3', 'billing_city' => 'Košice', 'billing_zip' => '04001', 'billing_country' => 'SK']);
    $r58tCodes = [];
    foreach ($r58tBlank as $r58tValue) $r58tCodes[] = $r58tBilling(['billing_name' => $r58tValue])['error_code'];
    $r58tCodes[] = $r58tBilling(['billing_street' => "\u{3164}"])['error_code'];
    $r58tCodes[] = $r58tBilling(['billing_zip' => '0400'])['error_code'];
    assert_equals([...array_fill(0, 5, 'checkout.error.billing_address_required'), 'checkout.error.zip_invalid'], $r58tCodes,
        'R58 N2 billing: a billing name or street of invisible characters alone is missing, a Slovak billing postcode is five digits');
    assert_equals('04001', $r58tBilling(['billing_zip' => '040 01'])['address']['zip'] ?? null, 'R58 N2 billing: "040 01" is stored as 04001');
} catch (Throwable $e) { $r58tFail('billing', $e); }

// --- N2: address book, manual order --------------------------------------------------------------------------------------------
try {
    $r58tAddress = ['type' => 'shipping', 'name' => 'Ján Novák', 'company' => '', 'street' => 'Hlavná 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK', 'phone' => ''];
    $r58tErrors = [];
    foreach (['name' => "\u{200B}", 'street' => "\u{3164}", 'city' => "\u{0301}", 'zip' => '8110'] as $r58tField => $r58tValue) {
        $r58tErrors[$r58tField] = Customers::addressErrors([$r58tField => $r58tValue] + $r58tAddress)[$r58tField] ?? null;
    }
    assert_equals(['name' => 'account.err_address_required', 'street' => 'account.err_address_required', 'city' => 'account.err_address_required', 'zip' => 'account.err_address_zip'],
        $r58tErrors, 'R58 N2 address book: a field of invisible characters alone is missing, a Slovak postcode is five digits (was: saved)');
    assert_equals([[], []], [Customers::addressErrors($r58tAddress), Customers::addressErrors(['zip' => '1010', 'country' => 'AT', 'city' => 'Wien'] + $r58tAddress)],
        'R58 N2 address book: a real address passes');
    $pdo->prepare("INSERT INTO customers (store_id, email, password_hash, name, status) VALUES ('default', 'r58t-book@example.test', 'x', 'R58 Book', 'active')")->execute();
    $r58tCustomer = (int)$pdo->lastInsertId();
    $r58tAddressId = Customers::addAddress($r58tCustomer, ['company' => "\u{200B}", 'zip' => '811 01'] + $r58tAddress);
    $r58tSaved = static fn(): array => array_values(array_filter(Customers::addresses($r58tCustomer), static fn(array $a): bool => (int)$a['id'] === $r58tAddressId))[0] ?? [];
    $r58tFirst = $r58tSaved();
    Customers::updateCheckedAddress($r58tCustomer, $r58tAddressId, ['zip' => '040 01']);
    assert_equals(['81101', '', '04001'], [(string)($r58tFirst['zip'] ?? ''), (string)($r58tFirst['company'] ?? ''), (string)($r58tSaved()['zip'] ?? '')],
        'R58 N2 address book: "811 01" is stored as 81101 (as checkout stores it), a company of invisible characters as none');
    assert_equals(['admin.order.manual.name_email', 'checkout.error.zip_invalid'],
        [ManualOrder::create([], ['name' => "\u{200B}\u{0301}", 'email' => 'r58t-manual@example.test', 'country' => 'SK'], false)['error'] ?? null,
            ManualOrder::create([], ['name' => 'R58 Manual', 'email' => 'r58t-manual@example.test', 'country' => 'CZ', 'zip' => '1100'], false)['error'] ?? null],
        'R58 N2 manual order: a name of invisible characters alone is missing, a Czech postcode is five digits');
} catch (Throwable $e) { $r58tFail('address book', $e); }

// --- N4: control characters in product names -----------------------------------------------------------------------------------
$r58tControl = ["Na\x01me", "Na\x1b[31mme", "Na\x7fme", "Na\u{2028}me", "Na\0me"];
try {
    I18n::setLocale('en');
    $r58tEditor = [];
    foreach ($r58tControl as $r58tName) $r58tEditor[] = ProductEditor::validate(['name' => $r58tName, 'slug' => '', 'sku' => 'R58T-ED', 'price' => '1'], [], 0, null);
    assert_equals(array_fill(0, 5, I18n::format('admin.msg.field_required', ['label' => 'Name', 'max' => 255])), $r58tEditor,
        'R58 N4 editor: a name with a control character inside is refused, the message names "Name" (was: stored, and the raw "name")');
    $r58tImport = [];
    foreach ($r58tControl as $r58tName) {
        try {
            $r58tCall(Import::class, 'importRow', [['sku' => 'R58T-IMPORT', 'name' => $r58tName, 'price' => '1.00'], $pdo]);
            $r58tImport[] = 'imported';
        } catch (InvalidArgumentException $e) {
            $r58tImport[] = str_contains($e->getMessage(), 'control characters') ? 'refused' : $e->getMessage();
        }
    }
    assert_equals(array_fill(0, 5, 'refused'), $r58tImport, 'R58 N4 CSV import: a name with a control character inside is a row error (was: imported)');
    $r58tApi = [];
    foreach ($r58tControl as $r58tName) {
        foreach ([['createProduct', [['sku' => 'R58T-API', 'name' => $r58tName, 'price' => 100], 'k', [], '/api/v1/products']],
            ['patchProduct', [1, ['name' => $r58tName], 'k', [], '/api/v1/products/1']]] as [$r58tMethod, $r58tArgs]) {
            try {
                $r58tCall(ApiV1::class, $r58tMethod, $r58tArgs);
                $r58tApi[] = 'stored';
            } catch (InvalidArgumentException $e) {
                $r58tApi[] = 'refused';
            }
        }
    }
    assert_equals(array_fill(0, 10, 'refused'), $r58tApi, 'R58 N4 API v1: POST and PATCH refuse a name with a control character inside (was: stored)');
    assert_equals(0, (int)$pdo->query("SELECT COUNT(*) FROM products WHERE sku IN ('R58T-IMPORT', 'R58T-API')")->fetchColumn(), 'R58 N4: nothing was stored');
    assert_equals(['Ján Novák', "Nguye\u{0302}\u{0303}n"], [Security::hasControlCharacters('Ján Novák') ? 'control' : 'Ján Novák', Security::hasControlCharacters("Nguye\u{0302}\u{0303}n") ? 'control' : "Nguye\u{0302}\u{0303}n"],
        'R58 N4: a name of letters and combining marks has none');
} catch (Throwable $e) { $r58tFail('control characters', $e); } finally { I18n::setLocale($r58tLocale); }

// --- admin-only attributes: separator runs, cached JSON, filter tags -----------------------------------------------------------
try {
    assert_true(CatalogAttributes::isAdminOnly('download  url') && CatalogAttributes::isAdminOnly('Download--URL') && CatalogAttributes::isAdminOnly('_download__url_')
        && !CatalogAttributes::isAdminOnly('file_format') && !CatalogAttributes::isAdminOnly('download'),
        'R58: "download  url" and "Download--URL" are admin-only (was: public, their filter token is download__url)');
    $r58tSecret = 'https://files.example.test/paid/r58-secret.pdf';
    Import::importProduct(['sku' => 'R58T-DL', 'name' => 'R58 Download', 'price' => '5.00', 'product_type' => 'downloadable', 'category' => 'R58T',
        'attr:file_format' => 'PDF', 'attr:download  url' => $r58tSecret, 'attr:Download--URL' => $r58tSecret], $pdo);
    $r58tRow = static function () use ($pdo): array {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE sku = 'R58T-DL'");
        $stmt->execute();
        return (array)$stmt->fetch(PDO::FETCH_ASSOC);
    };
    $r58tProduct = $r58tRow();
    assert_true(!str_contains((string)$r58tProduct['filter_tags'], 'download') && array_column(Products::attrs((int)$r58tProduct['id']), 'attr_name') === ['file_format'],
        'R58: they stay out of the filter tags and the public attributes: ' . $r58tProduct['filter_tags']);
    // Tags R57 wrote under such a spelling are rebuilt by the migration.
    $pdo->prepare("UPDATE products SET filter_tags = ? WHERE id = ?")->execute(['attr_file_format_pdf attr_download__url_' . Str::normalizeToken($r58tSecret), (int)$r58tProduct['id']]);
    (require $r58tRepo . '/migrations/20261124090000_filter_tags_admin_only_spellings.php')->up($pdo, Db::isSqlite());
    assert_equals('attr_file_format_pdf', (string)$r58tRow()['filter_tags'], 'R58: the migration rebuilds filter tags written with a separator-run spelling');

    // A cached product and filter list written before (with the attribute) are filtered when read.
    $r58tLocaleNow = I18n::locale();
    $r58tSlug = (string)$r58tProduct['slug'];
    $r58tCat = Categories::bySlug('r58t');
    unset($_SESSION['customer_id']);
    Cache::writeJson(Cache::localePath($r58tLocaleNow, "product/{$r58tSlug}.json"), ['product' => ['id' => (int)$r58tProduct['id'], 'slug' => $r58tSlug, 'r58_cached' => true,
        'attrs' => ['file_format' => 'PDF', 'download_url' => $r58tSecret, 'download  url' => $r58tSecret],
        'attributes' => [['attr_name' => 'download_url', 'attr_value' => $r58tSecret], ['attr_name' => 'file_format', 'attr_value' => 'PDF']]]]);
    // Newer than the import's catalog change, so the read is a cache hit (r58_cached), as on a shop that cached it before.
    $r58tFresh = static fn(string $key): bool => touch(Cache::path(Cache::localePath($r58tLocaleNow, $key)), time() + 5);
    $r58tFresh("product/{$r58tSlug}.json");
    $_GET['slug'] = $r58tSlug;
    $r58tApiProduct = apiProduct();
    unset($_GET['slug']);
    assert_true(!empty($r58tApiProduct['product']['r58_cached']) && !str_contains((string)json_encode($r58tApiProduct, JSON_UNESCAPED_SLASHES), 'r58-secret')
        && ($r58tApiProduct['product']['attrs'] ?? null) === ['file_format' => 'PDF'] && array_column($r58tApiProduct['product']['attributes'] ?? [], 'attr_name') === ['file_format'],
        'R58: /api/product read from a cache written before leaves the admin-only attributes out (was: returned as cached): ' . json_encode($r58tApiProduct, JSON_UNESCAPED_SLASHES));
    if ($r58tCat) {
        Cache::writeJson(Cache::localePath($r58tLocaleNow, 'cat/r58t/filters.json'), ['filters' => [
            ['attr_name' => 'download_url', 'label' => 'URL', 'options' => [['value' => $r58tSecret, 'label' => $r58tSecret, 'count' => 1]]],
            ['attr_name' => 'file_format', 'label' => 'Format', 'options' => [['value' => 'PDF', 'label' => 'PDF', 'count' => 1]]]], 'r58_cached' => true]);
        $r58tFresh('cat/r58t/filters.json');
        $_GET['cat'] = 'r58t';
        $r58tFilters = apiFilters();
        unset($_GET['cat']);
        assert_true(!empty($r58tFilters['r58_cached']) && array_column($r58tFilters['filters'], 'attr_name') === ['file_format'],
            'R58: /api/filters read from a cache written before leaves the admin-only attributes out: ' . json_encode($r58tFilters, JSON_UNESCAPED_SLASHES));
    } else {
        assert_true(false, 'R58: the import created the category r58t');
    }
} catch (Throwable $e) { $r58tFail('admin-only', $e); }

// --- UX lows ----------------------------------------------------------------------------------------------------------------------
try {
    $r58tHeader = (string)file_get_contents($r58tRepo . '/themes/default/blocks/storefront/header.html');
    assert_true(str_contains($r58tHeader, '{{#if language_links.length > 1}}<div class="lang-switch">'),
        'R58 UX: the default theme shows the language switcher only with more than one language (as the bookshop theme)');
    I18n::setLocale('de');
    $r58tTypes = array_column(BbfOptions::forAction('bbf_product_types') ?? [], 'label', 'value');
    assert_equals(['simple' => 'Einfaches Produkt', 'downloadable' => 'Digitales Produkt'], array_intersect_key($r58tTypes, ['simple' => 1, 'downloadable' => 1]),
        'R58 UX: the product type select names the types as catalog.json does (was: "Simple", "Downloadable")');
    I18n::setLocale('en');
    // The product editor of a fresh shop (every migration, no category yet), then with one category.
    $r58tFixture = test_fixture_path('r58-editor');
    @mkdir($r58tFixture, 0700, true);
    file_put_contents($r58tFixture . '/request.php', <<<'PHP'
<?php
[, $root, $dir, $withCategory] = $argv;
require $root . '/src/autoload.php';
require $root . '/src/Entrypoints/Admin/Shared.php';
require $root . '/src/Entrypoints/Admin/Catalog.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool { throw new ErrorException($message, 0, $severity, $file, $line); });
$db = new PDO('sqlite:' . $dir . '/shop.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
(new Migrator($db, $root . '/migrations', $dir . '/migrate.lock'))->apply();
if ($withCategory === '1') $db->exec("INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES ('default', 'r58c', 'R58', NULL, 'r58c', 0)");
StoreContext::activate(StoreContext::resolve(Bootstrap::stores(), '/', '', 'default'));
I18n::setLocale('en');
$_SESSION = ['admin_role' => 'owner', 'admin_identity_id' => 1];
$_GET = [];
ob_start();
pageProduct();
print json_encode(['page' => (string)ob_get_clean(), 'link' => esc(I18n::t('admin.product.create_category'))]);
PHP);
    $r58tRun = static function (string $withCategory) use ($r58tFixture, $r58tRepo): array {
        @unlink($r58tFixture . '/shop.sqlite');
        $process = proc_open([PHP_BINARY, $r58tFixture . '/request.php', $r58tRepo, $r58tFixture, $withCategory], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return (array)(json_decode($out, true) ?: ['page' => '', 'link' => '', 'error' => substr($out . $err, -400)]);
    };
    $r58tEmpty = $r58tRun('0');
    $r58tFilled = $r58tRun('1');
    assert_true(str_contains((string)$r58tEmpty['page'], 'data-no-categories') && str_contains((string)$r58tEmpty['page'], '<a href="admin.php?p=categories">' . $r58tEmpty['link'] . '</a>')
        && str_contains((string)$r58tFilled['page'], 'name="category_id"') && !str_contains((string)$r58tFilled['page'], 'data-no-categories'),
        'R58 UX: a catalog without categories says so in the product editor and links to the categories (was: a required select of "—" only) '
        . ($r58tEmpty['error'] ?? '') . ($r58tFilled['error'] ?? ''));
    foreach (['request.php', 'shop.sqlite', 'migrate.lock'] as $r58tLeft) @unlink($r58tFixture . '/' . $r58tLeft);
    @rmdir($r58tFixture);
    $r58tPlurals = [];
    foreach (['sk', 'cs', 'pl'] as $r58tLang) {
        $r58tTexts = require $r58tRepo . "/lang/{$r58tLang}.php";
        foreach (['admin.products.bulk.price_changed', 'admin.gdpr.guest_erased', 'admin.feed.status_stopped', 'admin.feed.status_stopped_prices'] as $r58tKey) {
            if (!str_contains((string)($r58tTexts[$r58tKey] ?? ''), ': {count}')) $r58tPlurals[] = "{$r58tLang} {$r58tKey}";
        }
    }
    assert_equals([], $r58tPlurals, 'R58 UX: Slovak, Czech and Polish put these counts after a colon (was: "pri 1 produktoch", "na 1 objednávkach", "po 1 chybách")');
    assert_equals(['jan-novak', 'jan-novak', 'kosela-zlta'], [Security::slug("J\u{0061}\u{0301}n Nov\u{0061}\u{0301}k"), Security::slug('Ján Novák'), Security::slug('Košeľa Žltá')],
        'R58 UX: a decomposed "Ján Novák" is the slug jan-novak (was: ja-n-nova-k)');
} catch (Throwable $e) { $r58tFail('UX', $e); } finally {
    I18n::setLocale($r58tLocale);
    $_GET = [];
    Bootstrap::initStoreContext('/', '', 'default');
}
