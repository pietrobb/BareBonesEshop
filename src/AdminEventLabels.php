<?php
/**
 * R49 (review round 19, low): payment providers and webhook / outbox events as the admin reads them. The dashboard's
 * incident table listed "stripe:charge.refunded" and the order's transaction table "stripe"; the code stays the tooltip.
 */
final class AdminEventLabels {
    private const BRANDS = ['stripe' => 'Stripe', 'payu' => 'PayU', 'gopay' => 'GoPay', 'comgate' => 'Comgate', 'thepay' => 'ThePay'];

    /** A payment provider's code ("stripe", "bank_transfer", "mock") by name; an unknown code as it is. */
    public static function provider(string $code): string {
        return self::BRANDS[strtolower($code)] ?? self::label('admin.payment_provider.', $code) ?? self::label('methods.payment.', $code) ?? $code;
    }

    /** A webhook or outbox event ("stripe:charge.refunded", "order.created") in words; an unknown one as the provider's event. */
    public static function event(string $eventType): string {
        [$provider, $event] = str_contains($eventType, ':') ? explode(':', $eventType, 2) : ['', $eventType];
        $label = self::label('admin.ops.event.', $event) ?? self::label('admin.audit.event.', $event) ?? I18n::t('admin.ops.event.other');
        return $provider === '' ? $label : self::provider($provider) . ': ' . $label;
    }

    private static function label(string $prefix, string $code): ?string {
        $text = I18n::t($prefix . $code);
        return $text === $prefix . $code ? null : $text;
    }
}
