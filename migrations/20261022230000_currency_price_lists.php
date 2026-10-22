<?php
/**
 * Price lists in the store currency for the sample CZ, HU and PL stores (round 8 review).
 *
 * Shipping rates, COD surcharges, free-shipping thresholds and payment fees are amounts in the currency of the
 * store owning the provider_scope. The sample CZK/HUF/PLN stores shared the default (EUR) scope and therefore got
 * only personal pickup and fee-less payment methods. stores.json now gives each its own provider_scope; this seeds
 * those scopes with the default methods priced in their currency (minor units). A scope that already has any
 * shipping or payment method is left alone, so an installation's own configuration is never touched.
 */
return new class {
    private const EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT',
        'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'];

    private const SCOPES = [
        'cz' => [
            'zones' => [['domestic', 'Domácí CZ', ['CZ']], ['sk', 'Slovensko', ['SK']], ['near_eu', 'Okolní EU', ['AT', 'HU', 'PL', 'DE']]],
            'methods' => [
                ['pickup', 'pickup', 'Osobní odběr', 'PersonalPickup', 'pickup', 0, 1, null, null, null, 10],
                ['packeta', 'packeta', 'Zásilkovna', 'PacketaShipping', 'parcel', 1, 1, 150000, 10000, 60000, 20],
                ['gls', 'gls', 'GLS kurýr', 'GlsShipping', 'parcel', 1, 0, 200000, 40000, 160000, 30],
            ],
            // method, country, zone, weight from, weight to, price, COD surcharge, delivery days
            'rates' => [
                ['pickup', 'CZ', 'domestic', 0, 999999, 0, 0, '0'],
                ['packeta', 'CZ', 'domestic', 0, 2000, 7900, 2500, '1-2'],
                ['packeta', 'CZ', 'domestic', 2001, 5000, 8900, 2500, '1-2'],
                ['packeta', 'CZ', 'domestic', 5001, 10000, 11900, 2500, '1-2'],
                ['packeta', 'SK', 'sk', 0, 2000, 9900, 2500, '1-2'],
                ['packeta', 'SK', 'sk', 2001, 5000, 11900, 2500, '1-2'],
                ['packeta', 'SK', 'sk', 5001, 10000, 14900, 2500, '1-2'],
                ['gls', 'CZ', 'domestic', 0, 2000, 9900, 3500, '1-2'],
                ['gls', 'CZ', 'domestic', 2001, 5000, 11900, 3500, '1-2'],
                ['gls', 'CZ', 'domestic', 5001, 20000, 16900, 3500, '1-2'],
                ['gls', 'SK', 'sk', 0, 2000, 11900, 3500, '1-2'],
                ['gls', 'SK', 'sk', 2001, 5000, 13900, 3500, '1-2'],
                ['gls', 'SK', 'sk', 5001, 20000, 18900, 3500, '1-2'],
                ['gls', '', 'near_eu', 0, 5000, 24900, 3500, '2-3'],
                ['gls', '', 'eu', 0, 5000, 29900, 3500, '3-5'],
                ['gls', '', 'eu', 5001, 20000, 42900, 3500, '3-5'],
            ],
            'payments' => [
                ['card', 'stripe', 'Platba kartou', 'online_card', 0, null, 0, 1, 10],
                ['bank_transfer', 'manual', 'Bankovní převod', 'bank_transfer', 0, null, 0, 0, 20],
                ['cod', 'manual', 'Dobírka', 'cod', 0, 2500000, 3900, 0, 30],
            ],
            'cod_countries' => ['CZ', 'SK'],
        ],
        'hu' => [
            'zones' => [['domestic', 'Belföld HU', ['HU']], ['near_eu', 'Szomszédos EU', ['SK', 'CZ', 'AT', 'PL', 'DE', 'RO', 'HR', 'SI']]],
            'methods' => [
                ['pickup', 'pickup', 'Személyes átvétel', 'PersonalPickup', 'pickup', 0, 1, null, null, null, 10],
                ['packeta', 'packeta', 'Packeta', 'PacketaShipping', 'parcel', 1, 1, 2500000, 10000, 60000, 20],
                ['gls', 'gls', 'GLS futár', 'GlsShipping', 'parcel', 1, 0, 3500000, 40000, 160000, 30],
            ],
            'rates' => [
                ['pickup', 'HU', 'domestic', 0, 999999, 0, 0, '0'],
                ['packeta', 'HU', 'domestic', 0, 2000, 149000, 49000, '1-2'],
                ['packeta', 'HU', 'domestic', 2001, 5000, 169000, 49000, '1-2'],
                ['packeta', 'HU', 'domestic', 5001, 10000, 219000, 49000, '1-2'],
                ['gls', 'HU', 'domestic', 0, 2000, 169000, 59000, '1-2'],
                ['gls', 'HU', 'domestic', 2001, 5000, 199000, 59000, '1-2'],
                ['gls', 'HU', 'domestic', 5001, 20000, 249000, 59000, '1-2'],
                ['gls', '', 'near_eu', 0, 5000, 399000, 59000, '2-3'],
                ['gls', '', 'eu', 0, 5000, 449000, 59000, '3-5'],
                ['gls', '', 'eu', 5001, 20000, 649000, 59000, '3-5'],
            ],
            'payments' => [
                ['card', 'stripe', 'Bankkártyás fizetés', 'online_card', 0, null, 0, 1, 10],
                ['bank_transfer', 'manual', 'Banki átutalás', 'bank_transfer', 0, null, 0, 0, 20],
                ['cod', 'manual', 'Utánvét', 'cod', 0, 50000000, 49000, 0, 30],
            ],
            'cod_countries' => ['HU'],
        ],
        'pl' => [
            'zones' => [['domestic', 'Krajowa PL', ['PL']], ['near_eu', 'Sąsiednie UE', ['SK', 'CZ', 'DE', 'AT', 'HU', 'LT']]],
            'methods' => [
                ['pickup', 'pickup', 'Odbiór osobisty', 'PersonalPickup', 'pickup', 0, 1, null, null, null, 10],
                ['packeta', 'packeta', 'Packeta', 'PacketaShipping', 'parcel', 1, 1, 25000, 10000, 60000, 20],
                ['gls', 'gls', 'Kurier GLS', 'GlsShipping', 'parcel', 1, 0, 35000, 40000, 160000, 30],
            ],
            'rates' => [
                ['pickup', 'PL', 'domestic', 0, 999999, 0, 0, '0'],
                ['packeta', 'PL', 'domestic', 0, 2000, 1299, 500, '1-2'],
                ['packeta', 'PL', 'domestic', 2001, 5000, 1499, 500, '1-2'],
                ['packeta', 'PL', 'domestic', 5001, 10000, 1899, 500, '1-2'],
                ['gls', 'PL', 'domestic', 0, 2000, 1599, 600, '1-2'],
                ['gls', 'PL', 'domestic', 2001, 5000, 1899, 600, '1-2'],
                ['gls', 'PL', 'domestic', 5001, 20000, 2499, 600, '1-2'],
                ['gls', '', 'near_eu', 0, 5000, 3999, 600, '2-3'],
                ['gls', '', 'eu', 0, 5000, 4999, 600, '3-5'],
                ['gls', '', 'eu', 5001, 20000, 6999, 600, '3-5'],
            ],
            'payments' => [
                ['card', 'stripe', 'Karta płatnicza', 'online_card', 0, null, 0, 1, 10],
                ['bank_transfer', 'manual', 'Przelew bankowy', 'bank_transfer', 0, null, 0, 0, 20],
                ['cod', 'manual', 'Pobranie', 'cod', 0, 500000, 500, 0, 30],
            ],
            'cod_countries' => ['PL'],
        ],
    ];

    public function up(PDO $db, bool $isSqlite): void {
        foreach (self::SCOPES as $scope => $list) {
            if ($this->scopeConfigured($db, $scope)) continue;
            $this->seedScope($db, $scope, $list);
        }
    }

    private function scopeConfigured(PDO $db, string $scope): bool {
        foreach (['shipping_methods', 'payment_methods'] as $table) {
            $stmt = $db->prepare("SELECT 1 FROM {$table} WHERE store_id = ? LIMIT 1");
            $stmt->execute([$scope]);
            if ($stmt->fetchColumn()) return true;
        }
        return false;
    }

    private function seedScope(PDO $db, string $scope, array $list): void {
        $zone = $db->prepare('INSERT INTO shipping_zones (store_id, code, name, description, sort_order) VALUES (?, ?, ?, ?, ?)');
        $zoneCountry = $db->prepare('INSERT INTO shipping_zone_countries (store_id, zone_code, country) VALUES (?, ?, ?)');
        $zoned = [];
        $sort = 10;
        foreach ($list['zones'] as [$code, $name, $countries]) {
            $zone->execute([$scope, $code, $name, '', $sort]);
            $sort += 10;
            foreach ($countries as $country) $zoneCountry->execute([$scope, $code, $country]);
            $zoned = array_merge($zoned, $countries);
        }
        $zone->execute([$scope, 'eu', 'EU', '', $sort]);
        foreach (array_diff(self::EU, $zoned) as $country) $zoneCountry->execute([$scope, 'eu', $country]);

        $method = $db->prepare('INSERT INTO shipping_methods
            (store_id, code, carrier, name, adapter_class, service_type, supports_cod, supports_pickup, free_above, max_weight_g, max_volume_cm3, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($list['methods'] as $row) $method->execute(array_merge([$scope], $row));

        $carriers = array_column($list['methods'], 1, 0);
        $rate = $db->prepare('INSERT INTO shipping_rates
            (store_id, method_code, carrier, country, zone_code, weight_from_g, weight_to_g, price, cod_surcharge, delivery_days, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($list['rates'] as $i => [$code, $country, $zoneCode, $from, $to, $price, $cod, $days]) {
            $rate->execute([$scope, $code, $carriers[$code], $country, $zoneCode, $from, $to, $price, $cod, $days, 10 + $i]);
        }

        $payment = $db->prepare('INSERT INTO payment_methods
            (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, sort_order)
            VALUES (?, ?, ?, ?, \'\', ?, ?, ?, ?, 0, NULL, ?, ?)');
        foreach ($list['payments'] as $row) $payment->execute(array_merge([$scope], $row));

        $paymentCountry = $db->prepare('INSERT INTO payment_method_countries (store_id, method_code, country) VALUES (?, ?, ?)');
        foreach (['card', 'bank_transfer'] as $code) {
            foreach (self::EU as $country) $paymentCountry->execute([$scope, $code, $country]);
        }
        foreach ($list['cod_countries'] as $country) $paymentCountry->execute([$scope, 'cod', $country]);

        $paymentShipping = $db->prepare('INSERT INTO payment_method_shipping (store_id, method_code, shipping_method_code) VALUES (?, ?, ?)');
        foreach (['packeta', 'gls'] as $carrier) $paymentShipping->execute([$scope, 'cod', $carrier]);
    }
};
