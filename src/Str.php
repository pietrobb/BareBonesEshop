<?php
/**
 * BareBones Shop — String Utilities
 * Shared text normalization for FTS tokens, slugs, etc.
 */
class Str {
    /**
     * The one e-mail normalisation for storage and lookups: trimmed, UTF-8 lowercased (mb_strtolower),
     * so "Éva@x" and "éva@x" are one customer for coupon limits. Stored values are compared as-is in
     * SQL (no LOWER(): SQLite's is ASCII-only, MySQL's is not), so both drivers agree. Customer
     * registration only accepts ASCII addresses (FILTER_VALIDATE_EMAIL), where this equals the
     * former ASCII-only lowercase.
     */
    public static function normalizeEmail(string $email): string {
        return mb_strtolower(trim($email), 'UTF-8');
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
        // Cyrillic (Russian, with Ukrainian, Belarusian, Serbian and Macedonian letters)
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y',
        'к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f',
        'х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'shch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        'є'=>'ye','і'=>'i','ї'=>'yi','ґ'=>'g','ў'=>'u','ђ'=>'dj','ј'=>'j','љ'=>'lj','њ'=>'nj','ћ'=>'c','џ'=>'dz',
        'ѓ'=>'gj','ќ'=>'kj','ѕ'=>'dz',
        // Greek
        'α'=>'a','β'=>'v','γ'=>'g','δ'=>'d','ε'=>'e','ζ'=>'z','η'=>'i','θ'=>'th','ι'=>'i','κ'=>'k','λ'=>'l',
        'μ'=>'m','ν'=>'n','ξ'=>'x','ο'=>'o','π'=>'p','ρ'=>'r','σ'=>'s','ς'=>'s','τ'=>'t','υ'=>'y','φ'=>'f',
        'χ'=>'ch','ψ'=>'ps','ω'=>'o','ά'=>'a','έ'=>'e','ή'=>'i','ί'=>'i','ό'=>'o','ύ'=>'y','ώ'=>'o',
        'ϊ'=>'i','ϋ'=>'y','ΐ'=>'i','ΰ'=>'y',
    ];

    /** URL slug: "Košeľa Žltá" → "kosela-zlta". */
    public static function slug(string $s): string {
        $s = strtr(self::removeDiacritics($s), self::$slugLetters);
        return trim((string)preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    }
}
