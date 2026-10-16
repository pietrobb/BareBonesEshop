<?php
final class StorefrontWishlistExtension
{
    public static function register(): void
    {
        if (!class_exists(Wishlist::class) || StorefrontViewModelRegistry::has('product', 'wishlist.product')) return;
        StorefrontViewModelRegistry::register('product', 'wishlist.product', static function(array $context): array {
            $product = $context['model']['product'];
            $active = Wishlist::has((int)$product['id']);
            $translations = I18n::all();
            return [
                'active' => $active,
                'pressed' => $active ? 'true' : 'false',
                'label' => $active ? ($translations['wishlist.in'] ?? 'In wishlist') : ($translations['wishlist.add'] ?? 'Add to wishlist'),
                'toggle_url' => Template::storeUrl('/wishlist.php'),
                'return_url' => (string)$product['url'],
                'csrf_token' => Security::csrfToken(),
            ];
        });
    }
}
