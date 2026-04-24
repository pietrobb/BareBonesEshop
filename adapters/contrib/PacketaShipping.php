<?php
/**
 * Packeta (Zásilkovna) Shipping Adapter
 * Most popular carrier for SK/CZ e-commerce.
 * Dev: mock responses. Prod: set SHIP_PACKETA_API_KEY env var.
 *
 * COD: supported for SK, CZ
 * Coverage: SK, CZ; EU via partner carriers
 */
class PacketaShipping implements ShippingAdapter
{
    // Free shipping threshold from env or default (5000 = 50 EUR)
    private int $freeAboveCents;

    public function __construct()
    {
        $this->freeAboveCents = (int)($_ENV['SHIP_PACKETA_FREE_ABOVE'] ?? 5000);
    }

    public function carrierId(): string
    {
        return 'packeta';
    }

    public function supportsCOD(): bool
    {
        return true;
    }

    /**
     * Packeta rates by weight tier, loaded from shipping_rates DB if available,
     * with hardcoded fallback for SK/CZ.
     */
    public function getRates(array $params): array
    {
        $country  = strtoupper($params['country'] ?? 'SK');
        $weightG  = (int)($params['weight_g'] ?? 0);
        $subtotal = (int)($params['subtotal'] ?? 0);
        $codWanted = !empty($params['cod']);

        // Check free threshold
        if ($subtotal >= $this->freeAboveCents && $subtotal > 0) {
            return [[
                'carrier'       => $this->carrierId(),
                'label'         => 'Packeta — zadarmo',
                'price'         => 0,
                'cod_surcharge' => 0,
                'delivery_days' => '2-3',
                'cod'           => $this->supportsCOD(),
            ]];
        }

        // Try DB rates first
        $price = $this->lookupDbRate($country, $weightG);

        if ($price === null) {
            // Fallback: hardcoded SK/CZ tiers
            $price = $this->fallbackRate($country, $weightG);
        }

        if ($price === null) {
            return []; // carrier does not service this country
        }

        $codSurcharge = ($codWanted && $this->supportsCOD()) ? $this->codSurcharge($country) : 0;

        return [[
            'carrier'       => $this->carrierId(),
            'label'         => $this->label($country),
            'price'         => $price,
            'cod_surcharge' => $codSurcharge,
            'delivery_days' => $this->deliveryDays($country),
            'cod'           => $this->supportsCOD(),
        ]];
    }

    public function generateLabel(array $order): array
    {
        $apiKey = $_ENV['SHIP_PACKETA_API_KEY'] ?? null;
        if ($apiKey) {
            // Production: call Packeta REST API
            // POST https://www.zasilkovna.cz/api/rest → CreatePacket
            // Simplified — real implementation requires SOAP/REST client
            throw new \RuntimeException('Packeta production API not yet wired (set SHIP_PACKETA_API_KEY)');
        }

        // Mock: generate deterministic AWB for testing
        $awb = 'PKT' . str_pad((string)$order['id'], 10, '0', STR_PAD_LEFT);
        return [
            'awb'        => $awb,
            'label_data' => base64_encode("MOCK PACKETA LABEL\nAWB: {$awb}\nOrder: {$order['id']}"),
            'carrier'    => $this->carrierId(),
        ];
    }

    public function trackShipment(string $awb): array
    {
        // Mock tracking
        return [
            'awb'    => $awb,
            'status' => 'in_transit',
            'events' => [
                ['timestamp' => date('Y-m-d H:i:s', strtotime('-2 hours')), 'location' => 'Bratislava', 'description' => 'Package received'],
                ['timestamp' => date('Y-m-d H:i:s', strtotime('-1 hour')),  'location' => 'Žilina',      'description' => 'In transit'],
            ],
        ];
    }

    // ─── Private helpers ───────────────────────────────────────────────────────

    private function lookupDbRate(string $country, int $weightG): ?int
    {
        try {
            $db   = Db::get();
            $stmt = $db->prepare("
                SELECT price FROM shipping_rates
                WHERE carrier = 'packeta' AND country = :country
                  AND :weight BETWEEN weight_from_g AND weight_to_g
                ORDER BY weight_from_g ASC LIMIT 1
            ");
            $stmt->execute([':country' => $country, ':weight' => $weightG]);
            $row = $stmt->fetch();
            return $row ? (int)$row['price'] : null;
        } catch (\Throwable $e) {
            return null; // table may not exist in test env
        }
    }

    private function lookupDbCodSurcharge(string $country): ?int
    {
        try {
            $db   = Db::get();
            $stmt = $db->prepare("
                SELECT cod_surcharge FROM shipping_rates
                WHERE carrier = 'packeta' AND country = :country
                ORDER BY weight_from_g ASC LIMIT 1
            ");
            $stmt->execute([':country' => $country]);
            $row = $stmt->fetch();
            return $row ? (int)$row['cod_surcharge'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Hardcoded tiered fallback (cents) */
    private function fallbackRate(string $country, int $weightG): ?int
    {
        $tiers = [
            'SK' => [[0, 2000, 299], [2001, 5000, 349], [5001, 10000, 449], [10001, 999999, 599]],
            'CZ' => [[0, 2000, 399], [2001, 5000, 449], [5001, 10000, 599], [10001, 999999, 799]],
            'DE' => [[0, 5000, 899], [5001, 999999, 1299]],
            'AT' => [[0, 5000, 799], [5001, 999999, 1099]],
            'HU' => [[0, 5000, 799], [5001, 999999, 1099]],
            'PL' => [[0, 5000, 799], [5001, 999999, 1099]],
        ];
        foreach ($tiers[$country] ?? [] as [$from, $to, $price]) {
            if ($weightG >= $from && $weightG <= $to) return $price;
        }
        return null;
    }

    private function codSurcharge(string $country): int
    {
        return $this->lookupDbCodSurcharge($country) ?? 100; // default 1 EUR COD fee
    }

    private function label(string $country): string
    {
        return match($country) {
            'SK' => 'Packeta — doručenie na adresu / výdajné miesto',
            'CZ' => 'Packeta — doručení na adresu / výdejní místo',
            default => 'Packeta — delivery',
        };
    }

    private function deliveryDays(string $country): string
    {
        return match($country) {
            'SK' => '1-2', 'CZ' => '1-2',
            default => '3-5',
        };
    }
}
