<?php
/**
 * BareBones Shop — ShippingService
 * Orchestrates multi-carrier rate aggregation, label generation, and tracking webhooks.
 *
 * Pricing algorithm:
 *  1. Query shipping_rates DB for carrier+country+weight match (tiered)
 *  2. Apply free_shipping threshold per adapter
 *  3. If no DB rate → adapter hardcoded fallback
 *  4. If adapter returns nothing → legacy Shipping::calculate() JSON fallback
 *
 * Example: 2 kg parcel to SK, subtotal 45 EUR (< 50 EUR free threshold)
 *   Packeta DB lookup → match weight tier 0-2000g SK → 299 cents = 2.99 EUR
 *   + COD surcharge 100 cents = 3.99 EUR total if COD
 */
class ShippingService
{
    /** @var ShippingAdapter[] */
    private static array $adapters = [];

    /** Override adapters for testing */
    public static function setAdapters(array $adapters): void
    {
        self::$adapters = $adapters;
    }

    /**
     * Aggregate shipping rates from all registered carrier adapters.
     * Falls back to legacy Shipping::calculate() for methods not covered by adapters.
     *
     * @param string $country   ISO country code
     * @param int    $weightG   Total weight in grams
     * @param int    $subtotal  Cart subtotal in cents
     * @param bool   $cod       Customer wants COD
     * @return array[] List of all available rate options (from all carriers)
     */
    public static function calculateRates(
        string $country,
        int    $weightG,
        int    $subtotal = 0,
        bool   $cod = false
    ): array {
        $adapters = self::resolveAdapters();
        $params   = compact('country', 'weightG', 'subtotal', 'cod');
        $params['weight_g'] = $weightG;

        $rates = [];
        $coveredCarriers = [];

        foreach ($adapters as $adapter) {
            $adapterRates = $adapter->getRates($params);
            if (!empty($adapterRates)) {
                $rates = array_merge($rates, $adapterRates);
                $coveredCarriers[] = $adapter->carrierId();
            }
        }

        // Legacy fallback: add JSON-based methods for carriers not yet adapted
        $legacyMethods = ['standard', 'express'];
        foreach ($legacyMethods as $method) {
            // Skip if already covered by an adapter
            if (in_array($method, $coveredCarriers, true)) continue;

            $cost = Shipping::calculate($method, $country, $weightG, $subtotal);
            if ($cost >= 0) { // -1 means "not available for zone"
                $cfg = self::legacyMethodConfig($method);
                $rates[] = [
                    'carrier'       => $method,
                    'label'         => $cfg['label'] ?? ucfirst($method),
                    'price'         => $cost,
                    'cod_surcharge' => 0,
                    'delivery_days' => $cfg['delivery_days'] ?? '',
                    'cod'           => false,
                ];
            }
        }

        return $rates;
    }

    /**
     * Generate a shipping label for an order and store it in shipping_labels.
     *
     * @param int    $orderId
     * @param string $carrierId  e.g. 'packeta', 'gls', 'pickup'
     * @return array {awb, label_data, carrier}
     * @throws \RuntimeException if carrier not found or API error
     */
    public static function generateLabel(int $orderId, string $carrierId): array
    {
        $adapter = self::findAdapter($carrierId);
        $order   = self::fetchOrder($orderId);

        $result = $adapter->generateLabel($order);

        // Persist label record
        $db = Db::get();
        $db->prepare("
            INSERT INTO shipping_labels (order_id, carrier, awb, status, label_data)
            VALUES (:order_id, :carrier, :awb, 'created', :label_data)
        ")->execute([
            ':order_id'   => $orderId,
            ':carrier'    => $carrierId,
            ':awb'        => $result['awb'],
            ':label_data' => $result['label_data'] ?? '',
        ]);

        // Update order with tracking info
        $db->prepare("
            UPDATE orders SET tracking_number = :awb, carrier = :carrier, updated_at = datetime('now')
            WHERE id = :id
        ")->execute([':awb' => $result['awb'], ':carrier' => $carrierId, ':id' => $orderId]);

        return $result;
    }

    /**
     * Handle a tracking webhook from a carrier.
     * If status = 'delivered' → transitions order to 'delivered' via OrderWorkflow.
     *
     * @param string $carrierId
     * @param array  $payload   Parsed webhook body
     * @param string $signature Raw signature header for verification
     * @return array {handled, awb, status, order_id}
     */
    public static function handleTrackingWebhook(
        string $carrierId,
        array  $payload,
        string $signature = ''
    ): array {
        // Verify signature if secret configured
        $secret = $_ENV['SHIP_' . strtoupper($carrierId) . '_WEBHOOK_SECRET'] ?? '';
        if ($secret !== '') {
            $computed = hash_hmac('sha256', json_encode($payload), $secret);
            if (!hash_equals($computed, $signature)) {
                throw new \RuntimeException("Invalid webhook signature for carrier: {$carrierId}");
            }
        }

        $awb    = $payload['awb'] ?? $payload['tracking_number'] ?? '';
        $status = $payload['status'] ?? '';

        if (!$awb) {
            return ['handled' => false, 'reason' => 'missing_awb'];
        }

        // Resolve label record
        $db    = Db::get();
        $label = $db->prepare("SELECT * FROM shipping_labels WHERE awb = :awb AND carrier = :carrier LIMIT 1");
        $label->execute([':awb' => $awb, ':carrier' => $carrierId]);
        $labelRow = $label->fetch();

        if (!$labelRow) {
            return ['handled' => false, 'reason' => 'unknown_awb'];
        }

        // Update label status
        $db->prepare("UPDATE shipping_labels SET status = :status, updated_at = datetime('now') WHERE awb = :awb")
           ->execute([':status' => $status, ':awb' => $awb]);

        $orderId = (int)$labelRow['order_id'];
        $result  = ['handled' => true, 'awb' => $awb, 'status' => $status, 'order_id' => $orderId];

        // Auto-transition order if delivered
        if ($status === 'delivered') {
            try {
                $jobs = OrderWorkflow::transition($orderId, 'delivered');
                JobQueue::dispatch($jobs);
                $result['transitioned'] = true;
            } catch (\Throwable $e) {
                $result['transition_error'] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Get tracking info for an AWB from the carrier adapter.
     */
    public static function trackShipment(string $carrierId, string $awb): array
    {
        return self::findAdapter($carrierId)->trackShipment($awb);
    }

    // ─── Private helpers ────────────────────────────────────────────────────────

    /** @return ShippingAdapter[] */
    private static function resolveAdapters(): array
    {
        if (!empty(self::$adapters)) {
            return self::$adapters;
        }

        // Default adapters — auto-registered
        $classes = [
            'PersonalPickup',
            'PacketaShipping',
            'GlsShipping',
        ];

        $adapters = [];
        foreach ($classes as $class) {
            if (class_exists($class)) {
                $adapters[] = new $class();
            }
        }

        return $adapters;
    }

    private static function findAdapter(string $carrierId): ShippingAdapter
    {
        foreach (self::resolveAdapters() as $adapter) {
            if ($adapter->carrierId() === $carrierId) {
                return $adapter;
            }
        }
        throw new \RuntimeException("Unknown shipping carrier: {$carrierId}");
    }

    private static function fetchOrder(int $orderId): array
    {
        $db   = Db::get();
        $stmt = $db->prepare("SELECT * FROM orders WHERE id = :id");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$order) throw new \RuntimeException("Order not found: {$orderId}");
        return $order;
    }

    private static function legacyMethodConfig(string $method): array
    {
        return match($method) {
            'standard' => ['label' => 'Standard Delivery', 'delivery_days' => '5-7'],
            'express'  => ['label' => 'Express Delivery',  'delivery_days' => '1-2'],
            default    => ['label' => ucfirst($method),    'delivery_days' => ''],
        };
    }
}
