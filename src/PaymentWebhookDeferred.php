<?php
/**
 * R27: a verified payment webhook that cannot be applied yet because the provider data it needs is unavailable (a Stripe
 * charge.refunded whose refunds cannot be listed). Nothing is booked; the webhook answers 503 so the provider redelivers it.
 */
final class PaymentWebhookDeferred extends RuntimeException {
}
