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

    /** Country names in their own language, so the list reads the same in every storefront locale. */
    private const COUNTRY_NAMES = [
        'AT' => 'Österreich', 'BE' => 'België / Belgique', 'BG' => 'България', 'CH' => 'Schweiz / Suisse', 'CY' => 'Κύπρος',
        'CZ' => 'Česko', 'DE' => 'Deutschland', 'DK' => 'Danmark', 'EE' => 'Eesti', 'ES' => 'España', 'FI' => 'Suomi',
        'FR' => 'France', 'GB' => 'United Kingdom', 'GR' => 'Ελλάδα', 'HR' => 'Hrvatska', 'HU' => 'Magyarország',
        'IE' => 'Ireland', 'IS' => 'Ísland', 'IT' => 'Italia', 'LI' => 'Liechtenstein', 'LT' => 'Lietuva',
        'LU' => 'Luxembourg', 'LV' => 'Latvija', 'MT' => 'Malta', 'NL' => 'Nederland', 'NO' => 'Norge', 'PL' => 'Polska',
        'PT' => 'Portugal', 'RO' => 'România', 'SE' => 'Sverige', 'SI' => 'Slovenija', 'SK' => 'Slovensko',
        'UA' => 'Україна', 'US' => 'United States',
    ];

    /**
     * Countries the checkout offers: the store's database shipping zones plus the shipping.json
     * zones, as code => name sorted by code. Wildcard zones name no country; with nothing
     * configured the historical default list is kept.
     * @return array<string,string>
     */
    public static function destinationCountries(): array
    {
        $codes = Shipping::zoneCountries();
        if (self::hasDatabasePricing()) {
            $stmt = Db::get()->prepare("SELECT DISTINCT country FROM shipping_zone_countries WHERE store_id = :store");
            $stmt->execute([':store' => StoreContext::current()->providerId()]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $code) {
                if (preg_match('/^[A-Za-z]{2}$/', (string)$code)) $codes[] = strtoupper((string)$code);
            }
        }
        $codes = array_unique($codes) ?: ['SK', 'CZ', 'DE', 'AT', 'HU', 'PL'];
        sort($codes);
        $countries = [];
        foreach ($codes as $code) $countries[$code] = self::COUNTRY_NAMES[$code] ?? $code;
        return $countries;
    }

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
        bool   $cod = false,
        int    $volumeCm3 = 0,
        int    $physicalQty = 0
    ): array {
        $country = strtoupper(trim($country ?: 'SK'));
        $weightG = max(0, $weightG);
        $subtotal = max(0, $subtotal);
        $volumeCm3 = max(0, $volumeCm3);
        $physicalQty = max(0, $physicalQty);

        if (self::hasDatabasePricing() && StoreContext::current()->usesProviderPrices()) {
            $dbRates = self::databaseRates($country, $weightG, $subtotal, $cod, $volumeCm3);
            if (!empty($dbRates)) {
                return self::applyFreeShippingRules($dbRates, $subtotal, $physicalQty);
            }
        }

        $adapters = self::resolveAdapters();
        // Amounts (subtotal, free-shipping thresholds, returned prices) are minor units of the store currency.
        $currency = strtoupper(StoreContext::current()->currency());
        $params   = compact('country', 'weightG', 'subtotal', 'cod', 'currency');
        $params['weight_g'] = $weightG;
        $params['volume_cm3'] = $volumeCm3;

        $rates = [];
        $coveredCarriers = [];

        foreach ($adapters as $providerCode => $adapter) {
            if (is_int($providerCode)) $providerCode = $adapter->carrierId();
            try {
                $adapterRates = $adapter->getRates($params);
            } catch (\Throwable $e) {
                // One carrier outage (HTTP 5xx, timeout, bad config) must not take checkout down for the others.
                Logger::warning('shipping.rates_adapter_failed', ['carrier' => (string)$providerCode, 'exception_type' => get_class($e)]);
                IncidentMonitor::report('shipping.rates_adapter_failed', 'shipping', 'warning', ['exception' => $e]);
                continue;
            }
            if (!empty($adapterRates)) {
                foreach ($adapterRates as $rate) {
                    $rate['provider'] = (string)$providerCode;
                    $rate['method_code'] = $rate['method_code'] ?? $rate['carrier'] ?? $adapter->carrierId();
                    $rate['key'] = $rate['key'] ?? $rate['method_code'];
                    $rates[] = $rate;
                }
                $coveredCarriers[] = $adapter->carrierId();
            }
        }

        // Legacy fallback: add JSON-based methods for carriers not yet adapted. shipping.json prices
        // are in its own "currency" (EUR when absent) and are never quoted to a store in another one.
        $legacyMethods = Shipping::currency() === $currency ? ['standard', 'express'] : [];
        foreach ($legacyMethods as $method) {
            // Skip if already covered by an adapter
            if (in_array($method, $coveredCarriers, true)) continue;

            $cost = Shipping::calculate($method, $country, $weightG, $subtotal);
            if ($cost >= 0) { // -1 means "not available for zone"
                $cfg = self::legacyMethodConfig($method);
                $jsonCfg = Shipping::methodConfig($method) ?? [];
                $rates[] = [
                    'key'           => $method,
                    'method_code'   => $method,
                    'carrier'       => $method,
                    'provider'      => 'legacy',
                    'label'         => $cfg['label'] ?? ucfirst($method),
                    'price'         => $cost,
                    'cod_surcharge' => 0,
                    'delivery_days' => $cfg['delivery_days'] ?? '',
                    'cod'           => false,
                    'free_above'    => isset($jsonCfg['free_above']) ? (int)$jsonCfg['free_above'] : null,
                    'free_from_qty' => isset($jsonCfg['free_from_qty']) ? (int)$jsonCfg['free_from_qty'] : null,
                ];
            }
        }

        return self::applyFreeShippingRules($rates, $subtotal, $physicalQty);
    }

    public static function selectRate(
        string $methodCode,
        string $country,
        int $weightG,
        int $subtotal = 0,
        bool $cod = false,
        int $volumeCm3 = 0,
        int $physicalQty = 0
    ): ?array {
        foreach (self::calculateRates($country, $weightG, $subtotal, $cod, $volumeCm3, $physicalQty) as $rate) {
            if (($rate['method_code'] ?? $rate['key'] ?? '') === $methodCode) {
                return $rate;
            }
        }
        return null;
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
        StoreContext::current()->assertPermission('shipping');
        $db = Db::get();
        $existing = $db->prepare("SELECT awb, label_data, carrier FROM shipping_labels WHERE order_id = :order_id AND carrier = :carrier AND store_id = :store AND status != 'cancelled' ORDER BY id DESC LIMIT 1");
        $existing->execute([':order_id' => $orderId, ':carrier' => $carrierId, ':store' => StoreContext::current()->id()]);
        $label = $existing->fetch(\PDO::FETCH_ASSOC);
        if ($label) return $label;

        $adapter = self::findAdapter($carrierId);
        $order   = self::fetchOrder($orderId);
        $snapshot = json_decode((string)($order['shipping_rate_snapshot'] ?? ''), true);
        $order['pickup_point_id'] = (string)($snapshot['pickup_point']['id'] ?? '');
        // What the carrier collects: the order total of an unpaid cash-on-delivery order.
        $order['cod_amount'] = ($order['payment_status'] ?? '') !== 'paid' && PaymentService::isCashOnDelivery((string)($order['payment_method'] ?? ''))
            ? (int)$order['total'] : 0;
        // A carrier that cannot collect would deliver it as prepaid (in-store pickup is paid at the counter).
        if ($order['cod_amount'] > 0 && $adapter instanceof ShippingLifecycleAdapter && !$adapter->supportsCOD()) {
            throw new \RuntimeException("Shipping carrier does not support cash on delivery: {$carrierId}");
        }
        // Labels cancelled earlier: a new label is a new carrier request, not a replay of the cancelled one.
        $cancelled = $db->prepare("SELECT COUNT(*) FROM shipping_labels WHERE order_id = :order_id AND carrier = :carrier AND store_id = :store");
        $cancelled->execute([':order_id' => $orderId, ':carrier' => $carrierId, ':store' => StoreContext::current()->id()]);
        $order['label_sequence'] = (int)$cancelled->fetchColumn() + 1;
        $result = $adapter->generateLabel($order);

        // Persist label record
        $db->prepare("
            INSERT INTO shipping_labels (order_id, store_id, carrier, awb, status, label_data)
            VALUES (:order_id, :store, :carrier, :awb, 'created', :label_data)
        ")->execute([
            ':order_id'   => $orderId,
            ':store'      => StoreContext::current()->id(),
            ':carrier'    => $carrierId,
            ':awb'        => $result['awb'],
            ':label_data' => $result['label_data'] ?? '',
        ]);

        // Update order with tracking info
        $db->prepare("
            UPDATE orders SET tracking_number = :awb, carrier = :carrier, updated_at = " . Db::now() . "
            WHERE id = :id AND store_id = :store
        ")->execute([':awb' => $result['awb'], ':carrier' => $carrierId, ':id' => $orderId, ':store' => StoreContext::current()->id()]);

        return $result;
    }

    /** Retrieve carrier pickup points through the optional full lifecycle contract. */
    public static function pickupPoints(string $carrierId, string $country, string $postalCode = ''): array
    {
        StoreContext::current()->assertPermission('shipping');
        $adapter = self::findAdapter($carrierId);
        if (!$adapter instanceof ShippingLifecycleAdapter) {
            throw new \RuntimeException("Shipping carrier does not support pickup points: {$carrierId}");
        }
        return $adapter->pickupPoints(strtoupper(trim($country)), trim($postalCode));
    }

    /**
     * Pick-up points the customer can choose for a selected rate, from its carrier adapter.
     * required: the rate only delivers to a point (service_type pickup or pickup_required);
     * a parcel rate that supports pick-up may also go to the address. Carriers without the
     * lifecycle contract (in-store pickup) offer no list and need no choice.
     * @return array{points: list<array{id:string,name:string,address:string}>, required: bool}
     */
    public static function pickupOptions(array $rate, string $country, string $postalCode): array
    {
        $adapter = self::pickupAdapter($rate);
        if ($adapter === null) return ['points' => [], 'required' => false];
        $required = ($rate['service_type'] ?? '') === 'pickup' || !empty($rate['pickup_required']);
        try {
            $points = $adapter->pickupPoints(strtoupper(trim($country)), trim($postalCode));
        } catch (\Throwable $e) {
            error_log('Pick-up points unavailable for ' . $adapter->carrierId() . ': ' . $e->getMessage());
            $points = [];
        }
        return ['points' => array_values(array_filter($points, static fn(array $p): bool => ($p['id'] ?? '') !== '')), 'required' => $required];
    }

    /** One point of the carrier's whole list, for adapters that can look a point up by id (optional pickupPoint()). */
    public static function pickupPointById(array $rate, string $country, string $pointId): ?array
    {
        $adapter = self::pickupAdapter($rate);
        if ($adapter === null || !method_exists($adapter, 'pickupPoint')) return null;
        try {
            $point = $adapter->pickupPoint(strtoupper(trim($country)), $pointId);
        } catch (\Throwable $e) {
            error_log('Pick-up point lookup failed for ' . $adapter->carrierId() . ': ' . $e->getMessage());
            return null;
        }
        return is_array($point) && ($point['id'] ?? '') === $pointId ? $point : null;
    }

    /** Carrier adapter that lists pick-up points for this rate, or null when the rate has no point choice. */
    public static function pickupAdapter(array $rate): ?ShippingLifecycleAdapter
    {
        if (($rate['service_type'] ?? '') !== 'pickup' && empty($rate['pickup_required']) && empty($rate['supports_pickup'])) return null;
        foreach (self::resolveAdapters() as $adapter) {
            if ($adapter->carrierId() === (string)($rate['carrier'] ?? '')) return $adapter instanceof ShippingLifecycleAdapter ? $adapter : null;
        }
        return null;
    }

    /**
     * Validate the posted pick-up point against the carrier's own list for this address.
     * @return array{point: ?array{id:string,name:string,address:string}, error: ?string} error is an i18n key
     */
    public static function choosePickupPoint(array $rate, string $country, string $postalCode, string $pointId): array
    {
        $options = self::pickupOptions($rate, $country, $postalCode);
        $pointId = trim($pointId);
        foreach ($options['points'] as $point) {
            if ($pointId !== '' && $point['id'] === $pointId) return ['point' => $point, 'error' => null];
        }
        // The list holds only the nearest points; a point the shopper found by searching is valid too.
        $found = $pointId !== '' ? self::pickupPointById($rate, $country, $pointId) : null;
        if ($found !== null) return ['point' => $found, 'error' => null];
        if ($pointId !== '' && ($options['points'] || $options['required'])) return ['point' => null, 'error' => 'pickup_point_invalid'];
        if ($options['required']) return ['point' => null, 'error' => $options['points'] ? 'pickup_point_required' : 'pickup_point_unavailable'];
        return ['point' => null, 'error' => null];
    }

    /** Cancel the latest active label exactly once and retain its audit row. */
    public static function cancelLabel(int $orderId, string $carrierId): array
    {
        StoreContext::current()->assertPermission('shipping');
        $db = Db::get();
        $stmt = $db->prepare("SELECT * FROM shipping_labels WHERE order_id = :order_id AND carrier = :carrier AND store_id = :store ORDER BY id DESC LIMIT 1");
        $stmt->execute([':order_id' => $orderId, ':carrier' => $carrierId, ':store' => StoreContext::current()->id()]);
        $label = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$label) throw new \RuntimeException("Shipping label not found for order: {$orderId}");
        if (($label['status'] ?? '') === 'cancelled') {
            return ['awb' => (string)$label['awb'], 'status' => 'cancelled', 'idempotent_replay' => true];
        }
        $adapter = self::findAdapter($carrierId);
        if (!$adapter instanceof ShippingLifecycleAdapter) {
            throw new \RuntimeException("Shipping carrier does not support label cancellation: {$carrierId}");
        }
        $result = $adapter->cancelLabel((string)$label['awb']);
        $db->prepare("UPDATE shipping_labels SET status = 'cancelled', updated_at = " . Db::now() . " WHERE id = :id AND status != 'cancelled'")
            ->execute([':id' => $label['id']]);
        return $result + ['awb' => (string)$label['awb'], 'status' => 'cancelled', 'idempotent_replay' => false];
    }

    /**
     * Tracking statuses a carrier webhook may set, by progress. A label never moves to a lower rank
     * (except failed → in_transit, see TRACKING_REOPEN), so a replayed or late "in_transit" cannot undo "delivered". Equal ranks may alternate
     * (a failed attempt is followed by another out_for_delivery). "cancelled" is set only by cancelLabel().
     */
    public const TRACKING_STATUS_RANK = [
        'created' => 0,
        'in_transit' => 1,
        'out_for_delivery' => 2,
        'ready_for_pickup' => 2,
        'failed' => 2,
        'delivered' => 3,
    ];

    /** Lower-rank moves that are real progress: after a failed attempt the parcel goes back to the depot and is re-sent. */
    private const TRACKING_REOPEN = ['in_transit' => ['failed']];

    /** Local brand names of a carrier that the admin may type as a shipment's carrier (carrier key => names). */
    private const CARRIER_BRANDS = ['packeta' => ['Zásielkovňa', 'Zásilkovna']];

    /** Statuses a parcel may move to $status from: equal or lower rank, plus the reopening moves above. */
    private static function trackingSources(string $status): array
    {
        $rank = self::TRACKING_STATUS_RANK[$status];
        return array_merge(array_keys(array_filter(self::TRACKING_STATUS_RANK, static fn(int $r): bool => $r <= $rank)),
            self::TRACKING_REOPEN[$status] ?? []);
    }

    /** Seconds a signed X-Shipping-Timestamp may differ from the shop clock. */
    public const WEBHOOK_TIMESTAMP_TOLERANCE = 300;

    /** Environment variable holding a carrier's webhook secret: SHIP_<CARRIER>_WEBHOOK_SECRET, "-" written as "_". */
    public static function webhookSecretEnvName(string $carrierId): string
    {
        return 'SHIP_' . strtoupper(str_replace('-', '_', $carrierId)) . '_WEBHOOK_SECRET';
    }

    /**
     * Handle a tracking webhook from a carrier.
     * If status = 'delivered' → transitions order to 'delivered' via OrderWorkflow.
     *
     * Signature: hex HMAC-SHA256 of the raw body with the carrier secret. A carrier that also sends
     * a Unix timestamp (X-Shipping-Timestamp) signs "<timestamp>.<raw body>" instead, and the
     * timestamp must be within WEBHOOK_TIMESTAMP_TOLERANCE seconds; config
     * shipping_webhook_require_timestamp makes it mandatory. Body-only signatures stay accepted
     * for carriers that cannot send one; replays of them are absorbed by the event idempotency
     * claim and by the status order above.
     *
     * @param string $carrierId
     * @param array  $payload    Parsed webhook body
     * @param string $signature  Raw signature header for verification
     * @param string $rawPayload Exact request body covered by the signature
     * @param string $timestamp  Optional signed timestamp header
     * @return array {handled, awb, status, order_id}
     * @throws ShippingWebhookConfigurationError when the shop side is misconfigured (not the sender's fault)
     */
    public static function handleTrackingWebhook(
        string $carrierId,
        array  $payload,
        string $signature = '',
        string $rawPayload = '',
        string $timestamp = '',
        ?int $now = null
    ): array {
        StoreContext::current()->assertPermission('shipping');
        $envSecret = getenv(self::webhookSecretEnvName($carrierId));
        $secret = is_string($envSecret) ? $envSecret : '';
        $cfg = require __DIR__ . '/../config.php';
        if ($secret === '') {
            $secret = (string)($cfg['shipping_webhook_secrets'][$carrierId] ?? '');
        }
        if (strlen($secret) < 16 || trim($secret) !== $secret) {
            throw new ShippingWebhookConfigurationError("Webhook signature secret is not configured securely for carrier: {$carrierId}");
        }
        if ($rawPayload === '' || $signature === '') {
            throw new \RuntimeException("Invalid webhook signature for carrier: {$carrierId}");
        }
        if ($timestamp !== '') {
            $now ??= time();
            if (!preg_match('/^\d{1,12}$/', $timestamp) || abs($now - (int)$timestamp) > self::WEBHOOK_TIMESTAMP_TOLERANCE) {
                throw new \RuntimeException("Invalid webhook signature timestamp for carrier: {$carrierId}");
            }
            $computed = hash_hmac('sha256', $timestamp . '.' . $rawPayload, $secret);
        } elseif (!empty($cfg['shipping_webhook_require_timestamp'])) {
            throw new \RuntimeException("Invalid webhook signature (timestamp required) for carrier: {$carrierId}");
        } else {
            $computed = hash_hmac('sha256', $rawPayload, $secret);
        }
        if (!hash_equals($computed, strtolower($signature))) {
            throw new \RuntimeException("Invalid webhook signature for carrier: {$carrierId}");
        }

        $awb    = $payload['awb'] ?? $payload['tracking_number'] ?? '';
        $status = strtolower(trim((string)($payload['status'] ?? '')));

        if (!$awb) {
            return ['handled' => false, 'reason' => 'missing_awb'];
        }
        if (!array_key_exists($status, self::TRACKING_STATUS_RANK)) {
            return ['handled' => false, 'reason' => 'unknown_status'];
        }

        // Resolve label record
        // The label may belong to any store sharing the endpoint's provider scope; process it in that store.
        $stores = PaymentWebhookRouting::eligibleStoreIds();
        $label = Db::get()->prepare("SELECT * FROM shipping_labels WHERE awb = ? AND carrier = ? AND store_id IN (" . implode(',', array_fill(0, count($stores), '?')) . ") LIMIT 2");
        $label->execute([$awb, $carrierId, ...$stores]);
        $labelRows = $label->fetchAll(\PDO::FETCH_ASSOC);
        if (count($labelRows) === 1) {
            return PaymentWebhookRouting::within((string)$labelRows[0]['store_id'], static fn(): array => self::applyTrackingUpdate($labelRows[0], $carrierId, (string)$awb, $status, $rawPayload));
        }
        // Parcels shipped in the admin (Fulfillment) carry the carrier and tracking number on the shipment.
        $shipmentRows = $labelRows ? [] : self::shippedShipments($carrierId, (string)$awb, $stores);
        if (count($shipmentRows) === 1) {
            return PaymentWebhookRouting::within((string)$shipmentRows[0]['store_id'], static fn(): array => self::applyShipmentTracking($shipmentRows[0], $carrierId, (string)$awb, $status, $rawPayload));
        }
        if ($labelRows || $shipmentRows) error_log("shipping webhook [{$carrierId}] AWB {$awb} is ambiguous across stores");
        return ['handled' => false, 'reason' => 'unknown_awb'];
    }

    /**
     * Shipped admin shipments of $carrierId with tracking number $awb in $stores. The admin types both by
     * hand: the tracking number matches without spaces/dashes and case, the carrier by its letters and
     * digits ("Packeta", "Packeta SK" = packeta, "HTTP Carrier" = http-carrier). An empty carrier matches through
     * the carrier of the order's shipping method, and so does a brand name of that carrier ("Zásielkovňa", or a
     * name that the shipping method's own name contains). Any other carrier ("GLS", "DPD") never matches, so
     * carrier A cannot deliver carrier B's parcel.
     * @param list<string> $stores
     * @return list<array<string,mixed>>
     */
    private static function shippedShipments(string $carrierId, string $awb, array $stores): array
    {
        $key = static fn(string $value): string => str_replace('-', '', Str::slug($value));
        $wanted = $key($carrierId);
        $others = array_values(array_filter(array_map($key, array_map('strval', array_keys(Adapters::registry('shipping')))),
            static fn(string $code): bool => $code !== '' && $code !== $wanted));
        $brands = array_map($key, self::CARRIER_BRANDS[$wanted] ?? []);
        $matches = static function (array $row) use ($key, $wanted, $others, $brands): bool {
            $carrier = $key((string)$row['carrier']);
            if ($wanted !== '' && str_contains($carrier, $wanted)) return true;
            foreach ($others as $other) {
                if (str_contains($carrier, $other)) return false;
            }
            if ($key((string)$row['method_carrier']) !== $wanted) return false;
            if ($carrier === '') return true;
            foreach ($brands as $brand) {
                if (str_contains($carrier, $brand)) return true;
            }
            return strlen($carrier) >= 3 && str_contains($key((string)$row['method_name']), $carrier);
        };
        $stmt = Db::get()->prepare("SELECT s.id, s.store_id, s.order_id, s.carrier, m.carrier AS method_carrier, m.name AS method_name FROM shipments s
            LEFT JOIN orders o ON o.id = s.order_id AND o.store_id = s.store_id
            LEFT JOIN shipping_methods m ON m.code = o.shipping_method AND m.store_id = ?
            WHERE s.status = 'shipped' AND s.tracking_number IS NOT NULL
              AND LOWER(REPLACE(REPLACE(s.tracking_number, ' ', ''), '-', '')) = ?
              AND s.store_id IN (" . implode(',', array_fill(0, count($stores), '?')) . ") LIMIT 20");
        $stmt->execute([StoreContext::current()->providerId(), strtolower(str_replace([' ', '-'], '', $awb)), ...$stores]);
        return array_values(array_filter($stmt->fetchAll(\PDO::FETCH_ASSOC), $matches));
    }

    /**
     * Forward-only carrier status on an admin shipment; the order becomes delivered once every parcel
     * of a shipped order is delivered (a parcel still on the way or goods not yet shipped keep it shipped).
     */
    private static function applyShipmentTracking(array $shipment, string $carrierId, string $awb, string $status, string $rawPayload): array
    {
        StoreContext::current()->assertPermission('shipping');
        $db = Db::get();
        $eventId = 'shipping_' . hash('sha256', $carrierId . "\0" . $rawPayload);
        $claim = Idempotency::claim($eventId);
        if ($claim === 'in_flight') return ['handled' => false, 'reason' => 'in_flight', 'retry' => true];
        if ($claim === 'done') return ['handled' => false, 'reason' => 'already_processed', 'duplicate' => true];

        $from = self::trackingSources($status);
        $known = array_keys(self::TRACKING_STATUS_RANK);
        $orderId = (int)$shipment['order_id'];
        try {
            $update = $db->prepare("UPDATE shipments SET tracking_status = ?, updated_at = " . Db::now() . ",
                    delivered_at = CASE WHEN ? = 'delivered' THEN COALESCE(delivered_at, " . Db::now() . ") ELSE delivered_at END
                WHERE id = ? AND store_id = ? AND status = 'shipped'
                  AND (tracking_status IS NULL OR tracking_status IN (" . implode(',', array_fill(0, count($from), '?')) . ")
                       OR tracking_status NOT IN (" . implode(',', array_fill(0, count($known), '?')) . "))");
            $update->execute([$status, $status, (int)$shipment['id'], StoreContext::current()->id(), ...$from, ...$known]);
            // MySQL counts changed rows only: a retried "delivered" within the same second still has to deliver the order.
            $current = $db->prepare('SELECT tracking_status FROM shipments WHERE id = ?');
            $current->execute([(int)$shipment['id']]);
            if ($update->rowCount() === 0 && !($status === 'delivered' && $current->fetchColumn() === 'delivered')) {
                Idempotency::complete($eventId);
                return ['handled' => false, 'reason' => 'stale_status', 'awb' => $awb, 'status' => $status, 'order_id' => $orderId];
            }
            $result = ['handled' => true, 'awb' => $awb, 'status' => $status, 'order_id' => $orderId, 'transitioned' => false];
            if ($status === 'delivered' && self::allParcelsDelivered($orderId)) {
                try {
                    OrderWorkflow::transition($orderId, 'delivered');
                } catch (\Throwable $e) {
                    if ((Orders::byId($orderId)['status'] ?? '') !== 'delivered') throw $e;
                }
                $result['transitioned'] = true;
            }
        } catch (\Throwable $e) {
            Idempotency::unmark($eventId);
            throw $e;
        }
        Idempotency::complete($eventId);
        return $result;
    }

    private static function allParcelsDelivered(int $orderId): bool
    {
        if ((Orders::byId($orderId)['status'] ?? '') !== 'shipped') return false;
        return !self::parcelsOutstanding($orderId);
    }

    /**
     * Whether a parcel of the order is still on the way: an admin shipment not yet shipped or not yet
     * delivered, or a carrier label neither delivered nor cancelled. A shipped shipment without a tracking
     * number (an all-items "shipped" transition) travels under the order's carrier label when it has one.
     */
    private static function parcelsOutstanding(int $orderId): bool
    {
        $store = StoreContext::current()->id();
        $stmt = Db::get()->prepare("SELECT
                (SELECT COUNT(*) FROM shipments s WHERE s.order_id = ? AND s.store_id = ? AND s.status <> 'cancelled'
                    AND NOT (s.status = 'shipped' AND COALESCE(s.tracking_status, '') = 'delivered')
                    AND NOT (s.status = 'shipped' AND COALESCE(s.tracking_number, '') = ''
                        AND EXISTS (SELECT 1 FROM shipping_labels l WHERE l.order_id = s.order_id AND l.store_id = s.store_id AND l.status <> 'cancelled')))
              + (SELECT COUNT(*) FROM shipping_labels WHERE order_id = ? AND store_id = ? AND status NOT IN ('delivered', 'cancelled'))");
        $stmt->execute([$orderId, $store, $orderId, $store]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function applyTrackingUpdate(array $labelRow, string $carrierId, string $awb, string $status, string $rawPayload): array
    {
        StoreContext::current()->assertPermission('shipping');
        $db = Db::get();
        $eventId = 'shipping_' . hash('sha256', $carrierId . "\0" . $rawPayload);
        $claim = Idempotency::claim($eventId);
        if ($claim === 'in_flight') return ['handled' => false, 'reason' => 'in_flight', 'retry' => true];
        if ($claim === 'done') return ['handled' => false, 'reason' => 'already_processed', 'duplicate' => true];

        // Forward-only: the label may move to this status from an equal or lower rank, or from a
        // legacy status outside the table; never from a higher rank (except failed → in_transit, a re-sent
        // parcel) and never out of "cancelled".
        $from = self::trackingSources($status);
        $known = array_merge(array_keys(self::TRACKING_STATUS_RANK), ['cancelled']);
        try {
            $update = $db->prepare("UPDATE shipping_labels SET status = ?, updated_at = " . Db::now() . "
                WHERE awb = ? AND carrier = ? AND store_id = ?
                  AND (status IN (" . implode(',', array_fill(0, count($from), '?')) . ")
                       OR status NOT IN (" . implode(',', array_fill(0, count($known), '?')) . "))");
            $update->execute([$status, $awb, $carrierId, StoreContext::current()->id(), ...$from, ...$known]);
        } catch (\Throwable $e) {
            Idempotency::unmark($eventId);
            throw $e;
        }
        if ($update->rowCount() === 0) {
            Idempotency::complete($eventId);
            return ['handled' => false, 'reason' => 'stale_status', 'awb' => $awb, 'status' => $status, 'order_id' => (int)$labelRow['order_id']];
        }

        $orderId = (int)$labelRow['order_id'];
        $result  = ['handled' => true, 'awb' => $awb, 'status' => $status, 'order_id' => $orderId];
        try {
            // The admin shipment of this same parcel (its tracking number is the label AWB) follows the label.
            $shipmentKnown = array_keys(self::TRACKING_STATUS_RANK);
            $db->prepare("UPDATE shipments SET tracking_status = ?, updated_at = " . Db::now() . ",
                    delivered_at = CASE WHEN ? = 'delivered' THEN COALESCE(delivered_at, " . Db::now() . ") ELSE delivered_at END
                WHERE order_id = ? AND store_id = ? AND status = 'shipped' AND tracking_number IS NOT NULL
                  AND LOWER(REPLACE(REPLACE(tracking_number, ' ', ''), '-', '')) = ?
                  AND (tracking_status IS NULL OR tracking_status IN (" . implode(',', array_fill(0, count($from), '?')) . ")
                       OR tracking_status NOT IN (" . implode(',', array_fill(0, count($shipmentKnown), '?')) . "))")
                ->execute([$status, $status, $orderId, StoreContext::current()->id(), strtolower(str_replace([' ', '-'], '', $awb)), ...$from, ...$shipmentKnown]);
        } catch (\Throwable $e) {
            Idempotency::unmark($eventId);
            throw $e;
        }

        // Auto-transition order once its last parcel is delivered. OrderWorkflow owns its deferred jobs.
        if ($status === 'delivered' && self::parcelsOutstanding($orderId)) {
            $result['transitioned'] = false;
        } elseif ($status === 'delivered') {
            try {
                OrderWorkflow::transition($orderId, 'delivered');
                $result['transitioned'] = true;
            } catch (\Throwable $e) {
                $currentOrder = Orders::byId($orderId);
                if (($currentOrder['status'] ?? '') === 'delivered') {
                    $result['transitioned'] = true;
                } else {
                    Idempotency::unmark($eventId);
                    throw $e;
                }
            }
        }
        Idempotency::complete($eventId);

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

    private static function databaseRates(string $country, int $weightG, int $subtotal, bool $cod, int $volumeCm3): array
    {
        $db = Db::get();
        $storeId = StoreContext::current()->providerId();
        StoreContext::current()->assertPermission('shipping');

        $stmt = $db->prepare("SELECT zone_code FROM shipping_zone_countries WHERE store_id = :store AND country = :country ORDER BY id");
        $stmt->execute([':store' => $storeId, ':country' => $country]);
        $zones = array_values(array_unique(array_filter(array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'zone_code'))));
        if (empty($zones)) {
            return [];
        }

        $zonePlaceholders = implode(',', array_fill(0, count($zones), '?'));
        $params = array_merge([$storeId, $storeId, $storeId, $country, $weightG, $weightG, $volumeCm3], $zones, [$weightG, $volumeCm3]);
        $stmt = $db->prepare("
            SELECT r.*, m.name AS method_name, m.description AS method_description, m.service_type,
                   m.supports_cod, m.supports_pickup, m.free_above, m.free_from_qty, m.max_weight_g, m.max_volume_cm3,
                   m.active AS method_active, m.sort_order AS method_sort, z.name AS zone_name
            FROM shipping_rates r
            JOIN shipping_methods m ON m.code = r.method_code
            JOIN shipping_zones z ON z.code = r.zone_code
            WHERE r.store_id = ? AND m.store_id = ? AND z.store_id = ?
              AND m.active = 1 AND z.active = 1 AND COALESCE(r.active, 1) = 1
              AND (r.country = ? OR r.country = '')
              AND r.weight_from_g <= ? AND r.weight_to_g >= ?
              AND (r.volume_to_cm3 IS NULL OR r.volume_to_cm3 = 0 OR r.volume_to_cm3 >= ?)
              AND r.zone_code IN ({$zonePlaceholders})
              AND (m.max_weight_g IS NULL OR m.max_weight_g = 0 OR m.max_weight_g >= r.weight_to_g OR m.max_weight_g >= ?)
              AND (m.max_volume_cm3 IS NULL OR m.max_volume_cm3 = 0 OR m.max_volume_cm3 >= ?)
            ORDER BY m.sort_order, r.sort_order, r.price
        ");
        $stmt->execute($params);

        $rates = [];
        $seen = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $methodCode = (string)$row['method_code'];
            if (isset($seen[$methodCode])) continue;
            if ($cod && empty($row['supports_cod'])) continue;

            $price = (int)$row['price'];
            $codSurcharge = $cod ? (int)($row['cod_surcharge'] ?? 0) : 0;
            $rates[] = [
                'key'             => $methodCode,
                'method_code'     => $methodCode,
                'carrier'         => $row['carrier'],
                'provider'        => $row['carrier'],
                'zone_code'       => $row['zone_code'],
                'zone_name'       => $row['zone_name'],
                'label'           => MethodLabels::name('shipping', $methodCode, (string)$row['method_name']),
                'description'     => MethodLabels::description('shipping', $methodCode, (string)($row['method_description'] ?? '')),
                'service_type'    => $row['service_type'] ?? 'parcel',
                'supports_cod'    => (bool)$row['supports_cod'],
                'supports_pickup' => (bool)$row['supports_pickup'],
                'price'           => $price + $codSurcharge,
                'base_price'      => $price,
                'cod_surcharge'   => $codSurcharge,
                'delivery_days'   => $row['delivery_days'] ?? '',
                'free_above'      => isset($row['free_above']) ? (int)$row['free_above'] : null,
                'free_from_qty'   => isset($row['free_from_qty']) ? (int)$row['free_from_qty'] : null,
            ];
            $seen[$methodCode] = true;
        }

        return $rates;
    }

    /**
     * Number of units that need physical delivery. Digital items never count,
     * so "free from N items" cannot be reached with e-books alone.
     */
    public static function physicalQuantity(array $items): int
    {
        $qty = 0;
        foreach ($items as $item) {
            if (VariantAvailability::isDigitalType((string)($item['product_type'] ?? ''))) continue;
            $qty += max(0, (int)($item['qty'] ?? 0));
        }
        return $qty;
    }

    /**
     * How many more physical units unlock free shipping on the cheapest-to-reach
     * method, or null when no quantity rule applies (no physical item in the
     * cart, no method with free_from_qty, or shipping is already free by quantity).
     */
    public static function unitsToFreeShipping(array $rates, int $physicalQty): ?int
    {
        if ($physicalQty < 1) return null;
        $missing = null;
        foreach ($rates as $rate) {
            if (($rate['free_reason'] ?? null) === 'quantity') return null;
            $threshold = (int)($rate['free_from_qty'] ?? 0);
            if ($threshold <= $physicalQty || ($rate['free_reason'] ?? null) !== null) continue;
            $missing = $missing === null ? $threshold - $physicalQty : min($missing, $threshold - $physicalQty);
        }
        return $missing;
    }

    /** Localized "add N more items for free shipping" text; empty when no hint applies. */
    public static function freeShippingHint(?int $unitsToFree): string
    {
        return $unitsToFree !== null && $unitsToFree > 0 ? I18n::plural('free_shipping_add', $unitsToFree) : '';
    }

    /**
     * Localized delivery time of a rate's delivery_days ("2" → "Delivery in 2 days", "1-2" → "Delivery in 1–2 days").
     * Empty for an unknown or zero value; free text a carrier sends is shown as it is.
     */
    public static function deliveryLabel(mixed $days): string
    {
        $days = trim(is_scalar($days) ? (string)$days : '');
        if (preg_match('/^\d+$/', $days)) return (int)$days > 0 ? I18n::plural('delivery_days', (int)$days) : '';
        if (preg_match('/^(\d+)\s*[-–]\s*(\d+)$/u', $days, $range) && (int)$range[2] > 0) {
            return I18n::plural('delivery_days', (int)$range[2], (int)$range[1] . '–' . (int)$range[2]);
        }
        return $days;
    }

    /**
     * Shipping summary for cart/checkout pages: offered rates, the requested
     * (or first) rate, its price, and the free-shipping hint for the cart items.
     */
    public static function cartQuote(array $items, int $subtotal, string $country, string $requestedMethod = '', bool $cod = false): array
    {
        $physicalQty = self::physicalQuantity($items);
        $weightG = 0;
        foreach ($items as $item) $weightG += (int)($item['weight_g'] ?? 0) * (int)($item['qty'] ?? 0);
        $rates = self::calculateRates($country, $weightG, $subtotal, $cod, 0, $physicalQty);
        $selected = $rates[0] ?? null;
        foreach ($rates as $rate) {
            if ($requestedMethod !== '' && ($rate['method_code'] ?? '') === $requestedMethod) { $selected = $rate; break; }
        }
        // Cart summaries estimate a delivered parcel, not a free in-store pickup.
        $delivery = $selected;
        foreach ($rates as $rate) {
            if (($rate['service_type'] ?? '') !== 'pickup' && ($rate['carrier'] ?? '') !== 'pickup') { $delivery = $rate; break; }
        }
        return [
            'rates' => $rates,
            'selected' => $selected,
            'price' => $physicalQty > 0 && $selected ? (int)$selected['price'] : 0,
            'delivery_price' => $physicalQty > 0 && $delivery ? (int)$delivery['price'] : 0,
            'free_above' => $delivery && !empty($delivery['free_above']) ? (int)$delivery['free_above'] : null,
            'hint' => self::freeShippingHint(self::unitsToFreeShipping($rates, $physicalQty)),
        ];
    }

    /**
     * Apply per-method free shipping (order amount or physical unit count) and
     * record which rule fired so the order shipping snapshot keeps the reason.
     */
    private static function applyFreeShippingRules(array $rates, int $subtotal, int $physicalQty): array
    {
        $quantityRules = null;
        foreach ($rates as &$rate) {
            if (!array_key_exists('free_from_qty', $rate)) {
                $quantityRules ??= self::methodQuantityRules();
                $rate['free_from_qty'] = $quantityRules[(string)($rate['method_code'] ?? $rate['key'] ?? '')] ?? null;
            }
            $freeAbove = (int)($rate['free_above'] ?? 0);
            $freeFromQty = (int)($rate['free_from_qty'] ?? 0);
            $reason = null;
            if ($freeAbove > 0 && $subtotal >= $freeAbove) $reason = 'amount';
            elseif ($freeFromQty > 0 && $physicalQty >= $freeFromQty) $reason = 'quantity';

            $rate['free_from_qty'] = $freeFromQty > 0 ? $freeFromQty : null;
            $rate['physical_qty'] = $physicalQty;
            $rate['free_reason'] = $reason;
            if ($reason !== null) {
                $rate['price'] = self::freeShippingPrice($rate);
                if (array_key_exists('base_price', $rate)) $rate['base_price'] = 0;
            }
        }
        unset($rate);
        return $rates;
    }

    /**
     * Price of a rate whose shipping is free (carrier threshold or free-shipping promotion): database rates carry
     * the COD surcharge inside their price and keep charging it; adapter/legacy rates keep COD separate.
     */
    public static function freeShippingPrice(array $rate): int
    {
        return array_key_exists('base_price', $rate) ? max(0, (int)($rate['cod_surcharge'] ?? 0)) : 0;
    }

    /** @return array<string,int> method_code => free_from_qty for adapter-priced methods */
    private static function methodQuantityRules(): array
    {
        try {
            $stmt = Db::get()->prepare("SELECT code, free_from_qty FROM shipping_methods WHERE store_id = :store AND free_from_qty > 0");
            $stmt->execute([':store' => StoreContext::current()->providerId()]);
            $rules = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) $rules[(string)$row['code']] = (int)$row['free_from_qty'];
            return $rules;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function hasDatabasePricing(): bool
    {
        $db = Db::get();
        return self::tableExists($db, 'shipping_methods')
            && self::tableExists($db, 'shipping_zones')
            && self::tableExists($db, 'shipping_zone_countries')
            && self::tableExists($db, 'shipping_rates');
    }

    private static function tableExists(\PDO $db, string $table): bool
    {
        try {
            return Db::tableExists($table, $db);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return ShippingAdapter[] */
    private static function resolveAdapters(): array
    {
        if (!empty(self::$adapters)) {
            return self::$adapters;
        }

        // A carrier that cannot even be constructed (missing key, broken extension) is left out like an outage.
        $adapters = [];
        foreach (Adapters::availableCodes('shipping') as $code) {
            try {
                $adapters[$code] = Adapters::provider('shipping', (string)$code);
            } catch (\Throwable $e) {
                Logger::warning('shipping.adapter_unavailable', ['carrier' => (string)$code, 'exception_type' => get_class($e)]);
                IncidentMonitor::report('shipping.adapter_unavailable', 'shipping', 'warning', ['exception' => $e]);
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
        $stmt = $db->prepare("SELECT * FROM orders WHERE id = :id AND store_id = :store");
        $stmt->execute([':id' => $orderId, ':store' => StoreContext::current()->id()]);
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
