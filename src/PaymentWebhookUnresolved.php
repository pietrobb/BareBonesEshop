<?php
/** A verified payment webhook references an order that no eligible store can resolve (yet). */
final class PaymentWebhookUnresolved extends RuntimeException {
    public function __construct(public readonly array $refs, string $reason) {
        parent::__construct($reason);
    }
}
