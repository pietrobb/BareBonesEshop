<?php
/**
 * R21: a feed sync recorded only "partial: N errors"; which items it refused and why was lost with the run (a scheduled
 * sync showed the admin nothing else). feeds.last_errors keeps the last sync's item errors (JSON list, capped).
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(feeds)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'feeds'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('last_errors', $columns, true)) {
            $db->exec('ALTER TABLE feeds ADD COLUMN last_errors ' . MigrationSql::text($isSqlite) . ' NULL');
        }
    }
};
