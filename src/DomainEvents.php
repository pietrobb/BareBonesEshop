<?php
/** Stable public event names emitted through the transactional outbox. */
final class DomainEvents
{
    public const ORDER_CREATED = 'order.created';
    public const ORDER_STATUS_CHANGED = 'order.status_changed';
    public const ORDER_REFUND_REQUESTED = 'order.refund_requested';
    public const PAYMENT_STATUS_CHANGED = 'payment.status_changed';
    public const PRODUCT_UPDATED = 'product.updated';
    public const CUSTOMER_CREATED = 'customer.created';
    public const CUSTOMER_UPDATED = 'customer.updated';
    public const STOCK_ADJUSTED = 'stock.adjusted';
    public const STOCK_RESERVED = 'stock.reserved';
    public const STOCK_CHANGED = 'stock.changed';

    public static function all(): array
    {
        return [
            self::ORDER_CREATED,
            self::ORDER_STATUS_CHANGED,
            self::ORDER_REFUND_REQUESTED,
            self::PAYMENT_STATUS_CHANGED,
            self::PRODUCT_UPDATED,
            self::CUSTOMER_CREATED,
            self::CUSTOMER_UPDATED,
            self::STOCK_ADJUSTED,
            self::STOCK_RESERVED,
            self::STOCK_CHANGED,
        ];
    }
}
