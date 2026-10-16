<?php
/**
 * The raw form-action report count (fallback alarm while trusted_proxies is empty) must come from at
 * least two separate report requests: each ingest stores a short random request id on its rows.
 * Rows written before this migration share the empty id and count as one request.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(csp_violations)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'csp_violations'")->fetchAll(PDO::FETCH_COLUMN);
        if ($columns === []) return;
        if (!in_array('request_id', $columns, true)) {
            $db->exec('ALTER TABLE csp_violations ADD COLUMN request_id ' . ($isSqlite ? 'TEXT' : 'VARCHAR(16)') . " NOT NULL DEFAULT ''");
        }
    }
};
