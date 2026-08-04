<?php
/** Typed product relations and data-only CMS section definitions. */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        if ($this->tableExists($db, $isSqlite, 'product_related_products')
            && !$this->columnExists($db, $isSqlite, 'product_related_products', 'relation_type')) {
            if ($isSqlite) {
                $db->exec("CREATE TABLE product_related_products_v2 (
                    product_id INTEGER NOT NULL,
                    related_product_id INTEGER NOT NULL,
                    relation_type TEXT NOT NULL DEFAULT 'related',
                    sort_order INTEGER NOT NULL DEFAULT 0,
                    PRIMARY KEY (product_id, related_product_id, relation_type),
                    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
                    FOREIGN KEY (related_product_id) REFERENCES products(id) ON DELETE CASCADE
                )");
                $db->exec("INSERT INTO product_related_products_v2
                    (product_id, related_product_id, relation_type, sort_order)
                    SELECT product_id, related_product_id, 'related', sort_order FROM product_related_products");
                $db->exec('DROP TABLE product_related_products');
                $db->exec('ALTER TABLE product_related_products_v2 RENAME TO product_related_products');
            } else {
                $db->exec("ALTER TABLE product_related_products ADD COLUMN relation_type VARCHAR(80) NOT NULL DEFAULT 'related'");
                $db->exec('ALTER TABLE product_related_products DROP PRIMARY KEY, ADD PRIMARY KEY (product_id, related_product_id, relation_type)');
            }
        }
        if ($this->tableExists($db, $isSqlite, 'product_related_products')) {
            MigrationSql::index($db, $isSqlite, 'product_related_products', 'idx_product_relation_type_sort', 'product_id, relation_type, sort_order');
        }

        if ($this->tableExists($db, $isSqlite, 'pages') && !$this->columnExists($db, $isSqlite, 'pages', 'sections')) {
            $definition = $isSqlite ? "TEXT NOT NULL DEFAULT '[]'" : "LONGTEXT NOT NULL DEFAULT ('[]')";
            $db->exec("ALTER TABLE pages ADD COLUMN sections {$definition}");
        }
    }

    private function tableExists(PDO $db, bool $isSqlite, string $table): bool {
        $stmt = $isSqlite
            ? $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?")
            : $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    private function columnExists(PDO $db, bool $isSqlite, string $table, string $column): bool {
        if ($isSqlite) {
            foreach ($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? '') === $column) return true;
            }
            return false;
        }
        $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    }
};
