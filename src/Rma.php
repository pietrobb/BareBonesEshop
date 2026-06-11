<?php
/**
 * BareBones Shop — RMA / Returns
 *
 * A return request (RMA) is opened by a customer against a *delivered* order and
 * then moved through a lifecycle by an admin:
 *
 *   requested → approved → received → refunded
 *             ↘ rejected            (terminal)
 *
 * create() enforces that the order is delivered and (when a customer id is given)
 * owned by that customer, and that only one open RMA exists per order at a time.
 * setStatus() guards every transition against the ALLOWED map.
 */
class Rma {

    public const STATUSES = ['requested', 'approved', 'received', 'rejected', 'refunded'];

    /** Non-terminal statuses — an order with one of these has an "open" return. */
    private const OPEN_STATUSES = ['requested', 'approved', 'received'];

    /** Permitted status transitions (current => [allowed next, ...]). */
    private const ALLOWED = [
        'requested' => ['approved', 'rejected'],
        'approved'  => ['received', 'rejected'],
        'received'  => ['refunded'],
        'rejected'  => [],
        'refunded'  => [],
    ];

    /**
     * Open a return request for an order.
     *
     * @param int      $orderId    Order the return is for.
     * @param int|null $customerId When > 0, the order must belong to this customer.
     * @param string   $reason     Free-text reason (required).
     * @param array    $items      [order_item_id => qty, ...] map of lines to return.
     * @return array [success, rma_id|null, error|null]. Errors are stable strings:
     *               invalid_order, order_not_returnable, not_owner, reason_required,
     *               no_items, rma_exists.
     */
    public static function create(int $orderId, ?int $customerId, string $reason, array $items): array {
        if ($orderId <= 0) {
            return [false, null, 'invalid_order'];
        }
        $order = Orders::byId($orderId);
        if (!$order) {
            return [false, null, 'invalid_order'];
        }
        if (($order['status'] ?? '') !== 'delivered') {
            return [false, null, 'order_not_returnable'];
        }
        if ($customerId !== null && $customerId > 0
            && (int)($order['customer_id'] ?? 0) !== $customerId) {
            return [false, null, 'not_owner'];
        }
        $reason = trim($reason);
        if ($reason === '') {
            return [false, null, 'reason_required'];
        }
        if (self::openForOrder($orderId)) {
            return [false, null, 'rma_exists'];
        }

        // Resolve which order_items are being returned and clamp quantities.
        $orderItems = [];
        foreach (Orders::items($orderId) as $oi) {
            $orderItems[(int)$oi['id']] = $oi;
        }
        $selected = self::normalizeItems($items, $orderItems);
        if (!$selected) {
            return [false, null, 'no_items'];
        }

        $db = Db::get();
        $now = Db::isSqlite() ? "datetime('now')" : 'NOW()';
        $rmaNumber = self::generateNumber($orderId);

        $db->beginTransaction();
        try {
            $ins = $db->prepare(
                "INSERT INTO rma_requests
                    (rma_number, order_id, customer_id, status, reason)
                 VALUES (?, ?, ?, 'requested', ?)"
            );
            $ins->execute([$rmaNumber, $orderId, $customerId ?: null, $reason]);
            $rmaId = (int)$db->lastInsertId();

            $itemIns = $db->prepare(
                "INSERT INTO rma_items
                    (rma_id, order_item_id, product_id, sku, name, quantity)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            foreach ($selected as $sel) {
                $oi = $sel['order_item'];
                $itemIns->execute([
                    $rmaId,
                    (int)$oi['id'],
                    isset($oi['product_id']) ? (int)$oi['product_id'] : null,
                    (string)($oi['sku'] ?? ''),
                    (string)($oi['name'] ?? ''),
                    $sel['qty'],
                ]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return [true, $rmaId, null];
    }

    /**
     * Move an RMA to a new status. Returns false if the id is unknown or the
     * transition is not permitted by the ALLOWED map.
     */
    public static function setStatus(int $rmaId, string $newStatus, string $adminNote = ''): bool {
        if ($rmaId <= 0 || !in_array($newStatus, self::STATUSES, true)) {
            return false;
        }
        $current = self::byId($rmaId);
        if (!$current) {
            return false;
        }
        $from = (string)$current['status'];
        if (!in_array($newStatus, self::ALLOWED[$from] ?? [], true)) {
            return false;
        }
        $now = Db::isSqlite() ? "datetime('now')" : 'NOW()';
        $sql = "UPDATE rma_requests SET status = ?, updated_at = {$now}";
        $params = [$newStatus];
        if ($adminNote !== '') {
            $sql .= ", admin_note = ?";
            $params[] = $adminNote;
        }
        $sql .= " WHERE id = ?";
        $params[] = $rmaId;
        return Db::get()->prepare($sql)->execute($params);
    }

    public static function byId(int $id): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM rma_requests WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Line items attached to an RMA. */
    public static function items(int $rmaId): array {
        $stmt = Db::get()->prepare(
            "SELECT * FROM rma_items WHERE rma_id = ? ORDER BY id"
        );
        $stmt->execute([$rmaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** All RMAs for a customer, newest first. */
    public static function forCustomer(int $customerId): array {
        $stmt = Db::get()->prepare(
            "SELECT * FROM rma_requests WHERE customer_id = ? ORDER BY id DESC"
        );
        $stmt->execute([$customerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** All RMAs for an order, newest first. */
    public static function forOrder(int $orderId): array {
        $stmt = Db::get()->prepare(
            "SELECT * FROM rma_requests WHERE order_id = ? ORDER BY id DESC"
        );
        $stmt->execute([$orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The open (non-terminal) RMA for an order, or null. */
    public static function openForOrder(int $orderId): ?array {
        $ph = implode(',', array_fill(0, count(self::OPEN_STATUSES), '?'));
        $stmt = Db::get()->prepare(
            "SELECT * FROM rma_requests
             WHERE order_id = ? AND status IN ({$ph})
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(array_merge([$orderId], self::OPEN_STATUSES));
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Admin moderation queue: all RMAs (optionally a single status), newest first. */
    public static function all(int $limit = 200, ?string $status = null): array {
        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $stmt = Db::get()->prepare(
                "SELECT * FROM rma_requests WHERE status = ? ORDER BY id DESC LIMIT ?"
            );
            $stmt->execute([$status, $limit]);
        } else {
            $stmt = Db::get()->prepare(
                "SELECT * FROM rma_requests ORDER BY id DESC LIMIT ?"
            );
            $stmt->execute([$limit]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Reduce a raw [order_item_id => qty] map to validated [order_item, qty]
     * rows: only ids belonging to the order are kept, qty must be > 0 and is
     * clamped to the ordered quantity.
     */
    private static function normalizeItems(array $items, array $orderItems): array {
        $out = [];
        foreach ($items as $oiId => $qty) {
            $oiId = (int)$oiId;
            if (!isset($orderItems[$oiId])) {
                continue;
            }
            $qty = (int)$qty;
            if ($qty <= 0) {
                continue;
            }
            $maxQty = max(1, (int)($orderItems[$oiId]['quantity'] ?? 1));
            $out[$oiId] = ['order_item' => $orderItems[$oiId], 'qty' => min($qty, $maxQty)];
        }
        return array_values($out);
    }

    private static function generateNumber(int $orderId): string {
        return 'RMA-' . $orderId . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }
}
