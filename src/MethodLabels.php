<?php
/**
 * R8: customer-facing names of the shipping and payment methods in the shopper's language. The methods the
 * migrations seed carry one Slovak name for every store; while a method still has that seeded name (and
 * description), the storefront shows the locale's lang/<locale>.php text (methods.<kind>.<code>[_desc]).
 * A name the merchant changed is the merchant's and is shown as entered.
 */
final class MethodLabels {
    /** Seeded [name, description] per kind and code (migrations 20260425064000 and 20260425071000). */
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
        ],
    ];

    public static function name(string $kind, string $code, string $name): string {
        return self::translated($kind, $code, $name, 0, '');
    }

    public static function description(string $kind, string $code, string $description): string {
        return self::translated($kind, $code, $description, 1, '_desc');
    }

    private static function translated(string $kind, string $code, string $value, int $field, string $suffix): string {
        $seeded = self::SEEDED[$kind][$code][$field] ?? null;
        if ($seeded === null || $value !== $seeded) return $value;
        $key = 'methods.' . $kind . '.' . $code . $suffix;
        $text = I18n::t($key);
        return $text === $key || $text === '' ? $value : $text;
    }
}
