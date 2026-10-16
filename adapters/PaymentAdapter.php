<?php
/**
 * BareBones Shop — Payment Adapter Interface
 * Shipped adapters (adapters/contrib): StripePayment (Stripe Checkout — hosted flow), PayUPayment,
 * GoPayPayment, ComgatePayment, ThePayPayment, CashOnDelivery, MockPayment (tests). Bank transfer is a built-in offline method, not an adapter.
 * Other gateways are added as new classes implementing this interface.
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

    /**
     * Authenticate a webhook request (raw body + signature header) before handleWebhook() runs.
     * Providers without signed webhooks return false, so a forged request is never applied.
     */
    public function verifySignature(string $payload, string $sigHeader): bool;
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

/**
 * Providers whose notification is only a reference to be confirmed with the provider
 * (GoPayPayment: unsigned GET ?id=<payment>; ComgatePayment: push with merchant+secret;
 * ThePayPayment: unsigned GET ?project_id=…&payment_uid=…).
 * The event is never taken from the request: verifiedEvent() asks the provider's authenticated
 * API for the payment and returns the same shape as handleWebhook() (event_type, event_id, data
 * with order_number/payment_id, amount, currency), so the payment state machine applies it.
 */
interface PullVerifiedNotificationAdapter extends PaymentAdapter {
    /**
     * @param array<string,mixed> $notification request parameters of the notification
     * @throws PaymentNotificationUnverified when the provider does not confirm the reference
     */
    public function verifiedEvent(array $notification): array;
}

/** The notified payment is unknown to the provider or not ours: answer 400, change nothing. */
class PaymentNotificationUnverified extends RuntimeException {}

/**
 * Providers that can void an unpaid checkout/payment, so a cancelled order can no longer be paid.
 * Payments captured anyway (provider without this capability, race) are refunded by
 * PaymentService::settleCapture().
 */
interface CancellablePaymentAdapter extends PaymentAdapter {
    /** Void the unpaid session/payment; a no-op when it is already closed. */
    public function cancelPayment(string $paymentId): void;
}

/** Provider capability required for retry-safe external refund mutations. */
interface IdempotentRefundAdapter extends PaymentAdapter {
    public function refundWithIdempotency(string $paymentId, int $amount, string $idempotencyKey): array;
}
