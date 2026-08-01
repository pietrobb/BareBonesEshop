<?php
/** Optional ERP extension for pushing lifecycle changes after the initial order export. */
interface OrderStatusIntegrationAdapter extends IntegrationAdapter
{
    public function updateOrderStatus(string $externalId, string $status, array $context = []): void;
}
