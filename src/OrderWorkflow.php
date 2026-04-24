<?php
/**
 * BareBones Shop — Order Workflow
 * State machine for order status transitions with side effects.
 */
class OrderWorkflow {
    /** Allowed state transitions */
    private const TRANSITIONS = [
        'pending'    => ['paid', 'cancelled'],
        'paid'       => ['processing', 'shipped', 'cancelled'],
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
     * Perform state transition with side effects
     */
    public static function transition(int $orderId, string $to, ?string $note = null): void {
        $order = Orders::byId($orderId);
        if (!$order) {
            throw new \RuntimeException("Order #{$orderId} not found");
        }

        $from = $order['status'];
        if (!self::canTransition($from, $to)) {
            throw new \LogicException("Cannot transition order #{$orderId} from '{$from}' to '{$to}'");
        }

        $db = Db::get();
        $deferredJobs = [];

        $db->beginTransaction();

        try {
            // Update status
            Orders::updateStatus($orderId, $to);

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

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        // Push jobs AFTER successful commit — filesystem writes are not transactional
        foreach ($deferredJobs as [$type, $payload, $priority]) {
            JobQueue::push($type, $payload, $priority);
        }
    }

    /**
     * Paid: send confirmation email, notify ERP.
     */
    private static function handlePaid(array $order): array {
        return [
            ['email_send', [
                'template' => 'order-confirmation',
                'to'       => $order['customer_email'],
                'order_id' => $order['id'],
            ], 5],
            ['erp_order_update', [
                'order_id' => $order['id'],
                'status'   => 'paid',
            ], 5],
        ];
    }

    /**
     * Cancellation: restore stock (in DB), return deferred jobs
     */
    private static function handleCancellation(array $order): array {
        Orders::restoreStock($order['id']);

        $jobs = [];
        // Only queue refund if payment_id is a payment_intent (pi_*), not a checkout session (cs_*)
        $pid = $order['payment_id'] ?? '';
        if ($pid && !str_starts_with($pid, 'cs_')) {
            $jobs[] = ['payment_refund', [
                'order_id'   => $order['id'],
                'payment_id' => $pid,
                'amount'     => $order['total'],
            ], 1];
        }
        return $jobs;
    }

    /**
     * Shipped: release warehouse reservations (goods physically left), return deferred jobs.
     * Decrements warehouse_stock.reserved and warehouse_stock.qty for shipped items
     * so available stock reflects reality without waiting for the next feed sync.
     */
    private static function handleShipped(array $order): array {
        $items = Orders::items($order['id']);
        $skusToSync = [];

        foreach ($items as $item) {
            if (!empty($item['variant_id'])) continue; // variants use products.stock directly

            $attrs = json_decode($item['attrs'] ?? '{}', true);
            $allocs = $attrs['_warehouse_allocations'] ?? null;

            if ($allocs) {
                $db = Db::get();
                foreach ($allocs as $alloc) {
                    // Goods shipped: clear reservation AND decrement physical qty
                    $db->prepare("
                        UPDATE warehouse_stock
                        SET reserved = MAX(0, reserved - :qty),
                            qty      = MAX(0, qty - :qty2)
                        WHERE warehouse_id = :wh AND sku = :sku
                    ")->execute([
                        ':qty'  => $alloc['qty'],
                        ':qty2' => $alloc['qty'],
                        ':wh'   => $alloc['warehouse_id'],
                        ':sku'  => $item['sku'],
                    ]);
                }
                $skusToSync[] = $item['sku'];
            }
        }

        if (!empty($skusToSync)) {
            Warehouse::syncProductStock(array_unique($skusToSync));
        }

        return [
            ['email_send', [
                'template' => 'shipping-notification',
                'to'       => $order['customer_email'],
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
     * Increments warehouse_stock.qty (goods physically back in warehouse).
     */
    private static function handleReturn(array $order): array {
        $items = Orders::items($order['id']);
        $db = Db::get();

        foreach ($items as $item) {
            if (!empty($item['variant_id'])) continue;

            $attrs = json_decode($item['attrs'] ?? '{}', true);
            $allocs = $attrs['_warehouse_allocations'] ?? null;

            if ($allocs) {
                foreach ($allocs as $alloc) {
                    // Goods returned: add qty back to warehouse
                    $db->prepare("
                        UPDATE warehouse_stock SET qty = qty + :qty
                        WHERE warehouse_id = :wh AND sku = :sku
                    ")->execute([
                        ':qty' => $alloc['qty'],
                        ':wh'  => $alloc['warehouse_id'],
                        ':sku' => $item['sku'],
                    ]);
                }
            }
        }

        Orders::restoreStock($order['id']);
        return [];
    }

    /**
     * Refund: return deferred email job
     */
    private static function handleRefund(array $order): array {
        return [
            ['email_send', [
                'template' => 'refund-confirmation',
                'to'       => $order['customer_email'],
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
