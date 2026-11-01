<?php
/**
 * Customer-facing labels of order and payment status codes (order_status.* / payment_status.* lang keys).
 * Shared by the order confirmation page, order e-mails and the customer account. An unknown code is shown
 * as a readable phrase, never as the raw lang key.
 */
final class OrderStatusLabels
{
    public static function order(string $status, ?string $locale = null): string
    {
        return self::label('order_status.', $status, $locale);
    }

    public static function payment(string $status, ?string $locale = null): string
    {
        return self::label('payment_status.', $status, $locale);
    }

    /** The payment status of this order: an unpaid purchase on invoice reads "on invoice, awaiting payment". */
    public static function paymentOf(array $order, ?string $locale = null): string
    {
        return OrderWorkflow::isUnpaidInvoicePurchase($order)
            ? self::label('payment_status.', 'invoiced', $locale)
            : self::payment((string)($order['payment_status'] ?? 'pending'), $locale);
    }

    private static function label(string $prefix, string $status, ?string $locale): string
    {
        $key = $prefix . $status;
        $label = $locale === null || $locale === '' ? I18n::t($key) : I18n::tIn($key, $locale);
        return $label !== $key ? $label : ucfirst(str_replace('_', ' ', $status));
    }
}
