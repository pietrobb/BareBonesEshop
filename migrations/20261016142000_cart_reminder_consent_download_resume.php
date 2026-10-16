<?php
/**
 * Abandoned-cart reminders need the shopper's consent, recorded with the cart (key, version, text
 * and time of the checkout consent "cart_reminder"); carts without it are never reminded.
 * Digital downloads remember when a download was last counted, so an interrupted transfer can
 * resume (HTTP Range) for a short window without consuming another download.
 * Each table is altered only when it exists (installs without the feature keep working).
 */
return new class {
    private const COLUMNS = [
        'abandoned_carts' => [
            'reminder_consent_version' => ['TEXT', 'VARCHAR(64) NULL'],
            'reminder_consent_text'    => ['TEXT', 'TEXT NULL'],
            'reminder_consent_at'      => ['TEXT', 'DATETIME NULL'],
        ],
        'digital_entitlements' => [
            'last_download_at' => ['INTEGER', 'BIGINT NULL'],
        ],
    ];

    public function up(PDO $db, bool $isSqlite): void {
        foreach (self::COLUMNS as $table => $columns) {
            $existing = $isSqlite
                ? array_column($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name')
                : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'")->fetchAll(PDO::FETCH_COLUMN);
            if ($existing === []) continue;
            foreach ($columns as $column => [$sqliteDef, $mysqlDef]) {
                if (!in_array($column, $existing, true)) {
                    $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} " . ($isSqlite ? $sqliteDef : $mysqlDef));
                }
            }
        }
    }
};
