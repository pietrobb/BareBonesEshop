<?php
/**
 * Round 11 (H9 cash on delivery per parcel): the cash on delivery a parcel collects, recorded when it is shipped by hand
 * or labelled, so a parcel booked in the carrier's portal still says whether it carries the order total.
 * NULL = not decided yet (planned parcel), 0 = prepaid parcel.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(shipments)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipments'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('cod_amount', $columns, true)) $db->exec('ALTER TABLE shipments ADD COLUMN cod_amount INTEGER DEFAULT NULL');
    }
};
