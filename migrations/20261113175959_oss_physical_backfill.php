<?php

// A prerequisite of the published R36 backfill: leave that file and its checksums untouched.
// Existing R36 installations already have totals and need no backfill; older installations bulk-fill physical
// invoices first, then the unchanged migration computes mixed digital supplies, credit notes and reversals.
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        if ($db->query($isSqlite ? "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'oss_threshold_totals'" : "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'oss_threshold_totals'")->fetchColumn() || !in_array('digital', $isSqlite ? array_column($db->query('PRAGMA table_info(order_items)')->fetchAll(PDO::FETCH_ASSOC), 'name') : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items'")->fetchAll(PDO::FETCH_COLUMN), true)) return;
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(invoices)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('order_id', $columns, true)) return;
        if (!in_array('oss_net', $columns, true)) $db->exec('ALTER TABLE invoices ADD COLUMN oss_net BIGINT DEFAULT NULL');
        $since = sprintf('%04d-01-01', (int)gmdate('Y') - 1);
        $db->prepare("UPDATE invoices SET oss_net = CASE WHEN vat_scenario IN ('oss', 'domestic_subthreshold') THEN total_net ELSE 0 END
            WHERE oss_net IS NULL AND status = 'issued' AND type = 'invoice' AND issued_at >= ?
              AND EXISTS (SELECT 1 FROM orders o WHERE o.id = invoices.order_id)
              AND NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = invoices.order_id AND oi.digital = 1)")->execute([$since]);
    }
};
