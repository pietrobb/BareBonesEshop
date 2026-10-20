<?php
/**
 * BareBones Shop — Order Workflow
 * State machine for order status transitions with side effects.
 */
class OrderWorkflow {
    /** Allowed state transitions */
    private const TRANSITIONS = [
        'pending'    => ['paid', 'cancelled'],
        'paid'       => ['processing', 'shipped', 'cancelled', 'refunded'],
        'processing' => ['shipped', 'cancelled'],
        'shipped'    => ['delivered', 'returned'],
        'delivered'  => ['returned'],
        'returned'   => ['refunded'],
        'cancelled'  => ['refunded'],
        'refunded'   => [],  // terminal state
    ];

    /**
     * Check if transition is allowed
     */
    public static function canTransition(string $from, string $to): bool {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Get allowed next states for current status
     */
    public static function allowedTransitions(string $from): array {
        return self::TRANSITIONS[$from] ?? [];
    }

    /**
     * Why an admin may not move this order to $to (null = allowed). Shared by the order
     * detail and bulk actions: 'paid' needs a confirmed payment, a move that returns money the refund right.
     */
    public static function adminRejection(?array $order, string $to, bool $mayRefund): ?string {
        if (!$order) return 'not_found';
        if (!in_array($to, self::statuses(), true)) return 'invalid_status';
        if (!$mayRefund && self::returnsMoney($order, $to)) return 'forbidden';
        if (!self::canTransition((string)$order['status'], $to)) return 'invalid_transition';
        if ($to === 'paid' && ($order['payment_status'] ?? 'pending') !== 'paid') return 'payment_not_confirmed';
        if (self::refundOutstanding($order, $to)) return 'refund_outstanding';
        return null;
    }

    /**
     * An order (paid, returned or cancelled) is 'refunded' only once its money went back: the status restores
     * stock, revokes digital access and emails "refund issued", but moves no money itself. While a refundable
     * balance remains the transition is refused; the money goes back through the refund ledger
     * (cancel the order or request a refund), which closes the order when the refund completes.
     */
    public static function refundOutstanding(array $order, string $to): bool {
        return $to === 'refunded' && PaymentService::refundableBalance((int)$order['id']) > 0;
    }

    /** Whether the move returns money: 'refunded', or cancelling captured money (handleCancellation() refunds it). */
    public static function returnsMoney(array $order, string $to): bool {
        return $to === 'refunded' || ($to === 'cancelled' && PaymentService::refundableBalance((int)$order['id']) > 0);
    }

    /**
     * Move several orders of the current store to $to, each through transition() with its
     * own transaction, stock and events. Refused orders are reported, never half-changed.
     *
     * @param int[] $orderIds
     * @return array{changed: array<int,string>, rejected: array<int,string>} order id => number / reason
     */
    public static function bulkTransition(array $orderIds, string $to, ?string $note, bool $mayRefund): array {
        $result = ['changed' => [], 'rejected' => []];
        foreach (array_unique(array_filter(array_map('intval', $orderIds), static fn(int $id): bool => $id > 0)) as $orderId) {
            $order = Orders::byId($orderId);
            $reason = self::adminRejection($order, $to, $mayRefund);
            if ($reason === null) {
                try {
                    self::transition($orderId, $to, $note);
                    $result['changed'][$orderId] = (string)$order['order_number'];
                    continue;
                } catch (\LogicException) {
                    $reason = 'invalid_transition';
                } catch (\Throwable $e) {
                    error_log("Bulk order status error [{$orderId}]: " . $e->getMessage());
                    $reason = 'error';
                }
            }
            $result['rejected'][$orderId] = $reason;
        }
        return $result;
    }

    /**
     * Perform state transition with side effects
     */
    public static function transition(int $orderId, string $to, ?string $note = null): void {
        $db = Db::get();
        $db->beginTransaction();
        try {
            $deferredJobs = self::transitionWithin($orderId, $to, $note);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        self::pushDeferredJobs($deferredJobs);
    }

    /**
     * The transition inside the caller's open transaction (e.g. together with an API idempotency
     * claim). Returns the deferred jobs; the caller pushes them with pushDeferredJobs() after commit.
     *
     * @return list<array{0:string,1:array,2:int}>
     */
    public static function transitionWithin(int $orderId, string $to, ?string $note = null): array {
        if (!Db::get()->inTransaction()) {
            throw new \LogicException('OrderWorkflow::transitionWithin needs an open transaction');
        }
        // Wait for a concurrent shipment of this order to commit before its state is read (MySQL row lock).
        Orders::lockWithin($orderId);
        $order = Orders::byId($orderId);
        if (!$order) {
            throw new \RuntimeException("Order #{$orderId} not found");
        }

        $from = $order['status'];
        if (!self::canTransition($from, $to)) {
            throw new \LogicException("Cannot transition order #{$orderId} from '{$from}' to '{$to}'");
        }
        if (self::refundOutstanding($order, $to)) {
            throw new \LogicException("Order #{$orderId} still has a refundable payment balance: refund it before marking the order refunded");
        }

        // Compare-and-set: of two concurrent transitions (webhook "paid", admin "cancel") only the
        // first wins; the loser changes nothing and its side effects roll back with it.
        if (!Orders::updateStatus($orderId, $to, $from)) {
            throw new \LogicException("Order #{$orderId} changed status concurrently (expected '{$from}')");
        }

        // Audit log
        AuditLog::log('order_status', $orderId, $from, $to, $note);

        // Collect DB-side effects (stock restore) + deferred jobs
        $deferredJobs = match($to) {
            'paid'      => self::handlePaid($order),
            'cancelled' => self::handleCancellation($order),
            'shipped'   => self::handleShipped($order),
            'returned'  => self::handleReturn($order),
            'refunded'  => self::handleRefund($order),
            default     => [],
        };
        $order['status'] = $to;
        $deferredJobs = array_merge($deferredJobs, FulfillmentDispatcher::dispatch($to, $order));
        Outbox::record(DomainEvents::ORDER_STATUS_CHANGED, 'order', $orderId, [
            'order_id' => $orderId,
            'order_number' => (string)$order['order_number'],
            'from' => (string)$from,
            'to' => $to,
            'note' => $note,
        ]);
        if (in_array($to, ['cancelled', 'shipped', 'returned', 'refunded'], true)) {
            Outbox::record(DomainEvents::STOCK_CHANGED, 'order', $orderId, [
                'order_id' => $orderId,
                'reason' => 'order.' . $to,
                'items' => array_map(static fn(array $item): array => [
                    'product_id' => (int)$item['product_id'],
                    'variant_id' => $item['variant_id'] === null ? null : (int)$item['variant_id'],
                    'sku' => (string)$item['sku'],
                    'quantity' => (int)$item['quantity'],
                ], Orders::items($orderId)),
            ]);
        }

        return $deferredJobs;
    }

    /** Push the deferred jobs of transitionWithin() AFTER commit: filesystem writes are not transactional. */
    public static function pushDeferredJobs(array $deferredJobs): void {
        foreach ($deferredJobs as [$type, $payload, $priority]) {
            JobQueue::push($type, $payload, $priority);
        }
    }

    /**
     * Paid: send confirmation email, issue invoice + email it, notify ERP.
     */
    private static function handlePaid(array $order): array {
        return [
            ['email_send', [
                'template' => 'order_paid',
                'to'       => $order['customer_email'],
                'locale'   => $order['locale'] ?? '',
                'order_id' => $order['id'],
            ], 5],
            ['invoice_issue', [
                'order_id' => $order['id'],
            ], 3],
            ['erp_order_update', [
                'order_id' => $order['id'],
                'status'   => 'paid',
            ], 5],
        ];
    }

    /**
     * Cancellation: restore stock (in DB); an unpaid payment is closed and voided at the
     * provider, a captured one is refunded through the refund ledger.
     */
    private static function handleCancellation(array $order): array {
        Orders::restoreStock($order['id']);
        Pricing::releaseCouponUse((int)$order['id']);

        return array_merge([
            ['email_send', [
                'template' => 'order_cancelled',
                'to'       => $order['customer_email'],
                'locale'   => $order['locale'] ?? '',
                'order_id' => $order['id'],
            ], 5],
        ], PaymentService::cancellationJobsWithin((int)$order['id']));
    }

    /**
     * Shipped: release warehouse reservations (goods physically left), return deferred jobs.
     * Decrements warehouse_stock.reserved and warehouse_stock.qty for shipped items
     * so available stock reflects reality without waiting for the next feed sync.
     */
    private static function handleShipped(array $order): array {
        Fulfillment::shipOrder((int)$order['id']);

        return [
            ['email_send', [
                'template' => 'order_shipped',
                'to'       => $order['customer_email'],
                'locale'   => $order['locale'] ?? '',
                'order_id' => $order['id'],
            ], 5],
            ['erp_order_update', [
                'order_id' => $order['id'],
                'status'   => 'shipped',
            ], 5],
        ];
    }

    /**
     * Returned: restore stock (in DB), no jobs needed.
     * Goods physically came back AFTER shipping, i.e. this order's
     * reservation was already consumed by handleShipped (reserved -= qty,
     * qty -= qty). Restore mode 'return' increments warehouse_stock.qty
     * only — it must NOT decrement 'reserved', which at this point
     * belongs to other open orders (bug #7: eaten foreign reservations).
     * The qty increment also runs under the stock_restored guard now,
     * so it is idempotent.
     */
    private static function handleReturn(array $order): array {
        Orders::restoreStock($order['id'], 'return');
        return [];
    }

    /**
     * Refund: return deferred email job
     */
    private static function handleRefund(array $order): array {
        Orders::restoreStock((int)$order['id']);
        return [
            ['email_send', [
                'template' => 'refund_issued',
                'to'       => $order['customer_email'],
                'locale'   => $order['locale'] ?? '',
                'order_id' => $order['id'],
            ], 5],
        ];
    }

    /**
     * All valid statuses
     */
    public static function statuses(): array {
        return array_keys(self::TRANSITIONS);
    }

    /**
     * Is terminal state?
     */
    public static function isTerminal(string $status): bool {
        if (!array_key_exists($status, self::TRANSITIONS)) {
            throw new \InvalidArgumentException("Unknown order status: '{$status}'");
        }
        return empty(self::TRANSITIONS[$status]);
    }
}
