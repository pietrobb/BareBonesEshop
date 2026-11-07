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

    public static function value(string $entity, ?string $value): string {
        $value = (string)$value;
        if ($value === '') return '';
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

    private static function label(string $prefix, string $code): string {
        $text = I18n::t($prefix . $code);
        return $text === $prefix . $code ? $code : $text;
    }
}
