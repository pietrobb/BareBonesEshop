<?php
/**
 * BareBones Shop — String Utilities
 * Shared text normalization for FTS tokens, slugs, etc.
 */
class Str {
    /**
     * The one e-mail normalisation for storage and lookups: trimmed, ASCII-lowercased. ASCII-only on
     * purpose: it matches what is already stored (customers, coupon uses), and stored values are
     * compared as-is in SQL, so no LOWER() is needed (SQLite's LOWER() is ASCII-only, MySQL's is not).
     */
    public static function normalizeEmail(string $email): string {
        return strtolower(trim($email));
    }

    /** Diacritics map for Central European languages (SK/CZ/DE/PL) */
    private static array $diacritics = [
        'á'=>'a','ä'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ĺ'=>'l','ľ'=>'l',
        'ň'=>'n','ó'=>'o','ô'=>'o','ö'=>'o','ŕ'=>'r','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u',
        'ů'=>'u','ü'=>'u','ý'=>'y','ž'=>'z','ß'=>'ss','à'=>'a','â'=>'a','è'=>'e','ê'=>'e',
        'ë'=>'e','î'=>'i','ï'=>'i','ò'=>'o','ù'=>'u','û'=>'u','ç'=>'c','ñ'=>'n',
    ];

    /**
     * Normalize string for FTS filter token (attr_color_red)
     * Lowercase, remove diacritics, replace non-alnum with underscore.
     */
    public static function normalizeToken(string $s): string {
        $s = mb_strtolower($s);
        $s = strtr($s, self::$diacritics);
        $s = preg_replace('/[^a-z0-9]/', '_', $s);
        return trim($s, '_');
    }

    /**
     * Remove diacritics from string (lowercase)
     */
    public static function removeDiacritics(string $s): string {
        return strtr(mb_strtolower($s), self::$diacritics);
    }

    /** Extra slug letters (PL/HU/RO/Nordic/…) kept out of $diacritics so stored FTS filter tokens stay unchanged. */
    private static array $slugLetters = [
        'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ś'=>'s','ź'=>'z','ż'=>'z','ő'=>'o','ű'=>'u',
        'ã'=>'a','å'=>'a','æ'=>'ae','ø'=>'o','õ'=>'o','œ'=>'oe','ÿ'=>'y','ì'=>'i','ă'=>'a',
        'ș'=>'s','ş'=>'s','ț'=>'t','ţ'=>'t','đ'=>'d','ğ'=>'g','ı'=>'i',
    ];

    /** URL slug: "Košeľa Žltá" → "kosela-zlta". */
    public static function slug(string $s): string {
        $s = strtr(self::removeDiacritics($s), self::$slugLetters);
        return trim((string)preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    }
}
