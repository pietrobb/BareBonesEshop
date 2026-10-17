<?php
/** One-shot storefront notice for the no-JS POST → redirect flows (cart add, wishlist), shown in a live region. */
final class StorefrontFlash
{
    private const KEY = 'storefront_flash';

    public static function set(string $message): void
    {
        $_SESSION[self::KEY] = $message;
    }

    public static function pull(): string
    {
        $message = $_SESSION[self::KEY] ?? '';
        unset($_SESSION[self::KEY]);
        return is_string($message) ? $message : '';
    }
}
