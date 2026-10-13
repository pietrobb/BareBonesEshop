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

/**
 * Hosted-checkout providers whose createSession() URL sends the browser to another origin.
 * The checkout form POST answers with a redirect there, and browsers apply the page's CSP
 * form-action to that redirect, so every such origin must be declared here.
 */
interface HostedCheckoutAdapter extends PaymentAdapter {
    /** @return list<string> https origins (scheme://host[:port]) createSession() may redirect to */
    public function redirectOrigins(): array;
}

/** Provider capability required for retry-safe external refund mutations. */
interface IdempotentRefundAdapter extends PaymentAdapter {
    public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array;
}
