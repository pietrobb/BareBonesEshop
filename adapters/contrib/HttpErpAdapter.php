<?php
/** Generic versioned HTTPS ERP contract for orders, statuses, products, customers, and stock. */
class HttpErpAdapter implements OrderStatusIntegrationAdapter
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = (array)($config['integrations']['http_erp'] ?? []);
    }

    public function pullProducts(?DateTimeInterface $since = null): iterable
    {
        $path = '/v1/products';
        if ($since !== null) $path .= '?updated_since=' . rawurlencode($since->format(DateTimeInterface::ATOM));
        $result = $this->call($path);
        foreach ((array)($result['data'] ?? []) as $product) {
            if (is_array($product)) yield $product;
        }
    }

    public function pushProduct(array $product): void
    {
        $sku = $this->identifier((string)($product['sku'] ?? ''), 'product SKU');
        $this->call('/v1/products/' . rawurlencode($sku), 'PUT', ['product' => $product]);
    }

    public function pushOrder(array $order): string
    {
        $orderId = (int)($order['id'] ?? 0);
        if ($orderId <= 0) throw new InvalidArgumentException('ERP order requires an ID');
        $result = $this->call('/v1/orders', 'POST', [
            'idempotency_key' => 'order-' . $orderId,
            'order' => $order,
        ]);
        $externalId = trim((string)($result['id'] ?? $result['external_id'] ?? ''));
        if ($externalId === '') throw new RuntimeException('ERP response is missing external order ID');
        return $externalId;
    }

    public function updateOrderStatus(string $externalId, string $status, array $context = []): void
    {
        $externalId = $this->identifier($externalId, 'external order ID');
        if (!preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $status)) throw new InvalidArgumentException('Invalid ERP order status');
        $this->call('/v1/orders/' . rawurlencode($externalId), 'PATCH', [
            'idempotency_key' => 'status-' . hash('sha256', $externalId . "\0" . $status . "\0" . json_encode($context)),
            'status' => $status,
            'context' => $context,
        ]);
    }

    public function pullOrderStatus(string $externalId): string
    {
        $result = $this->call('/v1/orders/' . rawurlencode($this->identifier($externalId, 'external order ID')) . '/status');
        return (string)($result['status'] ?? '');
    }

    public function pullStock(): array
    {
        $result = $this->call('/v1/stock');
        return array_values(array_filter((array)($result['data'] ?? []), 'is_array'));
    }

    public function pushCustomer(array $customer): string
    {
        $localId = (int)($customer['id'] ?? 0);
        $result = $this->call('/v1/customers', 'POST', [
            'idempotency_key' => $localId > 0 ? 'customer-' . $localId : 'customer-' . hash('sha256', (string)($customer['email'] ?? '')),
            'customer' => $customer,
        ]);
        $externalId = trim((string)($result['id'] ?? $result['external_id'] ?? ''));
        if ($externalId === '') throw new RuntimeException('ERP response is missing external customer ID');
        return $externalId;
    }

    public function healthCheck(): bool
    {
        try {
            $result = $this->call('/v1/health');
            return ($result['status'] ?? '') === 'ok';
        } catch (Throwable $e) {
            return false;
        }
    }

    private function call(string $path, string $method = 'GET', ?array $payload = null): array
    {
        $baseUrl = (string)($this->config['base_url'] ?? '');
        $apiKey = (string)($this->config['api_key'] ?? '');
        if ($baseUrl === '' || strlen($apiKey) < 16) throw new RuntimeException('HTTP ERP integration is not configured');
        return JsonHttpClient::request($baseUrl, $path, $method, $payload, ['Authorization: Bearer ' . $apiKey], (int)($this->config['timeout'] ?? 15));
    }

    private function identifier(string $value, string $label): string
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/', $value)) throw new InvalidArgumentException("Invalid {$label}");
        return $value;
    }
}
