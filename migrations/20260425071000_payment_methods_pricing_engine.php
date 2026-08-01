<?php
/**
 * Migration: payment_methods_pricing_engine
 * Generated: 2026-04-25T07:10:00+00:00
 *
 * Adds normalized payment method availability/pricing configuration and
 * order-level payment snapshot fields for checkout.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $now = $isSqlite ? "datetime('now')" : "NOW()";
        $autoinc = $isSqlite ? '' : ' AUTO_INCREMENT';
        $short = MigrationSql::short($isSqlite);
        $text = MigrationSql::text($isSqlite);
        $date = MigrationSql::datetime($isSqlite);

        $db->exec("CREATE TABLE IF NOT EXISTS payment_methods (
            id                    INTEGER PRIMARY KEY{$autoinc},
            code                  {$short} NOT NULL UNIQUE,
            provider              {$short} NOT NULL DEFAULT '',
            name                  {$short} NOT NULL,
            description           {$text} DEFAULT '',
            service_type          {$short} NOT NULL DEFAULT 'offline',
            min_amount            INTEGER NOT NULL DEFAULT 0,
            max_amount            INTEGER DEFAULT NULL,
            fixed_fee             INTEGER NOT NULL DEFAULT 0,
            percent_fee_bps       INTEGER NOT NULL DEFAULT 0,
            free_above            INTEGER DEFAULT NULL,
            requires_online       INTEGER NOT NULL DEFAULT 0,
            active                INTEGER NOT NULL DEFAULT 1,
            sort_order            INTEGER NOT NULL DEFAULT 100,
            created_at            {$date} DEFAULT ({$now}),
            updated_at            {$date} DEFAULT ({$now})
        )");
        MigrationSql::index($db, $isSqlite, 'payment_methods', 'idx_payment_methods_active_sort', 'active, sort_order');
        MigrationSql::index($db, $isSqlite, 'payment_methods', 'idx_payment_methods_provider', 'provider');

        $db->exec("CREATE TABLE IF NOT EXISTS payment_method_countries (
            id           INTEGER PRIMARY KEY{$autoinc},
            method_code  {$short} NOT NULL,
            country      {$short} NOT NULL,
            created_at   {$date} DEFAULT ({$now}),
            UNIQUE(method_code, country)
        )");
        MigrationSql::index($db, $isSqlite, 'payment_method_countries', 'idx_payment_method_countries_lookup', 'country, method_code');

        $db->exec("CREATE TABLE IF NOT EXISTS payment_method_shipping (
            id                   INTEGER PRIMARY KEY{$autoinc},
            method_code          {$short} NOT NULL,
            shipping_method_code {$short} NOT NULL,
            created_at           {$date} DEFAULT ({$now}),
            UNIQUE(method_code, shipping_method_code)
        )");
        MigrationSql::index($db, $isSqlite, 'payment_method_shipping', 'idx_payment_method_shipping_lookup', 'shipping_method_code, method_code');

        $this->addColumnIfMissing($db, $isSqlite, 'orders', 'payment_fee', "INTEGER NOT NULL DEFAULT 0");
        $this->addColumnIfMissing($db, $isSqlite, 'orders', 'payment_method_snapshot', "{$text} DEFAULT NULL");

        $this->seedMethods($db, $isSqlite);
        $this->seedCountries($db, $isSqlite);
        $this->seedShippingRestrictions($db, $isSqlite);
    }

    private function seedMethods(PDO $db, bool $isSqlite): void {
        $ins = $isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $rows = [
            ['card', 'stripe', 'Kartou online', 'Online card payment via configured gateway.', 'online_card', 0, null, 0, 0, null, 1, 10],
            ['bank_transfer', 'manual', 'Bankový prevod', 'Manual bank transfer after order creation.', 'bank_transfer', 0, null, 0, 0, null, 0, 20],
            ['cod', 'manual', 'Dobierka', 'Cash on delivery where the selected carrier supports COD.', 'cod', 0, 100000, 120, 0, null, 0, 30],
        ];
        $stmt = $db->prepare("{$ins} INTO payment_methods
            (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($rows as $row) $stmt->execute($row);
    }

    private function seedCountries(PDO $db, bool $isSqlite): void {
        $ins = $isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $countries = ['SK', 'CZ', 'AT', 'HU', 'PL', 'DE', 'FR', 'IT', 'ES', 'NL', 'BE', 'RO', 'HR', 'SI', 'EE', 'LV', 'LT', 'DK', 'SE', 'FI', 'PT', 'GR', 'IE'];
        $stmt = $db->prepare("{$ins} INTO payment_method_countries (method_code, country) VALUES (?, ?)");
        foreach (['card', 'bank_transfer'] as $method) {
            foreach ($countries as $country) $stmt->execute([$method, $country]);
        }
        foreach (['SK', 'CZ'] as $country) $stmt->execute(['cod', $country]);
    }

    private function seedShippingRestrictions(PDO $db, bool $isSqlite): void {
        $ins = $isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $rows = [
            ['cod', 'packeta'],
            ['cod', 'gls'],
        ];
        $stmt = $db->prepare("{$ins} INTO payment_method_shipping (method_code, shipping_method_code) VALUES (?, ?)");
        foreach ($rows as $row) $stmt->execute($row);
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
