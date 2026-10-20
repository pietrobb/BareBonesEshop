<?php
/**
 * Backorder and preorder lines of open orders get stock once it arrives: a checkout shortfall is frozen on the order
 * line as an allocation without a warehouse (status backorder/preorder), which no shipment can take. Whenever available
 * stock of a SKU grows (receipt, adjustment, feed, return, released reservation) the waiting lines are reserved from
 * it, oldest order first, and become shippable.
 */
class BackorderAllocation {
    private const WAITING = [Inventory::BACKORDER, Inventory::PREORDER];

    /** Reserve available stock of these SKUs for waiting order lines of the active store. Returns the units allocated. */
    public static function allocate(array $skus): int {
        $skus = array_values(array_unique(array_filter(array_map('strval', $skus), static fn(string $sku): bool => $sku !== '')));
        if (!$skus) return 0;
        $db = Db::get();
        $candidates = $db->prepare("SELECT oi.id, oi.order_id, oi.sku, oi.attrs FROM order_items oi JOIN orders o ON o.id = oi.order_id
            WHERE o.store_id = :store AND oi.backordered_qty > 0 AND o.stock_restored = 0
              AND o.status IN ('pending', 'paid', 'processing')
            ORDER BY o.created_at, o.id, oi.id");
        $candidates->execute([':store' => StoreContext::current()->id()]);
        $rows = $candidates->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return 0;

        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $allocated = 0;
            $touched = [];
            $wanted = array_flip($skus);
            foreach ($rows as $row) {
                if (!array_intersect_key(array_flip(self::lineSkus($row)), $wanted)) continue;
                if (!self::lockUnlessHeld((int)$row['order_id'])) continue;
                $item = self::orderItem((int)$row['id']);
                if ($item === null || (int)$item['backordered_qty'] <= 0) continue;
                $units = self::allocateItem($item, $skus);
                if ($units > 0) {
                    $allocated += $units;
                    $touched[(int)$row['order_id']] = true;
                }
            }
            foreach (array_keys($touched) as $orderId) Fulfillment::refreshOrderItemStatuses($orderId);
            if ($allocated > 0) Warehouse::syncProductStock($skus);
            if ($own) $db->commit();
            return $allocated;
        } catch (Throwable $e) {
            if ($own && Db::get()->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * The caller may already hold warehouse stock rows, while a shipment or status change locks its order first and
     * stock second: waiting on that order could deadlock. An order another transaction holds is skipped; the next
     * stock increase allocates it. SQLite serializes writers, so there is nothing to skip.
     */
    private static function lockUnlessHeld(int $orderId): bool {
        if (!Db::isMysql()) {
            Orders::lockWithin($orderId);
            return true;
        }
        $stmt = Db::get()->prepare('SELECT id FROM orders WHERE id = :id AND store_id = :store FOR UPDATE SKIP LOCKED');
        $stmt->execute([':id' => $orderId, ':store' => StoreContext::current()->id()]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return list<string> the SKUs an order line reserves (bundle components or the line itself) */
    private static function lineSkus(array $item): array {
        $attrs = json_decode((string)($item['attrs'] ?? '{}'), true) ?: [];
        if (empty($attrs['_bundle_components'])) return [(string)$item['sku']];
        return array_map(static fn(array $line): string => (string)$line['sku'], $attrs['_bundle_components']);
    }

    private static function orderItem(int $orderItemId): ?array {
        $stmt = Db::get()->prepare("SELECT oi.* FROM order_items oi JOIN orders o ON o.id = oi.order_id
            WHERE oi.id = :id AND o.store_id = :store AND o.stock_restored = 0 AND o.status IN ('pending', 'paid', 'processing')" . (Db::isMysql() ? ' FOR UPDATE' : ''));
        $stmt->execute([':id' => $orderItemId, ':store' => StoreContext::current()->id()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Reserve the waiting quantities of one order line for these SKUs and store the new allocation snapshot. */
    private static function allocateItem(array $item, array $skus): int {
        $attrs = json_decode((string)($item['attrs'] ?? '{}'), true) ?: [];
        $isBundle = !empty($attrs['_bundle_components']);
        $lines = $isBundle ? $attrs['_bundle_components'] : [[
            'sku' => (string)$item['sku'],
            'allocations' => $attrs['_inventory_allocations'] ?? $attrs['_warehouse_allocations'] ?? [],
        ]];
        $units = 0;
        foreach ($lines as $index => $line) {
            if (!in_array((string)$line['sku'], $skus, true)) continue;
            [$lines[$index]['allocations'], $taken] = self::reserveWaiting((string)$line['sku'], (array)($line['allocations'] ?? []));
            $units += $taken;
        }
        if ($units === 0) return 0;

        $quantity = (int)$item['quantity'];
        if ($isBundle) {
            $backordered = 0;
            foreach ($lines as $index => $line) {
                $waiting = self::waitingQuantity($line['allocations']);
                $lines[$index]['backordered_qty'] = $waiting;
                $lines[$index]['reserved_qty'] = max(0, (int)array_sum(array_column($line['allocations'], 'qty')) - $waiting);
                $backordered = max($backordered, (int)ceil($waiting / max(1, (int)($line['quantity_per_bundle'] ?? 1))));
            }
            $attrs['_bundle_components'] = $lines;
        } else {
            $backordered = self::waitingQuantity($lines[0]['allocations']);
            unset($attrs['_warehouse_allocations']);
            $attrs['_inventory_allocations'] = $lines[0]['allocations'];
        }
        Db::get()->prepare('UPDATE order_items SET attrs = :attrs, reserved_qty = :reserved, backordered_qty = :backordered WHERE id = :id')
            ->execute([
                ':attrs' => json_encode($attrs), ':reserved' => max(0, $quantity - $backordered),
                ':backordered' => $backordered, ':id' => (int)$item['id'],
            ]);
        AuditLog::log('order_backorder_allocated', (int)$item['order_id'], null, null, "Order line {$item['id']}: {$units} units reserved after restock");
        return $units;
    }

    /**
     * Reserve what is available now for the waiting allocations of one SKU: they shrink by what was reserved
     * and the warehouse reservations are appended.
     *
     * @return array{0: list<array>, 1: int} the new allocations and the units reserved
     */
    private static function reserveWaiting(string $sku, array $allocations): array {
        $waiting = self::waitingQuantity($allocations);
        $take = min($waiting, Warehouse::totalAvailable($sku));
        if ($take <= 0) return [$allocations, 0];
        try {
            $reserved = Warehouse::reserve($sku, $take, Inventory::DENY);
        } catch (RuntimeException) {
            return [$allocations, 0]; // a concurrent checkout took the stock first
        }
        $left = $take;
        foreach ($allocations as $index => $allocation) {
            if ($left === 0) break;
            if (!empty($allocation['warehouse_id']) || !in_array((string)($allocation['status'] ?? ''), self::WAITING, true)) continue;
            $cut = min($left, (int)$allocation['qty']);
            $allocations[$index]['qty'] = (int)$allocation['qty'] - $cut;
            $left -= $cut;
            if ($allocations[$index]['qty'] === 0) unset($allocations[$index]);
        }
        return [array_merge(array_values($allocations), $reserved), $take];
    }

    private static function waitingQuantity(array $allocations): int {
        $waiting = 0;
        foreach ($allocations as $allocation) {
            if (empty($allocation['warehouse_id']) && in_array((string)($allocation['status'] ?? ''), self::WAITING, true)) {
                $waiting += (int)$allocation['qty'];
            }
        }
        return $waiting;
    }
}
