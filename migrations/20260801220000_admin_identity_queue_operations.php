<?php
/** Production admin identity, durable queues, and operational correlation metadata. */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $autoinc = $isSqlite ? '' : ' AUTO_INCREMENT';
        $short = $isSqlite ? 'TEXT' : 'VARCHAR(191)';
        $datetime = $isSqlite ? 'TEXT' : 'DATETIME';
        $created = $isSqlite ? "TEXT NOT NULL DEFAULT (datetime('now'))" : 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP';
        $defaultEmpty = MigrationSql::defaultClause($isSqlite, '');
        $defaultArray = MigrationSql::defaultClause($isSqlite, '[]');

        $db->exec("CREATE TABLE IF NOT EXISTS admin_accounts (
            id INTEGER PRIMARY KEY{$autoinc}, username {$short} NOT NULL UNIQUE,
            password_hash TEXT NOT NULL, role {$short} NOT NULL DEFAULT 'viewer',
            status {$short} NOT NULL DEFAULT 'active', mfa_required INTEGER NOT NULL DEFAULT 1,
            totp_secret_encrypted TEXT DEFAULT NULL, failed_attempts INTEGER NOT NULL DEFAULT 0,
            locked_until {$datetime} DEFAULT NULL, last_login_at {$datetime} DEFAULT NULL,
            created_at {$created}, updated_at {$created}
        )");
        $this->index($db, $isSqlite, 'admin_accounts', 'idx_admin_accounts_status', 'status, username');

        $db->exec("CREATE TABLE IF NOT EXISTS admin_recovery_codes (
            id INTEGER PRIMARY KEY{$autoinc}, account_id INTEGER NOT NULL,
            code_hash {$short} NOT NULL UNIQUE, used_at {$datetime} DEFAULT NULL, created_at {$created}
        )");
        $this->index($db, $isSqlite, 'admin_recovery_codes', 'idx_admin_recovery_account', 'account_id, used_at');

        $db->exec("CREATE TABLE IF NOT EXISTS admin_store_permissions (
            id INTEGER PRIMARY KEY{$autoinc}, account_id INTEGER NOT NULL,
            store_id {$short} NOT NULL DEFAULT 'default', permissions TEXT NOT NULL {$defaultArray},
            created_at {$created}, updated_at {$created}, UNIQUE(account_id, store_id)
        )");
        $this->index($db, $isSqlite, 'admin_store_permissions', 'idx_admin_store_permissions_store', 'store_id, account_id');

        $db->exec("CREATE TABLE IF NOT EXISTS admin_devices (
            id INTEGER PRIMARY KEY{$autoinc}, account_id INTEGER NOT NULL, fingerprint {$short} NOT NULL,
            label {$short} NOT NULL DEFAULT '', user_agent TEXT NOT NULL {$defaultEmpty}, last_ip {$short} NOT NULL DEFAULT '',
            last_seen_at {$datetime} NOT NULL, revoked_at {$datetime} DEFAULT NULL, created_at {$created},
            UNIQUE(account_id, fingerprint)
        )");
        $this->index($db, $isSqlite, 'admin_devices', 'idx_admin_devices_account', 'account_id, revoked_at, last_seen_at');

        $db->exec("CREATE TABLE IF NOT EXISTS admin_sessions (
            id {$short} PRIMARY KEY, account_id INTEGER NOT NULL, device_id INTEGER NOT NULL,
            token_hash {$short} NOT NULL UNIQUE, ip_address {$short} NOT NULL DEFAULT '',
            user_agent TEXT NOT NULL {$defaultEmpty}, last_seen_at {$datetime} NOT NULL,
            expires_at {$datetime} NOT NULL, revoked_at {$datetime} DEFAULT NULL, created_at {$created}
        )");
        $this->index($db, $isSqlite, 'admin_sessions', 'idx_admin_sessions_account', 'account_id, revoked_at, expires_at');

        $db->exec("CREATE TABLE IF NOT EXISTS queue_jobs (
            id {$short} PRIMARY KEY, store_id {$short} NOT NULL DEFAULT 'default',
            channel_id {$short} NOT NULL DEFAULT 'storefront', type {$short} NOT NULL,
            priority INTEGER NOT NULL DEFAULT 5, payload TEXT NOT NULL, state {$short} NOT NULL DEFAULT 'pending',
            attempts INTEGER NOT NULL DEFAULT 0, max_attempts INTEGER NOT NULL DEFAULT 3,
            available_at {$datetime} NOT NULL, claimed_at {$datetime} DEFAULT NULL,
            claim_token {$short} DEFAULT NULL, last_error TEXT DEFAULT NULL,
            correlation_id {$short} NOT NULL, created_at {$created}, updated_at {$created}, completed_at {$datetime} DEFAULT NULL
        )");
        $this->index($db, $isSqlite, 'queue_jobs', 'idx_queue_jobs_claim', 'state, available_at, priority, created_at');
        $this->index($db, $isSqlite, 'queue_jobs', 'idx_queue_jobs_store', 'store_id, state, updated_at');

        foreach ([
            ['email_log', 'store_id', "{$short} NOT NULL DEFAULT 'default'"],
            ['email_log', 'correlation_id', "{$short} DEFAULT NULL"],
            ['event_outbox', 'correlation_id', "{$short} DEFAULT NULL"],
            ['payment_transactions', 'correlation_id', "{$short} DEFAULT NULL"],
            ['audit_log', 'store_id', "{$short} NOT NULL DEFAULT 'default'"],
            ['audit_log', 'correlation_id', "{$short} DEFAULT NULL"],
        ] as [$table, $column, $definition]) {
            $this->addColumnIfMissing($db, $isSqlite, $table, $column, $definition);
        }
        $this->index($db, $isSqlite, 'email_log', 'idx_email_log_operations', 'store_id, status, created_at');
        $this->index($db, $isSqlite, 'event_outbox', 'idx_event_outbox_operations', 'store_id, status, updated_at');
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
        if (!$this->tableExists($db, $isSqlite, $table)) return;
        $stmt = $isSqlite
            ? $db->prepare("SELECT 1 FROM sqlite_master WHERE type='index' AND name=?")
            : $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
        $stmt->execute($isSqlite ? [$name] : [$table, $name]);
        if (!$stmt->fetchColumn()) $db->exec("CREATE INDEX {$name} ON {$table}({$columns})");
    }
};
