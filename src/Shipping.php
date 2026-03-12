<?php
/**
 * BareBones Shop — Shipping
 * Zone-based shipping calculation from shipping.json.
 */
class Shipping {
    private static ?array $config = null;

    private static function config(): array {
        if (!self::$config) {
            $file = __DIR__ . '/../shipping.json';
            self::$config = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        }
        return self::$config;
    }

    /** Override config (for testing) */
    public static function setConfig(array $config): void {
        self::$config = $config;
    }

    /**
     * Calculate shipping cost for a given method, country, and weight.
     * @param string $method   Method key: pickup, standard, express
     * @param string $country  ISO country code: SK, CZ, DE...
     * @param int    $weightG  Total weight in grams
     * @param int    $subtotal Cart subtotal in cents (for free shipping check)
     * @return int Cost in cents
     */
    public static function calculate(string $method, string $country, int $weightG = 0, int $subtotal = 0): int {
        $cfg = self::config();
        $methodCfg = $cfg['methods'][$method] ?? null;
        if (!$methodCfg) return 0;

        // Pickup is always free
        if (($methodCfg['price'] ?? null) === 0) return 0;

        // Determine zone for country
        $zone = self::zoneForCountry($country);
        if (!$zone) return 0;

        // Check if method is available for this zone
        if (isset($methodCfg['available_zones'])) {
            if (!in_array($zone, $methodCfg['available_zones'])) return -1; // unavailable
        }

        $rates = $methodCfg['rates'][$zone] ?? null;
        if (!$rates) return -1; // no rate for this zone

        // Free shipping threshold
        if (!empty($methodCfg['free_above']) && $subtotal >= $methodCfg['free_above']) {
            return 0;
        }

        // Base + per kg
        $weightKg = $weightG / 1000;
        $cost = $rates['base'] + (int)round($rates['per_kg'] * $weightKg);

        return $cost;
    }

    /**
     * Get available shipping methods for a country
     */
    public static function availableMethods(string $country, int $subtotal = 0): array {
        $cfg = self::config();
        $zone = self::zoneForCountry($country);
        $locale = class_exists('I18n') ? I18n::locale() : 'en';
        $methods = [];

        foreach ($cfg['methods'] ?? [] as $key => $method) {
            // Check zone availability
            if (isset($method['available_zones'])) {
                if (!in_array($zone, $method['available_zones'])) continue;
            }

            // Check if rates exist for this zone (skip if no rates)
            if (isset($method['rates']) && !isset($method['rates'][$zone])) continue;

            $price = self::calculate($key, $country, 0, $subtotal);
            if ($price < 0) continue; // unavailable

            $name = is_array($method['name']) ? ($method['name'][$locale] ?? reset($method['name'])) : $method['name'];

            $methods[] = [
                'key'           => $key,
                'name'          => $name,
                'price'         => $price,
                'delivery_days' => $method['delivery_days'] ?? null,
                'free_above'    => $method['free_above'] ?? null,
            ];
        }

        return $methods;
    }

    /**
     * Determine shipping zone for country code
     */
    public static function zoneForCountry(string $country): ?string {
        $cfg = self::config();
        $country = strtoupper($country);

        // Check specific zones first (most specific → least specific)
        foreach ($cfg['zones'] ?? [] as $zoneName => $zone) {
            $countries = $zone['countries'] ?? [];
            if (in_array($country, $countries)) {
                return $zoneName;
            }
        }

        // Check wildcard zone
        foreach ($cfg['zones'] ?? [] as $zoneName => $zone) {
            if (in_array('*', $zone['countries'] ?? [])) {
                return $zoneName;
            }
        }

        return null;
    }

    /**
     * Get method configuration
     */
    public static function methodConfig(string $method): ?array {
        return self::config()['methods'][$method] ?? null;
    }
}
