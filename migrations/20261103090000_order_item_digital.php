<?php
/**
 * Freeze an order line's digital nature on the line (Inventory::isDigitalLine): 1 = digital content (nothing reserved,
 * nothing shipped), 0 = goods. Read live from the catalog before, an admin switching a variant's digital flag after the
 * order lost or invented stock on cancellation, and an e-book line became a parcel of its own carrying the whole
 * cash-on-delivery amount. Existing lines: digital when the product type is digital, or the variant is digital and the
 * line reserved no stock (a line with an allocation or bundle snapshot was sold as goods).
 * R15: in id ranges of BATCH lines. One UPDATE of the whole table took 156 s at 4 million lines and deadlocked with
 * concurrent writers (MySQL runs migrations in autocommit: each batch commits on its own). Re-runnable: only lines
 * still NULL are set.
 */
return new class {
    private const BATCH = 5000;

    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(order_items)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('digital', $columns, true)) $db->exec('ALTER TABLE order_items ADD COLUMN digital INTEGER NULL');
        $range = 'id > :from AND id <= :to AND digital IS NULL';
        $byProduct = $db->prepare("UPDATE order_items SET digital = 1 WHERE {$range}
            AND EXISTS (SELECT 1 FROM products p WHERE p.id = order_items.product_id AND LOWER(COALESCE(p.product_type, '')) IN ('digital', 'downloadable'))");
        $variantLines = $db->prepare("SELECT id, attrs FROM order_items WHERE {$range}
            AND EXISTS (SELECT 1 FROM variants v WHERE v.id = order_items.variant_id AND v.digital = 1)");
        $mark = $db->prepare('UPDATE order_items SET digital = 1 WHERE id = :id');
        $goods = $db->prepare("UPDATE order_items SET digital = 0 WHERE {$range}");
        $last = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM order_items')->fetchColumn();
        for ($from = 0; $from < $last; $from += self::BATCH) {
            $bounds = [':from' => $from, ':to' => $from + self::BATCH];
            $byProduct->execute($bounds);
            // The snapshot keys are read from the decoded attrs: a customer-visible attribute value may contain the same words.
            $variantLines->execute($bounds);
            foreach ($variantLines->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $json) {
                $attrs = json_decode((string)$json, true);
                $soldAsGoods = is_array($attrs) && (!empty($attrs['_inventory_allocations']) || !empty($attrs['_bundle_components']));
                if (!$soldAsGoods) $mark->execute([':id' => $id]);
            }
            $goods->execute($bounds);
        }
    }
};
