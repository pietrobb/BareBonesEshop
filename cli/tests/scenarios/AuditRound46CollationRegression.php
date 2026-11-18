<?php

// Review round 46 (#11): on MySQL 8 a table created with `DEFAULT CHARSET=utf8mb4` and no COLLATE gets
// utf8mb4_0900_ai_ci, not the database collation. Production databases are often utf8mb4_unicode_ci, so the tables
// five released migrations created (e-mail opt-outs, stock alerts, incidents, CSP reports, ERP watermark) failed every
// JOIN with the base tables (1267 "Illegal mix of collations"). 20261118110000_mysql_table_collation converts them.

echo "\n--- Audit round 46: MySQL tables follow the database collation ---\n";

if (!getenv('TEST_MYSQL_DSN')) {
    print "  [SKIP] R46 collation native MySQL (set TEST_MYSQL_DSN)\n";
} else {
    $r46cDb = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]);
    $r46cDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $r46cName = 'bbe_r46c_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
    $r46cMigrations = TEST_CLI_DIR . '/../migrations/';
    $r46cTables = ['email_suppressions', 'stock_alert_state', 'stock_alert_digests', 'operational_incidents',
        'operational_alert_state', 'operational_monitor_state', 'csp_violations', 'erp_sync_state'];
    try {
        $r46cDb->exec("CREATE DATABASE `{$r46cName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $r46cDb->exec("USE `{$r46cName}`");
        // Base tables as an install made them: no table charset, so they inherit utf8mb4_unicode_ci.
        $r46cDb->exec('CREATE TABLE customers (id INT PRIMARY KEY, email VARCHAR(191) NOT NULL) ENGINE=InnoDB');
        $r46cDb->exec("CREATE TABLE products (id INT PRIMARY KEY, sku VARCHAR(191) NOT NULL, store_id VARCHAR(64) NOT NULL DEFAULT 'default') ENGINE=InnoDB");
        foreach (['20261007110000_abandoned_cart_recovery', '20261008090000_stock_alerts', '20261012090000_operational_incidents',
            '20261013120000_payment_monitoring', '20261017093000_catalog_stock_erp_retry'] as $r46cOld) {
            (require $r46cMigrations . $r46cOld . '.php')->up($r46cDb, false);
        }
        $r46cDb->exec("INSERT INTO customers (id, email) VALUES (1, 'opted-out@example.test'), (2, 'subscribed@example.test')");
        $r46cDb->exec("INSERT INTO email_suppressions (email) VALUES ('opted-out@example.test')");
        $r46cDb->exec("INSERT INTO products (id, sku) VALUES (1, 'R46-LOW'), (2, 'R46-OK')");
        $r46cDb->exec("INSERT INTO stock_alert_state (store_id, sku, level) VALUES ('default', 'R46-LOW', 'low')");
        $r46cJoins = static function () use ($r46cDb): array {
            return [
                $r46cDb->query('SELECT c.id FROM customers c JOIN email_suppressions s ON s.email = c.email ORDER BY c.id')->fetchAll(PDO::FETCH_COLUMN),
                $r46cDb->query('SELECT p.sku FROM products p JOIN stock_alert_state a ON a.sku = p.sku AND a.store_id = p.store_id ORDER BY p.id')->fetchAll(PDO::FETCH_COLUMN),
            ];
        };
        $r46cCollations = static function () use ($r46cDb, $r46cTables): array {
            $stmt = $r46cDb->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $out = [];
            foreach ($r46cTables as $table) {
                $stmt->execute([$table]);
                $out[$table] = (string)$stmt->fetchColumn();
            }
            return $out;
        };

        // The server must actually default utf8mb4 to another collation for the probe to mean anything (MySQL 8: 0900_ai_ci).
        $r46cBefore = $r46cCollations();
        assert_true($r46cBefore['email_suppressions'] !== 'utf8mb4_unicode_ci',
            'R46 collation: the released migration still creates email_suppressions in the server default (' . $r46cBefore['email_suppressions'] . '), so the probe is real');

        (require $r46cMigrations . '20261118110000_mysql_table_collation.php')->up($r46cDb, false);
        $r46cError = null;
        try { $r46cJoined = $r46cJoins(); } catch (PDOException $e) { $r46cJoined = null; $r46cError = $e->getMessage(); }
        assert_equals([[1], ['R46-LOW']], $r46cJoined,
            'R46 collation: e-mail opt-outs and stock alerts JOIN customers and products on a utf8mb4_unicode_ci database' . ($r46cError ? " ({$r46cError})" : ''));
        assert_equals(array_fill_keys($r46cTables, 'utf8mb4_unicode_ci'), $r46cCollations(),
            'R46 collation: every table the five migrations created now uses the database collation');
        $r46cColumns = (int)$r46cDb->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> 'utf8mb4_unicode_ci'")->fetchColumn();
        assert_equals(0, $r46cColumns, 'R46 collation: no text column keeps another collation');
        assert_equals([['opted-out@example.test'], ['R46-LOW']], [
            $r46cDb->query('SELECT email FROM email_suppressions')->fetchAll(PDO::FETCH_COLUMN),
            $r46cDb->query('SELECT sku FROM stock_alert_state')->fetchAll(PDO::FETCH_COLUMN),
        ], 'R46 collation: converting keeps the rows');

        // A rerun changes nothing (no ALTER: the table's CREATE_TIME of an InnoDB rebuild would move).
        $r46cCreated = $r46cDb->query("SELECT TABLE_NAME, CREATE_TIME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_KEY_PAIR);
        sleep(1);
        (require $r46cMigrations . '20261118110000_mysql_table_collation.php')->up($r46cDb, false);
        assert_equals($r46cCreated, $r46cDb->query("SELECT TABLE_NAME, CREATE_TIME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_KEY_PAIR),
            'R46 collation: a second run rebuilds no table');
    } catch (Throwable $e) {
        assert_true(false, 'R46 collation (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    } finally {
        $r46cDb->exec("DROP DATABASE IF EXISTS `{$r46cName}`");
    }
}

// SQLite: the migration is a no-op.
$r46cSqlite = new PDO('sqlite::memory:');
$r46cSqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
(require TEST_CLI_DIR . '/../migrations/20261118110000_mysql_table_collation.php')->up($r46cSqlite, true);
assert_equals([], $r46cSqlite->query("SELECT name FROM sqlite_master")->fetchAll(PDO::FETCH_COLUMN), 'R46 collation: SQLite has nothing to convert');
