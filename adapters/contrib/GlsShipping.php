<?php
/**
 * GLS Shipping Adapter
 * Pan-European carrier — widely used in SK/CZ/EU.
 * Dev: mock. Prod: set SHIP_GLS_CLIENT_ID + SHIP_GLS_CLIENT_SECRET.
 *
 * COD: supported across all serviced EU countries
 */
class GlsShipping implements ShippingAdapter
{
    private int $freeAboveCents;

    public function __construct()
    {
        $this->freeAboveCents = (int)($_ENV['SHIP_GLS_FREE_ABOVE'] ?? 7500); // 75 EUR default
    }

    public function carrierId(): string
    {
        return 'gls';
    }

    public function supportsCOD(): bool
    {
        return true;
    }

    public function getRates(array $params): array
    {
        $country  = strtoupper($params['country'] ?? 'SK');
        $weightG  = (int)($params['weight_g'] ?? 0);
        $subtotal = (int)($params['subtotal'] ?? 0);
        $codWanted = !empty($params['cod']);

        if (!$this->servicesCountry($country)) {
            return [];
        }

        if ($subtotal >= $this->freeAboveCents && $subtotal > 0) {
            return [[
                'carrier'       => $this->carrierId(),
                'label'         => 'GLS — zadarmo',
                'price'         => 0,
                'cod_surcharge' => 0,
                'delivery_days' => $this->deliveryDays($country),
                'cod'           => $this->supportsCOD(),
            ]];
        }

        $price = $this->lookupDbRate($country, $weightG) ?? $this->fallbackRate($country, $weightG);
        if ($price === null) return [];

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
        $clientId = $_ENV['SHIP_GLS_CLIENT_ID'] ?? null;
        if ($clientId) {
            throw new \RuntimeException('GLS production API not yet wired (set SHIP_GLS_CLIENT_ID)');
        }

        $awb = 'GLS' . str_pad((string)$order['id'], 9, '0', STR_PAD_LEFT);
        return [
            'awb'        => $awb,
            'label_data' => base64_encode("MOCK GLS LABEL\nAWB: {$awb}\nOrder: {$order['id']}"),
            'carrier'    => $this->carrierId(),
        ];
    }

    public function trackShipment(string $awb): array
    {
        return [
            'awb'    => $awb,
            'status' => 'in_transit',
            'events' => [
                ['timestamp' => date('Y-m-d H:i:s', strtotime('-3 hours')), 'location' => 'GLS Depot SK', 'description' => 'Parcel sorted'],
                ['timestamp' => date('Y-m-d H:i:s', strtotime('-1 hour')),  'location' => 'GLS Route',   'description' => 'Out for delivery'],
            ],
        ];
    }

    // ─── Private helpers ───────────────────────────────────────────────────────

    private static array $EU_COUNTRIES = [
        'SK','CZ','DE','AT','HU','PL','FR','IT','ES','NL','BE','RO','BG','HR',
        'SI','EE','LV','LT','DK','SE','FI','PT','GR','IE',
    ];

    private function servicesCountry(string $country): bool
    {
        return in_array($country, self::$EU_COUNTRIES, true);
    }

    private function lookupDbRate(string $country, int $weightG): ?int
    {
        try {
            $db   = Db::get();
            $stmt = $db->prepare("
                SELECT price FROM shipping_rates
                WHERE carrier = 'gls' AND country = :country
                  AND :weight BETWEEN weight_from_g AND weight_to_g
                ORDER BY weight_from_g ASC LIMIT 1
            ");
            $stmt->execute([':country' => $country, ':weight' => $weightG]);
            $row = $stmt->fetch();
            return $row ? (int)$row['price'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** GLS standard EU rates by weight tier (cents) */
    private function fallbackRate(string $country, int $weightG): ?int
    {
        $domestic = in_array($country, ['SK','CZ'], true);

        if ($domestic) {
            $tiers = [[0, 2000, 399], [2001, 5000, 499], [5001, 20000, 699], [20001, 999999, 999]];
        } else {
            $tiers = [[0, 2000, 699], [2001, 5000, 899], [5001, 20000, 1299], [20001, 999999, 1999]];
        }

        foreach ($tiers as [$from, $to, $price]) {
            if ($weightG >= $from && $weightG <= $to) return $price;
        }
        return null;
    }

    private function codSurcharge(string $country): int
    {
        return 150; // 1.50 EUR COD surcharge for GLS
    }

    private function label(string $country): string
    {
        return match($country) {
            'SK' => 'GLS — kuriér na adresu',
            'CZ' => 'GLS — kurýr na adresu',
            default => 'GLS — courier delivery',
        };
    }

    private function deliveryDays(string $country): string
    {
        return match(true) {
            in_array($country, ['SK','CZ','AT','HU','PL'], true) => '1-2',
            in_array($country, ['DE','FR','IT','ES','NL','BE'], true) => '2-3',
            default => '3-5',
        };
    }
}
