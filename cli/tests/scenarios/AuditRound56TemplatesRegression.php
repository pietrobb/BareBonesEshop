<?php
$pdo ??= test_commerce_fixture();

// Review round 21 (R56), F1 + F2 + CSV template usability:
//  F1: the admin's CSV templates had no product_type column and the upload passes none, so a filled "downloadable"
//      template created physical "simple" products (shipping charged, the e-book never delivered); clothing the same.
//  F2: catalog.json "admin_only" (downloadable.download_url) was checked nowhere: the template asked for attr:download_url
//      and the value was shown in the product page's parameters, /api/v1/storefront/product, /api/filters and filter tags.
//  UX: one sample row (draft), a UTF-8 BOM and ";" for Excel in SK/CZ/DE, the type names translated on the import page.
echo "\n--- R56 templates: product_type column + sample row, admin_only attributes never public ---\n";

$r56tRoot = dirname(TEST_CLI_DIR);
$r56tFail = static fn(string $what, Throwable $e) => assert_true(false, "R56 templates {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');

// --- F1: the filled template imports with its product type ------------------------------------------------------------------
try {
    $r56tStores = ['default_locale' => 'sk', 'stores' => ['a' => ['languages' => ['sk']]]];
    $r56tCsv = (string)ImportTemplates::template('downloadable', 'Vzor', $r56tStores);
    assert_true(str_starts_with($r56tCsv, "\xEF\xBB\xBFsku;name;slug;product_type;category;price;"), 'R56 F1: the template is UTF-8 with BOM, ";"-separated, with a product_type column: ' . substr($r56tCsv, 0, 60));
    $r56tLines = array_values(array_filter(explode("\n", substr($r56tCsv, 3)), 'strlen'));
    $r56tHeader = str_getcsv($r56tLines[0], ';', '"', '');
    $r56tSample = array_combine($r56tHeader, str_getcsv($r56tLines[1] ?? '', ';', '"', ''));
    assert_equals(2, count($r56tLines), 'R56 UX: the template carries exactly one sample row');
    assert_equals(['downloadable', 'draft', '', ''], [$r56tSample['product_type'], $r56tSample['status'], $r56tSample['stock'], $r56tSample['weight_g']],
        'R56 F1: the sample row names its type, is a draft and has neither stock nor weight for a digital product');
    assert_true(!in_array('attr:download_url', $r56tHeader, true) && in_array('attr:file_format', $r56tHeader, true),
        'R56 F2: the downloadable template has no admin-only attr:download_url column (delivery is the digital asset)');
    $r56tClothing = str_getcsv(explode("\n", substr((string)ImportTemplates::template('clothing', 'Vzor', $r56tStores), 3))[1], ';', '"', '');
    assert_true(in_array('clothing', $r56tClothing, true) && in_array('XS', $r56tClothing, true) && in_array('10', $r56tClothing, true),
        'R56 F1: the clothing sample row names its type, a stock and the first size option');
    assert_equals(null, ImportTemplates::template('nope', 'Vzor', $r56tStores), 'R56 F1: an unknown type has no template (404)');

    // The admin upload imports the filled template (the sample row edited) as a digital product, a blank type cell as simple.
    $r56tFile = tempnam(sys_get_temp_dir(), 'r56t');
    $r56tRow = $r56tSample;
    $r56tRow['sku'] = 'R56T-EBOOK';
    $r56tRow['name'] = 'R56 E-book';
    $r56tRow['status'] = 'active';
    $r56tPlain = $r56tRow;
    $r56tPlain['sku'] = 'R56T-PLAIN';
    $r56tPlain['product_type'] = '';
    file_put_contents($r56tFile, ImportTemplates::csv($r56tHeader, array_values($r56tRow), array_values($r56tPlain)));
    $r56tResult = Import::csv($r56tFile);
    @unlink($r56tFile);
    $r56tType = static function (string $sku) use ($pdo): ?string {
        $stmt = $pdo->prepare('SELECT product_type FROM products WHERE sku = :sku');
        $stmt->execute([':sku' => $sku]);
        $type = $stmt->fetchColumn();
        return $type === false ? null : (string)$type;
    };
    assert_equals([2, ['downloadable', 'simple']], [$r56tResult['imported'] ?? 0, [$r56tType('R56T-EBOOK'), $r56tType('R56T-PLAIN')]],
        'R56 F1: the filled downloadable template imports a downloadable product; an empty product_type cell is simple (' . json_encode($r56tResult['errors'] ?? []) . ')');
    assert_equals(';', $r56tResult['delimiter'] ?? null, 'R56 UX: the import reads the template\'s ";"');

    // Import page: the type links carry translated names (catalog.json "name"), "simple" included.
    assert_equals(['Digitálny produkt', 'Jednoduchý produkt', 'Digital Download'],
        [ImportTemplates::label('downloadable', 'sk'), ImportTemplates::label('simple', 'sk'), ImportTemplates::label('downloadable', 'pl')],
        'R56 UX: template links are named in the admin language (English fallback)');
    foreach (['en', 'sk', 'cs', 'de', 'pl', 'hu', 'es'] as $r56tLang) {
        assert_true(trim((string)((require $r56tRoot . "/lang/{$r56tLang}.php")['admin.import.sample_name'] ?? '')) !== '', "R56 UX: sample_name in {$r56tLang}");
    }
} catch (Throwable $e) { $r56tFail('F1', $e); }

// --- F2: admin_only attributes stay out of everything public ----------------------------------------------------------------
try {
    $r56tSecret = 'https://files.example.test/paid/r56-secret-book.pdf';
    Import::importProduct(['sku' => 'R56T-PAID', 'name' => 'R56 Paid Book', 'price' => '5.00', 'product_type' => 'downloadable',
        'attr:file_format' => 'PDF', 'attr:download_url' => $r56tSecret], $pdo);
    $stmt = $pdo->prepare('SELECT * FROM products WHERE sku = :sku');
    $stmt->execute([':sku' => 'R56T-PAID']);
    $r56tProduct = $stmt->fetch(PDO::FETCH_ASSOC);
    $r56tAttrs = json_decode((string)$r56tProduct['attrs'], true);
    assert_equals($r56tSecret, $r56tAttrs['download_url'] ?? null, 'R56 F2: the admin-only attribute is kept on the product (admin editor, export)');

    $r56tId = (int)$r56tProduct['id'];
    assert_equals(['file_format'], array_column(Products::attrs($r56tId), 'attr_name'), 'R56 F2: Products::attrs (product page, API, cache) leaves out download_url');
    $r56tView = StorefrontViewModel::product($r56tProduct);
    $r56tJson = json_encode($r56tView, JSON_UNESCAPED_SLASHES);
    assert_true(!str_contains((string)$r56tJson, 'r56-secret-book') && in_array('PDF', array_column($r56tView['product']['attributes'], 'value'), true),
        'R56 F2: the storefront product (parameters, custom fields, /api/v1/storefront/product) has no trace of the paid file URL');
    $r56tEnriched = Products::enrich($r56tProduct);
    assert_true(!array_key_exists('download_url', (array)$r56tEnriched['attrs']) && ($r56tEnriched['attrs']['file_format'] ?? null) === 'PDF',
        'R56 F2: the enriched product (/api/product, listings, wishlist) drops download_url and keeps the other attributes');
    if (!empty($r56tProduct['category_id'])) {
        assert_true(!array_key_exists('download_url', Products::filterValues((int)$r56tProduct['category_id'])), 'R56 F2: category filters do not offer download_url');
    }
    assert_true(!str_contains((string)$r56tProduct['filter_tags'], 'download') && str_contains((string)$r56tProduct['filter_tags'], 'attr_file_format_pdf'),
        'R56 F2: the filter tags (search facets) have no download_url token: ' . $r56tProduct['filter_tags']);
    assert_true(CatalogAttributes::isAdminOnly('Download_URL ') && !CatalogAttributes::isAdminOnly('file_format'), 'R56 F2: the admin_only match ignores case and spaces');
} catch (Throwable $e) { $r56tFail('F2', $e); }

// --- R55 contract kept: one template per type, simple included ----------------------------------------------------------------
try {
    $r56tAll = ImportTemplates::all();
    $r56tCatalog = array_keys((array)(json_decode((string)file_get_contents($r56tRoot . '/catalog.json'), true)['product_types'] ?? []));
    assert_true(isset($r56tAll['simple']) && array_diff($r56tCatalog, array_keys($r56tAll)) === [] && in_array('product_type', $r56tAll['simple'], true),
        'R56: a template per product type, each with product_type');
} catch (Throwable $e) { $r56tFail('all', $e); }
