<?php
/**
 * GDPR audit trail per store: admin GDPR page lists only the active store's events.
 * Existing rows predate multi-store GDPR handling and stay under the literal `default` store.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $short = MigrationSql::short($isSqlite);
        if ($isSqlite) {
            $exists = false;
            foreach ($db->query('PRAGMA table_info(gdpr_events)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? '') === 'store_id') $exists = true;
            }
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gdpr_events' AND COLUMN_NAME = 'store_id'");
            $stmt->execute();
            $exists = (int)$stmt->fetchColumn() > 0;
        }
        if (!$exists) {
            $db->exec("ALTER TABLE gdpr_events ADD COLUMN store_id {$short} NOT NULL DEFAULT 'default'");
            $db->exec('CREATE INDEX idx_gdpr_events_store_created ON gdpr_events(store_id, created_at)');
        }
    }
};
