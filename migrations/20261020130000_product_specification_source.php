<?php
/**
 * Round 7: cli/extract-specs.php deleted every 'sk' specification before re-extracting, including the values an
 * admin entered on the product form and the imported ones. Specifications now carry their source: the script
 * replaces only its own ('extracted') rows. Rows that exist before this migration count as 'manual', so they are
 * kept, and extraction skips a criterion a product already has from another source.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(product_specifications)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_specifications'")->fetchAll(PDO::FETCH_COLUMN);
        if ($columns !== [] && !in_array('source', $columns, true)) {
            $db->exec('ALTER TABLE product_specifications ADD COLUMN source ' . ($isSqlite ? 'TEXT' : 'VARCHAR(16)') . " NOT NULL DEFAULT 'manual'");
        }
    }
};
