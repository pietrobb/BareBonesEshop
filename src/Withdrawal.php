<?php
/**
 * Round 8: electronic withdrawal function (Directive 2011/83/EU art. 11a, added by 2023/2673).
 *
 * Any consumer (guest or customer, no login) identifies the contract by order number + the order's e-mail,
 * withdraws without giving a reason, also before the goods are delivered, and confirms the statement in a
 * separate step. The shop records it with the time it was received and acknowledges it on a durable medium
 * (e-mail with the statement and that time). Withdrawing is not a return: the merchant then cancels or takes the
 * goods back through RMA as usual; a merchant_notify job tells the merchant.
 *
 * Withdrawal period: 14 days after delivery, or after the order for digital content only (withinPeriod();
 * rma_return_window_days may only extend it).
 * Wording is a technical baseline for a lawyer to review.
 */
class Withdrawal {
    public const PERIOD_DAYS = 14;

    /** Orders a consumer can no longer withdraw from: nothing left to withdraw from. */
    private const CLOSED_STATUSES = ['cancelled', 'refunded', 'returned'];

    /** The order of the active store with this number whose e-mail matches (case-insensitive), or null. */
    public static function findOrder(string $orderNumber, string $email): ?array {
        $orderNumber = trim($orderNumber);
        $email = Str::normalizeEmail($email);
        if ($orderNumber === '' || $email === '') return null;
        $stmt = Db::get()->prepare('SELECT * FROM orders WHERE order_number = ? AND store_id = ? LIMIT 1');
        $stmt->execute([$orderNumber, StoreContext::current()->id()]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($order === null || !hash_equals(Str::normalizeEmail((string)$order['customer_email']), $email)) return null;
        return $order;
    }

    /** null when the consumer may still withdraw, else 'not_applicable' or 'period_over'. */
    public static function refusal(array $order, ?int $now = null): ?string {
        if (in_array((string)($order['status'] ?? ''), self::CLOSED_STATUSES, true)) return 'not_applicable';
        if (!self::withinPeriod($order, $now)) return 'period_over';
        return self::openItems($order) === [] ? 'already' : null;
    }

    /**
     * The period runs from delivery of the goods, or from the contract (the order) for an order of digital content
     * only (art. 9(2)). Goods not (yet) marked delivered stay withdrawable, but never past the longest period the
     * directive allows at all: 12 months after the original one (art. 10). rma_return_window_days may only extend it.
     */
    public static function withinPeriod(array $order, ?int $now = null): bool {
        $days = max(self::PERIOD_DAYS, (int)(Security::config()['rma_return_window_days'] ?? self::PERIOD_DAYS));
        $now ??= time();
        $start = self::receivedAt($order);
        if ($start === false && self::digitalOnly($order)) $start = self::orderedAt($order);
        if ($start !== false) return $now <= $start + $days * 86400;
        return self::withinUndatedLimit($order, $days, $now);
    }

    /** Art. 10(1): a period not properly disclosed ends 12 months after the original one at the latest. */
    public const MAX_EXTENSION_DAYS = 365;

    /** Without a delivery date: open until $days + 12 months after the order (art. 10), never indefinitely. */
    public static function withinUndatedLimit(array $order, int $days, int $now): bool {
        $ordered = self::orderedAt($order);
        return $ordered === false || $now <= $ordered + ($days + self::MAX_EXTENSION_DAYS) * 86400;
    }

    /** Unix time the goods reached the consumer: the order's delivered_at, else the latest carrier-reported delivery; false when unknown. */
    public static function receivedAt(array $order): int|false {
        $deliveredAt = (string)($order['delivered_at'] ?? '');
        if ($deliveredAt === '' && (int)($order['id'] ?? 0) > 0) {
            $stmt = Db::get()->prepare('SELECT MAX(delivered_at) FROM shipments WHERE order_id = ?');
            $stmt->execute([(int)$order['id']]);
            $deliveredAt = (string)($stmt->fetchColumn() ?: '');
        }
        return $deliveredAt === '' ? false : strtotime($deliveredAt . ' UTC');
    }

    /** Unix time the order (the distance contract) was placed, or false when unknown. */
    public static function orderedAt(array $order): int|false {
        $created = (string)($order['created_at'] ?? '');
        return $created === '' ? false : strtotime($created . ' UTC');
    }

    /** Whether every line of the order is digital content (nothing is shipped, so nothing is ever "delivered"). */
    public static function digitalOnly(array $order): bool {
        $stmt = Db::get()->prepare('SELECT p.product_type FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?');
        $stmt->execute([(int)($order['id'] ?? 0)]);
        $types = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $types !== [] && array_filter($types, static fn($type): bool => !VariantAvailability::isDigitalType((string)$type)) === [];
    }

    /**
     * Order lines not yet covered by an earlier withdrawal statement, with their full quantity. Digital content whose
     * supply began with the consumer's consent to lose the right (ConsentService::DIGITAL_KEY) cannot be withdrawn
     * from (art. 16(m)); without that consent it stays withdrawable.
     */
    public static function openItems(array $order): array {
        $withdrawn = [];
        foreach (self::forOrder((int)$order['id']) as $statement) {
            foreach ((array)(json_decode((string)$statement['items'], true) ?: []) as $itemId => $qty) $withdrawn[(int)$itemId] = true;
        }
        $consented = in_array(ConsentService::DIGITAL_KEY, array_column(ConsentService::forOrder((int)$order['id']), 'consent_key'), true);
        if ($consented) foreach (DigitalDelivery::forOrder((int)$order['id']) as $entitlement) $withdrawn[(int)$entitlement['order_item_id']] = true;
        return array_values(array_filter(Orders::items((int)$order['id']), static fn(array $item): bool => !isset($withdrawn[(int)$item['id']])));
    }

    public static function forOrder(int $orderId): array {
        // Migration window: the order and shipment pages render before 20261022090000_withdrawal_requests has run.
        if (!Db::tableExists('withdrawal_requests')) return [];
        $stmt = Db::get()->prepare('SELECT * FROM withdrawal_requests WHERE order_id = ? AND store_id = ? ORDER BY id');
        $stmt->execute([$orderId, StoreContext::current()->id()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Record the statement for the selected open lines (order_item ids) and acknowledge it.
     * @return array{ok: bool, error: ?string, id: int, received_at: string, confirmation: string}
     */
    public static function submit(array $order, string $name, array $itemIds, string $reason = ''): array {
        $name = mb_substr(trim($name), 0, 191);
        $reason = mb_substr(trim($reason), 0, 2000);
        $db = Db::get();
        $db->beginTransaction();
        try {
            if (Db::isMysql()) $db->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE')->execute([(int)$order['id']]);
            $refusal = self::refusal($order);
            if ($refusal !== null) {
                $db->rollBack();
                return ['ok' => false, 'error' => $refusal, 'id' => 0, 'received_at' => '', 'confirmation' => ''];
            }
            $wanted = array_flip(array_map('intval', $itemIds));
            $lines = [];
            foreach (self::openItems($order) as $item) {
                if (isset($wanted[(int)$item['id']])) $lines[(int)$item['id']] = $item;
            }
            if ($lines === []) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'no_items', 'id' => 0, 'received_at' => '', 'confirmation' => ''];
            }
            $receivedAt = gmdate('Y-m-d H:i:s');
            $db->prepare('INSERT INTO withdrawal_requests (store_id, order_id, order_number, customer_name, customer_email, items, reason, received_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                StoreContext::current()->id(), (int)$order['id'], (string)$order['order_number'], $name !== '' ? $name : (string)$order['customer_name'],
                (string)$order['customer_email'], json_encode(array_map(static fn(array $l): int => (int)$l['quantity'], $lines)), $reason, $receivedAt,
            ]);
            $id = (int)$db->lastInsertId();
            AuditLog::log('withdrawal', $id, null, ['order_id' => (int)$order['id'], 'items' => array_keys($lines), 'received_at' => $receivedAt]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        $confirmation = self::acknowledge($id, $order, $name, $lines, $reason, $receivedAt);
        JobQueue::push('merchant_notify', ['order_id' => (int)$order['id'], 'event' => 'withdrawal:' . $id], 6);
        return ['ok' => true, 'error' => null, 'id' => $id, 'received_at' => $receivedAt, 'confirmation' => $confirmation];
    }

    /** Acknowledgement of receipt on a durable medium: the statement as received and when. */
    private static function acknowledge(int $id, array $order, string $name, array $lines, string $reason, string $receivedAt): string {
        $seller = InvoiceService::sellerIdentity($order);
        $result = MailService::send('withdrawal_received', (string)$order['customer_email'], (string)($order['locale'] ?? ''), [
            'customer_name' => $name !== '' ? $name : (string)$order['customer_name'],
            'order_number' => (string)$order['order_number'],
            'received_at' => $receivedAt . ' UTC',
            'items' => array_map(static fn(array $l): array => ['name' => (string)$l['name'], 'sku' => (string)$l['sku'], 'quantity' => (int)$l['quantity']], array_values($lines)),
            'reason' => $reason,
            'seller_name' => (string)($seller['name'] ?? ''),
            'seller_email' => (string)($seller['email'] ?? ''),
        ], (int)$order['id']);
        $status = (string)($result['status'] ?? 'failed');
        Db::get()->prepare('UPDATE withdrawal_requests SET confirmation_status = ? WHERE id = ?')->execute([$status, $id]);
        return $status;
    }
}
