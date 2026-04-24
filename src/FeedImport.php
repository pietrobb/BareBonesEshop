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

    /**
     * Sync a single feed: download → parse → import products → update stock
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

        // Download feed
        if ($verbose) echo "  Downloading {$feed['url']}...\n";
        $xml = self::download($feed['url']);
        if ($xml === false) {
            $result['error'] = 'Failed to download feed';
            Warehouse::updateFeedStatus($feedId, 'error: download failed', 0);
            return $result;
        }
        if ($verbose) echo "  Downloaded " . strlen($xml) . " bytes\n";

        // Parse based on format
        $items = [];
        if ($feed['format'] === 'xml') {
            $items = self::parseXml($xml);
        } elseif ($feed['format'] === 'csv') {
            $items = self::parseCsv($xml);
        } else {
            $result['error'] = "Unsupported format: {$feed['format']}";
            Warehouse::updateFeedStatus($feedId, 'error: bad format', 0);
            return $result;
        }

        if ($verbose) echo "  Parsed " . count($items) . " items\n";

        // Import products + collect stock data
        $db = Db::get();
        $db->beginTransaction();
        $stockMap = [];

        foreach ($items as $i => $raw) {
            $db->exec('SAVEPOINT row_import');
            try {
                $mapped = self::mapFields($raw, $fieldMap);
                if (empty($mapped['sku'])) {
                    $db->exec('RELEASE SAVEPOINT row_import');
                    $result['skipped']++;
                    continue;
                }

                $importMode = $feed['import_mode'] ?? 'upsert';

                if ($importMode === 'price_stock') {
                    // Only update price + stock for existing products — never create or overwrite content
                    $existing = $db->prepare("SELECT id, price FROM products WHERE sku = :sku");
                    $existing->execute([':sku' => $mapped['sku']]);
                    $row = $existing->fetch();
                    if ($row) {
                        $updates = [];
                        $params = [':sku' => $mapped['sku']];
                        if (isset($mapped['price'])) {
                            $priceCents = Import::parsePriceToCents($mapped['price']);
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
                            $db->prepare("UPDATE products SET " . implode(', ', $updates) . " WHERE sku = :sku")
                               ->execute($params);
                        }
                        $result['imported']++;
                    } else {
                        $result['skipped']++;
                    }
                } else {
                    // Full upsert — create or update product with all fields
                    Import::importProduct($mapped, $db);
                    $result['imported']++;
                }

                // Collect stock for warehouse update
                if (isset($mapped['stock'])) {
                    $stockMap[$mapped['sku']] = (int)$mapped['stock'];
                }

                $db->exec('RELEASE SAVEPOINT row_import');
            } catch (\Throwable $e) {
                $result['errors'][] = "Item #{$i}: {$e->getMessage()}";
                $db->exec('ROLLBACK TO SAVEPOINT row_import');
                if (count($result['errors']) > 100) break;
            }
        }

        $db->commit();

        // Update warehouse stock
        if (!empty($stockMap)) {
            $result['stock_updated'] = Warehouse::bulkSetStock(
                (int)$feed['warehouse_id'],
                $stockMap
            );
        }

        // Update feed status
        $status = empty($result['errors']) ? 'ok' : 'ok with ' . count($result['errors']) . ' errors';
        Warehouse::updateFeedStatus($feedId, $status, $result['imported']);

        if ($verbose) {
            echo "  Imported: {$result['imported']}, Stock: {$result['stock_updated']}, " .
                 "Skipped: {$result['skipped']}, Errors: " . count($result['errors']) . "\n";
        }

        return $result;
    }

    /**
     * Sync all enabled feeds (optionally only those due by schedule)
     */
    public static function syncAll(bool $verbose = false, bool $respectSchedule = true): array {
        $feeds = Warehouse::allFeeds();
        $results = [];

        foreach ($feeds as $feed) {
            if (!$feed['enabled']) continue;

            if ($respectSchedule && $feed['last_sync']) {
                if (!self::isDue($feed['schedule'], $feed['last_sync'])) {
                    if ($verbose) echo "[{$feed['code']}] Not due yet, skipping\n";
                    continue;
                }
            }

            if ($verbose) echo "[{$feed['code']}] Syncing...\n";
            $results[$feed['code']] = self::sync((int)$feed['id'], $verbose);
        }

        return $results;
    }

    // ─── Download ────────────────────────────────────────────

    private static function download(string $url): string|false {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 120,
                'user_agent' => 'BareBones Shop Feed Sync/1.0',
                'follow_location' => 1,
                'max_redirects' => 3,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $data = @file_get_contents($url, false, $ctx);
        return $data !== false ? $data : false;
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

    private static function parseCsv(string $data): array {
        $lines = explode("\n", $data);
        if (empty($lines)) return [];

        // BOM
        if (str_starts_with($lines[0], "\xEF\xBB\xBF")) {
            $lines[0] = substr($lines[0], 3);
        }

        $header = str_getcsv(array_shift($lines));
        $header = array_map('trim', $header);
        $items = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $row = str_getcsv($line);
            if (count($row) === count($header)) {
                $items[] = array_combine($header, $row);
            }
        }

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
            // "39,73" → 3973 (cents)
            'price_to_cents' => (int)round(floatval(str_replace(',', '.', str_replace(' ', '', $value))) * 100),

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

    /** Check if a cron schedule is due based on last_sync time */
    private static function isDue(string $schedule, string $lastSync): bool {
        $last = strtotime($lastSync);
        if (!$last) return true;

        $parts = preg_split('/\s+/', trim($schedule));
        if (count($parts) !== 5) return true; // invalid schedule → always run

        $now = time();
        $elapsed = $now - $last;

        // Simple heuristic: extract minimum interval from schedule
        // "0 */6 * * *" → every 6 hours → 21600 seconds
        // "*/15 * * * *" → every 15 minutes → 900 seconds
        $minute = $parts[0];
        $hour = $parts[1];

        if (str_starts_with($minute, '*/')) {
            $interval = (int)substr($minute, 2) * 60;
        } elseif (str_starts_with($hour, '*/')) {
            $interval = (int)substr($hour, 2) * 3600;
        } else {
            $interval = 3600; // default: 1 hour
        }

        return $elapsed >= $interval;
    }
}
