<?php
/**
 * - invoices.seller_code: the seller (stores.json store code) whose number series numbered the document, so a credit
 *   note continues its source invoice's seller and series even when the order's store invoices as another seller
 *   now. Rows issued before stay NULL; InvoiceService infers their seller from the number prefix or seller snapshot.
 * - digital_entitlements.last_download_offset / resume_count: how far the last counted download got (bytes served
 *   from the start of the file) and how many free resumes it used. A Range resume is free only up to that offset
 *   and only a few times (DigitalDelivery::MAX_FREE_RESUMES), so "Range: bytes=1-" is no unlimited free download.
 * Each table is altered only when it exists; code before this migration degrades (see Db::hasColumn() guards).
 */
return new class {
    private const COLUMNS = [
        'invoices' => [
            'seller_code' => ['TEXT', 'VARCHAR(64) NULL'],
        ],
        'digital_entitlements' => [
            'last_download_offset' => ['INTEGER', 'BIGINT NULL'],
            'resume_count'         => ['INTEGER NOT NULL DEFAULT 0', 'INT NOT NULL DEFAULT 0'],
        ],
    ];

    public function up(PDO $db, bool $isSqlite): void {
        foreach (self::COLUMNS as $table => $columns) {
            if ($isSqlite) {
                $existing = array_column($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
            } else {
                $stmt = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                $stmt->execute([$table]);
                $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            if ($existing === []) continue;
            foreach ($columns as $column => [$sqliteDef, $mysqlDef]) {
                if (!in_array($column, $existing, true)) {
                    $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} " . ($isSqlite ? $sqliteDef : $mysqlDef));
                }
            }
        }
    }
};
