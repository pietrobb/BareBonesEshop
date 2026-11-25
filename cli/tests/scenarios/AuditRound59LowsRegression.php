<?php

// Review round 24 (R59), lows:
//  - a Slovak or Czech postcode of full-width or Arabic-Indic digits passed (\d with /u);
//  - a street with a line separator (U+2028) passed the checkout, the address book refused it;
//  - a CSV whose cell held a control byte (0x01, DEL, NUL) was refused whole by the upload check (sniffed as binary), with
//    no row and in English; a name with ESC was a row error, also in English;
//  - "Nguyễn" became the slug "nguy-n";
//  - Czech and Slovak "{label} je povinné" with a masculine label ("Název je povinné");
//  - SalesReport::refreshOnce() restored a copy of the refreshed stores: a nested page released forgot the outer one's.
echo "\n--- Audit round 59: postcodes, line separators, CSV control bytes, Vietnamese slugs, CS/SK gender, refreshOnce() ---\n";

$pdo ??= test_commerce_fixture();
$r59lRepo = dirname(TEST_CLI_DIR);
$r59lLocale = I18n::locale();
$r59lFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R59 low {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
Bootstrap::initStoreContext('/', '', 'default');
if (!function_exists('checkoutExecute')) require_once $r59lRepo . '/src/Http/ApiHandlers.php';

// --- Postcodes: ASCII digits only ------------------------------------------------------------------------------------------------
try {
    assert_equals(['81101', '11000', null, null, null],
        [OrderAddress::postcode('811 01', 'SK'), OrderAddress::postcode('110 00', 'CZ'), OrderAddress::postcode("\u{FF18}\u{FF11}\u{FF11}\u{FF10}\u{FF11}", 'SK'),
            OrderAddress::postcode("\u{0668}\u{0661}\u{0661}\u{0660}\u{0661}", 'SK'), OrderAddress::postcode("\u{0967}\u{0967}\u{0966} \u{0966}\u{0966}", 'CZ')],
        'R59 low: a Slovak or Czech postcode is five ASCII digits (was: full-width, Arabic-Indic and Devanagari digits passed and were stored)');
} catch (Throwable $e) { $r59lFail('postcodes', $e); }

// --- U+2028 / U+2029: checkout as the address book ---------------------------------------------------------------------------------
$r59lSession = $_SESSION ?? [];
try {
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    $r59lCategory = (int)$pdo->query("SELECT id FROM categories WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, weight_g) VALUES ('default', ?, 'R59L-PHYS', 'r59l-phys', 'R59 Physical', 1000, 50, 'active', 500)")
        ->execute([$r59lCategory ?: null]);
    $r59lWarehouse = (int)$pdo->query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    if ($r59lWarehouse > 0) $pdo->prepare("INSERT INTO warehouse_stock (warehouse_id, sku, qty, reserved) VALUES (?, 'R59L-PHYS', 50, 0)")->execute([$r59lWarehouse]);
    unset($_SESSION['customer_id']);
    Cart::clear();
    Cart::add((int)$pdo->query("SELECT id FROM products WHERE sku = 'R59L-PHYS'")->fetchColumn(), null, 1);
    $r59lN = 0;
    $r59lCheckout = static function (array $fields) use (&$r59lN): string {
        $r59lN++;
        $result = checkoutExecute($fields + ['name' => 'R59 Buyer', 'email' => "r59l-{$r59lN}@example.test", 'country' => 'SK', 'street' => 'Hlavná 1', 'city' => 'Bratislava',
            'zip' => '81101', 'payment_method' => 'bank_transfer', 'shipping_method' => 'r59-none']);
        http_response_code(200);
        return (string)($result['error_code'] ?? 'other');
    };
    assert_equals(['checkout.error.field_invalid', 'checkout.error.field_invalid', 'checkout.error.field_invalid'],
        [$r59lCheckout(['street' => "Hlavná\u{2028}1"]), $r59lCheckout(['city' => "Brati\u{2029}slava"]), $r59lCheckout(['name' => "R59\u{2028}Buyer"])],
        'R59 low checkout: a street, city or name with a line or paragraph separator is refused (was: stored and printed on the label)');
    $r59lAddress = ['type' => 'shipping', 'name' => 'Ján Novák', 'company' => '', 'street' => 'Hlavná 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK', 'phone' => ''];
    assert_true(isset(Customers::addressErrors(['street' => "Hlavná\u{2028}1"] + $r59lAddress)['street']),
        'R59 low address book: the same street is refused there too');
    Cart::clear();
} catch (Throwable $e) { $r59lFail('line separators', $e); } finally { $_SESSION = $r59lSession; }

// --- CSV: a control byte is a row error, by its line, in the admin's language ------------------------------------------------------
$r59lCsv = tempnam(sys_get_temp_dir(), 'r59l');
try {
    I18n::setLocale('sk');
    file_put_contents($r59lCsv, "sku,name,price\nR59L-CSV-1,Dobrý,1.00\nR59L-CSV-2,Zl\x01ý,2.00\nR59L-CSV-3,Tiež\x7fzlý,3.00\nR59L-CSV-4,Escape\x1bzlý,4.00\nR59L-CSV-5,Dobrý 2,5.00\n");
    $r59lMimeBefore = (string)(new finfo(FILEINFO_MIME_TYPE))->file($r59lCsv);
    $r59lNeutralize = method_exists(Import::class, 'neutralizeControlBytes');
    if ($r59lNeutralize) Import::neutralizeControlBytes($r59lCsv);
    $r59lMime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($r59lCsv);
    assert_true($r59lMimeBefore === 'application/octet-stream' && in_array($r59lMime, ['text/csv', 'text/plain', 'application/csv'], true),
        "R59 low CSV: the upload check takes a CSV with a control byte in a cell as text (was: application/octet-stream, the whole file refused; now {$r59lMime})");
    $r59lResult = Import::csv($r59lCsv);
    $r59lShown = array_map(ImportErrorLabels::text(...), $r59lResult['errors']);
    assert_equals([2, 3], [$r59lResult['imported'], count($r59lShown)], 'R59 low CSV: the two good rows are imported, the three with a control character are row errors');
    assert_equals([
        I18n::format('admin.import.err.row', ['line' => '3', 'sku' => 'R59L-CSV-2', 'error' => I18n::format('admin.import.err.control_chars', ['field' => 'name'])]),
        I18n::format('admin.import.err.row', ['line' => '4', 'sku' => 'R59L-CSV-3', 'error' => I18n::format('admin.import.err.control_chars', ['field' => 'name'])]),
        I18n::format('admin.import.err.row', ['line' => '5', 'sku' => 'R59L-CSV-4', 'error' => I18n::format('admin.import.err.control_chars', ['field' => 'name'])]),
    ], $r59lShown, 'R59 low CSV: each refused row names its line and SKU, in Slovak (was: "Invalid name: control characters are not allowed")');
    assert_true(str_starts_with($r59lShown[0] ?? '', 'Riadok 3') && !str_contains($r59lShown[0] ?? '', 'control characters'),
        'R59 low CSV: the Slovak text is the translation (' . ($r59lShown[0] ?? '') . ')');
} catch (Throwable $e) { $r59lFail('CSV', $e); } finally {
    I18n::setLocale($r59lLocale);
    @unlink($r59lCsv);
    $pdo->exec("DELETE FROM products WHERE sku LIKE 'R59L-CSV-%'");
}

// --- Slugs: letters the tables do not know shed their marks ------------------------------------------------------------------------
try {
    if (class_exists('Normalizer')) {
        assert_equals(['nguyen-van-teo', 'pho-bo-ha-noi', 'kosela-zlta', 'jan-novak', 'zolw-lodz'],
            [Str::slug('Nguyễn Văn Tèo'), Str::slug('Phở bò Hà Nội'), Str::slug('Košeľa Žltá'), Str::slug("Ja\u{0301}n Nova\u{0301}k"), Str::slug('Żółw Łódź')],
            'R59 low: a Vietnamese name is "nguyen-van-teo" (was: "nguy-n-van-teo"); Slovak, decomposed and Polish names as before');
    } else {
        print "  [SKIP] R59 low slugs (intl Normalizer missing)\n";
    }
} catch (Throwable $e) { $r59lFail('slugs', $e); }

// --- CS/SK: "{label} je povinné" agrees with no label --------------------------------------------------------------------------------
try {
    $r59lRequired = [];
    foreach (['cs' => 'Název', 'sk' => 'Názov'] as $r59lLang => $r59lLabel) {
        I18n::setLocale($r59lLang);
        $r59lRequired[] = I18n::format('admin.msg.field_required', ['label' => $r59lLabel, 'max' => 255]);
    }
    assert_true(!str_contains($r59lRequired[0], 'Název je povinné') && !str_contains($r59lRequired[1], 'Názov je povinné')
        && str_starts_with($r59lRequired[0], 'Název:') && str_starts_with($r59lRequired[1], 'Názov:'),
        'R59 low: the Czech and Slovak required-field message reads "Název: povinné pole" (was: "Název je povinné", the neuter ending on a masculine label): ' . implode(' | ', $r59lRequired));
} catch (Throwable $e) { $r59lFail('gender', $e); } finally { I18n::setLocale($r59lLocale); }

// --- refreshOnce(): nested and out-of-order release ----------------------------------------------------------------------------------
try {
    $r59lRefreshed = new ReflectionProperty(SalesReport::class, 'pageRefreshed');
    $r59lOpen = static function () use ($r59lRefreshed): bool {
        if (property_exists(SalesReport::class, 'pageDepth')) return (new ReflectionProperty(SalesReport::class, 'pageDepth'))->getValue() > 0;
        return $r59lRefreshed->getValue() !== null;
    };
    $r59lOuter = SalesReport::refreshOnce();
    $r59lInner = SalesReport::refreshOnce();
    $r59lRefreshed->setValue(null, ['default' => true]); // a report of the inner part refreshed the store
    unset($r59lInner);
    $r59lKept = isset($r59lRefreshed->getValue()['default']);
    unset($r59lOuter);
    $r59lAfter = [$r59lOpen(), $r59lRefreshed->getValue() ?? []];
    $r59lA = SalesReport::refreshOnce();
    $r59lB = SalesReport::refreshOnce();
    unset($r59lA); // released before the one opened after it
    unset($r59lB);
    assert_equals([true, [false, []], false], [$r59lKept, $r59lAfter, $r59lOpen()],
        'R59 low: a nested refreshOnce() released keeps what the outer page refreshed, and no page is held after both are released in either order (was: the outer page refreshed again; released out of order, a finished page stayed open)');
} catch (Throwable $e) { $r59lFail('refreshOnce', $e); }
