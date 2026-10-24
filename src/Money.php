<?php
/**
 * Amounts are integer cents in every currency. A currency configured without minor units (stores.json
 * `currencies.<code>.decimals` = 0, e.g. CZK, HUF) is shown in whole units, so it is also charged in whole
 * units: every amount a checkout derives (percentage discount or fee, price without domestic VAT) is rounded
 * here before it is shown or stored.
 */
final class Money {
    /** Minor units the currency shows (stores.json), 2 when it is not configured. */
    public static function decimals(string $currency): int {
        $currencies = Bootstrap::stores()['currencies'] ?? [];
        return max(0, min(2, (int)($currencies[strtolower($currency)]['decimals'] ?? 2)));
    }

    /** Round half up (away from zero) to the smallest unit the currency shows. */
    public static function round(int $cents, string $currency): int {
        $step = 10 ** (2 - self::decimals($currency));
        if ($step === 1) return $cents;
        $units = intdiv(abs($cents) + intdiv($step, 2), $step) * $step;
        return $cents < 0 ? -$units : $units;
    }

    /** Machine-readable amount ("500", "12.90") in the units the storefront shows and the cart charges (feeds, JSON-LD). */
    public static function decimal(int $cents, string $currency): string {
        $decimals = self::decimals($currency);
        return number_format(self::round($cents, $currency) / 100, $decimals, '.', '');
    }
}
