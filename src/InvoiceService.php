<?php
/**
 * InvoiceService — issue, render, persist invoices.
 *
 * Once an invoice is issued for an order it is IMMUTABLE. Operator changes
 * to seller details (stores.json.legal) only affect FUTURE invoices —
 * past invoices keep the snapshot frozen at issuance time.
 *
 * To correct an invoice, issue a separate "credit note" (P1; not in v1).
 *
 * PDF generation: uses MinimalPdf (built-in). For full Unicode quality
 * (proper sk/cs/pl/hu diakritika), drop mPDF into vendor/mpdf/ — this
 * service will detect mpdf/autoload.php and route through Mpdf. See
 * vendor/mpdf/README.md.
 *
 * Numbering: per-(year, prefix) monotonic via invoice_counters table.
 * Format: {prefix}{YYYY}{NNNN}, e.g. "20260001", "DE-20260001".
 *
 * Storage: configured private invoice_storage_dir, outside the webroot.
 * Customer access via signed token (invoices.download_token).
 */
final class InvoiceService
{
    private static ?string $storageDir = null;

    public const STATUS_ISSUED    = 'issued';
    public const STATUS_CANCELLED = 'cancelled';

    /** EU member states for reverse-charge detection. */
    private const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES',
        'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
        'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'GR',
    ];

    /**
     * Issue an invoice for a paid order. Idempotent: re-calling for the
     * same order_id returns the existing invoice.
     *
     * @return array Invoice row.
     */
    public static function issue(int $orderId, ?string $storeCode = null): array
    {
        $existing = self::byOrderId($orderId);
        if ($existing !== null) {
            return $existing;
        }

        $order = Orders::byId($orderId);
        if (!$order) {
            throw new \RuntimeException("Order #{$orderId} not found");
        }

        $items  = Orders::items($orderId);
        $store  = self::resolveStore($order, $storeCode);
        $legal  = $store['legal'] ?? [];

        $sellerSnapshot   = self::sellerSnapshot($legal);
        $customerSnapshot = self::customerSnapshot($order);
        $financialSnapshot = self::financialSnapshot($order);
        $vatBreakdown     = self::computeVatBreakdown($order, $items, $financialSnapshot);
        $locale           = self::resolveLocale($order, $store);
        $issuedAt         = date('Y-m-d');
        $deliveryDate     = $issuedAt;
        $paymentTermsDays = (int)($legal['payment_terms_days'] ?? 14);
        $dueDate          = date('Y-m-d', strtotime("+{$paymentTermsDays} days"));

        $db = Db::get();
        $db->beginTransaction();
        try {
            // Allocate next number atomically.
            $year   = (int)date('Y', strtotime($issuedAt));
            $prefix = (string)($legal['invoice_prefix'] ?? '');
            $number = self::allocateNumber($db, $year, $prefix);

            $token  = bin2hex(random_bytes(32));

            $relPath = sprintf('%d/%02d/%s.pdf',
                $year, (int)date('m', strtotime($issuedAt)), rawurlencode($number));
            $absPath = self::resolvePdfPath($relPath);

            $stmt = $db->prepare("
                INSERT INTO invoices (
                    order_id, number, issued_at, delivery_date, due_date, pdf_path,
                    total_net, total_vat, total_gross, currency,
                    vat_breakdown, customer_snapshot, seller_snapshot,
                    payment_method, locale, status, download_token,
                    vat_scenario, reverse_charge_note_key, financial_snapshot
                ) VALUES (
                    :order_id, :number, :issued_at, :delivery_date, :due_date, :pdf_path,
                    :total_net, :total_vat, :total_gross, :currency,
                    :vat_breakdown, :customer_snapshot, :seller_snapshot,
                    :payment_method, :locale, :status, :download_token,
                    :vat_scenario, :note_key, :financial_snapshot
                )
            ");
            $stmt->execute([
                ':order_id'          => $orderId,
                ':number'            => $number,
                ':issued_at'         => $issuedAt,
                ':delivery_date'     => $deliveryDate,
                ':due_date'          => $dueDate,
                ':pdf_path'          => $relPath,
                // Invariant: net + VAT = gross, where gross is what the customer
                // actually paid (order.total). net is the reconciled remainder so
                // the three totals always tie out on the invoice.
                ':total_net'         => (int)$order['total'] - (int)$vatBreakdown['vat_total'],
                ':total_vat'         => (int)$vatBreakdown['vat_total'],
                ':total_gross'       => (int)$order['total'],
                ':currency'          => (string)($order['currency'] ?? 'eur'),
                ':vat_breakdown'     => json_encode($vatBreakdown['rates'], JSON_UNESCAPED_UNICODE),
                ':customer_snapshot' => json_encode($customerSnapshot, JSON_UNESCAPED_UNICODE),
                ':seller_snapshot'   => json_encode($sellerSnapshot, JSON_UNESCAPED_UNICODE),
                ':payment_method'    => (string)($order['payment_method'] ?? ''),
                ':locale'            => $locale,
                ':status'            => self::STATUS_ISSUED,
                ':download_token'    => $token,
                ':vat_scenario'      => $vatBreakdown['scenario'] ?? null,
                ':note_key'          => $vatBreakdown['note_key'] ?? null,
                ':financial_snapshot'=> $financialSnapshot === null ? null : json_encode($financialSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            $invoiceId = (int)$db->lastInsertId();

            $db->commit();
        } catch (\PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            // Race lost: a concurrent issue() inserted the invoice between our
            // byOrderId() check and the INSERT — the uq_invoices_order_invoice
            // unique index fired (SQLSTATE 23000). Return the winner's invoice
            // (idempotent semantics). Counter increment rolled back with the tx.
            if ((string)$e->getCode() === '23000') {
                $existing = self::byOrderId($orderId);
                if ($existing !== null) {
                    return $existing;
                }
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        // Render PDF AFTER successful commit (filesystem is non-transactional).
        $invoice = self::byId($invoiceId);
        $pdfBin  = self::renderPdf($invoice, $order, $items, $sellerSnapshot, $customerSnapshot, $vatBreakdown);
        self::writePdf($absPath, $pdfBin);

        return $invoice;
    }

    /** Re-render PDF for an existing invoice (e.g. file accidentally deleted). */
    public static function regeneratePdf(int $invoiceId): string
    {
        $invoice = self::byId($invoiceId);
        if (!$invoice) throw new \RuntimeException("Invoice #{$invoiceId} not found");

        $order   = Orders::byId((int)$invoice['order_id']);
        $items   = Orders::items((int)$invoice['order_id']);
        $sellerSnap = json_decode((string)$invoice['seller_snapshot'], true) ?: [];
        $custSnap   = json_decode((string)$invoice['customer_snapshot'], true) ?: [];
        $vatRates   = json_decode((string)$invoice['vat_breakdown'], true) ?: [];
        $vatBreakdown = ['rates' => $vatRates, 'vat_total' => (int)$invoice['total_vat']];

        $pdfBin = self::renderPdf($invoice, $order ?: [], $items, $sellerSnap, $custSnap, $vatBreakdown);
        $absPath = self::resolvePdfPath((string)$invoice['pdf_path']);
        self::writePdf($absPath, $pdfBin);
        return $absPath;
    }

    public static function cancel(int $invoiceId, ?string $note = null): void
    {
        $now = Db::isSqlite() ? "datetime('now')" : 'NOW()';
        $stmt = Db::get()->prepare("
            UPDATE invoices
               SET status = :status, cancelled_at = {$now}
             WHERE id = :id AND status = :issued
        ");
        $stmt->execute([
            ':status' => self::STATUS_CANCELLED,
            ':issued' => self::STATUS_ISSUED,
            ':id'     => $invoiceId,
        ]);
        AuditLog::log('invoice_cancelled', $invoiceId, self::STATUS_ISSUED, self::STATUS_CANCELLED, $note);
    }

    public static function byId(int $id): ?array
    {
        $stmt = Db::get()->prepare("SELECT * FROM invoices WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function byOrderId(int $orderId): ?array
    {
        // Prefer the live invoice over voided 'duplicate'/'credit_note' rows.
        $stmt = Db::get()->prepare("SELECT * FROM invoices WHERE order_id = :oid
            ORDER BY CASE WHEN type = 'invoice' THEN 0 ELSE 1 END, id LIMIT 1");
        $stmt->execute([':oid' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    public static function byNumber(string $number): ?array
    {
        $stmt = Db::get()->prepare("SELECT * FROM invoices WHERE number = :n");
        $stmt->execute([':n' => $number]);
        return $stmt->fetch() ?: null;
    }

    public static function byToken(string $token): ?array
    {
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            return null;
        }
        $stmt = Db::get()->prepare("SELECT * FROM invoices WHERE download_token = :t");
        $stmt->execute([':t' => $token]);
        return $stmt->fetch() ?: null;
    }

    /** List invoices for a customer (logged-in scope). */
    public static function forCustomer(int $customerId, int $limit = 50): array
    {
        $stmt = Db::get()->prepare("
            SELECT i.* FROM invoices i
            JOIN orders o ON o.id = i.order_id
            WHERE o.customer_id = :cid
            ORDER BY i.id DESC LIMIT :lim
        ");
        $stmt->bindValue(':cid', $customerId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Admin paginated list. */
    public static function adminList(?int $year = null, int $offset = 0, int $limit = 50): array
    {
        $where = [];
        $params = [];
        if ($year !== null) {
            $where[] = "substr(number, instr(number, '-')+1, 4) = :y_pref OR (instr(number, '-')=0 AND substr(number, 1, 4) = :y)";
            $params[':y'] = (string)$year;
            $params[':y_pref'] = (string)$year;
        }
        $sql = "SELECT * FROM invoices";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY id DESC LIMIT :lim OFFSET :off";
        $stmt = Db::get()->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function pdfAbsolutePath(array $invoice): string
    {
        return self::resolvePdfPath((string)($invoice['pdf_path'] ?? ''));
    }

    /**
     * Reverse-charge applies if (a) seller and customer are in different EU countries
     * and (b) customer has a VAT ID (B2B intra-community supply).
     */
    public static function isReverseCharge(array $sellerSnap, array $customerSnap): bool
    {
        $sCountry = strtoupper((string)($sellerSnap['country'] ?? ''));
        $cCountry = strtoupper((string)($customerSnap['country'] ?? ''));
        $cVat     = trim((string)($customerSnap['ic_dph'] ?? ''));

        if ($sCountry === '' || $cCountry === '' || $sCountry === $cCountry) return false;
        if ($cVat === '') return false;
        return in_array($sCountry, self::EU_COUNTRIES, true) && in_array($cCountry, self::EU_COUNTRIES, true);
    }

    // ──────────────────────────────────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────────────────────────────────

    private static function allocateNumber(PDO $db, int $year, string $prefix): string
    {
        // INSERT IF NOT EXISTS — driver-aware (INSERT OR IGNORE / INSERT IGNORE).
        $db->prepare(Db::insertIgnore() . " INTO invoice_counters (year, prefix, last_number) VALUES (:y, :p, 0)")
           ->execute([':y' => $year, ':p' => $prefix]);

        // Update + read in same statement (SQLite 3.35+ supports RETURNING; fallback otherwise).
        $hasReturning = false;
        try {
            $stmt = $db->prepare("UPDATE invoice_counters SET last_number = last_number + 1
                                  WHERE year = :y AND prefix = :p RETURNING last_number");
            $stmt->execute([':y' => $year, ':p' => $prefix]);
            $row = $stmt->fetch();
            if ($row && isset($row['last_number'])) {
                $hasReturning = true;
                $next = (int)$row['last_number'];
            }
        } catch (\Throwable $e) {
            // Older SQLite — fall through to UPDATE+SELECT.
        }
        if (!$hasReturning) {
            $db->prepare("UPDATE invoice_counters SET last_number = last_number + 1
                          WHERE year = :y AND prefix = :p")
               ->execute([':y' => $year, ':p' => $prefix]);
            $stmt = $db->prepare("SELECT last_number FROM invoice_counters WHERE year = :y AND prefix = :p");
            $stmt->execute([':y' => $year, ':p' => $prefix]);
            $next = (int)$stmt->fetchColumn();
        }
        return sprintf('%s%d%04d', $prefix, $year, $next);
    }

    private static function sellerSnapshot(array $legal): array
    {
        $keys = ['name', 'street', 'city', 'zip', 'country', 'ico', 'dic', 'ic_dph',
                 'iban', 'bank', 'email', 'phone', 'register_note', 'vat_registered'];
        $out = [];
        foreach ($keys as $k) $out[$k] = (string)($legal[$k] ?? '');
        return $out;
    }

    private static function customerSnapshot(array $order): array
    {
        $addr = json_decode((string)($order['shipping_addr'] ?? '{}'), true) ?: [];
        return [
            'name'    => (string)($order['customer_name']  ?? ''),
            'email'   => (string)($order['customer_email'] ?? ''),
            'phone'   => (string)($order['customer_phone'] ?? ''),
            'company' => (string)($addr['company']  ?? ''),
            'street'  => (string)($addr['street']   ?? $addr['address'] ?? ''),
            'city'    => (string)($addr['city']     ?? ''),
            'zip'     => (string)($addr['zip']      ?? ''),
            'country' => strtoupper((string)($addr['country'] ?? '')),
            'ico'     => (string)($addr['ico']      ?? ''),
            'dic'     => (string)($addr['dic']      ?? ''),
            'ic_dph'  => (string)($addr['ic_dph']   ?? ''),
        ];
    }

    private static function financialSnapshot(array $order): ?array
    {
        $json = trim((string)($order['financial_snapshot'] ?? ''));
        if ($json === '') return null;
        $snapshot = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot)) throw new \RuntimeException('Order financial snapshot is invalid');
        VatService::validateSnapshot($snapshot);
        if ((int)$snapshot['totals']['gross'] !== (int)($order['total'] ?? 0)
            || strtolower((string)$snapshot['currency']) !== strtolower((string)($order['currency'] ?? 'eur'))) {
            throw new \RuntimeException('Order financial snapshot does not match persisted order');
        }
        return $snapshot;
    }

    /**
     * VAT breakdown: derived from order_items per-line VAT data when available;
     * falls back to ratio heuristic for legacy orders without per-line snapshot.
     *
     * Returns ['rates' => {rate_str => {net,vat}}, 'vat_total' => int,
     *          'scenario' => string|null, 'note_key' => string|null].
     */
    private static function computeVatBreakdown(array $order, array $items, ?array $financialSnapshot = null): array
    {
        if ($financialSnapshot !== null) {
            $rates = [];
            foreach ($financialSnapshot['breakdown'] as $rate => $group) {
                $rates[(string)$rate] = ['net' => (int)$group['net'], 'vat' => (int)$group['vat']];
            }
            return [
                'rates' => $rates,
                'vat_total' => (int)$financialSnapshot['totals']['vat'],
                'scenario' => (string)$financialSnapshot['scenario'],
                'note_key' => (string)$financialSnapshot['note_key'] ?: null,
            ];
        }

        $orderTotal = (int)($order['total'] ?? 0);
        $discount   = (int)($order['discount_amount'] ?? 0);
        $shipping   = (int)($order['shipping_cost'] ?? 0);
        $paymentFee = (int)($order['payment_fee'] ?? 0);
        $scenario   = (string)($order['vat_scenario'] ?? '');
        // Reverse-charge, export and non-VAT-payer invoices carry no VAT on any component.
        $vatApplies = !in_array($scenario, ['reverse_charge', 'export', 'non_vat_payer'], true);

        // Preferred: aggregate per-line VAT snapshot from order_items
        $hasLineVat = false;
        foreach ($items as $it) {
            if (isset($it['vat_amount']) && $it['vat_amount'] !== null) { $hasLineVat = true; break; }
        }

        if ($hasLineVat) {
            // Total product gross (pre-discount) for proportional discount spread.
            $productsGross = 0;
            foreach ($items as $it) $productsGross += (int)($it['total'] ?? 0);

            // 1. Products — spread the order-level coupon discount proportionally
            //    across lines (by gross share), then re-extract VAT per line at its
            //    own rate so the coupon reduces the VAT base, not just the gross.
            $rates = [];
            $distributed = 0;
            $n = count($items);
            $idx = 0;
            foreach ($items as $it) {
                $idx++;
                $lineGross = (int)($it['total'] ?? 0);
                $rate = (float)($it['vat_rate'] ?? 0);
                // Last line absorbs the rounding residual of the discount spread.
                if ($idx < $n) {
                    $share = $productsGross > 0 ? (int)round($discount * $lineGross / $productsGross) : 0;
                    $distributed += $share;
                } else {
                    $share = $discount - $distributed;
                }
                $grossAfter = max(0, $lineGross - $share);
                [$net, $vat] = self::extractVat($grossAfter, $vatApplies ? $rate : 0.0);
                self::addRate($rates, $vatApplies ? $rate : 0.0, $net, $vat);
            }

            // 2. Shipping — its own VAT-bearing component. Follows the standard
            //    (highest product) rate when VAT applies, else 0%. Merged into the
            //    matching rate group for the VAT recap.
            if ($shipping > 0) {
                $shipRate = $vatApplies ? self::maxRate($rates) : 0.0;
                [$sNet, $sVat] = self::extractVat($shipping, $shipRate);
                self::addRate($rates, $shipRate, $sNet, $sVat);
            }

            // 3. Payment fee — VAT-exempt financial service → its own 0% line.
            if ($paymentFee > 0) {
                self::addRate($rates, 0.0, $paymentFee, 0);
            }

            // 4. Reconcile to order.total: fold any rounding residual into the
            //    largest-net group so sum(net+vat) == gross exactly.
            $vatTotal = self::reconcileRates($rates, $orderTotal);

            return [
                'rates'     => $rates,
                'vat_total' => $vatTotal,
                'scenario'  => $scenario ?: null,
                'note_key'  => self::scenarioNoteKey($scenario),
            ];
        }

        // Legacy fallback (no per-line VAT) — single-rate ratio heuristic.
        $taxAmt = (int)($order['tax_amount'] ?? 0);
        $sub    = (int)($order['subtotal'] ?? 0) - $discount + $shipping;
        $netForVat = max(0, $sub - $taxAmt);
        $rate = $netForVat > 0 ? (int)round($taxAmt * 100 / $netForVat) : 0;
        if ($rate < 0 || $rate > 50) $rate = 0;
        $rates = [];
        if ($taxAmt > 0 || $netForVat > 0) {
            self::addRate($rates, (float)$rate, $netForVat, $taxAmt);
        }
        $vatTotal = self::reconcileRates($rates, $orderTotal);
        return ['rates' => $rates, 'vat_total' => $vatTotal, 'scenario' => $scenario ?: null, 'note_key' => self::scenarioNoteKey($scenario)];
    }

    /** Extract net + VAT from a gross-inclusive amount at the given rate. */
    private static function extractVat(int $gross, float $rate): array
    {
        if ($rate <= 0) return [$gross, 0];
        $net = (int)round($gross / (1 + $rate / 100));
        return [$net, $gross - $net];
    }

    /** Canonical rate-group key: "23", "10", "0" (trailing zeros trimmed). */
    private static function rateKey(float $rate): string
    {
        $key = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
        return $key === '' ? '0' : $key;
    }

    /** Accumulate net/vat into the rate group keyed by $rate. */
    private static function addRate(array &$rates, float $rate, int $net, int $vat): void
    {
        $key = self::rateKey($rate);
        if (!isset($rates[$key])) $rates[$key] = ['net' => 0, 'vat' => 0];
        $rates[$key]['net'] += $net;
        $rates[$key]['vat'] += $vat;
    }

    /** Highest positive rate currently present in the breakdown (0 if none). */
    private static function maxRate(array $rates): float
    {
        $max = 0.0;
        foreach (array_keys($rates) as $k) $max = max($max, (float)$k);
        return $max;
    }

    /**
     * Force sum(net+vat) across all rate groups to equal $orderTotal by folding
     * the rounding residual into the largest-net group's net. Returns total VAT.
     */
    private static function reconcileRates(array &$rates, int $orderTotal): int
    {
        $vatTotal = 0;
        $groupGross = 0;
        $largestKey = null;
        $largestNet = PHP_INT_MIN;
        foreach ($rates as $key => $info) {
            $vatTotal   += (int)$info['vat'];
            $groupGross += (int)$info['net'] + (int)$info['vat'];
            if ((int)$info['net'] > $largestNet) { $largestNet = (int)$info['net']; $largestKey = $key; }
        }
        $residual = $orderTotal - $groupGross;
        if ($residual !== 0 && $largestKey !== null) {
            $rates[$largestKey]['net'] += $residual;
        } elseif ($residual !== 0) {
            self::addRate($rates, 0.0, $residual, 0);
        }
        return $vatTotal;
    }

    /** Map a VAT scenario to its on-invoice i18n note key. */
    private static function scenarioNoteKey(string $scenario): ?string
    {
        switch ($scenario) {
            case 'reverse_charge': return 'vat.reverse_charge_note';
            case 'export':         return 'vat.export_note';
            case 'non_vat_payer':  return 'vat.non_vat_payer_note';
            default:               return null;
        }
    }

    private static function resolveStore(array $order, ?string $storeCode): array
    {
        $stores = self::storesConfig();
        $code = $storeCode ?? self::detectStoreCode($order, $stores);
        return $stores['stores'][$code] ?? array_values($stores['stores'])[0] ?? [];
    }

    private static function detectStoreCode(array $order, array $stores): string
    {
        $orderCurrency = strtolower((string)($order['currency'] ?? ''));
        foreach (($stores['stores'] ?? []) as $code => $store) {
            if (strtolower((string)($store['currency'] ?? '')) === $orderCurrency) return $code;
        }
        return array_key_first($stores['stores'] ?? []) ?? 'sk';
    }

    private static function resolveLocale(array $order, array $store): string
    {
        return (string)($order['locale'] ?? $store['default_locale'] ?? 'en');
    }

    private static function storesConfig(): array
    {
        $file = self::baseDir() . '/stores.json';
        $j = file_exists($file) ? json_decode((string)file_get_contents($file), true) : [];
        return $j ?: [];
    }

    private static function storageDir(): string
    {
        if (self::$storageDir !== null) return self::$storageDir;

        $cfg = require __DIR__ . '/../config.php';
        $dir = (string)($cfg['invoice_storage_dir'] ?? dirname(self::baseDir()) . '/bareboneseshop-private/invoices');
        if (!self::isAbsolutePath($dir)) {
            throw new \RuntimeException('invoice_storage_dir must be an absolute path outside the application root');
        }
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create invoice_storage_dir: {$dir}");
        }

        $realDir = realpath($dir);
        if ($realDir === false) {
            throw new \RuntimeException("Cannot resolve invoice_storage_dir: {$dir}");
        }
        $root = self::normalizePath($realDir);
        $appRoot = self::normalizePath(self::baseDir());
        if (self::pathIsWithin($root, $appRoot)) {
            throw new \RuntimeException('invoice_storage_dir must be outside the application root');
        }
        self::$storageDir = str_replace('/', DIRECTORY_SEPARATOR, $root);
        return self::$storageDir;
    }

    private static function resolvePdfPath(string $relativePath): string
    {
        $path = str_replace('\\', '/', trim($relativePath));
        if (str_starts_with($path, 'storage/invoices/')) {
            $path = substr($path, strlen('storage/invoices/'));
        }
        if ($path === '' || preg_match('/[\x00-\x1f\x7f]/', $path) || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new \RuntimeException('Invalid invoice PDF path');
        }
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_contains($part, ':')) {
                throw new \RuntimeException('Invalid invoice PDF path');
            }
        }
        if (strtolower((string)pathinfo(end($parts), PATHINFO_EXTENSION)) !== 'pdf') {
            throw new \RuntimeException('Invalid invoice PDF extension');
        }

        $root = self::storageDir();
        return $root . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
    }

    private static function writePdf(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create invoice directory: {$dir}");
        }
        $root = realpath(self::storageDir());
        $realDir = realpath($dir);
        if ($root === false || $realDir === false || !self::pathIsWithin(self::normalizePath($realDir), self::normalizePath($root))) {
            throw new \RuntimeException('Invoice PDF path escapes invoice_storage_dir');
        }

        $tmp = tempnam($dir, '.invoice-');
        if ($tmp === false) {
            throw new \RuntimeException("Cannot create temporary invoice file in {$dir}");
        }
        try {
            $written = file_put_contents($tmp, $contents, LOCK_EX);
            if ($written !== strlen($contents)) {
                throw new \RuntimeException("Cannot write complete invoice PDF: {$path}");
            }
            @chmod($tmp, 0640);
            if (@rename($tmp, $path)) {
                return;
            }

            if (DIRECTORY_SEPARATOR === '\\' && is_file($path)) {
                $old = $path . '.old-' . bin2hex(random_bytes(4));
                if (@rename($path, $old)) {
                    if (@rename($tmp, $path)) {
                        @unlink($old);
                        return;
                    }
                    if (!@rename($old, $path)) {
                        throw new \RuntimeException("Cannot restore previous invoice PDF; preserved copy: {$old}");
                    }
                }
            }
            throw new \RuntimeException("Cannot atomically replace invoice PDF: {$path}");
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private static function normalizePath(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }

    private static function pathIsWithin(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private static function baseDir(): string
    {
        return realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    }

    /**
     * Render PDF binary. Routes through mPDF if available, else MinimalPdf.
     */
    private static function renderPdf(
        array $invoice, array $order, array $items,
        array $sellerSnap, array $customerSnap, array $vatBreakdown
    ): string {
        $mpdfAuto = self::baseDir() . '/vendor/mpdf/vendor/autoload.php';
        if (file_exists($mpdfAuto) && class_exists('Mpdf\\Mpdf', false)) {
            // mPDF integration intentionally minimal — the heavy lifting belongs
            // in a dedicated renderer file shipped alongside vendor/mpdf.
            // For v1 we fall through to MinimalPdf even if mPDF is present.
        }
        return self::renderMinimalPdf($invoice, $order, $items, $sellerSnap, $customerSnap, $vatBreakdown);
    }

    private static function renderMinimalPdf(
        array $invoice, array $order, array $items,
        array $sellerSnap, array $customerSnap, array $vatBreakdown
    ): string {
        $locale = (string)$invoice['locale'];
        $L = self::loadInvoiceLabels($locale);
        $currency = (string)$invoice['currency'];

        $pdf = new MinimalPdf();
        $pdf->addPage();

        $y = 18.0;
        // Title
        $pdf->text(20, $y, $L['title'] . ' ' . $invoice['number'], ['size' => 18, 'bold' => true]);

        // Right-aligned dates
        $pdf->text(120, $y, $L['issued_at'] . ': ' . $invoice['issued_at'], ['size' => 9]);
        $pdf->text(120, $y + 5, $L['delivery_date'] . ': ' . $invoice['delivery_date'], ['size' => 9]);
        $pdf->text(120, $y + 10, $L['due_date'] . ': ' . $invoice['due_date'], ['size' => 9]);

        $y += 22;
        $pdf->line(20, $y, 190, $y);
        $y += 5;

        // Seller (left) / Customer (right)
        $pdf->text(20, $y, $L['seller'], ['size' => 10, 'bold' => true]);
        $pdf->text(110, $y, $L['customer'], ['size' => 10, 'bold' => true]);
        $y += 6;
        $sy = $y; $cy = $y;
        foreach (self::partyLines($sellerSnap, $L) as $line) { $pdf->text(20, $sy, $line, ['size' => 9]); $sy += 4.5; }
        foreach (self::partyLines($customerSnap, $L) as $line) { $pdf->text(110, $cy, $line, ['size' => 9]); $cy += 4.5; }
        $y = max($sy, $cy) + 4;

        $pdf->line(20, $y, 190, $y);
        $y += 6;

        // Items header
        $pdf->text(20, $y, $L['col_item'], ['size' => 9, 'bold' => true]);
        $pdf->text(100, $y, $L['col_qty'], ['size' => 9, 'bold' => true, 'align' => 'right', 'width' => 15]);
        $pdf->text(120, $y, $L['col_unit_price'], ['size' => 9, 'bold' => true, 'align' => 'right', 'width' => 25]);
        $pdf->text(150, $y, $L['col_vat'], ['size' => 9, 'bold' => true, 'align' => 'right', 'width' => 12]);
        $pdf->text(165, $y, $L['col_total'], ['size' => 9, 'bold' => true, 'align' => 'right', 'width' => 25]);
        $y += 4;
        $pdf->line(20, $y, 190, $y);
        $y += 4;

        // Each line shows its OWN VAT rate (mixed-rate invoices: a 23% line and a
        // 10% line coexist instead of all lines borrowing the first rate).
        foreach ($items as $item) {
            $name = (string)$item['name'];
            $qty  = (int)$item['quantity'];
            $unit = (int)$item['unit_price'];
            $tot  = (int)$item['total'];
            $lineRate = self::rateKey((float)($item['vat_rate'] ?? 0));
            $pdf->text(20, $y, mb_substr($name, 0, 50), ['size' => 9]);
            $pdf->text(100, $y, (string)$qty, ['size' => 9, 'align' => 'right', 'width' => 15]);
            $pdf->text(120, $y, I18n::price($unit, $currency), ['size' => 9, 'align' => 'right', 'width' => 25]);
            $pdf->text(150, $y, $lineRate . '%', ['size' => 9, 'align' => 'right', 'width' => 12]);
            $pdf->text(165, $y, I18n::price($tot, $currency), ['size' => 9, 'align' => 'right', 'width' => 25]);
            $y += 5;
        }

        if (!empty($order['shipping_cost']) && (int)$order['shipping_cost'] > 0) {
            // Shipping carries the standard (highest) rate when VAT applies, 0% otherwise.
            $shipRate = self::rateKey(self::maxRate($vatBreakdown['rates'] ?? []));
            $pdf->text(20, $y, $L['shipping'], ['size' => 9]);
            $pdf->text(150, $y, $shipRate . '%', ['size' => 9, 'align' => 'right', 'width' => 12]);
            $pdf->text(165, $y, I18n::price((int)$order['shipping_cost'], $currency),
                ['size' => 9, 'align' => 'right', 'width' => 25]);
            $y += 5;
        }

        $pdf->line(20, $y, 190, $y);
        $y += 6;

        // Totals
        $pdf->text(120, $y, $L['total_net'] . ': ' . I18n::price((int)$invoice['total_net'], $currency),
            ['size' => 9, 'align' => 'right', 'width' => 70]);
        $y += 5;
        $pdf->text(120, $y, $L['total_vat'] . ': ' . I18n::price((int)$invoice['total_vat'], $currency),
            ['size' => 9, 'align' => 'right', 'width' => 70]);
        $y += 5;
        $pdf->text(120, $y, $L['total_gross'] . ': ' . I18n::price((int)$invoice['total_gross'], $currency),
            ['size' => 11, 'bold' => true, 'align' => 'right', 'width' => 70]);
        $y += 8;

        // VAT breakdown table
        if (!empty($vatBreakdown['rates'])) {
            $pdf->text(20, $y, $L['vat_recap'], ['size' => 9, 'bold' => true]);
            $y += 5;
            foreach ($vatBreakdown['rates'] as $r => $info) {
                $pdf->text(20, $y, $r . '%', ['size' => 9]);
                $pdf->text(40, $y, $L['col_net'] . ' ' . I18n::price((int)$info['net'], $currency), ['size' => 9]);
                $pdf->text(100, $y, $L['col_vat'] . ' ' . I18n::price((int)$info['vat'], $currency), ['size' => 9]);
                $y += 5;
            }
            $y += 3;
        }

        // VAT scenario notice
        $noteKey = (string)($invoice['reverse_charge_note_key'] ?? $vatBreakdown['note_key'] ?? '');
        if ($noteKey !== '') {
            $labelKey = str_replace('vat.', '', $noteKey);
            if (isset($L[$labelKey])) {
                $pdf->text(20, $y, $L[$labelKey], ['size' => 9, 'bold' => true]);
                $y += 5;
            }
        }

        // Payment info
        $pdf->text(20, $y, $L['payment_method'] . ': ' . ($invoice['payment_method'] ?: '—'), ['size' => 9]);
        $y += 5;
        if (!empty($sellerSnap['iban'])) {
            $pdf->text(20, $y, $L['iban'] . ': ' . $sellerSnap['iban'], ['size' => 9]);
            $y += 5;
        }
        $pdf->text(20, $y, $L['variable_symbol'] . ': ' . preg_replace('/\D/', '', (string)$invoice['number']),
            ['size' => 9]);
        $y += 8;

        // Footer (register note)
        if (!empty($sellerSnap['register_note'])) {
            $pdf->text(20, 285, mb_substr((string)$sellerSnap['register_note'], 0, 110), ['size' => 7]);
        }

        return $pdf->render();
    }

    private static function partyLines(array $p, array $L): array
    {
        $out = [];
        if (!empty($p['name']))    $out[] = $p['name'];
        if (!empty($p['company'])) $out[] = $p['company'];
        if (!empty($p['street']))  $out[] = $p['street'];
        if (!empty($p['city']) || !empty($p['zip'])) {
            $out[] = trim(($p['zip'] ?? '') . ' ' . ($p['city'] ?? ''));
        }
        if (!empty($p['country'])) $out[] = $p['country'];
        if (!empty($p['ico']))     $out[] = $L['ico'] . ': ' . $p['ico'];
        if (!empty($p['dic']))     $out[] = $L['dic'] . ': ' . $p['dic'];
        if (!empty($p['ic_dph']))  $out[] = $L['ic_dph'] . ': ' . $p['ic_dph'];
        if (!empty($p['email']))   $out[] = $p['email'];
        if (!empty($p['phone']))   $out[] = $p['phone'];
        return $out;
    }

    /** Inline labels per locale — kept here so InvoiceService is self-contained. */
    private static function loadInvoiceLabels(string $locale): array
    {
        $all = [
            'sk' => [
                'title' => 'Faktúra č.', 'issued_at' => 'Dátum vystavenia',
                'delivery_date' => 'Dátum dodania', 'due_date' => 'Splatnosť',
                'seller' => 'Predávajúci', 'customer' => 'Odberateľ',
                'col_item' => 'Položka', 'col_qty' => 'Množ.', 'col_unit_price' => 'J. cena',
                'col_vat' => 'DPH', 'col_total' => 'Spolu',
                'shipping' => 'Doprava',
                'total_net' => 'Spolu bez DPH', 'total_vat' => 'DPH spolu', 'total_gross' => 'Spolu k úhrade',
                'vat_recap' => 'Rekapitulácia DPH', 'col_net' => 'Základ', 'reverse_charge_note' => 'Daň odvádza zákazník',
                'export_note' => 'Dodanie oslobodené od DPH pri vývoze mimo EÚ', 'non_vat_payer_note' => 'Predávajúci nie je platiteľom DPH',
                'payment_method' => 'Spôsob úhrady', 'iban' => 'IBAN', 'variable_symbol' => 'Variabilný symbol',
                'ico' => 'IČO', 'dic' => 'DIČ', 'ic_dph' => 'IČ DPH',
            ],
            'cs' => [
                'title' => 'Faktura č.', 'issued_at' => 'Datum vystavení',
                'delivery_date' => 'Datum dodání', 'due_date' => 'Splatnost',
                'seller' => 'Prodávající', 'customer' => 'Odběratel',
                'col_item' => 'Položka', 'col_qty' => 'Množ.', 'col_unit_price' => 'J. cena',
                'col_vat' => 'DPH', 'col_total' => 'Celkem',
                'shipping' => 'Doprava',
                'total_net' => 'Celkem bez DPH', 'total_vat' => 'DPH celkem', 'total_gross' => 'Celkem k úhradě',
                'vat_recap' => 'Rekapitulace DPH', 'col_net' => 'Základ', 'reverse_charge_note' => 'Daň odvádí zákazník',
                'export_note' => 'Dodání osvobozené od DPH při vývozu mimo EU', 'non_vat_payer_note' => 'Prodávající není plátcem DPH',
                'payment_method' => 'Způsob úhrady', 'iban' => 'IBAN', 'variable_symbol' => 'Variabilní symbol',
                'ico' => 'IČO', 'dic' => 'DIČ', 'ic_dph' => 'DIČ DPH',
            ],
            'pl' => [
                'title' => 'Faktura nr', 'issued_at' => 'Data wystawienia',
                'delivery_date' => 'Data dostawy', 'due_date' => 'Termin płatności',
                'seller' => 'Sprzedawca', 'customer' => 'Nabywca',
                'col_item' => 'Pozycja', 'col_qty' => 'Ilość', 'col_unit_price' => 'Cena j.',
                'col_vat' => 'VAT', 'col_total' => 'Razem',
                'shipping' => 'Wysyłka',
                'total_net' => 'Razem netto', 'total_vat' => 'VAT razem', 'total_gross' => 'Do zapłaty',
                'vat_recap' => 'Rekapitulacja VAT', 'col_net' => 'Netto', 'reverse_charge_note' => 'Odwrotne obciążenie',
                'export_note' => 'Dostawa zwolniona z VAT przy eksporcie poza UE', 'non_vat_payer_note' => 'Sprzedawca nie jest płatnikiem VAT',
                'payment_method' => 'Forma płatności', 'iban' => 'IBAN', 'variable_symbol' => 'Symbol var.',
                'ico' => 'NIP/REGON', 'dic' => 'NIP', 'ic_dph' => 'NIP UE',
            ],
            'hu' => [
                'title' => 'Számla sz.', 'issued_at' => 'Kiállítás dátuma',
                'delivery_date' => 'Teljesítés dátuma', 'due_date' => 'Fizetési határidő',
                'seller' => 'Eladó', 'customer' => 'Vevő',
                'col_item' => 'Tétel', 'col_qty' => 'Menny.', 'col_unit_price' => 'Egys. ár',
                'col_vat' => 'ÁFA', 'col_total' => 'Összesen',
                'shipping' => 'Szállítás',
                'total_net' => 'Nettó összesen', 'total_vat' => 'ÁFA összesen', 'total_gross' => 'Fizetendő összesen',
                'vat_recap' => 'ÁFA összesítés', 'col_net' => 'Nettó', 'reverse_charge_note' => 'Fordított adózás',
                'export_note' => 'EU-n kívüli exportra vonatkozó ÁFA-mentes értékesítés', 'non_vat_payer_note' => 'Az eladó nem ÁFA-alany',
                'payment_method' => 'Fizetési mód', 'iban' => 'IBAN', 'variable_symbol' => 'Hivatkozási szám',
                'ico' => 'Cégjegyzékszám', 'dic' => 'Adószám', 'ic_dph' => 'Közösségi adószám',
            ],
            'de' => [
                'title' => 'Rechnung Nr.', 'issued_at' => 'Rechnungsdatum',
                'delivery_date' => 'Leistungsdatum', 'due_date' => 'Fällig am',
                'seller' => 'Verkäufer', 'customer' => 'Kunde',
                'col_item' => 'Position', 'col_qty' => 'Menge', 'col_unit_price' => 'Einzelpreis',
                'col_vat' => 'MwSt.', 'col_total' => 'Gesamt',
                'shipping' => 'Versand',
                'total_net' => 'Netto gesamt', 'total_vat' => 'MwSt. gesamt', 'total_gross' => 'Gesamtbetrag',
                'vat_recap' => 'MwSt.-Aufschlüsselung', 'col_net' => 'Netto', 'reverse_charge_note' => 'Reverse Charge — Steuerschuldnerschaft des Leistungsempfängers',
                'export_note' => 'Steuerfreie Ausfuhrlieferung außerhalb der EU', 'non_vat_payer_note' => 'Der Verkäufer ist nicht umsatzsteuerpflichtig',
                'payment_method' => 'Zahlungsart', 'iban' => 'IBAN', 'variable_symbol' => 'Verwendungszweck',
                'ico' => 'HR-Nr.', 'dic' => 'Steuernr.', 'ic_dph' => 'USt-IdNr.',
            ],
            'en' => [
                'title' => 'Invoice No.', 'issued_at' => 'Issue date',
                'delivery_date' => 'Delivery date', 'due_date' => 'Due date',
                'seller' => 'Seller', 'customer' => 'Customer',
                'col_item' => 'Item', 'col_qty' => 'Qty', 'col_unit_price' => 'Unit price',
                'col_vat' => 'VAT', 'col_total' => 'Total',
                'shipping' => 'Shipping',
                'total_net' => 'Total net', 'total_vat' => 'Total VAT', 'total_gross' => 'Total due',
                'vat_recap' => 'VAT breakdown', 'col_net' => 'Net', 'reverse_charge_note' => 'Reverse charge — VAT to be accounted for by the recipient',
                'export_note' => 'VAT-exempt export supply outside the EU', 'non_vat_payer_note' => 'The seller is not registered for VAT',
                'payment_method' => 'Payment method', 'iban' => 'IBAN', 'variable_symbol' => 'Reference',
                'ico' => 'Company ID', 'dic' => 'Tax ID', 'ic_dph' => 'VAT ID',
            ],
        ];
        return $all[$locale] ?? $all['en'];
    }
}
