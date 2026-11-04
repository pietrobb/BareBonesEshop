<?php
/**
 * Optional for shipping adapters: read the carrier's own tracking notification format.
 *
 * POST /api/v1/webhooks/shipping/<carrier> first verifies the request (HMAC-SHA256 with the carrier's secret, see
 * ShippingService::handleTrackingWebhook). The verified body then goes to this method of the carrier's adapter;
 * an adapter without it gets the core format {"awb": "...", "status": "..."} (or "tracking_number").
 */
interface TrackingWebhookAdapter extends ShippingAdapter
{
    /**
     * @param array  $payload    The decoded JSON body (the endpoint accepts JSON bodies only)
     * @param string $rawPayload The exact body the signature covered
     * @return array{awb:string, status:string} status is a key of ShippingService::TRACKING_STATUS_RANK (created,
     *         in_transit, out_for_delivery, ready_for_pickup, failed, delivered); an empty AWB or another status is
     *         answered as not handled
     */
    public function parseTrackingWebhook(array $payload, string $rawPayload): array;
}
