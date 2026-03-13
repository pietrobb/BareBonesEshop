<?php
/**
 * BareBones Shop — Adapter Factory
 * Resolves adapter names from config to concrete implementations.
 */
class Adapters {
    private static array $instances = [];

    private static function cfg(): array {
        static $cfg;
        return $cfg ??= require __DIR__ . '/../config.php';
    }

    public static function payment(): PaymentAdapter {
        return self::resolve('payment');
    }

    public static function storage(): StorageAdapter {
        return self::resolve('storage');
    }

    public static function resolve(string $slot): object {
        if (isset(self::$instances[$slot])) return self::$instances[$slot];

        $cfg = self::cfg();
        $class = $cfg['adapters'][$slot] ?? null;
        if (!$class) throw new \RuntimeException("No adapter configured for: {$slot}");

        $adapterFile = __DIR__ . '/../adapters/contrib/' . $class . '.php';
        if (file_exists($adapterFile)) require_once $adapterFile;

        if (!class_exists($class)) {
            throw new \RuntimeException("Adapter class not found: {$class}");
        }

        self::$instances[$slot] = new $class($cfg);
        return self::$instances[$slot];
    }

    /** Override (for testing) */
    public static function set(string $slot, object $instance): void {
        self::$instances[$slot] = $instance;
    }
}
