<?php

// R44 (low 6): eur_exchange_rates.forced marks the rates a feed only php cli/eur-rates.php --force let through. They are
// today's rates for the threshold (EurRates::current()), but no document is fixed in EUR at them (EurRates::rateOn()).
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(eur_exchange_rates)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eur_exchange_rates'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('forced', $columns, true)) $db->exec('ALTER TABLE eur_exchange_rates ADD COLUMN forced SMALLINT NOT NULL DEFAULT 0');
        if (method_exists('Db', 'forgetSchema')) Db::forgetSchema($db);
    }
};
