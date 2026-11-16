<?php

// R39 (low): the ECB reference rates cli/eur-rates.php stores (EurRates); config/vat-rates.json keeps the fallback.
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $db->exec($isSqlite
            ? 'CREATE TABLE IF NOT EXISTS eur_exchange_rates (currency TEXT NOT NULL PRIMARY KEY, rate REAL NOT NULL, as_of TEXT NOT NULL)'
            : 'CREATE TABLE IF NOT EXISTS eur_exchange_rates (currency VARCHAR(3) NOT NULL PRIMARY KEY, rate DOUBLE NOT NULL, as_of CHAR(10) NOT NULL) ENGINE=InnoDB');
    }
};
