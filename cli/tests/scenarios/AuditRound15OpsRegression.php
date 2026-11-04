<?php

// Round 15 review (2026-11-04) "release process and upgrade". High 7: a v1.8.0 shop adopted exactly per the runbook
// answered 503 on /readyz and /admin.php: its stores.json (no default_store, the main store keyed "sk", no channels)
// stayed as it was, while every migrated row belongs to the store 'default' and the workers, cron and admin open it.
echo "\n--- Audit round 15 (ops): v1.x stores.json upgraded on adoption, doctors report it ---\n";
$r15oFail = static fn(string $what, Throwable $e) => assert_true(false, "R15 ops {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
$r15oBase = test_fixture_path('r15-ops');
$r15oRemove = static function (string $path) use (&$r15oRemove): void {
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
};

try {
    $r15oApp = $r15oBase . '/app';
    $r15oData = $r15oBase . '/private';
    mkdir($r15oApp, 0777, true);
    $r15oLegal = static fn(string $name, string $country): array => ['name' => $name, 'street' => 'Hlavná 1', 'city' => 'Bratislava', 'zip' => '811 01',
        'country' => $country, 'ico' => '12345678', 'dic' => '1020304050', 'ic_dph' => '', 'iban' => '', 'bank' => '', 'email' => 'info@example.sk',
        'phone' => '', 'invoice_prefix' => '', 'payment_terms_days' => 14, 'register_note' => ''];
    // The shape of v1.8.0's stores.json (git show v1.8.0:stores.json), with a merchant's own seller.
    $r15oV18 = ['default_locale' => 'en', 'default_currency' => 'eur', 'stores' => [
        'sk' => ['name' => 'R15 Obchod', 'default_locale' => 'sk', 'currency' => 'eur', 'url_prefix' => '', 'languages' => ['sk', 'cs', 'en'],
            'oss_enabled' => false, 'legal' => $r15oLegal('R15 Legacy s.r.o.', 'SK')],
        'cz' => ['name' => 'R15 Obchod CZ', 'default_locale' => 'cs', 'currency' => 'czk', 'url_prefix' => '/cs', 'languages' => ['cs', 'en'],
            'oss_enabled' => false, 'legal' => $r15oLegal('R15 Legacy CZ s.r.o.', 'CZ')],
        'de' => ['name' => 'R15 Shop', 'default_locale' => 'de', 'currency' => 'eur', 'url_prefix' => '/de', 'languages' => ['de', 'en'],
            'oss_enabled' => false, 'legal' => $r15oLegal('R15 Legacy GmbH', 'DE')],
    ], 'currencies' => ['eur' => ['symbol' => '€', 'decimals' => 2, 'position' => 'after'], 'czk' => ['symbol' => 'Kč', 'decimals' => 0, 'position' => 'after']]];
    $r15oV18Json = json_encode($r15oV18, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($r15oApp . '/stores.json', $r15oV18Json);
    $r15oConfig = ['private_runtime_dir' => $r15oData];
    $r15oDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r15oReady = static fn(): string => (string)(Health::readiness($r15oConfig, $r15oApp, $r15oDb)['checks']['stores'] ?? '');
    $r15oUpdaterCheck = static function (array $config) use ($r15oApp, $r15oBase): ?array {
        $doctor = (new Updater($r15oApp, $r15oBase . '/updates'))->doctor(null, null, false, $config);
        foreach ($doctor['checks'] as $check) if ($check['name'] === 'stores') return [$check['level'], $check['message']];
        return null;
    };

    // Before: what the reviewer saw after the runbook (stores invalid = /readyz 503; no store 'default' for the admin).
    $r15oSetup = array_column(Setup::doctor($r15oConfig, $r15oApp)['checks'] ?? [], null, 'name');
    assert_equals([true, 'invalid', 'fail', true, true],
        [DataOverrides::storesNeedUpgrade($r15oApp, $r15oConfig), $r15oReady(), $r15oSetup['stores.default']['status'] ?? null,
            str_contains((string)($r15oSetup['stores.default']['message'] ?? ''), 'v1.x format'), str_contains((string)($r15oSetup['stores.default']['action'] ?? ''), 'setup.php apply')],
        'R15 high 7: a v1.8.0 stores.json is recognised; readiness fails and setup doctor says how to upgrade it');
    $r15oCheck = $r15oUpdaterCheck($r15oConfig);
    assert_true(($r15oCheck[0] ?? null) === 'warn' && str_contains((string)$r15oCheck[1], 'apply writes the upgraded copy'),
        'R15 high 7: update doctor reports the v1.x stores.json and that apply upgrades it');
    $r15oCheck = $r15oUpdaterCheck([]);
    assert_true(($r15oCheck[0] ?? null) === 'fail' && str_contains((string)$r15oCheck[1], 'private_runtime_dir'),
        'R15 high 7: without a private_runtime_dir update doctor is NOT_READY instead of leaving a shop that answers 503');

    // The upgrade (setup apply, or update apply of an adopted shop): a new private copy, the old file untouched.
    $r15oSeed = DataOverrides::seedStores($r15oApp, $r15oConfig);
    $r15oNew = json_decode((string)@file_get_contents($r15oData . '/stores.json'), true) ?: [];
    assert_true(str_contains((string)($r15oSeed['action'] ?? ''), 'upgraded from the v1.x format') && (string)file_get_contents($r15oApp . '/stores.json') === $r15oV18Json,
        'R15 high 7: the upgraded copy goes to private_runtime_dir, the v1.8.0 file stays unchanged for a rollback');
    assert_equals(['default', ['default', 'cz', 'de'], 'default', 'R15 Legacy s.r.o.', ['default', 'default', 'default'], ['default', 'cz', 'default'], ['storefront', 'api'], ['sk', 'cs', 'en'], 'czk'],
        [$r15oNew['default_store'] ?? null, array_keys($r15oNew['stores'] ?? []), $r15oNew['stores']['default']['id'] ?? null, $r15oNew['stores']['default']['legal']['name'] ?? null,
            array_column($r15oNew['stores'] ?? [], 'catalog_scope'), array_column($r15oNew['stores'] ?? [], 'provider_scope'), $r15oNew['stores']['cz']['channels'] ?? null,
            $r15oNew['stores']['default']['languages'] ?? null, $r15oNew['stores']['cz']['currency'] ?? null],
        'R15 high 7: the main store becomes "default" with its seller; one catalog; own provider scope only for another currency; the API channel; all fields kept');

    // After: the shop runs on it.
    $r15oContext = StoreContext::resolve($r15oNew, '/', '', 'default');
    $r15oCz = StoreContext::resolve($r15oNew, '/cs/', '');
    $r15oApi = StoreContext::resolve($r15oNew, '/', '', 'cz', 'api');
    assert_equals([false, 'ok', 'default', 'eur', 'cz', 'cz', null, 'unchanged'],
        [DataOverrides::storesNeedUpgrade($r15oApp, $r15oConfig), $r15oReady(), $r15oContext->id(), $r15oContext->currency(), $r15oCz->id(), $r15oApi->id(),
            $r15oUpdaterCheck($r15oConfig), DataOverrides::seedStores($r15oApp, $r15oConfig)['action'] ?? null],
        'R15 high 7: after the upgrade readiness passes, the default store, the /cs store and its API resolve, and nothing is upgraded twice');

    $r15oExample = json_decode((string)file_get_contents(dirname(TEST_CLI_DIR) . '/stores.example.json'), true);
    assert_equals(null, DataOverrides::upgradeLegacyStores($r15oExample), 'R15 high 7: a current stores.json is never rewritten');
    $r15oCli = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/cli/update.php');
    assert_true((bool)preg_match('/\$result = \$updater->apply\(\$package\);.*DataOverrides::storesNeedUpgrade\(\$root, \$config\).*DataOverrides::seedStores\(\$root, \$config\)/s', $r15oCli),
        'R15 high 7: update apply upgrades the v1.x stores.json of the adopted shop (--installed) right after the files');
} catch (Throwable $e) {
    $r15oFail('v1.x stores.json', $e);
} finally {
    $r15oRemove($r15oBase);
}

// Medium: the v2.1.0 notes published sha256sum of the key file, which no shop's `update.php fingerprint` shows; tags
// were not signed although the runbooks start from "the signed tag".
try {
    $r15oRoot = dirname(TEST_CLI_DIR);
    $r15oFingerprint = UpdateKeyRotation::fingerprint($r15oRoot . '/releases/update-public.key');
    $r15oNotes = (string)file_get_contents($r15oRoot . '/CHANGELOG.md');
    $r15oNotes210 = substr($r15oNotes, (int)strpos($r15oNotes, '## 2.1.0'));
    assert_true(preg_match('/^ {4}([0-9a-f]{64})\r?$/m', $r15oNotes210, $m) === 1 && $m[1] === $r15oFingerprint,
        'R15 medium: the fingerprint the 2.1.0 notes give is the one update.php fingerprint prints for the shipped key');
    $r15oBuild = (string)file_get_contents($r15oRoot . '/cli/build-update.php');
    assert_true(str_contains($r15oBuild, "'fingerprint'") && str_contains($r15oBuild, 'UpdateKeyRotation::fingerprint($public)'),
        'R15 medium: build-update.php fingerprint prints the value for the release notes with the same function as the shop');
    $r15oOps = (string)file_get_contents($r15oRoot . '/docs/OPERATIONS_RUNBOOK.md');
    $r15oAi = (string)file_get_contents($r15oRoot . '/docs/AI_UPDATE_RUNBOOK.md');
    assert_true(str_contains($r15oOps, 'git tag -s vX.Y.Z') && str_contains($r15oOps, 'git verify-tag vX.Y.Z') && str_contains($r15oAi, 'git verify-tag vX.Y.Z'),
        'R15 medium: the release checklist signs and verifies the tag, and the bootstrap runbook verifies it before exporting');
} catch (Throwable $e) {
    $r15oFail('fingerprint/tags', $e);
}
