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
        $db->beginTransaction();

        try {
            // Update status
            Orders::updateStatus($orderId, $to);

            // Audit log
            AuditLog::log('order_status', $orderId, $from, $to, $note);

            // Side effects
            match($to) {
                'cancelled' => self::handleCancellation($order),
                'shipped'   => self::handleShipped($order),
                'returned'  => self::handleReturn($order),
                'refunded'  => self::handleRefund($order),
                default     => null,
            };

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Cancellation: restore stock, queue refund if paid
     */
    private static function handleCancellation(array $order): void {
        Orders::restoreStock($order['id']);

        // Only queue refund if payment_id is a payment_intent (pi_*), not a checkout session (cs_*)
        $pid = $order['payment_id'] ?? '';
        if ($pid && !str_starts_with($pid, 'cs_')) {
            JobQueue::push('payment_refund', [
                'order_id'   => $order['id'],
                'payment_id' => $pid,
                'amount'     => $order['total'],
            ], 1);
        }
    }

    /**
     * Shipped: queue email notification
     */
    private static function handleShipped(array $order): void {
        JobQueue::push('email_send', [
            'template' => 'shipping-notification',
            'to'       => $order['customer_email'],
            'order_id' => $order['id'],
        ]);

        // ERP sync — push shipment status
        JobQueue::push('erp_order_update', [
            'order_id' => $order['id'],
            'status'   => 'shipped',
        ]);
    }

    /**
     * Returned: restore stock to inventory
     */
    private static function handleReturn(array $order): void {
        Orders::restoreStock($order['id']);
    }

    /**
     * Refund: queue refund email (stock already restored in handleReturn or handleCancellation)
     */
    private static function handleRefund(array $order): void {
        JobQueue::push('email_send', [
            'template' => 'refund-confirmation',
            'to'       => $order['customer_email'],
            'order_id' => $order['id'],
        ]);
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
