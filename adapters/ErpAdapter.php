<?php
/** Stable ERP contract for orders, customers, lifecycle status, and health. */
interface ErpAdapter
{
    public function pushOrder(array $order): string;

    public function pullOrderStatus(string $externalId): string;

    public function pushCustomer(array $customer): string;

    public function healthCheck(): bool;
}
