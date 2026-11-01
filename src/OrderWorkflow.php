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
     * Next states of this order. Cash on delivery is paid when the carrier hands the parcel over, and a B2B purchase
     * on invoice by the invoice's due date, so such an unpaid order is fulfilled from 'pending' (processing = accepted,
     * shipped) without being marked paid first; the label keeps the amount the carrier collects.
     */
    public static function allowedFor(array $order): array {
        $allowed = self::allowedTransitions((string)($order['status'] ?? ''));
        if (($order['status'] ?? '') === 'pending' && self::paidAfterFulfillment($order)) {
            $allowed = array_merge($allowed, ['processing', 'shipped']);
        }
        return $allowed;
    }

    /** An unpaid order whose money comes after the goods: cash on delivery or a purchase on invoice. */
    public static function paidAfterFulfillment(array $order): bool {
        return self::isUnpaidCashOnDelivery($order) || self::isUnpaidInvoicePurchase($order);
    }

    /** An order paid by cash on delivery whose money the carrier has not collected yet. */
    public static function isUnpaidCashOnDelivery(array $order): bool {
        if (!in_array((string)($order['payment_status'] ?? 'pending'), ['pending', ''], true)) return false;
        $type = self::paymentServiceType($order);
        return $type !== '' ? $type === 'cod' : PaymentService::isCashOnDelivery((string)($order['payment_method'] ?? ''));
    }

    /** A B2B purchase on invoice (payment terms of an approved company) whose invoice is not paid yet. */
    public static function isUnpaidInvoicePurchase(array $order): bool {
        return in_array((string)($order['payment_status'] ?? 'pending'), ['pending', ''], true) && self::isInvoicePurchase($order);
    }

    public static function isInvoicePurchase(array $order): bool {
        return self::paymentServiceType($order) === 'invoice';
    }

    private static function paymentServiceType(array $order): string {
        $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? ''), true);
        return is_array($snapshot) ? (string)($snapshot['service_type'] ?? '') : '';
    }

    /**
     * Why an admin may not move this order to $to (null = allowed). Shared by the order
     * detail and bulk actions: 'paid' needs a confirmed payment, a move that returns money the refund right,
     * shipping an order the consumer withdrew from an explicit acknowledgement.
     */
    public static function adminRejection(?array $order, string $to, bool $mayRefund, bool $withdrawalAcknowledged = false): ?string {
        if (!$order) return 'not_found';
        if (!in_array($to, self::statuses(), true)) return 'invalid_status';
        if (!$mayRefund && self::returnsMoney($order, $to)) return 'forbidden';
        if (!in_array($to, self::allowedFor($order), true)) return 'invalid_transition';
        if ($to === 'paid' && ($order['payment_status'] ?? 'pending') !== 'paid') return 'payment_not_confirmed';
        if (self::refundOutstanding($order, $to)) return 'refund_outstanding';
        if ($to === 'shipped' && !$withdrawalAcknowledged && Withdrawal::forOrder((int)$order['id'])) return 'withdrawn';
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
                    Logger::phpLog("Bulk order status error [{$orderId}]: " . $e->getMessage());
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
        if (!in_array($to, self::allowedFor($order), true)) {
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
            'processing' => self::handleAccepted($order),
            'cancelled' => self::handleCancellation($order),
            'shipped'   => self::handleShipped($order),
            'delivered' => self::handleDelivered($order),
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
                // Checkout confirms offline orders at once; an order paid online (card, gateway) or a free digital one
                // gets its confirmation — seller identity, withdrawal form, terms — when the payment arrives.
                'template' => self::confirmedAtPayment($order) ? 'order_confirmation' : 'order_paid',
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

    /** True for orders checkout did not confirm by e-mail (see checkoutExecute): online payment or a free digital order. */
    public static function confirmedAtPayment(array $order): bool {
        $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? ''), true);
        return !empty($snapshot['requires_online']) || ($order['payment_method'] ?? '') === 'free';
    }

    /**
     * Cancellation: restore stock (in DB); an unpaid payment is closed and voided at the
     * provider, a captured one is refunded through the refund ledger. After a partial shipment
     * only the goods that never shipped are released and refunded: the shipped units come back
     * through RMA and its credit note, and shipping and fees stay charged.
     */
    private static function handleCancellation(array $order): array {
        Fulfillment::cancelPlanned((int)$order['id']);
        Orders::restoreStock($order['id']);
        Pricing::releaseCouponUse((int)$order['id']);

        return array_merge([
            ['email_send', [
                'template' => 'order_cancelled',
                'to'       => $order['customer_email'],
                'locale'   => $order['locale'] ?? '',
                'order_id' => $order['id'],
            ], 5],
        ], PaymentService::cancellationJobsWithin((int)$order['id'], Fulfillment::unshippedGross((int)$order['id'])));
    }

    /**
     * Shipped: release warehouse reservations (goods physically left), return deferred jobs.
     * Decrements warehouse_stock.reserved and warehouse_stock.qty for shipped items
     * so available stock reflects reality without waiting for the next feed sync.
     */
    private static function handleShipped(array $order): array {
        Fulfillment::shipOrder((int)$order['id']);

        // A cash-on-delivery order or a purchase on invoice never passed 'paid': its invoice is issued now (idempotent
        // when accepting the invoice purchase already issued it) and travels with the parcel.
        $invoice = self::paidAfterFulfillment($order) ? [['invoice_issue', ['order_id' => $order['id']], 3]] : [];
        return [...$invoice,
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
     * Accepted: a purchase on invoice moved from 'pending' to 'processing' is the seller accepting the order on the
     * company's payment terms. The invoice, due after those terms, is issued and e-mailed now; the order then waits
     * for payment while it is fulfilled ("on invoice, awaiting payment") and is marked paid when the money arrives.
     */
    private static function handleAccepted(array $order): array {
        if (($order['status'] ?? '') !== 'pending' || !self::isUnpaidInvoicePurchase($order)) return [];
        return [
            ['invoice_issue', ['order_id' => $order['id']], 3],
            ['erp_order_update', ['order_id' => $order['id'], 'status' => 'processing'], 5],
        ];
    }

    /**
     * Delivered: the carrier collected the cash on delivery, so the payment is recorded as paid with the collected
     * amount (it then counts as revenue); a later carrier report or a repeated delivery records nothing twice.
     */
    private static function handleDelivered(array $order): array {
        if (!self::isUnpaidCashOnDelivery($order)) return [];
        PaymentService::transitionPayment((int)$order['id'], 'paid', 'cod.collected', (int)$order['total'],
            'cod_collected_' . (int)$order['id'], ['source' => 'delivery']);
        return [];
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

}
