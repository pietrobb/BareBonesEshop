<?php

// Round 8 review (2026-10-21), hotfix: a signed update from v2.0.0 always failed. cli/update.php of the old
// release has the old Db class loaded when it autoloads the new Migrator, and v2.0.0's Db has neither
// forgetSchema() nor optimize(). The child process below loads a Db with exactly v2.0.0's public methods first.
echo "\n--- Audit round 8 (hotfix): new Migrator inside a v2.0.0 update process ---\n";
$r8hRoot = test_fixture_path('r8-hotfix-migrator');
mkdir($r8hRoot);
file_put_contents($r8hRoot . '/old-db.php', <<<'PHP'
<?php
// Public API of src/Db.php at v2.0.0.
class Db {
    private static ?PDO $pdo = null;
    private static string $driver = 'sqlite';
    public static function get(): PDO { return self::$pdo ?? throw new RuntimeException('Db not initialised'); }
    public static function init(PDO $pdo, string $driver = 'sqlite'): void { self::$pdo = $pdo; self::$driver = $driver; }
    public static function reset(): void { self::$pdo = null; }
    public static function resetQueryCount(): void {}
    public static function queryCount(): ?int { return null; }
    public static function driver(): string { return self::$driver; }
    public static function isSqlite(): bool { return self::driver() === 'sqlite'; }
    public static function isMysql(): bool  { return self::driver() === 'mysql'; }
    public static function now(): string { return self::isMysql() ? 'NOW()' : "datetime('now')"; }
    public static function insertIgnore(): string { return self::isMysql() ? 'INSERT IGNORE' : 'INSERT OR IGNORE'; }
    public static function upsertClause(string $conflictTarget, string $updatesSqlite, ?string $updatesMysql = null): string {
        return self::isMysql() ? 'ON DUPLICATE KEY UPDATE ' . ($updatesMysql ?? $updatesSqlite) : "ON CONFLICT({$conflictTarget}) DO UPDATE SET {$updatesSqlite}";
    }
    public static function dateAddSeconds(string $field, string $seconds): string { return $field; }
}
PHP);
file_put_contents($r8hRoot . '/update.php', <<<'PHP'
<?php
[, $root, $dir] = $argv;
require $dir . '/old-db.php';
require $root . '/src/autoload.php';
$pdo = new PDO('sqlite:' . $dir . '/update.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Db::init($pdo, 'sqlite');
try {
    $applied = (new Migrator($pdo, $root . '/migrations', $dir . '/migrate.lock'))->apply();
    $pending = (new Migrator($pdo, $root . '/migrations', $dir . '/migrate.lock'))->apply();
    echo json_encode(['old_db' => !method_exists(Db::class, 'forgetSchema'), 'applied' => count($applied) > 0, 'second_run' => count($pending)]);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
PHP);
try {
    $r8hProcess = proc_open([PHP_BINARY, $r8hRoot . '/update.php', dirname(TEST_CLI_DIR), $r8hRoot], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r8hPipes);
    fclose($r8hPipes[0]);
    $r8hOut = stream_get_contents($r8hPipes[1]) . stream_get_contents($r8hPipes[2]);
    fclose($r8hPipes[1]);
    fclose($r8hPipes[2]);
    $r8hCode = proc_close($r8hProcess);
    assert_equals([0, ['old_db' => true, 'applied' => true, 'second_run' => 0]], [$r8hCode, json_decode(trim($r8hOut), true) ?? $r8hOut],
        'R8 update v2.0.0: the new Migrator applies every migration while the v2.0.0 Db class is loaded');
} finally {
    foreach (glob($r8hRoot . '/*') ?: [] as $r8hFile) @unlink($r8hFile);
    @rmdir($r8hRoot);
}

// Import of two products with the same name (so the same derived slug). MySQL's ON DUPLICATE KEY UPDATE fired on
// the unique slug: the second row overwrote the first product's price and its stock went to a SKU without a product.
echo "\n--- Audit round 8 (hotfix): CSV import of two products with the same name ---\n";
$r8iRoot = test_fixture_path('r8-hotfix-import');
mkdir($r8iRoot);
file_put_contents($r8iRoot . '/import.php', <<<'PHP'
<?php
[, $driver, $phase, $root, $target, $dir] = $argv;
require $root . '/src/autoload.php';
$db = $driver === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new PDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($driver === 'mysql') {
    if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
    $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
    $db->exec("USE `{$target}`");
}
Db::init($db, $driver);
(new Migrator($db, $root . '/migrations', $dir . '/migrate-' . $driver . '.lock'))->apply();
file_put_contents($dir . '/same-name.csv', "sku,name,price,stock,category\nR8I-A,R8 Same Name,10.00,3,r8i\nR8I-B,R8 Same Name,20.00,5,r8i\nR8I-A,R8 Same Name,11.00,3,r8i\n");
$result = Import::csv($dir . '/same-name.csv');
$rows = $db->query("SELECT sku, slug, price, stock FROM products WHERE sku LIKE 'R8I-%' ORDER BY sku")->fetchAll();
echo json_encode(['imported' => $result['imported'], 'errors' => $result['errors'], 'products' => array_map(
    static fn(array $r): array => [$r['sku'], $r['slug'], (int)$r['price'], (int)$r['stock']], $rows)]);
PHP);
$r8iDrivers = ['sqlite' => $r8iRoot . '/import.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r8iDrivers['mysql'] = 'bbe_r8i_import_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit R8 native MySQL same-name import (set TEST_MYSQL_DSN)\n";
$r8iRun = static function(array $args): string {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    return $out;
};
try {
    foreach ($r8iDrivers as $r8iDriver => $r8iTarget) {
        $r8iOut = $r8iRun([$r8iRoot . '/import.php', $r8iDriver, 'run', dirname(TEST_CLI_DIR), $r8iTarget, $r8iRoot]);
        assert_equals(['imported' => 3, 'errors' => [], 'products' => [['R8I-A', 'r8-same-name', 1100, 3], ['R8I-B', 'r8-same-name-2', 2000, 5]]],
            json_decode(trim($r8iOut), true) ?? $r8iOut,
            "R8 {$r8iDriver} import: a same-name product gets its own slug, neither product overwrites the other, re-import keeps the slug");
    }
} finally {
    if (isset($r8iDrivers['mysql'])) $r8iRun([$r8iRoot . '/import.php', 'mysql', 'teardown', dirname(TEST_CLI_DIR), $r8iDrivers['mysql'], $r8iRoot]);
    foreach (glob($r8iRoot . '/*') ?: [] as $r8iFile) @unlink($r8iFile);
    @rmdir($r8iRoot);
}
