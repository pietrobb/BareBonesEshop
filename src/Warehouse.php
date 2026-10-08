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
        $stmt = Db::get()->prepare("SELECT * FROM warehouses WHERE store_id = :store ORDER BY priority DESC, name");
        $stmt->execute([':store' => StoreContext::current()->id()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function byId(int $id): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM warehouses WHERE id = :id AND store_id = :store");
        $stmt->execute([':id' => $id, ':store' => StoreContext::current()->id()]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function byCode(string $code): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM warehouses WHERE code = :code AND store_id = :store");
        $stmt->execute([':code' => $code, ':store' => StoreContext::current()->id()]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function defaultWarehouse(): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM warehouses WHERE store_id = :store AND is_default = 1 LIMIT 1");
        $stmt->execute([':store' => StoreContext::current()->id()]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function save(array $data): int {
        $db = Db::get();
        $id = (int)($data['id'] ?? 0);

        if ($id) {
            $db->prepare("UPDATE warehouses SET code = :code, name = :name, priority = :priority,
                is_dropship = :dropship, is_default = :def, contact = :contact, notes = :notes WHERE id = :id AND store_id = :store")
               ->execute([
                   ':code' => $data['code'], ':name' => $data['name'],
                   ':priority' => (int)($data['priority'] ?? 0),
                   ':dropship' => (int)($data['is_dropship'] ?? 0),
                   ':def' => (int)($data['is_default'] ?? 0),
                   ':contact' => $data['contact'] ?? '', ':notes' => $data['notes'] ?? '',
                   ':id' => $id, ':store' => StoreContext::current()->id(),
               ]);
        } else {
            $db->prepare("INSERT INTO warehouses (store_id, code, name, priority, is_dropship, is_default, contact, notes)
                VALUES (:store, :code, :name, :priority, :dropship, :def, :contact, :notes)")
               ->execute([
                   ':code' => $data['code'], ':name' => $data['name'],
                   ':priority' => (int)($data['priority'] ?? 0),
                   ':dropship' => (int)($data['is_dropship'] ?? 0),
                   ':def' => (int)($data['is_default'] ?? 0),
                   ':contact' => $data['contact'] ?? '', ':notes' => $data['notes'] ?? '',
                   ':store' => StoreContext::current()->id(),
               ]);
            $id = (int)$db->lastInsertId();
        }

        // Only one default warehouse
        if (!empty($data['is_default'])) {
            $db->prepare("UPDATE warehouses SET is_default = 0 WHERE store_id = :store AND id != :id")
                ->execute([':store' => StoreContext::current()->id(), ':id' => $id]);
        }

        return $id;
    }

    public static function delete(int $id): void {
        if (!self::byId($id)) throw new RuntimeException('Warehouse not found in active store');
        $db = Db::get();
        $db->prepare("DELETE FROM warehouse_stock WHERE warehouse_id = :id")->execute([':id' => $id]);
        $db->prepare("DELETE FROM feeds WHERE warehouse_id = :id")->execute([':id' => $id]);
        $db->prepare("DELETE FROM warehouses WHERE id = :id")->execute([':id' => $id]);
    }

    // ─── Stock per warehouse ─────────────────────────────────

    /** Set an absolute count and preserve the delta in the immutable ledger. */
    public static function setStock(
        int $warehouseId,
        string $sku,
        int $qty,
        string $movementType = 'count',
        ?string $referenceType = null,
        string|int|null $referenceId = null,
        ?string $idempotencyKey = null
    ): void {
        if ($qty < 0) throw new InvalidArgumentException('Stock count cannot be negative');
        if (!self::byId($warehouseId)) throw new RuntimeException('Warehouse not found in active store');
        $db = Db::get();
        $beforeStmt = $db->prepare('SELECT qty, reserved FROM warehouse_stock WHERE warehouse_id = :wh AND sku = :sku');
        $beforeStmt->execute([':wh' => $warehouseId, ':sku' => $sku]);
        $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: ['qty' => 0, 'reserved' => 0];
        $params = [
            ':store' => StoreContext::current()->id(), ':wh' => $warehouseId,
            ':sku' => $sku, ':qty' => $qty, ':qty2' => $qty,
        ];
        if (Db::isSqlite()) {
            $db->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, updated_at)
                VALUES (:store, :wh, :sku, :qty, datetime('now'))
                ON CONFLICT(warehouse_id, sku) DO UPDATE SET store_id = :store, qty = :qty2, updated_at = datetime('now')")
               ->execute($params);
        } else {
            $db->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, updated_at)
                VALUES (:store, :wh, :sku, :qty, NOW())
                ON DUPLICATE KEY UPDATE store_id = :store, qty = :qty2, updated_at = NOW()")
               ->execute($params);
        }
        Inventory::movement(
            $warehouseId, $sku, $movementType, $qty - (int)$before['qty'], 0,
            $qty, (int)$before['reserved'], $referenceType, $referenceId, $idempotencyKey
        );
    }

    /** Bulk set stock for many SKUs in one warehouse (used by feed sync) */
    public static function bulkSetStock(int $warehouseId, array $stockMap): int {
        $count = 0;
        $db = Db::get();
        $db->beginTransaction();
        foreach ($stockMap as $sku => $qty) {
            self::setStock($warehouseId, $sku, (int)$qty, 'feed_sync', 'warehouse_feed', $warehouseId);
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
            LEFT JOIN warehouse_stock ws ON ws.warehouse_id = w.id AND ws.sku = :sku AND ws.store_id = :stock_store
            WHERE w.store_id = :warehouse_store
            ORDER BY w.priority DESC
        ");
        $stmt->execute([
            ':sku' => $sku,
            ':stock_store' => StoreContext::current()->id(),
            ':warehouse_store' => StoreContext::current()->id(),
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Total available stock for a SKU (sum of all warehouses, minus reserved) */
    public static function totalAvailable(string $sku): int {
        $stmt = Db::get()->prepare("
            SELECT COALESCE(SUM(qty - reserved), 0) FROM warehouse_stock WHERE sku = :sku AND store_id = :store
        ");
        $stmt->execute([':sku' => $sku, ':store' => StoreContext::current()->id()]);
        return max(0, (int)$stmt->fetchColumn());
    }

    /**
     * Reserve stock for an order — takes from highest priority warehouse first.
     *
     * Concurrency-safe: the reservation itself is a conditional UPDATE guarded
     * by `(qty - reserved) >= :take`, so two parallel checkouts can never both
     * reserve the same last piece (the SELECT below is only a candidate
     * snapshot — the UPDATE re-validates availability atomically).
     *
     * @throws \RuntimeException when the SKU cannot be fully reserved; any
     *                           partial allocations are rolled back first.
     */
    public static function reserve(string $sku, int $qty, string $shortfallPolicy = Inventory::DENY): array {
        if ($qty <= 0) throw new InvalidArgumentException('Reservation quantity must be positive');
        if (!in_array($shortfallPolicy, [Inventory::DENY, Inventory::BACKORDER, Inventory::PREORDER], true)) {
            throw new InvalidArgumentException('Invalid inventory shortfall policy');
        }
        $db = Db::get();
        $allocations = [];
        $remaining = $qty;

        $rows = $db->prepare("
            SELECT ws.id, ws.warehouse_id, ws.qty, ws.reserved, w.priority, w.code
            FROM warehouse_stock ws
            JOIN warehouses w ON w.id = ws.warehouse_id
            WHERE ws.sku = :sku AND ws.store_id = :store AND w.store_id = :warehouse_store
              AND (ws.qty - ws.reserved) > 0
            ORDER BY w.priority DESC
        ");
        $rows->execute([
            ':sku' => $sku,
            ':store' => StoreContext::current()->id(),
            ':warehouse_store' => StoreContext::current()->id(),
        ]);

        // NOTE: :take2 MUST be bound as PARAM_INT — execute(array) binds TEXT,
        // and SQLite never coerces a TEXT param when compared against the
        // affinity-less expression (qty - reserved), making the guard always
        // false (integer < text in SQLite type ordering).
        $upd = $db->prepare("
            UPDATE warehouse_stock SET reserved = reserved + :take
            WHERE id = :id AND (qty - reserved) >= :take2
        ");
        $cur = $db->prepare("SELECT qty - reserved FROM warehouse_stock WHERE id = :id");

        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($remaining <= 0) break;
            $take = min($remaining, max(0, (int)$row['qty'] - (int)$row['reserved']));

            while ($take > 0) {
                $upd->bindValue(':take',  $take, PDO::PARAM_INT);
                $upd->bindValue(':take2', $take, PDO::PARAM_INT);
                $upd->bindValue(':id',    (int)$row['id'], PDO::PARAM_INT);
                $upd->execute();
                if ($upd->rowCount() > 0) {
                    $allocations[] = [
                        'warehouse_id'   => (int)$row['warehouse_id'],
                        'warehouse_code' => $row['code'],
                        'qty'            => $take,
                        'status'         => 'reserved',
                    ];
                    Inventory::movement(
                        (int)$row['warehouse_id'], $sku, 'reserve', 0, $take,
                        (int)$row['qty'], (int)$row['reserved'] + $take, 'checkout'
                    );
                    $remaining -= $take;
                    break;
                }
                // Lost a race — a concurrent reservation shrank availability.
                // Re-read and retry with what is actually left (strictly less
                // than $take when the guard failed, so the loop terminates).
                $cur->execute([':id' => (int)$row['id']]);
                $newTake = min($take, max(0, (int)$cur->fetchColumn()));
                if ($newTake >= $take) break; // defensive: never spin
                $take = $newTake;
            }
        }

        if ($remaining > 0) {
            if ($shortfallPolicy === Inventory::DENY) {
                // Roll back partial allocations so a failed reserve leaves no trace.
                if ($allocations) self::release($sku, $allocations);
                throw new \RuntimeException("Nedostatok na sklade pre SKU: {$sku} (chýba {$remaining} ks)");
            }
            $allocations[] = [
                'warehouse_id' => null,
                'warehouse_code' => null,
                'qty' => $remaining,
                'status' => $shortfallPolicy,
            ];
            Inventory::movement(
                null, $sku, $shortfallPolicy, 0, 0, 0, 0, 'checkout', null, null,
                ['unallocated_quantity' => $remaining]
            );
        }

        return $allocations;
    }

    /** Release an exact reservation; mismatches fail instead of hiding a double release. */
    public static function release(
        string $sku,
        array $allocations,
        ?string $referenceType = null,
        string|int|null $referenceId = null
    ): void {
        $db = Db::get();
        foreach ($allocations as $alloc) {
            if (empty($alloc['warehouse_id']) || ($alloc['status'] ?? 'reserved') !== 'reserved') continue;
            $qty = (int)$alloc['qty'];
            $stmt = $db->prepare("UPDATE warehouse_stock SET reserved = reserved - :qty
                WHERE warehouse_id = :wh AND sku = :sku AND reserved >= :qty2");
            $stmt->bindValue(':qty', $qty, PDO::PARAM_INT);
            $stmt->bindValue(':qty2', $qty, PDO::PARAM_INT);
            $stmt->bindValue(':wh', (int)$alloc['warehouse_id'], PDO::PARAM_INT);
            $stmt->bindValue(':sku', $sku, PDO::PARAM_STR);
            $stmt->execute();
            if ($stmt->rowCount() !== 1) throw new RuntimeException("Reservation release mismatch for SKU: {$sku}");
            $after = self::stockRow((int)$alloc['warehouse_id'], $sku);
            Inventory::movement(
                (int)$alloc['warehouse_id'], $sku, 'release', 0, -$qty,
                (int)$after['qty'], (int)$after['reserved'], $referenceType, $referenceId
            );
        }
    }

    /** Physical return after shipping: goods come back as on-hand qty.
     *  The order's reservation was already consumed at shipping time,
     *  so 'reserved' must NOT be touched here (it belongs to other orders). */
    public static function restock(
        string $sku,
        array $allocations,
        ?string $referenceType = null,
        string|int|null $referenceId = null
    ): void {
        $db = Db::get();
        foreach ($allocations as $alloc) {
            if (empty($alloc['warehouse_id']) || ($alloc['status'] ?? 'reserved') !== 'reserved') continue;
            $qty = (int)$alloc['qty'];
            $stmt = $db->prepare("UPDATE warehouse_stock SET qty = qty + :qty WHERE warehouse_id = :wh AND sku = :sku");
            $stmt->execute([':qty' => $qty, ':wh' => $alloc['warehouse_id'], ':sku' => $sku]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException("Restock target missing for SKU: {$sku}");
            $after = self::stockRow((int)$alloc['warehouse_id'], $sku);
            Inventory::movement(
                (int)$alloc['warehouse_id'], $sku, 'return', $qty, 0,
                (int)$after['qty'], (int)$after['reserved'], $referenceType, $referenceId
            );
        }
    }

    /** Apply a receipt or adjustment while preserving reservation invariants. */
    public static function adjustStock(
        int $warehouseId,
        string $sku,
        int $delta,
        string $movementType = 'adjustment',
        ?string $referenceType = null,
        string|int|null $referenceId = null,
        ?string $idempotencyKey = null
    ): void {
        if ($delta === 0) return;
        $db = Db::get();
        $before = self::stockRow($warehouseId, $sku, false);
        if ($before === null) {
            if ($delta < 0) throw new RuntimeException("Cannot reduce missing stock row for SKU: {$sku}");
            self::setStock($warehouseId, $sku, $delta, $movementType, $referenceType, $referenceId, $idempotencyKey);
            self::syncProductStock([$sku]);
            return;
        }
        $stmt = $db->prepare("UPDATE warehouse_stock SET qty = qty + :delta, updated_at = " . Db::now() . "
            WHERE warehouse_id = :warehouse AND sku = :sku AND qty + :delta2 >= reserved");
        $stmt->bindValue(':delta', $delta, PDO::PARAM_INT);
        $stmt->bindValue(':delta2', $delta, PDO::PARAM_INT);
        $stmt->bindValue(':warehouse', $warehouseId, PDO::PARAM_INT);
        $stmt->bindValue(':sku', $sku, PDO::PARAM_STR);
        $stmt->execute();
        if ($stmt->rowCount() !== 1) throw new RuntimeException("Adjustment would violate reservations for SKU: {$sku}");
        $after = self::stockRow($warehouseId, $sku);
        Inventory::movement(
            $warehouseId, $sku, $movementType, $delta, 0,
            (int)$after['qty'], (int)$after['reserved'], $referenceType, $referenceId, $idempotencyKey
        );
        self::syncProductStock([$sku]);
    }

    /**
     * Manual/import stock edit expressed as "available" (what products.stock shows): the difference
     * is booked as an adjustment on the default warehouse. Returns false without a default warehouse.
     */
    public static function setAvailable(string $sku, int $available, string $movementType = 'manual_adjustment', ?string $referenceType = null): bool {
        if ($available < 0) throw new InvalidArgumentException('Stock count cannot be negative');
        $warehouse = self::defaultWarehouse();
        if (!$warehouse) return false;
        $delta = $available - self::totalAvailable($sku);
        if ($delta !== 0) self::adjustStock((int)$warehouse['id'], $sku, $delta, $movementType, $referenceType);
        return true;
    }

    /** Consume an exact reservation when shipment leaves a warehouse. */
    public static function ship(
        string $sku,
        array $allocation,
        string|int $shipmentId
    ): void {
        $warehouseId = (int)($allocation['warehouse_id'] ?? 0);
        $qty = (int)($allocation['qty'] ?? 0);
        if ($warehouseId <= 0 || $qty <= 0) throw new InvalidArgumentException('Shipment allocation is invalid');
        $stmt = Db::get()->prepare("UPDATE warehouse_stock
            SET qty = qty - :qty, reserved = reserved - :reserved, updated_at = " . Db::now() . "
            WHERE warehouse_id = :warehouse AND sku = :sku AND qty >= :qty2 AND reserved >= :reserved2");
        $stmt->bindValue(':qty', $qty, PDO::PARAM_INT);
        $stmt->bindValue(':reserved', $qty, PDO::PARAM_INT);
        $stmt->bindValue(':qty2', $qty, PDO::PARAM_INT);
        $stmt->bindValue(':reserved2', $qty, PDO::PARAM_INT);
        $stmt->bindValue(':warehouse', $warehouseId, PDO::PARAM_INT);
        $stmt->bindValue(':sku', $sku, PDO::PARAM_STR);
        $stmt->execute();
        if ($stmt->rowCount() !== 1) throw new RuntimeException("Shipment does not match reservation for SKU: {$sku}");
        $after = self::stockRow($warehouseId, $sku);
        Inventory::movement(
            $warehouseId, $sku, 'shipment', -$qty, -$qty,
            (int)$after['qty'], (int)$after['reserved'], 'shipment', $shipmentId,
            "shipment:{$shipmentId}:{$warehouseId}:{$sku}"
        );
        self::syncProductStock([$sku]);
    }

    /** Sync products.stock column from warehouse_stock totals */
    public static function syncProductStock(array $skus = []): void {
        $db = Db::get();
        if (empty($skus)) {
            // Sync all
            $db->exec("UPDATE products SET stock = COALESCE((
                SELECT SUM(qty - reserved) FROM warehouse_stock
                WHERE warehouse_stock.sku = products.sku AND warehouse_stock.store_id = products.store_id
            ), 0)");
        } else {
            $stmt = $db->prepare("UPDATE products SET stock = COALESCE((
                SELECT SUM(qty - reserved) FROM warehouse_stock
                WHERE warehouse_stock.sku = :sku AND warehouse_stock.store_id = products.store_id
            ), 0) WHERE sku = :sku2 AND store_id = :store");
            foreach ($skus as $sku) {
                $stmt->execute([':sku' => $sku, ':sku2' => $sku, ':store' => StoreContext::current()->catalogId()]);
            }
        }
    }

    private static function stockRow(int $warehouseId, string $sku, bool $required = true): ?array {
        $stmt = Db::get()->prepare('SELECT qty, reserved FROM warehouse_stock WHERE warehouse_id = :warehouse AND sku = :sku AND store_id = :store');
        $stmt->execute([':warehouse' => $warehouseId, ':sku' => $sku, ':store' => StoreContext::current()->id()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row && $required) throw new RuntimeException("Warehouse stock row missing for SKU: {$sku}");
        return $row ?: null;
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
