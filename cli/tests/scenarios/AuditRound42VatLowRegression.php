<?php
$pdo ??= test_commerce_fixture();

// Review round 16 (R42, low 5 and 6):
// 5. trusted_proxies took 0.0.0.0/0 and ::/0 (every client could name its own IP address and IP country), compared single
//    IPv6 addresses as text ("2001:DB8:0::1" did not match "2001:db8::1") and an IPv4-mapped client (::ffff:10.42.3.4,
//    as a dual-stack server reports it) never matched an IPv4 range. Too wide ranges are ignored and the doctor fails.
// 6. The VIES holder check took "Dr.Max" for the title "Dr." and the name "Max": "Dr.Max s.r.o." matched "Max Mustermann".
//    Two-word titles ("Ing. arch.", "Mgr. art.") left "arch"/"art" as words of the name.
echo "\n--- R42 VAT low: too wide / IPv6 / mapped trusted proxies, holder titles joined to the name ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r42vCfg = new ReflectionProperty(Security::class, 'cfg');
$r42vCfgBefore = $r42vCfg->getValue();
$r42vServer = $_SERVER;
try {
    // 5. Trusted proxies.
    $r42vBase = (array)(require AppConfig::path(dirname(TEST_CLI_DIR)));
    $r42vCfg->setValue(null, ['trusted_proxies' => ['0.0.0.0/0', '::/0', '2001:DB8:0::1', '10.42.0.0/16', '::ffff:10.43.0.0/112']] + $r42vBase);
    $r42vMatches = array_map(static fn(string $ip): bool => Security::isTrustedProxy($ip),
        ['198.51.100.1', '2001:db8::5', '2001:db8::1', '::ffff:10.42.3.4', '10.42.3.4', '10.43.1.1', '::ffff:10.43.1.1', '10.44.0.1', 'garbage']);
    assert_equals([false, false, true, true, true, true, true, false, false], $r42vMatches,
        'R42 trusted proxies: 0.0.0.0/0 and ::/0 match no one; IPv6 addresses compare as addresses; IPv4-mapped clients and ranges match their IPv4 form');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.99';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.42';
    $_SERVER['HTTP_CF_IPCOUNTRY'] = 'AT';
    assert_equals(['198.51.100.99', ''], [Security::clientIp(), CheckoutService::ipCountry(['vat_ip_country_header' => 'CF-IPCountry'])],
        'R42 trusted proxies: with 0.0.0.0/0 a client cannot name its IP address or IP country (was: both taken)');
    $_SERVER['REMOTE_ADDR'] = '::ffff:10.42.0.7';
    assert_equals(['203.0.113.42', 'AT'], [Security::clientIp(), CheckoutService::ipCountry(['vat_ip_country_header' => 'CF-IPCountry'])],
        'R42 trusted proxies: a proxy reported IPv4-mapped is trusted by its IPv4 range');

    assert_equals(['0.0.0.0/0', '::/0', '1.0.0.0/7'], Security::tooWideTrustedProxies(['trusted_proxies' => ['0.0.0.0/0', '::/0', '10.0.0.0/8', 'fc00::/7', '1.0.0.0/7', '::ffff:10.43.0.0/112', 'cloudflare']]),
        'R42 trusted proxies: ranges wider than IPv4 /8 or IPv6 /7 are named too wide');
    assert_equals([], Security::invalidTrustedProxies(['trusted_proxies' => ['::ffff:10.43.0.0/112', '2001:DB8:0::1', '0.0.0.0/0']]),
        'R42 trusted proxies: mapped ranges and upper-case IPv6 are valid entries');
    $r42vStore = ['vat_ip_country_header' => 'CF-IPCountry'];
    $r42vChecks = [
        Setup::ipCountryHeaderCheck(['trusted_proxies' => ['0.0.0.0/0']], 'r42', $r42vStore)['status'] ?? null,
        Setup::ipCountryHeaderCheck(['trusted_proxies' => ['::/0', '173.245.48.0/20']], 'r42', $r42vStore)['status'] ?? null,
    ];
    assert_equals(['fail', 'pass'], $r42vChecks, 'R42 trusted proxies: the doctor fails a store whose IP country header only a too wide range would trust');
    $r42vSetup = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Setup.php');
    assert_true(str_contains($r42vSetup, "Security::tooWideTrustedProxies(\$config)") && str_contains($r42vSetup, "'config.trusted_proxies'"),
        'R42 trusted proxies: the doctor reports too wide trusted_proxies as config.trusted_proxies (fail)');

    // 6. Titles joined to the name are not titles.
    $pdo->exec("DELETE FROM vat_id_cache WHERE vat_id LIKE 'DE4242%' OR vat_id LIKE 'CZ4242%'");
    $r42vCache = $pdo->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES (?, ?, 'valid', ?, ?, 86400)");
    $r42vCache->execute(['DE424242424', 'DE', 'Max Mustermann', gmdate('Y-m-d H:i:s')]);
    $r42vCache->execute(['CZ4242424242', 'CZ', 'Novák Jan', gmdate('Y-m-d H:i:s')]);
    $r42vHolder = [];
    foreach (['DE424242424' => ['Dr.Max s.r.o.', 'Dr. Max Mustermann', 'Max Mustermann'], 'CZ4242424242' => ['Ing. arch. Jan Novák', 'Mgr. art. Jan Novák', 'Ing.arch. Jan Novák', 'Dipl.-Ing. Jan Novák', 'Ing. arch. Petr Novák']] as $r42vId => $r42vNames) {
        foreach ($r42vNames as $r42vName) $r42vHolder[$r42vName] = VatService::vatIdHolderMismatch($r42vId, $r42vName);
    }
    assert_equals(['Dr.Max s.r.o.' => 'name', 'Dr. Max Mustermann' => null, 'Max Mustermann' => null,
        'Ing. arch. Jan Novák' => null, 'Mgr. art. Jan Novák' => null, 'Ing.arch. Jan Novák' => null, 'Dipl.-Ing. Jan Novák' => null, 'Ing. arch. Petr Novák' => 'name'], $r42vHolder,
        'R42 VIES holder: "Dr.Max s.r.o." is not Max Mustermann; two-word titles are dropped whole; another person still does not match');
} catch (Throwable $r42vE) {
    assert_true(false, 'R42 VAT low (' . get_class($r42vE) . ': ' . $r42vE->getMessage() . ' @ ' . basename($r42vE->getFile()) . ':' . $r42vE->getLine() . ')');
} finally {
    $r42vCfg->setValue(null, $r42vCfgBefore);
    $_SERVER = $r42vServer;
    $pdo->exec("DELETE FROM vat_id_cache WHERE vat_id LIKE 'DE4242%' OR vat_id LIKE 'CZ4242%'");
}
