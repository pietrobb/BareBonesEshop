<?php
/**
 * R8: customer-facing names of the shipping and payment methods in the shopper's language. The methods the
 * migrations seed carry one Slovak name for every store; while a method still has that seeded name (and
 * description), the storefront shows the locale's lang/<locale>.php text (methods.<kind>.<code>[_desc]).
 * A name the merchant changed is the merchant's and is shown as entered.
 */
final class MethodLabels {
    /** Seeded [name, description] per kind and code (migrations 20260425064000, 20260425071000 and 20260801190000). */
    private const SEEDED = [
        'shipping' => [
            'pickup' => ['Osobný odber', null],
            'packeta' => ['Packeta / Zásielkovňa', null],
            'gls' => ['GLS kuriér', null],
        ],
        'payment' => [
            'card' => ['Kartou online', 'Online card payment via configured gateway.'],
            'bank_transfer' => ['Bankový prevod', 'Manual bank transfer after order creation.'],
            'cod' => ['Dobierka', 'Cash on delivery where the selected carrier supports COD.'],
            // Migration 20260801190000 seeds the B2B method with an English name.
            'invoice' => ['Invoice purchase', 'Approved B2B account with credit limit and payment terms.'],
        ],
    ];

    /** Codes of the built-in shipping adapters (Adapters::shipping) that are stored as a shipment's carrier. */
    private const CARRIERS = ['pickup', 'packeta', 'gls'];

    /** $locale null = the current locale; a document (invoice, e-mail) passes its own language. */
    public static function name(string $kind, string $code, string $name, ?string $locale = null): string {
        return self::translated($kind, $code, $name, 0, '', $locale);
    }

    public static function description(string $kind, string $code, string $description, ?string $locale = null): string {
        return self::translated($kind, $code, $description, 1, '_desc', $locale);
    }

    /**
     * The customer-facing name of a carrier on a shipment (e-mail, account, order status page): a built-in carrier
     * code (packeta, gls, pickup) reads as its name in $locale (carrier_name.<code>); a carrier the merchant typed
     * in ("DPD") is shown as entered.
     */
    public static function carrier(string $carrier, ?string $locale = null): string {
        $code = strtolower(trim($carrier));
        if (!in_array($code, self::CARRIERS, true)) return $carrier;
        $key = 'carrier_name.' . $code;
        $text = $locale === null || $locale === '' ? I18n::t($key) : I18n::tIn($key, $locale);
        return $text === $key || $text === '' ? $carrier : $text;
    }

    private static function translated(string $kind, string $code, string $value, int $field, string $suffix, ?string $locale): string {
        $seeded = self::SEEDED[$kind][$code][$field] ?? null;
        if ($seeded === null || $value !== $seeded) return $value;
        $key = 'methods.' . $kind . '.' . $code . $suffix;
        $text = $locale === null || $locale === '' ? I18n::t($key) : I18n::tIn($key, $locale);
        return $text === $key || $text === '' ? $value : $text;
    }
}
