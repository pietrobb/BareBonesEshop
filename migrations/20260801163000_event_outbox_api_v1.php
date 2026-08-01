<?php
/**
 * Transactional commerce event outbox and machine API credentials/idempotency.
 * Existing commerce rows are unchanged; secrets remain deployment configuration.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $autoinc = $isSqlite ? '' : ' AUTO_INCREMENT';
        $short = $isSqlite ? 'TEXT' : 'VARCHAR(191)';
        $id = $isSqlite ? 'TEXT' : 'CHAR(32)';
        $hash = $isSqlite ? 'TEXT' : 'CHAR(64)';
        $datetime = $isSqlite ? 'TEXT' : 'DATETIME';
        $created = $isSqlite ? "TEXT NOT NULL DEFAULT (datetime('now'))" : 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP';

        $db->exec("CREATE TABLE IF NOT EXISTS event_outbox (
            id {$id} PRIMARY KEY,
            store_id {$short} NOT NULL,
            channel_id {$short} NOT NULL,
            event_type {$short} NOT NULL,
            aggregate_type {$short} NOT NULL,
            aggregate_id {$short} NOT NULL,
            event_version INTEGER NOT NULL DEFAULT 1,
            payload TEXT NOT NULL,
            status {$short} NOT NULL DEFAULT 'pending',
            attempts INTEGER NOT NULL DEFAULT 0,
            available_at {$datetime} NOT NULL,
            last_error TEXT DEFAULT NULL,
            occurred_at {$created},
            updated_at {$created}
        )");
        $this->index($db, $isSqlite, 'event_outbox', 'idx_event_outbox_pending', 'status, available_at, occurred_at');
        $this->index($db, $isSqlite, 'event_outbox', 'idx_event_outbox_store_event', 'store_id, event_type, occurred_at');

        $db->exec("CREATE TABLE IF NOT EXISTS webhook_deliveries (
            id INTEGER PRIMARY KEY{$autoinc},
            event_id {$id} NOT NULL,
            endpoint_code {$short} NOT NULL,
            attempt INTEGER NOT NULL,
            status {$short} NOT NULL,
            response_code INTEGER DEFAULT NULL,
            error TEXT DEFAULT NULL,
            attempted_at {$created},
            UNIQUE(event_id, endpoint_code, attempt),
            FOREIGN KEY (event_id) REFERENCES event_outbox(id) ON DELETE CASCADE
        )");
        $this->index($db, $isSqlite, 'webhook_deliveries', 'idx_webhook_deliveries_event', 'event_id, endpoint_code, status');

        $db->exec("CREATE TABLE IF NOT EXISTS api_clients (
            id INTEGER PRIMARY KEY{$autoinc},
            key_id {$short} NOT NULL UNIQUE,
            token_hash {$hash} NOT NULL UNIQUE,
            store_id {$short} NOT NULL,
            name {$short} NOT NULL,
            scopes TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            expires_at {$datetime} DEFAULT NULL,
            last_used_at {$datetime} DEFAULT NULL,
            created_at {$created}
        )");
        $this->index($db, $isSqlite, 'api_clients', 'idx_api_clients_store_active', 'store_id, active');

        $db->exec("CREATE TABLE IF NOT EXISTS api_idempotency (
            id INTEGER PRIMARY KEY{$autoinc},
            store_id {$short} NOT NULL,
            client_id INTEGER NOT NULL,
            idempotency_key {$short} NOT NULL,
            request_hash {$hash} NOT NULL,
            response_status INTEGER NOT NULL,
            response_body TEXT NOT NULL,
            created_at {$created},
            UNIQUE(store_id, client_id, idempotency_key),
            FOREIGN KEY (client_id) REFERENCES api_clients(id) ON DELETE CASCADE
        )");
        $this->index($db, $isSqlite, 'api_idempotency', 'idx_api_idempotency_created', 'created_at');
    }

    private function index(PDO $db, bool $isSqlite, string $table, string $name, string $columns): void {
        if ($isSqlite) {
            $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='index' AND name=?");
            $stmt->execute([$name]);
        } else {
            $stmt = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
            $stmt->execute([$table, $name]);
        }
        if (!$stmt->fetchColumn()) $db->exec("CREATE INDEX {$name} ON {$table}({$columns})");
    }
};
