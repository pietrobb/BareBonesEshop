<?php
/**
 * Round 7: a customer could open a return for an order delivered years ago; nothing recorded when an order was
 * delivered. orders.delivered_at is set when the order becomes delivered, and the return window (rma_return_window_days)
 * counts from it. Orders delivered before this migration take their parcels' delivery time, else their last update
 * (never earlier than the real delivery, so the window is not shortened).
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'")->fetchAll(PDO::FETCH_COLUMN);
        if ($columns === [] || in_array('delivered_at', $columns, true)) return;
        $db->exec('ALTER TABLE orders ADD COLUMN delivered_at ' . ($isSqlite ? 'TEXT' : 'DATETIME') . ' DEFAULT NULL');
        $shipments = $isSqlite
            ? array_column($db->query('PRAGMA table_info(shipments)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipments'")->fetchAll(PDO::FETCH_COLUMN);
        $fromShipments = in_array('delivered_at', $shipments, true) && in_array('order_id', $shipments, true)
            ? '(SELECT MAX(s.delivered_at) FROM shipments s WHERE s.order_id = orders.id), '
            : '';
        $db->exec("UPDATE orders SET delivered_at = COALESCE({$fromShipments}updated_at, created_at) WHERE status = 'delivered'");
    }
};
