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

        $db->exec("CREATE TABLE IF NOT EXISTS payment_methods (
            id                    INTEGER PRIMARY KEY{$autoinc},
            code                  TEXT NOT NULL UNIQUE,
            provider              TEXT NOT NULL DEFAULT '',
            name                  TEXT NOT NULL,
            description           TEXT DEFAULT '',
            service_type          TEXT NOT NULL DEFAULT 'offline',
            min_amount            INTEGER NOT NULL DEFAULT 0,
            max_amount            INTEGER DEFAULT NULL,
            fixed_fee             INTEGER NOT NULL DEFAULT 0,
            percent_fee_bps       INTEGER NOT NULL DEFAULT 0,
            free_above            INTEGER DEFAULT NULL,
            requires_online       INTEGER NOT NULL DEFAULT 0,
            active                INTEGER NOT NULL DEFAULT 1,
            sort_order            INTEGER NOT NULL DEFAULT 100,
            created_at            TEXT DEFAULT ({$now}),
            updated_at            TEXT DEFAULT ({$now})
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_payment_methods_active_sort ON payment_methods(active, sort_order)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_payment_methods_provider ON payment_methods(provider)");

        $db->exec("CREATE TABLE IF NOT EXISTS payment_method_countries (
            id           INTEGER PRIMARY KEY{$autoinc},
            method_code  TEXT NOT NULL,
            country      TEXT NOT NULL,
            created_at   TEXT DEFAULT ({$now}),
            UNIQUE(method_code, country)
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_payment_method_countries_lookup ON payment_method_countries(country, method_code)");

        $db->exec("CREATE TABLE IF NOT EXISTS payment_method_shipping (
            id                   INTEGER PRIMARY KEY{$autoinc},
            method_code          TEXT NOT NULL,
            shipping_method_code TEXT NOT NULL,
            created_at           TEXT DEFAULT ({$now}),
            UNIQUE(method_code, shipping_method_code)
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_payment_method_shipping_lookup ON payment_method_shipping(shipping_method_code, method_code)");

        $this->addColumnIfMissing($db, 'orders', 'payment_fee', "INTEGER NOT NULL DEFAULT 0");
        $this->addColumnIfMissing($db, 'orders', 'payment_method_snapshot', "TEXT DEFAULT NULL");

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

    private function addColumnIfMissing(PDO $db, string $table, string $col, string $def): void {
        $exists = false;
        try {
            $rows = $db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                if (($r['name'] ?? '') === $col) { $exists = true; break; }
            }
        } catch (Throwable $e) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_NAME=? AND COLUMN_NAME=?");
            $stmt->execute([$table, $col]);
            $exists = ((int)$stmt->fetchColumn()) > 0;
        }
        if (!$exists) $db->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
    }
};
