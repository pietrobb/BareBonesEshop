<?php
/** Warehouse-split shipment planning and idempotent partial dispatch. */
class Fulfillment {
    /** Create one planned shipment per warehouse from frozen order allocations. */
    public static function planOrder(int $orderId): array {
        $order = Orders::byId($orderId);
        if (!$order) throw new RuntimeException("Order #{$orderId} not found");
        $groups = [];
        foreach (Orders::items($orderId) as $orderItem) {
            foreach (Inventory::reservationLines($orderItem) as $line) {
                foreach ($line['allocations'] ?? [] as $allocation) {
                    $status = (string)($allocation['status'] ?? 'reserved');
                    if (!in_array($status, ['reserved', 'legacy_variant'], true)) continue;
                    $warehouseId = empty($allocation['warehouse_id']) ? null : (int)$allocation['warehouse_id'];
                    $warehouseKey = $warehouseId === null ? 'legacy' : (string)$warehouseId;
                    $itemKey = (int)$orderItem['id'] . ':' . (string)$line['sku'];
                    if (!isset($groups[$warehouseKey])) {
                        $groups[$warehouseKey] = ['warehouse_id' => $warehouseId, 'items' => []];
                    }
                    if (!isset($groups[$warehouseKey]['items'][$itemKey])) {
                        $groups[$warehouseKey]['items'][$itemKey] = [
                            'order_item_id' => (int)$orderItem['id'],
                            'sku' => (string)$line['sku'],
                            'quantity' => 0,
                        ];
                    }
                    $groups[$warehouseKey]['items'][$itemKey]['quantity'] += (int)$allocation['qty'];
                }
            }
        }

        $shipmentIds = [];
        foreach ($groups as $warehouseKey => $group) {
            $key = "order:{$orderId}:warehouse:{$warehouseKey}";
            // Plan only what earlier (e.g. partial admin) shipments have not covered yet.
            $items = [];
            foreach ($group['items'] as $item) {
                $item['quantity'] -= self::plannedQuantity($orderId, $item['order_item_id'], $item['sku'], $group['warehouse_id'], $key);
                if ($item['quantity'] > 0) $items[] = $item;
            }
            if ($items) {
                $shipmentIds[] = self::createShipment($orderId, $group['warehouse_id'], $items, $key);
            }
        }
        return $shipmentIds;
    }

    /**
     * Paid or processing orders ship; so does an unpaid cash-on-delivery order (the carrier collects on delivery)
     * or purchase on invoice (paid by the invoice's due date).
     */
    public static function orderShippable(array $order): bool {
        return in_array($order['status'], ['paid', 'processing'], true)
            || ($order['status'] === 'pending' && OrderWorkflow::paidAfterFulfillment($order));
    }

    /**
     * Open quantities per warehouse for new shipments of an order in the active store:
     * [['warehouse_id' => ?int, 'lines' => [['order_item_id', 'sku', 'name', 'allocated', 'planned', 'remaining']]]].
     */
    public static function shippable(int $orderId): array {
        if (!Orders::byId($orderId)) return [];
        $groups = [];
        foreach (Orders::items($orderId) as $orderItem) {
            foreach (Inventory::reservationLines($orderItem) as $line) {
                $sku = (string)$line['sku'];
                $warehouses = [];
                foreach ($line['allocations'] ?? [] as $allocation) {
                    if (!in_array((string)($allocation['status'] ?? 'reserved'), ['reserved', 'legacy_variant'], true)) continue;
                    $warehouses[empty($allocation['warehouse_id']) ? 'legacy' : (string)(int)$allocation['warehouse_id']] = true;
                }
                foreach (array_keys($warehouses) as $warehouseKey) {
                    $warehouseId = $warehouseKey === 'legacy' ? null : (int)$warehouseKey;
                    $allocated = self::allocatedQuantity($orderItem, $sku, $warehouseId);
                    $planned = self::plannedQuantity($orderId, (int)$orderItem['id'], $sku, $warehouseId);
                    $groups[$warehouseKey] ??= ['warehouse_id' => $warehouseId, 'lines' => []];
                    $groups[$warehouseKey]['lines'][] = [
                        'order_item_id' => (int)$orderItem['id'], 'sku' => $sku, 'name' => (string)$orderItem['name'],
                        'allocated' => $allocated, 'planned' => $planned, 'remaining' => max(0, $allocated - $planned),
                    ];
                }
            }
        }
        return array_values($groups);
    }

    /** Explicit partial shipment; quantities cannot exceed the frozen reservation snapshot. */
    public static function createShipment(
        int $orderId,
        ?int $warehouseId,
        array $items,
        string $idempotencyKey
    ): int {
        if (!$items || trim($idempotencyKey) === '') throw new InvalidArgumentException('Shipment items and idempotency key are required');
        if (!Orders::byId($orderId)) throw new InvalidArgumentException("Order #{$orderId} not found");
        $db = Db::get();
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            // Two concurrent partial shipments must not both pass the capacity check below.
            Orders::lockWithin($orderId);
            $insert = $db->prepare(Db::insertIgnore() . " INTO shipments
                (store_id, order_id, warehouse_id, status, idempotency_key)
                VALUES (:store, :order, :warehouse, 'planned', :key)");
            $insert->execute([
                ':store' => StoreContext::current()->id(), ':order' => $orderId,
                ':warehouse' => $warehouseId, ':key' => $idempotencyKey,
            ]);
            $replayStatus = 'planned';
            if ($insert->rowCount() === 1) {
                $shipmentId = (int)$db->lastInsertId();
            } else {
                $existing = $db->prepare('SELECT id, order_id, status FROM shipments WHERE store_id = :store AND idempotency_key = :key');
                $existing->execute([':store' => StoreContext::current()->id(), ':key' => $idempotencyKey]);
                $row = $existing->fetch(PDO::FETCH_ASSOC) ?: [];
                if ($row && (int)$row['order_id'] !== $orderId) throw new RuntimeException('Shipment idempotency key belongs to another order');
                $shipmentId = (int)($row['id'] ?? 0);
                $replayStatus = (string)($row['status'] ?? '');
            }
            if ($shipmentId <= 0) throw new RuntimeException('Shipment could not be resolved');

            // MySQL REPEATABLE READ: a caller may have read in this transaction before the lock, so plain SELECTs
            // would see that stale snapshot. Locking reads see the latest committed rows (allocations, shipments).
            $orderItems = [];
            $itemsStmt = $db->prepare('SELECT * FROM order_items WHERE order_id = :order ORDER BY id' . (Db::isMysql() ? ' FOR UPDATE' : ''));
            $itemsStmt->execute([':order' => $orderId]);
            foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $orderItem) $orderItems[(int)$orderItem['id']] = $orderItem;
            $itemInsert = $db->prepare(Db::insertIgnore() . " INTO shipment_items
                (shipment_id, order_item_id, sku, quantity) VALUES (:shipment, :item, :sku, :quantity)");
            foreach ($items as $item) {
                $orderItemId = (int)($item['order_item_id'] ?? 0);
                $sku = trim((string)($item['sku'] ?? ''));
                $quantity = (int)($item['quantity'] ?? 0);
                if (!isset($orderItems[$orderItemId]) || $quantity <= 0 || $sku === '') {
                    throw new InvalidArgumentException('Invalid shipment item');
                }
                $capacity = self::allocatedQuantity($orderItems[$orderItemId], $sku, $warehouseId);
                $alreadyPlanned = self::plannedQuantity($orderId, $orderItemId, $sku, $warehouseId, null, true);
                $currentStmt = $db->prepare('SELECT quantity FROM shipment_items WHERE shipment_id = :shipment AND order_item_id = :item AND sku = :sku');
                $currentStmt->execute([':shipment' => $shipmentId, ':item' => $orderItemId, ':sku' => $sku]);
                $current = (int)($currentStmt->fetchColumn() ?: 0);
                if (($current > 0 && $current !== $quantity) || ($current === 0 && $replayStatus !== 'planned')) {
                    throw new RuntimeException('Shipment idempotency key was reused with different quantities');
                }
                if ($alreadyPlanned - $current + $quantity > $capacity) throw new RuntimeException("Shipment exceeds reserved allocation for SKU: {$sku}");
                $itemInsert->execute([':shipment' => $shipmentId, ':item' => $orderItemId, ':sku' => $sku, ':quantity' => $quantity]);
            }
            if ($own) $db->commit();
            return $shipmentId;
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** Ship one planned fulfillment exactly once. */
    public static function ship(int $shipmentId, ?string $carrier = null, ?string $trackingNumber = null, ?string $trackingUrl = null): bool {
        [$carrier, $trackingNumber, $trackingUrl] = self::tracking($carrier, $trackingNumber, $trackingUrl);
        $db = Db::get();
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            // Ship and cancel of the same order are serialised on the order row (Orders::lockWithin()).
            $orderStmt = $db->prepare('SELECT order_id FROM shipments WHERE id = :id AND store_id = :store');
            $orderStmt->execute([':id' => $shipmentId, ':store' => StoreContext::current()->id()]);
            $lockOrder = (int)$orderStmt->fetchColumn();
            if ($lockOrder > 0) Orders::lockWithin($lockOrder);
            $shipmentStmt = $db->prepare('SELECT * FROM shipments WHERE id = :id AND store_id = :store');
            $shipmentStmt->execute([':id' => $shipmentId, ':store' => StoreContext::current()->id()]);
            $shipment = $shipmentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$shipment) throw new RuntimeException("Shipment #{$shipmentId} not found");
            $claim = $db->prepare("UPDATE shipments SET status = 'shipping', updated_at = " . Db::now() . "
                WHERE id = :id AND status = 'planned'");
            $claim->execute([':id' => $shipmentId]);
            if ($claim->rowCount() === 0) {
                if ($own) $db->rollBack();
                return false;
            }

            $items = $db->prepare('SELECT * FROM shipment_items WHERE shipment_id = :shipment AND status = :status ORDER BY id');
            $items->execute([':shipment' => $shipmentId, ':status' => 'planned']);
            $bySku = [];
            foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $sku = (string)$item['sku'];
                $bySku[$sku] = ($bySku[$sku] ?? 0) + (int)$item['quantity'];
            }
            if (!$bySku) throw new RuntimeException('Shipment has no planned items');
            if ($shipment['warehouse_id'] !== null) {
                foreach ($bySku as $sku => $quantity) {
                    Warehouse::ship($sku, [
                        'warehouse_id' => (int)$shipment['warehouse_id'],
                        'qty' => $quantity,
                    ], $shipmentId);
                }
            }
            $db->prepare("UPDATE shipment_items SET status = 'shipped' WHERE shipment_id = :shipment AND status = 'planned'")
                ->execute([':shipment' => $shipmentId]);
            // The cash on delivery the parcel leaves with is recorded, so another parcel never collects it again (R11 H9).
            $order = Db::hasColumn('shipments', 'cod_amount') ? Orders::byId((int)$shipment['order_id']) : null;
            $cod = $order !== null ? ShippingService::parcelCodAmount($order, $shipmentId) : null;
            $db->prepare("UPDATE shipments SET status = 'shipped', carrier = :carrier,
                    tracking_number = :tracking, tracking_url = :url, shipped_at = " . Db::now() . ", updated_at = " . Db::now()
                    . ($cod !== null ? ', cod_amount = COALESCE(cod_amount, :cod)' : '') . "
                WHERE id = :id")
                ->execute([':carrier' => $carrier, ':tracking' => $trackingNumber, ':url' => $trackingUrl, ':id' => $shipmentId]
                    + ($cod !== null ? [':cod' => $cod] : []));
            self::refreshOrderItemStatuses((int)$shipment['order_id']);
            if ($own) $db->commit();
            return true;
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** Compatibility path for an all-items order transition. */
    public static function shipOrder(int $orderId): array {
        $stmt = Db::get()->prepare('SELECT COALESCE(SUM(backordered_qty), 0) FROM order_items WHERE order_id = :order');
        $stmt->execute([':order' => $orderId]);
        if ((int)$stmt->fetchColumn() > 0) throw new RuntimeException('Backordered items require allocation before final shipment');
        self::planOrder($orderId);
        // Ship everything still planned, including partial shipments created earlier in the admin.
        $ids = [];
        foreach (self::shipments($orderId) as $shipment) {
            if ($shipment['status'] !== 'planned') continue;
            $ids[] = (int)$shipment['id'];
            self::ship((int)$shipment['id']);
        }
        return $ids;
    }

    /** Correct carrier/tracking of a shipped shipment of the active store; false when nothing changed. */
    public static function updateTracking(int $shipmentId, ?string $carrier, ?string $trackingNumber, ?string $trackingUrl): bool {
        [$carrier, $trackingNumber, $trackingUrl] = self::tracking($carrier, $trackingNumber, $trackingUrl);
        $stmt = Db::get()->prepare("UPDATE shipments SET carrier = :carrier, tracking_number = :tracking, tracking_url = :url,
                updated_at = " . Db::now() . "
            WHERE id = :id AND store_id = :store AND status = 'shipped'");
        $stmt->execute([':carrier' => $carrier, ':tracking' => $trackingNumber, ':url' => $trackingUrl,
            ':id' => $shipmentId, ':store' => StoreContext::current()->id()]);
        return $stmt->rowCount() === 1;
    }

    /** Cancel a planned (not yet shipped) shipment; its quantities become shippable again. */
    public static function cancel(int $shipmentId): bool {
        $db = Db::get();
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $stmt = $db->prepare("UPDATE shipments SET status = 'cancelled', updated_at = " . Db::now() . "
                WHERE id = :id AND store_id = :store AND status = 'planned'");
            $stmt->execute([':id' => $shipmentId, ':store' => StoreContext::current()->id()]);
            $cancelled = $stmt->rowCount() === 1;
            if ($cancelled) {
                $db->prepare("UPDATE shipment_items SET status = 'cancelled' WHERE shipment_id = :shipment AND status = 'planned'")
                    ->execute([':shipment' => $shipmentId]);
            }
            if ($own) $db->commit();
            return $cancelled;
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** @return array{0:?string,1:?string,2:?string} trimmed carrier, tracking number and http(s) tracking URL */
    private static function tracking(?string $carrier, ?string $trackingNumber, ?string $trackingUrl): array {
        $values = [];
        foreach ([$carrier, $trackingNumber, $trackingUrl] as $index => $value) {
            $value = trim((string)$value);
            if (preg_match('/[\x00-\x1F\x7F]/', $value) || mb_strlen($value) > ($index === 2 ? 500 : 100)) {
                throw new InvalidArgumentException('Invalid carrier or tracking value');
            }
            $values[] = $value === '' ? null : $value;
        }
        if ($values[2] !== null && (!filter_var($values[2], FILTER_VALIDATE_URL)
            || !in_array(strtolower((string)parse_url($values[2], PHP_URL_SCHEME)), ['http', 'https'], true))) {
            throw new InvalidArgumentException('Tracking URL must be an http(s) address');
        }
        return $values;
    }

    /** Quantity of an order line already in non-cancelled shipments of one warehouse (optionally ignoring one idempotency key). */
    private static function plannedQuantity(int $orderId, int $orderItemId, string $sku, ?int $warehouseId, ?string $exceptKey = null, bool $locking = false): int {
        $stmt = Db::get()->prepare("SELECT COALESCE(SUM(si.quantity), 0)
            FROM shipment_items si JOIN shipments s ON s.id = si.shipment_id
            WHERE s.order_id = :order AND si.order_item_id = :item AND si.sku = :sku AND si.status <> 'cancelled'
              AND ((s.warehouse_id = :warehouse) OR (s.warehouse_id IS NULL AND :warehouse2 IS NULL))
              AND s.idempotency_key <> :except" . ($locking && Db::isMysql() ? ' FOR UPDATE' : ''));
        $stmt->execute([
            ':order' => $orderId, ':item' => $orderItemId, ':sku' => $sku,
            ':warehouse' => $warehouseId, ':warehouse2' => $warehouseId, ':except' => $exceptKey ?? '',
        ]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Carrier, tracking number and tracking URL of the order's most recently shipped shipment, preferring one
     * that has tracking details (order e-mails).
     * By order id alone: the worker sends mail outside any store context.
     * @return array{carrier: ?string, tracking_number: ?string, tracking_url: ?string}|null
     */
    public static function latestTracking(int $orderId): ?array {
        $stmt = Db::get()->prepare("SELECT carrier, tracking_number, tracking_url FROM shipments
            WHERE order_id = :order AND status = 'shipped'
            ORDER BY CASE WHEN tracking_number IS NULL AND carrier IS NULL THEN 1 ELSE 0 END, shipped_at DESC, id DESC LIMIT 1");
        $stmt->execute([':order' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function shipments(int $orderId): array {
        $stmt = Db::get()->prepare('SELECT * FROM shipments WHERE order_id = :order AND store_id = :store ORDER BY id');
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Items of the given shipments (from shipments() of the active store), grouped by shipment id. */
    public static function shipmentItems(array $shipmentIds): array {
        $shipmentIds = array_values(array_unique(array_map('intval', $shipmentIds)));
        if (!$shipmentIds) return [];
        $stmt = Db::get()->prepare('SELECT shipment_id, order_item_id, sku, quantity, status FROM shipment_items
            WHERE shipment_id IN (' . implode(',', array_fill(0, count($shipmentIds), '?')) . ') ORDER BY id');
        $stmt->execute($shipmentIds);
        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $grouped[(int)$row['shipment_id']][] = $row;
        return $grouped;
    }

    private static function allocatedQuantity(array $orderItem, string $sku, ?int $warehouseId): int {
        $quantity = 0;
        foreach (Inventory::reservationLines($orderItem) as $line) {
            if ((string)$line['sku'] !== $sku) continue;
            foreach ($line['allocations'] ?? [] as $allocation) {
                $allocationWarehouse = empty($allocation['warehouse_id']) ? null : (int)$allocation['warehouse_id'];
                if ($allocationWarehouse === $warehouseId
                    && in_array(($allocation['status'] ?? 'reserved'), ['reserved', 'legacy_variant'], true)) {
                    $quantity += (int)$allocation['qty'];
                }
            }
        }
        return $quantity;
    }

    /** Units of an order line that left a warehouse: a bundle unit once every component of it shipped. */
    public static function shippedUnits(array $orderItem): int {
        $stmt = Db::get()->prepare("SELECT si.sku, COALESCE(SUM(si.quantity), 0) AS quantity FROM shipment_items si
            WHERE si.order_item_id = :item AND si.status = 'shipped' GROUP BY si.sku");
        $stmt->execute([':item' => (int)$orderItem['id']]);
        $shipped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $shipped[(string)$row['sku']] = (int)$row['quantity'];
        if (!$shipped) return 0;
        $units = (int)$orderItem['quantity'];
        foreach (Inventory::reservationLines($orderItem) as $line) {
            $units = min($units, intdiv($shipped[(string)$line['sku']] ?? 0, max(1, (int)($line['quantity_per_bundle'] ?? 1))));
        }
        return $units;
    }

    /**
     * Gross of the order's goods that never shipped, or null when nothing shipped. Each line's discounted gross (the
     * financial snapshot, as credit notes slice it) is split like InvoiceService::credit(): the shipped units are the
     * first slice, which a later RMA credit note refunds, so both refunds together never exceed the line.
     */
    public static function unshippedGross(int $orderId): ?int {
        $order = Orders::byId($orderId);
        if (!$order) return null;
        $snapshot = json_decode((string)($order['financial_snapshot'] ?? ''), true);
        $components = is_array($snapshot) ? array_values(array_filter((array)($snapshot['components'] ?? []),
            static fn($component): bool => is_array($component) && ($component['type'] ?? '') === 'product')) : [];
        $items = Orders::items($orderId);
        if (count($components) !== count($items)) $components = [];
        $anyShipped = false;
        $gross = 0;
        foreach ($items as $position => $item) {
            $ordered = max(1, (int)$item['quantity']);
            $shipped = min($ordered, self::shippedUnits($item));
            $anyShipped = $anyShipped || $shipped > 0;
            $line = (int)($components[$position]['gross'] ?? $item['total']);
            $gross += $line - intdiv($line * $shipped, $ordered);
        }
        return $anyShipped ? $gross : null;
    }

    /** Cancel the order's planned (not yet shipped) shipments: an order cancellation releases their stock. */
    public static function cancelPlanned(int $orderId): void {
        foreach (self::shipments($orderId) as $shipment) {
            if ($shipment['status'] === 'planned') self::cancel((int)$shipment['id']);
        }
    }

    public static function refreshOrderItemStatuses(int $orderId): void {
        $db = Db::get();
        foreach (Orders::items($orderId) as $item) {
            $stmt = $db->prepare("SELECT COALESCE(SUM(si.quantity), 0)
                FROM shipment_items si JOIN shipments s ON s.id = si.shipment_id
                WHERE s.order_id = :order AND si.order_item_id = :item AND si.status = 'shipped'");
            $stmt->execute([':order' => $orderId, ':item' => (int)$item['id']]);
            $shipped = (int)$stmt->fetchColumn();
            $expected = 0;
            foreach (Inventory::reservationLines($item) as $line) {
                foreach ($line['allocations'] ?? [] as $allocation) {
                    if (in_array(($allocation['status'] ?? 'reserved'), ['reserved', 'legacy_variant'], true)) {
                        $expected += (int)$allocation['qty'];
                    }
                }
            }
            $backordered = (int)($item['backordered_qty'] ?? 0);
            $status = $shipped >= $expected && $backordered === 0
                ? 'fulfilled'
                : ($shipped > 0 ? 'partially_fulfilled' : ($backordered > 0 ? 'backordered' : 'unfulfilled'));
            $db->prepare('UPDATE order_items SET fulfillment_status = :status WHERE id = :id')
                ->execute([':status' => $status, ':id' => (int)$item['id']]);
        }
    }
}
