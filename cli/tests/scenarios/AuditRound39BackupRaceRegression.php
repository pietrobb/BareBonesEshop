<?php

// Review round 13 (R39, low): R38 refused a root backup whose db_path is a link, but its last check accepted any open
// descriptor of the inode at db_path, and root holds others. The web user made db_path a link to a database only root
// may read, waited for the connection, then moved a file root had open (the .backup.lock it created in its private
// directory) to db_path: every check passed and VACUUM INTO copied the other database (5 of 5). Root now copies from a
// connection of its own opened after the checks, whose new descriptor must be the inode still at db_path.
echo "\n--- R39 root backup: a file root holds open, moved to db_path after the connection, does not pass ---\n";

if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid') || posix_geteuid() !== 0 || !is_dir('/proc/self/fd')) {
    echo "  [SKIP] R39 root backup race: run as root on Linux (wsl -u root / CI root job)\n";
} else {
    $r39bBase = sys_get_temp_dir() . '/bbe-r39-backup-' . bin2hex(random_bytes(4));
    try {
        mkdir($r39bBase . '/secret', 0700, true);
        mkdir($r39bBase . '/private/db', 0755, true);
        mkdir($r39bBase . '/backups', 0700, true);
        $r39bSecret = new PDO('sqlite:' . $r39bBase . '/secret/root.sqlite');
        $r39bSecret->exec("CREATE TABLE secret (v TEXT); INSERT INTO secret VALUES ('R39-ROOT-ONLY-SECRET')");
        $r39bSecret = null;
        $r39bDb = $r39bBase . '/private/db/shop.sqlite';
        $r39bLeaked = static function () use ($r39bBase): bool {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r39bBase . '/backups', FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && str_contains((string)file_get_contents($f->getPathname()), 'R39-ROOT-ONLY-SECRET')) return true;
            }
            return false;
        };
        $r39bBackup = static function (PDO $connection) use ($r39bBase, $r39bDb): array {
            BackupService::init($connection, ['enabled' => true, 'storage' => 'local', 'compression' => false, 'auto_gc_on_backup' => false, 'files' => [],
                'retention' => ['daily' => 0, 'weekly' => 0, 'monthly' => 0, 'minimum_kept' => 1], 'storage_dir' => $r39bBase . '/backups',
                'log_file' => $r39bBase . '/backup.log'], $r39bBase);
            (new ReflectionProperty(BackupService::class, 'configuredPath'))->setValue(null, $r39bDb); // db_sqlite_path of config.php
            try {
                return ['ok' => true, 'meta' => BackupService::create(['label' => 'manual'])];
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        };

        // The web user's link at db_path; root's connection opens the other database through it.
        symlink($r39bBase . '/secret/root.sqlite', $r39bDb);
        $r39bConnection = new PDO('sqlite:' . $r39bDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $r39bConnection->query('SELECT COUNT(*) FROM secret')->fetchColumn();
        // A file root holds open (its lock), moved by the web user over the link.
        $r39bDecoy = $r39bBase . '/private/.backup.lock';
        touch($r39bDecoy);
        $r39bHeld = fopen($r39bDecoy, 'r');
        unlink($r39bDb);
        rename($r39bDecoy, $r39bDb);
        $r39bResult = $r39bBackup($r39bConnection);
        assert_true(!$r39bLeaked(), 'R39 root backup race: nothing of the root-only database is in the backups (was: copied; got '
            . json_encode($r39bResult['error'] ?? 'backup made') . ')');
        fclose($r39bHeld);
        $r39bConnection = null;

        // Control: the shop's own database at db_path is still backed up as root.
        unlink($r39bDb);
        $r39bShop = new PDO('sqlite:' . $r39bDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $r39bShop->exec("CREATE TABLE shop (v TEXT); INSERT INTO shop VALUES ('R39-SHOP-DATA')");
        $r39bResult = $r39bBackup($r39bShop);
        $r39bCopy = $r39bResult['ok'] ? $r39bBase . '/backups/' . $r39bResult['meta']['remote_path'] : '';
        assert_true($r39bResult['ok'] && is_file($r39bCopy) && str_contains((string)file_get_contents($r39bCopy), 'R39-SHOP-DATA'),
            'R39 root backup race: the shop\'s own database is backed up as root (got ' . json_encode($r39bResult['error'] ?? $r39bCopy) . ')');
        $r39bShop = null;
    } finally {
        BackupService::reset();
        shell_exec('rm -rf ' . escapeshellarg($r39bBase));
    }
}
