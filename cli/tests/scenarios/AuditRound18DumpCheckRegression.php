<?php

// Review round 18 (2026-11-06), medium "mariadb-dump check": against MySQL 8 the R17 check (a MariaDB dump client would
// write generated columns into the dump) listed 184 "generated" columns where the schema has 2. MySQL marks every
// column with an expression default (DEFAULT CURRENT_TIMESTAMP) DEFAULT_GENERATED in information_schema EXTRA, and
// the check matched any "GENERATED". The live part migrates a disposable schema on TEST_MYSQL_DSN (MySQL 8.4 and
// MariaDB 11.4 in CI) and asks generatedColumns() for its list.
echo "\n--- R18: the mariadb-dump check names only the real generated columns ---\n";
$r18dSource = (string)file_get_contents(TEST_CLI_DIR . '/../src/MysqlUpdateDatabaseBackup.php');
assert_true(str_contains($r18dSource, "EXTRA LIKE '%VIRTUAL GENERATED%' OR EXTRA LIKE '%STORED GENERATED%'")
    && !str_contains($r18dSource, "EXTRA LIKE '%GENERATED%'"),
    'R18 dump check: only VIRTUAL and STORED generated columns count, not DEFAULT_GENERATED');
if (!getenv('TEST_MYSQL_DSN')) {
    if (getenv('BAREBONES_REQUIRE_MYSQL_UPDATE_TEST') === '1') assert_true(false, 'R18 dump check (MySQL live): CI requires TEST_MYSQL_DSN');
    else echo "  [SKIP] R18 dump check (MySQL live) (set TEST_MYSQL_DSN)\n";
} else {
    $r18dSuitePdo = Db::get();
    $r18dSuiteDriver = Db::driver();
    $r18dSchema = 'bbe_r18d_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
    $r18dServer = null;
    try {
        $r18dServer = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $r18dServer->exec("CREATE DATABASE `{$r18dSchema}` CHARACTER SET utf8mb4");
        $r18dServer->exec("USE `{$r18dSchema}`");
        Db::init($r18dServer, 'mysql');
        (new Migrator($r18dServer, TEST_CLI_DIR . '/../migrations', test_fixture_path('r18d-migrate.lock')))->apply();
        $r18dVersion = (string)$r18dServer->query('SELECT VERSION()')->fetchColumn();
        $r18dDefaultGenerated = (int)$r18dServer->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND EXTRA LIKE '%GENERATED%'")->fetchColumn();
        $r18dColumns = MysqlUpdateDatabaseBackup::generatedColumns($r18dServer, $r18dSchema);
        sort($r18dColumns);
        echo "  R18 dump check (MySQL live) on {$r18dVersion}: generatedColumns() = [" . implode(', ', $r18dColumns)
            . "], the old EXTRA LIKE '%GENERATED%' query matches {$r18dDefaultGenerated}\n";
        assert_equals(['categories.parent_id_uniq', 'invoices.invoice_order_unique'], $r18dColumns,
            "R18 dump check (MySQL live): only the 2 real generated columns are named, not the {$r18dDefaultGenerated} the old query matched");
    } catch (Throwable $r18dFailure) {
        assert_true(false, 'R18 dump check (MySQL live) (' . $r18dFailure->getMessage() . ')');
    } finally {
        if ($r18dServer !== null) {
            try { $r18dServer->exec("DROP DATABASE IF EXISTS `{$r18dSchema}`"); } catch (Throwable) {}
        }
        Db::init($r18dSuitePdo, $r18dSuiteDriver);
    }
}
