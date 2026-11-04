<?php
/**
 * The shop's wall clock. Stored timestamps stay UTC; what a merchant or consumer reads as a date — the invoice date
 * and its number year, the VAT rate in force, the last day of a withdrawal period, a transfer's due date — is the
 * store's local calendar (StoreContext::timezone()), never the server's.
 */
final class ShopTime {
    private static ?int $now = null;

    /** Freeze "now" for tests; null returns to the real clock. */
    public static function setNowForTesting(?int $now): void { self::$now = $now; }

    public static function now(): int { return self::$now ?? time(); }

    /** Time zone of store $storeId (null or the active store: the active store's). */
    public static function zone(?string $storeId = null): DateTimeZone {
        if ($storeId === null || $storeId === '' || (StoreContext::hasCurrent() && StoreContext::current()->id() === $storeId)) {
            return new DateTimeZone(StoreContext::current()->timezone());
        }
        $store = Bootstrap::stores()['stores'][$storeId] ?? null;
        return new DateTimeZone(is_array($store) ? StoreContext::timezoneFor($store) : StoreContext::current()->timezone());
    }

    /** date($format) on the store's calendar; $ts defaults to now. */
    public static function date(string $format, ?int $ts = null, ?string $storeId = null): string {
        return (new DateTimeImmutable('@' . ($ts ?? self::now())))->setTimezone(self::zone($storeId))->format($format);
    }

    /** date($format) in time zone $timezone (a store record's StoreContext::timezoneFor()); $ts defaults to now. */
    public static function dateIn(string $format, string $timezone, ?int $ts = null): string {
        return (new DateTimeImmutable('@' . ($ts ?? self::now())))->setTimezone(new DateTimeZone($timezone))->format($format);
    }

    /**
     * A stored UTC timestamp ("Y-m-d H:i:s") as the consumer reads it in a confirmation: store $storeId's wall clock in
     * the format.datetime of $locale ('' = the current one) with the zone ("4. 11. 2026 14:05 CET"); unparsable input
     * comes back unchanged.
     */
    public static function stamp(string $utc, string $locale = '', ?string $storeId = null): string {
        $ts = strtotime(trim($utc) . ' UTC');
        if ($ts === false || trim($utc) === '') return $utc;
        $local = (new DateTimeImmutable('@' . $ts))->setTimezone(self::zone($storeId));
        return $local->format($locale === '' ? I18n::t('format.datetime') : I18n::tIn('format.datetime', $locale)) . ' ' . $local->format('T');
    }

    /**
     * The last second (23:59:59 local) of the day $days calendar days after the local day of $ts. A period "of 14 days
     * after delivery" ends when that whole day has passed (Regulation 1182/71 art. 3), not 14 × 24 h after the hour.
     */
    public static function endOfDay(int $ts, int $days = 0, ?string $storeId = null): int {
        $local = (new DateTimeImmutable('@' . $ts))->setTimezone(self::zone($storeId));
        // sprintf('%+d'): "-2 days", never "+-2 days" (a negative $days is the reminder day before a due date).
        return $local->setTime(0, 0)->modify(sprintf('%+d days', $days + 1))->getTimestamp() - 1;
    }

    /** The local calendar date $days days after $date (Y-m-d). */
    public static function addDays(string $date, int $days): string {
        return (new DateTimeImmutable($date . ' 12:00:00', new DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }
}
