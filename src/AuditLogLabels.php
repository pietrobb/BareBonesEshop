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
        if (!in_array($entity, ['order_refund', 'order_status'], true)) return $note;
        $orderCurrency ??= static fn(int $orderId): string => self::orderCurrency($orderId);
        // R22: written in cents before 2.1.3 as well.
        if (preg_match('/^(Refund of )(\d+)( re-queued by .*)$/s', $note, $m) === 1
            || preg_match('/^(\S+ refund \S+ of )(\d+)( failed)$/', $note, $m) === 1) {
            return $m[1] . self::amount((int)$m[2], $orderCurrency($entityId)) . $m[3];
        }
        if (preg_match('/^((?:Provider|Manual) refund of )(\d+)( (?:confirmed|completed) by .*)$/s', $note, $m) === 1) {
            return $m[1] . self::amount((int)$m[2], $orderCurrency($entityId)) . $m[3];
        }
        if (preg_match('/^Stripe full refund: (\d+) cents$/', $note, $m) === 1) {
            return 'Stripe full refund: ' . self::amount((int)$m[1], $orderCurrency($entityId));
        }
        if (preg_match('~^Stripe refund: (\d+)/(\d+) cents$~', $note, $m) === 1) {
            $currency = $orderCurrency($entityId);
            return 'Stripe refund: ' . self::amount((int)$m[1], $currency) . ' of ' . self::amount((int)$m[2], $currency);
        }
        return $note;
    }

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
