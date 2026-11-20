<?php

// R50 (review round 50, low): a shipping or payment method the merchant named had that one name in every language of
// the store. translations holds the merchant's name and description per further store language
// ({"cs": {"name": "…", "description": "…"}}); MethodLabels shows them.
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        foreach (['shipping_methods', 'payment_methods'] as $table) {
            $columns = $isSqlite
                ? array_column($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name')
                : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'")->fetchAll(PDO::FETCH_COLUMN);
            if ($columns !== [] && !in_array('translations', $columns, true)) $db->exec("ALTER TABLE {$table} ADD COLUMN translations " . MigrationSql::text($isSqlite));
        }
        if (method_exists('Db', 'forgetSchema')) Db::forgetSchema($db);
    }
};
