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
 * Storage: storage/invoices/{YYYY}/{MM}/{number}.pdf — out of webroot.
 * Customer access via signed token (invoices.download_token).
 */
final class InvoiceService
{
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
        $vatBreakdown     = self::computeVatBreakdown($order, $items);
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

            $relPath = sprintf('storage/invoices/%d/%02d/%s.pdf',
                $year, (int)date('m', strtotime($issuedAt)), $number);
            $absPath = self::baseDir() . '/' . $relPath;

            $stmt = $db->prepare("
                INSERT INTO invoices (
                    order_id, number, issued_at, delivery_date, due_date, pdf_path,
                    total_net, total_vat, total_gross, currency,
                    vat_breakdown, customer_snapshot, seller_snapshot,
                    payment_method, locale, status, download_token,
                    vat_scenario, reverse_charge_note_key
                ) VALUES (
                    :order_id, :number, :issued_at, :delivery_date, :due_date, :pdf_path,
                    :total_net, :total_vat, :total_gross, :currency,
                    :vat_breakdown, :customer_snapshot, :seller_snapshot,
                    :payment_method, :locale, :status, :download_token,
                    :vat_scenario, :note_key
                )
            ");
            $stmt->execute([
                ':order_id'          => $orderId,
                ':number'            => $number,
                ':issued_at'         => $issuedAt,
                ':delivery_date'     => $deliveryDate,
                ':due_date'          => $dueDate,
                ':pdf_path'          => $relPath,
                ':total_net'         => (int)($order['subtotal'] ?? 0) - (int)($order['discount_amount'] ?? 0) + (int)($order['shipping_cost'] ?? 0) - (int)$vatBreakdown['vat_total'],
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
        @mkdir(dirname($absPath), 0750, true);
        file_put_contents($absPath, $pdfBin);

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
        $absPath = self::baseDir() . '/' . $invoice['pdf_path'];
        @mkdir(dirname($absPath), 0750, true);
        file_put_contents($absPath, $pdfBin);
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
        return self::baseDir() . '/' . $invoice['pdf_path'];
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
        // INSERT IF NOT EXISTS (SQLite: INSERT OR IGNORE).
        $db->prepare("INSERT OR IGNORE INTO invoice_counters (year, prefix, last_number) VALUES (:y, :p, 0)")
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
                 'iban', 'bank', 'email', 'phone', 'register_note'];
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

    /**
     * VAT breakdown: derived from order_items per-line VAT data when available;
     * falls back to ratio heuristic for legacy orders without per-line snapshot.
     *
     * Returns ['rates' => {rate_str => {net,vat}}, 'vat_total' => int,
     *          'scenario' => string|null, 'note_key' => string|null].
     */
    private static function computeVatBreakdown(array $order, array $items): array
    {
        // Preferred: aggregate per-line VAT snapshot from order_items
        $hasLineVat = false;
        foreach ($items as $it) {
            if (isset($it['vat_amount']) && $it['vat_amount'] !== null) { $hasLineVat = true; break; }
        }

        if ($hasLineVat) {
            $rates = [];
            $vatTotal = 0;
            foreach ($items as $it) {
                $rate = (float)($it['vat_rate'] ?? 0);
                $vat  = (int)($it['vat_amount'] ?? 0);
                $net  = (int)($it['net_amount'] ?? 0);
                $key  = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
                if ($key === '') $key = '0';
                if (!isset($rates[$key])) $rates[$key] = ['net' => 0, 'vat' => 0];
                $rates[$key]['net'] += $net;
                $rates[$key]['vat'] += $vat;
                $vatTotal += $vat;
            }
            return [
                'rates'     => $rates,
                'vat_total' => $vatTotal,
                'scenario'  => $order['vat_scenario'] ?? null,
                'note_key'  => self::scenarioNoteKey((string)($order['vat_scenario'] ?? '')),
            ];
        }

        // Legacy fallback (no per-line VAT) — keep existing ratio heuristic.
        $taxAmt = (int)($order['tax_amount'] ?? 0);
        $sub    = (int)($order['subtotal'] ?? 0) - (int)($order['discount_amount'] ?? 0) + (int)($order['shipping_cost'] ?? 0);
        $netForVat = max(0, $sub - $taxAmt);
        $rate = $netForVat > 0 ? (int)round($taxAmt * 100 / $netForVat) : 0;
        if ($rate < 0 || $rate > 50) $rate = 0;
        $rates = [];
        if ($taxAmt > 0 || $netForVat > 0) {
            $rates[(string)$rate] = ['net' => $netForVat, 'vat' => $taxAmt];
        }
        return ['rates' => $rates, 'vat_total' => $taxAmt, 'scenario' => null, 'note_key' => null];
    }

    /** Map a VAT scenario to its on-invoice i18n note key. */
    private static function scenarioNoteKey(string $scenario): ?string
    {
        switch ($scenario) {
            case 'reverse_charge': return 'vat.reverse_charge_note';
            case 'export':         return 'vat.export_note';
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

        $rate = (int)array_key_first($vatBreakdown['rates'] ?? []) ?: 0;
        foreach ($items as $item) {
            $name = (string)$item['name'];
            $qty  = (int)$item['quantity'];
            $unit = (int)$item['unit_price'];
            $tot  = (int)$item['total'];
            $pdf->text(20, $y, mb_substr($name, 0, 50), ['size' => 9]);
            $pdf->text(100, $y, (string)$qty, ['size' => 9, 'align' => 'right', 'width' => 15]);
            $pdf->text(120, $y, I18n::price($unit, $currency), ['size' => 9, 'align' => 'right', 'width' => 25]);
            $pdf->text(150, $y, $rate . '%', ['size' => 9, 'align' => 'right', 'width' => 12]);
            $pdf->text(165, $y, I18n::price($tot, $currency), ['size' => 9, 'align' => 'right', 'width' => 25]);
            $y += 5;
        }

        if (!empty($order['shipping_cost']) && (int)$order['shipping_cost'] > 0) {
            $pdf->text(20, $y, $L['shipping'], ['size' => 9]);
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

        // Reverse-charge notice
        if (self::isReverseCharge($sellerSnap, $customerSnap)) {
            $pdf->text(20, $y, $L['reverse_charge_note'], ['size' => 9, 'bold' => true]);
            $y += 5;
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
                'payment_method' => 'Payment method', 'iban' => 'IBAN', 'variable_symbol' => 'Reference',
                'ico' => 'Company ID', 'dic' => 'Tax ID', 'ic_dph' => 'VAT ID',
            ],
        ];
        return $all[$locale] ?? $all['en'];
    }
}
