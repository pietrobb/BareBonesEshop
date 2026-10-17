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
        // Multiple partial RMAs may be open at once. Quantity is reserved by all
        // non-rejected requests so concurrent requests cannot over-claim a line:
        // the claimed quantities are read and the new claim written in one transaction,
        // serialised per order by the order row lock (SQLite serialises writers itself).
        $db = Db::get();
        $rmaNumber = self::generateNumber($orderId);

        $db->beginTransaction();
        try {
            if (Db::isMysql()) {
                $db->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE')->execute([$orderId]);
            }
            // Resolve which order_items are being returned and clamp quantities.
            $orderItems = [];
            foreach (Orders::items($orderId) as $oi) {
                $orderItems[(int)$oi['id']] = $oi;
            }
            $selected = self::normalizeItems($orderId, $items, $orderItems);
            if (!$selected) {
                $db->rollBack();
                return [false, null, 'no_items'];
            }

            $ins = $db->prepare(
                "INSERT INTO rma_requests
                    (store_id, rma_number, order_id, customer_id, status, reason)
                 VALUES (?, ?, ?, ?, 'requested', ?)"
            );
            $ins->execute([StoreContext::current()->id(), $rmaNumber, $orderId, $customerId ?: null, $reason]);
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
        $sql .= " WHERE id = ? AND store_id = ?";
        $params[] = $rmaId;
        $params[] = StoreContext::current()->id();
        return Db::get()->prepare($sql)->execute($params);
    }

    public static function byId(int $id): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM rma_requests WHERE id = ? AND store_id = ?");
        $stmt->execute([$id, StoreContext::current()->id()]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Line items attached to an RMA. */
    public static function items(int $rmaId): array {
        $stmt = Db::get()->prepare(
            "SELECT ri.* FROM rma_items ri JOIN rma_requests rr ON rr.id = ri.rma_id
             WHERE ri.rma_id = ? AND rr.store_id = ? ORDER BY ri.id"
        );
        $stmt->execute([$rmaId, StoreContext::current()->id()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** All RMAs for a customer, newest first. */
    public static function forCustomer(int $customerId): array {
        $stmt = Db::get()->prepare(
            "SELECT * FROM rma_requests WHERE customer_id = ? AND store_id = ? ORDER BY id DESC"
        );
        $stmt->execute([$customerId, StoreContext::current()->id()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** All RMAs for an order, newest first. */
    public static function forOrder(int $orderId): array {
        $stmt = Db::get()->prepare(
            "SELECT * FROM rma_requests WHERE order_id = ? AND store_id = ? ORDER BY id DESC"
        );
        $stmt->execute([$orderId, StoreContext::current()->id()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Order lines with the quantity not already claimed by a non-rejected RMA. */
    public static function returnableItems(int $orderId): array {
        $items = Orders::items($orderId);
        $stmt = Db::get()->prepare("SELECT ri.order_item_id, COALESCE(SUM(ri.quantity),0) AS claimed
            FROM rma_items ri JOIN rma_requests rr ON rr.id=ri.rma_id
            WHERE rr.order_id=:order AND rr.store_id=:store AND rr.status!='rejected'
            GROUP BY ri.order_item_id");
        $stmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id()]);
        $claimed = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $claimed[(int)$row['order_item_id']] = (int)$row['claimed'];
        $result = [];
        foreach ($items as $item) {
            $item['returnable_quantity'] = max(0, (int)$item['quantity'] - ($claimed[(int)$item['id']] ?? 0));
            if ($item['returnable_quantity'] > 0) $result[] = $item;
        }
        return $result;
    }

    /** The newest open (non-terminal) RMA for an order, or null. */
    public static function openForOrder(int $orderId): ?array {
        $ph = implode(',', array_fill(0, count(self::OPEN_STATUSES), '?'));
        $stmt = Db::get()->prepare(
            "SELECT * FROM rma_requests
             WHERE order_id = ? AND store_id = ? AND status IN ({$ph})
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(array_merge([$orderId, StoreContext::current()->id()], self::OPEN_STATUSES));
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Admin moderation queue: all RMAs (optionally a single status), newest first. */
    public static function all(int $limit = 200, ?string $status = null): array {
        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $stmt = Db::get()->prepare(
                "SELECT * FROM rma_requests WHERE status = ? AND store_id = ? ORDER BY id DESC LIMIT ?"
            );
            $stmt->execute([$status, StoreContext::current()->id(), $limit]);
        } else {
            $stmt = Db::get()->prepare(
                "SELECT * FROM rma_requests WHERE store_id = ? ORDER BY id DESC LIMIT ?"
            );
            $stmt->execute([StoreContext::current()->id(), $limit]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Record a provider-created return label without exposing provider secrets. */
    public static function recordLabel(
        int $rmaId,
        string $provider,
        string $labelReference,
        string $idempotencyKey,
        ?string $trackingNumber = null,
        ?string $labelUrl = null
    ): array {
        if (!self::byId($rmaId)) throw new RuntimeException('RMA not found');
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,39}$/', $provider)
            || trim($labelReference) === '' || trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Return label identity is invalid');
        }
        if ($labelUrl !== null && $labelUrl !== ''
            && (!filter_var($labelUrl, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($labelUrl), 'https://'))) {
            throw new InvalidArgumentException('Return label URL must use HTTPS');
        }
        $stmt = Db::get()->prepare(Db::insertIgnore() . " INTO return_labels
            (store_id, rma_id, provider, label_reference, tracking_number, label_url, idempotency_key)
            VALUES (:store, :rma, :provider, :reference, :tracking, :url, :key)");
        $stmt->execute([
            ':store' => StoreContext::current()->id(), ':rma' => $rmaId, ':provider' => $provider,
            ':reference' => $labelReference, ':tracking' => $trackingNumber, ':url' => $labelUrl,
            ':key' => $idempotencyKey,
        ]);
        $lookup = Db::get()->prepare('SELECT * FROM return_labels WHERE store_id = :store AND idempotency_key = :key');
        $lookup->execute([':store' => StoreContext::current()->id(), ':key' => $idempotencyKey]);
        $label = $lookup->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int)($label['rma_id'] ?? 0) !== $rmaId || (string)($label['provider'] ?? '') !== $provider
            || (string)($label['label_reference'] ?? '') !== $labelReference) {
            throw new LogicException('Return label idempotency key was reused with different input');
        }
        return $label;
    }

    /** Receive partial quantities and apply an explicit stock disposition once. */
    public static function receive(int $rmaId, array $decisions, string $idempotencyKey, string $note = ''): bool {
        $rma = self::byId($rmaId);
        if (!$rma || !in_array((string)$rma['status'], ['approved', 'received'], true)) {
            throw new LogicException('RMA is not ready to receive');
        }
        if ($idempotencyKey === '' || !$decisions) throw new InvalidArgumentException('Receipt decisions and idempotency key are required');
        $db = Db::get();
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            // Status and received quantities are re-read inside the transaction (row-locked on MySQL),
            // so two concurrent receipts cannot both pass the "exceeds requested quantity" check.
            $rma = self::lockedRma($rmaId);
            if (!$rma || !in_array((string)$rma['status'], ['approved', 'received'], true)) {
                throw new LogicException('RMA is not ready to receive');
            }
            $items = [];
            $lines = $db->prepare('SELECT * FROM rma_items WHERE rma_id = ? ORDER BY id' . (Db::isMysql() ? ' FOR UPDATE' : ''));
            $lines->execute([$rmaId]);
            foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $item) $items[(int)$item['id']] = $item;
            $changed = false;
            foreach ($decisions as $rmaItemId => $decision) {
                $rmaItemId = (int)$rmaItemId;
                if (!isset($items[$rmaItemId])) throw new InvalidArgumentException('RMA item does not belong to request');
                $quantity = (int)($decision['quantity'] ?? 0);
                $disposition = (string)($decision['disposition'] ?? '');
                if ($quantity <= 0 || !in_array($disposition, ['restock', 'discard', 'repair'], true)) {
                    throw new InvalidArgumentException('Invalid return receipt decision');
                }
                $item = $items[$rmaItemId];
                $claim = $db->prepare(Db::insertIgnore() . " INTO return_receipts
                    (store_id, rma_id, rma_item_id, quantity, disposition, note, idempotency_key)
                    VALUES (:store, :rma, :item, :quantity, :disposition, :note, :key)");
                $claim->execute([
                    ':store' => StoreContext::current()->id(), ':rma' => $rmaId, ':item' => $rmaItemId,
                    ':quantity' => $quantity, ':disposition' => $disposition, ':note' => $note, ':key' => $idempotencyKey,
                ]);
                if ($claim->rowCount() === 0) {
                    $replay = $db->prepare('SELECT rma_id, quantity, disposition FROM return_receipts WHERE store_id=:store AND idempotency_key=:key AND rma_item_id=:item');
                    $replay->execute([':store' => StoreContext::current()->id(), ':key' => $idempotencyKey, ':item' => $rmaItemId]);
                    $prior = $replay->fetch(PDO::FETCH_ASSOC);
                    if (!$prior || (int)$prior['rma_id'] !== $rmaId || (int)$prior['quantity'] !== $quantity || (string)$prior['disposition'] !== $disposition) {
                        throw new LogicException('Receipt idempotency key was reused with different input');
                    }
                    continue;
                }
                if ((int)$item['received_quantity'] + $quantity > (int)$item['quantity']) {
                    throw new LogicException('Receipt exceeds requested quantity');
                }
                if ($disposition === 'restock') {
                    $previous = $db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM return_receipts
                        WHERE store_id = :store AND rma_item_id IN (
                            SELECT ri.id FROM rma_items ri JOIN rma_requests rr ON rr.id = ri.rma_id
                            WHERE ri.order_item_id = :order_item AND rr.store_id = :store2
                        ) AND disposition = 'restock' AND NOT (rma_item_id = :item AND idempotency_key = :key)");
                    $previous->execute([
                        ':store' => StoreContext::current()->id(), ':order_item' => (int)$item['order_item_id'],
                        ':store2' => StoreContext::current()->id(), ':item' => $rmaItemId, ':key' => $idempotencyKey,
                    ]);
                    $orderItem = null;
                    foreach (Orders::items((int)$rma['order_id']) as $candidate) {
                        if ((int)$candidate['id'] === (int)$item['order_item_id']) { $orderItem = $candidate; break; }
                    }
                    if (!$orderItem) throw new RuntimeException('Order item not found');
                    // The order-level return (status 'returned') already put its goods back on stock.
                    if ((int)(Orders::byId((int)$rma['order_id'])['stock_restored'] ?? 0) === 0) Inventory::returnOrderItemQuantity($orderItem, $quantity, "{$rmaId}:{$rmaItemId}:{$idempotencyKey}", (int)$previous->fetchColumn());
                }
                $db->prepare("UPDATE rma_items SET received_quantity = received_quantity + :quantity,
                        disposition = CASE WHEN disposition IN ('pending', :same) THEN :same2 ELSE 'mixed' END
                    WHERE id = :id")
                    ->execute([':quantity' => $quantity, ':same' => $disposition, ':same2' => $disposition, ':id' => $rmaItemId]);
                $changed = true;
            }
            if ($changed) {
                $remaining = $db->prepare('SELECT COUNT(*) FROM rma_items WHERE rma_id = :rma AND received_quantity < quantity');
                $remaining->execute([':rma' => $rmaId]);
                if ((int)$remaining->fetchColumn() === 0) {
                    $db->prepare("UPDATE rma_requests SET status = 'received', updated_at = " . Db::now() . " WHERE id = :id AND store_id = :store")
                        ->execute([':id' => $rmaId, ':store' => StoreContext::current()->id()]);
                }
                AuditLog::log('rma_receipt', $rmaId, null, ['idempotency_key' => $idempotencyKey], $note);
                Outbox::record(DomainEvents::STOCK_CHANGED, 'rma', $rmaId, ['rma_id' => $rmaId, 'order_id' => (int)$rma['order_id'], 'reason' => 'return.received']);
            }
            if ($own) $db->commit();
            return $changed;
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** Reserve a received quantity for an auditable replacement workflow. */
    public static function createExchange(
        int $rmaId,
        int $rmaItemId,
        int $quantity,
        int $replacementProductId,
        ?int $replacementVariantId,
        string $idempotencyKey
    ): int {
        $rma = self::byId($rmaId);
        if (!$rma || !in_array((string)$rma['status'], ['received', 'refunded'], true)) throw new LogicException('RMA is not received');
        if ($quantity <= 0 || $idempotencyKey === '' || !Products::byId($replacementProductId)) throw new InvalidArgumentException('Exchange request is invalid');
        // The available quantity is checked and the exchange inserted in one transaction, with the
        // RMA line locked on MySQL, so concurrent exchanges cannot both reserve the same units.
        $db = Db::get();
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $exchangeId = self::insertExchange($rmaId, $rmaItemId, $quantity, $replacementProductId, $replacementVariantId, $idempotencyKey);
            if ($own) $db->commit();
            return $exchangeId;
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** RMA row for update inside a transaction: locked on MySQL; SQLite serialises writers itself. */
    private static function lockedRma(int $rmaId): ?array {
        $stmt = Db::get()->prepare('SELECT * FROM rma_requests WHERE id = ? AND store_id = ?' . (Db::isMysql() ? ' FOR UPDATE' : ''));
        $stmt->execute([$rmaId, StoreContext::current()->id()]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function insertExchange(int $rmaId, int $rmaItemId, int $quantity, int $replacementProductId, ?int $replacementVariantId, string $idempotencyKey): int {
        $rma = self::lockedRma($rmaId);
        if (!$rma || !in_array((string)$rma['status'], ['received', 'refunded'], true)) throw new LogicException('RMA is not received');
        if (Db::isMysql()) Db::get()->prepare('SELECT id FROM rma_items WHERE id = ? FOR UPDATE')->execute([$rmaItemId]);
        $stmt = Db::get()->prepare("SELECT ri.*,
                COALESCE((SELECT SUM(cni.quantity) FROM credit_note_items cni JOIN order_refunds r ON r.id=cni.refund_id WHERE cni.rma_item_id=ri.id AND r.status IN ('pending','succeeded')),0) AS credited,
                COALESCE((SELECT SUM(quantity) FROM return_exchanges re WHERE re.rma_item_id=ri.id AND re.store_id=:store2 AND re.status!='cancelled'),0) AS exchanged
            FROM rma_items ri JOIN rma_requests rr ON rr.id=ri.rma_id
            WHERE ri.id=:item AND ri.rma_id=:rma AND rr.store_id=:store");
        $stmt->execute([':item' => $rmaItemId, ':rma' => $rmaId, ':store' => StoreContext::current()->id(), ':store2' => StoreContext::current()->id()]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item || $quantity > (int)$item['received_quantity'] - (int)$item['credited'] - (int)$item['exchanged']) throw new LogicException('Exchange exceeds available received quantity');
        $insert = Db::get()->prepare(Db::insertIgnore() . " INTO return_exchanges
            (store_id, rma_id, rma_item_id, quantity, replacement_product_id, replacement_variant_id, idempotency_key)
            VALUES (:store, :rma, :item, :quantity, :product, :variant, :key)");
        $insert->execute([
            ':store' => StoreContext::current()->id(), ':rma' => $rmaId, ':item' => $rmaItemId,
            ':quantity' => $quantity, ':product' => $replacementProductId, ':variant' => $replacementVariantId, ':key' => $idempotencyKey,
        ]);
        $lookup = Db::get()->prepare('SELECT * FROM return_exchanges WHERE store_id=:store AND idempotency_key=:key');
        $lookup->execute([':store' => StoreContext::current()->id(), ':key' => $idempotencyKey]);
        $exchange = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$exchange || (int)$exchange['rma_id'] !== $rmaId || (int)$exchange['rma_item_id'] !== $rmaItemId
            || (int)$exchange['quantity'] !== $quantity || (int)$exchange['replacement_product_id'] !== $replacementProductId
            || ($exchange['replacement_variant_id'] === null ? null : (int)$exchange['replacement_variant_id']) !== $replacementVariantId) {
            throw new LogicException('Exchange idempotency key was reused with different input');
        }
        return (int)$exchange['id'];
    }

    public static function completeRefund(int $refundId, string $paymentReference): void {
        $db = Db::get();
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT * FROM order_refunds WHERE id=:id AND store_id=:store");
            $stmt->execute([':id' => $refundId, ':store' => StoreContext::current()->id()]);
            $refund = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$refund || $refund['status'] === 'succeeded') { if ($own) $db->commit(); return; }
            if ($refund['status'] !== 'pending') throw new LogicException('Refund is not pending');
            $db->prepare("UPDATE order_refunds SET status='succeeded', payment_reference=:reference, updated_at=" . Db::now() . " WHERE id=:id")
                ->execute([':reference' => $paymentReference, ':id' => $refundId]);
            $lines = $db->prepare('SELECT rma_item_id, quantity FROM credit_note_items WHERE refund_id=:refund');
            $lines->execute([':refund' => $refundId]);
            foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $line) {
                $db->prepare('UPDATE rma_items SET refunded_quantity = refunded_quantity + :quantity WHERE id=:id')
                    ->execute([':quantity' => (int)$line['quantity'], ':id' => (int)$line['rma_item_id']]);
            }
            $remaining = $db->prepare("SELECT COUNT(*) FROM rma_items ri WHERE ri.rma_id=:rma AND ri.refunded_quantity +
                COALESCE((SELECT SUM(quantity) FROM return_exchanges re WHERE re.rma_item_id=ri.id AND re.status IN ('requested','fulfilled')),0) < ri.received_quantity");
            $remaining->execute([':rma' => (int)$refund['rma_id']]);
            if ((int)$remaining->fetchColumn() === 0) {
                $db->prepare("UPDATE rma_requests SET status='refunded', credit_note_id=:credit, updated_at=" . Db::now() . " WHERE id=:rma AND store_id=:store")
                    ->execute([':credit' => (int)$refund['credit_note_id'], ':rma' => (int)$refund['rma_id'], ':store' => StoreContext::current()->id()]);
            }
            if ($own) $db->commit();
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** The provider accepted an item refund asynchronously: its refund id lets the refund webhook complete it. */
    public static function noteProviderRefund(int $refundId, string $providerRefundId): void {
        Db::get()->prepare("UPDATE order_refunds SET payment_reference=:reference, updated_at=" . Db::now() . " WHERE id=:id AND store_id=:store AND status='pending'")
            ->execute([':reference' => $providerRefundId, ':id' => $refundId, ':store' => StoreContext::current()->id()]);
    }

    public static function refundById(int $refundId): ?array {
        $stmt = Db::get()->prepare('SELECT * FROM order_refunds WHERE id=:id AND store_id=:store');
        $stmt->execute([':id' => $refundId, ':store' => StoreContext::current()->id()]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Reduce a raw [order_item_id => qty] map to validated [order_item, qty]
     * rows: only ids belonging to the order are kept, qty must be > 0 and is
     * clamped to the ordered quantity.
     */
    private static function normalizeItems(int $orderId, array $items, array $orderItems): array {
        $claimedStmt = Db::get()->prepare("SELECT ri.order_item_id, COALESCE(SUM(ri.quantity), 0) AS claimed
            FROM rma_items ri JOIN rma_requests rr ON rr.id = ri.rma_id
            WHERE rr.order_id = :order AND rr.store_id = :store AND rr.status != 'rejected'
            GROUP BY ri.order_item_id");
        $claimedStmt->execute([':order' => $orderId, ':store' => StoreContext::current()->id()]);
        $claimed = [];
        foreach ($claimedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $claimed[(int)$row['order_item_id']] = (int)$row['claimed'];
        }

        $out = [];
        foreach ($items as $oiId => $qty) {
            $oiId = (int)$oiId;
            if (!isset($orderItems[$oiId])) continue;
            $qty = (int)$qty;
            if ($qty <= 0) continue;
            $ordered = max(1, (int)($orderItems[$oiId]['quantity'] ?? 1));
            $available = max(0, $ordered - ($claimed[$oiId] ?? 0));
            if ($available === 0) continue;
            $out[$oiId] = ['order_item' => $orderItems[$oiId], 'qty' => min($qty, $available)];
        }
        return array_values($out);
    }

    private static function generateNumber(int $orderId): string {
        return 'RMA-' . $orderId . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }
}
