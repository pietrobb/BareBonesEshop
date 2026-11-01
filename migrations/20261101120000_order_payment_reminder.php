<?php
/**
 * Round 11 (H6 bank transfer): an unpaid transfer order is reminded once before its due date. The time the reminder was
 * queued (UTC) keeps the sweep from sending it twice; NULL = not reminded.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('payment_reminder_sent_at', $columns, true)) {
            $db->exec('ALTER TABLE orders ADD COLUMN payment_reminder_sent_at ' . MigrationSql::datetime($isSqlite) . ' DEFAULT NULL');
        }
    }
};
