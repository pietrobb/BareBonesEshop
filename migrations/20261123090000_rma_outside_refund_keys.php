<?php

// R56 (review round 21, M3): a return closed because of money refunded outside the returns records, per VAT breakdown
// key, how much of that money it used up (Rma::closeWithoutPayout()). The next return of the order then takes the rest
// at its own rates (InvoiceService::outsideDeduction()) instead of the share already credited at other rates.
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(rma_requests)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rma_requests'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('outside_refund_keys', $columns, true)) $db->exec('ALTER TABLE rma_requests ADD COLUMN outside_refund_keys TEXT NULL');
        if (method_exists('Db', 'forgetSchema')) Db::forgetSchema($db);
    }
};
