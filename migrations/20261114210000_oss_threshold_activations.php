<?php

// The accounting total can fall after a credit; the fact that a year crossed the OSS threshold cannot.
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $db->exec($isSqlite
            ? 'CREATE TABLE IF NOT EXISTS oss_threshold_activations (seller_key TEXT NOT NULL, year INTEGER NOT NULL, PRIMARY KEY (seller_key, year))'
            : 'CREATE TABLE IF NOT EXISTS oss_threshold_activations (seller_key VARCHAR(64) NOT NULL, year INT NOT NULL, PRIMARY KEY (seller_key, year)) ENGINE=InnoDB');
        OssThreshold::rebuildActivations($db);
    }
};
