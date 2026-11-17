<?php

// Review round 15 (R41, low 6-8):
// 6. The VIES holder check dropped "ing", "mag", "doc", "bc" … anywhere, so "ING Bank N.V." matched "Bank Austria" and
//    "Doc Morris" "Morris Garages". Titles are dropped only written as titles (with a dot, or after a comma).
// 7. An order its declaration of residence decided (placed before R40, where two matching items now outrank it) could not
//    be edited ("VAT changed"); the edit keeps the declaration deciding.
// 8. trusted_proxies took exact addresses only: a CDN's CIDR range never matched, and the IP country header was dropped
//    without a word from the doctor. An export with a VAT ID VIES registers to another name told the buyer VAT was
//    charged for it.
echo "\n--- R41 VAT low: holder titles, edit keeps the declaration, CIDR trusted proxies, export reason ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r41vCfg = new ReflectionProperty(Security::class, 'cfg');
$r41vCfgBefore = $r41vCfg->getValue();
$r41vServer = $_SERVER;
try {
    // 6. Titles only as titles.
    $pdo->exec("DELETE FROM vat_id_cache WHERE vat_id LIKE 'ATU4141%' OR vat_id LIKE 'CZ4141%'");
    $r41vCache = $pdo->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES (?, ?, 'valid', ?, ?, 86400)");
    $r41vCache->execute(['ATU41410001', 'AT', 'Bank Austria', gmdate('Y-m-d H:i:s')]);
    $r41vCache->execute(['ATU41410002', 'AT', 'Morris Garages', gmdate('Y-m-d H:i:s')]);
    $r41vCache->execute(['CZ4141414141', 'CZ', 'Novák Jan', gmdate('Y-m-d H:i:s')]);
    $r41vHolder = [
        'ING Bank N.V.' => VatService::vatIdHolderMismatch('ATU41410001', 'ING Bank N.V.'),
        'Doc Morris' => VatService::vatIdHolderMismatch('ATU41410002', 'Doc Morris'),
        'Mag Bank' => VatService::vatIdHolderMismatch('ATU41410001', 'Mag Bank Austria'),
    ];
    assert_equals(['ING Bank N.V.' => 'name', 'Doc Morris' => 'name', 'Mag Bank' => 'name'], $r41vHolder,
        'R41 VIES holder: "ING", "Doc", "Mag" without a dot are words of the name, not titles — another company does not match');
    $r41vTitled = [];
    foreach (['Ing. Jan Novák', 'Mgr. Novák Jan, Ph.D.', 'Jan Novák, MBA', 'doc. Ing. Jan Novák, CSc.', 'Dipl.-Ing. Jan Novák', 'Petr Novák'] as $r41vName) {
        $r41vTitled[$r41vName] = VatService::vatIdHolderMismatch('CZ4141414141', $r41vName);
    }
    assert_equals(['Ing. Jan Novák' => null, 'Mgr. Novák Jan, Ph.D.' => null, 'Jan Novák, MBA' => null, 'doc. Ing. Jan Novák, CSc.' => null, 'Dipl.-Ing. Jan Novák' => null, 'Petr Novák' => 'name'], $r41vTitled,
        'R41 VIES holder: titles written as titles are still dropped (R40, control)');

    // 7. The declaration that decided an order still decides its edit.
    $r41vEdit = CheckoutService::digitalResidence(false, 'SK', 'AT', false, 'SK', 'AT', true);
    $r41vNew = CheckoutService::digitalResidence(false, 'SK', 'AT', false, 'SK', 'AT');
    assert_equals([['SK', false], ['AT', false]], [[$r41vEdit['country'], $r41vEdit['required']], [$r41vNew['country'], $r41vNew['required']]],
        'R41 edit: an order its declaration (SK) decided against delivery and IP (AT) keeps SK on edit; a new order is decided by the two items (R40)');
    $r41vManual = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/ManualOrder.php');
    assert_true(str_contains($r41vManual, "'residence_decided' => isset(\$stored['digital_residence']['declared'])") && str_contains($r41vManual, "unset(\$buyer['residence_decided'])"),
        'R41 edit: editItems() passes the stored declaration as deciding; a new manual order cannot post it');

    // 8. CIDR ranges in trusted_proxies.
    $r41vCfg->setValue(null, ['trusted_proxies' => ['173.245.48.0/20', '2400:cb00::/32', '10.41.0.1']] + (array)(require AppConfig::path(dirname(TEST_CLI_DIR))));
    $r41vMatches = array_map(static fn(string $ip): bool => Security::isTrustedProxy($ip), ['173.245.48.1', '173.245.63.254', '173.245.64.1', '2400:cb00:2049::1', '2400:cb01::1', '10.41.0.1', '10.41.0.2', '']);
    assert_equals([true, true, false, true, false, true, false, false], $r41vMatches, 'R41 trusted proxies: IPv4 and IPv6 CIDR ranges and exact addresses match; neighbours do not');
    $_SERVER['REMOTE_ADDR'] = '173.245.50.7';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.41';
    $_SERVER['HTTP_CF_IPCOUNTRY'] = 'AT';
    assert_equals(['198.51.100.41', 'AT'], [Security::clientIp(), CheckoutService::ipCountry(['vat_ip_country_header' => 'CF-IPCountry'])],
        'R41 trusted proxies: behind Cloudflare (a range of trusted_proxies) the client IP and the IP country header are read');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.99';
    assert_equals(['198.51.100.99', ''], [Security::clientIp(), CheckoutService::ipCountry(['vat_ip_country_header' => 'CF-IPCountry'])],
        'R41 trusted proxies: a client outside the ranges still cannot claim either (control)');
    $r41vStore = ['vat_ip_country_header' => 'CF-IPCountry'];
    $r41vChecks = [
        Setup::ipCountryHeaderCheck(['trusted_proxies' => []], 'r41', $r41vStore)['status'] ?? null,
        Setup::ipCountryHeaderCheck(['trusted_proxies' => ['cloudflare']], 'r41', $r41vStore)['status'] ?? null,
        Setup::ipCountryHeaderCheck(['trusted_proxies' => ['173.245.48.0/20', '173.245.48.0/40']], 'r41', $r41vStore)['status'] ?? null,
        Setup::ipCountryHeaderCheck(['trusted_proxies' => ['173.245.48.0/20']], 'r41', $r41vStore)['status'] ?? null,
        Setup::ipCountryHeaderCheck(['trusted_proxies' => []], 'r41', []),
    ];
    assert_equals(['warn', 'warn', 'warn', 'pass', null], $r41vChecks,
        'R41 trusted proxies: the doctor warns when a store reads the IP country header without trusted proxies or with entries that never match');

    // 8. An export's reason does not say VAT is charged for the VAT ID.
    $r41vExport = CheckoutService::residenceView(['context' => ['seller_country' => 'SK'], 'scenario' => ['scenario' => VatService::SCENARIO_EXPORT]],
        ['residence_choices' => [], 'vat_id_status' => 'mismatch_name'], [], '');
    $r41vDomestic = CheckoutService::residenceView(['context' => ['seller_country' => 'SK'], 'scenario' => ['scenario' => VatService::SCENARIO_DOMESTIC]],
        ['residence_choices' => [], 'vat_id_status' => 'mismatch_name'], [], '');
    $r41vHolderText = I18n::t('checkout.vat_reason.vat_id_holder');
    assert_true(!str_contains($r41vExport['vat_reason'], $r41vHolderText) && str_contains($r41vExport['vat_reason'], I18n::t('checkout.vat_reason.export')) && str_contains($r41vDomestic['vat_reason'], $r41vHolderText),
        'R41 export: an export names the export and not "VAT charged for the VAT ID"; a domestic sale still does (got ' . $r41vExport['vat_reason'] . ')');
} catch (Throwable $r41vE) {
    assert_true(false, 'R41 VAT low (' . get_class($r41vE) . ': ' . $r41vE->getMessage() . ' @ ' . basename($r41vE->getFile()) . ':' . $r41vE->getLine() . ')');
} finally {
    $r41vCfg->setValue(null, $r41vCfgBefore);
    $_SERVER = $r41vServer;
    $pdo->exec("DELETE FROM vat_id_cache WHERE vat_id LIKE 'ATU4141%' OR vat_id LIKE 'CZ4141%'");
}
