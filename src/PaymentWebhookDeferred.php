<?php
/**
 * R27: a verified payment webhook that cannot be applied yet because the provider data it needs is unavailable (a Stripe
 * charge.refunded whose refunds cannot be listed). Nothing is booked; the webhook answers 503 so the provider redelivers it.
 * R38 (medium 6): it names its order, so the deferral is counted per event (PaymentWebhookRouting::defer()) and the
 * merchant's incident says which order waits for a refund to be booked.
 */
final class PaymentWebhookDeferred extends RuntimeException {
    public int $orderId = 0;
    public string $orderNumber = '';
    public string $storeId = '';

    public function forOrder(int $orderId): self {
        if ($this->orderId > 0 || $orderId <= 0) return $this;
        $stmt = Db::get()->prepare('SELECT order_number, store_id FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $this->orderId = $orderId;
        $this->orderNumber = (string)($row['order_number'] ?? '');
        $this->storeId = (string)($row['store_id'] ?? '');
        return $this;
    }
}
