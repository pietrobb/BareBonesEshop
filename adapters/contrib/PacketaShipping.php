<?php
/**
 * Packeta (Zásilkovna) Shipping Adapter
 * Most popular carrier for SK/CZ e-commerce.
 * Dev: mock responses. Prod: set SHIP_PACKETA_API_KEY env var.
 *
 * COD: supported for SK, CZ
 * Coverage: SK, CZ; EU via partner carriers
 */
class PacketaShipping implements ShippingLifecycleAdapter
{
    /** Pick-up points offered at once in the checkout list, nearest postal codes first. */
    private const PICKUP_LIST_LIMIT = 50;

    // Free shipping threshold from env or default (5000 = 50 EUR)
    private int $freeAboveCents;
    private string $pointsDir;

    public function __construct(array $config = [])
    {
        $this->freeAboveCents = (int)($_ENV['SHIP_PACKETA_FREE_ABOVE'] ?? 5000);
        $dir = (string)($_ENV['SHIP_PACKETA_POINTS_DIR'] ?? getenv('SHIP_PACKETA_POINTS_DIR') ?: '');
        $this->pointsDir = $dir !== '' ? rtrim($dir, '/\\')
            : rtrim((string)($config['private_cache_dir'] ?? $config['cache_dir'] ?? sys_get_temp_dir()), '/\\') . '/packeta';
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
        // The built-in tiers, the COD fee and SHIP_PACKETA_FREE_ABOVE are EUR cents; other store
        // currencies are quoted only from the store's own shipping_rates rows (entered in that currency).
        $currency = strtoupper((string)($params['currency'] ?? 'EUR'));
        $eur = $currency === 'EUR';

        // Coverage first: free shipping is never offered to a country Packeta does not serve.
        $price = $this->lookupDbRate($country, $weightG, $currency) ?? ($eur ? $this->fallbackRate($country, $weightG) : null);
        if ($price === null) {
            return []; // carrier does not service this country (or has no price in this currency)
        }

        if ($eur && $subtotal >= $this->freeAboveCents && $subtotal > 0) {
            return [[
                'carrier'       => $this->carrierId(),
                'label'         => 'Packeta — zadarmo',
                'price'         => 0,
                'cod_surcharge' => 0,
                'delivery_days' => $this->deliveryDays($country),
                'cod'           => $this->supportsCOD(),
            ]];
        }

        $codSurcharge = ($codWanted && $this->supportsCOD()) ? $this->codSurcharge($country, $currency) : 0;

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
            'label_data' => base64_encode("MOCK PACKETA LABEL\nAWB: {$awb}\nOrder: {$order['id']}"
                . (!empty($order['pickup_point_id']) ? "\nPick-up point: {$order['pickup_point_id']}" : '')),
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

    /**
     * Packeta pick-up points of one country from the locally stored feed (see syncPickupPoints),
     * nearest postal codes first. $postalCode may also be a search text (town, street or point name):
     * then only matching points are listed, so a point beyond the nearest PICKUP_LIST_LIMIT can be found.
     * Nothing is invented: without a synced feed the list is empty.
     */
    public function pickupPoints(string $country, string $postalCode = ''): array
    {
        $points = $this->storedPoints($country);
        // Postal codes, also with a hyphen (PL "00-950"), are not search text: a leftover "-" matched no point.
        $search = self::fold(trim((string)preg_replace(['/\d+(?:[\s-]+\d+)*/u', '/\s+/u'], ' ', $postalCode)));
        if ($search !== '') {
            $points = array_values(array_filter($points, static fn(array $p): bool =>
                str_contains(self::fold($p['name'] . ' ' . $p['street'] . ' ' . $p['city']), $search)));
        }
        $zip = (int)preg_replace('/\D/', '', $postalCode);
        if ($zip > 0) {
            usort($points, static fn(array $a, array $b): int =>
                abs((int)preg_replace('/\D/', '', $a['zip']) - $zip) <=> abs((int)preg_replace('/\D/', '', $b['zip']) - $zip) ?: strcmp($a['id'], $b['id']));
        }
        return array_map([self::class, 'pointView'], array_slice($points, 0, self::PICKUP_LIST_LIMIT));
    }

    /** Any point of the country's feed by id, also one that is not among the listed nearest ones. */
    public function pickupPoint(string $country, string $id): ?array
    {
        foreach ($this->storedPoints($country) as $point) {
            if ($point['id'] === $id) return self::pointView($point);
        }
        return null;
    }

    /** @return list<array{id:string,name:string,street:string,city:string,zip:string}> */
    private function storedPoints(string $country): array
    {
        $file = $this->pointsFile($country);
        $points = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        return is_array($points) ? array_values($points) : [];
    }

    private static function pointView(array $p): array
    {
        return ['id' => $p['id'], 'name' => $p['name'], 'address' => trim($p['street'] . ', ' . $p['zip'] . ' ' . $p['city'], ', ')];
    }

    /** Lower case without diacritics, so "zilina" finds "Žilina". */
    private static function fold(string $text): string
    {
        return Str::removeDiacritics($text);
    }

    /**
     * Download the official v5 branch feed of one country and keep the points customers can pick:
     * in operation (statusId 1) and meant for the frontend (displayFrontend 1).
     * https://docs.packeta.com/docs/pudo-delivery/packeta-pudos — Packeta asks for 1–4 refreshes a day.
     */
    public function syncPickupPoints(string $country): int
    {
        $apiKey = (string)($_ENV['SHIP_PACKETA_API_KEY'] ?? getenv('SHIP_PACKETA_API_KEY') ?: '');
        if (!preg_match('/^[A-Za-z0-9]{8,64}$/', $apiKey)) throw new \RuntimeException('SHIP_PACKETA_API_KEY is not configured');
        $country = $this->assertCountry($country);
        $feed = JsonHttpClient::request('https://pickup-point.api.packeta.com', '/v5/' . $apiKey . '/branch/json?country=' . strtolower($country) . '&lang=sk', 'GET', null, [], 120);
        return $this->storePickupPoints($country, $feed);
    }

    /** @param array<int,array<string,mixed>> $branches Branch objects in the v5 feed format. */
    public function storePickupPoints(string $country, array $branches): int
    {
        $country = $this->assertCountry($country);
        $points = [];
        foreach ($branches as $branch) {
            if (!is_array($branch) || strtoupper((string)($branch['country'] ?? '')) !== $country) continue;
            if ((string)($branch['status']['statusId'] ?? '') !== '1' || (string)($branch['displayFrontend'] ?? '') !== '1') continue;
            $id = (string)($branch['id'] ?? '');
            if (!preg_match('/^\d{1,12}$/', $id)) continue;
            $points[] = ['id' => $id, 'name' => (string)($branch['name'] ?? ''), 'street' => (string)($branch['street'] ?? ''),
                'city' => (string)($branch['city'] ?? ''), 'zip' => (string)($branch['zip'] ?? '')];
        }
        if (!is_dir($this->pointsDir) && !mkdir($this->pointsDir, 0750, true) && !is_dir($this->pointsDir)) {
            throw new \RuntimeException('Cannot create Packeta pick-up point directory');
        }
        $file = $this->pointsFile($country);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, json_encode($points, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        rename($tmp, $file);
        return count($points);
    }

    public function cancelLabel(string $awb): array
    {
        if (!empty($_ENV['SHIP_PACKETA_API_KEY'])) {
            throw new \RuntimeException('Packeta production API not yet wired (set SHIP_PACKETA_API_KEY)');
        }
        return ['awb' => $awb, 'status' => 'cancelled'];
    }

    // ─── Private helpers ───────────────────────────────────────────────────────

    private function assertCountry(string $country): string
    {
        $country = strtoupper(trim($country));
        if (!preg_match('/^[A-Z]{2}$/', $country)) throw new \InvalidArgumentException('Invalid country code');
        return $country;
    }

    private function pointsFile(string $country): string
    {
        return $this->pointsDir . '/branch-' . $this->assertCountry($country) . '.json';
    }

    private function lookupDbRate(string $country, int $weightG, string $currency): ?int
    {
        if (!StoreContext::current()->usesProviderPrices($currency)) return null;
        try {
            $db   = Db::get();
            $stmt = $db->prepare("
                SELECT price FROM shipping_rates
                WHERE carrier = 'packeta' AND country = :country AND store_id = :store
                  AND :weight BETWEEN weight_from_g AND weight_to_g
                ORDER BY weight_from_g ASC LIMIT 1
            ");
            $stmt->execute([':country' => $country, ':weight' => $weightG, ':store' => StoreContext::current()->providerId()]);
            $row = $stmt->fetch();
            return $row ? (int)$row['price'] : null;
        } catch (\Throwable $e) {
            return null; // table may not exist in test env
        }
    }

    private function lookupDbCodSurcharge(string $country, string $currency): ?int
    {
        if (!StoreContext::current()->usesProviderPrices($currency)) return null;
        try {
            $db   = Db::get();
            $stmt = $db->prepare("
                SELECT cod_surcharge FROM shipping_rates
                WHERE carrier = 'packeta' AND country = :country AND store_id = :store
                ORDER BY weight_from_g ASC LIMIT 1
            ");
            $stmt->execute([':country' => $country, ':store' => StoreContext::current()->providerId()]);
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

    private function codSurcharge(string $country, string $currency): int
    {
        // The 1 EUR default only applies to EUR carts; other currencies use the store's own row.
        return $this->lookupDbCodSurcharge($country, $currency) ?? ($currency === 'EUR' ? 100 : 0);
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
