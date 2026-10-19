<?php
/**
 * Round 5: carrier tracking on admin shipments and an ordered event stream.
 *
 * - shipments.tracking_status / delivered_at: carrier webhooks update the shipments the admin ships
 *   (they only looked at shipping_labels, so every real parcel ended as unknown_awb).
 * - event_outbox.seq from the event_sequence counter: a number that only grows, also after
 *   retention deleted older events, so webhook receivers can order the events of one subject.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $short = $isSqlite ? 'TEXT' : 'VARCHAR(191)';
        $datetime = $isSqlite ? 'TEXT' : 'DATETIME';
        $this->addColumn($db, $isSqlite, 'shipments', 'tracking_status', "{$short} DEFAULT NULL");
        $this->addColumn($db, $isSqlite, 'shipments', 'delivered_at', "{$datetime} DEFAULT NULL");
        $this->addColumn($db, $isSqlite, 'event_outbox', 'seq', $isSqlite ? 'INTEGER DEFAULT NULL' : 'BIGINT DEFAULT NULL');
        $db->exec($isSqlite
            ? 'CREATE TABLE IF NOT EXISTS event_sequence (id INTEGER PRIMARY KEY AUTOINCREMENT)'
            : 'CREATE TABLE IF NOT EXISTS event_sequence (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY)');
    }

    private function addColumn(PDO $db, bool $isSqlite, string $table, string $column, string $definition): void {
        $columns = $isSqlite
            ? array_column($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'")->fetchAll(PDO::FETCH_COLUMN);
        if ($columns !== [] && !in_array($column, $columns, true)) $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
};
