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
    /** @var array<int, string> */
    private static array $currencies = [];

    public static function value(string $entity, ?string $value, ?int $entityId = null): string {
        $value = (string)$value;
        if ($value === '') return '';
        // R21: the amount a Stripe refund webhook records is in cents: shown in the order's currency.
        if ($entity === 'order_refund' && $entityId !== null && ctype_digit($value)) return self::amount((int)$value, self::orderCurrency($entityId));
        $code = preg_match('/^[a-z_]+$/', $value) === 1;
        if ($code && in_array($entity, self::ORDER, true)) return OrderStatusLabels::order($value);
        if ($code && in_array($entity, self::PAYMENT, true)) return OrderStatusLabels::payment($value);
        $prefix = self::STATUS_PREFIX[$entity] ?? null;
        if ($prefix === null) return $value;
        if ($code) return self::label($prefix, $value);
        $fields = json_decode($value, true);
        if (!is_array($fields) || !is_string($fields['status'] ?? null)) return $value;
        $parts = [];
        foreach ($fields as $field => $fieldValue) {
            $parts[] = $field === 'status' ? I18n::t('admin.th.status') . ': ' . self::label($prefix, $fieldValue)
                : $field . ': ' . (is_scalar($fieldValue) ? (string)$fieldValue : (string)json_encode($fieldValue, JSON_UNESCAPED_UNICODE));
        }
        return implode('; ', $parts);
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
        if (!in_array($entity, ['order_refund', 'order_status'], true)) return $note;
        $orderCurrency ??= static fn(int $orderId): string => self::orderCurrency($orderId);
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

    private static function orderCurrency(int $orderId): string {
        return self::$currencies[$orderId] ??= (string)(Orders::byId($orderId)['currency'] ?? '');
    }

    private static function label(string $prefix, string $code): string {
        $text = I18n::t($prefix . $code);
        return $text === $prefix . $code ? $code : $text;
    }
}
