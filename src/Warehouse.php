<?php
/**
 * BareBones Shop — Warehouse & Multi-Stock Management
 *
 * Warehouses hold stock per SKU. Total available = SUM(qty - reserved) across all warehouses.
 * Each warehouse has a priority — orders reserve from highest priority first.
 * Feeds are linked to warehouses — a feed updates stock for its warehouse.
 * Stock belongs to the catalog that owns the SKU: warehouses.store_id and warehouse_stock.store_id hold the
 * catalog_scope (StoreContext::catalogId()), so every store sharing a catalog sells from the same warehouses.
 */
class Warehouse {

    // ─── Warehouse CRUD ──────────────────────────────────────

    public static function all(): array {
        $stmt = Db::get()->prepare("SELECT * FROM warehouses WHERE store_id = :store ORDER BY priority DESC, name");
        $stmt->execute([':store' => StoreContext::current()->catalogId()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The store a catalog's stock jobs run in: the store whose id is the catalog scope, else the first store sharing it. */
    public static function catalogStoreId(string $catalog): string {
        $stores = (array)(Bootstrap::stores()['stores'] ?? []);
        if (isset($stores[$catalog])) return $catalog;
        foreach ($stores as $id => $config) {
            if ((string)(is_array($config) ? ($config['catalog_scope'] ?? $id) : $id) === $catalog) return (string)$id;
        }
        return $catalog;
    }

    public static function byId(int $id): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM warehouses WHERE id = :id AND store_id = :store");
        $stmt->execute([':id' => $id, ':store' => StoreContext::current()->catalogId()]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function byCode(string $code): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM warehouses WHERE code = :code AND store_id = :store");
        $stmt->execute([':code' => $code, ':store' => StoreContext::current()->catalogId()]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function defaultWarehouse(): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM warehouses WHERE store_id = :store AND is_default = 1 LIMIT 1");
        $stmt->execute([':store' => StoreContext::current()->catalogId()]);
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
                   ':id' => $id, ':store' => StoreContext::current()->catalogId(),
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
                   ':store' => StoreContext::current()->catalogId(),
               ]);
            $id = (int)$db->lastInsertId();
        }

        // Only one default warehouse
        if (!empty($data['is_default'])) {
            $db->prepare("UPDATE warehouses SET is_default = 0 WHERE store_id = :store AND id != :id")
                ->execute([':store' => StoreContext::current()->catalogId(), ':id' => $id]);
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
        if ($qty < (int)$before['reserved']) {
            throw new RuntimeException("Stock count {$qty} is below the {$before['reserved']} units reserved for SKU: {$sku}");
        }
        $params = [
            ':store' => StoreContext::current()->catalogId(), ':store2' => StoreContext::current()->catalogId(), ':wh' => $warehouseId,
            ':sku' => $sku, ':qty' => $qty, ':qty2' => $qty,
        ];
        // Native MySQL prepares (Db::get) reject a reused named placeholder, hence :store2.
        if (Db::isSqlite()) {
            $db->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, updated_at)
                VALUES (:store, :wh, :sku, :qty, datetime('now'))
                ON CONFLICT(warehouse_id, sku) DO UPDATE SET store_id = :store2, qty = :qty2, updated_at = datetime('now')")
               ->execute($params);
        } else {
            $db->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, updated_at)
                VALUES (:store, :wh, :sku, :qty, NOW())
                ON DUPLICATE KEY UPDATE store_id = :store2, qty = :qty2, updated_at = NOW()")
               ->execute($params);
        }
        Inventory::movement(
            $warehouseId, $sku, $movementType, $qty - (int)$before['qty'], 0,
            $qty, (int)$before['reserved'], $referenceType, $referenceId, $idempotencyKey
        );
    }

    /**
     * Bulk set stock for many SKUs in one warehouse (feed/ERP sync). A row with an empty SKU or a count that is not a
     * whole number >= 0 is skipped (its SKU listed in $skipped, reported to operations) and the rest of the feed is
     * applied. A count below the units already reserved for open orders is clamped to the reservations (nothing left
     * to sell) and reported as an oversell. A database failure still rolls back the whole sync.
     */
    public static function bulkSetStock(int $warehouseId, array $stockMap, ?array &$skipped = null): int {
        $count = 0;
        $oversold = 0;
        $skipped = [];
        $applied = [];
        $db = Db::get();
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) $db->beginTransaction();
        try {
            // Locked on MySQL so a concurrent reservation cannot slip in between this read and the write.
            $reservedStmt = $db->prepare('SELECT reserved FROM warehouse_stock WHERE warehouse_id = :wh AND sku = :sku' . (Db::isMysql() ? ' FOR UPDATE' : ''));
            foreach ($stockMap as $sku => $qty) {
                $sku = (string)$sku;
                if (trim($sku) === '' || filter_var($qty, FILTER_VALIDATE_INT) === false || (int)$qty < 0) {
                    $skipped[] = $sku;
                    continue;
                }
                $reservedStmt->execute([':wh' => $warehouseId, ':sku' => $sku]);
                $reserved = (int)$reservedStmt->fetchColumn();
                $reservedStmt->closeCursor();
                $qty = (int)$qty;
                if ($qty < $reserved) { $oversold++; $qty = $reserved; }
                self::setStock($warehouseId, $sku, $qty, 'feed_sync', 'warehouse_feed', $warehouseId);
                $applied[] = $sku;
                $count++;
            }
            self::syncProductStock($applied);
            if ($ownsTransaction) $db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
        if ($oversold > 0) IncidentMonitor::report('inventory.feed_below_reserved', 'inventory', 'warning', ['count' => $oversold]);
        if ($skipped !== []) IncidentMonitor::report('inventory.feed_invalid_rows', 'inventory', 'warning', ['count' => count($skipped)]);

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
            ':stock_store' => StoreContext::current()->catalogId(),
            ':warehouse_store' => StoreContext::current()->catalogId(),
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Total available stock for a SKU (sum of all warehouses, minus reserved) */
    public static function totalAvailable(string $sku): int {
        $stmt = Db::get()->prepare("
            SELECT COALESCE(SUM(qty - reserved), 0) FROM warehouse_stock WHERE sku = :sku AND store_id = :store
        ");
        $stmt->execute([':sku' => $sku, ':store' => StoreContext::current()->catalogId()]);
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
            ':store' => StoreContext::current()->catalogId(),
            ':warehouse_store' => StoreContext::current()->catalogId(),
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
        self::syncVariantStock($skus);
    }

    /**
     * Mirror ledger availability into variants.stock (read by cart and purchasability checks) for variant SKUs the
     * ledger tracks; variants without warehouse rows keep their legacy stock column.
     */
    private static function syncVariantStock(array $skus): void {
        $sql = "UPDATE variants SET stock = (
                SELECT COALESCE(SUM(ws.qty - ws.reserved), 0) FROM warehouse_stock ws WHERE ws.sku = variants.sku AND ws.store_id = :store
            )
            WHERE EXISTS (SELECT 1 FROM warehouse_stock w2 WHERE w2.sku = variants.sku AND w2.store_id = :store2)
              AND product_id IN (SELECT id FROM products WHERE store_id = :catalog)";
        $params = [':store' => StoreContext::current()->catalogId(), ':store2' => StoreContext::current()->catalogId(), ':catalog' => StoreContext::current()->catalogId()];
        if (empty($skus)) {
            Db::get()->prepare($sql)->execute($params);
            return;
        }
        $stmt = Db::get()->prepare($sql . ' AND sku = :sku');
        foreach ($skus as $sku) $stmt->execute($params + [':sku' => $sku]);
    }

    private static function stockRow(int $warehouseId, string $sku, bool $required = true): ?array {
        $stmt = Db::get()->prepare('SELECT qty, reserved FROM warehouse_stock WHERE warehouse_id = :warehouse AND sku = :sku AND store_id = :store');
        $stmt->execute([':warehouse' => $warehouseId, ':sku' => $sku, ':store' => StoreContext::current()->catalogId()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row && $required) throw new RuntimeException("Warehouse stock row missing for SKU: {$sku}");
        return $row ?: null;
    }

    // ─── Feed CRUD ───────────────────────────────────────────

    // Feeds carry no store column: a feed belongs to the catalog of its warehouse, so every read and write joins
    // warehouses and is bound to the active store's catalog.

    public static function allFeeds(): array {
        $stmt = Db::get()->prepare("
            SELECT f.*, w.name as warehouse_name, w.code as warehouse_code, w.store_id
            FROM feeds f JOIN warehouses w ON w.id = f.warehouse_id
            WHERE w.store_id = :store
            ORDER BY f.name
        ");
        $stmt->execute([':store' => StoreContext::current()->catalogId()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Feeds of every catalog with their warehouse's store_id (a catalog scope): only for runners that switch into that catalog per feed (cli/feed-sync.php, FeedImport::syncInStore()). */
    public static function allFeedsAllStores(): array {
        return Db::get()->query("
            SELECT f.*, w.name as warehouse_name, w.code as warehouse_code, w.store_id
            FROM feeds f JOIN warehouses w ON w.id = f.warehouse_id
            ORDER BY w.store_id, f.name
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function feedById(int $id): ?array {
        $stmt = Db::get()->prepare("SELECT f.*, w.store_id FROM feeds f JOIN warehouses w ON w.id = f.warehouse_id WHERE f.id = :id AND w.store_id = :store");
        $stmt->execute([':id' => $id, ':store' => StoreContext::current()->catalogId()]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function feedsByWarehouse(int $warehouseId): array {
        $stmt = Db::get()->prepare("SELECT f.* FROM feeds f JOIN warehouses w ON w.id = f.warehouse_id WHERE f.warehouse_id = :wh AND w.store_id = :store ORDER BY f.name");
        $stmt->execute([':wh' => $warehouseId, ':store' => StoreContext::current()->catalogId()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Create or update a feed of the active store. Refuses (RuntimeException) a warehouse of another store, a feed id of
     * another store and an import mode outside FeedImport::MODES.
     */
    public static function saveFeed(array $data): int {
        $db = Db::get();
        $id = (int)($data['id'] ?? 0);
        if (!self::byId((int)($data['warehouse_id'] ?? 0))) throw new RuntimeException('Warehouse not found in active store');
        if ($id && !self::feedById($id)) throw new RuntimeException('Feed not found in active store');
        $mode = (string)($data['import_mode'] ?? FeedImport::MODE_UPSERT);
        if (!in_array($mode, FeedImport::MODES, true)) throw new InvalidArgumentException("Unknown feed import mode: {$mode}");

        $params = [
            ':code' => $data['code'],
            ':name' => $data['name'],
            ':wh'   => (int)$data['warehouse_id'],
            ':url'  => $data['url'],
            ':fmt'  => $data['format'] ?? 'xml',
            ':map'  => is_string($data['field_map'] ?? '') ? $data['field_map'] : json_encode($data['field_map'] ?? []),
            ':mode' => $mode,
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

    /** Delete a feed of the active store; false when it does not exist there. */
    public static function deleteFeed(int $id): bool {
        if (!self::feedById($id)) return false;
        $stmt = Db::get()->prepare("DELETE FROM feeds WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public static function updateFeedStatus(int $feedId, string $status, int $count): void {
        $now = Db::isSqlite() ? "datetime('now')" : 'NOW()';
        Db::get()->prepare("UPDATE feeds SET last_sync = {$now}, last_status = :status, last_count = :cnt WHERE id = :id")
           ->execute([':status' => $status, ':cnt' => $count, ':id' => $feedId]);
    }
}
