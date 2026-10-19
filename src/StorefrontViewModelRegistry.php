<?php
/** Namespaced module data providers for storefront view models. */
final class StorefrontViewModelRegistry
{
    /** @var array<string,array<string,callable>> */
    private static array $providers = [];

    public static function register(string $context, string $namespace, callable $provider): void
    {
        self::assertSlug($context, 'view-model context');
        self::assertNamespace($namespace);
        self::$providers[$context][$namespace] = $provider;
    }

    public static function extensions(string $context, array $data): array
    {
        self::assertSlug($context, 'view-model context');
        $extensions = [];
        foreach (self::$providers[$context] ?? [] as $namespace => $provider) {
            // One failing module (extension, reviews, ...) leaves only its own block out, never the whole page.
            try {
                $value = $provider($data);
                if (!is_array($value)) {
                    throw new UnexpectedValueException("Storefront extension {$namespace} must return an array");
                }
            } catch (Throwable $e) {
                Logger::warning('storefront.extension_failed', ['context' => $context, 'namespace' => $namespace, 'exception_type' => get_class($e)]);
                IncidentMonitor::report('storefront.extension_failed', 'storefront', 'warning', ['exception' => $e]);
                continue;
            }
            if ($value === []) continue;
            $target =& $extensions;
            foreach (explode('.', $namespace) as $segment) $target =& $target[$segment];
            $target = $value;
            unset($target);
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

    private static function assertNamespace(string $value): void
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,39}(?:\.[a-z][a-z0-9_-]{0,39})*$/', $value)) {
            throw new InvalidArgumentException('Invalid extension namespace');
        }
    }
}
