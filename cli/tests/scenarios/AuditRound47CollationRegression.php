<?php

// Review round 17 (R47, low 5): 20261118110000_mysql_table_collation converted the newer tables to the database's
// default collation. A default changed after install (ALTER DATABASE, a dump restored into another schema) left them
// unlike the core tables they are joined with, and JOINs still failed with 1267. The target is now the core tables'
// collation; only a table that differs is rebuilt, with LOCK=SHARED so reads continue.

echo "\n--- Audit R47: the collation repair follows the core tables, not the database default ---\n";

$r47cFile = TEST_CLI_DIR . '/../migrations/20261118110000_mysql_table_collation.php';
$r47cSource = (string)file_get_contents($r47cFile);
assert_true(str_contains($r47cSource, ', LOCK=SHARED")'), 'R47 collation: a converted table stays readable (LOCK=SHARED)');
assert_true(!str_contains($r47cSource, 'DEFAULT_COLLATION_NAME'), 'R47 collation: the database default is not the target');

if (!getenv('TEST_MYSQL_DSN')) {
    print "  [SKIP] R47 collation native MySQL (set TEST_MYSQL_DSN)\n";
    return;
}
$r47cDb = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]);
$r47cDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$r47cName = 'bbe_r47c_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
$r47cMigrations = TEST_CLI_DIR . '/../migrations/';
try {
    $r47cDb->exec("CREATE DATABASE `{$r47cName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $r47cDb->exec("USE `{$r47cName}`");
    $r47cDb->exec('CREATE TABLE customers (id INT PRIMARY KEY, email VARCHAR(191) NOT NULL) ENGINE=InnoDB');
    $r47cDb->exec("CREATE TABLE products (id INT PRIMARY KEY, sku VARCHAR(191) NOT NULL, store_id VARCHAR(64) NOT NULL DEFAULT 'default') ENGINE=InnoDB");
    // The database default changed after the core tables were created (they stay utf8mb4_unicode_ci).
    $r47cDb->exec("ALTER DATABASE `{$r47cName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    foreach (['20261007110000_abandoned_cart_recovery', '20261008090000_stock_alerts', '20261012090000_operational_incidents',
        '20261013120000_payment_monitoring', '20261017093000_catalog_stock_erp_retry'] as $r47cOld) {
        (require $r47cMigrations . $r47cOld . '.php')->up($r47cDb, false);
    }
    $r47cDb->exec("INSERT INTO customers (id, email) VALUES (1, 'opted-out@example.test'), (2, 'subscribed@example.test')");
    $r47cDb->exec("INSERT INTO email_suppressions (email) VALUES ('opted-out@example.test')");
    $r47cDb->exec("INSERT INTO products (id, sku) VALUES (1, 'R47-LOW'), (2, 'R47-OK')");
    $r47cDb->exec("INSERT INTO stock_alert_state (store_id, sku, level) VALUES ('default', 'R47-LOW', 'low')");

    (require $r47cFile)->up($r47cDb, false);
    $r47cError = null;
    try {
        $r47cJoined = [
            $r47cDb->query('SELECT c.id FROM customers c JOIN email_suppressions s ON s.email = c.email ORDER BY c.id')->fetchAll(PDO::FETCH_COLUMN),
            $r47cDb->query('SELECT p.sku FROM products p JOIN stock_alert_state a ON a.sku = p.sku AND a.store_id = p.store_id ORDER BY p.id')->fetchAll(PDO::FETCH_COLUMN),
        ];
    } catch (PDOException $e) { $r47cJoined = null; $r47cError = $e->getMessage(); }
    assert_equals([[1], ['R47-LOW']], $r47cJoined, 'R47 collation: after the database default changed, opt-outs and stock alerts still JOIN the core tables' . ($r47cError ? " ({$r47cError})" : ''));
    $r47cOther = $r47cDb->query("SELECT DISTINCT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
    assert_equals(['utf8mb4_unicode_ci'], $r47cOther, 'R47 collation: every table has the core tables\' collation (not the database default utf8mb4_general_ci)');

    // Core tables that disagree are left to the operator: nothing is converted.
    $r47cDb->exec('ALTER TABLE products CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    $r47cDb->exec('ALTER TABLE email_suppressions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
    (require $r47cFile)->up($r47cDb, false);
    assert_equals('utf8mb4_bin', (string)$r47cDb->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_suppressions'")->fetchColumn(),
        'R47 collation: with customers and products on different collations the migration guesses nothing');
} catch (Throwable $e) {
    assert_true(false, 'R47 collation (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $r47cDb->exec("DROP DATABASE IF EXISTS `{$r47cName}`");
}
