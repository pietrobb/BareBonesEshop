<?php
/** Inventory policies, auditable movements, bundles and minimal procurement. */
class Inventory {
    public const DENY = 'deny';
    public const BACKORDER = 'backorder';
    public const PREORDER = 'preorder';

    public static function movement(
        ?int $warehouseId,
        string $sku,
        string $type,
        int $quantityDelta,
        int $reservedDelta,
        int $onHandAfter,
        int $reservedAfter,
        ?string $referenceType = null,
        string|int|null $referenceId = null,
        ?string $idempotencyKey = null,
        array $metadata = []
    ): bool {
        $stmt = Db::get()->prepare("INSERT INTO stock_movements
            (store_id, warehouse_id, sku, movement_type, quantity_delta, reserved_delta,
             on_hand_after, reserved_after, reference_type, reference_id, idempotency_key, metadata)
            VALUES (:store, :warehouse, :sku, :type, :quantity, :reserved, :on_hand, :reserved_after,
                    :reference_type, :reference_id, :idempotency, :metadata)");
        $stmt->execute([
            ':store' => StoreContext::current()->id(),
            ':warehouse' => $warehouseId,
            ':sku' => $sku,
            ':type' => $type,
            ':quantity' => $quantityDelta,
            ':reserved' => $reservedDelta,
            ':on_hand' => $onHandAfter,
            ':reserved_after' => $reservedAfter,
            ':reference_type' => $referenceType,
            ':reference_id' => $referenceId === null ? null : (string)$referenceId,
            ':idempotency' => $idempotencyKey,
            ':metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        return $stmt->rowCount() === 1;
    }

    /** Reserve a sellable line and return a frozen allocation snapshot. */
    public static function reserveLine(array $item, int $quantity): array {
        $productId = (int)$item['product_id'];
        $variantId = !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
        self::validatePersonalization($productId, (array)($item['attrs'] ?? []));

        $components = self::bundleComponents($productId);
        if ($components) {
            $snapshots = [];
            $reserved = $quantity;
            $backordered = 0;
            $preorderAt = null;
            foreach ($components as $component) {
                $required = $quantity * (int)$component['quantity'];
                $snapshot = self::reserveSku(
                    (int)$component['component_product_id'],
                    (int)$component['component_variant_id'] > 0 ? (int)$component['component_variant_id'] : null,
                    (string)$component['sku'],
                    $required
                );
                $snapshot['quantity_per_bundle'] = (int)$component['quantity'];
                $snapshots[] = $snapshot;
                if ($snapshot['backordered_qty'] > 0) {
                    $backordered = max($backordered, (int)ceil($snapshot['backordered_qty'] / max(1, (int)$component['quantity'])));
                }
                $preorderAt = self::laterDate($preorderAt, $snapshot['preorder_at']);
            }
            $reserved = max(0, $quantity - $backordered);
            return [
                'allocations' => [],
                'bundle_components' => $snapshots,
                'reserved_qty' => $reserved,
                'backordered_qty' => $backordered,
                'preorder_at' => $preorderAt,
            ];
        }

        $snapshot = self::reserveSku($productId, $variantId, (string)$item['sku'], $quantity);
        return [
            'allocations' => $snapshot['allocations'],
            'bundle_components' => [],
            'reserved_qty' => $snapshot['reserved_qty'],
            'backordered_qty' => $snapshot['backordered_qty'],
            'preorder_at' => $snapshot['preorder_at'],
        ];
    }

    /** Frozen component/allocation lines encoded on an order item. */
    public static function reservationLines(array $orderItem): array {
        $attrs = json_decode((string)($orderItem['attrs'] ?? '{}'), true) ?: [];
        if (!empty($attrs['_bundle_components'])) return $attrs['_bundle_components'];
        $allocations = $attrs['_inventory_allocations'] ?? $attrs['_warehouse_allocations'] ?? [];
        if (!$allocations && !empty($orderItem['variant_id'])) {
            // Pre-ledger orders decremented variant stock at checkout without an allocation snapshot.
            $allocations[] = [
                'warehouse_id' => null, 'warehouse_code' => null,
                'qty' => (int)$orderItem['quantity'], 'status' => 'legacy_variant',
            ];
        }
        return [[
            'product_id' => (int)$orderItem['product_id'],
            'variant_id' => $orderItem['variant_id'] === null ? null : (int)$orderItem['variant_id'],
            'sku' => (string)$orderItem['sku'],
            'allocations' => $allocations,
            'reserved_qty' => (int)($orderItem['reserved_qty'] ?? $orderItem['quantity']),
            'backordered_qty' => (int)($orderItem['backordered_qty'] ?? 0),
            'preorder_at' => $orderItem['preorder_at'] ?? null,
        ]];
    }

    public static function releaseOrderItem(array $orderItem, string|int $referenceId): void {
        $hasAllocationSnapshot = false;
        foreach (self::reservationLines($orderItem) as $line) {
            foreach ($line['allocations'] ?? [] as $allocation) {
                $hasAllocationSnapshot = true;
                $status = (string)($allocation['status'] ?? 'reserved');
                $warehouseId = empty($allocation['warehouse_id']) ? null : (int)$allocation['warehouse_id'];
                $remaining = (int)$allocation['qty'] - self::shippedQuantity((int)$orderItem['id'], (string)$line['sku'], $warehouseId);
                if ($remaining <= 0) continue;
                $allocation['qty'] = $remaining;
                if ($status === 'reserved' && $warehouseId !== null) {
                    Warehouse::release((string)$line['sku'], [$allocation], 'order', $referenceId);
                } elseif ($status === 'legacy_variant') {
                    self::restoreLegacyVariant($line, $remaining, 'release', $referenceId);
                }
            }
        }
        if (!$hasAllocationSnapshot && empty($orderItem['variant_id'])) {
            Products::incrementStock((int)$orderItem['product_id'], (int)$orderItem['quantity']);
        }
    }

    public static function returnOrderItem(array $orderItem, string|int $referenceId): void {
        $hasShipmentHistory = self::hasShipmentHistory((int)$orderItem['id']);
        $hasAllocationSnapshot = false;
        foreach (self::reservationLines($orderItem) as $line) {
            foreach ($line['allocations'] ?? [] as $allocation) {
                $hasAllocationSnapshot = true;
                $status = (string)($allocation['status'] ?? 'reserved');
                $warehouseId = empty($allocation['warehouse_id']) ? null : (int)$allocation['warehouse_id'];
                $quantity = $hasShipmentHistory
                    ? min((int)$allocation['qty'], self::shippedQuantity((int)$orderItem['id'], (string)$line['sku'], $warehouseId))
                    : (int)$allocation['qty'];
                if ($quantity <= 0) continue;
                $allocation['qty'] = $quantity;
                if ($status === 'reserved' && $warehouseId !== null) {
                    Warehouse::restock((string)$line['sku'], [$allocation], 'order', $referenceId);
                } elseif ($status === 'legacy_variant') {
                    self::restoreLegacyVariant($line, $quantity, 'return', $referenceId);
                }
            }
        }
        if (!$hasAllocationSnapshot && empty($orderItem['variant_id'])) {
            Products::incrementStock((int)$orderItem['product_id'], (int)$orderItem['quantity']);
        }
    }

    /** Supplier PO creation enforces configured minimum and pack size. */
    public static function createPurchaseOrder(
        int $supplierId,
        int $warehouseId,
        string $number,
        array $items,
        string $currency = 'eur',
        ?string $expectedAt = null
    ): int {
        if (!$items) throw new InvalidArgumentException('Purchase order requires items');
        $db = Db::get();
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $stmt = $db->prepare("INSERT INTO purchase_orders
                (store_id, supplier_id, warehouse_id, number, status, currency, expected_at)
                VALUES (:store, :supplier, :warehouse, :number, 'ordered', :currency, :expected)");
            $stmt->execute([
                ':store' => StoreContext::current()->id(), ':supplier' => $supplierId,
                ':warehouse' => $warehouseId, ':number' => $number,
                ':currency' => strtolower($currency), ':expected' => $expectedAt,
            ]);
            $purchaseOrderId = (int)$db->lastInsertId();
            $rules = $db->prepare('SELECT min_order_qty, pack_size, cost_price FROM supplier_products WHERE supplier_id = :supplier AND sku = :sku');
            $insert = $db->prepare('INSERT INTO purchase_order_items (purchase_order_id, sku, quantity, unit_cost) VALUES (:po, :sku, :quantity, :cost)');
            foreach ($items as $item) {
                $sku = trim((string)($item['sku'] ?? ''));
                $quantity = (int)($item['quantity'] ?? 0);
                $rules->execute([':supplier' => $supplierId, ':sku' => $sku]);
                $rule = $rules->fetch(PDO::FETCH_ASSOC);
                if (!$rule) throw new InvalidArgumentException("Supplier does not supply SKU {$sku}");
                $minimum = max(1, (int)$rule['min_order_qty']);
                $pack = max(1, (int)$rule['pack_size']);
                if ($quantity < $minimum || $quantity % $pack !== 0) {
                    throw new InvalidArgumentException("SKU {$sku} violates supplier minimum or pack size");
                }
                $insert->execute([
                    ':po' => $purchaseOrderId, ':sku' => $sku, ':quantity' => $quantity,
                    ':cost' => isset($item['unit_cost']) ? (int)$item['unit_cost'] : (int)$rule['cost_price'],
                ]);
            }
            if ($own) $db->commit();
            return $purchaseOrderId;
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** Idempotent partial receipt updates the PO and physical ledger together. */
    public static function receivePurchaseOrder(int $purchaseOrderId, string $sku, int $quantity, string $idempotencyKey): bool {
        if ($quantity <= 0 || trim($idempotencyKey) === '') throw new InvalidArgumentException('Receipt quantity and idempotency key are required');
        $db = Db::get();
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT po.warehouse_id, poi.id, poi.quantity, poi.received_qty
                FROM purchase_orders po JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
                WHERE po.id = :po AND poi.sku = :sku AND po.store_id = :store");
            $stmt->execute([':po' => $purchaseOrderId, ':sku' => $sku, ':store' => StoreContext::current()->id()]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) throw new RuntimeException('Purchase order item not found');
            if ((int)$item['received_qty'] + $quantity > (int)$item['quantity']) throw new RuntimeException('Receipt exceeds ordered quantity');

            $claim = $db->prepare(Db::insertIgnore() . " INTO inventory_receipts
                (store_id, purchase_order_id, purchase_order_item_id, warehouse_id, sku, quantity, idempotency_key)
                VALUES (:store, :po, :item, :warehouse, :sku, :quantity, :key)");
            $claim->execute([
                ':store' => StoreContext::current()->id(), ':po' => $purchaseOrderId,
                ':item' => (int)$item['id'], ':warehouse' => (int)$item['warehouse_id'],
                ':sku' => $sku, ':quantity' => $quantity, ':key' => $idempotencyKey,
            ]);
            if ($claim->rowCount() === 0) {
                if ($own) $db->rollBack();
                return false;
            }
            $db->prepare('UPDATE purchase_order_items SET received_qty = received_qty + :quantity WHERE id = :id')
                ->execute([':quantity' => $quantity, ':id' => (int)$item['id']]);
            Warehouse::adjustStock((int)$item['warehouse_id'], $sku, $quantity, 'receipt', 'purchase_order', $purchaseOrderId, 'receipt:' . $idempotencyKey);
            $remaining = (int)$db->query("SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id = {$purchaseOrderId} AND received_qty < quantity")->fetchColumn();
            $status = $remaining === 0 ? 'received' : 'partially_received';
            $db->prepare('UPDATE purchase_orders SET status = :status, updated_at = ' . Db::now() . ' WHERE id = :id')
                ->execute([':status' => $status, ':id' => $purchaseOrderId]);
            if ($own) $db->commit();
            return true;
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private static function reserveSku(int $productId, ?int $variantId, string $sku, int $quantity): array {
        $db = Db::get();
        $stmt = $db->prepare('SELECT inventory_policy, preorder_at FROM products WHERE id = :id AND store_id = :store');
        $stmt->execute([':id' => $productId, ':store' => StoreContext::current()->catalogId()]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) throw new RuntimeException("Product {$productId} not found");
        $policy = (string)$product['inventory_policy'];
        $preorderAt = $product['preorder_at'] ?: null;
        if ($variantId !== null) {
            $stmt = $db->prepare('SELECT inventory_policy, preorder_at, stock FROM variants WHERE id = :id AND product_id = :product');
            $stmt->execute([':id' => $variantId, ':product' => $productId]);
            $variant = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$variant) throw new RuntimeException("Variant {$variantId} not found");
            if (($variant['inventory_policy'] ?? 'inherit') !== 'inherit') $policy = (string)$variant['inventory_policy'];
            if (!empty($variant['preorder_at'])) $preorderAt = $variant['preorder_at'];
        }
        if (!in_array($policy, [self::DENY, self::BACKORDER, self::PREORDER], true)) throw new RuntimeException('Invalid inventory policy');

        $countStmt = $db->prepare('SELECT COUNT(*) FROM warehouse_stock WHERE sku = :sku AND store_id = :store');
        $countStmt->execute([':sku' => $sku, ':store' => StoreContext::current()->id()]);
        if ((int)$countStmt->fetchColumn() > 0 || $variantId === null) {
            $allocations = Warehouse::reserve($sku, $quantity, $policy);
            $reserved = 0;
            $backordered = 0;
            foreach ($allocations as $allocation) {
                if (($allocation['status'] ?? 'reserved') === 'reserved') $reserved += (int)$allocation['qty'];
                else $backordered += (int)$allocation['qty'];
            }
            Warehouse::syncProductStock([$sku]);
            return [
                'product_id' => $productId, 'variant_id' => $variantId, 'sku' => $sku,
                'allocations' => $allocations,
                'reserved_qty' => $reserved, 'backordered_qty' => $backordered,
                'preorder_at' => $policy === self::PREORDER ? $preorderAt : null,
            ];
        }

        $stockStmt = $db->prepare('SELECT stock FROM variants WHERE id = :id');
        $stockStmt->execute([':id' => $variantId]);
        $available = max(0, (int)$stockStmt->fetchColumn());
        if ($available < $quantity && $policy === self::DENY) throw new RuntimeException("Nedostatok na sklade pre SKU: {$sku}");
        $reserved = min($available, $quantity);
        if ($reserved > 0) {
            $update = $db->prepare('UPDATE variants SET stock = stock - :quantity WHERE id = :id AND stock >= :quantity2');
            foreach ([':quantity', ':quantity2'] as $parameter) $update->bindValue($parameter, $reserved, PDO::PARAM_INT);
            $update->bindValue(':id', $variantId, PDO::PARAM_INT);
            $update->execute();
            if ($update->rowCount() !== 1) throw new RuntimeException("Concurrent stock change for SKU: {$sku}");
            self::movement(null, $sku, 'reserve_legacy_variant', -$reserved, 0, $available - $reserved, 0, 'checkout', null, null, ['variant_id' => $variantId]);
        }
        $allocations = $reserved > 0 ? [['warehouse_id' => null, 'warehouse_code' => null, 'qty' => $reserved, 'status' => 'legacy_variant']] : [];
        $backordered = $quantity - $reserved;
        if ($backordered > 0) $allocations[] = ['warehouse_id' => null, 'warehouse_code' => null, 'qty' => $backordered, 'status' => $policy];
        return [
            'product_id' => $productId, 'variant_id' => $variantId, 'sku' => $sku,
            'allocations' => $allocations, 'reserved_qty' => $reserved,
            'backordered_qty' => $backordered, 'preorder_at' => $policy === self::PREORDER ? $preorderAt : null,
        ];
    }

    private static function bundleComponents(int $productId): array {
        $stmt = Db::get()->prepare("SELECT bc.component_product_id, bc.component_variant_id, bc.quantity,
                COALESCE(v.sku, p.sku) AS sku
            FROM bundle_components bc
            JOIN products p ON p.id = bc.component_product_id AND p.store_id = :store
            LEFT JOIN variants v ON v.id = bc.component_variant_id AND v.product_id = p.id
            WHERE bc.bundle_product_id = :bundle ORDER BY bc.sort_order, bc.component_product_id");
        $stmt->execute([':store' => StoreContext::current()->catalogId(), ':bundle' => $productId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function validatePersonalization(int $productId, array $attrs): void {
        $stmt = Db::get()->prepare('SELECT personalization_schema FROM products WHERE id = :id AND store_id = :store');
        $stmt->execute([':id' => $productId, ':store' => StoreContext::current()->catalogId()]);
        $schema = json_decode((string)$stmt->fetchColumn(), true) ?: [];
        $values = is_array($attrs['personalization'] ?? null) ? $attrs['personalization'] : [];
        foreach ((array)($schema['required'] ?? []) as $required) {
            if (!array_key_exists((string)$required, $values) || trim((string)$values[$required]) === '') {
                throw new InvalidArgumentException("Missing personalization field: {$required}");
            }
        }
    }

    private static function restoreLegacyVariant(array $line, int $quantity, string $type, string|int $referenceId): void {
        $variantId = (int)($line['variant_id'] ?? 0);
        if ($variantId <= 0 || $quantity <= 0) return;
        $db = Db::get();
        $db->prepare('UPDATE variants SET stock = stock + :quantity WHERE id = :id')
            ->execute([':quantity' => $quantity, ':id' => $variantId]);
        $stmt = $db->prepare('SELECT stock FROM variants WHERE id = :id');
        $stmt->execute([':id' => $variantId]);
        self::movement(null, (string)$line['sku'], $type, $quantity, 0, (int)$stmt->fetchColumn(), 0, 'order', $referenceId, null, ['variant_id' => $variantId]);
    }

    private static function shippedQuantity(int $orderItemId, string $sku, ?int $warehouseId): int {
        $stmt = Db::get()->prepare("SELECT COALESCE(SUM(si.quantity), 0)
            FROM shipment_items si JOIN shipments s ON s.id = si.shipment_id
            WHERE si.order_item_id = :item AND si.sku = :sku AND si.status = 'shipped'
              AND ((s.warehouse_id = :warehouse) OR (s.warehouse_id IS NULL AND :warehouse2 IS NULL))");
        $stmt->execute([
            ':item' => $orderItemId, ':sku' => $sku,
            ':warehouse' => $warehouseId, ':warehouse2' => $warehouseId,
        ]);
        return (int)$stmt->fetchColumn();
    }

    private static function hasShipmentHistory(int $orderItemId): bool {
        $stmt = Db::get()->prepare('SELECT COUNT(*) FROM shipment_items WHERE order_item_id = :item');
        $stmt->execute([':item' => $orderItemId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function laterDate(?string $left, ?string $right): ?string {
        if ($left === null) return $right;
        if ($right === null) return $left;
        return strtotime($left) >= strtotime($right) ? $left : $right;
    }
}
