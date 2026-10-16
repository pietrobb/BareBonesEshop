<?php
/**
 * BareBones Shop — Payment capability: providers whose webhooks are authenticated by a
 * signature over the raw request body. PaymentService::handleWebhook() applies a webhook
 * only for gateways with this capability whose verifySignature() accepts the request;
 * every other gateway's webhook is rejected as unverified.
 */
interface SignedWebhookPaymentAdapter extends PaymentAdapter {
    /** Authenticate a webhook request (raw body + signature header) before handleWebhook() runs. */
    public function verifySignature(string $payload, string $sigHeader): bool;
}
