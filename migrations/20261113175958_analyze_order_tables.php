<?php
/**
 * R53 (review round 53, low): the OSS migrations that follow (20261113175959, 20261113190000) read orders, their lines
 * and invoices row by row. On MySQL with statistics that lag behind a bulk import (order_items counted as empty), the
 * optimizer scanned the whole order_items table for every invoice and every open order: an update from 2.1.2 took
 * 2 minutes at 20 000 orders and 6-16 minutes at 100 000. Fresh statistics keep those reads on idx_oi_order.
 * Runs before them (also on an install that has them already: there it only refreshes the statistics).
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        if ($isSqlite) return;
        $tables = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('orders', 'order_items', 'invoices')")->fetchAll(PDO::FETCH_COLUMN);
        if ($tables !== []) $db->query('ANALYZE TABLE ' . implode(', ', array_map(static fn(string $t): string => '`' . $t . '`', $tables)))->fetchAll();
    }
};
