<?php
/** Canonical application boundary shared by HTML and JSON checkout transports. */
class CheckoutService {
    public static function isDigitalOnly(array $items): bool {
        if (!function_exists('cartIsDigitalOnly')) require_once __DIR__ . '/Http/ApiHandlers.php';
        return cartIsDigitalOnly($items);
    }

    public static function digitalRate(): array {
        if (!function_exists('digitalDeliveryRate')) require_once __DIR__ . '/Http/ApiHandlers.php';
        return digitalDeliveryRate();
    }

    public static function consentDefinitions(string $country, array $items): array {
        $available = array_filter($items, static fn(array $item): bool => $item['available'] ?? true);
        return ConsentService::definitions(['country' => $country, 'product_types' => array_values(array_unique(array_column($available, 'product_type')))]);
    }

    public static function execute(array $input, ?array $runtimeConfig = null): array {
        if (!function_exists('checkoutExecute')) {
            require_once __DIR__ . '/Http/ApiHandlers.php';
        }
        foreach (['name', 'email', 'phone', 'country', 'street', 'city', 'zip', 'shipping_method', 'payment_method', 'vat_id', 'coupon_code', 'notes'] as $key) {
            if (isset($input[$key]) && !is_string($input[$key])) {
                http_response_code(422);
                return ['success' => false, 'error' => I18n::t('checkout.failed'), 'error_code' => 'checkout.invalid_input'];
            }
        }
        return checkoutExecute($input, $runtimeConfig);
    }
}
