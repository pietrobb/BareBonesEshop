<?php
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $db->exec("CREATE TABLE IF NOT EXISTS product_related_products (
            product_id INTEGER NOT NULL,
            related_product_id INTEGER NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (product_id, related_product_id),
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY (related_product_id) REFERENCES products(id) ON DELETE CASCADE
        )");

        if ($isSqlite) {
            $db->exec('CREATE INDEX IF NOT EXISTS idx_product_related_sort ON product_related_products(product_id, sort_order)');
            return;
        }

        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->execute(['product_related_products', 'idx_product_related_sort']);
        if (!(int)$stmt->fetchColumn()) {
            $db->exec('CREATE INDEX idx_product_related_sort ON product_related_products(product_id, sort_order)');
        }
    }
};
