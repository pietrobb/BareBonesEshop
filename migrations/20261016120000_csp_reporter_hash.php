<?php
/**
 * CSP reports are unauthenticated: a salted hash of the reporting client IP lets the form-action
 * alarm count distinct reporters, so one source posting forged reports cannot raise it alone.
 * Rows written before this migration share the empty reporter and count as one source.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(csp_violations)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'csp_violations'")->fetchAll(PDO::FETCH_COLUMN);
        if ($columns !== [] && !in_array('reporter_hash', $columns, true)) {
            $db->exec('ALTER TABLE csp_violations ADD COLUMN reporter_hash ' . ($isSqlite ? 'TEXT' : 'VARCHAR(64)') . " NOT NULL DEFAULT ''");
        }
    }
};
