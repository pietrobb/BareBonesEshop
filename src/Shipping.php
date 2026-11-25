<?php
/**
 * BareBones Shop — Shipping
 * Zone-based shipping calculation from shipping.json.
 */
class Shipping {
    /** R58 (review round 23, low): the shipping.json methods checkout offers (ShippingService::legacyRates()); its "pickup" is not one. */
    public const CHECKOUT_METHODS = ['standard', 'express'];

    private static ?array $config = null;
    /** shipping.json with the merchant's Admin → Shipping settings over it (DataOverrides::shippingMethods()). */
    private static ?array $merged = null;

    /** shipping.json as shipped (or as setConfig() gave it). */
    public static function fileConfig(): array {
        if (!self::$config) {
            $file = __DIR__ . '/../shipping.json';
            $decoded = file_exists($file) ? json_decode((string)file_get_contents($file), true) : [];
            self::$config = is_array($decoded) ? $decoded : [];
        }
        return self::$config;
    }

    /**
     * R58 (review round 23, medium S4): shipping.json with the merchant's settings of its checkout methods over it. Was:
     * shipping.json alone, so turning Standard or Express off or changing a price meant editing a core file, and the
     * updater then stopped at "installed file was locally modified".
     */
    private static function config(): array {
        return self::$merged ??= self::withSettings(self::fileConfig(), DataOverrides::shippingMethods());
    }

    /** Read the merchant's settings again (after Admin → Shipping saved them). */
    public static function forget(): void {
        self::$merged = null;
    }

    /**
     * $cfg with $settings (DataOverrides::shippingMethods()) applied to its checkout methods: "active" (false = not offered),
     * "free_above" (null = none), "delivery_days", and "rates" per zone of the file (null = not offered in that zone).
     * Settings of other methods or zones are ignored.
     */
    public static function withSettings(array $cfg, array $settings): array {
        // R59 (review round 24, low): only zones the form shows (editableZones()); a posted rates[local] priced Standard
        // in the pickup zone, which comes before "domestic", so domestic delivery cost 0 €.
        $zones = array_flip(self::editableZones($cfg));
        foreach (self::CHECKOUT_METHODS as $key) {
            $own = $settings['methods'][$key] ?? null;
            if (!is_array($own) || !is_array($cfg['methods'][$key] ?? null)) continue;
            $method = $cfg['methods'][$key];
            if (array_key_exists('active', $own)) $method['active'] = $own['active'] !== false;
            if (array_key_exists('free_above', $own)) {
                if (is_int($own['free_above']) && $own['free_above'] >= 0) $method['free_above'] = $own['free_above'];
                elseif ($own['free_above'] === null) unset($method['free_above']);
            }
            if (is_string($own['delivery_days'] ?? null)) {
                if (trim($own['delivery_days']) === '') unset($method['delivery_days']);
                else $method['delivery_days'] = $own['delivery_days'];
            }
            foreach (is_array($own['rates'] ?? null) ? $own['rates'] : [] as $zone => $rate) {
                if (!isset($zones[$zone])) continue;
                if ($rate === null) {
                    unset($method['rates'][$zone]);
                } elseif (is_array($rate) && is_int($rate['base'] ?? null) && $rate['base'] >= 0) {
                    $method['rates'][$zone] = ['base' => $rate['base'], 'per_kg' => max(0, (int)($rate['per_kg'] ?? 0))];
                }
            }
            $cfg['methods'][$key] = $method;
        }
        return $cfg;
    }

    /**
     * Zones of $file (shipping.json) its checkout methods are priced for: the zones Admin → Shipping lists and prices.
     * @return list<string>
     */
    public static function editableZones(array $file): array {
        $zones = is_array($file['zones'] ?? null) ? $file['zones'] : [];
        $editable = [];
        foreach (self::CHECKOUT_METHODS as $key) {
            foreach ((array)($file['methods'][$key]['rates'] ?? []) as $zone => $rate) if (isset($zones[$zone])) $editable[(string)$zone] = true;
        }
        return array_keys($editable);
    }

    /** A method is offered unless the merchant turned it off. */
    private static function isActive(array $method): bool {
        return ($method['active'] ?? true) !== false;
    }

    /** A method's name in $locale (shipping.json names it per language), else in English; null when it has none. */
    public static function methodName(string $key, string $locale): ?string {
        $names = self::config()['methods'][$key]['name'] ?? null;
        $name = is_array($names) ? ($names[$locale] ?? $names['en'] ?? reset($names)) : $names;
        return is_string($name) && trim($name) !== '' ? $name : null;
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
        // R58 (review round 23, medium S4): not of a zone only methods turned off in Admin → Shipping (or the pickup
        // checkout never offers from shipping.json) deliver to.
        $cfg = self::config();
        $codes = [];
        foreach ($cfg['zones'] ?? [] as $name => $zone) {
            $served = null;
            foreach (is_array($cfg['methods'] ?? null) ? $cfg['methods'] : [] as $key => $method) {
                if (!is_array($method) || (!isset($method['rates'][$name]) && !in_array($name, (array)($method['available_zones'] ?? []), true))) continue;
                $served = $served === true || (in_array((string)$key, self::CHECKOUT_METHODS, true) && self::isActive($method));
            }
            if ($served === false) continue; // a zone no method names stays a destination, as before
            // R59 (review round 24, low): not one whose every price the merchant removed in Admin → Shipping either (was:
            // its countries stayed in the checkout's list, and no method delivered there).
            if ($served === null && in_array((string)$name, self::editableZones(self::fileConfig()), true)) continue;
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

    /** Override config (for testing); the merchant's Admin → Shipping settings still apply over it. */
    public static function setConfig(array $config): void {
        self::$config = $config;
        self::$merged = null;
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
        if (!self::isActive($methodCfg)) return -1; // R58: turned off in Admin → Shipping

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
     * R58 (review round 23, medium S4 and lows): only the methods checkout offers (not "pickup", which it never offers from
     * shipping.json), with "active" and the merchant's settings applied; with $editable a row for every zone those
     * methods are priced for in shipping.json, base and per_kg null where the method is not offered.
     * @return list<array{key: string, name: string, active: bool, zone: string, countries: list<string>, base: ?int, per_kg: ?int, free_above: ?int, delivery_days: ?string}>
     */
    public static function overview(string $locale, bool $editable = false): array {
        $cfg = self::config();
        $file = self::fileConfig();
        $zones = is_array($cfg['zones'] ?? null) ? $cfg['zones'] : [];
        $editableZones = array_flip(self::editableZones($file));
        $rows = [];
        foreach (self::CHECKOUT_METHODS as $key) {
            $method = $cfg['methods'][$key] ?? null;
            if (!is_array($method)) continue;
            $name = self::methodName($key, $locale) ?? ucfirst($key);
            $rates = is_array($method['rates'] ?? null) ? $method['rates'] : [];
            // A country takes the rate of the first zone (in the zones' order) that lists it, so a later zone's row leaves it out.
            $claimed = [];
            foreach ($zones as $zone => $zoneCfg) {
                $offered = isset($rates[$zone]) && (!isset($method['available_zones']) || in_array($zone, (array)$method['available_zones'], true));
                if (!$offered && !($editable && isset($editableZones[$zone]))) continue;
                $all = self::countriesOf($zoneCfg);
                $countries = $all === ['*'] ? ['*'] : array_values(array_diff($all, $claimed));
                $claimed = array_merge($claimed, $all);
                $rate = $offered ? $rates[$zone] : null;
                $rows[] = ['key' => $key, 'name' => $name, 'active' => self::isActive($method), 'zone' => (string)$zone, 'countries' => $countries,
                    'base' => $rate !== null ? (int)($rate['base'] ?? 0) : null, 'per_kg' => $rate !== null ? (int)($rate['per_kg'] ?? 0) : null,
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
