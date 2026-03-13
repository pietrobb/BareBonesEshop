<?php
/**
 * BareBones Shop — Integration (ERP) Adapter Interface
 * Default: null (standalone shop, no integration)
 * Optional: MoneyErpAdapter, PohodaAdapter, FlexiBeeAdapter,
 *           WooCommerceAdapter, CsvFileAdapter
 */
interface IntegrationAdapter {
    /** Pull products from external system (delta sync) */
    public function pullProducts(?\DateTimeInterface $since = null): iterable;

    /** Push a product to external system */
    public function pushProduct(array $product): void;

    /** Push an order to external system, returns external ID */
    public function pushOrder(array $order): string;

    /** Pull order status from external system */
    public function pullOrderStatus(string $externalId): string;

    /** Pull stock levels: [{sku, qty}, ...] */
    public function pullStock(): array;

    /** Push a customer to external system, returns external ID */
    public function pushCustomer(array $customer): string;

    /** Check connection health */
    public function healthCheck(): bool;
}
