<?php
/**
 * Cached public catalog artifacts (sitemap parts, merchant feeds) in the active store's cache
 * namespace. An entry is rebuilt when its TTL lapses, after Cache::flush(), as soon as the
 * catalog fingerprint changes (a product/category/CMS page is added or saved — every product and page
 * write bumps updated_at; categories are checksummed; R20: an entry built in the second of the latest
 * change is rebuilt after that second), or after Cache::catalogChanged()
 * of the store's catalog (a product or variant going in or out of stock, a price-list change).
 * R17: a sale that leaves a product in stock changes neither: it moves products.stock_updated_at, not
 * updated_at, so feeds and sitemaps are not rebuilt after every order. Promotions and other stock
 * changes are picked up within the TTL.
 */
final class PublicFeedCache
{
    public const TTL = 900;

    /**
     * Send the entry $name to the output, generating it with $writer (which streams into the
     * handle it receives) when stale. $variant (e.g. the absolute base URL) is part of the
     * fingerprint. Concurrent misses are serialised on a per-entry lock, so only the first
     * request regenerates; the others serve its result. The writer runs at most once per call:
     * without a writable cache it streams directly, and an entry that was written but could not
     * be renamed into place is served from its temporary copy.
     * @param callable(resource): void $writer
     */
    public static function send(string $name, string $variant, callable $writer, int $ttl = self::TTL): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,120}$/', $name)) throw new InvalidArgumentException('Invalid feed cache name');
        $key = 'public/' . $name;
        $state = self::catalogState();
        $fingerprint = hash('sha256', $variant . "\n" . $state['fingerprint'] . "\n" . Cache::catalogVersion());
        // R20: an entry built in the second of the latest change is marked: a change later in that second (a rename,
        // a product taken off sale) leaves MAX(updated_at) as it was, so the marked entry is rebuilt once that second
        // is over (it was served until the TTL ran out).
        if ($state['racy']) $fingerprint .= ' racy';
        $file = self::current($key, $ttl, $fingerprint);
        if ($file === null) {
            $lock = self::lock($key);
            try {
                // Another request may have rebuilt the entry while this one waited for the lock.
                $file = self::current($key, $ttl, $fingerprint);
                if ($file === null) {
                    self::rebuild($key, $fingerprint, $writer);
                    return;
                }
            } finally {
                if ($lock !== null) {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            }
        }
        readfile($file);
    }

    private static function current(string $key, int $ttl, string $fingerprint): ?string
    {
        $file = Cache::freshFile($key, $ttl);
        return $file !== null && Cache::read($key . '.sig') === $fingerprint ? $file : null;
    }

    /** @param callable(resource): void $writer */
    private static function rebuild(string $key, string $fingerprint, callable $writer): void
    {
        $result = Cache::writeStream($key, $writer);
        if ($result['status'] === Cache::STREAM_UNWRITABLE || $result['file'] === null) {
            $out = fopen('php://output', 'wb');
            if ($out === false) throw new RuntimeException('Output stream unavailable');
            $writer($out);
            fclose($out);
            return;
        }
        $file = $result['file'];
        if ($result['status'] === Cache::STREAM_CACHED) {
            Cache::write($key . '.sig', $fingerprint);
            readfile($file);
            return;
        }
        // Written but not cached: serve the temporary copy instead of running the writer again.
        try {
            readfile($file);
        } finally {
            @unlink($file);
        }
    }

    /** @return resource|null exclusive lock on the entry; null when no lock file can be opened (no serialisation then) */
    private static function lock(string $key)
    {
        try {
            $path = Cache::path($key . '.lock');
            $dir = dirname($path);
            if (!is_dir($dir)) @mkdir($dir, 0750, true);
            $handle = @fopen($path, 'c');
            if ($handle === false) return null;
            if (!flock($handle, LOCK_EX)) {
                fclose($handle);
                return null;
            }
            return $handle;
        } catch (Throwable) {
            return null;
        }
    }

    /** Seconds a latest change may be stamped ahead of the database clock (a timestamp written by PHP) and still count as now. */
    private const CLOCK_SKEW = 5;

    /**
     * Products and pages carry updated_at. Categories have no updated_at column, so their rows are folded into a
     * checksum (rename, slug, parent, translation and meta edits change it). A product deleted in the admin marks the
     * catalog changed (Cache::catalogVersion()).
     * R20: read from indexes only (MAX of the primary key and of updated_at); COUNT(*) and the R19 price sums read
     * every product row on every feed and sitemap request, cached or not (0.7 s on MySQL with 200 000 products).
     * updated_at has whole seconds: 'racy' says the latest change is in the current second (of the database clock),
     * so a further change in it would not move the marker.
     * @return array{fingerprint: string, racy: bool}
     */
    public static function catalogState(): array
    {
        try {
            $db = Db::get();
            $row = $db->query(self::stateSql())->fetch(PDO::FETCH_NUM);
            if (!is_array($row)) return ['fingerprint' => '', 'racy' => false];
            $now = strtotime((string)array_pop($row));
            $latest = max(array_map(static fn(mixed $at): int => is_string($at) && $at !== '' ? (int)strtotime($at) : 0, [$row[1], $row[3]]));
            return [
                'fingerprint' => implode('|', array_map('strval', $row)) . '|' . self::categoryChecksum($db),
                'racy' => $now !== false && $latest >= $now && $latest <= $now + self::CLOCK_SKEW,
            ];
        } catch (PDOException) {
            return ['fingerprint' => '', 'racy' => false];
        }
    }

    private static function stateSql(): string
    {
        return 'SELECT
                (SELECT MAX(id) FROM products) AS product_last,
                (SELECT MAX(updated_at) FROM products) AS product_updated,
                (SELECT COUNT(*) FROM pages) AS page_count,
                (SELECT MAX(updated_at) FROM pages) AS page_updated,
                ' . Db::now() . ' AS db_now';
    }

    private static function categoryChecksum(PDO $db): string
    {
        $hash = hash_init('sha256');
        $stmt = $db->query('SELECT * FROM categories ORDER BY id');
        if ($stmt === false) return '';
        while (($category = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            // product_count is bookkeeping (recounted on import), not something a feed shows.
            unset($category['product_count']);
            hash_update($hash, json_encode(array_map(static fn(mixed $v): ?string => $v === null ? null : (string)$v, $category)) . "\n");
        }
        return hash_final($hash);
    }
}
