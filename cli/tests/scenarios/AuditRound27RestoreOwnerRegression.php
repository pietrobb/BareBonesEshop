<?php

// Capability review run 397 (goal #393, review round 27 finding 1): cli/cli-only.php runs a step started as root as the
// owner of the application root, but BackupService gave a restored file the owner and group of the file it replaces only
// as root. `sudo php cli/restore.php` in a deploy user's checkout left the database of www-data to the deploy user (the
// shop could not open it) and exited 0. A restore, a files restore or an update rollback of the SQLite database that
// cannot give the replacement that owner and group is refused before anything changes.
echo "\n--- R27: a restore run as the app root's owner refuses to leave the shop's files to that owner ---\n";

$r27oRoot = dirname(TEST_CLI_DIR);
$r27oBackup = (string)file_get_contents($r27oRoot . '/src/BackupService.php');
$r27oUpdater = (string)file_get_contents($r27oRoot . '/src/Updater.php');
assert_true(!str_contains($r27oBackup, "posix_geteuid() !== 0) return;") && substr_count($r27oBackup, 'FileOwnership::cannotKeep(') >= 3
    && str_contains($r27oUpdater, 'FileOwnership::cannotKeep(') && str_contains($r27oUpdater, 'installAbsoluteFile($sqliteSource, $livePath, true)'),
    'R27 restore owner: restore, files restore and the SQLite rollback check the owner before writing; the owner is not set only as root');

if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid')) {
    echo "  [SKIP] R27 restore owner: POSIX owners (Windows)\n";
    return;
}
$r27oAsRoot = posix_geteuid() === 0;
$r27oOther = posix_geteuid() === 65534 ? 65533 : 65534;
assert_equals([null, $r27oAsRoot], [FileOwnership::cannotKeep('/x', posix_geteuid(), posix_getegid()),
    FileOwnership::cannotKeep('/x/shop.sqlite', $r27oOther, posix_getegid()) === null],
    'R27 restore owner: a file of this user keeps its owner; one of another user cannot, unless this step runs as root');

if (!$r27oAsRoot) {
    echo "  [SKIP] R27 restore owner: a step started as root (needs root)\n";
    return;
}
// The deploy layout: the app root belongs to 65534 (a step started as root runs as 65534, cli_drop_root()), the data
// directories too, and the live database and an upload belong to the web user (4321:4322).
$r27oDir = sys_get_temp_dir() . '/r27-restore-owner-' . getmypid();
$r27oApp = $r27oDir . '/app';
$r27oLive = $r27oDir . '/data/live.db';
$r27oUpload = $r27oDir . '/uploads/a.jpg';
$r27oCfg = [
    'enabled' => true, 'storage' => 'local', 'compression' => false, 'auto_gc_on_backup' => false,
    'retention' => ['daily' => 0, 'weekly' => 0, 'monthly' => 0, 'minimum_kept' => 1],
    'storage_dir' => $r27oDir . '/backups', 'log_file' => $r27oDir . '/logs/backup.log',
    'files' => ['uploads' => $r27oDir . '/uploads'],
];
$r27oChild = $r27oApp . '/cli/restore-probe.php';
try {
    foreach (['', '/app', '/data', '/uploads', '/backups', '/logs', '/restore-recovery'] as $r27oSub) {
        @mkdir($r27oDir . $r27oSub, 0755, true);
        if ($r27oSub !== '') chown($r27oDir . $r27oSub, 65534);
        if ($r27oSub !== '') chgrp($r27oDir . $r27oSub, 65534);
    }
    $r27oPdo = new PDO('sqlite:' . $r27oLive, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r27oPdo->exec("CREATE TABLE orders (id INTEGER PRIMARY KEY, note TEXT); INSERT INTO orders (note) VALUES ('backed up')");
    file_put_contents($r27oUpload, 'BACKED UP');
    BackupService::init($r27oPdo, $r27oCfg, $r27oApp);
    $r27oMeta = BackupService::create(['label' => 'manual']);
    BackupService::reset();
    $r27oPdo->exec("INSERT INTO orders (note) VALUES ('after the backup')");
    $r27oPdo = null;
    file_put_contents($r27oUpload, 'NEWER');
    // The app root holds the code its owner runs (the checkout itself may not be readable for 65534, e.g. under /root).
    mkdir($r27oApp . '/cli');
    copy($r27oRoot . '/cli/cli-only.php', $r27oApp . '/cli/cli-only.php');
    exec('cp -r ' . escapeshellarg($r27oRoot . '/src') . ' ' . escapeshellarg($r27oRoot . '/adapters') . ' ' . escapeshellarg($r27oApp . '/')
        . ' && chown -R 65534:65534 ' . escapeshellarg($r27oApp) . ' ' . escapeshellarg($r27oDir . '/backups') . ' ' . escapeshellarg($r27oDir . '/logs'));
    // The web user's files; 0666 so that the dropped step can read them at all (only their owner is in question).
    foreach ([$r27oLive, $r27oUpload] as $r27oFile) {
        chown($r27oFile, 4321);
        chgrp($r27oFile, 4322);
        chmod($r27oFile, 0666);
    }
    // A restore started as root as cli/restore.php runs it: cli/cli-only.php of the app first (it drops root), then src/.
    file_put_contents($r27oChild, '<?php
require __DIR__ . "/cli-only.php";
require dirname(__DIR__) . "/src/autoload.php";
$out = ["euid" => posix_geteuid()];
BackupService::init(new PDO("sqlite:" . ' . var_export($r27oLive, true) . ', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), '
        . var_export($r27oCfg, true) . ', ' . var_export($r27oApp, true) . ');
foreach (["db" => fn() => BackupService::restore(' . var_export($r27oMeta['remote_path'], true) . '),
    "files" => fn() => BackupService::restoreFiles(' . var_export($r27oMeta['remote_path'], true) . ', ["force" => true])] as $k => $run) {
    try { $run(); $out[$k] = "ok"; } catch (Throwable $e) { $out[$k] = $e->getMessage(); }
}
echo json_encode($out);');
    $r27oRun = static function () use ($r27oChild): array {
        $process = proc_open([PHP_BINARY, $r27oChild], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($process);
        $ran = json_decode((string)$out, true);
        return is_array($ran) ? $ran : ['error' => $out . $err];
    };
    $r27oState = static function () use ($r27oLive, $r27oUpload): array {
        clearstatcache();
        $pdo = new PDO('sqlite:' . $r27oLive, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $rows = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $pdo = null;
        return [fileowner($r27oLive), filegroup($r27oLive), $rows, fileowner($r27oUpload), (string)file_get_contents($r27oUpload)];
    };
    $r27oSnapshots = static fn(): int => count(glob($r27oDir . '/backups/*/*/*/*.db') ?: []) - 1; // the backup itself

    // 1. The live files belong to the web user: refused before the pre-restore snapshot, nothing replaced.
    $r27oRan = $r27oRun();
    assert_equals([65534, true, true, [4321, 4322, 2, 4321, 'NEWER'], 0],
        [$r27oRan['euid'] ?? null, str_contains((string)($r27oRan['db'] ?? ''), 'Restore refused, nothing was changed') && str_contains((string)$r27oRan['db'], 'belongs to'),
            str_contains((string)($r27oRan['files'] ?? ''), 'nothing was written') && str_contains((string)$r27oRan['files'], 'belongs to'),
            $r27oState(), $r27oSnapshots()],
        'R27 restore owner: run as the app root\'s owner, a restore of the web user\'s database and uploads is refused and changes nothing (was: exit 0, both left to the step\'s user) — '
        . json_encode($r27oRan));

    // 2. The files belong to the user the step runs as: restored, and they keep that owner.
    chown($r27oLive, 65534);
    chgrp($r27oLive, 65534);
    chown($r27oUpload, 65534);
    chgrp($r27oUpload, 65534);
    $r27oRan = $r27oRun();
    assert_equals(['ok', 'ok', [65534, 65534, 1, 65534, 'BACKED UP']], [$r27oRan['db'] ?? null, $r27oRan['files'] ?? null, $r27oState()],
        'R27 restore owner: files of the step\'s own user are restored and keep their owner — ' . json_encode($r27oRan));
} catch (Throwable $e) {
    assert_true(false, 'R27 restore owner (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    BackupService::reset();
    exec('rm -rf ' . escapeshellarg($r27oDir));
}
