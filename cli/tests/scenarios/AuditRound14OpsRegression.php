<?php

// Review round 14 (kolo 6): #4 rotate-key trusted a key given on the command line; #5 Updater rollback as root
// chmod/chown by path; #6 setup install switched off live online payments before it knew the database existed;
// the installer self-check was skipped under any php -S; T20 the updater did not put the shop into maintenance and
// gdpr_events had no retention.
echo "\n--- Audit round 14: updater, installation, maintenance, gdpr_events retention ---\n";

$r14oRepo = dirname(__DIR__, 3);
$r14oRm = static function (string $path) use (&$r14oRm): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $r14oRm($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
};
$r14oRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bbe-r14o-' . bin2hex(random_bytes(4));
@mkdir($r14oRoot, 0700, true);
$r14oError = static function (callable $call): string {
    try { $call(); } catch (Throwable $e) { return $e->getMessage(); }
    return '';
};
$r14oCli = static function (array $args) use ($r14oRepo): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $r14oRepo);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'out' => (string)$out];
};

// #4. The endorsement names the key it replaces, and rotate-key checks it against the configured key only.
if (function_exists('sodium_crypto_sign_keypair')) {
    $r14oPair = static function (): array {
        $pair = sodium_crypto_sign_keypair();
        return ['public' => 'base64:' . base64_encode(sodium_crypto_sign_publickey($pair)), 'secret' => 'base64:' . base64_encode(sodium_crypto_sign_secretkey($pair))];
    };
    $r14oOld = $r14oPair();
    $r14oNew = $r14oPair();
    assert_true(str_contains(UpdateKeyRotation::statement($r14oOld['public'], $r14oNew['public']), UpdateKeyRotation::fingerprint($r14oOld['public'])),
        'R14 #4: the signed rotation statement contains the fingerprint of the key being replaced');
    $r14oRotate = $r14oCli(['cli/update.php', 'rotate-key', '--public-key=' . $r14oNew['public'], '--new-key=' . $r14oNew['public'],
        '--endorsement=' . UpdateKeyRotation::endorse($r14oNew['secret'], $r14oNew['public'])]);
    assert_true($r14oRotate['code'] !== 0 && str_contains($r14oRotate['out'], 'no --public-key'),
        'R14 #4: rotate-key refuses --public-key, so a key cannot endorse itself in place of update_public_key (' . trim($r14oRotate['out']) . ')');
}

// #5. Rollback as root: no chown/chgrp/chmod by path on the staged copy; the inode is checked before lchown.
$r14oUpdaterSource = (string)file_get_contents($r14oRepo . '/src/Updater.php');
// R23 G5: lchown by name became FileOwnership::handOver through the open descriptor (hard links too).
assert_true(!preg_match('/@?\b(l?chown|l?chgrp)\s*\(/', $r14oUpdaterSource) && str_contains($r14oUpdaterSource, 'FileOwnership::handOver('),
    'R14 #5: Updater never chown/chgrp by path (only on the inode it created, through FileOwnership)');
$r14oHasInode = method_exists('Updater', 'assertStagedInode');
assert_true($r14oHasInode, 'R14 #5: the updater checks the staged inode before handing it over');
if ($r14oHasInode && DIRECTORY_SEPARATOR === '/') {
    $r14oSame = new ReflectionMethod('Updater', 'assertStagedInode');
    mkdir($r14oRoot . '/t5', 0700, true);
    file_put_contents($r14oRoot . '/t5/staged', 'db');
    file_put_contents($r14oRoot . '/t5/shadow', 'root:x');
    $r14oInode = (int)fileinode($r14oRoot . '/t5/staged');
    assert_equals('', $r14oError(static fn() => $r14oSame->invoke(null, $r14oRoot . '/t5/staged', $r14oInode)), 'R14 #5: the staged file itself passes');
    unlink($r14oRoot . '/t5/staged');
    symlink($r14oRoot . '/t5/shadow', $r14oRoot . '/t5/staged');
    assert_true(str_contains($r14oError(static fn() => $r14oSame->invoke(null, $r14oRoot . '/t5/staged', $r14oInode)), 'replaced'),
        'R14 #5: a symlink swapped in for the staged file is refused before the owner changes');
}

// #6. setup install decides about switching keyless online payments off before migrating, from the database itself.
$r14oSetup = (string)file_get_contents($r14oRepo . '/cli/setup.php');
$r14oFreshAt = strpos($r14oSetup, "'database-fresh'");
$r14oMigrateAt = strpos($r14oSetup, "'cli/migrate.php'");
assert_true($r14oFreshAt !== false && $r14oMigrateAt !== false && $r14oFreshAt < $r14oMigrateAt
    && preg_match('/if \(\$freshDatabase\) \{\s*\$steps\[\'payments_off\'\]/', $r14oSetup) === 1,
    'R14 #6: install switches online payments off only on a database that had no migrations before it ran');
$r14oEmpty = new PDO('sqlite::memory:');
$r14oNoMigrations = new PDO('sqlite::memory:');
$r14oNoMigrations->exec('CREATE TABLE schema_migrations (name TEXT)');
$r14oMigrated = new PDO('sqlite::memory:');
$r14oMigrated->exec("CREATE TABLE schema_migrations (name TEXT); INSERT INTO schema_migrations VALUES ('20260425033813_add_gdpr_tables')");
assert_equals([true, true, false], [Setup::databaseFresh($r14oEmpty), Setup::databaseFresh($r14oNoMigrations), Setup::databaseFresh($r14oMigrated)],
    'R14 #6: an empty database or one without applied migrations is fresh, a migrated one is not');
assert_true(str_contains($r14oSetup, "json_encode(['fresh' => Setup::databaseFresh(Db::get())])"), 'R14 #6: setup.php database-fresh answers from Setup::databaseFresh()');

// Installer: the self-check is skipped only for php -S started with this shop's router.php.
$r14oHasRouterCheck = method_exists('WebInstaller', 'devRouterActive');
assert_true($r14oHasRouterCheck, 'R14 installer: the self-check knows whether the php -S router is active');
if ($r14oHasRouterCheck) {
    assert_equals([true, false, false], [
        WebInstaller::devRouterActive($r14oRepo, 'cli-server', [$r14oRepo . '/router.php', $r14oRepo . '/install.php']),
        WebInstaller::devRouterActive($r14oRepo, 'cli-server', [$r14oRepo . '/install.php']),
        WebInstaller::devRouterActive($r14oRepo, 'apache2handler', [$r14oRepo . '/router.php']),
    ], 'R14 installer: php -S without router.php serves files as they are, so the self-check runs');
}

// T20. Maintenance mode while the updater replaces files and migrates.
$r14oHasMaintenance = class_exists('MaintenanceMode');
assert_true($r14oHasMaintenance, 'R14 T20: the updater has a maintenance mode');
if ($r14oHasMaintenance) {
    $r14oUpd = $r14oRoot . '/upd';
    foreach (['base/src', 'target/src', 'target/migrations', 'installed/src'] as $sub) mkdir($r14oUpd . '/' . $sub, 0700, true);
    file_put_contents($r14oUpd . '/base/src/Core.php', "core v1\n");
    file_put_contents($r14oUpd . '/installed/src/Core.php', "core v1\n");
    file_put_contents($r14oUpd . '/target/src/Core.php', "core v2\n");
    file_put_contents($r14oUpd . '/target/migrations/20991231000002_r14.php', "<?php return new class {};\n");
    UpdatePackageBuilder::build($r14oUpd . '/base', $r14oUpd . '/target', $r14oUpd . '/package', '14.0.1', '14.0.0');
    $r14oDbPath = $r14oUpd . '/live.sqlite';
    $r14oDb = new PDO('sqlite:' . $r14oDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r14oDb->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY)');
    $r14oDuring = null;
    $r14oFail = false;
    $r14oRunner = static function () use (&$r14oDuring, &$r14oFail, $r14oUpd): void {
        $r14oDuring = MaintenanceMode::active($r14oUpd . '/installed');
        if ($r14oFail) throw new RuntimeException('r14 migration failed');
    };
    $r14oUpdater = new Updater($r14oUpd . '/installed', $r14oUpd . '/private', $r14oDb, $r14oRunner);
    try {
        $r14oUpdater->baseline('14.0.0');
        $r14oFail = true;
        $r14oFailed = $r14oError(static fn() => $r14oUpdater->apply(new UpdatePackage($r14oUpd . '/package', null, true)));
        assert_true(str_contains($r14oFailed, 'r14 migration failed') && $r14oDuring === true && !MaintenanceMode::active($r14oUpd . '/installed'),
            'R14 T20: a failed update ran in maintenance and lifted it after the automatic rollback (' . $r14oFailed . ')');
        $r14oFail = false;
        $r14oDuring = null;
        // The automatic rollback replaced the SQLite file and released the connection: a new run opens it again.
        $r14oUpdater = null;
        $r14oDb = null;
        gc_collect_cycles();
        $r14oDb = new PDO('sqlite:' . $r14oDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $r14oUpdater = new Updater($r14oUpd . '/installed', $r14oUpd . '/private', $r14oDb, $r14oRunner);
        $r14oUpdater->apply(new UpdatePackage($r14oUpd . '/package', null, true));
        assert_equals([true, false, false], [$r14oDuring, MaintenanceMode::active($r14oUpd . '/installed'), is_file(MaintenanceMode::path($r14oUpd . '/installed'))],
            'R14 T20: the migration runs in maintenance mode and the marker is gone after the update');
    } catch (Throwable $e) {
        assert_true(false, 'R14 T20: update in maintenance (' . get_class($e) . ': ' . $e->getMessage() . ')');
    } finally {
        $r14oUpdater = null;
        $r14oDb = null;
        gc_collect_cycles();
    }

    // The web answers 503 and the worker skips its run while the marker in the application root is fresh.
    $r14oMarker = MaintenanceMode::path($r14oRepo);
    $r14oDocroot = $r14oRoot . '/web';
    mkdir($r14oDocroot, 0700, true);
    file_put_contents($r14oDocroot . '/probe.php', '<?php require ' . var_export($r14oRepo . '/src/autoload.php', true) . '; echo "served";');
    $r14oSocket = stream_socket_server('tcp://127.0.0.1:0');
    $r14oPort = (int)substr(strrchr((string)stream_socket_get_name($r14oSocket, false), ':'), 1);
    fclose($r14oSocket);
    $r14oServer = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$r14oPort}", '-t', $r14oDocroot],
        [0 => ['pipe', 'r'], 1 => ['file', $r14oRoot . '/server.log', 'a'], 2 => ['file', $r14oRoot . '/server.log', 'a']], $r14oPipes, $r14oDocroot);
    $r14oGet = static function () use ($r14oPort): array {
        $body = @file_get_contents("http://127.0.0.1:{$r14oPort}/probe.php", false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]));
        $headers = HttpResponseHeaders::last($http_response_header ?? null);
        return [(int)(explode(' ', (string)($headers[0] ?? ''))[1] ?? 0), trim((string)$body)];
    };
    try {
        for ($i = 0; $i < 50 && $r14oGet()[0] === 0; $i++) usleep(100000);
        assert_equals([200, 'served'], $r14oGet(), 'R14 T20: without the marker the shop serves');
        MaintenanceMode::enter($r14oRepo, 'r14 test');
        $r14oDown = $r14oGet();
        assert_true($r14oDown[0] === 503 && !str_contains($r14oDown[1], 'served'), 'R14 T20: during an update a web request gets 503 (' . json_encode($r14oDown) . ')');
        $r14oWorker = $r14oCli(['cli/worker.php', '--once', '--verbose']);
        assert_true($r14oWorker['code'] === 0 && str_contains($r14oWorker['out'], 'maintenance'), 'R14 T20: the worker skips its run during an update (' . trim($r14oWorker['out']) . ')');
        // A killed updater: its process is gone (R16 keeps the window of one still running past the hour).
        $r14oGone = proc_open([PHP_BINARY, '-r', ''], [], $r14oGonePipes);
        $r14oGonePid = (int)proc_get_status($r14oGone)['pid'];
        proc_close($r14oGone);
        MaintenanceMode::leave($r14oRepo); // R17: the lock of a killed process is gone with it
        file_put_contents($r14oMarker, json_encode(['since' => gmdate('c'), 'reason' => 'r14 test', 'pid' => $r14oGonePid, 'host' => (string)gethostname()]));
        touch($r14oMarker, time() - MaintenanceMode::STALE_AFTER - 60);
        assert_equals([200, 'served'], $r14oGet(), 'R14 T20: a marker left by a killed updater stops blocking after an hour');
    } finally {
        MaintenanceMode::leave($r14oRepo);
        proc_terminate($r14oServer);
        proc_close($r14oServer);
    }
}

// T20. gdpr_events have a retention policy (the "separate policy" the config promised did not exist).
$r14oRetention = json_decode((string)file_get_contents($r14oRepo . '/config/data-retention.json'), true);
$r14oGdprPolicy = array_values(array_filter((array)($r14oRetention['policies'] ?? []), static fn($p): bool => is_array($p) && ($p['table'] ?? '') === 'gdpr_events'))[0] ?? null;
assert_true(is_array($r14oGdprPolicy) && ($r14oGdprPolicy['action'] ?? '') === 'delete' && (int)($r14oGdprPolicy['ttl_days'] ?? 0) > 0,
    'R14 T20: gdpr_events are deleted by a retention policy');
if (is_array($r14oGdprPolicy)) {
    [$r14oSavedDb, $r14oSavedDriver] = [Db::get(), Db::driver()];
    $r14oDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    try {
        (new Migrator($r14oDb, $r14oRepo . '/migrations', $r14oRoot . '/migrations.lock'))->apply(static fn($m) => null);
        Db::init($r14oDb, 'sqlite');
        $r14oInsert = $r14oDb->prepare("INSERT INTO gdpr_events (action, actor, note, created_at) VALUES ('export', 'test', :note, :at)");
        $r14oInsert->execute([':note' => 'r14-old', ':at' => gmdate('Y-m-d H:i:s', time() - ((int)$r14oGdprPolicy['ttl_days'] + 2) * 86400)]);
        $r14oInsert->execute([':note' => 'r14-recent', ':at' => gmdate('Y-m-d H:i:s', time() - 86400)]);
        GdprService::applyRetention('gdpr_events', $r14oGdprPolicy, 'apply');
        assert_equals(['r14-recent'], $r14oDb->query("SELECT note FROM gdpr_events WHERE note LIKE 'r14-%' ORDER BY note")->fetchAll(PDO::FETCH_COLUMN),
            'R14 T20: retention deletes gdpr_events past the period and keeps recent ones');
    } catch (Throwable $e) {
        assert_true(false, 'R14 T20: gdpr_events retention (' . get_class($e) . ': ' . $e->getMessage() . ')');
    } finally {
        Db::init($r14oSavedDb, $r14oSavedDriver);
        $r14oDb = null;
    }
}

$r14oRm($r14oRoot);
