<?php
/** Auditable partial returns, exchanges, item refunds and credit notes. */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $autoinc = $isSqlite ? '' : ' AUTO_INCREMENT';
        $short = $isSqlite ? 'TEXT' : 'VARCHAR(191)';
        $datetime = $isSqlite ? 'TEXT' : 'DATETIME';
        $created = $isSqlite ? "TEXT NOT NULL DEFAULT (datetime('now'))" : 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP';
        $defaultEmpty = MigrationSql::defaultClause($isSqlite, '');

        foreach ([
            ['store_id', "{$short} NOT NULL DEFAULT 'default'"],
            ['credit_note_id', 'INTEGER DEFAULT NULL'],
        ] as [$column, $definition]) {
            $this->addColumnIfMissing($db, $isSqlite, 'rma_requests', $column, $definition);
        }
        foreach ([
            ['received_quantity', 'INTEGER NOT NULL DEFAULT 0'],
            ['refunded_quantity', 'INTEGER NOT NULL DEFAULT 0'],
            ['disposition', "{$short} NOT NULL DEFAULT 'pending'"],
        ] as [$column, $definition]) {
            $this->addColumnIfMissing($db, $isSqlite, 'rma_items', $column, $definition);
        }
        $this->addColumnIfMissing($db, $isSqlite, 'invoices', 'credit_snapshot', 'TEXT DEFAULT NULL');

        $db->exec("CREATE TABLE IF NOT EXISTS return_labels (
            id INTEGER PRIMARY KEY{$autoinc}, store_id {$short} NOT NULL DEFAULT 'default',
            rma_id INTEGER NOT NULL, provider {$short} NOT NULL, label_reference {$short} NOT NULL,
            tracking_number {$short} DEFAULT NULL, label_url TEXT DEFAULT NULL,
            status {$short} NOT NULL DEFAULT 'created', idempotency_key {$short} NOT NULL,
            created_at {$created}, UNIQUE(store_id, idempotency_key)
        )");
        $this->index($db, $isSqlite, 'return_labels', 'idx_return_labels_rma', 'store_id, rma_id, status');

        $db->exec("CREATE TABLE IF NOT EXISTS return_receipts (
            id INTEGER PRIMARY KEY{$autoinc}, store_id {$short} NOT NULL DEFAULT 'default',
            rma_id INTEGER NOT NULL, rma_item_id INTEGER NOT NULL, quantity INTEGER NOT NULL,
            disposition {$short} NOT NULL, note TEXT NOT NULL {$defaultEmpty}, idempotency_key {$short} NOT NULL,
            created_at {$created}, UNIQUE(store_id, idempotency_key, rma_item_id)
        )");
        $this->index($db, $isSqlite, 'return_receipts', 'idx_return_receipts_item', 'store_id, rma_item_id, disposition');

        $db->exec("CREATE TABLE IF NOT EXISTS return_exchanges (
            id INTEGER PRIMARY KEY{$autoinc}, store_id {$short} NOT NULL DEFAULT 'default',
            rma_id INTEGER NOT NULL, rma_item_id INTEGER NOT NULL, quantity INTEGER NOT NULL,
            replacement_product_id INTEGER NOT NULL, replacement_variant_id INTEGER DEFAULT NULL,
            replacement_order_id INTEGER DEFAULT NULL, status {$short} NOT NULL DEFAULT 'requested',
            idempotency_key {$short} NOT NULL, created_at {$created}, updated_at {$created},
            UNIQUE(store_id, idempotency_key)
        )");
        $this->index($db, $isSqlite, 'return_exchanges', 'idx_return_exchanges_rma', 'store_id, rma_id, status');

        $db->exec("CREATE TABLE IF NOT EXISTS order_refunds (
            id INTEGER PRIMARY KEY{$autoinc}, store_id {$short} NOT NULL DEFAULT 'default',
            order_id INTEGER NOT NULL, rma_id INTEGER NOT NULL, credit_note_id INTEGER NOT NULL,
            amount INTEGER NOT NULL, currency {$short} NOT NULL, status {$short} NOT NULL DEFAULT 'pending',
            payment_reference {$short} DEFAULT NULL, idempotency_key {$short} NOT NULL,
            created_at {$created}, updated_at {$created}, UNIQUE(store_id, idempotency_key)
        )");
        $this->index($db, $isSqlite, 'order_refunds', 'idx_order_refunds_order', 'store_id, order_id, status');

        $db->exec("CREATE TABLE IF NOT EXISTS credit_note_items (
            id INTEGER PRIMARY KEY{$autoinc}, credit_note_id INTEGER NOT NULL, refund_id INTEGER NOT NULL,
            order_item_id INTEGER NOT NULL, rma_item_id INTEGER NOT NULL, sku {$short} NOT NULL,
            name TEXT NOT NULL, quantity INTEGER NOT NULL, unit_gross INTEGER NOT NULL,
            gross INTEGER NOT NULL, net INTEGER NOT NULL, vat INTEGER NOT NULL,
            rate_basis_points INTEGER NOT NULL, UNIQUE(credit_note_id, rma_item_id)
        )");
        $this->index($db, $isSqlite, 'credit_note_items', 'idx_credit_note_items_order_item', 'order_item_id');
    }

    private function tableExists(PDO $db, bool $isSqlite, string $table): bool {
        $stmt = $isSqlite
            ? $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?")
            : $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    private function addColumnIfMissing(PDO $db, bool $isSqlite, string $table, string $column, string $definition): void {
        if (!$this->tableExists($db, $isSqlite, $table)) return;
        if ($isSqlite) {
            foreach ($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? '') === $column) return;
            }
        } else {
            $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
            $stmt->execute([$table, $column]);
            if ($stmt->fetchColumn()) return;
        }
        $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    private function index(PDO $db, bool $isSqlite, string $table, string $name, string $columns): void {
        $stmt = $isSqlite
            ? $db->prepare("SELECT 1 FROM sqlite_master WHERE type='index' AND name=?")
            : $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
        $stmt->execute($isSqlite ? [$name] : [$table, $name]);
        if (!$stmt->fetchColumn()) $db->exec("CREATE INDEX {$name} ON {$table}({$columns})");
    }
};
