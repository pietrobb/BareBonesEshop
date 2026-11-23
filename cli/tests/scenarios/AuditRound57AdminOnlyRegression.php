<?php

// Review round 22 (R57):
// medium S1. R56 left admin-only attributes (download_url) out of the filter tags it writes, but tags written before stayed:
//    the storefront API returned the raw filter_tags column, the SQLite search matched it ("attr_download_url_…_zq" found,
//    "…_zr" not: the URL read character by character) and ?attr[download_url]=… was an exact-value oracle.
// low N3. The admin_only check lower-cased the name, the filters normalise it: "download-url" stayed public.
// low N4. product_type from a CSV was stored as written: "Downloadable" sold as a physical product, "<script>" kept.
// low. A downloadable product imported without its file was "sold out" and the import said nothing.
echo "\n--- R57 admin-only attributes: old filter tags rebuilt, search and filters blind to them; CSV product types ---\n";

$pdo ??= test_commerce_fixture();
Bootstrap::initStoreContext('/', '', 'default');
if (!function_exists('apiProduct')) require_once dirname(TEST_CLI_DIR) . '/src/Http/ApiHandlers.php';
$r57aFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R57 {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};

// --- S1: a product whose filter tags were written before R56 ------------------------------------------------------------------
try {
    $r57aSecret = 'https://files.example.test/paid/r57-secret-book.pdf';
    Import::importProduct(['sku' => 'R57A-PAID', 'name' => 'R57 Paid Book', 'price' => '5.00', 'product_type' => 'downloadable', 'category' => 'R57A',
        'attr:file_format' => 'PDF', 'attr:download_url' => $r57aSecret], $pdo);
    Import::importProduct(['sku' => 'R57A-OTHER', 'name' => 'R57 Other Book', 'price' => '6.00', 'product_type' => 'downloadable', 'category' => 'R57A',
        'attr:file_format' => 'PDF'], $pdo);
    $r57aLegacyTags = 'attr_file_format_pdf attr_download_url_' . Str::normalizeToken($r57aSecret);
    $pdo->prepare("UPDATE products SET filter_tags = ?, translations = ? WHERE sku = 'R57A-PAID'")
        ->execute([$r57aLegacyTags, json_encode(['en' => ['name' => 'R57 Paid Book']])]);
    $r57aRow = static function () use ($pdo): array {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE sku = 'R57A-PAID'");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    };
    $r57aProduct = $r57aRow();
    $r57aCategory = (int)$r57aProduct['category_id'];

    // The search reads the product's text, never its filter tags.
    $r57aHits = static fn(string $q): array => array_column(Search::query($q)['products'] ?? [], 'sku');
    assert_equals([[], []], [$r57aHits('attr_download_url_https_files_example_test_paid_r57_secret_b'), $r57aHits('r57_secret_book_pdf')],
        'R57 S1: a search for the hidden URL\'s filter token finds nothing, a prefix included (was: found, so the URL read character by character)');
    assert_true(in_array('R57A-PAID', $r57aHits('R57 Paid'), true), 'R57 S1: the product is still found by its name');

    // A category filter on an admin-only attribute is no filter: the answer does not depend on the value.
    $r57aFiltered = static function (array $filters) use ($r57aCategory): array {
        $skus = array_column(Products::byCategory($r57aCategory, 1, 24, 'price_asc', $filters)['products'], 'sku');
        sort($skus);
        return $skus;
    };
    assert_equals([['R57A-OTHER', 'R57A-PAID'], ['R57A-OTHER', 'R57A-PAID']],
        [$r57aFiltered(['download_url' => [$r57aSecret]]), $r57aFiltered(['download_url' => ['https://files.example.test/paid/wrong.pdf']])],
        'R57 S1: ?attr[download_url]= with the right and a wrong value lists the same products (was: an exact-value oracle)');
    assert_equals([['R57A-OTHER', 'R57A-PAID'], []], [$r57aFiltered(['file_format' => ['PDF']]), $r57aFiltered(['file_format' => ['EPUB']])],
        'R57 S1: a public attribute still filters');

    // The storefront API returns no raw filter_tags or translations column (fresh and cached responses).
    $_GET['slug'] = (string)$r57aProduct['slug'];
    unset($_SESSION['customer_id']);
    foreach (['fresh', 'cached'] as $r57aPass) {
        $r57aApi = apiProduct();
        $r57aJson = (string)json_encode($r57aApi, JSON_UNESCAPED_SLASHES);
        assert_true(isset($r57aApi['product']) && !array_key_exists('filter_tags', $r57aApi['product']) && !array_key_exists('translations', $r57aApi['product'])
            && !str_contains($r57aJson, 'r57-secret-book') && !str_contains($r57aJson, 'r57_secret_book'),
            "R57 S1: /api/product ({$r57aPass}) has neither the filter_tags nor the translations column, no trace of the URL");
    }
    unset($_GET['slug']);
    $r57aListing = array_map('apiPublicProduct', Products::enrichMany([$r57aRow()]));
    assert_true(!array_key_exists('filter_tags', $r57aListing[0]), 'R57 S1: listings and search results drop filter_tags too');

    // The migration writes every product's filter tags again: the old download_url token is gone.
    $r57aMigration = require dirname(TEST_CLI_DIR) . '/migrations/20261123120000_filter_tags_admin_only.php';
    $r57aMigration->up($pdo, Db::isSqlite());
    assert_equals('attr_file_format_pdf', (string)$r57aRow()['filter_tags'], 'R57 S1: the migration rebuilds the old filter tags without the admin-only attribute');
    if (Db::isSqlite()) {
        $r57aFts = $pdo->prepare("SELECT COUNT(*) FROM products_fts WHERE products_fts MATCH ?");
        $r57aFts->execute(['filter_tags : (download)']);
        assert_equals(0, (int)$r57aFts->fetchColumn(), 'R57 S1: the full-text index follows the rebuilt filter tags');
    }
} catch (Throwable $e) { $r57aFail('S1', $e); }

// --- N3: every spelling the filters read as the attribute is admin-only --------------------------------------------------------
try {
    assert_true(CatalogAttributes::isAdminOnly('download-url') && CatalogAttributes::isAdminOnly('Download URL') && CatalogAttributes::isAdminOnly('DOWNLOAD_URL ')
        && !CatalogAttributes::isAdminOnly('file_format'), 'R57 N3: "download-url" and "Download URL" are admin-only as the filters read them (was: public)');
    Import::importProduct(['sku' => 'R57A-DASH', 'name' => 'R57 Dash', 'price' => '5.00', 'product_type' => 'downloadable', 'attr:download-url' => 'https://files.example.test/x.pdf'], $pdo);
    $stmt = $pdo->prepare("SELECT id, filter_tags FROM products WHERE sku = 'R57A-DASH'");
    $stmt->execute();
    $r57aDash = $stmt->fetch(PDO::FETCH_ASSOC);
    assert_true(!str_contains((string)$r57aDash['filter_tags'], 'download') && Products::attrs((int)$r57aDash['id']) === [],
        'R57 N3: "download-url" stays out of the filter tags and the public attributes');
} catch (Throwable $e) { $r57aFail('N3', $e); }

// --- N4: product_type from a CSV ---------------------------------------------------------------------------------------------
try {
    Import::importProduct(['sku' => 'R57A-CASE', 'name' => 'R57 Case', 'price' => '5.00', 'product_type' => ' Downloadable '], $pdo);
    assert_equals('downloadable', (string)$pdo->query("SELECT product_type FROM products WHERE sku = 'R57A-CASE'")->fetchColumn(),
        'R57 N4: "Downloadable" is the downloadable type (was: stored as written and sold as a physical product)');
    foreach (['<script>alert(1)</script>', 'ebook'] as $r57aBad) {
        $r57aError = '';
        try { Import::importProduct(['sku' => 'R57A-BAD', 'name' => 'R57 Bad', 'price' => '5.00', 'product_type' => $r57aBad], $pdo); } catch (InvalidArgumentException $e) { $r57aError = $e->getMessage(); }
        assert_true(str_contains($r57aError, 'Unknown product type') && !str_contains($r57aError, '<') && str_contains($r57aError, 'downloadable'),
            "R57 N4: an unknown product type is a row error naming the types (was: stored): {$r57aError}");
    }
    assert_equals(0, (int)$pdo->query("SELECT COUNT(*) FROM products WHERE sku = 'R57A-BAD'")->fetchColumn(), 'R57 N4: nothing stored for it');
    Import::importProduct(['sku' => 'R57A-CASE', 'name' => 'R57 Case', 'price' => '5.00', 'product_type' => ''], $pdo);
    assert_equals('simple', (string)$pdo->query("SELECT product_type FROM products WHERE sku = 'R57A-CASE'")->fetchColumn(), 'R57 N4: an empty cell is still "simple"');
} catch (Throwable $e) { $r57aFail('N4', $e); }

// --- low: the CSV import names downloadable products without their file ---------------------------------------------------------
try {
    $r57aCsv = test_fixture_path('r57a-import.csv');
    file_put_contents($r57aCsv, "sku;name;price;product_type\nR57A-NOFILE;R57 No file;5,00;downloadable\nR57A-PHYS;R57 Physical;5,00;simple\n");
    $r57aResult = Import::csv($r57aCsv);
    assert_equals([2, [], ['R57A-NOFILE']], [$r57aResult['imported'], $r57aResult['errors'], $r57aResult['no_download'] ?? null],
        'R57 low: the import names the downloadable product imported without a file (was: silently "sold out")');
    $r57aLangs = [];
    foreach (glob(dirname(TEST_CLI_DIR) . '/lang/*.php') as $r57aLang) {
        if (!str_contains((string)((require $r57aLang)['admin.import.no_download'] ?? ''), '{skus}')) $r57aLangs[] = basename($r57aLang);
    }
    assert_equals([], $r57aLangs, 'R57 low: the admin message is translated in every language');
} catch (Throwable $e) { $r57aFail('no download', $e); }
