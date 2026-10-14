<?php
/** Per-request composition root for optional storefront providers. */
final class StorefrontComposition
{
    public static function boot(): void
    {
        foreach ([
            StorefrontCoreSectionProviders::class,
            StorefrontReviewExtension::class,
            StorefrontWishlistExtension::class,
            StorefrontAutomotiveExtension::class,
        ] as $provider) {
            if (class_exists($provider)) $provider::register();
        }
        Extensions::registerStorefront();
    }
}
