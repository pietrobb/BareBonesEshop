<?php

// R46 (#11): on MySQL 8 `DEFAULT CHARSET=utf8mb4` without COLLATE gives utf8mb4_0900_ai_ci, not the database collation.
// On a utf8mb4_unicode_ci database the tables these migrations created then fail every JOIN/compare with the base
// tables ("Illegal mix of collations", 1267). The released migrations stay as they are (checksums); this converts the
// tables they created to the database collation. SQLite has no collations to fix.
// R47 (review round 17, low 5): the target is the collation of the core tables these are joined with (customers,
// products, orders), not the database default, which may have changed since install (ALTER DATABASE, a restore into
// another schema). Only a table that differs is converted; CONVERT TO copies it and blocks writes to it meanwhile
// (LOCK=SHARED keeps reads going), which for these small state tables takes a moment (see the CHANGELOG upgrade note).
return new class {
    private const TABLES = [
        'email_suppressions',        // 20261007110000_abandoned_cart_recovery
        'stock_alert_state',         // 20261008090000_stock_alerts
        'stock_alert_digests',       // 20261008090000_stock_alerts
        'operational_incidents',     // 20261012090000_operational_incidents
        'operational_alert_state',   // 20261012090000_operational_incidents
        'operational_monitor_state', // 20261012090000_operational_incidents
        'csp_violations',            // 20261013120000_payment_monitoring
        'erp_sync_state',            // 20261017093000_catalog_stock_erp_retry
    ];

    public function up(PDO $db, bool $isSqlite): void {
        if ($isSqlite) return;
        $core = $db->query("SELECT DISTINCT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME IN ('customers', 'products', 'orders') AND TABLE_COLLATION IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        // Core tables that disagree, are missing or are not utf8mb4 (converting would change the charset) are left to the operator.
        if (count($core) !== 1 || !preg_match('/^utf8mb4_[A-Za-z0-9_]+$/', (string)$core[0])) return;
        $collation = (string)$core[0];
        $current = $db->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $columns = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> ?');
        foreach (self::TABLES as $table) {
            $current->execute([$table]);
            $tableCollation = $current->fetchColumn();
            if ($tableCollation === false) continue;
            $columns->execute([$table, $collation]);
            if ((string)$tableCollation === $collation && (int)$columns->fetchColumn() === 0) continue;
            $db->exec("ALTER TABLE {$table} CONVERT TO CHARACTER SET utf8mb4 COLLATE {$collation}, LOCK=SHARED");
        }
        if (method_exists('Db', 'forgetSchema')) Db::forgetSchema($db);
    }
};
