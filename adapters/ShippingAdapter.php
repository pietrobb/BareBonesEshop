<?php
/**
 * BareBones Shop — Shipping Adapter Interface
 * Pluggable carrier integration: rate calculation, label generation, tracking.
 */
interface ShippingAdapter
{
    /**
     * Return available rates for the given shipment parameters.
     *
     * @param array $params {
     *   country   string  ISO-3166 country code (e.g. 'SK', 'CZ', 'DE')
     *   weight_g  int     Total package weight in grams
     *   subtotal  int     Cart subtotal in minor units of `currency` (for free-shipping threshold)
     *   currency  string  ISO-4217 store currency (e.g. 'EUR', 'CZK'); every amount in and out is in it.
     *                     An adapter whose prices exist only in another currency returns [] (no quote).
     *   cod       bool    Customer wants cash-on-delivery
     * }
     * @return array[] List of rate options: [{
     *   carrier      string  Adapter identifier (e.g. 'packeta')
     *   label        string  Human-readable name (locale-aware)
     *   price        int     Shipping price in minor units of `currency` (0 = free)
     *   cod_surcharge int    Additional COD fee in minor units (0 if not applicable)
     *   delivery_days string Estimated delivery range, e.g. '1-2'
     *   cod          bool    Whether COD is available for this option
     * }]
     */
    public function getRates(array $params): array;

    /**
     * Generate a shipping label (AWB) for a confirmed order.
     *
     * @param array $order Full order row from DB (+ items if needed)
     * @return array {
     *   awb        string  Tracking number / AWB
     *   label_data string  Base64-encoded PDF label or URL
     *   carrier    string  Carrier identifier
     * }
     * @throws \RuntimeException on API error or unsupported country
     */
    public function generateLabel(array $order): array;

    /**
     * Fetch current tracking status for a given AWB.
     *
     * @param string $awb Tracking number returned by generateLabel()
     * @return array {
     *   awb     string
     *   status  string  One of: created, in_transit, out_for_delivery, delivered, failed
     *   events  array[] [{timestamp, location, description}]
     * }
     */
    public function trackShipment(string $awb): array;

    /**
     * Whether this carrier supports cash-on-delivery payments.
     */
    public function supportsCOD(): bool;

    /**
     * Unique machine-readable carrier identifier (e.g. 'packeta', 'gls', 'pickup').
     */
    public function carrierId(): string;
}
