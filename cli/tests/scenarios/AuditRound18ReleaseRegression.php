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
    'configuration FAIL', '.maintenance', '--accept-data-loss', 'version 2.3.1', 'confirmed e-mail address', 'shipping.json',
    'field_too_long', 'MAIL_REPLY_TO', 'legal.email', '*.config.js', 'operations.nginx_config', "catalog's currency",
] as $r18rNeedle) {
    assert_true(str_contains($r18rNotes, $r18rNeedle), "R18 release: the 2.1.3 upgrade notes cover {$r18rNeedle}");
}
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
];
$r18rVersion = (string)($r18rManifest['version'] ?? '');
assert_equals($r18rFingerprints[$r18rVersion] ?? '(no fingerprint recorded for theme version ' . $r18rVersion . ')', hash_final($r18rHash),
    'R18 release: the default theme assets match the fingerprint recorded for theme.json version ' . $r18rVersion);
assert_true(array_filter(Template::assets()['css'], static fn(string $u): bool => str_ends_with($u, 'storefront.css?v=2.3.1')) !== [],
    'R18 release: storefront.css is served as ?v=2.3.1');
