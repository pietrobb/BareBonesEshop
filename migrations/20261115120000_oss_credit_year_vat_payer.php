<?php
/**
 * R38 (medium 4, 5): a credit note counts toward the OSS threshold in the year of the invoice it corrects, and the
 * threshold is the VAT payer's (IČ DPH), not a number series'; (low) a document counts by its date of supply. Recounts the cached totals by the corrected invoice's
 * year (as OssThresholdCache::repair() would) and records the activations under the VAT payer's key as well.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        // R38 (low): by the date of supply (delivery_date), else the issue date (OssThreshold::documentDateSql()).
        $at = "CASE WHEN i.type = 'credit_note' AND s.issued_at IS NOT NULL THEN COALESCE(NULLIF(s.delivery_date, ''), s.issued_at)"
            . " ELSE COALESCE(NULLIF(i.delivery_date, ''), i.issued_at) END";
        $year = $isSqlite ? "CAST(substr({$at}, 1, 4) AS INTEGER)" : "YEAR({$at})";
        $db->exec('DELETE FROM oss_threshold_totals');
        $db->exec("INSERT INTO oss_threshold_totals (store_id, year, currency, net)
            SELECT o.store_id, {$year}, LOWER(i.currency), SUM(i.oss_net) FROM invoices i JOIN orders o ON o.id = i.order_id
              LEFT JOIN invoices s ON s.id = i.refers_to_invoice_id
             WHERE i.status = 'issued' AND i.type IN ('invoice', 'credit_note') AND i.oss_net IS NOT NULL AND i.oss_net <> 0
             GROUP BY o.store_id, {$year}, LOWER(i.currency)");
        // Run by an older release's code, the activations keep their keys (that release reads them so).
        if (!class_exists('OssThreshold') || !method_exists('OssThreshold', 'rekeyActivations')) return;
        OssThreshold::rekeyActivations($db);
        // R38 (medium 1): drop the next-year activations c1fe7a9 recorded for sales taxed abroad because of a past year.
        OssThreshold::rebuildActivations($db, true);
    }
};
