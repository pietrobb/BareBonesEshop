<?php
/**
 * Merchant low / critical stock alerts.
 *
 * Source of truth is warehouse_stock (available = qty - reserved, summed over the
 * store's warehouses). Every ledger movement (Inventory::movement) marks its SKU;
 * at the end of the request one `stock_alert` job per store is queued, so checkout
 * never waits for or fails on e-mail. The worker compares each SKU's level with the
 * stored state per SKU+store and mails only downward crossings
 * (ok → low → critical → out), batched into one e-mail per run. The state re-arms
 * once the SKU is restocked above `low`. Only active physical products are tracked;
 * SKUs without any warehouse_stock row are not stock-tracked.
 */
class StockAlerts {
    public const OK = 'ok';
    public const LOW = 'low';
    public const CRITICAL = 'critical';
    public const OUT = 'out';
    private const RANK = [self::OK => 0, self::LOW => 1, self::CRITICAL => 2, self::OUT => 3];
    private const DEFAULTS = [
        'enabled' => true, 'low' => 5, 'critical' => 2,
        'recipients' => [], 'digest_hour' => null, 'locale' => '',
    ];

    private static array $pending = [];
    private static bool $shutdownRegistered = false;
    private static ?array $appConfig = null;

    /** Tests: replace the whole application config (null = reload config.php). */
    public static function setConfigForTesting(?array $cfg): void {
        self::$appConfig = $cfg;
    }

    public static function config(): array {
        $cfg = array_replace(self::DEFAULTS, (array)(self::appConfig()['stock_alerts'] ?? []));
        $cfg['enabled'] = $cfg['enabled'] !== false;
        $cfg['low'] = max(0, (int)$cfg['low']);
        $cfg['critical'] = max(0, min((int)$cfg['critical'], $cfg['low']));
        $hour = $cfg['digest_hour'];
        $cfg['digest_hour'] = $hour === null || $hour === '' ? null : max(0, min(23, (int)$hour));
        return $cfg;
    }

    /** Effective [low, critical] for a product; per-product overrides win, critical never exceeds low. */
    public static function thresholds(?array $product = null): array {
        $cfg = self::config();
        $low = isset($product['stock_low_threshold']) && $product['stock_low_threshold'] !== ''
            ? max(0, (int)$product['stock_low_threshold']) : $cfg['low'];
        $critical = isset($product['stock_critical_threshold']) && $product['stock_critical_threshold'] !== ''
            ? max(0, (int)$product['stock_critical_threshold']) : $cfg['critical'];
        return [$low, min($critical, $low)];
    }

    /** Thresholds are inclusive: reaching `low` is already low; 0 is always out of stock. */
    public static function level(int $available, int $low, int $critical): string {
        if ($available <= 0) return self::OUT;
        if ($available <= $critical) return self::CRITICAL;
        if ($available <= $low) return self::LOW;
        return self::OK;
    }

    /** Remember a SKU whose stock moved; cheap and never throws (called inside checkout transactions). */
    public static function touch(string $sku): void {
        if ($sku === '') return;
        try {
            self::$pending[StoreContext::current()->id()][$sku] = true;
        } catch (Throwable $e) {
            return;
        }
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'flush']);
        }
    }

    /** Queue one stock_alert job per store for the SKUs touched so far. Never throws. */
    public static function flush(): int {
        $pending = self::$pending;
        self::$pending = [];
        if (!$pending) return 0;
        $queued = 0;
        try {
            if (!self::config()['enabled']) return 0;
            foreach ($pending as $storeId => $skus) {
                JobQueue::push('stock_alert', ['skus' => array_keys($skus), '_store_id' => (string)$storeId], 4);
                $queued++;
            }
        } catch (Throwable $e) {
            Logger::exception('stock_alert.enqueue_failed', $e);
        }
        return $queued;
    }

    /**
     * Worker entry point: evaluate SKUs (null = every tracked SKU of the store) and send
     * one e-mail for all new downward crossings. Throws when delivery failed so the job retries;
     * in that case the claimed state is rolled back and the next run alerts again.
     */
    public static function process(?array $skus = null): array {
        $cfg = self::config();
        $result = ['checked' => 0, 'alerts' => 0, 'sent' => 0];
        if (!$cfg['enabled']) return $result;
        $storeId = StoreContext::current()->id();
        $rows = self::rows($skus === null ? null : array_values(array_unique(array_map('strval', $skus))), false);
        $result['checked'] = count($rows);

        $alerts = [];
        foreach ($rows as $row) {
            $previous = self::stateLevel($storeId, $row['sku']);
            if (self::RANK[$row['level']] > self::RANK[$previous ?? self::OK]) {
                if (self::claim($storeId, $row, $previous)) $alerts[] = $row + ['previous_level' => $previous];
            } elseif ($row['level'] === self::OK && $previous !== null && $previous !== self::OK) {
                self::rearm($storeId, $row, $previous);
            }
        }
        $result['alerts'] = count($alerts);
        if (!$alerts) return $result;

        try {
            $result['sent'] = self::deliver($alerts, false, $cfg);
        } catch (Throwable $e) {
            foreach ($alerts as $alert) self::unclaim($storeId, $alert);
            throw $e;
        }
        return $result;
    }

    /** Optional daily summary of everything at or below `low`; claimed once per store and day. */
    public static function digest(?int $now = null): int {
        $cfg = self::config();
        if (!$cfg['enabled'] || $cfg['digest_hour'] === null) return 0;
        $now ??= time();
        if ((int)date('G', $now) < $cfg['digest_hour']) return 0;
        $storeId = StoreContext::current()->id();
        $day = date('Y-m-d', $now);
        $claim = Db::get()->prepare(Db::insertIgnore() . ' INTO stock_alert_digests (store_id, digest_date) VALUES (:store, :day)');
        $claim->execute([':store' => $storeId, ':day' => $day]);
        if ($claim->rowCount() !== 1) return 0;
        $rows = self::belowLow();
        if (!$rows) return 0;
        try {
            return self::deliver($rows, true, $cfg);
        } catch (Throwable $e) {
            Db::get()->prepare('DELETE FROM stock_alert_digests WHERE store_id = :store AND digest_date = :day')
                ->execute([':store' => $storeId, ':day' => $day]);
            throw $e;
        }
    }

    /**
     * Tracked physical SKUs at or below their `low` threshold, worst first (dashboard, digest, product filter).
     * Ordered and limited in SQL: a catalog with 100k+ low SKUs must not be loaded into PHP for a 20-row card.
     */
    public static function belowLow(?int $limit = null, int $offset = 0): array {
        [$sql, $params] = self::trackedSql(null, true);
        $sql = "SELECT * FROM ({$sql}) r WHERE r.alert_rank > 0 ORDER BY r.alert_rank DESC, r.available ASC, r.sku ASC";
        if ($limit !== null) $sql .= ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        return self::fetchRows($sql, $params);
    }

    /** Dashboard summary with the same thresholds the e-mails use. */
    public static function summary(int $limit = 20): array {
        // One query: the window count is taken over all low rows before the LIMIT.
        [$sql, $params] = self::trackedSql(null, true);
        $stmt = Db::get()->prepare("SELECT r.*, COUNT(*) OVER () AS total_low FROM ({$sql}) r WHERE r.alert_rank > 0
            ORDER BY r.alert_rank DESC, r.available ASC, r.sku ASC LIMIT " . max(1, $limit));
        $stmt->execute($params);
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['count' => (int)($raw[0]['total_low'] ?? 0), 'products' => array_map([self::class, 'mapRow'], $raw)];
    }

    /**
     * Worst level below `low` per product for the given product ids only (one admin list page).
     * @param int[] $productIds
     * @return array<int, string> product id => level
     */
    public static function levelsForProducts(array $productIds): array {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds) return [];
        $in = implode(', ', $productIds);
        $catalog = StoreContext::current()->catalogId();
        $stmt = Db::get()->prepare("SELECT sku FROM products WHERE store_id = :store AND id IN ({$in})
            UNION SELECT v.sku FROM variants v JOIN products p ON p.id = v.product_id WHERE p.store_id = :store2 AND p.id IN ({$in})");
        $stmt->execute([':store' => $catalog, ':store2' => $catalog]);
        $skus = array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), static fn(string $sku): bool => $sku !== ''));
        $levels = [];
        foreach (self::rows($skus, true) as $row) {
            $current = $levels[$row['product_id']] ?? self::OK;
            if (self::RANK[$row['level']] > self::RANK[$current]) $levels[$row['product_id']] = $row['level'];
        }
        return $levels;
    }

    /**
     * Subquery of product ids with a tracked SKU at or below `low`, for `id IN (...)` filters.
     * Named parameters are prefixed `:sa_` so they can be merged into the caller's.
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function belowLowProductIdsSql(): array {
        [$sql, $params] = self::trackedSql(null, true);
        return ["SELECT r.product_id FROM ({$sql}) r WHERE r.alert_rank > 0", $params];
    }

    /**
     * Availability rows for tracked SKUs: products and variants of active physical products.
     * @param string[]|null $skus null = all SKUs of the store
     */
    private static function rows(?array $skus, bool $onlyBelowLow): array {
        if ($skus === []) return [];
        if ($skus !== null && count($skus) > 500) {
            $rows = [];
            foreach (array_chunk($skus, 500) as $chunk) $rows = array_merge($rows, self::rows($chunk, $onlyBelowLow));
            return $rows;
        }
        [$sql, $params] = self::trackedSql($skus, $onlyBelowLow);
        return self::fetchRows("SELECT * FROM ({$sql}) r" . ($onlyBelowLow ? ' WHERE r.alert_rank > 0' : ''), $params);
    }

    /**
     * Tracked availability rows with the effective thresholds (per-product override, else config; never negative,
     * critical never above low) and the level rank (0 ok … 3 out), computed in SQL like thresholds() and level().
     * Each UNION branch gets its own parameter names (MySQL native prepares refuse a repeated name).
     * @param string[]|null $skus
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function trackedSql(?array $skus, bool $onlyBelowLow = false): array {
        $cfg = self::config();
        $catalog = StoreContext::current()->catalogId();
        $low = (int)$cfg['low'];
        $critical = (int)$cfg['critical'];
        // Below-low lists join only SKUs at or below the configured `low`, or below a product's own higher `low`, to the
        // catalog: on a large catalog almost every SKU is in stock, and joining all of them made the dashboard slow.
        $prune = $onlyBelowLow && $skus === null;
        $maxLow = $prune ? self::maxLowThreshold($catalog, $low) : $low;
        $lowExpr = "CASE WHEN COALESCE(p.stock_low_threshold, {$low}) < 0 THEN 0 ELSE COALESCE(p.stock_low_threshold, {$low}) END";
        $criticalRaw = "CASE WHEN COALESCE(p.stock_critical_threshold, {$critical}) < 0 THEN 0 ELSE COALESCE(p.stock_critical_threshold, {$critical}) END";
        $criticalExpr = "CASE WHEN {$criticalRaw} > {$lowExpr} THEN {$lowExpr} ELSE {$criticalRaw} END";
        $params = [];
        $branches = [];
        // MySQL otherwise starts the variant branch from all active products and probes the summed stock 100k times.
        $join = Db::isMysql() ? 'STRAIGHT_JOIN' : 'JOIN';
        foreach (['p' => "'' AS variant_name FROM %s {$join} products p ON p.sku = s.sku",
                  'v' => "v.name AS variant_name FROM %s {$join} variants v ON v.sku = s.sku {$join} products p ON p.id = v.product_id"] as $branch => $from) {
            $bind = static function (string $name, mixed $value) use (&$params, $branch): string {
                $key = ":sa_{$branch}_{$name}";
                $params[$key] = $value;
                return $key;
            };
            $skuFilter = '';
            if ($skus !== null) {
                $names = [];
                foreach (array_values($skus) as $i => $sku) $names[] = $bind("sku{$i}", (string)$sku);
                $skuFilter = ' AND sku IN (' . implode(', ', $names) . ')';
            }
            $having = '';
            if ($prune) {
                $having = " HAVING SUM(qty - reserved) <= {$low}";
                if ($maxLow > $low) {
                    $overridden = $branch === 'p'
                        ? 'SELECT sku FROM products WHERE store_id = ' . $bind('ov', $catalog) . " AND stock_low_threshold > {$low}"
                        : 'SELECT v.sku FROM variants v JOIN products o ON o.id = v.product_id WHERE o.store_id = ' . $bind('ov', $catalog) . " AND o.stock_low_threshold > {$low}";
                    $having .= " OR (SUM(qty - reserved) <= {$maxLow} AND sku IN ({$overridden}))";
                }
            }
            $stock = '(SELECT sku, SUM(qty - reserved) AS available, SUM(reserved) AS reserved FROM warehouse_stock WHERE store_id = '
                . $bind('ws', $catalog) . "{$skuFilter} GROUP BY sku{$having}) s";
            $digital = [];
            foreach (VariantAvailability::DIGITAL_TYPES as $i => $type) $digital[] = $bind("dt{$i}", $type);
            $branches[] = "SELECT s.sku, CASE WHEN s.available < 0 THEN 0 ELSE s.available END AS available, s.reserved,
                    p.id AS product_id, p.name AS product_name, {$lowExpr} AS low_threshold, {$criticalExpr} AS critical_threshold, "
                . sprintf($from, $stock)
                . ' WHERE p.store_id = ' . $bind('store', $catalog) . " AND p.status = 'active' AND LOWER(COALESCE(p.product_type, '')) NOT IN (" . implode(', ', $digital) . ')';
        }
        $sql = 'SELECT t.*, CASE WHEN t.available <= 0 THEN 3 WHEN t.available <= t.critical_threshold THEN 2
                WHEN t.available <= t.low_threshold THEN 1 ELSE 0 END AS alert_rank
            FROM (' . implode(' UNION ALL ', $branches) . ') t';
        return [$sql, $params];
    }

    /** The highest effective `low` of the store's products (config value or a larger per-product override). */
    private static function maxLowThreshold(string $catalog, int $low): int {
        $stmt = Db::get()->prepare('SELECT MAX(stock_low_threshold) FROM products WHERE store_id = :store');
        $stmt->execute([':store' => $catalog]);
        return max($low, (int)$stmt->fetchColumn());
    }

    private static function fetchRows(string $sql, array $params): array {
        $stmt = Db::get()->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $rows[] = self::mapRow($row);
        return $rows;
    }

    private static function mapRow(array $row): array {
        $name = (string)$row['product_name'];
        if ((string)$row['variant_name'] !== '') $name .= ' – ' . $row['variant_name'];
        return [
            'sku' => (string)$row['sku'],
            'product_id' => (int)$row['product_id'],
            'name' => $name,
            'available' => (int)$row['available'],
            'reserved' => max(0, (int)$row['reserved']),
            'low' => (int)$row['low_threshold'],
            'critical' => (int)$row['critical_threshold'],
            'level' => array_flip(self::RANK)[(int)$row['alert_rank']],
        ];
    }

    private static function deliver(array $rows, bool $digest, array $cfg): int {
        $app = self::appConfig();
        $recipients = MerchantNotifier::recipients($app, (array)$cfg['recipients']);
        if (!$recipients) {
            Logger::warning('stock_alert.no_recipients', ['skus' => array_column($rows, 'sku')]);
            return 0;
        }
        try {
            $baseUrl = Security::appUrl($app) . '/';
        } catch (RuntimeException $e) {
            $baseUrl = '';
        }
        $items = static fn(array $level): array => array_values(array_map(static fn(array $row): array => [
            'name' => $row['name'],
            'sku' => $row['sku'],
            'available' => $row['available'],
            'reserved' => $row['reserved'],
            'threshold' => $row['level'] === self::LOW ? $row['low'] : $row['critical'],
            'admin_url' => $baseUrl . 'admin.php?p=product&id=' . $row['product_id'],
        ], array_filter($rows, static fn(array $row): bool => in_array($row['level'], $level, true))));
        // The subject names the most severe level in the e-mail and its first product.
        $worst = self::LOW;
        foreach ([self::OUT, self::CRITICAL] as $level) {
            if (in_array($level, array_column($rows, 'level'), true)) { $worst = $level; break; }
        }
        $first = array_values(array_filter($rows, static fn(array $row): bool => $row['level'] === $worst))[0] ?? $rows[0];
        $vars = [
            'digest' => $digest,
            'alert' => !$digest,
            'worst_out' => $worst === self::OUT,
            'worst_critical' => $worst === self::CRITICAL,
            'worst_low' => $worst === self::LOW,
            'count' => count($rows),
            'more_count' => count($rows) - 1,
            'first_name' => $first['name'],
            'first_available' => $first['available'],
            'out_items' => $items([self::OUT]),
            'critical_items' => $items([self::CRITICAL]),
            'low_items' => $items([self::LOW]),
            'admin_url' => $baseUrl . 'admin.php?p=products&stock=low',
        ];

        $sent = 0;
        $errors = [];
        foreach ($recipients as $recipient) {
            try {
                $mail = MailService::send('merchant_stock_low', $recipient, (string)$cfg['locale'], $vars);
                if (($mail['status'] ?? '') === 'sent') $sent++;
                else $errors[] = $recipient . ': ' . ($mail['error'] ?? 'not sent');
            } catch (Throwable $e) {
                $errors[] = $recipient . ': ' . $e->getMessage();
            }
        }
        // A partial delivery keeps the state (no duplicate to the inboxes that got it); none = retry.
        if ($errors) Logger::warning('stock_alert.delivery_failed', ['errors' => $errors, 'sent' => $sent]);
        if ($sent === 0) throw new RuntimeException('Stock alert e-mail not delivered: ' . implode('; ', $errors));
        return $sent;
    }

    private static function stateLevel(string $storeId, string $sku): ?string {
        $stmt = Db::get()->prepare('SELECT level FROM stock_alert_state WHERE store_id = :store AND sku = :sku');
        $stmt->execute([':store' => $storeId, ':sku' => $sku]);
        $level = $stmt->fetchColumn();
        return $level === false ? null : (string)$level;
    }

    /** Atomically move the state to the new level; false when a parallel worker already did. */
    private static function claim(string $storeId, array $row, ?string $previous): bool {
        $db = Db::get();
        if ($previous === null) {
            $stmt = $db->prepare(Db::insertIgnore() . ' INTO stock_alert_state (store_id, sku, level, available, alerted_at, updated_at)
                VALUES (:store, :sku, :level, :available, ' . Db::now() . ', ' . Db::now() . ')');
            $stmt->execute([':store' => $storeId, ':sku' => $row['sku'], ':level' => $row['level'], ':available' => $row['available']]);
            return $stmt->rowCount() === 1;
        }
        $stmt = $db->prepare('UPDATE stock_alert_state SET level = :level, available = :available, alerted_at = ' . Db::now() . ', updated_at = ' . Db::now() . '
            WHERE store_id = :store AND sku = :sku AND level = :previous');
        $stmt->execute([':level' => $row['level'], ':available' => $row['available'], ':store' => $storeId, ':sku' => $row['sku'], ':previous' => $previous]);
        return $stmt->rowCount() === 1;
    }

    private static function unclaim(string $storeId, array $alert): void {
        Db::get()->prepare('UPDATE stock_alert_state SET level = :previous, updated_at = ' . Db::now() . '
            WHERE store_id = :store AND sku = :sku AND level = :level')
            ->execute([':previous' => $alert['previous_level'] ?? self::OK, ':store' => $storeId, ':sku' => $alert['sku'], ':level' => $alert['level']]);
    }

    private static function rearm(string $storeId, array $row, string $previous): void {
        Db::get()->prepare("UPDATE stock_alert_state SET level = 'ok', available = :available, updated_at = " . Db::now() . '
            WHERE store_id = :store AND sku = :sku AND level = :previous')
            ->execute([':available' => $row['available'], ':store' => $storeId, ':sku' => $row['sku'], ':previous' => $previous]);
    }

    private static function appConfig(): array {
        if (self::$appConfig === null) {
            $file = __DIR__ . '/../config.php';
            self::$appConfig = is_file($file) ? (array)(require $file) : [];
        }
        return self::$appConfig;
    }
}
