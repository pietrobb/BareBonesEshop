<?php

// Review round 34 (2026-11-12), VAT lows (goal #440): a business with a valid VAT ID of another member state billed to
// the seller's state or outside the EU paid Slovak VAT on an e-book (art. 44: reverse charge where it is established);
// the reverse-charge group was named by the billing country; the 10 000 EUR OSS threshold was a switch nobody was
// reminded of; a mixed invoice (exported goods + an e-book reverse charged) carried only the export notice, not the
// Art. 196 one; an IČ DPH VIES could not check was printed like a verified one.
echo "\n--- R34: VAT lows — art. 44 e-services, OSS threshold, mixed invoice notices, unverified VAT ID ---\n";

// R35 (low): run on its own (after FoundationScenario) it found no digital_assets, invoices or vat_id_cache tables,
// which only scenarios before it in the suite had migrated: an incomplete schema is migrated first.
if (!Db::tableExists('digital_assets') || !Db::tableExists('invoices') || !Db::tableExists('vat_id_cache')) {
    (new Migrator($pdo, dirname(TEST_CLI_DIR) . '/migrations', test_fixture_path('r34l-migrate') . '.lock'))->apply();
}
Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
$r34lStores = Bootstrap::stores();
$r34lStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r34lOss = $r34lStores;
$r34lOss['stores']['default']['oss_enabled'] = true;
$r34lOss['stores']['default']['legal']['country'] = 'SK';
$r34lStoresProp->setValue(null, $r34lOss);
Bootstrap::initStoreContext('/', '', 'default');
$r34lCart = $_SESSION['cart'] ?? null;
$r34lOrders = [];
$r34lFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R34 VAT lows {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r34lScenario = static fn(array $vat): array => [(string)$vat['scenario']['scenario'], (string)$vat['scenario']['rate_country']];

try {
    $pdo->exec("DELETE FROM products WHERE id IN (99361, 99362)");
    $pdo->exec("DELETE FROM digital_assets WHERE sku = 'R34V-EBOOK'");
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (99361, 'default', 1, 'R34L e-book', 'r34v-ebook', 'R34V-EBOOK', 'downloadable', 1230, 'eur', 'active', 0, 'continue')");
    $pdo->exec("INSERT INTO digital_assets (sku, asset_key, label, storage_path, download_name, content_type, download_limit, expiry_seconds, active)
        VALUES ('R34V-EBOOK', 'pdf', 'PDF', 'r34l.pdf', 'r34l.pdf', 'application/pdf', 2, 3600, 1)");
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (99362, 'default', 1, 'R34L book', 'r34v-book', 'R34V-BOOK', 'simple', 1000, 'eur', 'active', 10, 'continue')");
    $pdo->prepare("DELETE FROM vat_id_cache WHERE vat_id = 'DE123456789'")->execute();
    $pdo->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES ('DE123456789', 'DE', 'valid', 'R34L GmbH', :at, 86400)")
        ->execute([':at' => gmdate('Y-m-d H:i:s')]);

    // 1. Art. 44: a download for a business with a valid German VAT ID is reverse charged in Germany, billed anywhere.
    $_SESSION['cart'] = ['p_99361' => ['product_id' => 99361, 'variant_id' => null, 'qty' => 1]];
    assert_equals([['reverse_charge', 'DE'], ['reverse_charge', 'DE'], ['reverse_charge', 'DE']],
        [$r34lScenario(CheckoutService::vatContext('SK', 'DE123456789', '', true, null, 'SK')), $r34lScenario(CheckoutService::vatContext('SK', 'DE123456789', '', true, null, 'US')),
         $r34lScenario(CheckoutService::vatContext('DE', 'DE123456789', '', true, null, 'DE'))],
        'R34 VAT: an e-book for a German business billed to Slovakia or the USA is reverse charged in DE (was 23 % Slovak VAT)');
    assert_equals([['domestic', 'SK'], ['oss', 'AT']],
        [$r34lScenario(CheckoutService::vatContext('SK', 'DE123456789', '', false, null, 'SK')), $r34lScenario(CheckoutService::vatContext('SK', '', '', false, null, 'AT'))],
        'R34 VAT: an unverified VAT ID is a consumer where it is billed; a consumer billed to Austria stays OSS AT');
    // Mixed cart: goods supplied at home, the download reverse charged in the VAT ID's state and grouped under it.
    $_SESSION['cart'] = ['p_99361' => ['product_id' => 99361, 'variant_id' => null, 'qty' => 1], 'p_99362' => ['product_id' => 99362, 'variant_id' => null, 'qty' => 1]];
    $r34lMixed = CheckoutService::vatContext('SK', 'DE123456789', 'packeta', true, null, 'SK');
    assert_equals(['DE', ['country' => 'DE', 'scheme' => 'reverse_charge'], '0 DE RC'],
        [(string)$r34lMixed['context']['digital_customer_country'], VatService::digitalSupply($r34lMixed['context']), VatService::breakdownKey(0, 'DE', 'reverse_charge')],
        'R34 VAT: next to goods, the e-book of a German VAT ID billed to Slovakia is reverse charged under DE, not the billing country');
    // Art. 58 stays as agreed in round 5: a declared non-EU country alone is no evidence, the download keeps Slovak VAT.
    $_SESSION['cart'] = ['p_99361' => ['product_id' => 99361, 'variant_id' => null, 'qty' => 1]];
    assert_equals(['domestic', 'SK'], $r34lScenario(CheckoutService::vatContext('SK', '', '', false, null, 'US')),
        'R34 VAT: a consumer download billed to the USA keeps the seller\'s VAT (R5: a declared country alone does not zero-rate)');
} catch (Throwable $e) { $r34lFail('art. 44', $e); }

// 2. OSS threshold: invoices of cross-border consumer sales at the seller's VAT (OSS off) count against 10 000 EUR.
try {
    $year = (int)ShopTime::date('Y');
    $r34lInvoice = static function (string $number, int $net, string $scenario, int $year, string $currency = 'eur') use ($pdo, &$r34lOrders): void {
        $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
                total, currency, status, payment_status, payment_method, shipping_method, store_id, created_at)
            VALUES (?, 'R34L', 'r34l@example.test', '', '{\"country\":\"AT\"}', ?, 0, 0, 0, ?, ?, 'delivered', 'paid', 'bank_transfer', 'packeta', 'default', " . Db::now() . ")")
            ->execute([$number, $net, $net, $currency]);
        $orderId = $r34lOrders[] = (int)$pdo->lastInsertId();
        $pdo->prepare("DELETE FROM invoices WHERE order_id = ? OR number = ?")->execute([$orderId, $number]);
        $pdo->prepare("INSERT INTO invoices (order_id, number, issued_at, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, vat_scenario)
            VALUES (?, ?, ?, '', ?, 0, ?, ?, 'issued', ?, ?)")
            ->execute([$orderId, $number, sprintf('%04d-06-15 10:00:00', $year), $net, $net, $currency, 'r34l-' . $number, $scenario]);
    };
    $store = ['legal' => ['country' => 'SK'], 'oss_enabled' => false];
    $check = static fn(array $store): array => Setup::ossThresholdCheck($pdo, 'default', $store, ['default' => $store]);
    $r34lInvoice('R34L-T1', 600000, 'domestic_subthreshold', $year);
    $r34lInvoice('R34L-T2', 900000, 'domestic', $year);           // a domestic sale does not count
    $r34lInvoice('R34L-T3', 900000, 'domestic_subthreshold', $year - 3); // nor one three years ago
    $under = $check($store);
    $r34lInvoice('R34L-T4', 450000, 'domestic_subthreshold', $year);
    $over = $check($store);
    $withOss = $check(['oss_enabled' => true] + $store);
    assert_equals(['pass', 'warn', 'pass', 'stores.oss_threshold.default'], [$under['status'], $over['status'], $withOss['status'], $over['name']],
        'R34 VAT: cross-border consumer sales at the seller\'s VAT above 10 000 EUR in a year warn to switch OSS on (was: a manual switch only)');
    assert_true(str_contains($over['message'], '10 500.00 EUR') && str_contains($over['action'], 'Admin → Stores'),
        'R34 VAT: the OSS threshold warning names the amount and where to switch OSS on');
    $checklistTitle = SetupChecklist::title('stores.oss_threshold.default');
    assert_true($checklistTitle !== null && str_contains($checklistTitle, 'default') && SetupChecklist::fixPage('stores.oss_threshold.default') === 'stores',
        'R34 VAT: the dashboard checklist shows the OSS threshold warning translated, with the store settings to fix it');
} catch (Throwable $e) { $r34lFail('OSS threshold', $e); }

// 3. A mixed invoice states the legal ground of each scheme: export (§ 47 / Art. 146) and reverse charge (Art. 196).
try {
    $mixedRates = ['0' => ['net' => 1000, 'vat' => 0], '0 DE RC' => ['net' => 1000, 'vat' => 0, 'country' => 'DE', 'supply' => 'reverse_charge']];
    $mixed = InvoiceService::vatNotices(['reverse_charge_note_key' => 'vat.export_note'], 'sk', ['rates' => $mixedRates], 'SK');
    $goodsOnly = InvoiceService::vatNotices(['reverse_charge_note_key' => 'vat.export_note'], 'sk', ['rates' => ['0' => ['net' => 1000, 'vat' => 0]]], 'SK');
    $rcDigital = InvoiceService::vatNotices(['reverse_charge_note_key' => 'vat.reverse_charge_services_note'], 'en', ['rates' => ['0 DE RC' => $mixedRates['0 DE RC']]], 'SK');
    assert_true(count($mixed) === 2 && str_contains($mixed[0], '§ 47') && str_contains($mixed[1], 'čl. 196') && count($goodsOnly) === 1 && count($rcDigital) === 1,
        'R34 VAT: an invoice of exported goods and an e-book reverse charged cites both Art. 146 and Art. 196 (was: only the export)');
} catch (Throwable $e) { $r34lFail('mixed notices', $e); }

// 4. An IČ DPH VIES could not check is printed as unverified, not as the buyer's checked registration.
try {
    $fields = CheckoutService::withoutInvalidVatId(['company' => 'R34L GmbH', 'ic_dph' => 'DE999999999'], 'unverified');
    $snapshotOf = new ReflectionMethod(InvoiceService::class, 'customerSnapshot');
    $linesOf = new ReflectionMethod(InvoiceService::class, 'partyLines');
    $labelsOf = new ReflectionMethod(InvoiceService::class, 'loadInvoiceLabels');
    $unverified = $snapshotOf->invoke(null, ['customer_name' => 'R34L', 'shipping_addr' => json_encode(['name' => 'R34L', 'country' => 'DE'] + $fields)]);
    $valid = $snapshotOf->invoke(null, ['customer_name' => 'R34L', 'shipping_addr' => json_encode(['name' => 'R34L', 'country' => 'DE']
        + CheckoutService::withoutInvalidVatId(['company' => 'R34L GmbH', 'ic_dph' => 'DE123456789'], 'valid'))]);
    $sk = $labelsOf->invoke(null, 'sk');
    assert_true(in_array('IČ DPH: DE999999999 (neoverené v systéme VIES)', $linesOf->invoke(null, $unverified, $sk, 'sk'), true)
        && in_array('IČ DPH: DE123456789', $linesOf->invoke(null, $valid, $sk, 'sk'), true) && !isset($valid['ic_dph_unverified']),
        'R34 VAT: an IČ DPH VIES did not confirm is printed as unverified on the invoice; a valid one as before');
    $missing = [];
    foreach (['sk', 'cs', 'pl', 'hu', 'de', 'es', 'en'] as $locale) if (trim((string)($labelsOf->invoke(null, $locale)['ic_dph_unverified'] ?? '')) === '') $missing[] = $locale;
    assert_equals([], $missing, 'R34 VAT: every invoice language labels an unverified VAT ID');
} catch (Throwable $e) { $r34lFail('unverified VAT ID', $e); }

$r34lStoresProp->setValue(null, $r34lStores);
Bootstrap::initStoreContext('/', '', 'default');
if ($r34lCart === null) unset($_SESSION['cart']); else $_SESSION['cart'] = $r34lCart;
foreach ($r34lOrders as $r34lOrder) {
    $pdo->prepare('DELETE FROM invoices WHERE order_id = ?')->execute([$r34lOrder]);
    $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$r34lOrder]);
}
$pdo->exec("DELETE FROM products WHERE id IN (99361, 99362)");
$pdo->exec("DELETE FROM digital_assets WHERE sku = 'R34V-EBOOK'");
