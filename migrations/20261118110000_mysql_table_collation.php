<?php

// R46 (#11): on MySQL 8 `DEFAULT CHARSET=utf8mb4` without COLLATE gives utf8mb4_0900_ai_ci, not the database collation.
// On a utf8mb4_unicode_ci database the tables these migrations created then fail every JOIN/compare with the base
// tables ("Illegal mix of collations", 1267). The released migrations stay as they are (checksums); this converts the
// tables they created to the database collation. SQLite has no collations to fix.
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
        $schema = $db->query('SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()')->fetch(PDO::FETCH_NUM);
        [$charset, $collation] = [(string)($schema[0] ?? ''), (string)($schema[1] ?? '')];
        // A database that is not utf8mb4 (or an odd collation name) is left to the operator: converting would change the charset.
        if ($charset !== 'utf8mb4' || !preg_match('/^utf8mb4_[A-Za-z0-9_]+$/', $collation)) return;
        $current = $db->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $columns = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> ?');
        foreach (self::TABLES as $table) {
            $current->execute([$table]);
            $tableCollation = $current->fetchColumn();
            if ($tableCollation === false) continue;
            $columns->execute([$table, $collation]);
            if ((string)$tableCollation === $collation && (int)$columns->fetchColumn() === 0) continue;
            $db->exec("ALTER TABLE {$table} CONVERT TO CHARACTER SET utf8mb4 COLLATE {$collation}");
        }
        if (method_exists('Db', 'forgetSchema')) Db::forgetSchema($db);
    }
};
