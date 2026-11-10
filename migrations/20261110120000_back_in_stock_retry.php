<?php
/**
 * R27: one retry series per "back in stock" request. A notice the shop could not deliver yet (written to the log while
 * the mail provider is misconfigured, or held back by the recipient's rate limit) keeps its request with the time it is
 * tried again (next_try_at) and the tries so far (mail_tries); a restock meanwhile does not start another series.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(back_in_stock_requests)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'back_in_stock_requests'")->fetchAll(PDO::FETCH_COLUMN);
        $date = MigrationSql::datetime($isSqlite);
        if (!in_array('mail_tries', $columns, true)) $db->exec('ALTER TABLE back_in_stock_requests ADD COLUMN mail_tries INTEGER NOT NULL DEFAULT 0');
        if (!in_array('next_try_at', $columns, true)) $db->exec("ALTER TABLE back_in_stock_requests ADD COLUMN next_try_at {$date} NULL");
    }
};
