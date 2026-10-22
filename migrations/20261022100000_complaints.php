<?php
/**
 * Round 8: complaints (reklamácie) — a claim that goods are defective (Directive (EU) 2019/771, 24-month liability).
 * Separate from returns (RMA) and from withdrawal: own record, own status flow and a written decision.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $short = MigrationSql::short($isSqlite);
        $text = MigrationSql::text($isSqlite);
        $date = MigrationSql::datetime($isSqlite);
        $id = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $db->exec("CREATE TABLE IF NOT EXISTS complaints (
            id {$id},
            store_id {$short} NOT NULL,
            complaint_number {$short} NOT NULL,
            order_id INTEGER NOT NULL,
            order_item_id INTEGER NOT NULL,
            order_number {$short} NOT NULL,
            customer_name {$short} NOT NULL,
            customer_email {$short} NOT NULL,
            item_name {$short} NOT NULL,
            defect {$text},
            remedy {$short} NOT NULL,
            status {$short} NOT NULL DEFAULT 'received',
            received_at {$date} NOT NULL,
            decided_at {$date} NULL,
            decision_note {$text},
            confirmation_status {$short} NOT NULL DEFAULT 'pending'
        )" . ($isSqlite ? '' : ' ENGINE=InnoDB'));
        MigrationSql::index($db, $isSqlite, 'complaints', 'idx_complaints_store_status', 'store_id, status');
        MigrationSql::index($db, $isSqlite, 'complaints', 'idx_complaints_order', 'order_id');
    }
};
