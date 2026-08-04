<?php
/** Storefront transport boundary for canonical cart commands. */
final class StorefrontCartAction
{
    public static function execute(array $input): array
    {
        $productId = Security::int($input['product_id'] ?? 0);
        $variantId = !empty($input['variant_id']) ? Security::int($input['variant_id']) : null;
        $quantity = max(0, Security::int($input['quantity'] ?? 1));
        $result = ['ok' => true, 'error' => null];
        switch ((string)($input['cart_action'] ?? '')) {
            case 'add':
                $result = Cart::addValidated($productId, $variantId, $quantity);
                break;
            case 'update':
                Cart::update(self::key($input, $productId, $variantId), $quantity);
                break;
            case 'remove':
                Cart::update(self::key($input, $productId, $variantId), 0);
                break;
            case 'clear':
                Cart::clear();
                break;
            default:
                return ['ok' => false, 'error' => 'cart_error', 'count' => self::count(), 'cart' => Cart::get()];
        }
        $result['count'] = self::count();
        $result['cart'] = Cart::get();
        return $result;
    }

    public static function count(): int
    {
        return (int)Cart::enriched()['count'];
    }

    private static function key(array $input, int $productId, ?int $variantId): string
    {
        return (string)($input['cart_key'] ?? ($variantId ? "v_{$variantId}" : "p_{$productId}"));
    }
}
