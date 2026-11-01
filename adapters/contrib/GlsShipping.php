<?php
/**
 * GLS Shipping Adapter
 * Pan-European carrier — widely used in SK/CZ/EU.
 * Dev: mock labels with SHIP_MOCK_LABELS=1. Prod: set SHIP_GLS_CLIENT_ID + SHIP_GLS_CLIENT_SECRET.
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
        // Built-in tiers, the COD fee and SHIP_GLS_FREE_ABOVE are EUR cents; other store currencies
        // are quoted only from the store's own shipping_rates rows (entered in that currency).
        $currency = strtoupper((string)($params['currency'] ?? 'EUR'));
        $eur = $currency === 'EUR';

        if (!$this->servicesCountry($country)) {
            return [];
        }

        $price = $this->lookupDbRate($country, $weightG, $currency) ?? ($eur ? $this->fallbackRate($country, $weightG) : null);
        if ($price === null) return [];

        if ($eur && $subtotal >= $this->freeAboveCents && $subtotal > 0) {
            return [[
                'carrier'       => $this->carrierId(),
                'label'         => 'GLS — ' . I18n::t('free'),
                'price'         => 0,
                'cod_surcharge' => 0,
                'delivery_days' => $this->deliveryDays($country),
                'cod'           => $this->supportsCOD(),
            ]];
        }

        $codSurcharge = ($codWanted && $this->supportsCOD()) ? ($eur ? $this->codSurcharge($country) : $this->lookupDbCodSurcharge($country, $currency)) : 0;

        return [[
            'carrier'       => $this->carrierId(),
            'label'         => I18n::t('carrier.gls'),
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
        self::assertMock('label');

        // Mock (SHIP_MOCK_LABELS=1, development and tests only): deterministic AWB, one per parcel of the order
        $awb = 'GLS' . str_pad((string)$order['id'], 9, '0', STR_PAD_LEFT) . (!empty($order['shipment_id']) ? '-' . (int)$order['shipment_id'] : '');
        return [
            'awb'        => $awb,
            'label_data' => base64_encode("MOCK GLS LABEL\nAWB: {$awb}\nOrder: {$order['id']}"),
            'carrier'    => $this->carrierId(),
        ];
    }

    /**
     * Why labels cannot be created (null = mock labels for development). The production GLS API is not connected in
     * this version: no tracking number is ever invented, the parcel is booked in MyGLS.
     */
    public function labelUnavailableReason(): ?string
    {
        if (!empty($_ENV['SHIP_GLS_CLIENT_ID'])) return 'GLS production API is not connected in this version: create the parcel in MyGLS and enter its tracking number when shipping.';
        if ((string)($_ENV['SHIP_MOCK_LABELS'] ?? getenv('SHIP_MOCK_LABELS')) === '1') return null;
        return 'GLS API is not connected: create the parcel in MyGLS and enter its tracking number when shipping (SHIP_MOCK_LABELS=1 enables mock labels for development).';
    }

    public function trackShipment(string $awb): array
    {
        self::assertMock('tracking');
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

    /** Without the API, an invented AWB looked like a real parcel. Mock data only when explicitly asked for. */
    private static function assertMock(string $what): void
    {
        if ((string)($_ENV['SHIP_MOCK_LABELS'] ?? getenv('SHIP_MOCK_LABELS')) !== '1') {
            throw new \RuntimeException("GLS {$what} is not available: the GLS API is not connected (SHIP_MOCK_LABELS=1 enables mock data for development)");
        }
    }

    private static array $EU_COUNTRIES = [
        'SK','CZ','DE','AT','HU','PL','FR','IT','ES','NL','BE','RO','BG','HR',
        'SI','EE','LV','LT','DK','SE','FI','PT','GR','IE',
    ];

    private function servicesCountry(string $country): bool
    {
        return in_array($country, self::$EU_COUNTRIES, true);
    }

    private function lookupDbRate(string $country, int $weightG, string $currency): ?int
    {
        if (!StoreContext::current()->usesProviderPrices($currency)) return null;
        try {
            $db   = Db::get();
            $stmt = $db->prepare("
                SELECT price FROM shipping_rates
                WHERE carrier = 'gls' AND country = :country AND store_id = :store
                  AND :weight BETWEEN weight_from_g AND weight_to_g
                ORDER BY weight_from_g ASC LIMIT 1
            ");
            $stmt->execute([':country' => $country, ':weight' => $weightG, ':store' => StoreContext::current()->providerId()]);
            $row = $stmt->fetch();
            return $row ? (int)$row['price'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** COD fee from the store's own GLS row (in the scope's currency); 0 when the row has none or the cart is in another. */
    private function lookupDbCodSurcharge(string $country, string $currency): int
    {
        if (!StoreContext::current()->usesProviderPrices($currency)) return 0;
        try {
            $stmt = Db::get()->prepare("SELECT cod_surcharge FROM shipping_rates
                WHERE carrier = 'gls' AND country = :country AND store_id = :store ORDER BY weight_from_g ASC LIMIT 1");
            $stmt->execute([':country' => $country, ':store' => StoreContext::current()->providerId()]);
            $row = $stmt->fetch();
            return $row ? (int)$row['cod_surcharge'] : 0;
        } catch (\Throwable $e) {
            return 0;
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

    private function deliveryDays(string $country): string
    {
        return match(true) {
            in_array($country, ['SK','CZ','AT','HU','PL'], true) => '1-2',
            in_array($country, ['DE','FR','IT','ES','NL','BE'], true) => '2-3',
            default => '3-5',
        };
    }
}
