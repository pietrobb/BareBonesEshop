<?php
/**
 * The ERP status pull polls 50 open orders per run. Ordered by updated_at, which only moves when
 * the ERP status changes, it polled the same 50 forever once more than 50 orders were open.
 * erp_status_checked_at is stamped on every poll and orders the pull, never-polled orders first.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'")->fetchAll(PDO::FETCH_COLUMN);
        if ($columns === []) return;
        if (!in_array('erp_status_checked_at', $columns, true)) {
            $db->exec('ALTER TABLE orders ADD COLUMN erp_status_checked_at ' . MigrationSql::datetime($isSqlite) . ' DEFAULT NULL');
        }
        MigrationSql::index($db, $isSqlite, 'orders', 'idx_ord_erp_checked', 'store_id, erp_status_checked_at');
    }
};
