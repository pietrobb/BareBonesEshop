<?php
/**
 * R33: a saved address keeps the buyer company's IČO (ico) and DIČ (dic) next to its name and IČ DPH (vat_id), so a
 * saved billing address prefills the whole company the invoice is issued to. Existing addresses keep NULL.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(customer_addresses)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_addresses'")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['ico', 'dic'] as $column) {
            if (!in_array($column, $columns, true)) $db->exec("ALTER TABLE customer_addresses ADD COLUMN {$column} VARCHAR(40) DEFAULT NULL");
        }
    }
};
