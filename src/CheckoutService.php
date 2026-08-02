<?php
/** Canonical application boundary shared by HTML and JSON checkout transports. */
class CheckoutService {
    public static function execute(array $input, ?array $runtimeConfig = null): array {
        if (!function_exists('checkoutExecute')) {
            require_once __DIR__ . '/Http/ApiHandlers.php';
        }
        return checkoutExecute($input, $runtimeConfig);
    }
}
