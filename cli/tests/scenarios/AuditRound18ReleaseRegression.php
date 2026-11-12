<?php

// Review round 18 (2026-11-06), release preparation (goal #332): upgrade notes for 2.1.3 and the default theme version.

echo "\n--- R18 release: upgrade notes 2.1.2 -> 2.1.3, theme version follows its assets ---\n";

$r18rRoot = dirname(TEST_CLI_DIR);

// 1. The 2.1.3 upgrade notes exist above the 2.1.2 release and warn about what an operator must act on.
$r18rLog = (string)file_get_contents($r18rRoot . '/CHANGELOG.md');
$r18rStart = strpos($r18rLog, '### Upgrade notes (2.1.2 → 2.1.3)');
$r18rPrevious = strpos($r18rLog, '## 2.1.2 — ');
assert_true($r18rStart !== false && $r18rPrevious !== false && $r18rStart < $r18rPrevious,
    'R18 release: CHANGELOG has upgrade notes 2.1.2 -> 2.1.3 above the 2.1.2 release');
$r18rEnd = $r18rStart === false ? false : strpos($r18rLog, "\n### ", $r18rStart + 1);
$r18rNotes = $r18rStart === false ? '' : substr($r18rLog, $r18rStart, ($r18rEnd === false ? strlen($r18rLog) : $r18rEnd) - $r18rStart);
foreach ([
    '"1290" saves 1 290 €', 'mariadb-dump', 'update_mysql_dump_binary', 'update_mysql_client_binary', 'migrate.php apply',
    'configuration FAIL', '.maintenance', '--accept-data-loss', 'confirmed e-mail address', 'shipping.json',
    'field_too_long', 'MAIL_REPLY_TO', 'legal.email', '*.config.js', 'operations.nginx_config', "catalog's currency",
] as $r18rNeedle) {
    assert_true(str_contains($r18rNotes, $r18rNeedle), "R18 release: the 2.1.3 upgrade notes cover {$r18rNeedle}");
}
// R19: 2.1.3 is installed by the 2.1.2 updater, which reopens the shop after any apply (also a failed automatic
// rollback) and refuses neither a configuration FAIL nor a MariaDB client by name: the notes must not promise the
// 2.1.3 updater's behaviour for this step and must tell the operator to close the shop at the web server.
foreach ([
    'installed by the 2.1.2 updater', 'close the shop at the web server', 'return 503;', 'automatic rollback also failed',
    'rolled-back', 'The 2.1.2 updater that installs this release does not refuse', 'The 2.1.2 updater refuses this package',
    'Maintenance window (from the next update',
] as $r18rNeedle) {
    assert_true(str_contains($r18rNotes, $r18rNeedle), "R19 release: the 2.1.3 upgrade notes cover {$r18rNeedle}");
}
assert_true(!str_contains($r18rNotes, '2.1.2 installed the package anyway'),
    'R19 release: the notes do not claim this update is refused on a configuration FAIL');
// Every migration added after 2.1.2 (whose last one is 20261104120000) is named in the notes.
foreach (glob($r18rRoot . '/migrations/*.php') ?: [] as $r18rMigration) {
    $r18rName = basename($r18rMigration, '.php');
    if ($r18rName <= '20261104120000_back_in_stock_double_opt_in') continue;
    assert_true(str_contains($r18rNotes, $r18rName), "R18 release: the 2.1.3 upgrade notes list migration {$r18rName}");
}

// 2. The theme's assets are cached for a year under ?v=<theme.json version>: changed assets need a new version.
//    When this fails after an asset change, bump "version" in themes/default/theme.json and record the new
//    fingerprint for it here (a previous version's fingerprint stays as it was).
$r18rManifest = json_decode((string)file_get_contents($r18rRoot . '/themes/default/theme.json'), true);
$r18rHash = hash_init('sha256');
foreach (['css', 'js'] as $r18rKind) {
    foreach ((array)($r18rManifest['assets'][$r18rKind] ?? []) as $r18rAsset) {
        hash_update($r18rHash, $r18rAsset . "\0" . (string)file_get_contents($r18rRoot . '/themes/default/assets/' . $r18rAsset) . "\0");
    }
}
$r18rFingerprints = [
    '2.3.1' => '5d9f7c8f0c409ef255f301d967d5993bee340031370bfd40730e1a75792802de',
    '2.3.2' => 'b0c2781f99d4115555a359b429ec455c5e2c296bd6a2d999be08005dc047d0b6',
    '2.3.3' => '1ca7a233e2eba8e8fd46f2ceb440ebc266f512d8ab4d55e556974b99caa3f367',
    '2.3.4' => 'e0d157199c149f00946e88d3a92819bc4a029e5975433e78930312497716d520',
    '2.3.5' => '8779200d2d5bf5e7b53ec2884d83d3782edb34b7879df9e30ecea2778acec559',
    '2.3.6' => '9854e3f3ca014ad7475bbb9388f6464cff95b57d2deaba59ab3626739cf7f53d',
    '2.3.7' => '7794a31e849dace20a01b30167dd0b4eddf37d7469f985decdb02e40e166dc78',
    '2.3.8' => '0c328d95755f3dc87eccc5d82fc00dc47bac1f82e5723db722f95847557dc146',
    '2.3.9' => 'dc3b2c72b5de468078239df338a0d4cc68af31c3c1fa2cbbb0343b9048179547',
    '2.3.10' => '7f39669ed66ebda3894df8f0e4000afe699e50f2a4eedf707481d52145eb4743',
    '2.3.11' => 'a6aa0a2368547719902f701325ed6875a5a9b9f14e0f6385cb648b926f1086a7',
];
$r18rVersion = (string)($r18rManifest['version'] ?? '');
assert_equals($r18rFingerprints[$r18rVersion] ?? '(no fingerprint recorded for theme version ' . $r18rVersion . ')', hash_final($r18rHash),
    'R18 release: the default theme assets match the fingerprint recorded for theme.json version ' . $r18rVersion);
assert_true(array_filter(Template::assets()['css'], static fn(string $u): bool => str_ends_with($u, 'storefront.css?v=' . $r18rVersion)) !== [],
    'R18 release: storefront.css is served as ?v=' . $r18rVersion);
assert_true(str_contains($r18rNotes, 'version ' . $r18rVersion), 'R18 release: the 2.1.3 upgrade notes name theme version ' . $r18rVersion);
