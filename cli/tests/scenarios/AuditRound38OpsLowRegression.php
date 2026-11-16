<?php

// Review round 12 (R38, low), operations:
// 1. `php cli/setup.php install --help` ran a real installation (config.php, migrations) in the checkout it was asked
//    about; so did any switch install does not know. Help prints the usage, an unknown switch installs nothing.
// 2. Root keeps root for a tree only root can change, but the SQLite file is in the private directory the web user
//    writes: a symbolic link put at db_path made root's backup copy another SQLite database root can read (read leak).
// No database driver is involved in 1; 2 is SQLite only (a MySQL backup is mysqldump over the network).
echo "\n--- R38 setup install --help installs nothing; a root backup does not follow a planted database link ---\n";

$r38oRepo = dirname(TEST_CLI_DIR);
$r38oBase = sys_get_temp_dir() . '/r38-ops-low-' . getmypid() . '-' . bin2hex(random_bytes(3));
$r38oRun = static function (array $command, array $env = []): array {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv());
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'out' => $out, 'err' => $err];
};
$r38oCopy = static function (string $from, string $to) use (&$r38oCopy): void {
    if (is_dir($from)) {
        @mkdir($to, 0755, true);
        foreach (scandir($from) ?: [] as $entry) if ($entry !== '.' && $entry !== '..' && $entry !== 'tests') $r38oCopy($from . '/' . $entry, $to . '/' . $entry);
        return;
    }
    copy($from, $to);
};
try {
    mkdir($r38oBase, 0755, true);
    chmod($r38oBase, 0755);

    // 1. A checkout without config.php: help and an unknown switch leave it as it was.
    $r38oCheckout = $r38oBase . '/checkout';
    foreach (['src', 'cli', 'adapters', 'migrations', 'config'] as $directory) $r38oCopy($r38oRepo . '/' . $directory, $r38oCheckout . '/' . $directory);
    foreach (['config.example.php', 'stores.example.json'] as $file) if (is_file($r38oRepo . '/' . $file)) copy($r38oRepo . '/' . $file, $r38oCheckout . '/' . $file);
    $r38oCopy($r38oRepo . '/vendor/bbf', $r38oCheckout . '/vendor/bbf');
    // As root the step runs as the checkout's owner (cli_drop_root), as an operator's would.
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) shell_exec('chown -R 65534:65534 ' . escapeshellarg($r38oCheckout));
    $r38oEnv = [];
    foreach ([['install', '--help'], ['install', '-h'], ['--help'], ['help']] as $args) {
        $label = 'R38 setup ' . implode(' ', $args);
        $help = $r38oRun([PHP_BINARY, $r38oCheckout . '/cli/setup.php', ...$args], $r38oEnv);
        assert_true($help['exit'] === 0 && str_starts_with($help['out'], 'Usage: php cli/setup.php') && !str_contains($help['out'] . $help['err'], '[1/4]'),
            "{$label}: prints the usage and exits 0 (was: started the installation; exit {$help['exit']}, " . trim(substr($help['out'] . $help['err'], 0, 160)) . ')');
    }
    $typo = $r38oRun([PHP_BINARY, $r38oCheckout . '/cli/setup.php', 'install', '--dry-run', '--app-url', 'https://shop.example'], $r38oEnv);
    assert_true($typo['exit'] === 2 && str_contains($typo['err'], 'Unknown install argument --dry-run') && !str_contains($typo['out'] . $typo['err'], '[1/4]'),
        'R38 setup install --dry-run: an unknown switch refuses, nothing installed (exit ' . $typo['exit'] . ', ' . trim(substr($typo['out'] . $typo['err'], 0, 160)) . ')');
    clearstatcache();
    assert_true(!is_file($r38oCheckout . '/config.php') && !is_dir($r38oBase . '/bareboneseshop-private'),
        'R38 setup: after help and the refused switch the checkout has no config.php and no private directory');

    // 2. Root only: a root-only shop tree whose SQLite file lives in the web user's private directory.
    if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        echo "  [SKIP] R38 root backup: run as root (wsl -u root / CI root job)\n";
    } else {
        $app = $r38oBase . '/app';
        foreach (['src', 'adapters', 'migrations', 'config', 'extensions'] as $directory) {
            if (is_dir($r38oRepo . '/' . $directory)) $r38oCopy($r38oRepo . '/' . $directory, $app . '/' . $directory);
        }
        mkdir($app . '/cli', 0755, true);
        foreach (glob($r38oRepo . '/cli/*.php') ?: [] as $script) copy($script, $app . '/cli/' . basename($script));
        foreach (glob($r38oRepo . '/*.json') ?: [] as $file) copy($file, $app . '/' . basename($file));
        $backupJson = json_decode((string)file_get_contents($app . '/config/backup.json'), true) ?: [];
        file_put_contents($app . '/config/backup.json', json_encode(['enabled' => true, 'include_files' => false, 'compression' => false] + $backupJson));
        $private = $r38oBase . '/private';
        $db = $private . '/db/shop.sqlite';
        file_put_contents($app . '/config.php', '<?php return ' . var_export([
            'db_driver' => 'sqlite', 'db_sqlite_path' => $db, 'private_runtime_dir' => $private,
            'cache_dir' => $r38oBase . '/root-state/cache', 'jobs_dir' => $r38oBase . '/root-state/jobs',
            'backup_storage_dir' => $r38oBase . '/root-state/backups', 'backup_log_file' => $r38oBase . '/root-state/backup.log',
        ], true) . ";\n");
        mkdir($r38oBase . '/root-state', 0700);
        shell_exec('chown -R 0:0 ' . escapeshellarg($app) . ' && chmod -R go-w ' . escapeshellarg($app));
        // A database only root may read, and the web user's (65534) private directory.
        mkdir($r38oBase . '/secret', 0700);
        $secret = new PDO('sqlite:' . $r38oBase . '/secret/root.sqlite');
        $secret->exec("CREATE TABLE secret (v TEXT); INSERT INTO secret VALUES ('R38-ROOT-ONLY-SECRET')");
        $secret = null;
        chmod($r38oBase . '/secret/root.sqlite', 0600);
        mkdir($private . '/db', 0755, true);
        $shop = new PDO('sqlite:' . $private . '/db/real.sqlite');
        $shop->exec("CREATE TABLE shop (v TEXT); INSERT INTO shop VALUES ('R38-SHOP-DATA')");
        $shop = null;
        shell_exec('chown -R 65534:65534 ' . escapeshellarg($private));
        $leaked = static function () use ($r38oBase): bool {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r38oBase . '/root-state/backups', FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && str_contains((string)file_get_contents($f->getPathname()), 'R38-ROOT-ONLY-SECRET')) return true;
            }
            return false;
        };
        @mkdir($r38oBase . '/root-state/backups', 0700, true);

        // The web user plants a link at db_path; root runs the nightly backup.
        symlink($r38oBase . '/secret/root.sqlite', $db);
        lchown($db, 65534);
        $linked = $r38oRun([PHP_BINARY, $app . '/cli/backup.php', '--quiet']);
        assert_true($linked['exit'] !== 0 && str_contains($linked['err'], 'is a symbolic link') && !$leaked(),
            'R38 root backup: a symbolic link at db_path is refused, nothing of the other database is in the backups (was: root copied it; exit '
            . $linked['exit'] . ', ' . trim(substr($linked['out'] . $linked['err'], 0, 220)) . ')');
        unlink($db);
        // A second name of the same file (hard link) is refused too.
        link($r38oBase . '/secret/root.sqlite', $db);
        $hard = $r38oRun([PHP_BINARY, $app . '/cli/backup.php', '--quiet']);
        assert_true($hard['exit'] !== 0 && str_contains($hard['err'], 'single link') && !$leaked(),
            'R38 root backup: a hard link to another database is refused (exit ' . $hard['exit'] . ', ' . trim(substr($hard['err'], 0, 200)) . ')');
        unlink($db);
        // The shop's own database file: the root backup works as before.
        rename($private . '/db/real.sqlite', $db);
        $own = $r38oRun([PHP_BINARY, $app . '/cli/backup.php', '--quiet']);
        assert_true($own['exit'] === 0 && !$leaked(), 'R38 root backup: the shop\'s own database file is backed up as root (exit ' . $own['exit'] . ', ' . trim(substr($own['out'] . $own['err'], 0, 220)) . ')');
    }
} finally {
    shell_exec('rm -rf ' . escapeshellarg($r38oBase));
}
