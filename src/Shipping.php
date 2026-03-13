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

        // Determine zones for country (may belong to multiple: local + domestic)
        $zones = self::zonesForCountry($country);
        if (empty($zones)) return 0;

        // Check zone availability and find matching rates
        $rates = null;
        foreach ($zones as $zone) {
            // If method restricts zones, check if this zone is allowed
            if (isset($methodCfg['available_zones']) && !in_array($zone, $methodCfg['available_zones'])) {
                continue;
            }
            // Check if this zone has rates
            if (isset($methodCfg['rates'][$zone])) {
                $rates = $methodCfg['rates'][$zone];
                break;
            }
        }

        if (!$rates) return -1; // no rate for any matching zone

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
        $zones = self::zonesForCountry($country);
        $locale = class_exists('I18n') ? I18n::locale() : 'en';
        $methods = [];

        foreach ($cfg['methods'] ?? [] as $key => $method) {
            // Check if any zone matches for this method
            $hasZone = false;
            foreach ($zones as $zone) {
                if (isset($method['available_zones']) && !in_array($zone, $method['available_zones'])) continue;
                if (isset($method['rates']) && !isset($method['rates'][$zone])) continue;
                $hasZone = true;
                break;
            }
            if (!$hasZone && !empty($zones)) continue;

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
     * Determine shipping zone for country code (returns first match)
     */
    public static function zoneForCountry(string $country): ?string {
        $zones = self::zonesForCountry($country);
        return $zones[0] ?? null;
    }

    /**
     * Get ALL matching zones for a country (for methods with overlapping zones)
     */
    public static function zonesForCountry(string $country): array {
        $cfg = self::config();
        $country = strtoupper($country);
        $zones = [];

        foreach ($cfg['zones'] ?? [] as $zoneName => $zone) {
            $countries = $zone['countries'] ?? [];
            if (in_array($country, $countries)) {
                $zones[] = $zoneName;
            }
        }

        // Wildcard zone as fallback
        foreach ($cfg['zones'] ?? [] as $zoneName => $zone) {
            if (in_array('*', $zone['countries'] ?? []) && !in_array($zoneName, $zones)) {
                $zones[] = $zoneName;
            }
        }

        return $zones;
    }

    /**
     * Get method configuration
     */
    public static function methodConfig(string $method): ?array {
        return self::config()['methods'][$method] ?? null;
    }
}
