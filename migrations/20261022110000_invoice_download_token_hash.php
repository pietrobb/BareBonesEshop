<?php
/**
 * Round 8: invoices.download_token held the e-mailed bearer token in plain text and never expired. The column now holds
 * the token's SHA-256 and download_token_expires_at bounds the link. Links already e-mailed keep working for
 * InvoiceService::DOWNLOAD_LINK_TTL_DAYS (30) days from this migration.
 *
 * Resumable: a row is converted together with its expiry, so a row without an expiry still holds its plain token. A run
 * interrupted on MySQL (no transaction around DDL) is finished by the next one; converted rows are never hashed twice.
 * MySQL hashes in the database in id ranges (SHA2 = PHP's lowercase hex SHA-256), one short statement per range.
 */
return new class {
    private const BATCH = 5000;

    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(invoices)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'")->fetchAll(PDO::FETCH_COLUMN);
        if ($columns === []) return;
        if (!in_array('download_token_expires_at', $columns, true)) {
            $db->exec('ALTER TABLE invoices ADD COLUMN download_token_expires_at ' . ($isSqlite ? 'TEXT' : 'DATETIME') . ' DEFAULT NULL');
        }
        $expires = gmdate('Y-m-d H:i:s', time() + 30 * 86400);
        $pending = "download_token_expires_at IS NULL AND download_token IS NOT NULL AND download_token <> ''";
        if (!$isSqlite) {
            [$min, $max] = $db->query("SELECT MIN(id), MAX(id) FROM invoices WHERE {$pending}")->fetch(PDO::FETCH_NUM);
            if ($min === null) return;
            $update = $db->prepare("UPDATE invoices SET download_token = SHA2(LOWER(download_token), 256), download_token_expires_at = :expires
                WHERE id >= :from AND id < :to AND {$pending}");
            for ($from = (int)$min; $from <= (int)$max; $from += self::BATCH) {
                $update->execute([':expires' => $expires, ':from' => $from, ':to' => $from + self::BATCH]);
            }
            return;
        }
        $update = $db->prepare('UPDATE invoices SET download_token = :hash, download_token_expires_at = :expires WHERE id = :id');
        $after = 0;
        do {
            $read = $db->prepare("SELECT id, download_token FROM invoices WHERE id > :after AND {$pending} ORDER BY id LIMIT 1000");
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
