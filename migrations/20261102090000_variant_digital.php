<?php
/**
 * Mixed-format products: a variant can be digital content (an e-book next to its paperback and hardcover) while the
 * product stays physical. 1 = the variant is delivered as a download (no stock, no shipping), 0 = as the product type says.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(variants)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'variants'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('digital', $columns, true)) $db->exec('ALTER TABLE variants ADD COLUMN digital INTEGER NOT NULL DEFAULT 0');
    }
};
