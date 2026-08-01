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
            $shipmentIds[] = self::createShipment(
                $orderId,
                $group['warehouse_id'],
                array_values($group['items']),
                "order:{$orderId}:warehouse:{$warehouseKey}"
            );
        }
        return $shipmentIds;
    }

    /** Explicit partial shipment; quantities cannot exceed the frozen reservation snapshot. */
    public static function createShipment(
        int $orderId,
        ?int $warehouseId,
        array $items,
        string $idempotencyKey
    ): int {
        if (!$items || trim($idempotencyKey) === '') throw new InvalidArgumentException('Shipment items and idempotency key are required');
        $db = Db::get();
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $insert = $db->prepare(Db::insertIgnore() . " INTO shipments
                (store_id, order_id, warehouse_id, status, idempotency_key)
                VALUES (:store, :order, :warehouse, 'planned', :key)");
            $insert->execute([
                ':store' => StoreContext::current()->id(), ':order' => $orderId,
                ':warehouse' => $warehouseId, ':key' => $idempotencyKey,
            ]);
            if ($insert->rowCount() === 1) {
                $shipmentId = (int)$db->lastInsertId();
            } else {
                $existing = $db->prepare('SELECT id FROM shipments WHERE store_id = :store AND idempotency_key = :key');
                $existing->execute([':store' => StoreContext::current()->id(), ':key' => $idempotencyKey]);
                $shipmentId = (int)$existing->fetchColumn();
            }
            if ($shipmentId <= 0) throw new RuntimeException('Shipment could not be resolved');

            $orderItems = [];
            foreach (Orders::items($orderId) as $orderItem) $orderItems[(int)$orderItem['id']] = $orderItem;
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
                $plannedStmt = $db->prepare("SELECT COALESCE(SUM(si.quantity), 0)
                    FROM shipment_items si JOIN shipments s ON s.id = si.shipment_id
                    WHERE s.order_id = :order AND si.order_item_id = :item AND si.sku = :sku
                      AND ((s.warehouse_id = :warehouse) OR (s.warehouse_id IS NULL AND :warehouse2 IS NULL))");
                $plannedStmt->execute([
                    ':order' => $orderId, ':item' => $orderItemId, ':sku' => $sku,
                    ':warehouse' => $warehouseId, ':warehouse2' => $warehouseId,
                ]);
                $alreadyPlanned = (int)$plannedStmt->fetchColumn();
                $currentStmt = $db->prepare('SELECT quantity FROM shipment_items WHERE shipment_id = :shipment AND order_item_id = :item AND sku = :sku');
                $currentStmt->execute([':shipment' => $shipmentId, ':item' => $orderItemId, ':sku' => $sku]);
                $current = (int)($currentStmt->fetchColumn() ?: 0);
                if ($current > 0 && $current !== $quantity) throw new RuntimeException('Shipment idempotency key was reused with different quantities');
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
    public static function ship(int $shipmentId, ?string $carrier = null, ?string $trackingNumber = null): bool {
        $db = Db::get();
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
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
            $db->prepare("UPDATE shipments SET status = 'shipped', carrier = :carrier,
                    tracking_number = :tracking, shipped_at = " . Db::now() . ", updated_at = " . Db::now() . "
                WHERE id = :id")
                ->execute([':carrier' => $carrier, ':tracking' => $trackingNumber, ':id' => $shipmentId]);
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
        $ids = self::planOrder($orderId);
        foreach ($ids as $id) self::ship($id);
        return $ids;
    }

    public static function shipments(int $orderId): array {
        $stmt = Db::get()->prepare('SELECT * FROM shipments WHERE order_id = :order AND store_id = :store ORDER BY id');
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

    private static function refreshOrderItemStatuses(int $orderId): void {
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
