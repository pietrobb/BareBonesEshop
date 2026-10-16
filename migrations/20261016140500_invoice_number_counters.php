<?php
/**
 * Invoice and credit-note numbering per legal seller (store) and document type.
 *
 * invoice_counters was keyed by (year, prefix) only: every store and both document types that shared a prefix
 * (all credit notes used the default 'CN-') drew from one sequence, so one seller's series had gaps that another
 * seller's documents filled. InvoiceService now numbers from invoice_number_counters, keyed by
 * (store_id, type, year, prefix). invoice_counters is left untouched as the record of the old shared sequences
 * (a new table instead of a rebuild: no DROP of a live table, nothing to resume after a failed MySQL DDL step).
 *
 * Backfill of the shared rows (issued numbers can never be renumbered, so history keeps its interleaving):
 * - each (year, prefix) row keeps its last_number and gets exactly ONE owner (seller, document type), so the owner's
 *   next number is above every number ever issued under that prefix and year, and no number can be issued twice;
 * - the owner is the seller that owns the prefix by configuration (InvoiceService::configuredSeries(): '' and 'CN-'
 *   belong to the default store's seller), however many documents other stores issued under it. Stores without
 *   their own legal block, and stores whose legal.seller names that seller, invoice as that seller
 *   (InvoiceService::sellerCode()), so they keep numbering from the SAME counter;
 * - a prefix no seller configures any more goes to the seller that issued documents under it (the default store's
 *   seller first, else the one with the most documents, with the type it issued most), else to the default seller,
 *   so its numbers are not reused either;
 * - every other seller starts fresh only under a prefix nobody issued from that year. A seller with its own legal
 *   block that shared a prefix with the owner is refused by InvoiceService (and reported by cli/setup.php doctor)
 *   until it has its own invoice_prefix / credit_note_prefix (the default credit-note prefix is "<invoice_prefix>CN-").
 * Idempotent: rows already in invoice_number_counters are kept.
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        $store = $isSqlite ? 'TEXT' : 'VARCHAR(64)';
        $type = $isSqlite ? 'TEXT' : 'VARCHAR(32)';
        $prefix = MigrationSql::short($isSqlite);
        $db->exec("CREATE TABLE IF NOT EXISTS invoice_number_counters (
            store_id {$store} NOT NULL,
            type {$type} NOT NULL DEFAULT 'invoice',
            year INTEGER NOT NULL,
            prefix {$prefix} NOT NULL DEFAULT '',
            last_number INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (store_id, type, year, prefix)
        )");
        if (!$this->tableExists($db, $isSqlite, 'invoice_counters') || !$this->tableExists($db, $isSqlite, 'invoices')) return;

        $legacy = $db->query('SELECT year, prefix, MAX(last_number) AS last_number FROM invoice_counters GROUP BY year, prefix ORDER BY year, prefix')->fetchAll(PDO::FETCH_ASSOC);
        $exists = $db->prepare('SELECT 1 FROM invoice_number_counters WHERE year = :year AND prefix = :prefix');
        $insert = $db->prepare('INSERT INTO invoice_number_counters (store_id, type, year, prefix, last_number) VALUES (:store, :type, :year, :prefix, :last)');
        foreach ($legacy as $row) {
            if ((int)$row['last_number'] <= 0) continue;
            $exists->execute([':year' => (int)$row['year'], ':prefix' => (string)$row['prefix']]);
            $already = $exists->fetchColumn();
            $exists->closeCursor();
            if ($already) continue;
            [$ownerStore, $ownerType] = $this->owner($db, (int)$row['year'], (string)$row['prefix']);
            $insert->execute([':store' => $ownerStore, ':type' => $ownerType, ':year' => (int)$row['year'], ':prefix' => (string)$row['prefix'], ':last' => (int)$row['last_number']]);
        }
    }

    /** [seller store_id, type] owning a legacy (year, prefix) sequence. */
    private function owner(PDO $db, int $year, string $prefix): array {
        $root = $this->storesRoot();
        $configured = class_exists('InvoiceService') ? InvoiceService::configuredSeries($root) : [];
        if (isset($configured[$prefix])) return $configured[$prefix];
        $default = $this->sellerOf($root, (string)($root['default_store'] ?? 'default'));
        $stmt = $db->prepare("SELECT store_id, type, number FROM invoices WHERE number LIKE :pattern ESCAPE '!'");
        $stmt->execute([':pattern' => strtr($prefix, ['!' => '!!', '%' => '!%', '_' => '!_']) . $year . '%']);
        $counts = [];
        $pattern = '/^' . preg_quote($prefix, '/') . $year . '\d{4,}$/';
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $invoice) {
            if (!preg_match($pattern, (string)$invoice['number'])) continue;
            $seller = $this->sellerOf($root, (string)($invoice['store_id'] ?? '') ?: $default);
            $docType = (string)($invoice['type'] ?? '') ?: 'invoice';
            $counts[$seller][$docType] = ($counts[$seller][$docType] ?? 0) + 1;
        }
        if ($counts !== []) {
            $ownerStore = $default;
            if (!isset($counts[$default])) {
                $totals = array_map('array_sum', $counts);
                uksort($totals, static fn($a, $b): int => [$totals[$b], (string)$a] <=> [$totals[$a], (string)$b]);
                $ownerStore = (string)array_key_first($totals);
            }
            $types = $counts[$ownerStore];
            arsort($types);
            return [$ownerStore, (string)array_key_first($types)];
        }
        return [$default, $prefix === 'CN-' ? 'credit_note' : 'invoice'];
    }

    private function storesRoot(): array {
        if (class_exists('InvoiceService')) return InvoiceService::storesRoot();
        return class_exists('Bootstrap') ? Bootstrap::stores() : [];
    }

    /** The seller a store's documents number under (InvoiceService::sellerCode(); the store itself when unknown). */
    private function sellerOf(array $root, string $storeId): string {
        return (class_exists('InvoiceService') ? InvoiceService::sellerCode($root, $storeId) : null) ?? $storeId;
    }

    private function tableExists(PDO $db, bool $isSqlite, string $table): bool {
        $stmt = $db->prepare($isSqlite
            ? "SELECT 1 FROM sqlite_master WHERE type='table' AND name=?"
            : 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $found = (bool)$stmt->fetchColumn();
        $stmt->closeCursor();
        return $found;
    }
};
