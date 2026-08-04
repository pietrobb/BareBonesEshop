<?php
/** Allowlisted, namespaced data providers for CMS/theme storefront sections. */
final class StorefrontSectionRegistry
{
    /** @var array<string,callable> */
    private static array $providers = [];

    public static function register(string $name, callable $provider): void
    {
        self::assertName($name);
        self::$providers[$name] = $provider;
    }

    public static function resolve(string $name, array $parameters, array $context = []): array
    {
        self::assertName($name);
        if (!isset(self::$providers[$name])) return ['status' => 'unknown_provider', 'items' => []];
        $value = (self::$providers[$name])($parameters, $context);
        if (!is_array($value)) throw new UnexpectedValueException("Storefront section provider {$name} must return an array");
        return $value;
    }

    public static function has(string $name): bool
    {
        return isset(self::$providers[$name]);
    }

    public static function reset(): void
    {
        self::$providers = [];
    }

    private static function assertName(string $name): void
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,39}\.[a-z][a-z0-9_-]{0,39}$/', $name)) {
            throw new InvalidArgumentException('Invalid storefront section provider');
        }
    }
}
