<?php
/** Generic HTTPS carrier contract: rates, pickup points, label, cancel, and tracking. */
class HttpShipping implements ShippingLifecycleAdapter
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = (array)($config['integrations']['http_shipping'] ?? []);
    }

    public function carrierId(): string
    {
        $code = strtolower((string)($this->config['carrier_code'] ?? 'http-carrier'));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,39}$/', $code)) throw new RuntimeException('Invalid HTTP carrier code');
        return $code;
    }

    public function supportsCOD(): bool { return !empty($this->config['supports_cod']); }

    public function getRates(array $params): array
    {
        $result = $this->call('/v1/rates', 'POST', [
            'country' => strtoupper((string)($params['country'] ?? '')),
            'postal_code' => (string)($params['postal_code'] ?? ''),
            'weight_g' => max(0, (int)($params['weight_g'] ?? 0)),
            'volume_cm3' => max(0, (int)($params['volume_cm3'] ?? 0)),
            'subtotal' => max(0, (int)($params['subtotal'] ?? 0)),
            'currency' => strtolower((string)($params['currency'] ?? 'eur')),
            'cod' => !empty($params['cod']),
        ], (int)($this->config['rates_timeout'] ?? 5)); // checkout waits on this call: a short timeout
        $rates = $result['rates'] ?? [];
        if (!is_array($rates)) throw new RuntimeException('Carrier rates response is invalid');
        return array_map(function (array $rate): array {
            return [
                'carrier' => $this->carrierId(),
                'method_code' => (string)($rate['method_code'] ?? $this->carrierId()),
                'label' => (string)($rate['label'] ?? $this->carrierId()),
                'price' => max(0, (int)($rate['price'] ?? 0)),
                'cod_surcharge' => max(0, (int)($rate['cod_surcharge'] ?? 0)),
                'delivery_days' => (string)($rate['delivery_days'] ?? ''),
                'cod' => !empty($rate['cod']),
                'pickup_required' => !empty($rate['pickup_required']),
            ];
        }, array_values($rates));
    }

    public function pickupPoints(string $country, string $postalCode = ''): array
    {
        $query = '?country=' . rawurlencode(strtoupper($country));
        if ($postalCode !== '') $query .= '&postal_code=' . rawurlencode($postalCode);
        $result = $this->call('/v1/pickup-points' . $query);
        $points = $result['data'] ?? [];
        if (!is_array($points)) throw new RuntimeException('Carrier pickup response is invalid');
        return array_map(static fn(array $point): array => [
            'id' => (string)($point['id'] ?? ''),
            'name' => (string)($point['name'] ?? ''),
            'address' => (string)($point['address'] ?? ''),
        ], array_values($points));
    }

    public function generateLabel(array $order): array
    {
        $orderId = (int)($order['id'] ?? 0);
        if ($orderId <= 0) throw new InvalidArgumentException('Carrier label requires an order ID');
        $result = $this->call('/v1/labels', 'POST', [
            'idempotency_key' => 'order-' . $orderId,
            'order' => $this->orderPayload($order),
        ]);
        $awb = trim((string)($result['awb'] ?? ''));
        if ($awb === '') throw new RuntimeException('Carrier label response is missing AWB');
        return ['awb' => $awb, 'label_data' => (string)($result['label_data'] ?? ''), 'carrier' => $this->carrierId()];
    }

    public function cancelLabel(string $awb): array
    {
        $result = $this->call('/v1/labels/' . rawurlencode($this->assertAwb($awb)) . '/cancel', 'POST', [
            'idempotency_key' => 'cancel-' . hash('sha256', $awb),
        ]);
        return ['awb' => $awb, 'status' => (string)($result['status'] ?? 'cancelled')];
    }

    public function trackShipment(string $awb): array
    {
        $result = $this->call('/v1/shipments/' . rawurlencode($this->assertAwb($awb)));
        $events = is_array($result['events'] ?? null) ? $result['events'] : [];
        return ['awb' => $awb, 'status' => (string)($result['status'] ?? 'created'), 'events' => $events];
    }

    private function call(string $path, string $method = 'GET', ?array $payload = null, ?int $timeout = null): array
    {
        $baseUrl = (string)($this->config['base_url'] ?? '');
        $apiKey = (string)($this->config['api_key'] ?? '');
        if ($baseUrl === '' || strlen($apiKey) < 16) throw new RuntimeException('HTTP shipping integration is not configured');
        return JsonHttpClient::request($baseUrl, $path, $method, $payload, ['Authorization: Bearer ' . $apiKey], max(1, $timeout ?? (int)($this->config['timeout'] ?? 15)));
    }

    private function assertAwb(string $awb): string
    {
        if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/', $awb)) throw new InvalidArgumentException('Invalid carrier AWB');
        return $awb;
    }

    private function orderPayload(array $order): array
    {
        $address = json_decode((string)($order['shipping_addr'] ?? '{}'), true) ?: [];
        return [
            'id' => (int)$order['id'],
            'number' => (string)($order['order_number'] ?? ''),
            'recipient' => [
                'name' => (string)($order['customer_name'] ?? ''),
                'email' => (string)($order['customer_email'] ?? ''),
                'phone' => (string)($order['customer_phone'] ?? ''),
                'address' => $address,
            ],
            'total' => (int)($order['total'] ?? 0),
            'currency' => strtolower((string)($order['currency'] ?? 'eur')),
            'pickup_point_id' => (string)($order['pickup_point_id'] ?? ''),
        ];
    }
}
