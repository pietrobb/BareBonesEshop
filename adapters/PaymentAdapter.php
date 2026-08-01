<?php
/**
 * BareBones Shop — Payment Adapter Interface
 * Default: StripePayment (Stripe Checkout — hosted flow)
 * Optional: PayPalPayment, GoPay, TatraPay, BankTransfer, CashOnDelivery
 */
interface PaymentAdapter {
    /** Create a payment session, returns redirect URL or client secret */
    public function createSession(array $order): array;

    /** Process webhook callback from payment provider */
    public function handleWebhook(array $payload): array;

    /** Refund a payment (full or partial) */
    public function refund(string $paymentId, int $amount): array;

    /** Get current payment status */
    public function getStatus(string $paymentId): string;
}

/** Provider capability required for retry-safe external refund mutations. */
interface IdempotentRefundAdapter extends PaymentAdapter {
    public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array;
}
