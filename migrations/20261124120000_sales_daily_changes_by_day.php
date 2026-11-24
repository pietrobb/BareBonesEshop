<?php

// R58 (review round 23, medium S2): SalesDaily reads the logged changes of the daily sales oldest day first, through
// (store_id, created_at, id): each request starts at the oldest change left and builds the days it reads. Was: every
// request read the store's changes again from id 0 through (store_id, id), and on MariaDB a page near the end of a large
// backlog took 64-147 ms (700 000 changes: 3.3-4.8 s per report request, 20-80 changes built). (store_id, id) is no
// longer read; it is dropped.
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        MigrationSql::index($db, $isSqlite, 'sales_daily_changes', 'idx_sales_daily_changes_created', 'store_id, created_at, id');
        if ($isSqlite) {
            $db->exec('DROP INDEX IF EXISTS idx_sales_daily_changes_store');
            return;
        }
        $stmt = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->execute(['sales_daily_changes', 'idx_sales_daily_changes_store']);
        if ($stmt->fetchColumn()) $db->exec('DROP INDEX idx_sales_daily_changes_store ON sales_daily_changes');
    }
};
