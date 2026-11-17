<?php

// Review round 15 (R41, low 10): as root (a step that keeps root, cli/backup.php) the shop's own connection Db::get()
// switched the database at db_sqlite_path to WAL — a write — before the backup refused it as another account's (the web
// user's link to a database only root may read). And the journal, WAL and shared memory files next to the database were
// not checked: a hard link the web user put at shop.sqlite-wal made root open (and write) the linked file. As root,
// Db::get() checks the path first and leaves the journal mode to the web user; root refuses sidecars that are links or
// belong to another account, before and after it opens them.
echo "\n--- R41 root WAL: Db::get() as root writes no foreign database; -wal/-shm checked for owner and links ---\n";

if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    echo "  [SKIP] R41 root WAL: run as root on Linux (wsl -u root / CI root job)\n";
} else {
    $r41wBase = sys_get_temp_dir() . '/bbe-r41-wal-' . bin2hex(random_bytes(4));
    $r41wDbState = [Db::connected() ? Db::get() : null, (new ReflectionProperty(Db::class, 'driver'))->getValue(), (new ReflectionProperty(Db::class, 'configPath'))->getValue()];
    $r41wMode = static fn(string $file): string => strtolower((string)(new PDO('sqlite:' . $file))->query('PRAGMA journal_mode')->fetchColumn());
    $r41wConnect = static function (string $path) use ($r41wBase): string {
        file_put_contents($r41wBase . '/config.php', '<?php return ' . var_export(['db_driver' => 'sqlite', 'db_sqlite_path' => $path], true) . ';');
        Db::useConfig($r41wBase . '/config.php');
        try {
            Db::get()->query('SELECT COUNT(*) FROM sqlite_master')->fetchColumn();
            return 'opened';
        } catch (RuntimeException $e) {
            return str_contains($e->getMessage(), 'refused as root') ? 'refused' : $e->getMessage();
        } finally {
            Db::useConfig($r41wBase . '/config.php'); // drops the connection
        }
    };
    // The service's own connection is to another file: a plain root connection to $db would itself open the planted -wal
    // (cli/backup.php gets Db::get()'s, refused now before it opens anything). configuredPath is the database backed up.
    $r41wInit = static function (string $db) use ($r41wBase): void {
        BackupService::init(new PDO('sqlite:' . $r41wBase . '/service.sqlite'), ['enabled' => true, 'storage' => 'local', 'compression' => false, 'auto_gc_on_backup' => false, 'files' => [],
            'retention' => ['daily' => 0, 'weekly' => 0, 'monthly' => 0, 'minimum_kept' => 1], 'storage_dir' => $r41wBase . '/backups',
            'log_file' => $r41wBase . '/backup.log', 'wal_checkpoint' => true], $r41wBase);
        (new ReflectionProperty(BackupService::class, 'configuredPath'))->setValue(null, $db);
    };
    $r41wBackup = static function (string $db) use ($r41wInit): string {
        $r41wInit($db);
        try {
            BackupService::create(['label' => 'manual']);
            return 'made';
        } catch (Throwable $e) {
            return str_contains($e->getMessage(), 'refused as root') ? 'refused' : $e->getMessage();
        }
    };
    try {
        foreach (['secret', 'private/db', 'backups'] as $dir) mkdir($r41wBase . '/' . $dir, 0700, true);
        foreach (['private', 'private/db'] as $dir) { chown($r41wBase . '/' . $dir, 65534); chgrp($r41wBase . '/' . $dir, 65534); }
        $r41wSecret = $r41wBase . '/secret/other.sqlite';
        $r41wDb = $r41wBase . '/private/db/shop.sqlite';
        foreach ([$r41wSecret => 'R41-ROOT-ONLY', $r41wDb => 'R41-SHOP'] as $file => $value) {
            $init = new PDO('sqlite:' . $file);
            $init->exec("PRAGMA journal_mode=DELETE; CREATE TABLE t (v TEXT); INSERT INTO t VALUES ('{$value}')");
            $init = null;
        }
        chown($r41wDb, 65534);

        // 1. db_sqlite_path is the web user's link to a database only root may read.
        $r41wLink = $r41wBase . '/private/db/linked.sqlite';
        symlink($r41wSecret, $r41wLink);
        lchown($r41wLink, 65534);
        assert_equals(['refused', 'delete'], [$r41wConnect($r41wLink), $r41wMode($r41wSecret)],
            'R41 root WAL: Db::get() as root refuses the web user\'s link to another database, which stays in its journal mode (was: switched to WAL)');
        $r41wDirLink = $r41wBase . '/private/swapped';
        symlink($r41wBase . '/secret', $r41wDirLink);
        lchown($r41wDirLink, 65534);
        assert_equals(['refused', 'delete'], [$r41wConnect($r41wDirLink . '/other.sqlite'), $r41wMode($r41wSecret)],
            'R41 root WAL: a directory link of the web user on the way is refused too');

        // Control: the shop's own database opens as root, and root leaves its journal mode to the web user.
        assert_equals(['opened', 'delete'], [$r41wConnect($r41wDb), $r41wMode($r41wDb)],
            'R41 root WAL: as root the shop\'s own database opens, its journal mode not switched by root');
        assert_equals('made', $r41wBackup($r41wDb), 'R41 root WAL: the shop\'s own database is backed up as root (control)');

        // 2. Sidecars: a hard link to a root file, a symbolic link, a file of another account at -wal / -shm.
        $r41wRootFile = $r41wBase . '/secret/root-file';
        file_put_contents($r41wRootFile, 'R41-ROOT-FILE');
        $r41wSidecars = [
            'a hard link to a root file at -wal' => static function () use ($r41wDb, $r41wRootFile): void { link($r41wRootFile, $r41wDb . '-wal'); },
            'a symbolic link at -shm' => static function () use ($r41wDb, $r41wRootFile): void { symlink($r41wRootFile, $r41wDb . '-shm'); lchown($r41wDb . '-shm', 65534); },
            'a -journal of another account' => static function () use ($r41wDb): void { touch($r41wDb . '-journal'); chown($r41wDb . '-journal', 65533); },
        ];
        foreach ($r41wSidecars as $label => $plant) {
            $plant();
            clearstatcache();
            assert_equals(['refused', 'refused', 'R41-ROOT-FILE'], [$r41wConnect($r41wDb), $r41wBackup($r41wDb), (string)file_get_contents($r41wRootFile)],
                "R41 root WAL: {$label} is refused by Db::get() and the backup as root; the root file is untouched");
            foreach (['-wal', '-shm', '-journal'] as $suffix) @unlink($r41wDb . $suffix);
        }
        // Sidecars of the database's owner are fine.
        $r41wWal = new PDO('sqlite:' . $r41wDb);
        $r41wWal->exec('PRAGMA journal_mode=WAL');
        $r41wWal->exec("INSERT INTO t VALUES ('R41-IN-WAL')");
        foreach (['', '-wal', '-shm'] as $suffix) if (file_exists($r41wDb . $suffix)) chown($r41wDb . $suffix, 65534);
        assert_equals(['opened', 'made'], [$r41wConnect($r41wDb), $r41wBackup($r41wDb)],
            'R41 root WAL: a WAL database with its owner\'s -wal and -shm opens and is backed up as root (control)');
        $r41wWal = null;
    } catch (Throwable $r41wE) {
        assert_true(false, 'R41 root WAL (' . get_class($r41wE) . ': ' . $r41wE->getMessage() . ' @ ' . basename($r41wE->getFile()) . ':' . $r41wE->getLine() . ')');
    } finally {
        BackupService::reset();
        (new ReflectionProperty(Db::class, 'configPath'))->setValue(null, $r41wDbState[2]);
        if ($r41wDbState[0] !== null) Db::init($r41wDbState[0], $r41wDbState[1]);
        else Db::reset();
        shell_exec('rm -rf ' . escapeshellarg($r41wBase));
    }
}
