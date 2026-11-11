<?php
/**
 * R30: an error of Import::csv() as the admin reads it. Import writes its errors in English (the worker log and the
 * API keep them so); the admin's import page shows the row and file errors it knows in the admin's language, anything
 * else (a database error) as written.
 */
final class ImportErrorLabels {
    /** Fixed errors => admin.import.err.<key>. */
    private const FIXED = [
        'Empty CSV or invalid format' => 'empty',
        'CSV header is invalid, duplicated, missing sku/name, or exceeds limits' => 'header',
        'CSV exceeds the 100000 row limit; earlier committed batches remain imported' => 'row_limit',
        'CSV size must be between 1 byte and 50 MB' => 'size',
        'CSV is not valid UTF-8 (nor Windows-1250); save it as "CSV UTF-8" and import again' => 'encoding',
        'Missing required field: sku or name' => 'missing_sku_name',
        'Missing required field: price' => 'missing_price',
        'Price must not be negative' => 'price_negative',
    ];
    /** Errors with values: pattern => [admin.import.err.<key>, placeholder names of the groups]. More specific first. */
    private const PATTERNS = [
        "/^SKU '(.+)' belongs to another catalog$/s" => ['sku_other_catalog', ['sku']],
        "/^SKU '(.+)' is already used by a variant$/s" => ['sku_variant', ['sku']],
        "/^Slug '(.+)' \\((\\w+)\\) is already used by another \\w+$/s" => ['slug_taken', ['slug', 'locale']],
        '/^Not a price: (.+) \(ambiguous: .+\)$/s' => ['price_ambiguous', ['value']],
        '/^Not a price: (.+) \(finer than a cent.+\)$/s' => ['price_fine', ['value']],
        '/^Not a price: (.+) \(the decimal mark is "(.)"\)$/s' => ['price_decimal', ['value', 'mark']],
        '/^Not a price: (.+)$/s' => ['not_price', ['value']],
        '/^Price must not exceed (.+)$/s' => ['price_max', ['max']],
        '/^Price must be at least (.+) \(a variant discount would make its price negative\)$/s' => ['price_min', ['min']],
        '/^Invalid status "(.*)" \(use (.+)\)$/s' => ['status', ['value', 'allowed']],
    ];

    public static function text(string $error): string {
        if (preg_match('/^Line (\d+): column count mismatch$/', $error, $m) === 1) return I18n::format('admin.import.err.columns', ['line' => $m[1]]);
        if (preg_match('/^Line (\d+) \(SKU: (.*?)\): (.*)$/s', $error, $m) === 1) {
            return I18n::format('admin.import.err.row', ['line' => $m[1], 'sku' => $m[2], 'error' => self::message($m[3])]);
        }
        return self::message($error);
    }

    private static function message(string $message): string {
        if (isset(self::FIXED[$message])) return I18n::t('admin.import.err.' . self::FIXED[$message]);
        foreach (self::PATTERNS as $pattern => [$key, $names]) {
            if (preg_match($pattern, $message, $m) === 1) return I18n::format('admin.import.err.' . $key, array_combine($names, array_slice($m, 1)));
        }
        return $message;
    }
}
