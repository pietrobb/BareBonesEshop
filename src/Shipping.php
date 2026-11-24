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

    /**
     * A zone's countries, upper case. R57 (review round 22, UX S5): "@store" is the current store's country (its seller
     * country, legal.country). Was: the home zones named SK, so a German shop charged its German customers the EU rate
     * (12.70) and a Slovak customer the domestic one (3.99).
     * @return list<string>
     */
    private static function countriesOf(mixed $zone): array {
        $countries = [];
        foreach (is_array($zone) ? (array)($zone['countries'] ?? []) : [] as $code) {
            if ($code === '@store') $code = self::storeCountry();
            if (is_string($code)) $countries[] = strtoupper($code);
        }
        return $countries;
    }

    /** The current store's seller country (SK when it names none, as the VAT context reads it). R58: through legal.seller. */
    public static function storeCountry(): string {
        return class_exists('StoreContext') ? StoreContext::current()->sellerCountry() : SellerLegal::FALLBACK_COUNTRY;
    }

    /** Explicit country codes of the shipping.json zones; the '*' wildcard names no country. */
    public static function zoneCountries(): array {
        $codes = [];
        foreach (self::config()['zones'] ?? [] as $zone) {
            foreach (self::countriesOf($zone) as $code) {
                if (preg_match('/^[A-Za-z]{2}$/', $code)) $codes[] = strtoupper($code);
            }
        }
        return array_values(array_unique($codes));
    }

    /** ISO currency the shipping.json amounts (rates, free_above) are written in; EUR when not declared. */
    public static function currency(): string {
        $currency = strtoupper((string)(self::config()['currency'] ?? 'EUR'));
        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'EUR';
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
     * @return int Cost in cents; -1 when the method does not exist or does not serve the country
     */
    public static function calculate(string $method, string $country, int $weightG = 0, int $subtotal = 0): int {
        $cfg = self::config();
        $methodCfg = $cfg['methods'][$method] ?? null;
        if (!$methodCfg) return -1; // unknown method is unavailable, never free

        // Pickup is always free
        if (($methodCfg['price'] ?? null) === 0) return 0;

        // Determine zones for country (may belong to multiple: local + domestic)
        $zones = self::zonesForCountry($country);
        if (empty($zones)) return -1; // country outside every zone: unavailable, never free

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
     * Get ALL matching zones for a country (for methods with overlapping zones)
     */
    public static function zonesForCountry(string $country): array {
        $cfg = self::config();
        $country = strtoupper($country);
        $zones = [];

        foreach ($cfg['zones'] ?? [] as $zoneName => $zone) {
            if (in_array($country, self::countriesOf($zone), true)) {
                $zones[] = $zoneName;
            }
        }

        // Wildcard zone as fallback
        foreach ($cfg['zones'] ?? [] as $zoneName => $zone) {
            if (in_array('*', self::countriesOf($zone), true) && !in_array($zoneName, $zones)) {
                $zones[] = $zoneName;
            }
        }

        return $zones;
    }

    /**
     * R57 (review round 22, UX S5): the shipping.json delivery methods as the admin's shipping page lists them, one row
     * per zone rate with the zone's countries ("@store" resolved). Was: not shown, the merchant could not see what
     * Standard/Express charged nor to whom.
     * @return list<array{key: string, name: string, zone: string, countries: list<string>, base: int, per_kg: int, free_above: ?int, delivery_days: ?string}>
     */
    public static function overview(string $locale): array {
        $cfg = self::config();
        $rows = [];
        foreach (is_array($cfg['methods'] ?? null) ? $cfg['methods'] : [] as $key => $method) {
            if (!is_array($method)) continue;
            $names = $method['name'] ?? $key;
            $name = is_array($names) ? (string)($names[$locale] ?? $names['en'] ?? reset($names)) : (string)$names;
            $rates = is_array($method['rates'] ?? null) ? $method['rates']
                : (($method['price'] ?? null) === 0 ? array_fill_keys((array)($method['available_zones'] ?? []), ['base' => 0, 'per_kg' => 0]) : []);
            // A country takes the rate of the first zone (in the zones' order) that lists it, so a later zone's row leaves it out.
            $claimed = [];
            foreach (is_array($cfg['zones'] ?? null) ? $cfg['zones'] : [] as $zone => $zoneCfg) {
                if (!isset($rates[$zone]) || (isset($method['available_zones']) && !in_array($zone, (array)$method['available_zones'], true))) continue;
                $rate = $rates[$zone];
                $all = self::countriesOf($zoneCfg);
                $countries = $all === ['*'] ? ['*'] : array_values(array_diff($all, $claimed));
                $claimed = array_merge($claimed, $all);
                $rows[] = ['key' => (string)$key, 'name' => $name, 'zone' => (string)$zone, 'countries' => $countries,
                    'base' => (int)($rate['base'] ?? 0), 'per_kg' => (int)($rate['per_kg'] ?? 0),
                    'free_above' => isset($method['free_above']) ? (int)$method['free_above'] : null,
                    'delivery_days' => isset($method['delivery_days']) ? (string)$method['delivery_days'] : null];
            }
        }
        return $rows;
    }

    /**
     * Get method configuration
     */
    public static function methodConfig(string $method): ?array {
        return self::config()['methods'][$method] ?? null;
    }
}
