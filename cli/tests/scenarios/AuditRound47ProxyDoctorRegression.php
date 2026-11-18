<?php

// Review round 17 (R47, low 6): R44 ignores a public trusted_proxies range wider than IPv4 /16 (IPv6 /32), among them
// ranges Cloudflare publishes (104.16.0.0/13, 104.24.0.0/14, 162.158.0.0/15, 2a06:98c0::/29). Only setup.php doctor
// failed them, after the update; update.php doctor said nothing, so the update went through and every request through
// those ranges counted as the proxy's address. The update doctor now fails them and names each.
echo "\n--- R47: update.php doctor fails trusted_proxies ranges this release ignores ---\n";

$r47pBase = test_fixture_path('r47-proxy-doctor');
mkdir($r47pBase . '/app', 0755, true);
mkdir($r47pBase . '/updates', 0700);
$r47pCheck = static function (array $proxies) use ($r47pBase): ?array {
    $config = ['trusted_proxies' => $proxies];
    foreach ((new Updater($r47pBase . '/app', $r47pBase . '/updates'))->doctor(null, null, false, $config)['checks'] as $check) {
        if ($check['name'] === 'trusted_proxies') return $check;
    }
    return null;
};

$r47pCloudflare = $r47pCheck(['173.245.48.0/20', '104.16.0.0/13', '104.24.0.0/14', '162.158.0.0/15', '10.0.0.0/8',
    '2400:cb00::/32', '2a06:98c0::/29', '203.0.113.7']);
assert_equals('fail', $r47pCloudflare['level'] ?? null, 'R47 proxy doctor: Cloudflare\'s /13, /14, /15 and /29 fail update.php doctor (was: no check)');
$r47pNamed = [];
foreach (['104.16.0.0/13', '104.24.0.0/14', '162.158.0.0/15', '2a06:98c0::/29', '173.245.48.0/20', '10.0.0.0/8', '2400:cb00::/32', '203.0.113.7'] as $r47pRange) {
    $r47pNamed[$r47pRange] = str_contains((string)($r47pCloudflare['message'] ?? ''), $r47pRange);
}
assert_equals(['104.16.0.0/13' => true, '104.24.0.0/14' => true, '162.158.0.0/15' => true, '2a06:98c0::/29' => true,
    '173.245.48.0/20' => false, '10.0.0.0/8' => false, '2400:cb00::/32' => false, '203.0.113.7' => false], $r47pNamed,
    'R47 proxy doctor: the message names exactly the ignored ranges (not /16+, /32+, single addresses or a whole private block)');
assert_equals('ok', $r47pCheck(['173.245.48.0/20', '104.16.0.0/16', '10.0.0.0/8', '2a06:98c0::/32'])['level'] ?? null,
    'R47 proxy doctor: ranges this release trusts pass');
assert_equals(null, $r47pCheck([]), 'R47 proxy doctor: a shop without trusted_proxies gets no proxy check');

// R47 (low 7): the release notes tell an upgrading shop to split the ranges (its 2.1.2 doctor does not check them) and
// describe the new admin, naming no other product it was modelled on.
$r47pChangelog = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/CHANGELOG.md');
$r47pUnreleased = substr($r47pChangelog, 0, (int)strpos($r47pChangelog, "\n## 2.1.2"));
assert_equals([true, true, false], [
    str_contains($r47pUnreleased, 'Split wide `trusted_proxies` ranges before `apply`'),
    str_contains($r47pUnreleased, 'The admin has a new look'),
    stripos($r47pChangelog . file_get_contents(dirname(TEST_CLI_DIR) . '/README.md'), 'MoneyERPApi') !== false,
], 'R47 release notes: an upgrade note on wide trusted_proxies, an entry for the new admin, no "MoneyERPApi"');
