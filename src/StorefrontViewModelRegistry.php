<?php
/** Namespaced module data providers for storefront view models. */
final class StorefrontViewModelRegistry
{
    /** @var array<string,array<string,callable>> */
    private static array $providers = [];

    public static function register(string $context, string $namespace, callable $provider): void
    {
        self::assertSlug($context, 'view-model context');
        self::assertSlug($namespace, 'extension namespace');
        self::$providers[$context][$namespace] = $provider;
    }

    public static function extensions(string $context, array $data): array
    {
        self::assertSlug($context, 'view-model context');
        $extensions = [];
        foreach (self::$providers[$context] ?? [] as $namespace => $provider) {
            $value = $provider($data);
            if (!is_array($value)) {
                throw new UnexpectedValueException("Storefront extension {$namespace} must return an array");
            }
            if ($value !== []) $extensions[$namespace] = $value;
        }
        return $extensions;
    }

    public static function has(string $context, string $namespace): bool
    {
        return isset(self::$providers[$context][$namespace]);
    }

    public static function reset(): void
    {
        self::$providers = [];
    }

    private static function assertSlug(string $value, string $label): void
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,79}$/', $value)) {
            throw new InvalidArgumentException("Invalid {$label}");
        }
    }
}
