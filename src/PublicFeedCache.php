<?php
/**
 * Cached public catalog artifacts (sitemap parts, merchant feeds) in the active store's cache
 * namespace. An entry is rebuilt when its TTL lapses, after Cache::flush(), or as soon as the
 * catalog fingerprint changes (a product/category/CMS page is added, removed or saved — every
 * product write bumps products.updated_at). Price-list, promotion and stock changes do not touch
 * the fingerprint and are picked up within the TTL.
 */
final class PublicFeedCache
{
    public const TTL = 900;

    /**
     * Send the entry $name to the output, generating it with $writer (which streams into the
     * handle it receives) when stale. $variant (e.g. the absolute base URL) is part of the
     * fingerprint. Without a writable cache the artifact is streamed directly.
     * @param callable(resource): void $writer
     */
    public static function send(string $name, string $variant, callable $writer, int $ttl = self::TTL): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,120}$/', $name)) throw new InvalidArgumentException('Invalid feed cache name');
        $key = 'public/' . $name;
        $fingerprint = hash('sha256', $variant . "\n" . self::catalogFingerprint());
        $file = Cache::freshFile($key, $ttl);
        if ($file === null || Cache::read($key . '.sig') !== $fingerprint) {
            $file = Cache::writeStream($key, $writer);
            if ($file === null) {
                $out = fopen('php://output', 'wb');
                if ($out === false) throw new RuntimeException('Output stream unavailable');
                $writer($out);
                fclose($out);
                return;
            }
            Cache::write($key . '.sig', $fingerprint);
        }
        readfile($file);
    }

    /** Cheap change marker over every store's catalog and CMS pages; '' when tables are missing. */
    public static function catalogFingerprint(): string
    {
        try {
            $row = Db::get()->query('SELECT
                    (SELECT COUNT(*) FROM products) AS product_count,
                    (SELECT MAX(updated_at) FROM products) AS product_updated,
                    (SELECT COUNT(*) FROM categories) AS category_count,
                    (SELECT MAX(id) FROM categories) AS category_max,
                    (SELECT COUNT(*) FROM pages) AS page_count,
                    (SELECT MAX(updated_at) FROM pages) AS page_updated')->fetch(PDO::FETCH_NUM);
            return is_array($row) ? implode('|', array_map('strval', $row)) : '';
        } catch (PDOException) {
            return '';
        }
    }
}
