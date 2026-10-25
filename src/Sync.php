<?php
/**
 * BareBones Shop — ERP Sync Orchestration
 * Delta sync: products, stock, orders.
 * Usage: php cli/sync.php (cron: every 15 minutes)
 */
class Sync {
    private const COUNTERS = ['products_updated', 'stock_updated', 'orders_pushed', 'orders_failed', 'statuses_updated', 'products_skipped'];

    /**
     * Sync every store: products and stock are pulled once, into the default store's catalog (one ERP), then each
     * store pushes its own orders and pulls their statuses. Stores sharing that catalog get a cache rebuild too.
     */
    public static function runAllStores(): array {
        $root = Bootstrap::stores();
        $storeIds = array_map('strval', array_keys((array)($root['stores'] ?? [])));
        $default = StoreContext::resolve($root, '/', '');
        $storeIds = array_values(array_unique([$default->id(), ...$storeIds]));
        $totals = array_fill_keys(self::COUNTERS, 0);
        foreach ($storeIds as $storeId) {
            $result = PaymentWebhookRouting::within($storeId, static function () use ($storeId, $default, $totals): array {
                if ($storeId === $default->id()) return self::run();
                if (StoreContext::current()->catalogId() === $default->catalogId() && ($totals['products_updated'] > 0 || $totals['stock_updated'] > 0)) {
                    JobQueue::push('cache_rebuild', ['scope' => 'full'], 8);
                }
                return self::run(false);
            });
            if (!empty($result['skipped']) || !empty($result['error'])) return $result;
            foreach (self::COUNTERS as $counter) $totals[$counter] += (int)($result[$counter] ?? 0);
            if (!empty($result['catalog_error'])) $totals['catalog_error'] = (string)$result['catalog_error'];
        }
        return $totals;
    }

    /**
     * Run the sync cycle of the active store. Without $pullCatalog only its orders are pushed and their statuses pulled.
     */
    public static function run(bool $pullCatalog = true): array {
        $adapter = Adapters::integration();
        if (!$adapter) {
            return ['skipped' => true, 'reason' => 'No integration adapter configured'];
        }

        if (!$adapter->healthCheck()) {
            return ['error' => 'ERP health check failed'];
        }

        $result = array_fill_keys(self::COUNTERS, 0);
        // The next delta starts where this run started: a product changed in the ERP during the pull is pulled again.
        $startedAt = gmdate('c');

        // 1. Pull products (delta sync since last run)
        $lastSync = $pullCatalog ? self::watermark() : null;
        $since = $lastSync ? new \DateTime($lastSync) : null;

        // A failed catalog or stock pull (ERP down mid-listing, a bad page) must not keep paid orders from being pushed.
        $catalogErrors = [];
        try {
            foreach ($pullCatalog ? $adapter->pullProducts($since) : [] as $productData) {
                // One bad product (e.g. a SKU owned by another catalog) must not abort stock, order push and status pull.
                try {
                    Import::upsertProduct($productData);
                    $result['products_updated']++;
                } catch (\Throwable $e) {
                    $result['products_skipped']++;
                    Logger::phpLog('ERP product sync skipped [' . (string)($productData['sku'] ?? '?') . "]: {$e->getMessage()}");
                }
            }
        } catch (\Throwable $e) {
            $catalogErrors[] = 'products: ' . $e->getMessage();
        }

        // 2. Pull stock levels
        try {
            $stockData = $pullCatalog ? $adapter->pullStock() : [];
            if (!empty($stockData)) {
                $result['stock_updated'] = Products::bulkUpdateStock($stockData);
            }
        } catch (\Throwable $e) {
            $catalogErrors[] = 'stock: ' . $e->getMessage();
        }
        if ($catalogErrors !== []) {
            $result['catalog_error'] = implode('; ', $catalogErrors);
            Logger::phpLog('ERP catalog pull failed: ' . $result['catalog_error']);
            IncidentMonitor::report('erp.catalog_pull_failed', 'erp', 'warning', ['error' => $result['catalog_error']]);
        }

        // 3. Push new orders to ERP
        $orders = Orders::unsyncedForErp();
        foreach ($orders as $order) {
            try {
                $externalId = $adapter->pushOrder($order);
                Orders::markSynced((int)$order['id'], $externalId);
                $result['orders_pushed']++;
            } catch (\Throwable $e) {
                Orders::markSyncFailed((int)$order['id'], $e->getMessage());
                $result['orders_failed']++;
                Logger::phpLog("ERP order push failed [{$order['id']}]: {$e->getMessage()}");
            }
        }

        // 4. Pull order statuses from ERP
        $pendingSync = Orders::pendingStatusSync();
        foreach ($pendingSync as $order) {
            try {
                // Orders::markSynced/updateErpStatus write the erp_id/erp_status columns (pendingStatusSync selects on erp_id);
                // a legacy row may still carry its last status only as an erp_status: note marker.
                $externalId = (string)($order['erp_id'] ?? '');
                if ($externalId === '') continue;

                // Stamped before the pull: an order whose pull keeps failing must not hold the head of the rotation.
                Orders::markErpStatusChecked((int)$order['id']);
                $status = $adapter->pullOrderStatus($externalId);
                $lastErpStatus = $order['erp_status'] ?? null;
                if ($lastErpStatus === null && preg_match_all('/erp_status:(\S+)/', (string)($order['notes'] ?? ''), $m2)) {
                    $lastErpStatus = end($m2[1]);
                }
                if ($status !== (string)$lastErpStatus) {
                    Orders::updateErpStatus((int)$order['id'], $status);
                    $result['statuses_updated']++;
                }
            } catch (\Throwable $e) {
                Logger::phpLog("ERP status pull failed [{$order['id']}]: {$e->getMessage()}");
            }
        }

        if (!$pullCatalog) return $result;

        // A skipped product would never be pulled again past a newer watermark: keep the previous one so the next run retries it.
        // Until it is fixed every run re-pulls the delta since then, so the operator must see it.
        // An interrupted pull did not see the rest of the delta either.
        if ($result['products_skipped'] === 0 && $catalogErrors === []) {
            self::saveWatermark($startedAt);
        } elseif ($result['products_skipped'] > 0) {
            IncidentMonitor::report('erp.products_skipped', 'erp', 'warning', ['count' => $result['products_skipped']]);
        }

        // 5. Invalidate cache for changed products
        if ($result['products_updated'] > 0 || $result['stock_updated'] > 0) {
            JobQueue::push('cache_rebuild', ['scope' => 'full'], 8);
        }

        return $result;
    }

    /** Start of the active store's last clean product pull (ISO 8601), or null before the first one. */
    public static function watermark(): ?string {
        $stmt = Db::get()->prepare('SELECT watermark FROM erp_sync_state WHERE store_id = :store');
        $stmt->execute([':store' => StoreContext::current()->id()]);
        $watermark = $stmt->fetchColumn();
        // Until its first run on the database, an older install kept the watermark in the store's cache.
        return $watermark !== false ? (string)$watermark : Cache::get('last_erp_sync');
    }

    private static function saveWatermark(string $watermark): void {
        $db = Db::get();
        $params = [':watermark' => $watermark, ':store' => StoreContext::current()->id()];
        $update = $db->prepare('UPDATE erp_sync_state SET watermark = :watermark, updated_at = ' . Db::now() . ' WHERE store_id = :store');
        $update->execute($params);
        if ($update->rowCount() === 0) {
            $db->prepare(Db::insertIgnore() . ' INTO erp_sync_state (store_id, watermark, updated_at) VALUES (:store, :watermark, ' . Db::now() . ')')->execute($params);
        }
    }
}
