<?php
/** A12.8: carrier tracking link of a shipment, entered in the admin order detail (NULL = no link). */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(shipments)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipments'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('tracking_url', $columns, true)) $db->exec('ALTER TABLE shipments ADD COLUMN tracking_url TEXT DEFAULT NULL');
    }
};
