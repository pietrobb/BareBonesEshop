<?php
/**
 * Round 8: invoices.download_token held the e-mailed bearer token in plain text and never expired. The column now holds
 * the token's SHA-256 and download_token_expires_at bounds the link. Links already e-mailed keep working for
 * InvoiceService::DOWNLOAD_LINK_TTL_DAYS (30) days from this migration.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(invoices)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'")->fetchAll(PDO::FETCH_COLUMN);
        if ($columns === [] || in_array('download_token_expires_at', $columns, true)) return;
        $db->exec('ALTER TABLE invoices ADD COLUMN download_token_expires_at ' . ($isSqlite ? 'TEXT' : 'DATETIME') . ' DEFAULT NULL');
        $expires = gmdate('Y-m-d H:i:s', time() + 30 * 86400);
        $update = $db->prepare('UPDATE invoices SET download_token = :hash, download_token_expires_at = :expires WHERE id = :id');
        $after = 0;
        do {
            $read = $db->prepare('SELECT id, download_token FROM invoices WHERE id > :after ORDER BY id LIMIT 1000');
            $read->execute([':after' => $after]);
            $rows = $read->fetchAll(PDO::FETCH_ASSOC);
            $read->closeCursor();
            foreach ($rows as $row) {
                $after = (int)$row['id'];
                $update->execute([':hash' => hash('sha256', strtolower((string)$row['download_token'])), ':expires' => $expires, ':id' => $after]);
            }
        } while (count($rows) === 1000);
    }
};
