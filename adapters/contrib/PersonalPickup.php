<?php
/**
 * Personal Pickup Shipping Adapter
 * In-store pickup — always free, no COD needed (paid in-person or pre-paid).
 * Always available for all countries (customer comes to the store).
 */
class PersonalPickup implements ShippingAdapter
{
    public function carrierId(): string
    {
        return 'pickup';
    }

    public function supportsCOD(): bool
    {
        // COD not applicable — customer pays in-store or pre-paid online
        return false;
    }

    public function getRates(array $params): array
    {
        return [[
            'carrier'       => $this->carrierId(),
            'label'         => $this->label($params['country'] ?? 'SK'),
            'price'         => 0,
            'cod_surcharge' => 0,
            'delivery_days' => '0',
            'cod'           => false,
        ]];
    }

    public function generateLabel(array $order): array
    {
        // No physical label needed — generate a pickup confirmation code. It is the collection
        // secret, so it is random (not derived from the order ID); ShippingService::generateLabel()
        // stores it as the label AWB / order tracking number and returns the stored one on repeat calls.
        $code = 'PU-' . strtoupper(bin2hex(random_bytes(5)));
        return [
            'awb'        => $code,
            'label_data' => base64_encode("PICKUP CONFIRMATION\nCode: {$code}\nOrder: {$order['id']}\nPlease bring this code to the store."),
            'carrier'    => $this->carrierId(),
        ];
    }

    public function trackShipment(string $awb): array
    {
        return [
            'awb'    => $awb,
            'status' => 'ready_for_pickup',
            'events' => [
                ['timestamp' => date('Y-m-d H:i:s'), 'location' => 'Store', 'description' => 'Ready for pickup'],
            ],
        ];
    }

    private function label(string $country): string
    {
        return match(strtoupper($country)) {
            'SK' => 'Osobný odber — predajňa',
            'CZ' => 'Osobní odběr — prodejna',
            'DE' => 'Abholung im Laden',
            default => 'In-store pickup',
        };
    }
}
