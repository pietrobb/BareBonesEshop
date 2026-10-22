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
