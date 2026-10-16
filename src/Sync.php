<?php
/**
 * BareBones Shop — ERP Sync Orchestration
 * Delta sync: products, stock, orders.
 * Usage: php cli/sync.php (cron: every 15 minutes)
 */
class Sync {
    /**
     * Run full sync cycle
     */
    public static function run(): array {
        $adapter = Adapters::integration();
        if (!$adapter) {
            return ['skipped' => true, 'reason' => 'No integration adapter configured'];
        }

        if (!$adapter->healthCheck()) {
            return ['error' => 'ERP health check failed'];
        }

        $cfg = require __DIR__ . '/../config.php';

        $result = [
            'products_updated' => 0,
            'stock_updated'    => 0,
            'orders_pushed'    => 0,
            'orders_failed'    => 0,
            'statuses_updated' => 0,
            'products_skipped' => 0,
        ];

        // 1. Pull products (delta sync since last run)
        $lastSync = Cache::get('last_erp_sync');
        $since = $lastSync ? new \DateTime($lastSync) : null;

        foreach ($adapter->pullProducts($since) as $productData) {
            // One bad product (e.g. a SKU owned by another catalog) must not abort stock, order push and status pull.
            try {
                Import::upsertProduct($productData);
                $result['products_updated']++;
            } catch (\Throwable $e) {
                $result['products_skipped']++;
                error_log('ERP product sync skipped [' . (string)($productData['sku'] ?? '?') . "]: {$e->getMessage()}");
            }
        }

        // 2. Pull stock levels
        $stockData = $adapter->pullStock();
        if (!empty($stockData)) {
            $result['stock_updated'] = Products::bulkUpdateStock($stockData);
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
                error_log("ERP order push failed [{$order['id']}]: {$e->getMessage()}");
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
                error_log("ERP status pull failed [{$order['id']}]: {$e->getMessage()}");
            }
        }

        // A skipped product would never be pulled again past a newer watermark: keep the previous one so the next run retries it.
        // Until it is fixed every run re-pulls the delta since then, so the operator must see it.
        if ($result['products_skipped'] === 0) {
            Cache::set('last_erp_sync', date('c'));
        } else {
            IncidentMonitor::report('erp.products_skipped', 'erp', 'warning', ['count' => $result['products_skipped']]);
        }

        // 5. Invalidate cache for changed products
        if ($result['products_updated'] > 0 || $result['stock_updated'] > 0) {
            JobQueue::push('cache_rebuild', ['scope' => 'full'], 8);
        }

        return $result;
    }
}
