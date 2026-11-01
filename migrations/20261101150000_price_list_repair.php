<?php
/**
 * Complete the CZ/HU/PL price lists a pre-round-9 run of 20261022230000_currency_price_lists left half-seeded on
 * MySQL/MariaDB (round 11 review).
 *
 * That version committed every statement on its own; a run failing half-way left, say, the shipping methods and
 * card/bank transfer without cash on delivery or most payment countries. The next run took the scope for the
 * merchant's own and skipped it, so the store never got COD. A scope counts as such a remainder when it holds only
 * rows of the seed (no shipping or payment method of its own) and the seed's last step — the COD ↔ carrier links —
 * never ran; the missing rows are then added. A scope the merchant configured, or one that was seeded in full, is
 * not touched.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $seed = require __DIR__ . '/20261022230000_currency_price_lists.php';
        $scopes = (new ReflectionClassConstant($seed, 'SCOPES'))->getValue();
        $eu = (new ReflectionClassConstant($seed, 'EU'))->getValue();
        $ignore = $isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        foreach ($scopes as $scope => $list) {
            if (!$this->isPartialSeed($db, $scope, $list)) continue;
            $ownsTransaction = !$db->inTransaction();
            if ($ownsTransaction) $db->beginTransaction();
            try {
                $this->complete($db, $scope, $list, $eu, $ignore);
                if ($ownsTransaction) $db->commit();
            } catch (Throwable $e) {
                if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
                throw $e;
            }
        }
    }

    private function isPartialSeed(PDO $db, string $scope, array $list): bool {
        $shipping = $this->column($db, 'SELECT code, adapter_class FROM shipping_methods WHERE store_id = ?', $scope);
        $payments = $this->column($db, 'SELECT code, provider FROM payment_methods WHERE store_id = ?', $scope);
        if ($shipping === [] && $payments === []) return false; // empty: seeded by the price-list migration itself, or emptied on purpose
        $seedShipping = array_column($list['methods'], 3, 0);
        $seedPayments = array_column($list['payments'], 1, 0);
        foreach ($shipping as $code => $adapter) if (($seedShipping[$code] ?? null) !== $adapter) return false;
        foreach ($payments as $code => $provider) if (($seedPayments[$code] ?? null) !== $provider) return false;
        $links = $db->prepare('SELECT COUNT(*) FROM payment_method_shipping WHERE store_id = ?');
        $links->execute([$scope]);
        return (int)$links->fetchColumn() === 0;
    }

    /** @return array<string, string> */
    private function column(PDO $db, string $sql, string $scope): array {
        $stmt = $db->prepare($sql);
        $stmt->execute([$scope]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    private function complete(PDO $db, string $scope, array $list, array $eu, string $ignore): void {
        $zone = $db->prepare("{$ignore} INTO shipping_zones (store_id, code, name, description, sort_order) VALUES (?, ?, ?, ?, ?)");
        $zoneCountry = $db->prepare("{$ignore} INTO shipping_zone_countries (store_id, zone_code, country) VALUES (?, ?, ?)");
        $zoned = [];
        $sort = 10;
        foreach ($list['zones'] as [$code, $name, $countries]) {
            $zone->execute([$scope, $code, $name, '', $sort]);
            $sort += 10;
            foreach ($countries as $country) $zoneCountry->execute([$scope, $code, $country]);
            $zoned = array_merge($zoned, $countries);
        }
        $zone->execute([$scope, 'eu', 'EU', '', $sort]);
        foreach (array_diff($eu, $zoned) as $country) $zoneCountry->execute([$scope, 'eu', $country]);

        $method = $db->prepare("{$ignore} INTO shipping_methods
            (store_id, code, carrier, name, adapter_class, service_type, supports_cod, supports_pickup, free_above, max_weight_g, max_volume_cm3, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($list['methods'] as $row) $method->execute(array_merge([$scope], $row));

        // Rates have no unique key: a seeded rate is added unless the same band is already there (a run may have
        // stopped between two rates of one method).
        $carriers = array_column($list['methods'], 1, 0);
        $exists = $db->prepare('SELECT 1 FROM shipping_rates WHERE store_id = ? AND method_code = ? AND country = ? AND zone_code = ?
            AND weight_from_g = ? AND weight_to_g = ? LIMIT 1');
        $rate = $db->prepare('INSERT INTO shipping_rates
            (store_id, method_code, carrier, country, zone_code, weight_from_g, weight_to_g, price, cod_surcharge, delivery_days, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($list['rates'] as $i => [$code, $country, $zoneCode, $from, $to, $price, $cod, $days]) {
            $exists->execute([$scope, $code, $country, $zoneCode, $from, $to]);
            $found = $exists->fetchColumn() !== false;
            $exists->closeCursor();
            if (!$found) $rate->execute([$scope, $code, $carriers[$code], $country, $zoneCode, $from, $to, $price, $cod, $days, 10 + $i]);
        }

        $payment = $db->prepare("{$ignore} INTO payment_methods
            (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, sort_order)
            VALUES (?, ?, ?, ?, '', ?, ?, ?, ?, 0, NULL, ?, ?)");
        foreach ($list['payments'] as $row) $payment->execute(array_merge([$scope], $row));

        $paymentCountry = $db->prepare("{$ignore} INTO payment_method_countries (store_id, method_code, country) VALUES (?, ?, ?)");
        foreach (['card', 'bank_transfer'] as $code) {
            foreach ($eu as $country) $paymentCountry->execute([$scope, $code, $country]);
        }
        foreach ($list['cod_countries'] as $country) $paymentCountry->execute([$scope, 'cod', $country]);

        $paymentShipping = $db->prepare("{$ignore} INTO payment_method_shipping (store_id, method_code, shipping_method_code) VALUES (?, ?, ?)");
        foreach (['packeta', 'gls'] as $carrier) $paymentShipping->execute([$scope, 'cod', $carrier]);
    }
};
