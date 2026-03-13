<?php
/**
 * BareBones Shop — String Utilities
 * Shared text normalization for FTS tokens, slugs, etc.
 */
class Str {
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
}
