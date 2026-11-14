<?php
/**
 * R36 (medium 1): the 10 000 EUR OSS threshold read every invoice of two years on each checkout request (~5 s on MySQL
 * at 200 000 invoices). oss_threshold_totals holds the sum of invoices.oss_net per store, year and currency, added to as
 * documents are issued or cancelled (OssThreshold::record()/forget()); orders.oss_net what a placed order adds until
 * it is invoiced (OssThreshold::recordOrder()). Fills both for the current and the previous year. Indexes keep the
 * documents and orders still without a stored net (counted from their lines) cheap to find.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $db->exec($isSqlite
            ? "CREATE TABLE IF NOT EXISTS oss_threshold_totals (
                store_id TEXT NOT NULL,
                year INTEGER NOT NULL,
                currency TEXT NOT NULL,
                net INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (store_id, year, currency)
            )"
            : "CREATE TABLE IF NOT EXISTS oss_threshold_totals (
                store_id VARCHAR(64) NOT NULL,
                year INT NOT NULL,
                currency VARCHAR(8) NOT NULL,
                net BIGINT NOT NULL DEFAULT 0,
                PRIMARY KEY (store_id, year, currency)
            ) ENGINE=InnoDB");
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('oss_net', $columns, true)) $db->exec('ALTER TABLE orders ADD COLUMN oss_net BIGINT DEFAULT NULL');
        MigrationSql::index($db, $isSqlite, 'orders', 'idx_orders_store_oss_net', 'store_id, oss_net, created_at');
        MigrationSql::index($db, $isSqlite, 'invoices', 'idx_invoices_oss_net_issued', 'oss_net, issued_at');

        $since = sprintf('%04d-01-01', (int)gmdate('Y') - 1);
        // Totals of the documents whose net is stored already (migration 20261113180000 or issued since).
        $db->prepare('DELETE FROM oss_threshold_totals WHERE year >= ?')->execute([(int)gmdate('Y') - 1]);
        $year = $isSqlite ? 'CAST(substr(i.issued_at, 1, 4) AS INTEGER)' : 'YEAR(i.issued_at)';
        $db->prepare("INSERT INTO oss_threshold_totals (store_id, year, currency, net)
            SELECT o.store_id, {$year}, LOWER(i.currency), SUM(i.oss_net) FROM invoices i JOIN orders o ON o.id = i.order_id
             WHERE i.status = 'issued' AND i.type IN ('invoice', 'credit_note') AND i.oss_net IS NOT NULL AND i.oss_net <> 0 AND i.issued_at >= ?
             GROUP BY o.store_id, {$year}, LOWER(i.currency)")->execute([$since]);
        // Run by an older release's code (its Db without the schema cache reset), the rest is counted from the lines.
        if (!class_exists('OssThreshold') || !method_exists('Db', 'forgetSchema')) return;
        Db::forgetSchema($db);
        // MySQL runs a migration without a transaction: one commit per 500 rows, not per row.
        $batched = static function (array $ids, callable $each) use ($db, $isSqlite): void {
            foreach (array_chunk($ids, 500) as $chunk) {
                if (!$isSqlite) $db->beginTransaction();
                try {
                    foreach ($chunk as $id) $each((int)$id);
                    if (!$isSqlite) $db->commit();
                } catch (Throwable $e) {
                    if (!$isSqlite && $db->inTransaction()) $db->rollBack();
                    throw $e;
                }
            }
        };
        $ids = $db->prepare("SELECT id FROM invoices WHERE oss_net IS NULL AND status = 'issued' AND type IN ('invoice', 'credit_note') AND issued_at >= ? ORDER BY id");
        $ids->execute([$since]);
        $batched($ids->fetchAll(PDO::FETCH_COLUMN), static fn(int $id) => OssThreshold::record($db, $id));
        // An invoiced order counts by its invoice; the others by their own net.
        $db->prepare("UPDATE orders SET oss_net = 0 WHERE oss_net IS NULL AND created_at >= ?
            AND EXISTS (SELECT 1 FROM invoices i WHERE i.order_id = orders.id AND i.type = 'invoice' AND i.status = 'issued')")->execute([$since]);
        $ids = $db->prepare('SELECT id FROM orders WHERE oss_net IS NULL AND created_at >= ? ORDER BY id');
        $ids->execute([$since]);
        $batched($ids->fetchAll(PDO::FETCH_COLUMN), static fn(int $id) => OssThreshold::recordOrder($db, $id));
    }
};
