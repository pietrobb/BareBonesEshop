<?php

// R41 (medium 3): the ECB rates of past days (EurRates::rateOn()): a document in another currency counts in EUR at the rate
// of its day, which a stale or configured rate never stands in for. Seeded with the stored daily rates.
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $db->exec($isSqlite
            ? 'CREATE TABLE IF NOT EXISTS eur_exchange_rate_history (currency TEXT NOT NULL, as_of TEXT NOT NULL, rate REAL NOT NULL, PRIMARY KEY (currency, as_of))'
            : 'CREATE TABLE IF NOT EXISTS eur_exchange_rate_history (currency VARCHAR(3) NOT NULL, as_of CHAR(10) NOT NULL, rate DOUBLE NOT NULL, PRIMARY KEY (currency, as_of)) ENGINE=InnoDB');
        $db->exec('INSERT INTO eur_exchange_rate_history (currency, as_of, rate) SELECT r.currency, r.as_of, r.rate FROM eur_exchange_rates r
            WHERE NOT EXISTS (SELECT 1 FROM eur_exchange_rate_history h WHERE h.currency = r.currency AND h.as_of = r.as_of)');
    }
};
