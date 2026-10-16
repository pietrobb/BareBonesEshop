<?php
/**
 * Code review M7: a webhook claim is 'processing' until its handler finished and only then 'done', so a process that
 * dies mid-event (fatal error, timeout) leaves a stale claim that a provider retry can take over after the lease.
 * Rows written before this migration were consumed claims and stay 'done'.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(processed_events)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'processed_events'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('state', $columns, true)) {
            $db->exec('ALTER TABLE processed_events ADD COLUMN state ' . ($isSqlite ? 'TEXT' : 'VARCHAR(16)') . " NOT NULL DEFAULT 'done'");
        }
        if (!in_array('claimed_at', $columns, true)) {
            $db->exec('ALTER TABLE processed_events ADD COLUMN claimed_at ' . ($isSqlite ? 'INTEGER' : 'BIGINT') . ' DEFAULT NULL');
        }
    }
};
