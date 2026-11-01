<?php
/**
 * Round 11 (H9 carrier labels): a label belongs to one parcel (shipment) of the order and keeps the cash-on-delivery
 * amount printed on it, taken from the order when the label was created. NULL shipment_id = an order-level label of
 * an older version; cod_amount 0 = prepaid parcel.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(shipping_labels)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipping_labels'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('shipment_id', $columns, true)) {
            $db->exec('ALTER TABLE shipping_labels ADD COLUMN shipment_id INTEGER DEFAULT NULL');
        }
        if (!in_array('cod_amount', $columns, true)) {
            $db->exec('ALTER TABLE shipping_labels ADD COLUMN cod_amount INTEGER NOT NULL DEFAULT 0');
        }
        MigrationSql::index($db, $isSqlite, 'shipping_labels', 'idx_slabel_shipment', 'shipment_id');
    }
};
