<?php
$pdo ??= test_commerce_fixture();

// Review round 14 (R40), low findings of VAT (goal #493):
// 1. The IP country header (vat_ip_country_header) was taken from any client, not only from a trusted proxy.
// 2. A declaration with one item of evidence outranked two that agreed (billed and delivered in SK, IP AT, declared AT).
// 3. An e-book-only order taxed where its IP was could not be edited: the edit re-priced without the IP ("VAT changed").
// 4. The year's sales in another currency were converted at today's rate: a moving rate alone could pass the threshold.
//    Each document now counts in EUR at the rate of the day it is issued (art. 91(2)).
// 5. The VIES holder check: an academic title or the word order ("Novák Ján") refused, and the buyer was not told why.
// 6. OssThreshold::collected() took another store's pickup method of the same code for the order's.
echo "\n--- R40 VAT low: trusted IP header, two items of evidence, edit keeps the IP, EUR at issue, holder titles, store's pickup ---\n";

Bootstrap::initStoreContext('/', '', 'default');
VatService::reset();
$r40vCfg = new ReflectionProperty(Security::class, 'cfg');
$r40vCfgBefore = $r40vCfg->getValue();
$r40vRemote = $_SERVER['REMOTE_ADDR'] ?? null;
$r40vStores = Bootstrap::stores();
$r40vStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r40vCart = $_SESSION['cart'] ?? null;
$r40vOrderIds = [];
try {
    // 1. IP country only from a trusted proxy.
    $r40vCfg->setValue(null, ['trusted_proxies' => ['10.40.0.1']] + (array)(require AppConfig::path(dirname(TEST_CLI_DIR))));
    $_SERVER['HTTP_CF_IPCOUNTRY'] = 'AT';
    $r40vHeader = ['vat_ip_country_header' => 'CF-IPCountry'];
    $_SERVER['REMOTE_ADDR'] = '198.51.100.40';
    $r40vDirect = CheckoutService::ipCountry($r40vHeader);
    $_SERVER['REMOTE_ADDR'] = '10.40.0.1';
    $r40vProxied = CheckoutService::ipCountry($r40vHeader);
    assert_equals(['', 'AT'], [$r40vDirect, $r40vProxied], 'R40 IP country: a client reaching the shop directly cannot claim a country; the trusted proxy\'s header is taken');
    unset($_SERVER['HTTP_CF_IPCOUNTRY']);

    // 2. Two items of evidence naming one country decide.
    $r40vTwo = CheckoutService::digitalResidence(false, 'SK', 'SK', false, 'AT', 'AT');
    assert_equals(['SK', false, []], [$r40vTwo['country'], $r40vTwo['required'], $r40vTwo['choices']],
        'R40 residence: billed and delivered in SK, an IP in AT — SK decides, the declaration of AT does not outrank two items (got ' . json_encode($r40vTwo) . ')');
    $r40vDelivered = CheckoutService::digitalResidence(false, 'SK', 'AT', false, '', 'AT');
    assert_equals(['AT', false, 'AT'], [$r40vDelivered['country'], $r40vDelivered['required'], $r40vDelivered['evidence']['decided'] ?? null],
        'R40 residence: delivered to AT and an IP in AT outweigh the billing country, recorded as decided');
    $r40vAsk = CheckoutService::digitalResidence(false, 'LU', 'SK', false, '', 'AT');
    assert_true($r40vAsk['required'] && $r40vAsk['choices'] === ['LU', 'SK', 'AT'], 'R40 residence: three different countries still ask the buyer (control)');
    $r40vView = CheckoutService::residenceView(['context' => ['seller_country' => 'SK', 'digital_residence_evidence' => $r40vDelivered['evidence']],
        'scenario' => ['scenario' => VatService::SCENARIO_OSS, 'rate_country' => 'AT']], ['residence_choices' => []], [], '');
    $r40vAustria = (string)(ShippingService::billingCountries()['AT'] ?? 'AT');
    assert_true(str_contains($r40vView['vat_reason'], sprintf(I18n::t('checkout.vat_reason.digital'), $r40vAustria)),
        'R40 residence: the checkout names the country the evidence decided, not the billing one (got ' . $r40vView['vat_reason'] . ')');

    // 5. VIES holder: titles and word order; the buyer is told.
    $pdo->exec("DELETE FROM vat_id_cache WHERE vat_id LIKE 'CZ4040%'");
    $pdo->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES ('CZ4040404040', 'CZ', 'valid', 'Novák Jan', ?, 86400)")
        ->execute([gmdate('Y-m-d H:i:s')]);
    $r40vHolder = [];
    foreach (['Ing. Jan Novák', 'Jan Novák', 'Mgr. Novák Jan, Ph.D.', 'Petr Novák', 'Ing.'] as $r40vName) $r40vHolder[$r40vName] = VatService::vatIdHolderMismatch('CZ4040404040', $r40vName);
    assert_equals(['Ing. Jan Novák' => null, 'Jan Novák' => null, 'Mgr. Novák Jan, Ph.D.' => null, 'Petr Novák' => 'name', 'Ing.' => 'name'], $r40vHolder,
        'R40 VIES holder: academic titles aside and the words in any order the holder matches; another name or a title alone does not');
    $r40vMismatch = CheckoutService::residenceView(['context' => ['seller_country' => 'SK'], 'scenario' => ['scenario' => VatService::SCENARIO_DOMESTIC]],
        ['residence_choices' => [], 'vat_id_status' => 'mismatch_name'], [], '');
    assert_true(str_contains($r40vMismatch['vat_reason'], I18n::t('checkout.vat_reason.vat_id_holder')) && I18n::t('checkout.vat_reason.vat_id_holder') !== 'checkout.vat_reason.vat_id_holder',
        'R40 VIES holder: the checkout says VAT is charged because VIES registers the VAT ID to another name (got ' . $r40vMismatch['vat_reason'] . ')');

    // 6. Another store's pickup method of the same code is not the order's.
    $pdo->exec("DELETE FROM shipping_methods WHERE code = 'r40v-pick'");
    $pdo->exec("INSERT INTO shipping_methods (store_id, code, carrier, name, service_type, supports_cod, supports_pickup, sort_order) VALUES ('r40-other', 'r40v-pick', 'pickup', 'R40V store pickup', 'pickup', 0, 1, 990)");
    $r40vOld = ['store_id' => 'default', 'shipping_method' => 'r40v-pick', 'shipping_addr' => json_encode(['country' => 'AT']), 'billing_addr' => null, 'customer_name' => 'R40V'];
    assert_equals([false, true], OssThreshold::distance($r40vOld, VatService::SCENARIO_DOMESTIC, 'SK'),
        'R40 collected: an older order of the default store sent by its own method r40v-pick to AT counts its e-books abroad (another store\'s pickup of that code was taken)');
    $pdo->exec("UPDATE shipping_methods SET store_id = 'default' WHERE code = 'r40v-pick'");
    assert_equals([false, false], OssThreshold::distance($r40vOld, VatService::SCENARIO_DOMESTIC, 'SK'), 'R40 collected: the store\'s own pickup method is collection (control)');
    $pdo->exec("DELETE FROM shipping_methods WHERE code = 'r40v-pick'");

    // 4. EUR at the rate of the day of issue.
    foreach (['20261113180000_invoice_oss_net', '20261113190000_oss_threshold_totals', '20261116110000_eur_exchange_rates', '20261116190000_invoice_oss_net_eur'] as $r40vMigration) {
        (require TEST_CLI_DIR . "/../migrations/{$r40vMigration}.php")->up($pdo, Db::isSqlite());
    }
    Db::forgetSchema($pdo);
    assert_true(Db::hasColumn('invoices', 'oss_net_eur', $pdo), 'R40 EUR at issue: the migration adds invoices.oss_net_eur');
    $r40vYear = (int)ShopTime::date('Y');
    $r40vRates = $pdo->query('SELECT currency, rate, as_of FROM eur_exchange_rates')->fetchAll(PDO::FETCH_NUM);
    // R42 (low 8): the ECB history other scenarios (or an earlier run) left is set aside, so this one passes in any order;
    // the rate is today's (R42: only the rate of the last publication day is fixed at issue).
    $r40vHistory = Db::tableExists('eur_exchange_rate_history', $pdo) ? $pdo->query('SELECT currency, as_of, rate FROM eur_exchange_rate_history')->fetchAll(PDO::FETCH_NUM) : null;
    if ($r40vHistory !== null) $pdo->exec('DELETE FROM eur_exchange_rate_history');
    $r40vSetCzk = static function (float $rate) use ($pdo): void {
        $pdo->exec('DELETE FROM eur_exchange_rates');
        $pdo->prepare('INSERT INTO eur_exchange_rates (currency, rate, as_of) VALUES (?, ?, ?)')->execute(['CZK', $rate, gmdate('Y-m-d', ShopTime::now())]);
        EurRates::reset();
    };
    $r40vSetCzk(0.04);
    $r40vTotal = static fn(string $currency): int => (int)$pdo->query("SELECT COALESCE(SUM(net), 0) FROM oss_threshold_totals WHERE store_id = 'default' AND year = {$r40vYear} AND currency = " . $pdo->quote($currency))->fetchColumn();
    $r40vRebuild = static function (string $currency) use ($pdo, $r40vYear): int {
        $net = 0;
        foreach ($pdo->query((string)(new ReflectionMethod(OssThresholdCache::class, 'source'))->invoke(null, $pdo))->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['store_id'] === 'default' && (int)$row['year'] === $r40vYear && $row['currency'] === $currency) $net += (int)$row['net'];
        }
        return $net;
    };
    [$r40vEurBefore, $r40vCzkBefore, $r40vEurRebuiltBefore, $r40vCzkRebuiltBefore] = [$r40vTotal('eur'), $r40vTotal('czk'), $r40vRebuild('eur'), $r40vRebuild('czk')];
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
            total, net_total, currency, status, payment_status, payment_method, shipping_method, store_id, vat_scenario, created_at)
        VALUES ('R40V-CZK', 'R40V', 'r40v@example.test', '', ?, 100000, 0, 0, 0, 100000, 100000, 'czk', 'delivered', 'paid', 'bank_transfer', 'gls', 'default', 'domestic_subthreshold', ?)")
        ->execute([json_encode(['country' => 'AT']), sprintf('%04d-02-10 10:00:00', $r40vYear)]);
    $r40vOrderIds[] = $r40vOrder = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO invoices (order_id, store_id, number, issued_at, pdf_path, total_net, total_vat, total_gross, currency, status, download_token, vat_scenario, type)
        VALUES (?, 'default', 'R40V-CZK', ?, '', 100000, 0, 100000, 'czk', 'issued', 'r40v-czk', 'domestic_subthreshold', 'invoice')")
        ->execute([$r40vOrder, gmdate('Y-m-d H:i:s', ShopTime::now())]);
    $r40vInvoice = (int)$pdo->lastInsertId();
    OssThreshold::record($pdo, $r40vInvoice);
    $r40vStored = $pdo->query("SELECT oss_net, oss_net_eur FROM invoices WHERE id = {$r40vInvoice}")->fetch(PDO::FETCH_NUM);
    assert_equals([[100000, 4000], 4000, 0], [array_map('intval', $r40vStored), $r40vTotal('eur') - $r40vEurBefore, $r40vTotal('czk') - $r40vCzkBefore],
        'R40 EUR at issue: 1 000 CZK issued at 0.04 is stored and counted as 40 EUR in the year\'s EUR total');
    $r40vSetCzk(0.08);
    assert_equals([4000, 0], [$r40vRebuild('eur') - $r40vEurRebuiltBefore, $r40vRebuild('czk') - $r40vCzkRebuiltBefore],
        'R40 EUR at issue: the rate doubling later does not double the sale (the totals rebuild counts it in EUR at its issue)');
    InvoiceService::cancel($r40vInvoice, 'R40V');
    assert_equals([0, 0], [$r40vTotal('eur') - $r40vEurBefore, $r40vTotal('czk') - $r40vCzkBefore],
        'R40 EUR at issue: cancelled after the rate moved, exactly what it added leaves the total');
    $pdo->exec('DELETE FROM eur_exchange_rates');
    $r40vRestoreRate = $pdo->prepare('INSERT INTO eur_exchange_rates (currency, rate, as_of) VALUES (?, ?, ?)');
    foreach ($r40vRates as $r40vRate) $r40vRestoreRate->execute($r40vRate);
    EurRates::reset();

    // 3. An e-book-only order taxed where its IP was is edited at the same VAT.
    $r40vOss = $r40vStores;
    $r40vOss['stores']['default']['oss_enabled'] = true;
    $r40vOss['stores']['default']['legal']['country'] = 'SK';
    $r40vStoresProp->setValue(null, $r40vOss);
    Bootstrap::initStoreContext('/', '', 'default');
    VatService::reset();
    $pdo->exec("DELETE FROM products WHERE sku = 'R40V-EBOOK'");
    $pdo->exec("DELETE FROM digital_assets WHERE sku = 'R40V-EBOOK'");
    $pdo->exec("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (99401, 'default', 1, 'R40V e-book', 'r40v-ebook', 'R40V-EBOOK', 'downloadable', 1230, 'eur', 'active', 0, 'continue')");
    $pdo->exec("INSERT INTO digital_assets (sku, asset_key, label, storage_path, download_name, content_type, download_limit, expiry_seconds, active)
        VALUES ('R40V-EBOOK', 'pdf', 'PDF', 'r40v.pdf', 'r40v.pdf', 'application/pdf', 2, 3600, 1)");
    $_SESSION['cart'] = [];
    // Placed by an Austrian consumer: billed in AT. Then the record of the checkout: billed in SK, IP in AT, declared AT.
    $r40vPlaced = ManualOrder::create(ManualOrder::parseLines('R40V-EBOOK 1')['lines'], ['name' => 'R40V Leser', 'email' => 'r40v-ebook@example.com',
        'country' => 'AT', 'street' => 'Ring 1', 'city' => 'Wien', 'zip' => '1010', 'shipping_method' => 'digital', 'payment_method' => 'bank_transfer', 'locale' => 'de'], false);
    $r40vOrderIds[] = $r40vEbookId = (int)($r40vPlaced['order_id'] ?? 0);
    $r40vRow = Orders::byId($r40vEbookId);
    $r40vSnapshot = json_decode((string)($r40vRow['financial_snapshot'] ?? ''), true) ?: [];
    $r40vSnapshot['digital_residence'] = ['billing' => 'SK', 'ip' => 'AT', 'declared' => 'AT'];
    $r40vAddr = json_decode((string)$r40vRow['shipping_addr'], true) ?: [];
    $r40vAddr['country'] = 'SK';
    $pdo->prepare('UPDATE orders SET financial_snapshot = ?, shipping_addr = ? WHERE id = ?')->execute([json_encode($r40vSnapshot), json_encode($r40vAddr), $r40vEbookId]);
    $r40vScenario = (string)($r40vRow['vat_scenario'] ?? '');
    $r40vEdit = ManualOrder::editItems($r40vEbookId, ManualOrder::parseLines('R40V-EBOOK 2')['lines']);
    assert_equals([true, VatService::SCENARIO_OSS, true, $r40vScenario], [$r40vPlaced['ok'], $r40vScenario, $r40vEdit['ok'], (string)(Orders::byId($r40vEbookId)['vat_scenario'] ?? '')],
        'R40 edit: an e-book-only order billed in SK and taxed in AT on its IP and declaration is edited at Austrian VAT (was "VAT changed": ' . ($r40vEdit['error'] ?? 'ok') . ')');
    $r40vManual = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/ManualOrder.php');
    assert_true(str_contains($r40vManual, "unset(\$buyer['placed_ip_country'])"), 'R40 edit: a new manual order cannot post an IP country of its own');
} catch (Throwable $r40vE) {
    assert_true(false, 'R40 VAT low (' . get_class($r40vE) . ': ' . $r40vE->getMessage() . ' @ ' . basename($r40vE->getFile()) . ':' . $r40vE->getLine() . ')');
} finally {
    foreach ($r40vOrderIds as $r40vId) {
        if ($r40vId <= 0) continue;
        if ((string)(Orders::byId($r40vId)['status'] ?? '') === 'pending') OrderWorkflow::transition($r40vId, 'cancelled', 'R40 VAT fixture');
    }
    $pdo->exec("DELETE FROM invoices WHERE number = 'R40V-CZK'");
    $pdo->exec("DELETE FROM orders WHERE order_number = 'R40V-CZK'");
    $pdo->exec("DELETE FROM vat_id_cache WHERE vat_id LIKE 'CZ4040%'");
    $pdo->exec("DELETE FROM shipping_methods WHERE code = 'r40v-pick'");
    unset($_SERVER['HTTP_CF_IPCOUNTRY']);
    if ($r40vRemote === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $r40vRemote;
    if (($r40vHistory ?? null) !== null) {
        $pdo->exec('DELETE FROM eur_exchange_rate_history');
        $r40vRestoreHistory = $pdo->prepare('INSERT INTO eur_exchange_rate_history (currency, as_of, rate) VALUES (?, ?, ?)');
        foreach ($r40vHistory as $r40vHistRow) $r40vRestoreHistory->execute($r40vHistRow);
    }
    $r40vCfg->setValue(null, $r40vCfgBefore);
    $r40vStoresProp->setValue(null, $r40vStores);
    Bootstrap::initStoreContext('/', '', 'default');
    VatService::reset();
    EurRates::reset();
    if ($r40vCart === null) unset($_SESSION['cart']); else $_SESSION['cart'] = $r40vCart;
}
