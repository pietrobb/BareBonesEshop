<?php
/**
 * Migration: shipping_methods_pricing_engine
 * Generated: 2026-04-25T06:40:00+00:00
 *
 * Adds normalized shipping method/zone/rate configuration while keeping the
 * existing shipping_rates(carrier,country,weight_from_g,weight_to_g,price,...) API
 * compatible with current carrier adapters.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $now = $isSqlite ? "datetime('now')" : "NOW()";
        $autoinc = $isSqlite ? '' : ' AUTO_INCREMENT';
        $short = MigrationSql::short($isSqlite);
        $text = MigrationSql::text($isSqlite);
        $date = MigrationSql::datetime($isSqlite);
        $defaultEmpty = MigrationSql::defaultClause($isSqlite, '');

        $db->exec("CREATE TABLE IF NOT EXISTS shipping_methods (
            id                 INTEGER PRIMARY KEY{$autoinc},
            code               {$short} NOT NULL UNIQUE,
            carrier            {$short} NOT NULL,
            name               {$short} NOT NULL,
            description        {$text} {$defaultEmpty},
            adapter_class      {$short} {$defaultEmpty},
            service_type       {$short} NOT NULL DEFAULT 'parcel',
            supports_cod       INTEGER NOT NULL DEFAULT 0,
            supports_pickup    INTEGER NOT NULL DEFAULT 0,
            free_above         INTEGER DEFAULT NULL,
            max_weight_g       INTEGER DEFAULT NULL,
            max_volume_cm3     INTEGER DEFAULT NULL,
            active             INTEGER NOT NULL DEFAULT 1,
            sort_order         INTEGER NOT NULL DEFAULT 100,
            created_at         {$date} DEFAULT ({$now}),
            updated_at         {$date} DEFAULT ({$now})
        )");
        MigrationSql::index($db, $isSqlite, 'shipping_methods', 'idx_shipping_methods_active_sort', 'active, sort_order');
        MigrationSql::index($db, $isSqlite, 'shipping_methods', 'idx_shipping_methods_carrier', 'carrier');

        $db->exec("CREATE TABLE IF NOT EXISTS shipping_zones (
            id          INTEGER PRIMARY KEY{$autoinc},
            code        {$short} NOT NULL UNIQUE,
            name        {$short} NOT NULL,
            description {$text} {$defaultEmpty},
            active      INTEGER NOT NULL DEFAULT 1,
            sort_order  INTEGER NOT NULL DEFAULT 100,
            created_at  {$date} DEFAULT ({$now}),
            updated_at  {$date} DEFAULT ({$now})
        )");
        MigrationSql::index($db, $isSqlite, 'shipping_zones', 'idx_shipping_zones_active_sort', 'active, sort_order');

        $db->exec("CREATE TABLE IF NOT EXISTS shipping_zone_countries (
            id         INTEGER PRIMARY KEY{$autoinc},
            zone_code  {$short} NOT NULL,
            country    {$short} NOT NULL,
            postal_min {$short} {$defaultEmpty},
            postal_max {$short} {$defaultEmpty},
            created_at {$date} DEFAULT ({$now}),
            UNIQUE(zone_code, country, postal_min, postal_max)
        )");
        MigrationSql::index($db, $isSqlite, 'shipping_zone_countries', 'idx_shipping_zone_countries_lookup', 'country, postal_min, postal_max');

        $db->exec("CREATE TABLE IF NOT EXISTS shipping_rates (
            id            INTEGER PRIMARY KEY{$autoinc},
            carrier       {$short} NOT NULL,
            country       {$short} NOT NULL,
            weight_from_g INTEGER NOT NULL DEFAULT 0,
            weight_to_g   INTEGER NOT NULL DEFAULT 999999,
            price         INTEGER NOT NULL,
            cod_surcharge INTEGER NOT NULL DEFAULT 0,
            created_at    {$date} DEFAULT ({$now})
        )");

        $this->addColumnIfMissing($db, $isSqlite, 'shipping_rates', 'method_code', "{$short} {$defaultEmpty}");
        $this->addColumnIfMissing($db, $isSqlite, 'shipping_rates', 'zone_code', "{$short} {$defaultEmpty}");
        $this->addColumnIfMissing($db, $isSqlite, 'shipping_rates', 'volume_to_cm3', "INTEGER DEFAULT NULL");
        $this->addColumnIfMissing($db, $isSqlite, 'shipping_rates', 'delivery_days', "{$short} {$defaultEmpty}");
        $this->addColumnIfMissing($db, $isSqlite, 'shipping_rates', 'active', "INTEGER NOT NULL DEFAULT 1");
        $this->addColumnIfMissing($db, $isSqlite, 'shipping_rates', 'sort_order', "INTEGER NOT NULL DEFAULT 100");
        $this->addColumnIfMissing($db, $isSqlite, 'shipping_rates', 'updated_at', "{$date} DEFAULT NULL");
        MigrationSql::index($db, $isSqlite, 'shipping_rates', 'idx_srate_method_zone', 'method_code, zone_code, active, weight_from_g, weight_to_g');
        MigrationSql::index($db, $isSqlite, 'shipping_rates', 'idx_srate_country_active', 'country, active');

        $this->addColumnIfMissing($db, $isSqlite, 'orders', 'shipping_method', "{$short} DEFAULT NULL");
        $this->addColumnIfMissing($db, $isSqlite, 'orders', 'shipping_zone', "{$short} DEFAULT NULL");
        $this->addColumnIfMissing($db, $isSqlite, 'orders', 'shipping_weight_g', "INTEGER NOT NULL DEFAULT 0");
        $this->addColumnIfMissing($db, $isSqlite, 'orders', 'shipping_rate_snapshot', "{$text} DEFAULT NULL");

        $this->seedMethods($db, $isSqlite);
        $this->seedZones($db, $isSqlite);
        $this->seedRates($db);
    }

    private function seedMethods(PDO $db, bool $isSqlite): void {
        $rows = [
            ['pickup', 'pickup', 'Osobný odber', 'PersonalPickup', 'pickup', 0, 1, null, null, null, 10],
            ['packeta', 'packeta', 'Packeta / Zásielkovňa', 'PacketaShipping', 'parcel', 1, 1, 5000, 10000, 60000, 20],
            ['gls', 'gls', 'GLS kuriér', 'GlsShipping', 'parcel', 1, 0, 7500, 40000, 160000, 30],
        ];
        $ins = $isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $stmt = $db->prepare("{$ins} INTO shipping_methods
            (code, carrier, name, adapter_class, service_type, supports_cod, supports_pickup, free_above, max_weight_g, max_volume_cm3, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($rows as $row) $stmt->execute($row);
    }

    private function seedZones(PDO $db, bool $isSqlite): void {
        $ins = $isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $zones = [
            ['domestic', 'Domestic SK', 'Slovakia domestic shipping', 10],
            ['cz', 'Czech Republic', 'CZ cross-border shipping', 20],
            ['near_eu', 'Nearby EU', 'AT/HU/PL/DE regional EU shipping', 30],
            ['eu', 'European Union', 'Fallback EU shipping zone', 40],
        ];
        $stmt = $db->prepare("{$ins} INTO shipping_zones (code, name, description, sort_order) VALUES (?, ?, ?, ?)");
        foreach ($zones as $zone) $stmt->execute($zone);

        $countries = [
            ['domestic', 'SK'], ['cz', 'CZ'],
            ['near_eu', 'AT'], ['near_eu', 'HU'], ['near_eu', 'PL'], ['near_eu', 'DE'],
            ['eu', 'FR'], ['eu', 'IT'], ['eu', 'ES'], ['eu', 'NL'], ['eu', 'BE'], ['eu', 'RO'], ['eu', 'HR'], ['eu', 'SI'],
            ['eu', 'BG'], ['eu', 'CY'], ['eu', 'DK'], ['eu', 'EE'], ['eu', 'FI'], ['eu', 'GR'], ['eu', 'IE'], ['eu', 'LT'],
            ['eu', 'LU'], ['eu', 'LV'], ['eu', 'MT'], ['eu', 'PT'], ['eu', 'SE'],
        ];
        $stmt = $db->prepare("{$ins} INTO shipping_zone_countries (zone_code, country) VALUES (?, ?)");
        foreach ($countries as $country) $stmt->execute($country);
    }

    private function seedRates(PDO $db): void {
        $rates = [
            ['pickup', 'pickup', 'SK', 'domestic', 0, 999999, 0, 0, '0', 10],
            ['packeta', 'packeta', 'SK', 'domestic', 0, 2000, 299, 100, '1-2', 20],
            ['packeta', 'packeta', 'SK', 'domestic', 2001, 5000, 349, 100, '1-2', 21],
            ['packeta', 'packeta', 'SK', 'domestic', 5001, 10000, 449, 100, '1-2', 22],
            ['packeta', 'packeta', 'CZ', 'cz', 0, 2000, 399, 100, '1-2', 30],
            ['packeta', 'packeta', 'CZ', 'cz', 2001, 5000, 449, 100, '1-2', 31],
            ['packeta', 'packeta', 'CZ', 'cz', 5001, 10000, 599, 100, '1-2', 32],
            ['gls', 'gls', 'SK', 'domestic', 0, 2000, 399, 150, '1-2', 40],
            ['gls', 'gls', 'SK', 'domestic', 2001, 5000, 499, 150, '1-2', 41],
            ['gls', 'gls', 'SK', 'domestic', 5001, 20000, 699, 150, '1-2', 42],
            ['gls', 'gls', 'CZ', 'cz', 0, 2000, 399, 150, '1-2', 50],
            ['gls', 'gls', 'CZ', 'cz', 2001, 5000, 499, 150, '1-2', 51],
            ['gls', 'gls', 'CZ', 'cz', 5001, 20000, 699, 150, '1-2', 52],
            ['gls', 'gls', 'DE', 'near_eu', 0, 5000, 899, 150, '2-3', 60],
            ['gls', 'gls', 'AT', 'near_eu', 0, 5000, 799, 150, '1-2', 61],
            ['gls', 'gls', 'HU', 'near_eu', 0, 5000, 799, 150, '1-2', 62],
            ['gls', 'gls', 'PL', 'near_eu', 0, 5000, 799, 150, '1-2', 63],
            ['gls', 'gls', '', 'eu', 0, 5000, 1099, 150, '3-5', 70],
            ['gls', 'gls', '', 'eu', 5001, 20000, 1599, 150, '3-5', 71],
        ];
        $stmt = $db->prepare("INSERT INTO shipping_rates
            (method_code, carrier, country, zone_code, weight_from_g, weight_to_g, price, cod_surcharge, delivery_days, sort_order)
            SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            WHERE NOT EXISTS (
                SELECT 1 FROM shipping_rates
                WHERE carrier = ? AND country = ? AND weight_from_g = ? AND weight_to_g = ?
            )");
        foreach ($rates as $rate) {
            $stmt->execute(array_merge($rate, [$rate[1], $rate[2], $rate[4], $rate[5]]));
        }
    }

    private function addColumnIfMissing(PDO $db, bool $isSqlite, string $table, string $col, string $def): void {
        if ($isSqlite) {
            foreach ($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? '') === $col) return;
            }
        } else {
            $stmt = $db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
            $stmt->execute([$table, $col]);
            if ($stmt->fetchColumn()) return;
        }
        $db->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
    }
};
