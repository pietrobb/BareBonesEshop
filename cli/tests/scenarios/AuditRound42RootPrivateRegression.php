<?php

// Review round 16 (R42, findings 1, 2 and 7): root kept root in the private directory the web user owns, and checked each
// path before it opened it there. migrate.php as root followed the web user's link at <db>.migrations.lock and created
// the target (/etc/nologin); the database directory swapped for a link between Db::get()'s check and its open made root
// connect to a database only root may read (and migrate.php write schema_migrations into it); root left a 0644 lock and
// 0700 backup directories the web user could not use. Root now works there only as the directory's owner
// (src/PrivateOwner.php): every step started as root in a tree root owns switches to it for good, cli/update.php (which
// installs the tree) only while it works in the private directory.
echo "\n--- R42 root: the private directory only as its owner (migrations lock, database swap, sidecars, backups) ---\n";

$r42pRepo = dirname(TEST_CLI_DIR);
assert_true(str_contains((string)file_get_contents($r42pRepo . '/cli/cli-only.php'), 'cli_private_owner($root)')
    && str_contains((string)file_get_contents($r42pRepo . '/src/Db.php'), 'PrivateOwner::rootOutside()')
    && str_contains((string)file_get_contents($r42pRepo . '/cli/update.php'), 'PrivateOwner::run(static function () use ($root): void {'),
    'R42 root: cli-only.php switches to the private directory\'s owner, Db::get() refuses root outside PrivateOwner::run(), update.php migrates as the owner');

// Directories of the config: the runtime directory, the SQLite database's, and the ones that default below it.
assert_equals(['/p', '/p/db', '/j'], PrivateOwner::directories(['db_driver' => 'sqlite', 'private_runtime_dir' => '/p/',
    'db_sqlite_path' => '/p/db/shop.sqlite', 'jobs_dir' => '/j', 'backup_storage_dir' => 'relative'], '/srv/shop'),
    'R42 root: the private directories of a config (relative paths ignored)');
assert_equals(['/srv/bareboneseshop-private'], PrivateOwner::directories(['db_driver' => 'mysql'], '/srv/shop'),
    'R42 root: without private_runtime_dir, the default next to the application root');
// Outside a root step run() and asRoot() just call.
assert_equals([null, 7, 8], [PrivateOwner::recorded(), PrivateOwner::run(static fn(): int => 7), PrivateOwner::asRoot(static fn(): int => 8)],
    'R42 root: nothing recorded, run() and asRoot() call directly');

// A lock root left behind (0644, not writable by the web user) no longer stops migrations: flock on a read descriptor.
$r42pMig = (string)file_get_contents($r42pRepo . '/src/Migrator.php');
assert_true(str_contains($r42pMig, "@fopen(\$this->lockFile, 'r')"), 'R42 root: Migrator falls back to a read-only descriptor for an existing lock');

if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    echo "  [SKIP] R42 root private: run as root on Linux (wsl -u root / CI root job)\n";
    return;
}

$r42pBase = sys_get_temp_dir() . '/bbe-r42-private-' . bin2hex(random_bytes(4));
$r42pRun = static function (array $command): array {
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $r42pOut = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $r42pOut . $err];
};
$r42pTables = static function (string $file): array {
    $r42pPdo = new PDO('sqlite:' . $file);
    return $r42pPdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
};
$r42pDbState = [Db::connected() ? Db::get() : null, (new ReflectionProperty(Db::class, 'driver'))->getValue(), (new ReflectionProperty(Db::class, 'configPath'))->getValue()];
try {
    mkdir($r42pBase, 0755);
    chmod($r42pBase, 0755);
    // A tree root owns alone, with the steps under test.
    $r42pApp = $r42pBase . '/app';
    mkdir($r42pApp . '/cli', 0755, true);
    mkdir($r42pApp . '/migrations', 0755);
    shell_exec('cp -r ' . escapeshellarg($r42pRepo . '/src') . ' ' . escapeshellarg($r42pApp . '/src'));
    foreach (['cli-only.php', 'migrate.php', 'update.php'] as $file) copy($r42pRepo . '/cli/' . $file, $r42pApp . '/cli/' . $file);
    file_put_contents($r42pApp . '/migrations/20260101000000_r42_probe.php', "<?php\nreturn new class {\n    public function up(PDO \$db, bool \$isSqlite): void {\n"
        . "        \$db->exec('CREATE TABLE r42_probe (id INTEGER PRIMARY KEY)');\n    }\n};\n");
    file_put_contents($r42pApp . '/cli/r42-whoami.php', "<?php\nrequire __DIR__ . '/cli-only.php';\n\$dir = \$argv[1];\n"
        . "@mkdir(\$dir, 0700, true);\necho posix_geteuid(), ' ', posix_getegid(), ' ', implode(',', posix_getgroups()), ' ', fileowner(\$dir), \"\\n\";\n");
    // The private directory, the web user's (65534); a directory and a database only root may read.
    $r42pPrivate = $r42pBase . '/private';
    mkdir($r42pPrivate . '/db', 0755, true);
    shell_exec('chown -R 65534:65534 ' . escapeshellarg($r42pPrivate));
    mkdir($r42pBase . '/secret', 0700);
    $r42pSecretDb = $r42pBase . '/secret/other.sqlite';
    (new PDO('sqlite:' . $r42pSecretDb))->exec("PRAGMA journal_mode=DELETE; CREATE TABLE t (v TEXT); INSERT INTO t VALUES ('R42-ROOT-ONLY')");
    $r42pConfig = static function (string $dbPath) use ($r42pApp, $r42pPrivate, $r42pBase): void {
        file_put_contents($r42pApp . '/config.php', '<?php return ' . var_export(['db_driver' => 'sqlite', 'db_sqlite_path' => $dbPath,
            'private_runtime_dir' => $r42pPrivate, 'update_storage_dir' => $r42pBase . '/updates'], true) . ';');
    };
    $r42pConfig($r42pPrivate . '/db/shop.sqlite');
    mkdir($r42pBase . '/updates', 0700);
    shell_exec('chown -R 0:0 ' . escapeshellarg($r42pApp) . ' && chmod -R go-w ' . escapeshellarg($r42pApp));

    // 7. A step started as root runs as the private directory's owner: what it creates there is the web user's.
    [$r42pExit, $r42pOut] = $r42pRun([PHP_BINARY, $r42pApp . '/cli/r42-whoami.php', $r42pPrivate . '/backups/2026/11/17']);
    $r42pWho = explode(' ', trim((string)preg_replace('/^NOTICE:.*\n/m', '', $r42pOut)));
    assert_true($r42pExit === 0 && ($r42pWho[0] ?? '') === '65534' && ($r42pWho[1] ?? '') === '65534' && !in_array('0', explode(',', $r42pWho[2] ?? '0'), true)
        && ($r42pWho[3] ?? '') === '65534' && str_contains($r42pOut, 'the owner of the private directory ' . $r42pPrivate),
        'R42 root: a root step in a root-owned tree runs as the owner of the private directory; its backup directories are the owner\'s (was: root 0700) (' . trim($r42pOut) . ')');

    // 1. The web user's link at the migrations lock: root used to create its target.
    $r42pTarget = $r42pBase . '/secret/nologin';
    symlink($r42pTarget, $r42pPrivate . '/db/shop.sqlite.migrations.lock');
    lchown($r42pPrivate . '/db/shop.sqlite.migrations.lock', 65534);
    [$r42pExit, $r42pOut] = $r42pRun([PHP_BINARY, $r42pApp . '/cli/migrate.php', '--skip-backup']);
    clearstatcache();
    assert_true($r42pExit !== 0 && !file_exists($r42pTarget),
        'R42 root: migrate.php as root does not create the target of the web user\'s link at the lock (was: created as root) (exit ' . $r42pExit . ')');
    unlink($r42pPrivate . '/db/shop.sqlite.migrations.lock');
    [$r42pExit, $r42pOut] = $r42pRun([PHP_BINARY, $r42pApp . '/cli/migrate.php', '--skip-backup']);
    clearstatcache();
    $r42pOwners = array_map(static fn(string $f): int|false => @fileowner($f), [$r42pPrivate . '/db/shop.sqlite', $r42pPrivate . '/db/shop.sqlite.migrations.lock']);
    assert_true($r42pExit === 0 && $r42pOwners === [65534, 65534] && in_array('r42_probe', $r42pTables($r42pPrivate . '/db/shop.sqlite'), true),
        'R42 root: migrate.php as root migrates the web user\'s database as its owner; database and lock stay the web user\'s (' . trim($r42pOut) . ')');

    // 2. The database directory swapped for the web user's link to a directory only root may read, before and while
    // root steps run: root never connects to the other database, writes nothing into it and creates nothing next to it.
    $r42pConfig($r42pPrivate . '/swap/other.sqlite');
    symlink($r42pBase . '/secret', $r42pPrivate . '/swap');
    lchown($r42pPrivate . '/swap', 65534);
    [$r42pExit, $r42pOut] = $r42pRun([PHP_BINARY, $r42pApp . '/cli/migrate.php', '--skip-backup']);
    assert_true($r42pExit !== 0, 'R42 root: migrate.php as root does not open the database behind the web user\'s link to a directory only root may read (' . trim($r42pOut) . ')');
    // The race: the web user swaps a real directory and the link back and forth while root steps run.
    unlink($r42pPrivate . '/swap');
    mkdir($r42pPrivate . '/swap.dir', 0755);
    chown($r42pPrivate . '/swap.dir', 65534);
    $r42pSwapper = proc_open(['setpriv', '--reuid=65534', '--regid=65534', '--clear-groups', 'sh', '-c',
        'cd "$1" && while :; do ln -sfn "$2" swap.tmp && mv -T swap.tmp swap; rm -f swap; mv -T swap.dir swap 2>/dev/null; mv -T swap swap.dir 2>/dev/null; done',
        'r42-swap', $r42pPrivate, $r42pBase . '/secret'], [], $r42pSwapPipes);
    for ($r42pI = 0; $r42pI < 25; $r42pI++) $r42pRun([PHP_BINARY, $r42pApp . '/cli/migrate.php', '--skip-backup']);
    proc_terminate($r42pSwapper, 9);
    proc_close($r42pSwapper);
    clearstatcache();
    assert_equals([['t'], ['.', '..', 'other.sqlite']], [$r42pTables($r42pSecretDb), scandir($r42pBase . '/secret')],
        'R42 root: 25 root migrations while the web user swaps the directory: the root-only database gets no schema_migrations and no file appears next to it');
    $r42pConfig($r42pPrivate . '/db/shop.sqlite');

    // update.php keeps root for the tree and records the owner for the private directory.
    [$r42pExit, $r42pOut] = $r42pRun([PHP_BINARY, $r42pApp . '/cli/update.php', 'list']);
    assert_true($r42pExit === 0 && str_contains($r42pOut, 'works in the private directory ' . $r42pPrivate . ' as its owner') && str_contains($r42pOut, 'No update snapshots'),
        'R42 root: update.php keeps root for the tree and works in the private directory as its owner (' . trim($r42pOut) . ')');

    // In process, as update.php runs: the database opens only as the owner; the snapshot root takes and the rollback's
    // database are the owner's work, the snapshot root's.
    $r42pOwner = PrivateOwner::find(PrivateOwner::directories(require $r42pApp . '/config.php', $r42pApp));
    assert_true(is_array($r42pOwner) && $r42pOwner['uid'] === 65534 && $r42pOwner['dir'] === $r42pPrivate, 'R42 root: the private directory\'s owner is found');
    PrivateOwner::record($r42pOwner);
    try {
        Db::useConfig($r42pApp . '/config.php');
        $r42pRefused = '';
        try {
            Db::get();
        } catch (RuntimeException $r42pErr) {
            $r42pRefused = $r42pErr->getMessage();
        }
        $r42pPdo = PrivateOwner::run(static fn(): PDO => Db::get());
        clearstatcache();
        assert_true(str_contains($r42pRefused, 'refused as root') && posix_geteuid() === 0 && @fileowner($r42pPrivate . '/db/shop.sqlite-wal') === 65534,
            'R42 root: Db::get() refuses root outside PrivateOwner::run(); inside, the WAL it creates is the owner\'s and root is back after');
        $r42pUpdater = new Updater($r42pApp, $r42pBase . '/updates', $r42pPdo);
        $r42pSnapshot = $r42pBase . '/updates/r42-snapshot';
        mkdir($r42pSnapshot, 0700);
        $r42pMeta = (new ReflectionMethod(Updater::class, 'snapshotSqliteDatabase'))->invoke($r42pUpdater, $r42pSnapshot);
        clearstatcache();
        $r42pLeftovers = glob($r42pPrivate . '/db/.shop.sqlite.update-snapshot-*') ?: [];
        assert_true(fileowner($r42pSnapshot . '/database.sqlite') === 0 && $r42pLeftovers === [] && $r42pMeta['live_path'] === $r42pPrivate . '/db/shop.sqlite'
            && in_array('r42_probe', $r42pTables($r42pSnapshot . '/database.sqlite'), true),
            'R42 root: the update snapshot is vacuumed by the owner and copied into root\'s update directory; nothing is left next to the database');
        PrivateOwner::run(static function () use (&$r42pPdo, &$r42pUpdater): void { // closed (its -wal/-shm deleted) as the owner
            Db::reset();
            $r42pPdo = null;
            $r42pUpdater = null;
            gc_collect_cycles();
        });
        $r42pSource = fopen($r42pSnapshot . '/database.sqlite', 'rb');
        $r42pInstaller = new Updater($r42pApp, $r42pBase . '/updates');
        PrivateOwner::run(static function () use ($r42pInstaller, $r42pSource, $r42pPrivate): void {
            (new ReflectionMethod(Updater::class, 'installAbsoluteFile'))->invoke($r42pInstaller, $r42pSource, $r42pPrivate . '/db/shop.sqlite', true);
        });
        fclose($r42pSource);
        clearstatcache();
        assert_true(fileowner($r42pPrivate . '/db/shop.sqlite') === 65534 && posix_geteuid() === 0,
            'R42 root: a rollback writes root\'s snapshot over the live database as the owner; it stays the web user\'s');
        // The migrations lock planted as a link, now for the update's own migration runner.
        unlink($r42pPrivate . '/db/shop.sqlite.migrations.lock');
        symlink($r42pTarget, $r42pPrivate . '/db/shop.sqlite.migrations.lock');
        $r42pFailed = '';
        try {
            PrivateOwner::run(static fn() => (new Migrator(Db::get(), $r42pApp . '/migrations'))->apply());
        } catch (Throwable $r42pErr) {
            $r42pFailed = $r42pErr->getMessage();
        }
        clearstatcache();
        assert_true($r42pFailed !== '' && !file_exists($r42pTarget), 'R42 root: the update\'s migrations as the owner do not follow the link at the lock (' . $r42pFailed . ')');
    } finally {
        PrivateOwner::run(static fn() => Db::reset());
        PrivateOwner::record(null);
    }

    // Refused: a private directory root owns that another account can write; private directories of two accounts.
    mkdir($r42pBase . '/shared', 0775);
    chown($r42pBase . '/shared', 0);
    chgrp($r42pBase . '/shared', 65534);
    chmod($r42pBase . '/shared', 0775);
    mkdir($r42pBase . '/other', 0755);
    chown($r42pBase . '/other', 65533);
    assert_true(is_string(PrivateOwner::find([$r42pBase . '/shared'])) && is_string(PrivateOwner::find([$r42pPrivate, $r42pBase . '/other']))
        && PrivateOwner::find([$r42pBase . '/secret', $r42pBase . '/updates/absent']) === null,
        'R42 root: a root directory another account can write and private directories of two accounts are refused; root\'s alone keeps root');
} catch (Throwable $r42pE) {
    assert_true(false, 'R42 root private (' . get_class($r42pE) . ': ' . $r42pE->getMessage() . ' @ ' . basename($r42pE->getFile()) . ':' . $r42pE->getLine() . ')');
} finally {
    PrivateOwner::record(null);
    (new ReflectionProperty(Db::class, 'configPath'))->setValue(null, $r42pDbState[2]);
    if ($r42pDbState[0] !== null) Db::init($r42pDbState[0], $r42pDbState[1]);
    else Db::reset();
    shell_exec('rm -rf ' . escapeshellarg($r42pBase));
}
