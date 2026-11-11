<?php
/**
 * R22: a feed's status and the errors of its last sync are kept in English (CLI, logs, the stored last_errors); the
 * admin showed them so in the SK or HU admin as well. Shown there in the admin's language; a line no pattern knows (an
 * older or an unexpected message) is shown as it is.
 */
final class FeedMessages
{
    /** Pattern => admin.feed.* key; named groups fill its {placeholders}, {reasons} is translated reason by reason. */
    private const STATUS = [
        '/^ok$/' => 'status_ok',
        '/^partial: (?<count>\d+) errors \((?<prices>\d+) prices refused\)$/' => 'status_partial_prices',
        '/^partial: (?<count>\d+) errors$/' => 'status_partial',
        '/^stopped after (?<count>\d+) errors \((?<prices>\d+) prices refused\)$/' => 'status_stopped_prices',
        '/^stopped after (?<count>\d+) errors$/' => 'status_stopped',
        '/^error: no field_map$/' => 'status_no_map',
        '/^error: bad import mode$/' => 'status_bad_mode',
        '/^error: download failed$/' => 'status_download',
        '/^error: bad format$/' => 'status_bad_format',
        '/^error: (?<detail>.*)$/s' => 'status_error',
    ];

    private const ERRORS = [
        '/^CSV row (?<line>\d+): column count does not match the header$/' => 'err_csv_columns',
        '/^Item #(?<item>\d+) \(SKU (?<sku>.*)\): price refused, price kept: (?<reasons>.*)$/s' => 'err_price_kept',
        '/^Item #(?<item>\d+) \(SKU (?<sku>.*)\): price refused, compare price kept: (?<reasons>.*)$/s' => 'err_compare_kept',
        '/^Item #(?<item>\d+) \(SKU (?<sku>.*)\): price refused, product not created: (?<reasons>.*)$/s' => 'err_not_created',
        '/^Item #(?<item>\d+): (?<reasons>.*)$/s' => 'err_item',
    ];

    private const REASONS = [
        '/^Not a price: (?<value>.*) \(ambiguous: write e\.g\. 199\.00 or 199000, or declare the feed\'s decimal mark: .*\)$/s' => 'reason_ambiguous_feed',
        '/^Not a price: (?<value>.*) \(ambiguous: write e\.g\. 199\.00 or 199000\)$/s' => 'reason_ambiguous',
        '/^Not a price: (?<value>.*) \(finer than a cent, write e\.g\. 1234\.00 or 1\.23\)$/s' => 'reason_finer',
        '/^Not a price: (?<value>.*) \(the decimal mark is "(?<mark>[.,])"\)$/s' => 'reason_mark',
        '/^Not a price: (?<value>.*)$/s' => 'reason_not_price',
        '/^Price must not be negative$/' => 'reason_negative',
        '/^Price must not exceed (?<max>[\d.]+)$/' => 'reason_too_high',
        '/^Unknown decimal mark: (?<mark>.*)$/s' => 'reason_unknown_mark',
    ];

    public static function status(string $status): string
    {
        return self::translate($status, self::STATUS);
    }

    public static function error(string $line): string
    {
        return self::translate($line, self::ERRORS);
    }

    /** @param array<string, string> $patterns */
    private static function translate(string $line, array $patterns): string
    {
        foreach ($patterns as $pattern => $key) {
            if (!preg_match($pattern, $line, $match)) continue;
            $vars = [];
            foreach ($match as $name => $value) {
                if (!is_string($name)) continue;
                // R29: "2 chyby", not "2 chýb": the error count with the noun in its plural form.
                if ($name === 'count') $vars['{errors}'] = I18n::plural('admin.common.errors_count', (int)$value);
                $vars['{' . $name . '}'] = $name !== 'reasons' ? $value : implode('; ', array_map(
                    static fn(string $reason): string => self::translate($reason, self::REASONS), explode('; ', $value)));
            }
            return strtr(I18n::t('admin.feed.' . $key), $vars);
        }
        return $line;
    }
}
