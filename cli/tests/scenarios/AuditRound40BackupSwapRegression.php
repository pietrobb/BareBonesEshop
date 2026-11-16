<?php

// Review round 14 (R40, medium 2): a root backup checked the database file and its directories at different moments. The
// web user swapping its db/ directory with its own link to a directory only root may read, over and over, made the file
// check see the other database while the directory check saw the real directory: 2 of 50 and 8 of 200 backups held the
// other database. The path is now resolved component by component and every file the connection opened must be that
// path (or its journal, WAL, shared memory) by the kernel's name for it. Also: no WAL checkpoint as root before the check.
echo "\n--- R40 root backup: db/ swapped with a link while backing up never copies another database ---\n";

if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid') || posix_geteuid() !== 0 || !is_dir('/proc/self/fd')) {
    echo "  [SKIP] R40 root backup swap: run as root on Linux (wsl -u root / CI root job)\n";
} else {
    $r40bBase = sys_get_temp_dir() . '/bbe-r40-backup-' . bin2hex(random_bytes(4));
    $r40bFlipper = null;
    try {
        foreach (['secret', 'private/db', 'backups'] as $r40bDir) mkdir($r40bBase . '/' . $r40bDir, 0700, true);
        $r40bDb = $r40bBase . '/private/db/shop.sqlite';
        $r40bLink = $r40bBase . '/private/db.link';
        // The web user's link to the directory of a database only root may read, under the same file name.
        symlink($r40bBase . '/secret', $r40bLink);
        lchown($r40bLink, 65534);
        lchgrp($r40bLink, 65534);
        $r40bLeaks = static function () use ($r40bBase): int {
            $n = 0;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r40bBase . '/backups', FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && str_contains((string)file_get_contents($f->getPathname()), 'R40-ROOT-ONLY-SECRET')) $n++;
            }
            return $n;
        };
        $r40bInit = static function (PDO $connection) use ($r40bBase, $r40bDb): void {
            BackupService::init($connection, ['enabled' => true, 'storage' => 'local', 'compression' => false, 'auto_gc_on_backup' => false, 'files' => [],
                'retention' => ['daily' => 0, 'weekly' => 0, 'monthly' => 0, 'minimum_kept' => 1], 'storage_dir' => $r40bBase . '/backups',
                'log_file' => $r40bBase . '/backup.log', 'wal_checkpoint' => true], $r40bBase);
            (new ReflectionProperty(BackupService::class, 'configuredPath'))->setValue(null, $r40bDb); // db_sqlite_path of config.php
        };

        foreach (['delete', 'wal'] as $r40bMode) {
            shell_exec('rm -rf ' . escapeshellarg($r40bBase . '/backups'));
            mkdir($r40bBase . '/backups', 0700);
            foreach ([$r40bBase . '/secret/shop.sqlite' => 'R40-ROOT-ONLY-SECRET', $r40bDb => 'R40-SHOP-DATA'] as $r40bFile => $r40bValue) {
                @unlink($r40bFile); @unlink($r40bFile . '-wal'); @unlink($r40bFile . '-shm');
                $r40bInitDb = new PDO('sqlite:' . $r40bFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $r40bInitDb->exec("PRAGMA journal_mode=" . ($r40bMode === 'wal' ? 'WAL' : 'DELETE') . "; CREATE TABLE t (v TEXT); INSERT INTO t VALUES ('{$r40bValue}')");
                $r40bInitDb = null;
            }
            // The shop's connection, made before the swapping starts.
            $r40bShop = new PDO('sqlite:' . $r40bDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $r40bShop->query('SELECT COUNT(*) FROM t')->fetchColumn();
            $r40bInit($r40bShop);

            // The web user swaps db/ and its link, as fast as it can, until told to stop.
            $r40bStop = $r40bBase . '/stop';
            @unlink($r40bStop);
            $r40bScript = '$d = ' . var_export(dirname($r40bDb), true) . '; $l = ' . var_export($r40bLink, true) . '; $s = ' . var_export($r40bStop, true) . ';'
                . ' while (!file_exists($s)) { @rename($d, $d . ".real"); @rename($l, $d); @rename($d, $l); @rename($d . ".real", $d); }';
            $r40bFlipper = proc_open([PHP_BINARY, '-r', $r40bScript], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r40bPipes);
            usleep(100000);
            $r40bMade = 0;
            for ($r40bI = 0; $r40bI < 200; $r40bI++) {
                try { BackupService::create(['label' => 'manual']); $r40bMade++; } catch (Throwable $e) { }
            }
            touch($r40bStop);
            proc_close($r40bFlipper);
            $r40bFlipper = null;
            clearstatcache();
            if (!is_dir(dirname($r40bDb)) || is_link(dirname($r40bDb))) {
                if (is_link(dirname($r40bDb))) rename(dirname($r40bDb), $r40bLink);
                if (is_dir(dirname($r40bDb) . '.real')) rename(dirname($r40bDb) . '.real', dirname($r40bDb));
            }
            assert_equals(0, $r40bLeaks(), "R40 root backup swap ({$r40bMode}): no backup of 200 made while db/ was swapped holds the root-only database ({$r40bMade} made; was 2 of 50, 8 of 200)");
            assert_equals('R40-ROOT-ONLY-SECRET', (string)(new PDO('sqlite:' . $r40bBase . '/secret/shop.sqlite'))->query('SELECT v FROM t')->fetchColumn(),
                "R40 root backup swap ({$r40bMode}): the root-only database is untouched");

            // Control: with nothing swapped, the shop's own database is backed up as root.
            try {
                $r40bMeta = BackupService::create(['label' => 'manual']);
                $r40bCopy = $r40bBase . '/backups/' . $r40bMeta['remote_path'];
                $r40bControl = is_file($r40bCopy) && str_contains((string)file_get_contents($r40bCopy), 'R40-SHOP-DATA') ? 'ok' : 'missing';
            } catch (Throwable $e) {
                $r40bControl = $e->getMessage();
            }
            assert_equals('ok', $r40bControl, "R40 root backup swap ({$r40bMode}): the shop's own database is backed up as root");
            $r40bShop = null;
        }

        // Low: as root the WAL checkpoint no longer runs before the check, on whatever the shop's connection opened.
        $r40bSource = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/BackupService.php');
        assert_true(str_contains($r40bSource, "!empty(self::\$cfg['wal_checkpoint']) && !self::runsAsRoot()"),
            'R40 root backup swap: no WAL checkpoint as root before the database is verified');
    } finally {
        if (isset($r40bStop)) @touch($r40bStop);
        if (is_resource($r40bFlipper)) proc_close($r40bFlipper);
        BackupService::reset();
        shell_exec('rm -rf ' . escapeshellarg($r40bBase));
    }
}
