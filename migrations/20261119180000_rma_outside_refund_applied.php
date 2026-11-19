<?php

// R49 (capability review of finding 3): money of an order refunded outside its returns is a balance. A return closed
// because of it records how much of it the return used up (Rma::closeWithoutPayout()), so the next return of the order
// does not count the same money again.
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(rma_requests)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rma_requests'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('outside_refund_applied', $columns, true)) $db->exec('ALTER TABLE rma_requests ADD COLUMN outside_refund_applied INTEGER NOT NULL DEFAULT 0');
        if (method_exists('Db', 'forgetSchema')) Db::forgetSchema($db);
    }
};
