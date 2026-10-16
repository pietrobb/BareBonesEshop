<?php
/**
 * Import::registerSlug() wrote slug_registry rows without store_id, so they took the column default 'default':
 * translated product slugs of any other catalog never resolved (Products::bySlug filters by catalog) and 404'd.
 * Product rows take the catalog of the product they point to. Idempotent; other entities are left as they are.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        foreach (['slug_registry', 'products'] as $table) {
            $stmt = $db->prepare($isSqlite
                ? "SELECT 1 FROM sqlite_master WHERE type='table' AND name=?"
                : 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$table]);
            if (!$stmt->fetchColumn()) return;
        }
        $db->exec("UPDATE slug_registry SET store_id = (SELECT p.store_id FROM products p WHERE p.id = slug_registry.entity_id)
            WHERE entity = 'product'
              AND EXISTS (SELECT 1 FROM products p2 WHERE p2.id = slug_registry.entity_id AND p2.store_id != slug_registry.store_id)");
    }
};
