<?php
/**
 * Round 8: complaints (reklamácie) about defective goods — not a return and not a withdrawal.
 *
 * The seller is liable for a lack of conformity that appears within 24 months of delivery (Directive (EU) 2019/771
 * art. 10). A consumer (guest or customer, identified by order number + the order's e-mail) files a complaint for one
 * delivered line with the defect and the remedy wanted, gets a receipt on a durable medium, and the merchant decides
 * in writing (accepted / rejected / resolved, with a note) — the decision is e-mailed to the consumer. National law
 * sets the decision deadline (SK/CZ 30 days); DECISION_DAYS shows it in admin. Wording is for a lawyer to review.
 */
class Complaints {
    public const STATUSES = ['received', 'in_review', 'accepted', 'rejected', 'resolved'];
    public const REMEDIES = ['repair', 'replacement', 'price_reduction', 'refund'];
    public const DECISION_DAYS = 30;
    /** New complaints one order may file per 24 hours (each sends e-mails). */
    public const MAX_PER_ORDER_PER_DAY = 3;

    public const NEXT = [
        'received' => ['in_review', 'accepted', 'rejected'],
        'in_review' => ['accepted', 'rejected'],
        'accepted' => ['resolved'],
    ];
    /** Statuses whose change the consumer is told about in writing. */
    private const DECISIONS = ['accepted', 'rejected', 'resolved'];
    /** Order statuses whose goods reached (or are on the way to) the consumer even without a delivered_at. */
    private const SHIPPED_STATUSES = ['shipped', 'delivered'];

    /** null when a complaint can be filed for $order, else 'not_delivered' or 'period_over'. */
    public static function refusal(array $order, ?int $now = null): ?string {
        $deliveredAt = (string)($order['delivered_at'] ?? '');
        if ($deliveredAt === '') return in_array((string)($order['status'] ?? ''), self::SHIPPED_STATUSES, true) ? null : 'not_delivered';
        $delivered = strtotime($deliveredAt . ' UTC');
        if ($delivered === false) return null;
        $until = strtotime('+' . ConsumerNotice::LIABILITY_MONTHS . ' months', $delivered);
        return ($now ?? time()) <= $until ? null : 'period_over';
    }

    /**
     * Record a complaint for one line of $order and send the receipt.
     * @return array{ok: bool, error: ?string, id: int, number: string, received_at: string, confirmation: string}
     */
    public static function submit(array $order, int $orderItemId, string $name, string $defect, string $remedy): array {
        $fail = static fn(string $error): array => ['ok' => false, 'error' => $error, 'id' => 0, 'number' => '', 'received_at' => '', 'confirmation' => ''];
        $refusal = self::refusal($order);
        if ($refusal !== null) return $fail($refusal);
        $defect = mb_substr(trim($defect), 0, 4000);
        if ($defect === '') return $fail('defect_required');
        if (!in_array($remedy, self::REMEDIES, true)) return $fail('remedy_required');
        $line = null;
        foreach (Orders::items((int)$order['id']) as $item) if ((int)$item['id'] === $orderItemId) $line = $item;
        if ($line === null) return $fail('no_items');
        // Each complaint e-mails the order's address from the shop: whoever knows an order number and its e-mail must
        // not be able to flood that inbox (and the merchant's). One open complaint per line, a few new ones a day.
        $stmt = Db::get()->prepare("SELECT COUNT(CASE WHEN order_item_id = ? AND status IN ('received', 'in_review') THEN 1 END),
            COUNT(CASE WHEN received_at >= ? THEN 1 END) FROM complaints WHERE order_id = ? AND store_id = ?");
        $stmt->execute([$orderItemId, gmdate('Y-m-d H:i:s', time() - 86400), (int)$order['id'], StoreContext::current()->id()]);
        [$openForLine, $today] = array_map('intval', $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0]);
        if ($openForLine > 0) return $fail('already_open');
        if ($today >= self::MAX_PER_ORDER_PER_DAY) return $fail('too_many');
        $name = mb_substr(trim($name), 0, 191) ?: (string)$order['customer_name'];
        $receivedAt = gmdate('Y-m-d H:i:s');
        $number = 'REK-' . (int)$order['id'] . '-' . strtoupper(bin2hex(random_bytes(3)));
        Db::get()->prepare('INSERT INTO complaints (store_id, complaint_number, order_id, order_item_id, order_number, customer_name, customer_email,
            item_name, defect, remedy, status, received_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            StoreContext::current()->id(), $number, (int)$order['id'], $orderItemId, (string)$order['order_number'], $name,
            (string)$order['customer_email'], (string)$line['name'], $defect, $remedy, 'received', $receivedAt,
        ]);
        $id = (int)Db::get()->lastInsertId();
        AuditLog::log('complaint', $id, null, ['order_id' => (int)$order['id'], 'order_item_id' => $orderItemId, 'remedy' => $remedy, 'received_at' => $receivedAt]);
        $complaint = self::byId($id) ?? [];
        $status = self::mail('complaint_received', $complaint, $order);
        Db::get()->prepare('UPDATE complaints SET confirmation_status = ? WHERE id = ?')->execute([$status, $id]);
        JobQueue::push('merchant_notify', ['order_id' => (int)$order['id'], 'event' => 'complaint:' . $id], 6);
        return ['ok' => true, 'error' => null, 'id' => $id, 'number' => $number, 'received_at' => $receivedAt, 'confirmation' => $status];
    }

    /** Merchant step; accepted / rejected / resolved need a note and are e-mailed to the consumer. Returns an error key or null. */
    public static function setStatus(int $id, string $status, string $note): ?string {
        $complaint = self::byId($id);
        if ($complaint === null) return 'not_found';
        if (!in_array($status, self::NEXT[(string)$complaint['status']] ?? [], true)) return 'transition_refused';
        $note = mb_substr(trim($note), 0, 4000);
        $decision = in_array($status, self::DECISIONS, true);
        if ($decision && $note === '') return 'note_required';
        $stmt = Db::get()->prepare('UPDATE complaints SET status = ?, decision_note = ?, decided_at = ' . ($decision ? Db::now() : 'decided_at') . ' WHERE id = ? AND status = ? AND store_id = ?');
        $stmt->execute([$status, $decision ? $note : (string)($complaint['decision_note'] ?? ''), $id, (string)$complaint['status'], StoreContext::current()->id()]);
        if ($stmt->rowCount() !== 1) return 'transition_refused';
        AuditLog::log('complaint', $id, ['status' => $complaint['status']], ['status' => $status, 'note' => $note]);
        if ($decision) {
            $order = Orders::byId((int)$complaint['order_id']) ?? [];
            self::mail('complaint_decided', self::byId($id) ?? [], $order);
        }
        return null;
    }

    public static function byId(int $id): ?array {
        $stmt = Db::get()->prepare('SELECT * FROM complaints WHERE id = ? AND store_id = ?');
        $stmt->execute([$id, StoreContext::current()->id()]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Complaints of the active store, newest first, optionally of one status. */
    public static function all(?string $status = null, int $limit = 200): array {
        $sql = 'SELECT * FROM complaints WHERE store_id = ?' . ($status !== null ? ' AND status = ?' : '') . ' ORDER BY id DESC LIMIT ' . max(1, $limit);
        $stmt = Db::get()->prepare($sql);
        $stmt->execute($status !== null ? [StoreContext::current()->id(), $status] : [StoreContext::current()->id()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The date (UTC) by which the merchant has to decide. */
    public static function decideBy(array $complaint): string {
        return gmdate('Y-m-d', (int)strtotime((string)$complaint['received_at'] . ' UTC') + self::DECISION_DAYS * 86400);
    }

    private static function mail(string $template, array $complaint, array $order): string {
        $locale = (string)($order['locale'] ?? '');
        $seller = $order ? InvoiceService::sellerIdentity($order) : [];
        $result = MailService::send($template, (string)$complaint['customer_email'], $locale, [
            'customer_name' => (string)$complaint['customer_name'],
            'order_number' => (string)$complaint['order_number'],
            'complaint_number' => (string)$complaint['complaint_number'],
            'item_name' => (string)$complaint['item_name'],
            'defect' => (string)$complaint['defect'],
            'remedy' => I18n::tIn('complaint.remedy.' . $complaint['remedy'], $locale),
            'received_at' => (string)$complaint['received_at'] . ' UTC',
            'decide_by' => self::decideBy($complaint),
            'status' => I18n::tIn('complaint.status.' . $complaint['status'], $locale),
            'decision_note' => (string)($complaint['decision_note'] ?? ''),
            'seller_name' => (string)($seller['name'] ?? ''),
            'seller_email' => (string)($seller['email'] ?? ''),
        ], (int)$complaint['order_id']);
        return (string)($result['status'] ?? 'failed');
    }
}
