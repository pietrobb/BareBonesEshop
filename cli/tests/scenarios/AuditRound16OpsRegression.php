<?php

// Review round 16 (2026-11-04) "tests, doctor, restore". Medium: the suite stopped running after `setup.php install`
// (it read the installed stores.json and config.php); the doctors did not report an nginx site config without the deny
// rules of deploy/nginx.conf, nor carrier webhooks hit by the new shipping_webhook_require_timestamp default; and
// cli/restore.php refused an absolute backup path ("path must be relative") without saying what it takes.
echo "\n--- Audit round 16: ops (doctor nginx denies, carrier webhook timestamp default, restore by absolute path) ---\n";
$r16oApp = dirname(TEST_CLI_DIR);
$r16oRoot = sys_get_temp_dir() . '/bbe-r16o-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($r16oRoot, 0700, true);
$r16oFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R16 ops {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};

// The suite runs on its own data directory: never the installed shop's stores.json and legal texts (private_runtime_dir).
$r16oInstalled = (string)((require AppConfig::path($r16oApp))['private_runtime_dir'] ?? '');
assert_true(DataOverrides::dir() === str_replace('\\', '/', TEST_DATA_DIR) && DataOverrides::dir() !== rtrim(str_replace('\\', '/', $r16oInstalled), '/'),
    'R16 ops tests: the suite reads its own data directory, not config.php\'s private_runtime_dir (was: the installed stores.json hid every sample store)');
assert_equals((string)file_get_contents($r16oApp . '/stores.example.json'), (string)file_get_contents(DataOverrides::storesPath()),
    'R16 ops tests: the suite\'s stores.json is the shipped stores.example.json');
DataOverrides::setDirForTesting($r16oRoot . '/scenario-data');
DataOverrides::setDirForTesting(null);
assert_equals(str_replace('\\', '/', TEST_DATA_DIR), DataOverrides::dir(), 'R16 ops tests: a scenario that resets its own data directory returns to the suite\'s, not the installed one');
assert_equals(str_replace('\\', '/', $r16oRoot) . '/given', DataOverrides::dir(['private_runtime_dir' => $r16oRoot . '/given']), 'R16 ops tests: a config passed explicitly keeps its own private_runtime_dir');

// restore.php takes the path --list prints; an absolute path to a file in the local backup storage is accepted too.
try {
    $r16oLivePath = $r16oRoot . '/live.db';
    $r16oLive = new PDO('sqlite:' . $r16oLivePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r16oLive->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT NOT NULL)');
    $r16oLive->exec('CREATE TABLE schema_migrations (name TEXT PRIMARY KEY)');
    BackupService::init($r16oLive, ['enabled' => true, 'compression' => false, 'log_file' => 'backup.log', 'storage_dir' => $r16oRoot . '/storage'], $r16oRoot);
    $r16oLive->exec("INSERT INTO notes (body) VALUES ('backed up')");
    $r16oRemote = (string)BackupService::create(['label' => 'scheduled'])['remote_path'];
    $r16oLive->exec("INSERT INTO notes (body) VALUES ('live only')");
    $r16oLive = null;
    $r16oAbsolute = BackupService::storage()->localPath($r16oRemote);
    assert_equals($r16oRemote, BackupService::storagePath($r16oRemote), 'R16 ops restore: the relative path --list prints is taken as it is');
    assert_equals($r16oRemote, BackupService::storagePath($r16oAbsolute), 'R16 ops restore: an absolute path to a backup in the storage is made relative (was: "path must be relative")');
    BackupService::restore(BackupService::storagePath($r16oAbsolute));
    assert_equals(['backed up'], (new PDO('sqlite:' . $r16oLivePath))->query('SELECT body FROM notes ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
        'R16 ops restore: the backup named by its absolute path is restored');
    file_put_contents($r16oRoot . '/elsewhere.db', 'x');
    foreach ([$r16oRoot . '/elsewhere.db' => 'a file outside the storage', $r16oRoot . '/storage/missing.db' => 'a missing file'] as $r16oPath => $r16oWhat) {
        $r16oError = '';
        try {
            BackupService::storagePath($r16oPath);
        } catch (RuntimeException $e) {
            $r16oError = $e->getMessage();
        }
        assert_true(str_contains($r16oError, 'not a backup in the backup storage') && str_contains($r16oError, '--list'),
            "R16 ops restore: {$r16oWhat} is refused with what restore.php takes ({$r16oError})");
    }
    $r16oCli = (string)file_get_contents($r16oApp . '/cli/restore.php');
    assert_true(str_contains($r16oCli, 'BackupService::storagePath($remotePath)'), 'R16 ops restore: cli/restore.php resolves the given path through storagePath');
    assert_true(str_contains((string)file_get_contents($r16oApp . '/README.md'), 'relative to the backup storage'), 'R16 ops restore: README says which path restore.php takes');
} catch (Throwable $e) {
    $r16oFail('restore', $e);
} finally {
    BackupService::reset();
}

// The doctors compare the live nginx site config with the deny rules of the shipped deploy/nginx.conf.
try {
    $r16oShipped = (string)file_get_contents($r16oApp . '/deploy/nginx.conf');
    assert_equals([], UpgradeChecks::nginxConfMissingDenies($r16oShipped, $r16oShipped), 'R16 ops doctor: the shipped nginx sample has all its own deny rules');
    $r16oOld = (string)preg_replace('/\|lock\|tmp(?=\|ini)/', '', $r16oShipped);
    $r16oOld = (string)preg_replace('/^\s*location ~\* \(\?:\^\|\/\)\(\?:php_\)\?error_log\$ \{ return 403; \}\s*$/m', '', $r16oOld);
    assert_true($r16oOld !== $r16oShipped, 'R16 ops doctor: the test builds an older nginx sample (no *.lock/*.tmp, no error_log deny)');
    $r16oMissing = UpgradeChecks::nginxConfMissingDenies($r16oOld, $r16oShipped);
    assert_true(count($r16oMissing) >= 2 && (bool)array_filter($r16oMissing, static fn(string $l): bool => str_contains($l, 'lock|tmp'))
        && (bool)array_filter($r16oMissing, static fn(string $l): bool => str_contains($l, 'error_log')),
        'R16 ops doctor: an nginx config without the *.lock/*.tmp and error_log denies is reported (' . count($r16oMissing) . ' rules)');
    assert_equals([], UpgradeChecks::nginxConfMissingDenies(str_replace('{ return 403; }', "{\n        return   403;\n    }", $r16oShipped), $r16oShipped),
        'R16 ops doctor: spacing and line breaks inside a deny rule do not count as missing');
    file_put_contents($r16oRoot . '/old-site.conf', $r16oOld);
    file_put_contents($r16oRoot . '/site.conf', $r16oShipped);
    assert_true(UpgradeChecks::nginxMissingDenies([], $r16oApp) === null && UpgradeChecks::nginxMissingDenies(['operations' => ['nginx_config' => $r16oRoot . '/none.conf']], $r16oApp) === null,
        'R16 ops doctor: no or an unreadable nginx_config is skipped');

    // A carrier webhook secret without an explicit shipping_webhook_require_timestamp (the default became true).
    $r16oHooks = ['shipping_webhook_secrets' => ['packeta' => str_repeat('p', 32), 'gls' => '']];
    assert_equals(['packeta'], UpgradeChecks::carrierWebhooksTimestampDefault($r16oHooks), 'R16 ops doctor: a carrier with a webhook secret and the timestamp default is reported');
    assert_equals([], UpgradeChecks::carrierWebhooksTimestampDefault($r16oHooks + ['shipping_webhook_require_timestamp' => true]), 'R16 ops doctor: an explicit true passes');
    assert_equals([], UpgradeChecks::carrierWebhooksTimestampDefault($r16oHooks + ['shipping_webhook_require_timestamp' => false]), 'R16 ops doctor: an explicit false (body-only carrier) passes');
    assert_equals([], UpgradeChecks::carrierWebhooksTimestampDefault(['shipping_webhook_secrets' => []]), 'R16 ops doctor: no carrier webhook, nothing to report');

    $r16oBase = require AppConfig::path($r16oApp);
    unset($r16oBase['shipping_webhook_require_timestamp']);
    $r16oDoctor = static function (array $config) use ($r16oApp): array {
        $checks = [];
        foreach (Setup::doctor($config, $r16oApp, Db::get())['checks'] as $check) $checks[$check['name']] = $check;
        return $checks;
    };
    $r16oWarn = $r16oDoctor(array_replace($r16oBase, $r16oHooks, ['operations' => ['nginx_config' => $r16oRoot . '/old-site.conf']]));
    assert_true(($r16oWarn['upgrade.nginx_denies']['status'] ?? '') === 'warn' && str_contains((string)$r16oWarn['upgrade.nginx_denies']['message'], 'lock|tmp'),
        'R16 ops doctor: setup doctor warns about the missing nginx deny rules and names them (was: only the .php fallback was checked)');
    assert_true(($r16oWarn['upgrade.shipping_webhook_timestamp']['status'] ?? '') === 'warn' && str_contains((string)$r16oWarn['upgrade.shipping_webhook_timestamp']['message'], 'packeta'),
        'R16 ops doctor: setup doctor warns that packeta webhooks now need a signed X-Shipping-Timestamp');
    $r16oOk = $r16oDoctor(array_replace($r16oBase, $r16oHooks, ['shipping_webhook_require_timestamp' => true, 'operations' => ['nginx_config' => $r16oRoot . '/site.conf']]));
    assert_true(($r16oOk['upgrade.nginx_denies']['status'] ?? '') === 'pass' && ($r16oOk['upgrade.shipping_webhook_timestamp']['status'] ?? '') === 'pass',
        'R16 ops doctor: the current nginx sample and an explicit timestamp setting pass');

    $r16oUpdater = [];
    @mkdir($r16oRoot . '/update-state', 0700, true);
    $r16oUpdaterConfig = array_replace($r16oBase, $r16oHooks, ['operations' => ['nginx_config' => $r16oRoot . '/old-site.conf']]);
    foreach ((new Updater($r16oApp, $r16oRoot . '/update-state', Db::get()))->doctor(null, null, false, $r16oUpdaterConfig)['checks'] as $r16oCheck) {
        $r16oUpdater[$r16oCheck['name']] = $r16oCheck['level'];
    }
    assert_true(($r16oUpdater['nginx_denies'] ?? '') === 'warn' && ($r16oUpdater['shipping_webhook_timestamp'] ?? '') === 'warn',
        'R16 ops doctor: cli/update.php doctor reports both before the update (' . json_encode($r16oUpdater) . ')');
    $r16oPreflight = (string)file_get_contents($r16oApp . '/cli/staging-preflight.php');
    assert_true(str_contains($r16oPreflight, 'UpgradeChecks::nginxMissingDenies($cfg, $root)') && str_contains($r16oPreflight, 'UpgradeChecks::carrierWebhooksTimestampDefault($cfg)'),
        'R16 ops doctor: staging preflight runs both checks');
} catch (Throwable $e) {
    $r16oFail('doctor', $e);
}

// Medium: the 2.1.1 notes had no "Upgrade notes" (migrations, maintenance window, theme and signing changes). Every
// release section from 2.1.1 on has them, they list the release's migrations, and they give the shipped key's fingerprint.
try {
    $r16oNotes = str_replace("\r\n", "\n", (string)file_get_contents($r16oApp . '/CHANGELOG.md'));
    $r16oFingerprint = UpdateKeyRotation::fingerprint($r16oApp . '/releases/update-public.key');
    foreach ([
        '2.1.2' => ['20261104120000_back_in_stock_double_opt_in', 'customers:read', 'nginx_config', 'verify-tag v2.1.2', 'update-2.1.1-2.1.2'],
        '2.1.1' => ['20261102090000_variant_digital', '20261103090000_order_item_digital', '20261103150000_fulfillment_status_grant',
            'maintenance', 'rotate-key', '{{account.page_urls.<page>}}', 'pricing.legacy_rules'],
    ] as $r16oVersion => $r16oNeedles) {
        $r16oStart = strpos($r16oNotes, "\n## {$r16oVersion} ");
        $r16oEnd = $r16oStart === false ? false : strpos($r16oNotes, "\n## ", $r16oStart + 4);
        $r16oSection = $r16oStart === false ? '' : substr($r16oNotes, $r16oStart, ($r16oEnd ?: strlen($r16oNotes)) - $r16oStart);
        $r16oUpgrade = strpos($r16oSection, '### Upgrade notes');
        $r16oChanges = strpos($r16oSection, '### Changes');
        assert_true($r16oUpgrade !== false && $r16oChanges !== false && $r16oUpgrade < $r16oChanges,
            "R16 ops notes: {$r16oVersion} has Upgrade notes before its changes");
        $r16oUpgradeText = $r16oUpgrade === false ? '' : substr($r16oSection, $r16oUpgrade, ($r16oChanges ?: strlen($r16oSection)) - $r16oUpgrade);
        foreach ($r16oNeedles as $r16oNeedle) {
            // Migrations belong in the upgrade notes; the signed tag and package names are in the release's introduction.
            $r16oWhere = str_starts_with($r16oNeedle, '2026') ? $r16oUpgradeText : $r16oSection;
            assert_true(str_contains($r16oWhere, $r16oNeedle), "R16 ops notes: {$r16oVersion} names {$r16oNeedle}");
        }
        assert_true(preg_match('/^ {4}([0-9a-f]{64})\r?$/m', $r16oSection, $m) === 1 && $m[1] === $r16oFingerprint,
            "R16 ops notes: {$r16oVersion} gives the fingerprint update.php fingerprint prints for the shipped key");
    }
    // Later work adds entries under Unreleased; what matters is that no entry released in 2.1.2 is still listed there.
    $r16oUnreleasedAt = strpos($r16oNotes, "\n## Unreleased\n");
    $r16oReleasedAt = strpos($r16oNotes, "\n## 2.1.2 ");
    assert_true($r16oUnreleasedAt !== false && $r16oReleasedAt !== false && $r16oUnreleasedAt < $r16oReleasedAt,
        'R16 ops notes: Unreleased comes before 2.1.2');
    $r16oReleasedEnd = $r16oReleasedAt === false ? false : strpos($r16oNotes, "\n## ", $r16oReleasedAt + 4);
    $r16oReleased = $r16oReleasedAt === false ? '' : substr($r16oNotes, $r16oReleasedAt, ($r16oReleasedEnd ?: strlen($r16oNotes)) - $r16oReleasedAt);
    $r16oUnreleased = ($r16oUnreleasedAt === false || $r16oReleasedAt === false) ? '' : substr($r16oNotes, $r16oUnreleasedAt, $r16oReleasedAt - $r16oUnreleasedAt);
    // Compare each entry's whole first line: titles repeat across rounds ("Low findings" in rounds 15 and 16).
    preg_match_all('/^- \*\*.+$/m', str_replace("\r\n", "\n", $r16oUnreleased), $r16oTitles);
    $r16oReleasedLines = explode("\n", str_replace("\r\n", "\n", $r16oReleased));
    assert_equals([], array_values(array_filter($r16oTitles[0], static fn(string $t): bool => in_array($t, $r16oReleasedLines, true))),
        'R16 ops notes: nothing released in 2.1.2 is left under Unreleased');
} catch (Throwable $e) {
    $r16oFail('release notes', $e);
}
