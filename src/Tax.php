<?php
/**
 * BareBones Shop — Tax
 * Country-based tax calculation from tax.json.
 * Prices in the system are stored WITH tax (gross).
 */
class Tax {
    private static ?array $config = null;

    private static function config(): array {
        if (!self::$config) {
            $file = __DIR__ . '/../tax.json';
            self::$config = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        }
        return self::$config;
    }

    /** Override config (for testing) */
    public static function setConfig(array $config): void {
        self::$config = $config;
    }

    /**
     * Get tax rate for a country and product tax class.
     * @return float Rate as percentage (e.g. 20.0 for 20%)
     */
    public static function rate(string $country, string $taxClass = 'default'): float {
        $cfg = self::config();
        $country = strtoupper($country);

        // Resolve product class → rate key (standard, reduced, zero)
        $rateKey = $cfg['product_tax_class'][$taxClass] ?? 'standard';

        // Country-specific rate
        if (isset($cfg['rates'][$country][$rateKey])) {
            return (float)$cfg['rates'][$country][$rateKey];
        }

        // Fallback to country standard rate
        if (isset($cfg['rates'][$country]['standard'])) {
            return (float)$cfg['rates'][$country]['standard'];
        }

        // Global default
        return (float)($cfg['default_rate'] ?? 0);
    }

    /**
     * Extract tax from a gross price (prices include tax).
     * Returns tax amount in cents.
     */
    public static function extractFromGross(int $grossCents, string $country, string $taxClass = 'default'): int {
        $rate = self::rate($country, $taxClass);
        if ($rate <= 0) return 0;

        // gross = net + tax = net * (1 + rate/100)
        // tax = gross - gross / (1 + rate/100)
        $taxAmount = $grossCents - (int)round($grossCents / (1 + $rate / 100));
        return $taxAmount;
    }

    /**
     * Calculate tax to add on a net price.
     * Returns tax amount in cents.
     */
    public static function calculateOnNet(int $netCents, string $country, string $taxClass = 'default'): int {
        $rate = self::rate($country, $taxClass);
        if ($rate <= 0) return 0;

        return (int)round($netCents * $rate / 100);
    }

    /**
     * Get net price from gross (remove tax).
     */
    public static function netFromGross(int $grossCents, string $country, string $taxClass = 'default'): int {
        return $grossCents - self::extractFromGross($grossCents, $country, $taxClass);
    }

    /**
     * Calculate order tax breakdown.
     * Since our prices include tax, we extract it for the invoice.
     *
     * @param array  $items   Cart items with 'total' (gross) and optional 'tax_class'
     * @param string $country Customer country code
     * @return array [total_tax, breakdown[rate => amount]]
     */
    public static function orderBreakdown(array $items, string $country): array {
        $breakdown = [];
        $totalTax = 0;

        foreach ($items as $item) {
            $taxClass = $item['tax_class'] ?? 'default';
            $rate = self::rate($country, $taxClass);
            $tax = self::extractFromGross($item['total'], $country, $taxClass);

            $rateKey = number_format($rate, 1) . '%';
            $breakdown[$rateKey] = ($breakdown[$rateKey] ?? 0) + $tax;
            $totalTax += $tax;
        }

        return [
            'total_tax' => $totalTax,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Are prices stored with tax included?
     */
    public static function pricesIncludeTax(): bool {
        return self::config()['prices_include_tax'] ?? true;
    }

    /**
     * Get all configured country rates
     */
    public static function allRates(): array {
        return self::config()['rates'] ?? [];
    }
}
