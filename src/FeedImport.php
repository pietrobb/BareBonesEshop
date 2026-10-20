<?php
/**
 * BareBones Shop — Feed Import Engine
 *
 * Downloads XML/CSV feeds, maps fields to internal format,
 * upserts products and updates warehouse stock.
 *
 * Supports:
 * - XML feeds: <SHOP><SHOPITEM> (Heureka/NeoSolar standard)
 * - CSV feeds: header row with mapped column names
 * - Field mapping via JSON: {"ITEM_ID": "sku", "PRODUCTNAME": "name", ...}
 * - Stock sync per warehouse
 * - Scheduled via cron (cli/feed-sync.php)
 */
class FeedImport {

    /** Full upsert: create or update products with every mapped field, then set the warehouse stock. */
    public const MODE_UPSERT = 'upsert';
    /** Existing products of the catalog: update the price and the warehouse stock, never create or touch content. */
    public const MODE_PRICE_STOCK = 'price_stock';
    /** Existing products/variants of the catalog: only the warehouse stock changes; nothing is created or overwritten. */
    public const MODE_STOCK_ONLY = 'stock_only';
    /** The import modes the admin form offers, Warehouse::saveFeed() accepts and applyItems() implements. */
    public const MODES = [self::MODE_UPSERT, self::MODE_PRICE_STOCK, self::MODE_STOCK_ONLY];

    /**
     * Sync a single feed of the active store: download → parse → import products → update stock
     */
    public static function sync(int $feedId, bool $verbose = false): array {
        $feed = Warehouse::feedById($feedId);
        if (!$feed) {
            return ['error' => "Feed #{$feedId} not found"];
        }
        if (!$feed['enabled']) {
            return ['skipped' => true, 'reason' => 'Feed is disabled'];
        }

        $result = [
            'feed_id'   => $feedId,
            'feed_code' => $feed['code'],
            'imported'  => 0,
            'stock_updated' => 0,
            'skipped'   => 0,
            'errors'    => [],
        ];

        $fieldMap = json_decode($feed['field_map'], true) ?: [];
        if (empty($fieldMap)) {
            $result['error'] = 'Empty field_map — cannot map feed fields to product fields';
            Warehouse::updateFeedStatus($feedId, 'error: no field_map', 0);
            return $result;
        }
        if (!in_array($feed['import_mode'] ?? '', self::MODES, true)) {
            $result['error'] = 'Unknown import mode: ' . ($feed['import_mode'] ?? '');
            Warehouse::updateFeedStatus($feedId, 'error: bad import mode', 0);
            return $result;
        }

        // Download feed
        if ($verbose) echo "  Downloading {$feed['url']}...\n";
        $xml = self::download($feed['url']);
        if ($xml === false) {
            $result['error'] = 'Failed to download feed';
            Warehouse::updateFeedStatus($feedId, 'error: download failed', 0);
            return $result;
        }
        if ($verbose) echo "  Downloaded " . strlen($xml) . " bytes\n";

        $result = self::importDocument($feed, $xml, $result);
        if (isset($result['error'])) return $result;

        if ($verbose) {
            echo "  Imported: {$result['imported']}, Stock: {$result['stock_updated']}, " .
                 "Skipped: {$result['skipped']}, Errors: " . count($result['errors']) . "\n";
        }

        return $result;
    }

    /**
     * Parse a downloaded feed document per the feed's format and import it (applyItems). CSV rows that cannot be read
     * are reported as errors and mark the sync incomplete, so no product is zeroed for a row the parser lost.
     */
    public static function importDocument(array $feed, string $body, ?array $result = null): array {
        $result ??= ['feed_id' => (int)$feed['id'], 'feed_code' => $feed['code'], 'imported' => 0, 'stock_updated' => 0, 'skipped' => 0, 'errors' => []];
        if ($feed['format'] === 'xml') {
            $items = self::parseXml($body);
        } elseif ($feed['format'] === 'csv') {
            $rejected = [];
            $items = self::parseCsv($body, $rejected);
            foreach ($rejected as $line) $result['errors'][] = "CSV row {$line}: column count does not match the header";
            $result['rows_dropped'] = count($rejected);
        } else {
            $result['error'] = "Unsupported format: {$feed['format']}";
            Warehouse::updateFeedStatus((int)$feed['id'], 'error: bad format', 0);
            return $result;
        }
        return self::applyItems($feed, $items, $result);
    }

    /**
     * Import parsed feed items into the active store per the feed's import mode and book the stock into the feed's
     * warehouse; records the feed status. The feed must belong to the active store (Warehouse::feedById()).
     */
    public static function applyItems(array $feed, array $items, ?array $result = null): array {
        $feedId = (int)$feed['id'];
        $result ??= ['feed_id' => $feedId, 'feed_code' => $feed['code'], 'imported' => 0, 'stock_updated' => 0, 'skipped' => 0, 'errors' => []];
        $importMode = (string)($feed['import_mode'] ?? '');
        if (!in_array($importMode, self::MODES, true)) throw new InvalidArgumentException("Unknown feed import mode: {$importMode}");
        $fieldMap = json_decode((string)$feed['field_map'], true) ?: [];

        // Import products + collect stock data
        $db = Db::get();
        $db->beginTransaction();
        $stockMap = [];
        $listed = [];
        // Rows the parser could not read are still listed by the supplier: nothing may be zeroed then either.
        $complete = empty($result['rows_dropped']);

        foreach ($items as $i => $raw) {
            $db->exec('SAVEPOINT row_import');
            try {
                // Listed even when the row then fails: only products absent from the feed lose their stock.
                foreach (self::sourcesOf('sku', $fieldMap) as $source) {
                    if (is_string($raw[$source] ?? null) && trim($raw[$source]) !== '') $listed[trim($raw[$source])] = true;
                }
                $mapped = self::mapFields($raw, $fieldMap);
                if (empty($mapped['sku'])) {
                    $db->exec('RELEASE SAVEPOINT row_import');
                    $result['skipped']++;
                    continue;
                }
                $listed[(string)$mapped['sku']] = true;

                if ($importMode === self::MODE_UPSERT) {
                    // Full upsert — create or update product with all fields. The stock goes only into the feed's
                    // warehouse below: the product import would book it into the default warehouse as well.
                    Import::importProduct(array_diff_key($mapped, ['stock' => true]), $db);
                } elseif (!($importMode === self::MODE_PRICE_STOCK
                    // Only update price + stock for existing products — never create or overwrite content
                    ? self::updatePriceOnly($mapped, $db)
                    // Only the warehouse stock of an existing product or variant SKU of this catalog
                    : self::skuInCatalog((string)$mapped['sku'], $db))) {
                    // Unknown here (or another catalog's SKU): no stock for this warehouse either.
                    $db->exec('RELEASE SAVEPOINT row_import');
                    $result['skipped']++;
                    continue;
                }
                $result['imported']++;

                // Collect stock for warehouse update
                if (isset($mapped['stock'])) {
                    $stockMap[$mapped['sku']] = (int)$mapped['stock'];
                }

                $db->exec('RELEASE SAVEPOINT row_import');
            } catch (\Throwable $e) {
                $result['errors'][] = "Item #{$i}: {$e->getMessage()}";
                $db->exec('ROLLBACK TO SAVEPOINT row_import');
                if (count($result['errors']) > 100) {
                    $complete = false;
                    break;
                }
            }
        }

        $db->commit();
        if ($result['imported'] > 0) Cache::catalogChanged();

        // Update warehouse stock
        if (!empty($stockMap)) {
            $result['stock_updated'] = Warehouse::bulkSetStock(
                (int)$feed['warehouse_id'],
                $stockMap
            );
        }
        // Cut short by the error limit or rows lost in parsing: those products are still listed, so nothing is zeroed.
        $result['stock_zeroed'] = $complete ? self::zeroUnlisted($feed, $fieldMap, $listed) : 0;

        // Update feed status
        $status = empty($result['errors']) ? 'ok' : 'ok with ' . count($result['errors']) . ' errors';
        Warehouse::updateFeedStatus($feedId, $status, $result['imported']);
        return $result;
    }

    /**
     * Sync all enabled feeds of every store (optionally only those due by schedule). Each feed runs inside the store
     * context of its warehouse, so products, slugs and stock land in that store's catalog and ledger.
     */
    public static function syncAll(bool $verbose = false, bool $respectSchedule = true): array {
        $feeds = Warehouse::allFeedsAllStores();
        $results = [];

        foreach ($feeds as $feed) {
            if (!$feed['enabled']) continue;

            if ($respectSchedule && $feed['last_sync']) {
                if (!self::isDue($feed['schedule'], $feed['last_sync'])) {
                    if ($verbose) echo "[{$feed['code']}] Not due yet, skipping\n";
                    continue;
                }
            }

            if ($verbose) echo "[{$feed['code']}] Syncing ({$feed['store_id']})...\n";
            try {
                $results[$feed['code']] = self::syncInStore((string)$feed['store_id'], (int)$feed['id'], $verbose);
            } catch (\Throwable $e) {
                // F5: isolate per-feed failures (e.g. parseXml throw, network error)
                // so one bad feed does NOT abort syncAll and skip the remaining feeds.
                $results[$feed['code']] = [
                    'feed_id'       => (int)$feed['id'],
                    'feed_code'     => $feed['code'],
                    'imported'      => 0,
                    'stock_updated' => 0,
                    'skipped'       => 0,
                    'errors'        => [],
                    'error'         => 'sync failed: ' . $e->getMessage(),
                ];
                Warehouse::updateFeedStatus((int)$feed['id'], 'error: ' . substr($e->getMessage(), 0, 100), 0);
                if ($verbose) echo "[{$feed['code']}] FAILED: {$e->getMessage()}\n";
            }
        }

        return $results;
    }

    /** Run sync() for a feed inside a store of its warehouse's catalog (the previous context is restored afterwards). */
    public static function syncInStore(string $catalog, int $feedId, bool $verbose = false): array {
        return PaymentWebhookRouting::within(Warehouse::catalogStoreId($catalog), static fn(): array => self::sync($feedId, $verbose));
    }

    /** @return list<string> the feed fields mapped onto the product field $field */
    private static function sourcesOf(string $field, array $fieldMap): array {
        $sources = [];
        foreach ($fieldMap as $source => $target) {
            if ($target === $field || (is_array($target) && ($target['field'] ?? null) === $field)) $sources[] = (string)$source;
        }
        return $sources;
    }

    /**
     * A product the supplier no longer lists has nothing left in the feed's warehouse: its count drops to the units
     * open orders reserve there. Only for a feed that maps stock, listed at least one product and is the warehouse's
     * only feed (another feed's products must not be zeroed). Returns the number of SKUs zeroed.
     */
    private static function zeroUnlisted(array $feed, array $fieldMap, array $listed): int {
        $warehouseId = (int)$feed['warehouse_id'];
        if ($listed === [] || self::sourcesOf('stock', $fieldMap) === [] || count(Warehouse::feedsByWarehouse($warehouseId)) !== 1) return 0;
        $stmt = Db::get()->prepare('SELECT sku FROM warehouse_stock WHERE warehouse_id = :wh AND qty > reserved');
        $stmt->execute([':wh' => $warehouseId]);
        $zero = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sku) {
            if (!isset($listed[(string)$sku])) $zero[(string)$sku] = 0;
        }
        return $zero === [] ? 0 : Warehouse::bulkSetStock($warehouseId, $zero);
    }

    /** True when the SKU is a product or a variant of a product of the active catalog. */
    private static function skuInCatalog(string $sku, PDO $db): bool {
        $catalog = StoreContext::current()->catalogId();
        $stmt = $db->prepare('SELECT 1 FROM products WHERE sku = :sku AND store_id = :catalog');
        $stmt->execute([':sku' => $sku, ':catalog' => $catalog]);
        if ($stmt->fetchColumn() !== false) return true;
        $stmt = $db->prepare('SELECT 1 FROM variants v JOIN products p ON p.id = v.product_id WHERE v.sku = :sku AND p.store_id = :catalog');
        $stmt->execute([':sku' => $sku, ':catalog' => $catalog]);
        return $stmt->fetchColumn() !== false;
    }

    // ─── Download ────────────────────────────────────────────

    /** price_stock mode: update the price of an existing product in the current catalog; false when the SKU is not there. */
    private static function updatePriceOnly(array $mapped, PDO $db): bool {
        $params = [':sku' => $mapped['sku'], ':catalog' => StoreContext::current()->catalogId()];
        $existing = $db->prepare("SELECT * FROM products WHERE sku = :sku AND store_id = :catalog");
        $existing->execute($params);
        $row = $existing->fetch();
        if (!$row) return false;
        $updates = [];
        if (isset($mapped['price'])) {
            $priceCents = Import::assertVariantFloor((int)$row['id'], Import::parsePriceToCents($mapped['price']));
            $updates[] = 'price = :price';
            $params[':price'] = $priceCents;
            // Set old price as compare_price if price dropped
            if ($priceCents < (int)$row['price'] && (int)$row['price'] > 0) {
                $updates[] = 'compare_price = :cprice';
                $params[':cprice'] = (int)$row['price'];
            }
        }
        if (!empty($updates)) {
            $updates[] = "updated_at = " . (Db::isSqlite() ? "datetime('now')" : "NOW()");
            $db->prepare("UPDATE products SET " . implode(', ', $updates) . " WHERE sku = :sku AND store_id = :catalog")
               ->execute($params);
            Products::recordUpdated((int)$row['id'], $row);
        }
        return true;
    }

    private static function download(string $url): string|false {
        $endpoint = self::publicFeedEndpoint($url);
        if ($endpoint === null || !function_exists('curl_init')) return false;

        $maxBytes = 10 * 1024 * 1024;
        $data = '';
        $ip = str_contains($endpoint['ip'], ':') ? '[' . $endpoint['ip'] . ']' : $endpoint['ip'];
        $ch = curl_init($url);
        if ($ch === false) return false;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_USERAGENT => 'BareBones Shop Feed Sync/1.0',
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXFILESIZE => $maxBytes,
            CURLOPT_RESOLVE => ["{$endpoint['host']}:{$endpoint['port']}:{$ip}"],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$data, $maxBytes): int {
                if (strlen($data) + strlen($chunk) > $maxBytes) return 0;
                $data .= $chunk;
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $ok !== false && $status >= 200 && $status < 300 ? $data : false;
    }

    private static function publicFeedEndpoint(string $url): ?array {
        if ($url === '' || preg_match('/[\\x00-\\x20\\x7F\\\\]/', $url)) return null;
        $parts = parse_url($url);
        if (!is_array($parts)) return null;

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || str_contains($host, '%')) {
            return null;
        }

        $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        if (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)) return null;

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            if (strlen($host) > 253 || preg_match('/^[0-9.]+$/', $host)
                || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host)) {
                return null;
            }
            foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip)) $ips[] = $ip;
            }
            if (!$ips) $ips = gethostbynamel($host) ?: [];
        }

        $ips = array_values(array_unique($ips));
        if (!$ips) return null;
        foreach ($ips as $ip) {
            if (!self::isPublicFeedIp($ip)) return null;
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    private static function isPublicFeedIp(string $ip): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        $packed = inet_pton($ip);
        if ($packed === false) return false;
        if (strlen($packed) === 4) {
            // Non-global IPv4 space that PHP's private/reserved flags do not cover.
            $long = unpack('N', $packed)[1];
            foreach ([
                ['100.64.0.0', 10],  // RFC 6598 carrier-grade NAT (provider-internal hosts)
                ['192.0.0.0', 24],   // RFC 6890 IETF protocol assignments
                ['198.18.0.0', 15],  // RFC 2544 benchmarking
            ] as [$network, $bits]) {
                $mask = (-1 << (32 - $bits)) & 0xFFFFFFFF;
                if (($long & $mask) === (ip2long($network) & $mask)) return false;
            }
            return true;
        }

        foreach ([
            [hex2bin('0064ff9b0000000000000000'), 12], // RFC 6052 well-known NAT64 /96
            [hex2bin('0064ff9b0001'), 6],                 // RFC 8215 local-use NAT64 /48
            [hex2bin('000000000000000000000000'), 12], // IPv4-compatible /96
            [hex2bin('00000000000000000000ffff'), 12], // IPv4-mapped /96
            [hex2bin('2002'), 2],                       // 6to4 /16
            [hex2bin('20010000'), 4],                   // Teredo /32
        ] as [$prefix, $length]) {
            if (substr($packed, 0, $length) === $prefix) return false;
        }
        return true;
    }

    // ─── XML Parser (<SHOP><SHOPITEM>) ───────────────────────

    private static function parseXml(string $xml): array {
        // Clean up common issues
        $xml = preg_replace('/&(?!amp;|lt;|gt;|quot;|apos;|#)/', '&amp;', $xml);

        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if ($doc === false) {
            $err = libxml_get_last_error();
            throw new \RuntimeException('XML parse error: ' . ($err ? $err->message : 'unknown'));
        }

        $items = [];

        // Support <SHOP><SHOPITEM> (Heureka standard)
        $shopItems = $doc->SHOPITEM ?? $doc->item ?? $doc->product ?? [];

        foreach ($shopItems as $node) {
            $item = [];
            foreach ($node->children() as $child) {
                $name = $child->getName();
                // Handle multiple elements with same name (e.g., IMGURL_ALTERNATIVE)
                if (isset($item[$name])) {
                    if (!is_array($item[$name])) {
                        $item[$name] = [$item[$name]];
                    }
                    $item[$name][] = trim((string)$child);
                } else {
                    $item[$name] = trim((string)$child);
                }
            }
            $items[] = $item;
        }

        return $items;
    }

    // ─── CSV Parser ──────────────────────────────────────────

    /**
     * Parse CSV records, including quoted fields that span lines. Records whose column count differs from the header
     * are not imported; their 1-based record numbers go into $rejected.
     *
     * @param list<int> $rejected
     */
    private static function parseCsv(string $data, array &$rejected = []): array {
        if (str_starts_with($data, "\xEF\xBB\xBF")) $data = substr($data, 3);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $data);
        rewind($stream);

        $header = fgetcsv($stream, null, ',', '"', '\\');
        if (!is_array($header) || $header === [null]) {
            fclose($stream);
            return [];
        }
        $header = array_map(static fn($h) => trim((string)$h), $header);
        $items = [];
        $record = 0;
        while (($row = fgetcsv($stream, null, ',', '"', '\\')) !== false) {
            if (count($row) === 1 && trim((string)$row[0]) === '') continue; // blank line
            $record++;
            if (count($row) === count($header)) {
                $items[] = array_combine($header, array_map(static fn($v) => (string)$v, $row));
            } else {
                $rejected[] = $record;
            }
        }
        fclose($stream);
        return $items;
    }

    // ─── Field Mapping ───────────────────────────────────────

    /**
     * Map feed fields to internal product fields using field_map.
     *
     * field_map example:
     * {
     *   "ITEM_ID": "sku",
     *   "PRODUCTNAME": "name",
     *   "CATEGORY_TEXT": {"field": "category", "separator": " | "},
     *   "PRICE_VAT": {"field": "price", "transform": "price_to_cents"},
     *   "STORE": {"field": "stock", "transform": "boolean_stock", "default_qty": 10},
     *   "IMGURL": "image",
     *   "IMGURL_ALTERNATIVE": {"field": "image", "append": true},
     *   "DESCRIPTION": "description",
     *   "DESCRIPTION_SHORT": "summary",
     *   "WEIGHT": {"field": "weight_g", "transform": "weight_to_grams"},
     *   "MANUFACTURER": "attr:manufacturer",
     *   "EAN": "attr:ean",
     *   "PN": "attr:part_number"
     * }
     */
    private static function mapFields(array $raw, array $fieldMap): array {
        $mapped = [];
        $images = [];

        foreach ($fieldMap as $srcField => $target) {
            $value = $raw[$srcField] ?? null;
            if ($value === null || $value === '') continue;

            // Simple mapping: "ITEM_ID": "sku"
            if (is_string($target)) {
                if ($target === 'image') {
                    $images = array_merge($images, is_array($value) ? $value : [$value]);
                } else {
                    $mapped[$target] = $value;
                }
                continue;
            }

            // Complex mapping with transforms
            if (!is_array($target)) continue;
            $destField = $target['field'] ?? null;
            if (!$destField) continue;

            $transform = $target['transform'] ?? null;
            $transformed = self::transform($value, $transform, $target);

            if ($destField === 'image') {
                $append = $target['append'] ?? false;
                $vals = is_array($transformed) ? $transformed : [$transformed];
                $images = array_merge($images, $vals);
            } elseif (isset($mapped[$destField]) && ($target['append'] ?? false)) {
                $mapped[$destField] .= "\n" . $transformed;
            } else {
                $mapped[$destField] = $transformed;
            }
        }

        if (!empty($images)) {
            $mapped['image'] = implode('|', array_filter($images));
        }

        return $mapped;
    }

    /** Apply transforms to field values */
    private static function transform(mixed $value, ?string $transform, array $config): mixed {
        if ($transform === null) return $value;

        return match ($transform) {
            // "39,73" → 3973, "1 299,00 Kč" → 129900 (cents)
            'price_to_cents' => Import::parsePriceToCents((string)$value),

            // "0,23kg" → 230 (grams)
            'weight_to_grams' => (int)round(floatval(str_replace(',', '.', preg_replace('/[^0-9,.]/', '', $value))) * 1000),

            // "1" → stock qty; "0" → 0; configurable default for "in stock"
            'boolean_stock' => ((int)$value > 0) ? (int)($config['default_qty'] ?? 10) : 0,

            // "Fotovoltaika | Karavány" → "Fotovoltaika/Karavány"
            'category_path' => str_replace(
                $config['separator'] ?? ' | ',
                '/',
                trim($value)
            ),

            // "27. 03. 2026" → "2026-03-27"
            'date_cz' => self::parseCzDate($value),

            // Strip HTML tags
            'strip_tags' => strip_tags($value),

            // Pass through
            default => $value,
        };
    }

    /** Parse Czech/Slovak date format "27. 03. 2026" or "27.03.2026" → "2026-03-27" */
    private static function parseCzDate(string $date): string {
        $date = str_replace(' ', '', $date);
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $date, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        return $date;
    }

    // ─── Schedule Check ──────────────────────────────────────

    /**
     * Check if a cron schedule is due based on last_sync time.
     * F6: a real cron evaluator. The previous heuristic only understood "*\/N",
     * so fixed-time schedules ("0 0 * * *", "30 8 * * 1") silently fell back to a
     * 1-hour interval and ran hourly. We now answer the precise question: did a
     * minute matching the cron expression occur in the window (last_sync, now]?
     */
    private static function isDue(string $schedule, string $lastSync): bool {
        $last = strtotime($lastSync . ' UTC');
        if (!$last) return true;

        $parts = preg_split('/\s+/', trim($schedule));
        if (count($parts) !== 5) return true; // invalid schedule → always run

        $now = time();
        // Walk minute-by-minute backwards from now; if a scheduled minute exists
        // after last_sync, the feed is due. Bounded to ~1 year of look-back so a
        // rare schedule with no recent match terminates instead of spinning.
        $cursor = $now - ($now % 60);
        $limit  = 366 * 24 * 60;
        for ($i = 0; $i <= $limit; $i++, $cursor -= 60) {
            if ($cursor <= $last) return false;       // no match in (last, now]
            if (self::cronMatches($parts, $cursor)) return true;
        }
        return false;
    }

    /** True if the 5 cron fields all match the given unix timestamp. */
    private static function cronMatches(array $parts, int $ts): bool {
        $domRestricted = ($parts[2] !== '*');
        $dowRestricted = ($parts[4] !== '*');
        $domMatch = self::cronField($parts[2], (int)date('j', $ts));
        $dowMatch = self::cronField($parts[4], (int)date('w', $ts)); // 0=Sun..6=Sat
        // Vixie-cron rule: when BOTH day-of-month and day-of-week are restricted,
        // the day matches if EITHER matches; otherwise both must match.
        $dayMatch = ($domRestricted && $dowRestricted) ? ($domMatch || $dowMatch) : ($domMatch && $dowMatch);

        return self::cronField($parts[0], (int)date('i', $ts))   // minute 0-59
            && self::cronField($parts[1], (int)date('G', $ts))   // hour 0-23
            && $dayMatch
            && self::cronField($parts[3], (int)date('n', $ts));  // month 1-12
    }

    /** Match a single cron field against a value. Supports *, a, a-b, a,b, *\/n, a-b/n. */
    private static function cronField(string $field, int $value): bool {
        foreach (explode(',', $field) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $step  = 1;
            $range = $part;
            if (str_contains($part, '/')) {
                [$range, $st] = explode('/', $part, 2);
                $step = max(1, (int)$st);
            }
            if ($range === '*') {
                if ($value % $step === 0) return true;
                continue;
            }
            if (str_contains($range, '-')) {
                [$lo, $hi] = explode('-', $range, 2);
                $lo = (int)$lo; $hi = (int)$hi;
                if ($value >= $lo && $value <= $hi && (($value - $lo) % $step) === 0) return true;
                continue;
            }
            $num = (int)$range;
            if ($step === 1) {
                if ($value === $num) return true;
            } elseif ($value >= $num && (($value - $num) % $step) === 0) {
                return true;
            }
        }
        return false;
    }
}
