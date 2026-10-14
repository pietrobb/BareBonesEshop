<?php
/**
 * A07.3: one-time TOTP codes and no raw session IDs at rest.
 *  - admin_accounts.totp_last_counter: the highest accepted TOTP time step; a code is valid once.
 *  - wishlist_items.owner_key 's:<id>', abandoned_carts.session_id and cookie_consent.session_id
 *    switch to SHA-256(session_id) (Security::sessionKey), so a DB leak cannot hijack live sessions.
 *    Already-hashed values (64 hex chars) and archived 'order:<id>' carts are left as they are.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $this->addColumnIfMissing($db, $isSqlite, 'admin_accounts', 'totp_last_counter', $isSqlite ? 'INTEGER NULL' : 'BIGINT NULL');

        if ($this->tableExists($db, $isSqlite, 'wishlist_items')) {
            $rows = $db->query("SELECT id, owner_key FROM wishlist_items WHERE owner_key LIKE 's:%'")->fetchAll(PDO::FETCH_KEY_PAIR);
            $update = $db->prepare('UPDATE wishlist_items SET owner_key = :key WHERE id = :id');
            foreach ($rows as $id => $key) {
                $raw = substr((string)$key, 2);
                if ($raw === 'anon' || $this->hashed($raw)) continue;
                $update->execute([':key' => 's:' . hash('sha256', $raw), ':id' => $id]);
            }
        }
        foreach (['abandoned_carts', 'cookie_consent'] as $table) {
            if (!$this->tableExists($db, $isSqlite, $table)) continue;
            $rows = $db->query("SELECT id, session_id FROM {$table} WHERE session_id IS NOT NULL AND session_id <> ''")->fetchAll(PDO::FETCH_KEY_PAIR);
            $update = $db->prepare("UPDATE {$table} SET session_id = :sid WHERE id = :id");
            foreach ($rows as $id => $sid) {
                $sid = (string)$sid;
                if ($this->hashed($sid) || str_starts_with($sid, 'order:')) continue;
                $update->execute([':sid' => hash('sha256', $sid), ':id' => $id]);
            }
        }
    }

    private function tableExists(PDO $db, bool $isSqlite, string $table): bool {
        $stmt = $db->prepare($isSqlite
            ? "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?"
            : 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function hashed(string $value): bool {
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    private function addColumnIfMissing(PDO $db, bool $isSqlite, string $table, string $col, string $def): void {
        if ($isSqlite) {
            $exists = in_array($col, array_column($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
        } else {
            $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $col]);
            $exists = (int)$stmt->fetchColumn() > 0;
        }
        if (!$exists) $db->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
    }
};
