<?php
/**
 * Reusable digital commerce primitives: assets, entitlements, checkout
 * bindings, order consent evidence, notification deduplication and variant
 * availability.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $autoinc = $isSqlite ? '' : ' AUTO_INCREMENT';
        $short = $isSqlite ? 'TEXT' : 'VARCHAR(191)';
        $token = $isSqlite ? 'TEXT' : 'CHAR(64)';
        $datetime = $isSqlite ? 'TEXT' : 'DATETIME';
        $created = $isSqlite ? "TEXT DEFAULT (datetime('now'))" : 'DATETIME DEFAULT CURRENT_TIMESTAMP';

        $db->exec("CREATE TABLE IF NOT EXISTS digital_assets (
            id INTEGER PRIMARY KEY{$autoinc},
            sku {$short} NOT NULL,
            asset_key {$short} NOT NULL DEFAULT 'default',
            label {$short} NOT NULL DEFAULT '',
            storage_path TEXT NOT NULL,
            download_name {$short} NOT NULL DEFAULT '',
            content_type {$short} NOT NULL DEFAULT 'application/octet-stream',
            download_limit INTEGER DEFAULT 5,
            expiry_seconds INTEGER DEFAULT 604800,
            active INTEGER NOT NULL DEFAULT 1,
            created_at {$created},
            updated_at {$created},
            UNIQUE(sku, asset_key)
        )");
        $this->addIndexIfMissing($db, $isSqlite, 'digital_assets', 'idx_digital_assets_sku_active', 'sku, active');

        $db->exec("CREATE TABLE IF NOT EXISTS digital_entitlements (
            id INTEGER PRIMARY KEY{$autoinc},
            order_id INTEGER NOT NULL,
            order_item_id INTEGER NOT NULL,
            asset_id INTEGER NOT NULL,
            token_hash {$token} NOT NULL UNIQUE,
            status {$short} NOT NULL DEFAULT 'active',
            download_count INTEGER NOT NULL DEFAULT 0,
            download_limit INTEGER DEFAULT NULL,
            expires_at INTEGER DEFAULT NULL,
            delivered_at {$datetime} DEFAULT NULL,
            revoked_at {$datetime} DEFAULT NULL,
            revoked_reason TEXT DEFAULT NULL,
            created_at {$created},
            updated_at {$created},
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
            FOREIGN KEY (asset_id) REFERENCES digital_assets(id)
        )");
        $this->addIndexIfMissing($db, $isSqlite, 'digital_entitlements', 'idx_digital_entitlements_order', 'order_id');
        $this->addIndexIfMissing($db, $isSqlite, 'digital_entitlements', 'idx_digital_entitlements_item_asset', 'order_item_id, asset_id, status');
        $this->addIndexIfMissing($db, $isSqlite, 'digital_entitlements', 'idx_digital_entitlements_status_expiry', 'status, expires_at');

        $db->exec("CREATE TABLE IF NOT EXISTS checkout_sessions (
            id INTEGER PRIMARY KEY{$autoinc},
            order_id INTEGER NOT NULL,
            provider {$short} NOT NULL,
            session_id {$short} NOT NULL,
            browser_session_hash {$token} DEFAULT NULL,
            status {$short} NOT NULL DEFAULT 'pending',
            created_at {$created},
            updated_at {$created},
            UNIQUE(provider, session_id),
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        )");
        $this->addIndexIfMissing($db, $isSqlite, 'checkout_sessions', 'idx_checkout_sessions_order', 'order_id, provider');

        $db->exec("CREATE TABLE IF NOT EXISTS order_consents (
            id INTEGER PRIMARY KEY{$autoinc},
            order_id INTEGER NOT NULL,
            consent_key {$short} NOT NULL,
            version {$short} NOT NULL,
            consent_text TEXT NOT NULL,
            accepted INTEGER NOT NULL DEFAULT 1,
            ip_hash {$token} NOT NULL DEFAULT '',
            user_agent TEXT NOT NULL,
            created_at {$created},
            UNIQUE(order_id, consent_key),
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        )");
        $this->addIndexIfMissing($db, $isSqlite, 'order_consents', 'idx_order_consents_order', 'order_id');

        $db->exec("CREATE TABLE IF NOT EXISTS merchant_notifications (
            id INTEGER PRIMARY KEY{$autoinc},
            order_id INTEGER NOT NULL,
            event_key {$short} NOT NULL,
            recipient {$short} NOT NULL,
            status {$short} NOT NULL DEFAULT 'pending',
            error TEXT DEFAULT NULL,
            sent_at {$datetime} DEFAULT NULL,
            created_at {$created},
            updated_at {$created},
            UNIQUE(order_id, event_key, recipient),
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        )");
        $this->addIndexIfMissing($db, $isSqlite, 'merchant_notifications', 'idx_merchant_notifications_order', 'order_id, event_key');

        $this->addColumnIfMissing($db, $isSqlite, 'variants', 'availability', "{$short} NOT NULL DEFAULT 'active'");
        $this->addIndexIfMissing($db, $isSqlite, 'variants', 'idx_variants_availability', 'product_id, availability');
    }

    private function addIndexIfMissing(PDO $db, bool $isSqlite, string $table, string $index, string $columns): void {
        if ($isSqlite) {
            $exists = false;
            foreach ($db->query("PRAGMA index_list({$table})")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? '') === $index) { $exists = true; break; }
            }
        } else {
            $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
            $stmt->execute([$table, $index]);
            $exists = (int)$stmt->fetchColumn() > 0;
        }
        if (!$exists) $db->exec("CREATE INDEX {$index} ON {$table}({$columns})");
    }

    private function addColumnIfMissing(PDO $db, bool $isSqlite, string $table, string $column, string $definition): void {
        $exists = false;
        if ($isSqlite) {
            foreach ($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? '') === $column) { $exists = true; break; }
            }
        } else {
            $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);
            $exists = (int)$stmt->fetchColumn() > 0;
        }
        if (!$exists) $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
};
