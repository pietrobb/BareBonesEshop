<?php
/** Canonical application boundary shared by HTML and JSON checkout transports. */
class CheckoutService {
    public static function execute(array $input, ?array $runtimeConfig = null): array {
        if (!function_exists('checkoutExecute')) {
            require_once __DIR__ . '/Http/ApiHandlers.php';
        }
        return checkoutExecute($input, $runtimeConfig);
    }

    /**
     * Discount the checkout form shows before the order exists, with the same rules execute() applies:
     * the coupon (refusal reason when it gives nothing) plus automatic promotions.
     * @return array{coupon_discount: int, coupon_error: ?string, promotion_discount: int, free_shipping: bool}
     */
    public static function discountPreview(string $couponCode, int $subtotal, ?string $email, array $items, ?int $customerId): array {
        $couponDiscount = 0;
        $couponError = null;
        if ($couponCode !== '') {
            $coupon = Pricing::applyCoupon($couponCode, $subtotal, $email);
            $couponDiscount = max(0, (int)$coupon['discount']);
            if ($couponDiscount === 0) $couponError = (string)($coupon['error'] ?? '');
        }
        $promotionDiscount = 0;
        $freeShipping = false;
        foreach (Pricing::autoCartRules($subtotal, $items, $customerId) as $rule) {
            if ($rule['action'] === 'free_shipping') $freeShipping = true;
            $promotionDiscount += max(0, (int)($rule['discount'] ?? 0));
        }
        return ['coupon_discount' => $couponDiscount, 'coupon_error' => $couponError, 'promotion_discount' => $promotionDiscount, 'free_shipping' => $freeShipping];
    }
}
