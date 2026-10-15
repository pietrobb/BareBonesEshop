<?php
/**
 * Auto-parts support (TecDoc vehicles and OE numbers) is no longer part of the core. Its tables
 * are dropped when they hold no rows. A table that still holds data is left exactly as it is, so
 * no shop loses data silently; an extension can adopt it. product_specifications is generic and stays.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        if ($this->exists($db, $isSqlite, 'product_oe_numbers') && $this->isEmpty($db, 'product_oe_numbers')) {
            if ($isSqlite) {
                $db->exec('DROP TRIGGER IF EXISTS oe_ai');
                $db->exec('DROP TRIGGER IF EXISTS oe_ad');
                $db->exec('DROP TABLE IF EXISTS oe_fts');
            }
            $db->exec('DROP TABLE product_oe_numbers');
        }
        if ($this->exists($db, $isSqlite, 'product_vehicles') && $this->isEmpty($db, 'product_vehicles')) {
            $db->exec('DROP TABLE product_vehicles');
        }
    }

    private function exists(PDO $db, bool $isSqlite, string $table): bool {
        $stmt = $isSqlite
            ? $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?")
            : $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }

    private function isEmpty(PDO $db, string $table): bool {
        return $db->query("SELECT 1 FROM {$table} LIMIT 1")->fetchColumn() === false;
    }
};
