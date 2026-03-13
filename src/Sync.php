<?php
/**
 * BareBones Shop — ERP Sync Orchestration
 * Delta sync: products, stock, orders.
 * Usage: php cli/sync.php (cron: */15 * * * *)
 */
class Sync {
    /**
     * Run full sync cycle
     */
    public static function run(): array {
        $cfg = require __DIR__ . '/../config.php';
        $adapterClass = $cfg['adapters']['integration'] ?? null;

        if (!$adapterClass) {
            return ['skipped' => true, 'reason' => 'No integration adapter configured'];
        }

        $adapterFile = __DIR__ . '/../adapters/' . $adapterClass . '.php';
        if (!file_exists($adapterFile)) {
            return ['error' => "Adapter file not found: {$adapterClass}"];
        }

        require_once $adapterFile;
        if (!class_exists($adapterClass)) {
            return ['error' => "Adapter class not found: {$adapterClass}"];
        }

        $adapter = new $adapterClass($cfg);
        if (!$adapter->healthCheck()) {
            return ['error' => 'ERP health check failed'];
        }

        $result = [
            'products_updated' => 0,
            'stock_updated'    => 0,
            'orders_pushed'    => 0,
            'orders_failed'    => 0,
            'statuses_updated' => 0,
        ];

        // 1. Pull products (delta sync since last run)
        $lastSync = Cache::get('last_erp_sync');
        $since = $lastSync ? new \DateTime($lastSync) : null;

        foreach ($adapter->pullProducts($since) as $productData) {
            Import::upsertProduct($productData);
            $result['products_updated']++;
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
                $status = $adapter->pullOrderStatus($order['external_id']);
                if ($status !== ($order['erp_status'] ?? '')) {
                    Orders::updateErpStatus((int)$order['id'], $status);
                    $result['statuses_updated']++;
                }
            } catch (\Throwable $e) {
                error_log("ERP status pull failed [{$order['id']}]: {$e->getMessage()}");
            }
        }

        Cache::set('last_erp_sync', date('c'));

        // 5. Invalidate cache for changed products
        if ($result['products_updated'] > 0 || $result['stock_updated'] > 0) {
            JobQueue::push('cache_rebuild', ['scope' => 'full'], 8);
        }

        return $result;
    }
}
