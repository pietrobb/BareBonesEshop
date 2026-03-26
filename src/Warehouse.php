<?php
/**
 * BareBones Shop — Warehouse & Multi-Stock Management
 *
 * Warehouses hold stock per SKU. Total available = SUM(qty - reserved) across all warehouses.
 * Each warehouse has a priority — orders reserve from highest priority first.
 * Feeds are linked to warehouses — a feed updates stock for its warehouse.
 */
class Warehouse {

    // ─── Warehouse CRUD ──────────────────────────────────────

    public static function all(): array {
        return Db::get()->query("SELECT * FROM warehouses ORDER BY priority DESC, name")->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function byId(int $id): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM warehouses WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function byCode(string $code): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM warehouses WHERE code = :code");
        $stmt->execute([':code' => $code]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function defaultWarehouse(): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM warehouses WHERE is_default = 1 LIMIT 1");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function save(array $data): int {
        $db = Db::get();
        $id = (int)($data['id'] ?? 0);

        if ($id) {
            $db->prepare("UPDATE warehouses SET code = :code, name = :name, priority = :priority,
                is_dropship = :dropship, is_default = :def, contact = :contact, notes = :notes WHERE id = :id")
               ->execute([
                   ':code' => $data['code'], ':name' => $data['name'],
                   ':priority' => (int)($data['priority'] ?? 0),
                   ':dropship' => (int)($data['is_dropship'] ?? 0),
                   ':def' => (int)($data['is_default'] ?? 0),
                   ':contact' => $data['contact'] ?? '', ':notes' => $data['notes'] ?? '',
                   ':id' => $id,
               ]);
        } else {
            $db->prepare("INSERT INTO warehouses (code, name, priority, is_dropship, is_default, contact, notes)
                VALUES (:code, :name, :priority, :dropship, :def, :contact, :notes)")
               ->execute([
                   ':code' => $data['code'], ':name' => $data['name'],
                   ':priority' => (int)($data['priority'] ?? 0),
                   ':dropship' => (int)($data['is_dropship'] ?? 0),
                   ':def' => (int)($data['is_default'] ?? 0),
                   ':contact' => $data['contact'] ?? '', ':notes' => $data['notes'] ?? '',
               ]);
            $id = (int)$db->lastInsertId();
        }

        // Only one default warehouse
        if (!empty($data['is_default'])) {
            $db->prepare("UPDATE warehouses SET is_default = 0 WHERE id != :id")->execute([':id' => $id]);
        }

        return $id;
    }

    public static function delete(int $id): void {
        $db = Db::get();
        $db->prepare("DELETE FROM warehouse_stock WHERE warehouse_id = :id")->execute([':id' => $id]);
        $db->prepare("DELETE FROM feeds WHERE warehouse_id = :id")->execute([':id' => $id]);
        $db->prepare("DELETE FROM warehouses WHERE id = :id")->execute([':id' => $id]);
    }

    // ─── Stock per warehouse ─────────────────────────────────

    /** Set stock for a SKU in a specific warehouse */
    public static function setStock(int $warehouseId, string $sku, int $qty): void {
        $db = Db::get();
        $now = Db::isSqlite() ? "datetime('now')" : 'NOW()';

        if (Db::isSqlite()) {
            $db->prepare("INSERT INTO warehouse_stock (warehouse_id, sku, qty, updated_at)
                VALUES (:wh, :sku, :qty, datetime('now'))
                ON CONFLICT(warehouse_id, sku) DO UPDATE SET qty = :qty2, updated_at = datetime('now')")
               ->execute([':wh' => $warehouseId, ':sku' => $sku, ':qty' => $qty, ':qty2' => $qty]);
        } else {
            $db->prepare("INSERT INTO warehouse_stock (warehouse_id, sku, qty, updated_at)
                VALUES (:wh, :sku, :qty, NOW())
                ON DUPLICATE KEY UPDATE qty = :qty2, updated_at = NOW()")
               ->execute([':wh' => $warehouseId, ':sku' => $sku, ':qty' => $qty, ':qty2' => $qty]);
        }
    }

    /** Bulk set stock for many SKUs in one warehouse (used by feed sync) */
    public static function bulkSetStock(int $warehouseId, array $stockMap): int {
        $count = 0;
        $db = Db::get();
        $db->beginTransaction();
        foreach ($stockMap as $sku => $qty) {
            self::setStock($warehouseId, $sku, (int)$qty);
            $count++;
        }
        $db->commit();

        // Recalculate products.stock for affected SKUs
        self::syncProductStock(array_keys($stockMap));

        return $count;
    }

    /** Get stock breakdown for a SKU across all warehouses */
    public static function stockBySku(string $sku): array {
        $stmt = Db::get()->prepare("
            SELECT w.id, w.code, w.name, w.priority, w.is_dropship,
                   COALESCE(ws.qty, 0) as qty, COALESCE(ws.reserved, 0) as reserved
            FROM warehouses w
            LEFT JOIN warehouse_stock ws ON ws.warehouse_id = w.id AND ws.sku = :sku
            ORDER BY w.priority DESC
        ");
        $stmt->execute([':sku' => $sku]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Total available stock for a SKU (sum of all warehouses, minus reserved) */
    public static function totalAvailable(string $sku): int {
        $stmt = Db::get()->prepare("
            SELECT COALESCE(SUM(qty - reserved), 0) FROM warehouse_stock WHERE sku = :sku
        ");
        $stmt->execute([':sku' => $sku]);
        return max(0, (int)$stmt->fetchColumn());
    }

    /** Reserve stock for an order — takes from highest priority warehouse first */
    public static function reserve(string $sku, int $qty): array {
        $db = Db::get();
        $allocations = [];
        $remaining = $qty;

        $rows = $db->prepare("
            SELECT ws.id, ws.warehouse_id, ws.qty, ws.reserved, w.priority, w.code
            FROM warehouse_stock ws
            JOIN warehouses w ON w.id = ws.warehouse_id
            WHERE ws.sku = :sku AND (ws.qty - ws.reserved) > 0
            ORDER BY w.priority DESC
        ");
        $rows->execute([':sku' => $sku]);

        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($remaining <= 0) break;
            $available = $row['qty'] - $row['reserved'];
            $take = min($remaining, $available);

            $db->prepare("UPDATE warehouse_stock SET reserved = reserved + :take WHERE id = :id")
               ->execute([':take' => $take, ':id' => $row['id']]);

            $allocations[] = [
                'warehouse_id'   => (int)$row['warehouse_id'],
                'warehouse_code' => $row['code'],
                'qty'            => $take,
            ];
            $remaining -= $take;
        }

        if ($remaining > 0) {
            throw new \RuntimeException("Nedostatok na sklade pre SKU: {$sku} (chýba {$remaining} ks)");
        }

        return $allocations;
    }

    /** Release reservation (order cancelled) */
    public static function release(string $sku, array $allocations): void {
        $db = Db::get();
        foreach ($allocations as $alloc) {
            $db->prepare("
                UPDATE warehouse_stock SET reserved = MAX(0, reserved - :qty)
                WHERE warehouse_id = :wh AND sku = :sku
            ")->execute([':qty' => $alloc['qty'], ':wh' => $alloc['warehouse_id'], ':sku' => $sku]);
        }
    }

    /** Sync products.stock column from warehouse_stock totals */
    public static function syncProductStock(array $skus = []): void {
        $db = Db::get();
        if (empty($skus)) {
            // Sync all
            $db->exec("UPDATE products SET stock = COALESCE((
                SELECT SUM(qty - reserved) FROM warehouse_stock WHERE warehouse_stock.sku = products.sku
            ), 0)");
        } else {
            $stmt = $db->prepare("UPDATE products SET stock = COALESCE((
                SELECT SUM(qty - reserved) FROM warehouse_stock WHERE warehouse_stock.sku = :sku
            ), 0) WHERE sku = :sku2");
            foreach ($skus as $sku) {
                $stmt->execute([':sku' => $sku, ':sku2' => $sku]);
            }
        }
    }

    // ─── Feed CRUD ───────────────────────────────────────────

    public static function allFeeds(): array {
        return Db::get()->query("
            SELECT f.*, w.name as warehouse_name, w.code as warehouse_code
            FROM feeds f JOIN warehouses w ON w.id = f.warehouse_id
            ORDER BY f.name
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function feedById(int $id): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM feeds WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function feedsByWarehouse(int $warehouseId): array {
        $stmt = Db::get()->prepare("SELECT * FROM feeds WHERE warehouse_id = :wh ORDER BY name");
        $stmt->execute([':wh' => $warehouseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function saveFeed(array $data): int {
        $db = Db::get();
        $id = (int)($data['id'] ?? 0);

        $params = [
            ':code' => $data['code'],
            ':name' => $data['name'],
            ':wh'   => (int)$data['warehouse_id'],
            ':url'  => $data['url'],
            ':fmt'  => $data['format'] ?? 'xml',
            ':map'  => is_string($data['field_map'] ?? '') ? $data['field_map'] : json_encode($data['field_map'] ?? []),
            ':mode' => $data['import_mode'] ?? 'upsert',
            ':sched' => $data['schedule'] ?? '0 */6 * * *',
            ':on'   => (int)($data['enabled'] ?? 1),
        ];

        if ($id) {
            $params[':id'] = $id;
            $db->prepare("UPDATE feeds SET code=:code, name=:name, warehouse_id=:wh, url=:url, format=:fmt,
                field_map=:map, import_mode=:mode, schedule=:sched, enabled=:on WHERE id=:id")
               ->execute($params);
        } else {
            $db->prepare("INSERT INTO feeds (code, name, warehouse_id, url, format, field_map, import_mode, schedule, enabled)
                VALUES (:code, :name, :wh, :url, :fmt, :map, :mode, :sched, :on)")
               ->execute($params);
            $id = (int)$db->lastInsertId();
        }
        return $id;
    }

    public static function deleteFeed(int $id): void {
        Db::get()->prepare("DELETE FROM feeds WHERE id = :id")->execute([':id' => $id]);
    }

    public static function updateFeedStatus(int $feedId, string $status, int $count): void {
        $now = Db::isSqlite() ? "datetime('now')" : 'NOW()';
        Db::get()->prepare("UPDATE feeds SET last_sync = {$now}, last_status = :status, last_count = :cnt WHERE id = :id")
           ->execute([':status' => $status, ':cnt' => $count, ':id' => $feedId]);
    }
}
