<?php
/** Storefront transport boundary for canonical cart commands. */
final class StorefrontCartAction
{
    public static function execute(array $input): array
    {
        $productId = Security::int($input['product_id'] ?? 0);
        // The product page's no-JS form submits its variant radios (name="variant-{product id}") as they are named.
        $variantInput = $input['variant_id'] ?? $input['variant-' . $productId] ?? null;
        $variantId = !empty($variantInput) ? Security::int($variantInput) : null;
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

    /** One-shot notice for the page a no-JS add redirects back to. */
    public static function flash(array $result): void
    {
        StorefrontFlash::set(self::notice($result));
    }

    /** Notice of a no-JS add: what was added (or the clamped quantity), else why nothing was. */
    public static function notice(array $result): string
    {
        if (!$result['ok']) return I18n::t((string)($result['error'] ?? 'cart_error'));
        return !empty($result['clamped'])
            ? I18n::format('cart_quantity_clamped', ['count' => (int)$result['quantity']])
            : I18n::t('cart_added_notice');
    }

    public static function count(): int
    {
        return (int)Cart::enriched()['count'];
    }

    /** Reminder-link commands: refill the cart or opt the recipient out. */
    public static function recover(string $token): bool
    {
        return AbandonedCarts::restore($token);
    }

    public static function unsubscribeReminder(string $token): bool
    {
        return AbandonedCarts::unsubscribe($token);
    }

    private static function key(array $input, int $productId, ?int $variantId): string
    {
        return (string)($input['cart_key'] ?? ($variantId ? "v_{$variantId}" : "p_{$productId}"));
    }
}
