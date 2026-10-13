<?php
/**
 * Audit 2026-10-12 / A09.2 / finding 23 — backups and retention maintenance, driven through the
 * real CLI entrypoints in a disposable application copy (own config.php, own SQLite, private
 * backup directory outside the webroot):
 *   - `gdpr-gc --apply` always died before deleting anything: BackupService was never initialised.
 *   - `backup --gc` never deleted anything: every CLI backup was labelled 'manual', a protected label.
 *   - backups with personal data were world-readable (0644 files, 0775 directories).
 */

echo "\n--- Audit A09.2: gdpr-gc pre-backup, backup retention, private backup files ---\n";

function a092_copy_tree(string $from, string $to): void
{
    if (!is_dir($to)) {
        mkdir($to, 0750, true);
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($items as $item) {
        $destination = $to . DIRECTORY_SEPARATOR . substr($item->getPathname(), strlen($from) + 1);
        if ($item->isDir()) {
            if (!is_dir($destination)) {
                mkdir($destination, 0750, true);
            }
        } else {
            copy($item->getPathname(), $destination);
        }
    }
}

function a092_run(string $script, array $args): array
{
    $process = proc_open(array_merge([PHP_BINARY, $script], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'output' => $out . $err];
}

function a092_backups(string $storage): array
{
    $rows = [];
    if (!is_dir($storage)) {
        return $rows;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS)) as $file) {
        $name = $file->getFilename();
        if (str_starts_with($name, 'eshop-') && str_ends_with($name, '.db')) {
            $meta = json_decode((string)@file_get_contents($file->getPathname() . '.meta.json'), true) ?: [];
            $rows[] = ['path' => $file->getPathname(), 'label' => (string)($meta['label'] ?? '')];
        }
    }
    return $rows;
}

$repoRoot = dirname(__DIR__, 3);
$a092Root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bbe-a092-' . getmypid() . '-' . bin2hex(random_bytes(4));
$app = $a092Root . DIRECTORY_SEPARATOR . 'app';
$storage = $a092Root . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'backups';
$dbPath = $a092Root . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'shop.sqlite';
try {
    foreach (['src', 'adapters'] as $directory) {
        a092_copy_tree($repoRoot . DIRECTORY_SEPARATOR . $directory, $app . DIRECTORY_SEPARATOR . $directory);
    }
    mkdir($app . '/cli', 0750, true);
    mkdir($app . '/config', 0750, true);
    mkdir(dirname($dbPath), 0750, true);
    foreach (['cli-only.php', 'gdpr-gc.php', 'backup.php'] as $file) {
        copy($repoRoot . '/cli/' . $file, $app . '/cli/' . $file);
    }
    copy($repoRoot . '/config/backup.json', $app . '/config/backup.json');
    file_put_contents($app . '/config/data-retention.json', json_encode(['policies' => [
        'abandoned_carts' => ['table' => 'abandoned_carts', 'ttl_days' => 30, 'field' => 'created_at', 'action' => 'delete'],
    ]]));
    file_put_contents($app . '/config.php', '<?php return ' . var_export([
        'db_driver' => 'sqlite',
        'db_sqlite_path' => $dbPath,
        'backup_storage_dir' => $storage,
        'backup_log_file' => $a092Root . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'backup.log',
    ], true) . ";\n");

    $seed = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $seed->exec('CREATE TABLE abandoned_carts (id INTEGER PRIMARY KEY, customer_email TEXT, created_at TEXT)');
    $seed->exec('CREATE TABLE data_retention_runs (id INTEGER PRIMARY KEY, mode TEXT, policy_key TEXT, affected_rows INTEGER, details TEXT, duration_ms INTEGER, run_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $seed->exec("INSERT INTO abandoned_carts (customer_email, created_at) VALUES ('old@example.test', '2020-01-01 00:00:00'), ('new@example.test', '2999-01-01 00:00:00')");
    $seed = null;

    // 1. gdpr-gc --apply takes the pre-retention snapshot and then applies the policy.
    $gc = a092_run($app . '/cli/gdpr-gc.php', ['--apply']);
    assert_equals(0, $gc['exit'], 'A09.2: gdpr-gc --apply succeeds (exit 0) — ' . trim(substr($gc['output'], 0, 160)));
    $labels = array_column(a092_backups($storage), 'label');
    assert_equals(['pre-retention-gc'], $labels, 'A09.2: gdpr-gc --apply stores a pre-retention backup before deleting data');
    $check = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    assert_equals(['new@example.test'], $check->query('SELECT customer_email FROM abandoned_carts ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), 'A09.2: retention deletes only rows past the TTL after the snapshot');
    $check = null;

    // 1b. Without a possible automatic snapshot the destructive run is refused, unless the operator
    //     explicitly confirms an external backup.
    $backupJson = (string)file_get_contents($app . '/config/backup.json');
    file_put_contents($app . '/config/backup.json', json_encode(['enabled' => false]));
    $seed = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $seed->exec("INSERT INTO abandoned_carts (customer_email, created_at) VALUES ('old2@example.test', '2020-01-01 00:00:00')");
    $seed = null;
    $refused = a092_run($app . '/cli/gdpr-gc.php', ['--apply']);
    $check = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $remaining = (int)$check->query("SELECT COUNT(*) FROM abandoned_carts WHERE customer_email = 'old2@example.test'")->fetchColumn();
    $check = null;
    assert_true($refused['exit'] === 3 && $remaining === 1, 'A09.2: gdpr-gc --apply refuses (exit 3, no deletion) when no snapshot can be taken');
    $confirmed = a092_run($app . '/cli/gdpr-gc.php', ['--apply', '--skip-backup']);
    $check = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $remaining = (int)$check->query("SELECT COUNT(*) FROM abandoned_carts WHERE customer_email = 'old2@example.test'")->fetchColumn();
    $check = null;
    assert_true($confirmed['exit'] === 0 && $remaining === 0, 'A09.2: --skip-backup applies retention after an operator-confirmed external backup');
    file_put_contents($app . '/config/backup.json', $backupJson);

    // 2. Scheduled CLI backups are subject to retention; explicit manual backups stay protected.
    $manual = a092_run($app . '/cli/backup.php', ['--label=manual', '--quiet']);
    assert_equals(0, $manual['exit'], 'A09.2: explicit manual backup succeeds');
    for ($i = 0; $i < 5; $i++) {
        $scheduled = a092_run($app . '/cli/backup.php', ['--quiet']);
        assert_true($scheduled['exit'] === 0, 'A09.2: default (cron) backup run ' . ($i + 1) . ' succeeds');
    }
    $collected = a092_run($app . '/cli/backup.php', ['--gc-only']);
    assert_equals(0, $collected['exit'], 'A09.2: backup --gc-only succeeds');
    $after = a092_backups($storage);
    $counts = array_count_values(array_column($after, 'label'));
    // minimum_kept=3 (+ the protected manual one); every backup is from the same day.
    assert_true(count($after) <= 4, 'A09.2: retention GC actually deletes surplus same-day CLI backups (kept ' . count($after) . ' of 7)');
    assert_equals(1, $counts['manual'] ?? 0, 'A09.2: explicitly labelled manual backup is preserved by GC');
    assert_true(($counts['scheduled'] ?? 0) >= 2, 'A09.2: the newest scheduled backups survive retention');

    // 3. Backup artefacts contain personal data and must not be readable by other users.
    if (DIRECTORY_SEPARATOR === '\\') {
        echo "  [SKIP] A09.2 POSIX permission bits (0600 files / 0700 dirs) — not enforceable on Windows; verified on Linux CI\n";
    } else {
        clearstatcache();
        $badFiles = [];
        foreach ($after as $row) {
            foreach ([$row['path'], $row['path'] . '.meta.json'] as $file) {
                if ((fileperms($file) & 0777) !== 0600) {
                    $badFiles[] = basename($file) . '=' . decoct(fileperms($file) & 0777);
                }
            }
            if ((fileperms(dirname($row['path'])) & 0777) !== 0700) {
                $badFiles[] = 'dir=' . decoct(fileperms(dirname($row['path'])) & 0777);
            }
        }
        assert_equals([], array_values(array_unique($badFiles)), 'A09.2: backup files are 0600 and backup directories 0700');
    }
} finally {
    if (function_exists('updateTestRemoveTree')) {
        updateTestRemoveTree($a092Root);
    }
}

// A11.3 / found by the PHP 8.4 Linux run: backups created within the same second share a 1 s mtime,
// so "newest first" fell back to directory order — GC deleted the newest scheduled backups and
// `restore --latest` could pick an older one. Order must not depend on the filesystem.
echo "\n--- Audit A11.3: same-second backups keep a deterministic newest-first order ---\n";
$a113Root = sys_get_temp_dir() . '/bbe-a113-backup-order-' . bin2hex(random_bytes(4));
$a113Dir = $a113Root . '/storage/backups/2026/01/02';
mkdir($a113Dir, 0700, true);
$a113Pdo = new PDO('sqlite:' . $a113Root . '/live.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$a113Pdo->exec('CREATE TABLE x (id INTEGER PRIMARY KEY)');
try {
    BackupService::init($a113Pdo, [
        'enabled' => true, 'storage' => 'local', 'compression' => false, 'auto_gc_on_backup' => false,
        'retention' => ['daily' => 1, 'weekly' => 1, 'monthly' => 1, 'minimum_kept' => 3],
    ], $a113Root);
    $a113Second = strtotime('2026-01-02T04:00:00+00:00');
    // Legacy rows (second-precision timestamp only) told apart by the -N uniqueness suffix.
    foreach (['eshop-20260102-040000.db', 'eshop-20260102-040000-1.db', 'eshop-20260102-040000-2.db', 'eshop-20260102-040000-3.db', 'eshop-20260102-040000-4.db'] as $a113Name) {
        file_put_contents($a113Dir . '/' . $a113Name, str_repeat("\0", 32));
        file_put_contents($a113Dir . '/' . $a113Name . '.meta.json', json_encode(['timestamp' => '2026-01-02T04:00:00+00:00', 'label' => 'scheduled']));
        touch($a113Dir . '/' . $a113Name, $a113Second);
    }
    clearstatcache();
    assert_equals('2026/01/02/eshop-20260102-040000-4.db', BackupService::list()[0]['path'] ?? null, 'A11.3: list() puts the last same-second backup first (restore --latest)');
    $a113Gc = BackupService::gc();
    $a113Left = array_map('basename', glob($a113Dir . '/eshop-*.db') ?: []);
    sort($a113Left);
    assert_equals(['eshop-20260102-040000-2.db', 'eshop-20260102-040000-3.db', 'eshop-20260102-040000-4.db'], $a113Left, 'A11.3: GC keeps the newest same-second backups, deletes the oldest');

    // New backups carry a microsecond creation stamp; it wins over name and mtime.
    $a113First = BackupService::create(['label' => 'scheduled']);
    $a113Second2 = BackupService::create(['label' => 'scheduled']);
    $a113Meta = json_decode((string)file_get_contents($a113Root . '/storage/backups/' . $a113Second2['remote_path'] . '.meta.json'), true) ?: [];
    assert_true(ctype_digit((string)($a113Meta['created_us'] ?? '')), 'A11.3: backup metadata records a microsecond creation stamp');
    clearstatcache();
    assert_equals($a113Second2['remote_path'], BackupService::list()[0]['path'] ?? null, 'A11.3: the most recently created backup is listed first');
    assert_true($a113First['remote_path'] !== $a113Second2['remote_path'], 'A11.3: consecutive backups get distinct paths');
} finally {
    BackupService::reset();
    $a113Pdo = null;
    if (function_exists('updateTestRemoveTree')) {
        updateTestRemoveTree($a113Root);
    }
}
