<?php
/**
 * Evidence of VAT id checks. vat_id_cache keeps only the latest answer per VAT id (and expires it), so the VIES
 * answer a reverse-charge invoice relied on was overwritten by the next check. Every live check now also appends
 * a row here, kept with the accounting documents.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $short = MigrationSql::short($isSqlite);
        $text = MigrationSql::text($isSqlite);
        $date = MigrationSql::datetime($isSqlite);
        $id = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $db->exec("CREATE TABLE IF NOT EXISTS vat_id_checks (
            id {$id},
            vat_id {$short} NOT NULL,
            country {$short} DEFAULT NULL,
            status {$short} NOT NULL,
            trader_name {$text},
            trader_addr {$text},
            request_identifier {$short} DEFAULT NULL,
            checked_at {$date} NOT NULL,
            raw_response {$text}
        )");
        MigrationSql::index($db, $isSqlite, 'vat_id_checks', 'idx_vat_id_checks_vat', 'vat_id, checked_at');
    }
};
