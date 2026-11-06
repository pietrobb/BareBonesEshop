<?php

// Review round 18 (2026-11-06), medium "mariadb-dump check": against MySQL 8 the R17 check (a MariaDB dump client would
// write generated columns into the dump) listed 184 "generated" columns where the schema has 2. MySQL marks every
// column with an expression default (DEFAULT CURRENT_TIMESTAMP) DEFAULT_GENERATED in information_schema EXTRA, and
// the check matched any "GENERATED".
echo "\n--- R18: the mariadb-dump check names only the real generated columns ---\n";
$r18dSource = (string)file_get_contents(TEST_CLI_DIR . '/../src/MysqlUpdateDatabaseBackup.php');
assert_true(str_contains($r18dSource, "EXTRA LIKE '%VIRTUAL GENERATED%' OR EXTRA LIKE '%STORED GENERATED%'")
    && !str_contains($r18dSource, "EXTRA LIKE '%GENERATED%'"),
    'R18 dump check: only VIRTUAL and STORED generated columns count, not DEFAULT_GENERATED');
if (Db::isMysql()) {
    $r18dDb = Db::get();
    $r18dSchema = (string)$r18dDb->query('SELECT DATABASE()')->fetchColumn();
    $r18dDefaultGenerated = (int)$r18dDb->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND EXTRA LIKE '%DEFAULT_GENERATED%'")->fetchColumn();
    $r18dColumns = MysqlUpdateDatabaseBackup::generatedColumns($r18dDb, $r18dSchema);
    assert_true(in_array('categories.parent_id_uniq', $r18dColumns, true) && in_array('invoices.invoice_order_unique', $r18dColumns, true)
        && count($r18dColumns) === 2,
        "R18 dump check (MySQL live): the generated columns are categories.parent_id_uniq and invoices.invoice_order_unique only, not the {$r18dDefaultGenerated} DEFAULT_GENERATED ones (" . implode(', ', $r18dColumns) . ')');
}
