<?php
/**
 * Orders paid outside the shop (bank transfer) that still wait for money,
 * and the single operator action that confirms them.
 *
 * Confirming records the payment idempotently and moves the order to "paid",
 * which runs the normal paid fulfillment (digital delivery, paid e-mails).
 */
class OfflinePaymentQueue {
    private const CLOSED_ORDER_STATUSES = ['cancelled', 'refunded', 'returned'];

    /** @return array<int,array> oldest first, with age_hours and payment_label */
    public static function awaiting(int $limit = 200): array {
        $closed = implode(',', array_map(static fn(string $s): string => "'{$s}'", self::CLOSED_ORDER_STATUSES));
        $stmt = Db::get()->prepare("SELECT * FROM orders
            WHERE store_id = :store AND payment_status = 'pending' AND status NOT IN ({$closed})
            ORDER BY created_at ASC, id ASC LIMIT :limit");
        $stmt->bindValue(':store', StoreContext::current()->id());
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $now = time();
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
            $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? ''), true) ?: [];
            if (!self::isOfflineTransfer($order, $snapshot)) continue;
            $created = strtotime((string)$order['created_at'] . ' UTC') ?: $now;
            $order['age_hours'] = max(0, intdiv($now - $created, 3600));
            $order['payment_label'] = (string)($snapshot['label'] ?? $snapshot['name'] ?? $order['payment_method'] ?? '');
            $rows[] = $order;
        }
        return $rows;
    }

    /**
     * Record the received transfer and release paid fulfillment. Returns false when already confirmed.
     * Money that arrives for an order already cancelled or expired is still recorded (it was received),
     * but settleCapture() keeps the order cancelled and flags the refund; check the order status after.
     */
    public static function confirm(int $orderId, string $actor, string $note = ''): bool {
        $order = Orders::byId($orderId);
        if (!$order) throw new RuntimeException("Order #{$orderId} not found");
        $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? ''), true) ?: [];
        if (!self::isOfflineTransfer($order, $snapshot)) {
            throw new LogicException("Order #{$orderId} is not paid by offline transfer");
        }

        $recorded = PaymentService::transitionPayment($orderId, 'paid', 'admin.offline_payment_received', 0,
            'offline_paid_' . $orderId, ['actor' => $actor, 'note' => $note]);
        PaymentService::settleCapture($orderId, 'Platba prevodom prijatá' . ($note !== '' ? ': ' . $note : ''));
        return $recorded;
    }

    public static function isOfflineTransfer(array $order, array $snapshot): bool {
        $type = (string)($snapshot['service_type'] ?? '');
        if ($type !== '') return $type === 'bank_transfer';
        return (string)($order['payment_method'] ?? '') === 'bank_transfer';
    }
}
