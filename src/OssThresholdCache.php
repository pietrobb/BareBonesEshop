<?php

/**
 * Maintenance audit of the cached threshold totals. The whole history only on explicit request (doctor --oss-cache);
 * R49 (low): the ordinary doctor audits the years the threshold decides on (this and last), issued_at-indexed.
 */
final class OssThresholdCache
{
    private static function source(PDO $db, ?int $fromYear = null): string
    {
        // A document counts in the year of its supply (or of its corrected invoice), never after its issue: one issued
        // before $fromYear cannot count in it, so the issued_at index bounds the scan.
        $recent = $fromYear === null ? '' : " AND invoices.issued_at >= '" . sprintf('%04d', $fromYear) . "-01-01'";
        // R38 (medium 4): a credit note in the year of the invoice it corrects (OssThreshold::documentYear()).
        $at = OssThreshold::documentDateSql('invoices', 'source_invoice');
        $year = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "CAST(substr({$at}, 1, 4) AS INTEGER)" : "YEAR({$at})";
        // R40: a document converted to EUR at its issue counts in the EUR total (OssThreshold::record()).
        [$currency, $net] = OssThreshold::storedNetSql($db, 'invoices');
        $sql = "SELECT orders.store_id, {$year} AS year, {$currency} AS currency, SUM({$net}) AS net
            FROM invoices JOIN orders ON orders.id = invoices.order_id LEFT JOIN invoices AS source_invoice ON source_invoice.id = invoices.refers_to_invoice_id
            WHERE invoices.status = 'issued' AND invoices.type IN ('invoice', 'credit_note')
              AND invoices.oss_net IS NOT NULL AND invoices.oss_net <> 0{$recent}
            GROUP BY orders.store_id, {$year}, {$currency}";
        return $fromYear === null ? $sql : "SELECT * FROM ({$sql}) recent_totals WHERE year >= " . (int)$fromYear;
    }

    /** One statement/snapshot, constant-size result; SQL aggregates history only on explicit request.
     * Zero rows are equivalent to absent rows (record/forget may leave a zero). NULL nets belong to
     * the existing line-based fallback, not this cache; neither audit nor repair calculates them.
     */
    public static function check(PDO $db, ?int $fromYear = null): array
    {
        $source = self::source($db, $fromYear);
        [$cached, $fallbackRecent] = $fromYear === null ? ['', ''] : [' WHERE year >= ' . (int)$fromYear, " AND fallback_invoice.issued_at >= '" . sprintf('%04d', $fromYear) . "-01-01'"];
        $row = $db->query("SELECT
            (SELECT COUNT(*) FROM (
                SELECT store_id, year, currency FROM (
                    SELECT store_id, year, currency, net AS expected, 0 AS actual FROM ({$source}) expected_totals
                    UNION ALL SELECT store_id, year, currency, 0 AS expected, net AS actual FROM oss_threshold_totals{$cached}
                ) contributions GROUP BY store_id, year, currency HAVING SUM(expected) <> SUM(actual)
            ) differences) AS mismatches,
            (SELECT COUNT(*) FROM invoices AS fallback_invoice JOIN orders AS fallback_order ON fallback_order.id = fallback_invoice.order_id
                WHERE fallback_invoice.status = 'issued' AND fallback_invoice.type IN ('invoice', 'credit_note') AND fallback_invoice.oss_net IS NULL{$fallbackRecent}) AS null_nets")->fetch(PDO::FETCH_ASSOC);
        return ['mismatches' => (int)$row['mismatches'], 'null_nets' => (int)$row['null_nets']];
    }

    /** $recentOnly: this and last year's totals (the ordinary doctor), else the whole history (doctor --oss-cache). */
    public static function doctorCheck(PDO $db, bool $recentOnly = false): array
    {
        $action = 'Stop and drain ALL web, queue, cron, import and invoice/order writers (maintenance mode alone is not enough), back up the database, then run php cli/setup.php repair-oss-cache --writers-stopped --json. This changes only cached totals, not financial documents or OSS activation facts.';
        try {
            $fromYear = $recentOnly ? (int)ShopTime::date('Y') - 1 : null;
            $result = self::check($db, $fromYear);
            $scope = $fromYear === null ? '' : " in {$fromYear}–" . ($fromYear + 1);
            return ['status' => $result['mismatches'] === 0 ? 'pass' : 'fail', 'name' => 'database.oss_threshold_cache',
                'message' => "OSS cache{$scope}: {$result['mismatches']} inconsistent store/year/currency totals; {$result['null_nets']} issued documents with NULL nets remain in the separate fallback.",
                'action' => $result['mismatches'] === 0 ? '' : $action];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'name' => 'database.oss_threshold_cache', 'message' => 'OSS cache audit failed: ' . $e->getMessage(),
                'action' => 'Verify the database and applied migrations; no repair was attempted.'];
        }
    }

    /**
     * Dedicated idle connection and drained writers required: an already running checkout may have
     * priced against the corrupt cache before our lock. Locks alone cannot repair that snapshot.
     * SQLite reserves the database writer before reading. MySQL uses real table locks (not advisory
     * locks ignored by invoice writers), autocommit=0 and transactional InnoDB tables. LOCK TABLES
     * implicitly commits, so caller transactions/autocommit=0 are refused before touching anything.
     * No TRUNCATE/DDL, document recalculation, activation deletion or order-reservation writes.
     */
    public static function repair(PDO $db, bool $writersStopped = false): array
    {
        if (PHP_SAPI !== 'cli' || !$writersStopped) throw new RuntimeException('Repair requires CLI and explicit confirmation that all writers are stopped and drained.');
        if ($db->inTransaction()) throw new RuntimeException('Repair requires an idle connection, not a caller transaction.');
        if ($db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) throw new RuntimeException('Repair requires PDO exception mode.');
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['sqlite', 'mysql'], true)) throw new RuntimeException('Unsupported repair database.');
        $locked = false;
        $started = false;
        $autocommitChanged = false;
        try {
            if ($driver === 'mysql') {
                if ((int)$db->query('SELECT @@autocommit')->fetchColumn() !== 1) throw new RuntimeException('Repair requires autocommit enabled on entry.');
                $engines = $db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('invoices', 'orders', 'oss_threshold_totals')")->fetchAll(PDO::FETCH_COLUMN);
                if (count($engines) !== 3 || array_filter($engines, static fn($engine): bool => strcasecmp((string)$engine, 'InnoDB') !== 0)) throw new RuntimeException('Repair requires all three tables to use InnoDB.');
                $db->exec('SET autocommit = 0');
                $autocommitChanged = true;
                $db->exec('LOCK TABLES invoices READ, invoices AS source_invoice READ, orders READ, invoices AS fallback_invoice READ, orders AS fallback_order READ, oss_threshold_totals WRITE');
                $locked = true;
                $started = true;
            } else {
                // Raw SQL also works with plain PDO before PHP 8.4 (which does not track this BEGIN).
                $db->exec('BEGIN IMMEDIATE');
                $started = true;
            }
            $before = self::check($db);
            if ($before['mismatches'] !== 0) {
                $db->exec('DELETE FROM oss_threshold_totals');
                $db->exec('INSERT INTO oss_threshold_totals (store_id, year, currency, net) ' . self::source($db));
            }
            $after = self::check($db);
            if ($after['mismatches'] !== 0) throw new RuntimeException('Rebuilt OSS cache failed verification.');
            $db->exec('COMMIT');
            $started = false;
            OssThreshold::reset();
            return ['status' => 'repaired', 'changed' => $before['mismatches'] !== 0, 'before' => $before, 'after' => $after];
        } finally {
            try {
                if ($started) $db->exec('ROLLBACK');
            } finally {
                try {
                    if ($locked) $db->exec('UNLOCK TABLES');
                } finally {
                    if ($autocommitChanged) $db->exec('SET autocommit = 1');
                }
            }
        }
    }
}
