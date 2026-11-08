<?php
/**
 * R20: an audit-log value as the admin reads it — the status codes of orders, payments and invoices and the
 * {"status": …} of products, customers, reviews, shipments and purchase orders by their labels; anything else as stored.
 */
final class AuditLogLabels {
    private const ORDER = ['order_status', 'order_dispute'];
    private const PAYMENT = ['payment_status', 'order_refund', 'payment_link', 'payment_webhook_ignored', 'payment_duplicate_capture'];
    private const STATUS_PREFIX = ['shipment' => 'admin.shipment.status.', 'product' => 'admin.product.status.', 'customer' => 'admin.customer.status.',
        'review' => 'admin.reviews.status.', 'purchase_order' => 'admin.po.status.', 'invoice_cancelled' => 'admin.invoice.status.'];
    /** JSON keys of audit values that hold an amount in cents. */
    private const MONEY_KEYS = ['total', 'cod_amount', 'gross', 'price', 'compare_price', 'amount'];
    /** The currency of the record an audit entry is about. */
    private const CURRENCY_SQL = [
        'order' => 'SELECT currency FROM orders WHERE id = :id',
        'shipment' => 'SELECT o.currency FROM shipments s JOIN orders o ON o.id = s.order_id WHERE s.id = :id',
        'credit_note' => 'SELECT currency FROM invoices WHERE id = :id',
        'product' => 'SELECT currency FROM products WHERE id = :id',
        'price_list' => 'SELECT currency FROM price_lists WHERE id = :id',
    ];
    /** @var array<int, string> */
    private static array $currencies = [];
    /** @var array<string, string> */
    private static array $recordCurrencies = [];

    public static function value(string $entity, ?string $value, ?int $entityId = null): string {
        $value = (string)$value;
        if ($value === '') return '';
        // R21: the amount a Stripe refund webhook records is in cents: shown in the order's currency.
        if ($entity === 'order_refund' && $entityId !== null && ctype_digit($value)) return self::amount((int)$value, self::orderCurrency($entityId));
        $code = preg_match('/^[a-z_]+$/', $value) === 1;
        if ($code && in_array($entity, self::ORDER, true)) return OrderStatusLabels::order($value);
        if ($code && in_array($entity, self::PAYMENT, true)) return OrderStatusLabels::payment($value);
        $prefix = self::STATUS_PREFIX[$entity] ?? null;
        if ($prefix !== null && $code) return self::label($prefix, $value);
        $fields = json_decode($value, true);
        $status = $prefix !== null && is_array($fields) && is_string($fields['status'] ?? null);
        $money = isset(self::CURRENCY_SQL[$entity]) && is_array($fields) && !array_is_list($fields) && self::hasAmount($fields);
        if (!$status && !$money) return $value;
        // R22: amounts in the JSON ("total": 1647000, "cod_amount", "gross", "price") are cents: shown in the record's currency.
        if ($money) $fields = self::amounts($fields, (string)($fields['currency'] ?? self::recordCurrency($entity, (int)$entityId)));
        $parts = [];
        foreach ($fields as $field => $fieldValue) {
            $parts[] = $field === 'status' && $status ? I18n::t('admin.th.status') . ': ' . self::label((string)$prefix, $fieldValue)
                : $field . ': ' . (is_scalar($fieldValue) ? (string)$fieldValue : (string)json_encode($fieldValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return implode('; ', $parts);
    }

    /** @param array<array-key, mixed> $fields */
    private static function amounts(array $fields, string $currency): array {
        foreach ($fields as $key => $value) {
            if (is_array($value)) $fields[$key] = self::amounts($value, $currency);
            elseif (is_int($value) && in_array($key, self::MONEY_KEYS, true)) $fields[$key] = self::amount($value, $currency);
        }
        return $fields;
    }

    /** @param array<array-key, mixed> $fields */
    private static function hasAmount(array $fields): bool {
        foreach ($fields as $key => $value) {
            if (is_array($value) ? self::hasAmount($value) : is_int($value) && in_array($key, self::MONEY_KEYS, true)) return true;
        }
        return false;
    }

    private static function recordCurrency(string $entity, int $id): string {
        $key = $entity . ':' . $id;
        if (!isset(self::$recordCurrencies[$key])) {
            $stmt = Db::get()->prepare(self::CURRENCY_SQL[$entity]);
            $stmt->execute([':id' => $id]);
            self::$recordCurrencies[$key] = (string)($stmt->fetchColumn() ?: '');
            if (self::$recordCurrencies[$key] === '' && StoreContext::hasCurrent()) self::$recordCurrencies[$key] = StoreContext::current()->currency();
        }
        return self::$recordCurrencies[$key];
    }

    /** R21: a refund amount written into an audit note, in the order's currency ("16 470 Ft"), not in cents. */
    public static function amount(int $cents, mixed $currency): string {
        return I18n::price($cents, strtolower((string)($currency ?: 'eur')));
    }

    /**
     * R21: a note as the admin reads it. Notes written before 2.1.3 carried refund amounts in cents
     * ("Manual refund of 1647000 completed by …", "Stripe full refund: 1647000 cents"): shown in the order's currency.
     * @param (callable(int): ?string)|null $orderCurrency the order's currency, by default read once per order
     */
    public static function note(string $entity, int $entityId, ?string $note, ?callable $orderCurrency = null): string {
        $note = (string)$note;
        // R22: a payment status change is noted with its event code ("admin.paid", "refund.requested"): in words.
        if ($entity === 'payment_status' && preg_match('/^[a-z_]+(?:\.[a-z_]+)+$/', $note) === 1) return self::event($note);
        // R23 UX: the notes the code writes (in English) in the admin's language; the log keeps what was written.
        if (isset(self::NOTES[$note])) return I18n::t('admin.audit.note.' . self::NOTES[$note]);
        foreach (self::NOTE_PREFIXES as $prefix => $key) {
            if (str_starts_with($note, $prefix) && strlen($note) > strlen($prefix)) return I18n::format('admin.audit.note.' . $key, ['detail' => substr($note, strlen($prefix))]);
        }
        if (preg_match('/^Admin [a-z][a-z -]* delete$/', $note) === 1) return I18n::t('admin.audit.note.deleted');
        if (preg_match('/^Admin (?:' . self::SAVED_ENTITIES . ')$/', $note) === 1) return I18n::t('admin.audit.note.saved');
        if (preg_match('/^Admin [a-z_]+: (.+)$/s', $note, $m) === 1) return I18n::format('admin.audit.note.saved_detail', ['detail' => $m[1]]);
        if (preg_match('/^(\S+) capture of an earlier payment attempt$/', $note, $m) === 1) return I18n::format('admin.audit.note.earlier_attempt', ['provider' => $m[1]]);
        if (!in_array($entity, ['order_refund', 'order_status'], true)) return $note;
        $orderCurrency ??= static fn(int $orderId): string => self::orderCurrency($orderId);
        // An amount written in cents before 2.1.3 (R22) or already formatted: in the order's currency either way.
        $money = static fn(string $amount): string => ctype_digit($amount) ? self::amount((int)$amount, $orderCurrency($entityId)) : $amount;
        if (preg_match('/^Refund of (.+?) re-queued by (.*)$/s', $note, $m) === 1) {
            return I18n::format('admin.audit.note.refund_requeued', ['amount' => $money($m[1]), 'actor' => $m[2]]);
        }
        if (preg_match('/^(\S+) refund (\S+) of (.+) failed$/', $note, $m) === 1) {
            return I18n::format('admin.audit.note.refund_failed', ['provider' => $m[1], 'id' => $m[2], 'amount' => $money($m[3])]);
        }
        if (preg_match('/^(Provider|Manual) refund of (.+?) (?:confirmed|completed) by (.*)$/s', $note, $m) === 1) {
            return I18n::format('admin.audit.note.refund_' . strtolower($m[1]), ['amount' => $money($m[2]), 'detail' => $m[3]]);
        }
        if (preg_match('/^Stripe full refund: (.+?)(?: cents)?$/', $note, $m) === 1) {
            return I18n::format('admin.audit.note.stripe_full_refund', ['amount' => $money($m[1])]);
        }
        if (preg_match('~^Stripe refund: (\d+)/(\d+) cents$~', $note, $m) === 1 || preg_match('~^Stripe refund: (.+) of (.+)$~', $note, $m) === 1) {
            return I18n::format('admin.audit.note.stripe_refund', ['amount' => $money($m[1]), 'total' => $money($m[2])]);
        }
        return $note;
    }

    /** R23 UX: fixed audit notes => admin.audit.note.<key>. */
    private const NOTES = [
        'Admin create' => 'created', 'Content deleted' => 'deleted', 'Admin bulk delete' => 'deleted', 'Admin duplicate' => 'duplicated',
        'Order created' => 'order_created', 'Synthetic smoke order created' => 'smoke_order', 'Synthetic smoke checkout closed' => 'smoke_closed',
        'Manual order entered in the admin' => 'manual_order', 'Order items edited by admin' => 'items_edited', 'Item credit issued' => 'item_credit',
        'Invoice issued' => 'invoice_issued', 'CSV import' => 'csv_import', 'Cache flushed' => 'cache_flushed',
        'Re-authentication locked after repeated wrong passwords; session ended' => 'reauth_locked',
        'Admin price list prices' => 'prices', 'Admin supplier products' => 'supplier_products', 'Admin purchase order receipt' => 'receipt',
        'Admin variants' => 'variants', 'Admin digital asset toggle' => 'toggled', 'Admin review moderation' => 'moderated',
        'Admin customer status' => 'status', 'Admin shipment label' => 'label', 'Admin shipment shipped' => 'shipped',
        'Admin shipment tracking' => 'tracking', 'Admin shipment cancelled' => 'shipment_cancelled', 'Admin feed sync' => 'feed_sync',
        'Admin e-mail template override' => 'template_saved', 'Admin e-mail template reset' => 'template_reset', 'Admin B2B assignment' => 'b2b_assignment',
        'Admin status change' => 'status_change',
    ];
    /** "Admin <entity>" notes of a plain admin save. */
    private const SAVED_ENTITIES = 'category|brand|promotion|price list|supplier|purchase order|shipment|digital asset save';
    /** R23 UX: notes "<prefix><detail>" => admin.audit.note.<key> with {detail}. Longer prefixes first. */
    private const NOTE_PREFIXES = [
        'Admin edit: ' => 'edited', 'Admin bulk price ' => 'bulk_price', 'Admin bulk ' => 'bulk', 'Refund: ' => 'refund',
        'Credit note reversed: ' => 'credit_reversed', 'Capture under another provider reference: ' => 'other_reference',
        'Payment captured on a cancelled order: ' => 'captured_cancelled', 'API v1 client ' => 'api_client',
        'New payment session ' => 'payment_session', 'Payment link e-mailed by ' => 'payment_link_sent', 'Stripe dispute: ' => 'dispute',
    ];

    /** "admin.paid" → "Set in the admin: Paid"; a known event by its label; a provider's own event code as it is. */
    private static function event(string $code): string {
        $status = str_starts_with($code, 'admin.') ? substr($code, 6) : '';
        $text = self::label('admin.audit.event.', $code);
        if ($text !== $code || $status === '' || str_contains($status, '.')) return $text;
        return strtr(I18n::t('admin.audit.event.admin'), ['{status}' => OrderStatusLabels::payment($status)]);
    }

    private static function orderCurrency(int $orderId): string {
        return self::$currencies[$orderId] ??= (string)(Orders::byId($orderId)['currency'] ?? '');
    }

    private static function label(string $prefix, string $code): string {
        $text = I18n::t($prefix . $code);
        return $text === $prefix . $code ? $code : $text;
    }
}
