<?php
/**
 * R36: invoices.oss_net is what an issued invoice or credit note adds to the 10 000 EUR OSS threshold (art. 59c) — the
 * net of its distance sales to consumers of other member states, in its currency, negative on a credit note
 * (OssThreshold::documentNet()). InvoiceService fills it when it issues a document; this fills it for the documents of
 * the current and the previous calendar year, the only ones the threshold counts. Older documents keep NULL.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $columns = $isSqlite
            ? array_column($db->query('PRAGMA table_info(invoices)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('oss_net', $columns, true)) $db->exec('ALTER TABLE invoices ADD COLUMN oss_net BIGINT DEFAULT NULL');
        // Run by an older release's code (its Db without the schema cache reset), the threshold counts the documents
        // from their lines until they are filled.
        if (!class_exists('OssThreshold') || !method_exists('Db', 'forgetSchema') || !in_array('order_id', $columns, true)) return;
        Db::forgetSchema($db);
        $since = sprintf('%04d-01-01', (int)gmdate('Y') - 1);
        $ids = $db->prepare("SELECT id FROM invoices WHERE oss_net IS NULL AND status = 'issued' AND type IN ('invoice', 'credit_note') AND issued_at >= ? ORDER BY id");
        $ids->execute([$since]);
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) OssThreshold::record($db, (int)$id);
    }
};
