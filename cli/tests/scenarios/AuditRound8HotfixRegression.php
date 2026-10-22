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

// Category counts: the R7 recount ran one product scan per category (57 s at 40k products), inside the feed's
// write transaction on every full-upsert sync. Now a fixed number of statements, whatever the category count.
echo "\n--- Audit round 8 (hotfix): category product counts in one pass ---\n";
$r8cFile = test_fixture_path('r8-hotfix-counts') . '.sqlite';
$r8cPrev = [Db::get(), Db::driver()];
$r8cDb = new QueryCountingPDO('sqlite:' . $r8cFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
try {
    Db::init($r8cDb, 'sqlite');
    (new Migrator($r8cDb, dirname(TEST_CLI_DIR) . '/migrations', $r8cFile . '.lock'))->apply(static fn() => null);
    $r8cCat = $r8cDb->prepare('INSERT INTO categories (store_id, name, slug, path, parent_id, depth) VALUES (?, ?, ?, ?, ?, ?)');
    $r8cId = static function(string $store, string $path, ?int $parent) use ($r8cDb, $r8cCat): int {
        $r8cCat->execute([$store, $path, $store . '-' . str_replace('/', '-', $path), $path, $parent, substr_count($path, '/')]);
        return (int)$r8cDb->lastInsertId();
    };
    $r8cA = $r8cId('default', 'a', null);
    $r8cAB = $r8cId('default', 'a/b', $r8cA);
    $r8cAC = $r8cId('default', 'a/c', $r8cA);
    $r8cX = $r8cId('default', 'x', null);
    $r8cCzA = $r8cId('cz', 'a', null);          // same path in another catalog: not an ancestor of default's a/b
    $r8cEmpty = $r8cId('default', 'empty', null);
    for ($i = 0; $i < 60; $i++) $r8cId('default', "a/c/n{$i}", $r8cAC); // many categories, still a constant statement count
    $r8cProduct = $r8cDb->prepare("INSERT INTO products (store_id, sku, slug, name, category_id, price, status) VALUES ('default', ?, ?, ?, ?, 100, ?)");
    $r8cLink = $r8cDb->prepare('INSERT INTO product_categories (product_id, category_id) VALUES (?, ?)');
    $r8cAdd = static function(string $sku, int $category, string $status = 'active', array $links = []) use ($r8cDb, $r8cProduct, $r8cLink): void {
        $r8cProduct->execute([$sku, strtolower($sku), $sku, $category, $status]);
        $id = (int)$r8cDb->lastInsertId();
        foreach ($links as $link) $r8cLink->execute([$id, $link]);
    };
    $r8cAdd('R8C-1', $r8cAB);
    $r8cAdd('R8C-2', $r8cAB, 'active', [$r8cAC, $r8cX]); // linked into a sibling (same ancestor a: once) and into x
    $r8cAdd('R8C-3', $r8cAC, 'draft');
    $r8cAdd('R8C-4', $r8cX, 'active', [$r8cCzA]);
    $r8cDb->exec("UPDATE categories SET product_count = 99 WHERE id = {$r8cEmpty}");
    $r8cDb->resetQueryCount();
    Import::recountCategories($r8cDb);
    $r8cStatements = $r8cDb->queryCount();
    $r8cCounts = $r8cDb->query('SELECT id, product_count FROM categories')->fetchAll(PDO::FETCH_KEY_PAIR);
    assert_equals([2, 2, 1, 2, 1, 0], array_map(static fn(int $id): int => (int)$r8cCounts[$id], [$r8cA, $r8cAB, $r8cAC, $r8cX, $r8cCzA, $r8cEmpty]),
        'R8 category counts: subtree totals with linked categories counted once per category, drafts skipped, catalogs kept apart, stale count reset');
    assert_true($r8cStatements <= 5, "R8 category counts: a full recount of 66 categories takes a constant number of statements ({$r8cStatements})");
    $r8cDb->exec("UPDATE products SET status = 'draft' WHERE sku = 'R8C-1'");
    Import::recountCategories($r8cDb, [$r8cAB]);
    $r8cCounts = $r8cDb->query('SELECT id, product_count FROM categories')->fetchAll(PDO::FETCH_KEY_PAIR);
    assert_equals([2, 1], [(int)$r8cCounts[$r8cA], (int)$r8cCounts[$r8cAB]],
        'R8 category counts: recounting given ids touches only those (the ancestor a is refreshed by CategoryCounts::refresh, not here)');
} finally {
    Db::init(...$r8cPrev);
    $r8cDb = null;
    @unlink($r8cFile);
    @unlink($r8cFile . '.lock');
}
$r8cFeed = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/FeedImport.php');
assert_true(preg_match('/\$db->commit\(\);\s*(?:\/\/[^\n]*\n\s*)*if \(\$importMode === self::MODE_UPSERT[^\n]*recountCategories/', $r8cFeed) === 1,
    'R8 feed sync: the category recount runs after the feed transaction commits, not inside it');

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
