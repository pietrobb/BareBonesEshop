<?php
/** Signed payment webhooks whose order could not be resolved yet: persisted for bounded retry and admin alerting. */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $db->exec($isSqlite
            ? "CREATE TABLE IF NOT EXISTS payment_webhook_inbox (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                store_id TEXT NOT NULL DEFAULT 'default',
                provider TEXT NOT NULL,
                event_id TEXT NOT NULL,
                event_type TEXT NOT NULL,
                refs TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'retry',
                attempts INTEGER NOT NULL DEFAULT 0,
                last_error TEXT,
                correlation_id TEXT,
                resolved_store_id TEXT,
                created_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now')),
                UNIQUE (provider, event_id)
            )"
            : "CREATE TABLE IF NOT EXISTS payment_webhook_inbox (
                id INT AUTO_INCREMENT PRIMARY KEY,
                store_id VARCHAR(64) NOT NULL DEFAULT 'default',
                provider VARCHAR(40) NOT NULL,
                event_id VARCHAR(191) NOT NULL,
                event_type VARCHAR(80) NOT NULL,
                refs TEXT NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'retry',
                attempts INT NOT NULL DEFAULT 0,
                last_error VARCHAR(500) NULL,
                correlation_id VARCHAR(64) NULL,
                resolved_store_id VARCHAR(64) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_payment_webhook_inbox_event (provider, event_id),
                KEY idx_payment_webhook_inbox_store_status (store_id, status)
            ) ENGINE=InnoDB");
        if ($isSqlite) {
            $db->exec('CREATE INDEX IF NOT EXISTS idx_payment_webhook_inbox_store_status ON payment_webhook_inbox (store_id, status)');
        }
    }
};
