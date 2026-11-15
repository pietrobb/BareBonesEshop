<?php

// An invoiced order is counted by its invoice. Keep the open-order index sparse on existing installations too.
return new class {
    public function up(PDO $db, bool $isSqlite): void { MigrationSql::index($db, $isSqlite, 'orders', 'idx_orders_oss_open_cover', 'store_id, oss_net, created_at, status, currency');
        $db->exec("UPDATE orders SET oss_net = 0 WHERE (oss_net IS NULL OR oss_net <> 0)
            AND (status IN ('cancelled', 'refunded', 'failed') OR EXISTS (
                SELECT 1 FROM invoices i WHERE i.order_id = orders.id AND i.type = 'invoice' AND i.status = 'issued'
            ))");
    }
};
