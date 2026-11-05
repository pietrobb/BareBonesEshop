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

    /**
     * An amount typed in the currency ("12,50", "12.50", "1 299") as cents; -1 when it is not an amount
     * or has more decimals than the currency shows (CZK/HUF are whole units).
     */
    public static function parseInput(mixed $raw, string $currency): int {
        if (!is_string($raw)) return -1;
        // R15 low: "1,299" is 1 299 or a typo, never 1,30 €: a decimal part finer than a cent is refused, not rounded.
        // The decimal separator is found as Import::parsePriceToCents finds it (the last one when both kinds occur,
        // a single one otherwise; a repeated one alone is digit grouping, "12,345,678").
        $number = (string)preg_replace('/[.,]-$/', '', (string)preg_replace('/[^\d.,-]/', '', $raw));
        $comma = strrpos($number, ',');
        $dot = strrpos($number, '.');
        $last = max($comma === false ? -1 : $comma, $dot === false ? -1 : $dot);
        if ($last >= 0 && ($comma !== false && $dot !== false || substr_count($number, $number[$last]) === 1)
            && strlen($number) - $last - 1 > 2) {
            return -1;
        }
        try {
            $cents = Import::parsePriceToCents($raw);
        } catch (\InvalidArgumentException $e) {
            return -1;
        }
        return self::round($cents, $currency) === $cents ? $cents : -1;
    }

    /**
     * R17: an admin money field typed in the currency ("12.90", "-1,50") as cents; null when it is not such an amount.
     * '' is $empty. Negative only with $signed (a variant surcharge).
     */
    public static function adminInput(mixed $raw, string $currency, ?int $empty = 0, bool $signed = false): ?int {
        if (!is_scalar($raw)) return null;
        $raw = trim((string)$raw);
        if ($raw === '') return $empty;
        $negative = $signed && str_starts_with($raw, '-');
        $cents = self::parseInput($negative ? ltrim(substr($raw, 1)) : $raw, $currency);
        if ($cents < 0) return null;
        return $negative ? -$cents : $cents;
    }

    /** An amount in cents as an admin field shows it, in the currency ("12.90"); null is ''. */
    public static function adminValue(?int $cents, string $currency): string {
        return $cents === null ? '' : self::decimal($cents, $currency);
    }
}
