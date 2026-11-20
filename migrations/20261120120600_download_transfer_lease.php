<?php

// R50 (capability review run 620): digital_entitlements.transfer_lease marks the transfer of the last counted download
// (or of its last free resume) as still running. A free resume needs it cleared, so a "Range: bytes=1-" next to a
// counted download that is still streaming is no free second copy (DigitalDelivery::openTransfer()/endTransfer()).
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query("PRAGMA table_info(digital_entitlements)")->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'digital_entitlements'")->fetchAll(PDO::FETCH_COLUMN);
        if ($columns !== [] && !in_array('transfer_lease', $columns, true)) {
            $db->exec('ALTER TABLE digital_entitlements ADD COLUMN transfer_lease ' . ($isSqlite ? 'TEXT' : 'VARCHAR(32) NULL'));
        }
        if (method_exists('Db', 'forgetSchema')) Db::forgetSchema($db);
    }
};
