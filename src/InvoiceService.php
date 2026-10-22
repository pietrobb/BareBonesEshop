<?php
declare(strict_types=1);
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
 * Numbering: gapless per (seller store, document type, year, prefix) via the invoice_number_counters table;
 * two series may never share a prefix (numberingConflicts(), allocateNumber()).
 * Format: {prefix}{YYYY}{NNNN}, e.g. "20260001", "DE-20260001".
 *
 * Storage: configured private invoice_storage_dir, outside the webroot.
 * Customer access: the signed-in owner through the account, a guest through a bearer link sent by e-mail. The link's
 * token is minted per mail (downloadLink()) and only its SHA-256 is stored (invoices.download_token), valid for
 * DOWNLOAD_LINK_TTL_DAYS; a new mail replaces the previous link.
 */
final class InvoiceService
{
    private static ?string $storageDir = null;
    private static ?array $storesOverride = null;
    /** Lowest baseline (mm from the top of A4) for invoice body text; the register note sits at 285 mm. */
    private const PDF_CONTENT_BOTTOM_MM = 278.0;

    /** Days an e-mailed invoice link stays valid. */
    public const DOWNLOAD_LINK_TTL_DAYS = 30;

    public const STATUS_ISSUED    = 'issued';
    public const STATUS_CANCELLED = 'cancelled';

    /** EU member states for reverse-charge detection. */
    private const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES',
        'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
        'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'GR',
    ];

    /** Order statuses after payment: only these orders get an invoice (admin button, worker, backfill alike). */
    public const INVOICEABLE_STATUSES = ['paid', 'processing', 'shipped', 'delivered'];

    /** True when the order may be invoiced: it reached a paid status and its payment did not fail or get cancelled. */
    public static function canIssue(array $order): bool
    {
        return in_array((string)($order['status'] ?? ''), self::INVOICEABLE_STATUSES, true)
            && !in_array((string)($order['payment_status'] ?? ''), ['cancelled', 'failed'], true);
    }

    /**
     * Issue an invoice for a paid order (see canIssue()). Idempotent: re-calling for the
     * same order_id returns the existing invoice. A cancelled invoice is replaced: it keeps its number and status for
     * the audit trail and is re-typed 'replaced', which frees the one-invoice-per-order slot for the new invoice.
     *
     * @return array Invoice row.
     */
    public static function issue(int $orderId, ?string $storeCode = null): array
    {
        return self::issueAttempt($orderId, $storeCode, 1);
    }

    /** MySQL/MariaDB picked this transaction as a deadlock victim (its work was rolled back and may simply be retried). */
    private static function deadlocked(\PDOException $e): bool
    {
        return Db::isMysql() && ((string)$e->getCode() === '40001' || (int)($e->errorInfo[1] ?? 0) === 1213);
    }

    private static function issueAttempt(int $orderId, ?string $storeCode, int $attempt): array
    {
        $existing = self::byOrderId($orderId);
        $replaces = null;
        if ($existing !== null) {
            if ((string)($existing['type'] ?? 'invoice') !== 'invoice' || (string)($existing['status'] ?? '') !== self::STATUS_CANCELLED) {
                return $existing;
            }
            $replaces = (int)$existing['id'];
        }

        $order = Orders::byId($orderId);
        if (!$order) {
            throw new \RuntimeException("Order #{$orderId} not found");
        }
        if (!self::canIssue($order)) {
            throw new \RuntimeException("Order #{$orderId} is not paid (status {$order['status']}); no invoice is issued");
        }

        $items  = Orders::items($orderId);
        [$sellerCode, $store] = self::resolveStore($order, $storeCode);
        $legal  = $store['legal'] ?? [];

        $sellerSnapshot   = self::sellerSnapshot($legal);
        $customerSnapshot = self::customerSnapshot($order);
        $financialSnapshot = self::financialSnapshot($order);
        $vatBreakdown     = self::computeVatBreakdown($order, $items, $financialSnapshot);
        $locale           = self::resolveLocale($order, $store);
        $issuedAt         = date('Y-m-d');
        $deliveryDate     = self::supplyDate($order, $issuedAt);
        $paymentTermsDays = (int)($legal['payment_terms_days'] ?? 14);
        $dueDate          = date('Y-m-d', strtotime("+{$paymentTermsDays} days"));

        $db = Db::get();
        $db->beginTransaction();
        try {
            if ($replaces !== null) {
                $db->prepare("UPDATE invoices SET type = 'replaced' WHERE id = :id AND type = 'invoice' AND status = :cancelled")
                    ->execute([':id' => $replaces, ':cancelled' => self::STATUS_CANCELLED]);
            }
            // Allocate next number atomically.
            $year   = (int)date('Y', strtotime($issuedAt));
            $prefix = self::numberPrefix($legal, 'invoice');
            $number = self::allocateNumber($db, $sellerCode, 'invoice', $year, $prefix);

            // Placeholder secret: the column is unique and required, but no link exists until a mail mints one.
            $token  = self::tokenHash(bin2hex(random_bytes(32)));

            $relPath = sprintf('%d/%02d/%s.pdf',
                $year, (int)date('m', strtotime($issuedAt)), rawurlencode($number));
            $absPath = self::resolvePdfPath($relPath);

            $stmt = $db->prepare("
                INSERT INTO invoices (
                    order_id, store_id, number, issued_at, delivery_date, due_date, pdf_path,
                    total_net, total_vat, total_gross, currency,
                    vat_breakdown, customer_snapshot, seller_snapshot,
                    payment_method, locale, status, download_token,
                    vat_scenario, reverse_charge_note_key, financial_snapshot
                ) VALUES (
                    :order_id, :store, :number, :issued_at, :delivery_date, :due_date, :pdf_path,
                    :total_net, :total_vat, :total_gross, :currency,
                    :vat_breakdown, :customer_snapshot, :seller_snapshot,
                    :payment_method, :locale, :status, :download_token,
                    :vat_scenario, :note_key, :financial_snapshot
                )
            ");
            $stmt->execute([
                ':order_id'          => $orderId,
                ':store'             => StoreContext::current()->id(),
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
            self::recordSeller($db, $invoiceId, $sellerCode);
            AuditLog::log('invoice', $invoiceId, null, ['number' => $number, 'order_id' => $orderId, 'type' => 'invoice', 'seller' => $sellerCode,
                'total_gross' => (int)$order['total'], 'currency' => (string)($order['currency'] ?? 'eur')] + ($replaces !== null ? ['replaces_invoice_id' => $replaces] : []),
                'Invoice issued');

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
            // The first number of a new series: two issuers can still deadlock creating its counter row. The victim
            // was rolled back whole, so it starts over (and finds the other's invoice if it was the same order).
            if (self::deadlocked($e) && $attempt < 3) return self::issueAttempt($orderId, $storeCode, $attempt + 1);
            throw $e;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        // Render PDF AFTER successful commit (filesystem is non-transactional).
        $invoice = self::byId($invoiceId);
        $vatBreakdown['services_only'] = self::servicesOnly($order);
        $pdfBin  = self::renderPdf($invoice, self::invoiceRows($invoice, $order, $items, $financialSnapshot), $sellerSnapshot, $customerSnapshot, $vatBreakdown);
        self::writePdf($absPath, $pdfBin);

        return $invoice;
    }

    /**
     * Date of supply (dátum dodania) of an order: the earlier of the payment being received and the goods being
     * shipped, else the order date — never the day a late or backfilled invoice happens to be issued.
     */
    private static function supplyDate(array $order, string $issuedAt): string
    {
        $localDate = static function (string $timestamp): ?string {
            $time = trim($timestamp) === '' ? false : strtotime($timestamp . ' UTC');
            return $time === false ? null : date('Y-m-d', $time);
        };
        $events = [];
        foreach ([
            'payment_transactions' => "SELECT MIN(created_at) FROM payment_transactions WHERE order_id = :order AND status = 'paid'",
            'shipments' => 'SELECT MIN(shipped_at) FROM shipments WHERE order_id = :order AND shipped_at IS NOT NULL',
        ] as $table => $sql) {
            if (!Db::tableExists($table)) continue;
            $stmt = Db::get()->prepare($sql);
            $stmt->execute([':order' => (int)$order['id']]);
            $date = $localDate((string)$stmt->fetchColumn());
            if ($date !== null) $events[] = $date;
        }
        $supply = $events !== [] ? min($events) : ($localDate((string)($order['created_at'] ?? '')) ?? $issuedAt);
        return min($supply, $issuedAt);
    }

    /**
     * Variables of the invoice_issued e-mail: what the templates of every language print (amount, dates, the online
     * link and the seller), whichever path sends it — the worker after payment or the admin's issue / resend.
     * @return array<string, string>
     */
    public static function mailVars(array $invoice, array $order): array
    {
        $seller = json_decode((string)($invoice['seller_snapshot'] ?? ''), true) ?: [];
        $total = I18n::price((int)$invoice['total_gross'], (string)($invoice['currency'] ?? 'eur'));
        // The token is store-scoped and a store on its own domain is resolved by host: the link must use that host.
        $link = self::downloadLink((int)($invoice['id'] ?? 0));
        $appUrl = Security::configuredAppUrl();
        $link = $appUrl === null ? $link : Security::storeAppUrl($appUrl, StoreContext::current()->config()) . Template::seoPath($link);
        return [
            'order_number' => (string)($order['order_number'] ?? ''),
            'invoice_number' => (string)$invoice['number'],
            'customer_name' => (string)($order['customer_name'] ?? ''),
            'issued_at' => (string)$invoice['issued_at'],
            'due_date' => (string)$invoice['due_date'],
            'total_gross_formatted' => $total,
            'invoice_total' => $total,
            'invoice_download_url' => $link,
            'seller_name' => (string)($seller['name'] ?? ''),
        ];
    }

    /** Whether the order supplied nothing but digital (electronically supplied) goods. */
    private static function servicesOnly(array $order): bool
    {
        return ($order['shipping_method'] ?? '') === 'digital';
    }

    /** Received quantity of one RMA item not yet committed to a credit note or an exchange. */
    private static function uncommittedQuantity(array $rmaItem): int {
        $stmt = Db::get()->prepare("SELECT
                COALESCE((SELECT SUM(cni.quantity) FROM credit_note_items cni JOIN order_refunds r ON r.id=cni.refund_id WHERE cni.rma_item_id=:item AND r.status IN ('pending','succeeded')),0) AS credited,
                COALESCE((SELECT SUM(re.quantity) FROM return_exchanges re WHERE re.rma_item_id=:item2 AND re.store_id=:store AND re.status!='cancelled'),0) AS exchanged");
        $stmt->execute([':item' => (int)$rmaItem['id'], ':item2' => (int)$rmaItem['id'], ':store' => StoreContext::current()->id()]);
        $reserved = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$rmaItem['received_quantity'] - (int)$reserved['credited'] - (int)$reserved['exchanged'];
    }

    /** Credit notes already issued on this RMA, whatever their refund outcome. */
    public static function creditAttempts(int $rmaId): int {
        $stmt = Db::get()->prepare('SELECT COUNT(*) FROM order_refunds WHERE rma_id=:rma AND store_id=:store AND credit_note_id IS NOT NULL');
        $stmt->execute([':rma' => $rmaId, ':store' => StoreContext::current()->id()]);
        return (int)$stmt->fetchColumn();
    }

    /** rma_item_id => quantity that a further credit note on this RMA may still cover. */
    public static function creditableQuantities(int $rmaId): array {
        $quantities = [];
        foreach (Rma::items($rmaId) as $item) {
            $remaining = self::uncommittedQuantity($item);
            if ($remaining > 0) $quantities[(int)$item['id']] = $remaining;
        }
        return $quantities;
    }

    /**
     * Money of the order not yet returned: the total less provider refunds (whole-order and completed item
     * refunds), pending credit notes, and credit notes settled outside the gateway (no payment id). A pending
     * credit note whose provider refund is already booked on the ledger (under its key or its provider refund
     * id) is in the refunded amount and is not subtracted twice.
     */
    private static function refundableForCredit(array $order): int {
        $stmt = Db::get()->prepare("SELECT
                COALESCE(SUM(CASE WHEN r.status='pending' AND NOT EXISTS (
                    SELECT 1 FROM payment_transactions t
                    WHERE t.order_id=r.order_id AND t.store_id=r.store_id
                      AND t.status IN ('partially_refunded', 'refunded', 'refund_succeeded')
                      AND (t.idempotency_key=r.idempotency_key OR (r.payment_reference IS NOT NULL AND r.payment_reference<>'' AND t.provider_ref=r.payment_reference))
                ) THEN r.amount ELSE 0 END),0) AS pending,
                COALESCE(SUM(CASE WHEN r.status='succeeded' THEN r.amount ELSE 0 END),0) AS succeeded
            FROM order_refunds r WHERE r.order_id=:order AND r.store_id=:store");
        $stmt->execute([':order' => (int)$order['id'], ':store' => StoreContext::current()->id()]);
        $credits = $stmt->fetch(PDO::FETCH_ASSOC);
        $offGateway = trim((string)($order['payment_id'] ?? '')) === '' ? (int)$credits['succeeded'] : 0;
        return (int)$order['total'] - PaymentService::refundedAmount((int)$order['id']) - (int)$credits['pending'] - $offGateway;
    }

    /**
     * Issue an immutable item-level credit note from received RMA quantities.
     * Amounts are deterministic slices of the original financial snapshot.
     */
    public static function credit(
        int $sourceInvoiceId,
        int $rmaId,
        array $quantities,
        string $idempotencyKey
    ): array {
        if ($idempotencyKey === '' || !$quantities) throw new InvalidArgumentException('Credit quantities and idempotency key are required');
        $requestedQuantities = [];
        foreach ($quantities as $itemId => $quantity) $requestedQuantities[(int)$itemId] = (int)$quantity;
        ksort($requestedQuantities, SORT_NUMERIC);
        $quantities = $requestedQuantities;
        $db = Db::get();
        $existing = $db->prepare("SELECT i.*, r.id AS refund_id, r.rma_id FROM order_refunds r JOIN invoices i ON i.id=r.credit_note_id
            WHERE r.store_id=:store AND r.idempotency_key=:key");
        $existing->execute([':store' => StoreContext::current()->id(), ':key' => $idempotencyKey]);
        if ($row = $existing->fetch(PDO::FETCH_ASSOC)) {
            $priorStmt = $db->prepare('SELECT rma_item_id, quantity FROM credit_note_items WHERE refund_id=:refund ORDER BY rma_item_id');
            $priorStmt->execute([':refund' => (int)$row['refund_id']]);
            $priorQuantities = [];
            foreach ($priorStmt->fetchAll(PDO::FETCH_ASSOC) as $line) $priorQuantities[(int)$line['rma_item_id']] = (int)$line['quantity'];
            if ((int)$row['rma_id'] !== $rmaId || (int)$row['refers_to_invoice_id'] !== $sourceInvoiceId || $priorQuantities !== $requestedQuantities) {
                throw new LogicException('Credit idempotency key was reused with different input');
            }
            return $row;
        }

        $source = self::byId($sourceInvoiceId);
        if (!$source || (string)($source['type'] ?? 'invoice') !== 'invoice') throw new RuntimeException('Source invoice not found');
        $rma = Rma::byId($rmaId);
        if (!$rma || (int)$rma['order_id'] !== (int)$source['order_id']
            || !in_array((string)$rma['status'], ['received', 'refunded'], true)) {
            throw new LogicException('RMA is not eligible for a credit note');
        }
        $order = Orders::byId((int)$source['order_id']);
        if (!$order) throw new RuntimeException('Credit note order not found');
        $orderItems = Orders::items((int)$order['id']);
        $rmaItems = [];
        foreach (Rma::items($rmaId) as $item) $rmaItems[(int)$item['id']] = $item;

        $snapshot = json_decode((string)($source['financial_snapshot'] ?? ''), true);
        if (!is_array($snapshot)) throw new RuntimeException('Credit note requires the source financial snapshot');
        $taxProvider = Adapters::tax((string)($snapshot['providers']['tax'] ?? Adapters::defaultProviderCode('tax')));
        $taxProvider::validateSnapshot($snapshot);
        $components = array_values(array_filter($snapshot['components'], static fn(array $component): bool => ($component['type'] ?? '') === 'product'));
        if (count($components) !== count($orderItems)) throw new RuntimeException('Source snapshot does not match order items');
        $componentByItem = [];
        foreach ($orderItems as $position => $orderItem) $componentByItem[(int)$orderItem['id']] = $components[$position];

        $lines = [];
        $totals = ['gross' => 0, 'net' => 0, 'vat' => 0];
        $breakdown = [];
        foreach ($quantities as $rmaItemId => $quantity) {
            $rmaItemId = (int)$rmaItemId;
            $quantity = (int)$quantity;
            $rmaItem = $rmaItems[$rmaItemId] ?? null;
            if (!$rmaItem || $quantity <= 0) throw new InvalidArgumentException('Invalid credit note item');
            if ($quantity > self::uncommittedQuantity($rmaItem)) {
                throw new LogicException('Credit quantity exceeds received uncommitted quantity');
            }
            $orderItemId = (int)$rmaItem['order_item_id'];
            $orderItem = null;
            foreach ($orderItems as $candidate) if ((int)$candidate['id'] === $orderItemId) { $orderItem = $candidate; break; }
            if (!$orderItem || !isset($componentByItem[$orderItemId])) throw new RuntimeException('Credit source item not found');
            $priorStmt = $db->prepare("SELECT COALESCE(SUM(cni.quantity),0) FROM credit_note_items cni
                JOIN order_refunds r ON r.id=cni.refund_id
                WHERE cni.order_item_id=:item AND r.store_id=:store AND r.status IN ('pending','succeeded')");
            $priorStmt->execute([':item' => $orderItemId, ':store' => StoreContext::current()->id()]);
            $before = (int)$priorStmt->fetchColumn();
            // An unfinished SELECT keeps a SQLite read snapshot open, and the write below then fails at once with
            // "database is locked" instead of waiting for a concurrent writer.
            $priorStmt->closeCursor();
            $ordered = (int)$orderItem['quantity'];
            if ($before + $quantity > $ordered) throw new LogicException('Order item is already fully credited');
            $component = $componentByItem[$orderItemId];
            $gross = self::proportionalSegment((int)$component['gross'], $before, $quantity, $ordered);
            $net = self::proportionalSegment((int)$component['net'], $before, $quantity, $ordered);
            $vat = $gross - $net;
            $rate = (int)$component['rate_basis_points'];
            $line = [
                'order_item_id' => $orderItemId, 'rma_item_id' => $rmaItemId,
                'sku' => (string)$orderItem['sku'], 'name' => (string)$orderItem['name'],
                'quantity' => $quantity, 'unit_gross' => (int)$component['unit_gross'],
                'gross' => $gross, 'net' => $net, 'vat' => $vat, 'rate_basis_points' => $rate,
            ];
            $lines[] = $line;
            foreach (['gross', 'net', 'vat'] as $key) $totals[$key] += $line[$key];
            $rateKey = self::rateKey($rate / 100);
            if (!isset($breakdown[$rateKey])) $breakdown[$rateKey] = ['net' => 0, 'vat' => 0];
            $breakdown[$rateKey]['net'] -= $net;
            $breakdown[$rateKey]['vat'] -= $vat;
        }
        if ($totals['gross'] <= 0 || $totals['gross'] > (int)$source['total_gross']) throw new LogicException('Credit total is invalid');
        if ($totals['gross'] > self::refundableForCredit($order)) throw new LogicException('Credit exceeds the amount that can still be refunded');

        $creditData = [
            'version' => 1, 'source_invoice_id' => $sourceInvoiceId,
            'source_fingerprint' => (string)($snapshot['fingerprint'] ?? ''),
            'rounding' => 'source_component_prefix_allocation', 'currency' => (string)$source['currency'],
            'lines' => $lines, 'totals' => $totals,
        ];
        $creditData['fingerprint'] = hash('sha256', json_encode($creditData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $issuedAt = date('Y-m-d');
        // The credit note continues the source invoice's seller (its series and snapshot), not the order's current store.
        [$sellerCode, $store] = self::creditSeller($source, $order);
        $legal = $store['legal'] ?? [];
        $prefix = self::numberPrefix($legal, 'credit_note');
        $year = (int)date('Y');
        $number = '';
        $invoiceId = 0;
        $refundId = 0;
        $db->beginTransaction();
        try {
            // Take the RMA lock an exchange takes (MySQL row lock; SQLite's writer lock), then check the quantities again:
            // the check above ran outside any transaction, so a concurrent exchange or credit note could have committed
            // the same returned units since (customer refunded and sent a replacement for one unit).
            $db->prepare(Db::isMysql() ? 'SELECT id FROM rma_requests WHERE id = ? AND store_id = ? FOR UPDATE'
                : 'UPDATE rma_requests SET id = id WHERE id = ? AND store_id = ?')->execute([$rmaId, StoreContext::current()->id()]);
            $freshItems = array_column(Rma::items($rmaId), null, 'id');
            foreach ($lines as $line) {
                if ($line['quantity'] > self::uncommittedQuantity($freshItems[$line['rma_item_id']] ?? ['id' => $line['rma_item_id'], 'received_quantity' => 0])) {
                    throw new LogicException('Credit quantity exceeds received uncommitted quantity');
                }
            }
            $number = self::allocateNumber($db, $sellerCode, 'credit_note', $year, $prefix);
            $relPath = sprintf('%d/%02d/%s.pdf', $year, (int)date('m'), rawurlencode($number));
            $token = self::tokenHash(bin2hex(random_bytes(32)));
            $insert = $db->prepare("INSERT INTO invoices
                (order_id, store_id, number, issued_at, delivery_date, due_date, pdf_path,
                 total_net, total_vat, total_gross, currency, vat_breakdown,
                 customer_snapshot, seller_snapshot, payment_method, locale, status, download_token,
                 type, refers_to_invoice_id, vat_scenario, reverse_charge_note_key, financial_snapshot, credit_snapshot)
                VALUES (:order, :store, :number, :issued, :delivery, :due, :path,
                 :net, :vat, :gross, :currency, :breakdown, :customer, :seller, :payment, :locale, 'issued', :token,
                 'credit_note', :source, :scenario, :note, :financial, :credit)");
            $insert->execute([
                ':order' => (int)$order['id'], ':store' => StoreContext::current()->id(), ':number' => $number,
                ':issued' => $issuedAt, ':delivery' => $issuedAt, ':due' => $issuedAt, ':path' => $relPath,
                ':net' => -$totals['net'], ':vat' => -$totals['vat'], ':gross' => -$totals['gross'],
                ':currency' => (string)$source['currency'], ':breakdown' => json_encode($breakdown, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':customer' => (string)$source['customer_snapshot'], ':seller' => (string)$source['seller_snapshot'],
                ':payment' => (string)$source['payment_method'], ':locale' => (string)$source['locale'], ':token' => $token,
                ':source' => $sourceInvoiceId, ':scenario' => $source['vat_scenario'] ?? null,
                ':note' => $source['reverse_charge_note_key'] ?? null, ':financial' => (string)$source['financial_snapshot'],
                ':credit' => json_encode($creditData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            $invoiceId = (int)$db->lastInsertId();
            self::recordSeller($db, $invoiceId, $sellerCode);
            $refund = $db->prepare("INSERT INTO order_refunds
                (store_id, order_id, rma_id, credit_note_id, amount, currency, status, idempotency_key)
                VALUES (:store, :order, :rma, :credit, :amount, :currency, 'pending', :key)");
            $refund->execute([
                ':store' => StoreContext::current()->id(), ':order' => (int)$order['id'], ':rma' => $rmaId,
                ':credit' => $invoiceId, ':amount' => $totals['gross'], ':currency' => (string)$source['currency'], ':key' => $idempotencyKey,
            ]);
            $refundId = (int)$db->lastInsertId();
            $lineInsert = $db->prepare("INSERT INTO credit_note_items
                (credit_note_id, refund_id, order_item_id, rma_item_id, sku, name, quantity, unit_gross, gross, net, vat, rate_basis_points)
                VALUES (:credit, :refund, :order_item, :rma_item, :sku, :name, :quantity, :unit, :gross, :net, :vat, :rate)");
            foreach ($lines as $line) {
                $lineInsert->execute([
                    ':credit' => $invoiceId, ':refund' => $refundId, ':order_item' => $line['order_item_id'], ':rma_item' => $line['rma_item_id'],
                    ':sku' => $line['sku'], ':name' => $line['name'], ':quantity' => $line['quantity'], ':unit' => $line['unit_gross'],
                    ':gross' => $line['gross'], ':net' => $line['net'], ':vat' => $line['vat'], ':rate' => $line['rate_basis_points'],
                ]);
            }
            if (trim((string)($order['payment_id'] ?? '')) !== '') {
                $db->prepare("UPDATE orders SET payment_status='refund_pending', updated_at=" . Db::now() . "
                    WHERE id=:id AND store_id=:store AND payment_status IN ('paid','partially_refunded','disputed')")
                    ->execute([':id' => (int)$order['id'], ':store' => StoreContext::current()->id()]);
            }
            AuditLog::log('credit_note', $invoiceId, null, ['source_invoice_id' => $sourceInvoiceId, 'refund_id' => $refundId, 'gross' => $totals['gross']], 'Item credit issued');
            Outbox::record(DomainEvents::ORDER_REFUND_REQUESTED, 'order', (int)$order['id'], [
                'order_id' => (int)$order['id'], 'rma_id' => $rmaId, 'refund_id' => $refundId,
                'credit_note_id' => $invoiceId, 'amount' => $totals['gross'], 'currency' => strtolower((string)$source['currency']),
            ]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        $invoice = self::byId($invoiceId);
        $seller = json_decode((string)$source['seller_snapshot'], true) ?: [];
        $customer = json_decode((string)$source['customer_snapshot'], true) ?: [];
        $pdf = self::renderPdf($invoice, self::creditRows($lines), $seller, $customer,
            ['rates' => $breakdown, 'vat_total' => -$totals['vat'], 'services_only' => self::servicesOnly($order)]);
        self::writePdf(self::resolvePdfPath((string)$invoice['pdf_path']), $pdf);

        if (trim((string)($order['payment_id'] ?? '')) !== '') {
            JobQueue::push('payment_refund', [
                'order_id' => (int)$order['id'], 'payment_id' => (string)$order['payment_id'],
                'amount' => $totals['gross'], 'refund_id' => $refundId,
                'idempotency_key' => $idempotencyKey,
            ], 1);
        }
        $invoice['refund_id'] = $refundId;
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
        $vatBreakdown = ['rates' => $vatRates, 'vat_total' => (int)$invoice['total_vat'], 'services_only' => self::servicesOnly($order ?: [])];

        if (($invoice['type'] ?? 'invoice') === 'credit_note') {
            $stmt = Db::get()->prepare('SELECT name, quantity, net, gross, rate_basis_points FROM credit_note_items WHERE credit_note_id = :id ORDER BY id');
            $stmt->execute([':id' => $invoiceId]);
            $rows = self::creditRows($stmt->fetchAll(PDO::FETCH_ASSOC));
        } else {
            $snapshotJson = trim((string)($invoice['financial_snapshot'] ?? ''));
            $snapshot = $snapshotJson === '' ? null : json_decode($snapshotJson, true, 512, JSON_THROW_ON_ERROR);
            $rows = self::invoiceRows($invoice, $order ?: [], $items, is_array($snapshot) ? $snapshot : null);
        }
        $pdfBin = self::renderPdf($invoice, $rows, $sellerSnap, $custSnap, $vatBreakdown);
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
             WHERE id = :id AND store_id = :store AND status = :issued
        ");
        $stmt->execute([
            ':status' => self::STATUS_CANCELLED,
            ':issued' => self::STATUS_ISSUED,
            ':id'     => $invoiceId,
            ':store'  => StoreContext::current()->id(),
        ]);
        AuditLog::log('invoice_cancelled', $invoiceId, self::STATUS_ISSUED, self::STATUS_CANCELLED, $note);
    }

    public static function byId(int $id): ?array
    {
        $stmt = Db::get()->prepare("SELECT * FROM invoices WHERE id = :id AND store_id = :store");
        $stmt->execute([':id' => $id, ':store' => StoreContext::current()->id()]);
        return $stmt->fetch() ?: null;
    }

    public static function byOrderId(int $orderId): ?array
    {
        // Prefer the live invoice over voided 'duplicate'/'credit_note' rows.
        $stmt = Db::get()->prepare("SELECT * FROM invoices WHERE order_id = :oid AND store_id = :store
            ORDER BY CASE WHEN type = 'invoice' THEN 0 ELSE 1 END, id LIMIT 1");
        $stmt->execute([':oid' => $orderId, ':store' => StoreContext::current()->id()]);
        return $stmt->fetch() ?: null;
    }

    public static function byNumber(string $number): ?array
    {
        $stmt = Db::get()->prepare("SELECT * FROM invoices WHERE number = :n AND store_id = :store");
        $stmt->execute([':n' => $number, ':store' => StoreContext::current()->id()]);
        return $stmt->fetch() ?: null;
    }

    /** The stored form of a download token: a database read never yields a working link. */
    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * A fresh bearer link to the invoice PDF for one e-mail: the previous link stops working, the new one expires after
     * DOWNLOAD_LINK_TTL_DAYS. Only the token's hash is kept, so the link cannot be rebuilt from the database or the mail log.
     */
    public static function downloadLink(int $invoiceId): string
    {
        $token = bin2hex(random_bytes(32));
        Db::get()->prepare('UPDATE invoices SET download_token = :hash, download_token_expires_at = :expires WHERE id = :id')
            ->execute([':hash' => self::tokenHash($token), ':expires' => gmdate('Y-m-d H:i:s', time() + self::DOWNLOAD_LINK_TTL_DAYS * 86400), ':id' => $invoiceId]);
        // In the fragment: never sent to the server, so it stays out of access logs and Referer headers.
        return Template::storeUrl('/account.php?p=invoice') . '#' . $token;
    }

    /** The active store's invoice behind an e-mailed token, unless the link expired. */
    public static function byToken(string $token): ?array
    {
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            return null;
        }
        $stmt = Db::get()->prepare("SELECT * FROM invoices WHERE download_token = :t AND store_id = :store
            AND download_token_expires_at IS NOT NULL AND download_token_expires_at > :now");
        $stmt->execute([':t' => self::tokenHash(strtolower($token)), ':store' => StoreContext::current()->id(), ':now' => gmdate('Y-m-d H:i:s')]);
        return $stmt->fetch() ?: null;
    }

    /** An invoice (or credit note) of the signed-in customer's own order in the active store, or null. */
    public static function ownedBy(int $customerId, int $invoiceId): ?array
    {
        $stmt = Db::get()->prepare("SELECT i.* FROM invoices i JOIN orders o ON o.id = i.order_id
            WHERE i.id = :invoice AND o.customer_id = :customer AND o.store_id = :order_store AND i.store_id = :invoice_store
              AND i.status != :cancelled");
        $stmt->execute([':invoice' => $invoiceId, ':customer' => $customerId, ':order_store' => StoreContext::current()->id(),
            ':invoice_store' => StoreContext::current()->id(), ':cancelled' => self::STATUS_CANCELLED]);
        return $stmt->fetch() ?: null;
    }

    /** Sends an invoice PDF to the browser; nothing of it stays in a (shared) browser's cache. */
    public static function sendPdf(array $invoice): void
    {
        $abs = self::pdfAbsolutePath($invoice);
        if (!is_file($abs)) {
            http_response_code(404);
            exit('PDF missing.');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $invoice['number'] . '.pdf"');
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        readfile($abs);
        exit;
    }

    /** List invoices for a customer (logged-in scope). */
    public static function forCustomer(int $customerId, int $limit = 50): array
    {
        $stmt = Db::get()->prepare("
            SELECT i.* FROM invoices i
            JOIN orders o ON o.id = i.order_id
            WHERE o.customer_id = :cid AND o.store_id = :order_store AND i.store_id = :invoice_store
            ORDER BY i.id DESC LIMIT :lim
        ");
        $stmt->bindValue(':cid', $customerId, PDO::PARAM_INT);
        $stmt->bindValue(':order_store', StoreContext::current()->id());
        $stmt->bindValue(':invoice_store', StoreContext::current()->id());
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Admin paginated list. */
    public static function adminList(?int $year = null, int $offset = 0, int $limit = 50): array
    {
        $where = ['store_id = :store'];
        $params = [':store' => StoreContext::current()->id()];
        if ($year !== null) {
            // Parenthesized so the year filter never escapes the store filter; the issue date (not the number,
            // whose prefix may itself contain dashes, e.g. "CZ-CN-") gives the year.
            $where[] = "(substr(issued_at, 1, 4) = :y)";
            $params[':y'] = (string)$year;
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

    /**
     * Number prefix of a seller's document type: legal.invoice_prefix for invoices; legal.credit_note_prefix for
     * credit notes, by default "<invoice_prefix>CN-" so sellers with distinct invoice prefixes never share a series.
     */
    public static function numberPrefix(array $legal, string $type): string
    {
        $invoicePrefix = (string)($legal['invoice_prefix'] ?? '');
        return $type === 'credit_note' ? (string)($legal['credit_note_prefix'] ?? $invoicePrefix . 'CN-') : $invoicePrefix;
    }

    /** stores.json as InvoiceService reads it (the test override when one is set). */
    public static function storesRoot(): array
    {
        return self::storesConfig();
    }

    /** Replace stores.json for tests (null reads the file again). */
    public static function setStoresConfigForTesting(?array $root): void
    {
        self::$storesOverride = $root;
    }

    /**
     * Seller of a configured store: the legal entity whose invoice and credit-note number series (and counters) the
     * store's documents use.
     * - A store with its own non-empty `legal` block is its own seller, unless legal.seller names another store that
     *   is a seller itself: storefronts of one company then share that store's series and counter (its prefixes;
     *   their own legal fields only override the printed seller details).
     * - A store without a `legal` block is no seller of its own (as before per-seller numbering, when its documents
     *   used the store found by the order's currency): it invoices as the first seller selling in the order's
     *   currency, else as the default store's seller, else as the first seller configured.
     * Null for an unknown store, or a legal.seller that names no seller (numberingConflicts() reports it).
     */
    public static function sellerCode(array $root, string $storeCode, ?string $currency = null): ?string
    {
        $stores = self::storeMap($root);
        if (!isset($stores[$storeCode])) return null;
        $legal = self::legalBlock($stores[$storeCode]);
        if ($legal === null) return self::fallbackSeller($root, $currency);
        $target = trim((string)($legal['seller'] ?? ''));
        if ($target === '' || $target === $storeCode) return $storeCode;
        return self::ownsSeries($stores, $target) ? $target : null;
    }

    /**
     * Number series of the configured sellers that cannot be issued: "store/type" => message. A prefix shared by two
     * series belongs to the default store's seller when it is one of them (its invoices first): the default store's
     * '' / 'CN-' series keep working, the other series sharing the prefix are refused. Without the default seller
     * involved every series sharing the prefix is refused. A legal.seller naming no seller is refused as well.
     * Stores without a legal block are no sellers (see sellerCode()) and never conflict.
     * Reported by cli/setup.php doctor and cli/staging-preflight.php; issuing refuses the series with an incident.
     */
    public static function numberingConflicts(array $root): array
    {
        $stores = self::storeMap($root);
        $byPrefix = [];
        $conflicts = [];
        foreach ($stores as $storeId => $store) {
            $storeId = (string)$storeId;
            $legal = self::legalBlock($store);
            if ($legal === null) continue;
            $target = trim((string)($legal['seller'] ?? ''));
            if ($target !== '' && $target !== $storeId) {
                if (!self::ownsSeries($stores, $target)) {
                    foreach (['invoice', 'credit_note'] as $type) {
                        $conflicts["{$storeId}/{$type}"] = "Store {$storeId}: legal.seller '{$target}' is not a store with its own legal block; name a seller store or remove legal.seller in stores.json";
                    }
                }
                continue;
            }
            foreach (['invoice', 'credit_note'] as $type) $byPrefix[self::numberPrefix($legal, $type)][] = "{$storeId}/{$type}";
        }
        $default = self::defaultSeller($root);
        foreach ($byPrefix as $prefix => $series) {
            if (count($series) < 2) continue;
            $owner = null;
            foreach (['invoice', 'credit_note'] as $type) {
                if ($owner === null && $default !== null && in_array("{$default}/{$type}", $series, true)) $owner = "{$default}/{$type}";
            }
            foreach ($series as $one) {
                if ($one === $owner) continue;
                $conflicts[$one] = 'Number series ' . implode(', ', $series) . " share the prefix '{$prefix}'"
                    . ($owner !== null ? " (it belongs to {$owner})" : '')
                    . '; give each seller its own invoice_prefix / credit_note_prefix in stores.json, or let storefronts of one company share a series with legal.seller';
            }
        }
        return $conflicts;
    }

    /**
     * Prefix => [seller, type] of every number series that may be issued (numberingConflicts() excluded): the owner
     * of a prefix by configuration, also of a legacy shared counter with that prefix (invoice_number_counters migration).
     */
    public static function configuredSeries(?array $root = null): array
    {
        $root ??= self::storesConfig();
        $conflicts = self::numberingConflicts($root);
        $series = [];
        foreach (self::storeMap($root) as $storeId => $store) {
            $legal = self::legalBlock($store);
            if ($legal === null || self::sellerCode($root, $storeId) !== $storeId) continue;
            foreach (['invoice', 'credit_note'] as $type) {
                if (isset($conflicts["{$storeId}/{$type}"])) continue;
                $series[self::numberPrefix($legal, $type)] ??= [$storeId, $type];
            }
        }
        return $series;
    }

    /** @return array<string,array<mixed>> */
    private static function storeMap(array $root): array
    {
        $stores = [];
        foreach ((array)($root['stores'] ?? []) as $code => $store) {
            if (is_array($store)) $stores[(string)$code] = $store;
        }
        return $stores;
    }

    private static function legalBlock(array $store): ?array
    {
        return is_array($store['legal'] ?? null) && $store['legal'] !== [] ? $store['legal'] : null;
    }

    /** True when the store is a seller of its own (own legal block, no legal.seller naming another store). */
    private static function ownsSeries(array $stores, string $code): bool
    {
        $legal = isset($stores[$code]) ? self::legalBlock($stores[$code]) : null;
        if ($legal === null) return false;
        $target = trim((string)($legal['seller'] ?? ''));
        return $target === '' || $target === $code;
    }

    private static function defaultSeller(array $root): ?string
    {
        $default = (string)($root['default_store'] ?? '');
        $stores = self::storeMap($root);
        if ($default === '' || !isset($stores[$default]) || self::legalBlock($stores[$default]) === null) return null;
        return self::sellerCode($root, $default);
    }

    private static function fallbackSeller(array $root, ?string $currency): ?string
    {
        $stores = self::storeMap($root);
        if ($currency !== null && $currency !== '') {
            foreach ($stores as $code => $store) {
                if (self::legalBlock($store) === null || strtolower((string)($store['currency'] ?? '')) !== strtolower($currency)) continue;
                $seller = self::sellerCode($root, $code);
                if ($seller !== null) return $seller;
            }
        }
        $default = self::defaultSeller($root);
        if ($default !== null) return $default;
        foreach (array_keys($stores) as $code) {
            if (self::ownsSeries($stores, $code)) return $code;
        }
        return null;
    }

    /** Legal details a store's documents print: its seller's, with the store's own fields over them (never the prefixes). */
    private static function effectiveLegal(array $root, string $storeCode, string $sellerCode): array
    {
        $stores = self::storeMap($root);
        $sellerLegal = isset($stores[$sellerCode]) ? (self::legalBlock($stores[$sellerCode]) ?? []) : [];
        $own = isset($stores[$storeCode]) ? self::legalBlock($stores[$storeCode]) : null;
        if ($own === null || $storeCode === $sellerCode) return $sellerLegal;
        unset($own['seller'], $own['invoice_prefix'], $own['credit_note_prefix']);
        return array_replace($sellerLegal, $own);
    }

    /** Identity of the seller of $order (name, address, ids, e-mail, phone) for consumer notices; [] when none is configured. */
    public static function sellerIdentity(array $order): array
    {
        $root = self::storesConfig();
        $stores = self::storeMap($root);
        if ($stores === []) return [];
        $orderStore = (string)($order['store_id'] ?? '');
        $code = isset($stores[$orderStore]) ? $orderStore : self::detectStoreCode($order, $root);
        $seller = self::sellerCode($root, $code, strtolower((string)($order['currency'] ?? '')));
        return $seller === null ? [] : self::sellerSnapshot(self::effectiveLegal($root, $code, $seller));
    }

    /**
     * [seller code, store config carrying the seller's effective legal block] for documents of $storeCode in
     * $sellerCode's series of $type. A series numberingConflicts() refuses (or a store without any seller) is reported
     * as an operational incident and refused with the configuration error: the job fails loudly, nothing is numbered twice.
     */
    private static function sellerStore(array $root, string $storeCode, ?string $sellerCode, string $type, int $orderId): array
    {
        $stores = self::storeMap($root);
        if ($stores === []) return [$storeCode, []];
        $conflicts = self::numberingConflicts($root);
        $conflict = $sellerCode === null
            ? ($conflicts["{$storeCode}/{$type}"] ?? "Store {$storeCode} has no seller: add a legal block to a store in stores.json")
            : ($conflicts["{$sellerCode}/{$type}"] ?? null);
        if ($conflict !== null || $sellerCode === null) {
            IncidentMonitor::report('invoice.numbering_conflict', 'invoicing', 'error', ['order_id' => $orderId]);
            throw new \RuntimeException('Invoice numbering configuration conflict: ' . $conflict . ' (see php cli/setup.php doctor)');
        }
        $store = $stores[$storeCode] ?? $stores[$sellerCode] ?? [];
        $store['legal'] = self::effectiveLegal($root, $storeCode, $sellerCode);
        return [$sellerCode, $store];
    }

    /**
     * Seller of a credit note: the seller of its source invoice, never the order's current store. Invoices record
     * their seller (seller_code); for invoices issued before that, the seller whose configured invoice prefix the
     * invoice number carries, else the seller whose legal details the invoice's seller snapshot shows, else the
     * order's seller.
     */
    private static function creditSeller(array $source, array $order): array
    {
        $root = self::storesConfig();
        $stores = self::storeMap($root);
        $seller = null;
        $recorded = (string)($source['seller_code'] ?? '');
        if ($recorded !== '' && isset($stores[$recorded])) $seller = self::sellerCode($root, $recorded);
        $seller ??= self::sellerByInvoiceNumber($root, (string)($source['number'] ?? ''), (string)($source['issued_at'] ?? ''));
        $seller ??= self::sellerBySnapshot($root, json_decode((string)($source['seller_snapshot'] ?? ''), true) ?: []);
        if ($seller === null) return self::resolveStore($order, null, 'credit_note');
        return self::sellerStore($root, $seller, $seller, 'credit_note', (int)($order['id'] ?? 0));
    }

    private static function sellerByInvoiceNumber(array $root, string $number, string $issuedAt): ?string
    {
        $year = substr($issuedAt, 0, 4);
        if (!preg_match('/^\d{4}$/', $year) || !preg_match('/^(.*?)' . $year . '\d{4,}$/s', $number, $m)) return null;
        $series = self::configuredSeries($root)[$m[1]] ?? null;
        return $series !== null && $series[1] === 'invoice' ? $series[0] : null;
    }

    private static function sellerBySnapshot(array $root, array $snapshot): ?string
    {
        $ico = trim((string)($snapshot['ico'] ?? ''));
        $name = trim((string)($snapshot['name'] ?? ''));
        if ($ico === '' && $name === '') return null;
        $stores = self::storeMap($root);
        foreach ($stores as $code => $store) {
            if (!self::ownsSeries($stores, $code)) continue;
            $legal = self::legalBlock($store) ?? [];
            if (trim((string)($legal['ico'] ?? '')) === $ico && trim((string)($legal['name'] ?? '')) === $name) return $code;
        }
        return null;
    }

    /**
     * Next gapless number of one seller's document series (store, type, year, prefix). Runs inside the caller's
     * issuing transaction, so a rolled-back issue gives its number back. A prefix that another seller or document
     * type already numbers in that year is refused: both would issue the same numbers.
     * Before the invoice_number_counters migration ran, numbers come from the legacy per-(year, prefix) table
     * invoice_counters; the migration carries its last numbers over, so no number is issued twice.
     */
    private static function allocateNumber(PDO $db, string $storeId, string $type, int $year, string $prefix): string
    {
        if (!Db::tableExists('invoice_number_counters', $db)) return self::allocateLegacyNumber($db, $year, $prefix);
        $key = [':s' => $storeId, ':t' => $type, ':y' => $year, ':p' => $prefix];
        $shared = $db->prepare("SELECT store_id, type FROM invoice_number_counters
            WHERE year = :y AND prefix = :p AND last_number > 0 AND NOT (store_id = :s AND type = :t) LIMIT 1");
        $shared->execute($key);
        if ($owner = $shared->fetch(PDO::FETCH_ASSOC)) {
            throw new \RuntimeException("Number prefix '{$prefix}' is already used in {$year} by {$owner['store_id']}/{$owner['type']}; "
                . "give store {$storeId} its own " . ($type === 'credit_note' ? 'credit_note_prefix' : 'invoice_prefix') . ' in stores.json');
        }

        // Row-locking increment first, then read back inside the same transaction (portable to SQLite and MySQL/MariaDB).
        // Only the series' first number inserts the counter row: an INSERT IGNORE on an existing row takes a shared
        // lock in MySQL, and two issuers holding it both waited for the other's increment (a deadlock per collision).
        $where = 'store_id = :s AND type = :t AND year = :y AND prefix = :p';
        $increment = $db->prepare("UPDATE invoice_number_counters SET last_number = last_number + 1 WHERE {$where}");
        $increment->execute($key);
        if ($increment->rowCount() === 0) {
            $db->prepare(Db::insertIgnore() . " INTO invoice_number_counters (store_id, type, year, prefix, last_number) VALUES (:s, :t, :y, :p, 0)")
               ->execute($key);
            $increment->execute($key);
        }
        $stmt = $db->prepare("SELECT last_number FROM invoice_number_counters WHERE {$where}");
        $stmt->execute($key);
        $next = (int)$stmt->fetchColumn();
        return sprintf('%s%d%04d', $prefix, $year, $next);
    }

    /** The seller whose series numbered the document (credit notes of it follow it); skipped before its migration ran. */
    private static function recordSeller(PDO $db, int $invoiceId, string $sellerCode): void
    {
        if (!Db::hasColumn('invoices', 'seller_code', $db)) return;
        $db->prepare('UPDATE invoices SET seller_code = :seller WHERE id = :id')->execute([':seller' => $sellerCode, ':id' => $invoiceId]);
    }

    /** Migration window: the pre-upgrade sequence shared per (year, prefix), which the migration carries over. */
    private static function allocateLegacyNumber(PDO $db, int $year, string $prefix): string
    {
        $key = [':y' => $year, ':p' => $prefix];
        $increment = $db->prepare("UPDATE invoice_counters SET last_number = last_number + 1 WHERE year = :y AND prefix = :p");
        $increment->execute($key);
        if ($increment->rowCount() === 0) {
            $db->prepare(Db::insertIgnore() . " INTO invoice_counters (year, prefix, last_number) VALUES (:y, :p, 0)")->execute($key);
            $increment->execute($key);
        }
        $stmt = $db->prepare("SELECT MAX(last_number) FROM invoice_counters WHERE year = :y AND prefix = :p");
        $stmt->execute($key);
        return sprintf('%s%d%04d', $prefix, $year, (int)$stmt->fetchColumn());
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
        $taxProvider = Adapters::tax((string)($snapshot['providers']['tax'] ?? Adapters::defaultProviderCode('tax')));
        $taxProvider::validateSnapshot($snapshot);
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

    /** Deterministic prefix allocation; all partial credits sum exactly to the source component. */
    private static function proportionalSegment(int $total, int $before, int $quantity, int $ordered): int
    {
        if ($total < 0 || $before < 0 || $quantity <= 0 || $ordered <= 0 || $before + $quantity > $ordered) {
            throw new InvalidArgumentException('Invalid proportional credit allocation');
        }
        return intdiv($total * ($before + $quantity), $ordered) - intdiv($total * $before, $ordered);
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

    /**
     * [seller code, store config] of the order's legal seller: the seller of the explicit store code, else of the
     * order's own store, else of the first store selling in the order's currency (orders written before stores were
     * scoped). See sellerCode(): a store without a legal block invoices as another seller. Refuses (with an incident)
     * a series numberingConflicts() reports.
     */
    private static function resolveStore(array $order, ?string $storeCode, string $type = 'invoice'): array
    {
        $root = self::storesConfig();
        $stores = self::storeMap($root);
        $orderStore = (string)($order['store_id'] ?? '');
        $code = $storeCode ?? (isset($stores[$orderStore]) ? $orderStore : self::detectStoreCode($order, $root));
        if (!isset($stores[$code])) $code = (string)(array_key_first($stores) ?? $code);
        $seller = self::sellerCode($root, $code, strtolower((string)($order['currency'] ?? '')));
        return self::sellerStore($root, $code, $seller, $type, (int)($order['id'] ?? 0));
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
        if (self::$storesOverride !== null) return self::$storesOverride;
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
    /**
     * Document lines of an invoice. Every line carries its net and gross amount, and the lines add up exactly to the
     * invoice totals: products before discount, the discount per VAT rate, shipping, the payment fee and, for legacy
     * orders without a financial snapshot, a rounding line for whatever the stored totals differ by.
     * @return list<array{name?:string, label?:string, qty?:int, rate:float, net:int, gross:int}>
     */
    public static function invoiceRows(array $invoice, array $order, array $items, ?array $snapshot): array
    {
        $rows = [];
        if ($snapshot !== null) {
            $items = array_values($items);
            $discounts = [];
            $position = 0;
            foreach ($snapshot['components'] as $component) {
                $rate = (int)$component['rate_basis_points'] / 100;
                if ($component['type'] !== 'product') {
                    $rows[] = ['label' => $component['type'] === 'shipping' ? 'shipping' : 'payment_fee', 'rate' => $rate,
                        'net' => (int)$component['net'], 'gross' => (int)$component['gross']];
                    continue;
                }
                $grossBefore = (int)$component['gross_before_discount'];
                [$netBefore] = self::extractVat($grossBefore, $rate);
                $name = (string)($items[$position]['name'] ?? $component['sku']);
                $position++;
                $rows[] = ['name' => $name, 'qty' => (int)$component['quantity'], 'rate' => $rate, 'net' => $netBefore, 'gross' => $grossBefore];
                if ($grossBefore !== (int)$component['gross']) {
                    $key = self::rateKey($rate);
                    $discounts[$key] ??= ['rate' => $rate, 'net' => 0, 'gross' => 0];
                    $discounts[$key]['net'] += $netBefore - (int)$component['net'];
                    $discounts[$key]['gross'] += $grossBefore - (int)$component['gross'];
                }
            }
            $discountRows = array_map(static fn(array $d): array => ['label' => 'discount', 'rate' => $d['rate'], 'net' => -$d['net'], 'gross' => -$d['gross']], array_values($discounts));
            $productRows = array_values(array_filter($rows, static fn(array $row): bool => isset($row['name'])));
            $otherRows = array_values(array_filter($rows, static fn(array $row): bool => !isset($row['name'])));
            return array_merge($productRows, $discountRows, $otherRows);
        }

        $vatApplies = !in_array((string)($order['vat_scenario'] ?? ''), ['reverse_charge', 'export', 'non_vat_payer'], true);
        $maxRate = 0.0;
        foreach ($items as $item) {
            $rate = $vatApplies ? (float)($item['vat_rate'] ?? 0) : 0.0;
            $maxRate = max($maxRate, $rate);
            [$net] = self::extractVat((int)$item['total'], $rate);
            $rows[] = ['name' => (string)$item['name'], 'qty' => (int)$item['quantity'], 'rate' => $rate, 'net' => $net, 'gross' => (int)$item['total']];
        }
        foreach (['discount' => -(int)($order['discount_amount'] ?? 0), 'shipping' => (int)($order['shipping_cost'] ?? 0),
                  'payment_fee' => (int)($order['payment_fee'] ?? 0)] as $label => $gross) {
            if ($gross === 0) continue;
            $rate = $label === 'payment_fee' ? 0.0 : $maxRate;
            [$net] = self::extractVat(abs($gross), $rate);
            $rows[] = ['label' => $label, 'rate' => $rate, 'net' => $gross < 0 ? -$net : $net, 'gross' => $gross];
        }
        $netLeft = (int)$invoice['total_net'] - array_sum(array_column($rows, 'net'));
        $grossLeft = (int)$invoice['total_gross'] - array_sum(array_column($rows, 'gross'));
        if ($netLeft !== 0 || $grossLeft !== 0) $rows[] = ['label' => 'rounding', 'rate' => 0.0, 'net' => $netLeft, 'gross' => $grossLeft];
        return $rows;
    }

    /**
     * Lines of a credit note: the credited quantities with their own net and VAT, negative.
     * @return list<array{name:string, qty:int, rate:float, net:int, gross:int}>
     */
    private static function creditRows(array $lines): array
    {
        return array_map(static fn(array $line): array => [
            'name' => (string)$line['name'], 'qty' => (int)$line['quantity'], 'rate' => (int)$line['rate_basis_points'] / 100,
            'net' => -abs((int)$line['net']), 'gross' => -abs((int)$line['gross']),
        ], $lines);
    }

    private static function renderPdf(
        array $invoice, array $rows,
        array $sellerSnap, array $customerSnap, array $vatBreakdown
    ): string {
        $mpdfAuto = self::baseDir() . '/vendor/mpdf/vendor/autoload.php';
        if (file_exists($mpdfAuto) && class_exists('Mpdf\\Mpdf', false)) {
            // mPDF integration intentionally minimal — the heavy lifting belongs
            // in a dedicated renderer file shipped alongside vendor/mpdf.
            // For v1 we fall through to MinimalPdf even if mPDF is present.
        }
        return self::renderMinimalPdf($invoice, $rows, $sellerSnap, $customerSnap, $vatBreakdown);
    }

    /** Country whose national VAT act the exemption wording of a document language cites. */
    private const NOTICE_LAW_COUNTRY = ['sk' => 'SK', 'cs' => 'CZ', 'pl' => 'PL', 'hu' => 'HU', 'de' => 'DE', 'es' => 'ES'];

    /**
     * Text of the document's VAT notice (exemption / reverse charge wording), or null. The exemption is granted by
     * the seller's country: the national act a language's wording cites is only quoted for a seller established
     * there ($sellerCountry), anyone else gets the same wording citing only the VAT Directive.
     */
    public static function vatNotice(array $invoice, string $locale, bool $servicesOnly, string $sellerCountry): ?string
    {
        $noteKey = str_replace('vat.', '', (string)($invoice['reverse_charge_note_key'] ?? ''));
        if ($noteKey === '') return null;
        // Electronically supplied services to an EU business are not an exempt supply of goods: the customer
        // accounts for the VAT (Art. 196 of the VAT Directive).
        if ($noteKey === 'reverse_charge_note' && $servicesOnly) $noteKey = 'reverse_charge_services_note';
        $labels = self::loadInvoiceLabels($locale);
        $lawCountry = self::NOTICE_LAW_COUNTRY[$locale] ?? null;
        if ($lawCountry !== null && strtoupper(trim($sellerCountry)) !== $lawCountry && isset($labels[$noteKey . '_eu'])) {
            return $labels[$noteKey . '_eu'];
        }
        return $labels[$noteKey] ?? null;
    }

    private static function renderMinimalPdf(
        array $invoice, array $rows,
        array $sellerSnap, array $customerSnap, array $vatBreakdown
    ): string {
        $locale = (string)$invoice['locale'];
        $L = self::loadInvoiceLabels($locale);
        $currency = (string)$invoice['currency'];

        $pdf = new MinimalPdf();
        $pdf->addPage();

        $y = 18.0;
        // Title
        $creditNote = ($invoice['type'] ?? 'invoice') === 'credit_note';
        $documentTitle = $creditNote ? $L['credit_title'] : $L['title'];
        $pdf->text(20, $y, $documentTitle . ' ' . $invoice['number'], ['size' => 18, 'bold' => true]);

        // Right-aligned dates
        $pdf->text(120, $y, $L['issued_at'] . ': ' . $invoice['issued_at'], ['size' => 9]);
        $pdf->text(120, $y + 5, $L['delivery_date'] . ': ' . $invoice['delivery_date'], ['size' => 9]);
        $pdf->text(120, $y + 10, $L['due_date'] . ': ' . $invoice['due_date'], ['size' => 9]);

        // A credit note names the invoice it corrects.
        $source = $creditNote && !empty($invoice['refers_to_invoice_id']) ? self::byId((int)$invoice['refers_to_invoice_id']) : null;
        if ($source !== null) {
            $pdf->text(20, $y + 10, $L['refers_to'] . ' ' . $source['number'] . ' (' . $L['issued_at'] . ': ' . $source['issued_at'] . ')', ['size' => 9]);
        }

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

        // Items header (repeated on every continuation page)
        $itemsHeader = static function () use ($pdf, $L, &$y): void {
            $pdf->text(20, $y, $L['col_item'], ['size' => 8, 'bold' => true]);
            $pdf->text(80, $y, $L['col_qty'], ['size' => 8, 'bold' => true, 'align' => 'right', 'width' => 12]);
            $pdf->text(94, $y, $L['col_unit_net'], ['size' => 8, 'bold' => true, 'align' => 'right', 'width' => 26]);
            $pdf->text(122, $y, $L['col_vat'], ['size' => 8, 'bold' => true, 'align' => 'right', 'width' => 12]);
            $pdf->text(136, $y, $L['col_net_total'], ['size' => 8, 'bold' => true, 'align' => 'right', 'width' => 26]);
            $pdf->text(164, $y, $L['col_total'], ['size' => 8, 'bold' => true, 'align' => 'right', 'width' => 26]);
            $y += 4;
            $pdf->line(20, $y, 190, $y);
            $y += 4;
        };
        // Start a new A4 page when the next block would run into the footer area (y ≥ 280 mm);
        // long invoices used to keep writing below the page edge, losing items and totals.
        $ensureSpace = static function (float $needed, bool $withItemsHeader = false) use ($pdf, $itemsHeader, &$y): void {
            if ($y + $needed <= self::PDF_CONTENT_BOTTOM_MM) return;
            $pdf->addPage();
            $y = 20.0;
            if ($withItemsHeader) $itemsHeader();
        };
        $itemsHeader();

        // Each line shows its OWN VAT rate (mixed-rate invoices: a 23% line and a 10% line coexist) and its price
        // without VAT; the lines add up to the totals below.
        foreach ($rows as $row) {
            $name = isset($row['label']) ? $L[$row['label']] . ($row['label'] === 'discount' ? ' ' . self::rateKey($row['rate']) . '%' : '') : (string)$row['name'];
            $ensureSpace(5, true);
            $pdf->text(20, $y, mb_substr($name, 0, 34), ['size' => 8]);
            if (isset($row['qty']) && $row['qty'] !== 0) {
                $pdf->text(80, $y, (string)$row['qty'], ['size' => 8, 'align' => 'right', 'width' => 12]);
                $pdf->text(94, $y, I18n::price((int)round($row['net'] / $row['qty']), $currency), ['size' => 8, 'align' => 'right', 'width' => 26]);
            }
            $pdf->text(122, $y, self::rateKey($row['rate']) . '%', ['size' => 8, 'align' => 'right', 'width' => 12]);
            $pdf->text(136, $y, I18n::price($row['net'], $currency), ['size' => 8, 'align' => 'right', 'width' => 26]);
            $pdf->text(164, $y, I18n::price($row['gross'], $currency), ['size' => 8, 'align' => 'right', 'width' => 26]);
            $y += 5;
        }

        $pdf->line(20, $y, 190, $y);
        $y += 6;

        // Totals
        $ensureSpace(18);
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
            $ensureSpace(10);
            $pdf->text(20, $y, $L['vat_recap'], ['size' => 9, 'bold' => true]);
            $y += 5;
            foreach ($vatBreakdown['rates'] as $r => $info) {
                $ensureSpace(5);
                $pdf->text(20, $y, $r . '%', ['size' => 9]);
                $pdf->text(40, $y, $L['col_net'] . ' ' . I18n::price((int)$info['net'], $currency), ['size' => 9]);
                $pdf->text(100, $y, $L['col_vat'] . ' ' . I18n::price((int)$info['vat'], $currency), ['size' => 9]);
                $y += 5;
            }
            $y += 3;
        }

        // VAT scenario notice: the legal ground of the exemption / reverse charge, wrapped to the page width.
        $noteKey = (string)($invoice['reverse_charge_note_key'] ?? $vatBreakdown['note_key'] ?? '');
        $notice = self::vatNotice(['reverse_charge_note_key' => $noteKey] + $invoice, $locale, !empty($vatBreakdown['services_only']), (string)($sellerSnap['country'] ?? ''));
        if ($notice !== null) {
            foreach (explode("\n", wordwrap($notice, 95, "\n", true)) as $noticeLine) {
                $ensureSpace(5);
                $pdf->text(20, $y, $noticeLine, ['size' => 9, 'bold' => true]);
                $y += 5;
            }
        }

        // Payment info
        $ensureSpace(15);
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
                'vat_recap' => 'Rekapitulácia DPH', 'col_net' => 'Základ', 'reverse_charge_note' => 'Dodanie tovaru do iného členského štátu oslobodené od dane podľa § 43 zákona o DPH (čl. 138 smernice 2006/112/ES)',
                'export_note' => 'Vývoz tovaru oslobodený od dane podľa § 47 zákona o DPH (čl. 146 smernice 2006/112/ES)', 'non_vat_payer_note' => 'Predávajúci nie je platiteľom DPH',
                'payment_method' => 'Spôsob úhrady', 'iban' => 'IBAN', 'variable_symbol' => 'Variabilný symbol',
                'ico' => 'IČO', 'dic' => 'DIČ', 'ic_dph' => 'IČ DPH',
                'reverse_charge_note_eu' => 'Dodanie tovaru do iného členského štátu oslobodené od dane (čl. 138 smernice 2006/112/ES)', 'export_note_eu' => 'Vývoz tovaru oslobodený od dane (čl. 146 smernice 2006/112/ES)',
                'reverse_charge_services_note' => 'Prenesenie daňovej povinnosti — daň odvádza príjemca služby (čl. 196 smernice 2006/112/ES)',
                'credit_title' => 'Dobropis č.',
                'refers_to' => 'Opravný doklad k faktúre č.',
                'discount' => 'Zľava',
                'payment_fee' => 'Poplatok za platbu',
                'rounding' => 'Zaokrúhlenie',
                'col_unit_net' => 'J. cena bez DPH',
                'col_net_total' => 'Spolu bez DPH',
            ],
            'cs' => [
                'title' => 'Faktura č.', 'issued_at' => 'Datum vystavení',
                'delivery_date' => 'Datum dodání', 'due_date' => 'Splatnost',
                'seller' => 'Prodávající', 'customer' => 'Odběratel',
                'col_item' => 'Položka', 'col_qty' => 'Množ.', 'col_unit_price' => 'J. cena',
                'col_vat' => 'DPH', 'col_total' => 'Celkem',
                'shipping' => 'Doprava',
                'total_net' => 'Celkem bez DPH', 'total_vat' => 'DPH celkem', 'total_gross' => 'Celkem k úhradě',
                'vat_recap' => 'Rekapitulace DPH', 'col_net' => 'Základ', 'reverse_charge_note' => 'Dodání zboží do jiného členského státu osvobozené od daně podle § 64 zákona o DPH (čl. 138 směrnice 2006/112/ES)',
                'export_note' => 'Vývoz zboží osvobozený od daně podle § 66 zákona o DPH (čl. 146 směrnice 2006/112/ES)', 'non_vat_payer_note' => 'Prodávající není plátcem DPH',
                'payment_method' => 'Způsob úhrady', 'iban' => 'IBAN', 'variable_symbol' => 'Variabilní symbol',
                'ico' => 'IČO', 'dic' => 'DIČ', 'ic_dph' => 'DIČ (pro DPH)',
                'reverse_charge_note_eu' => 'Dodání zboží do jiného členského státu osvobozené od daně (čl. 138 směrnice 2006/112/ES)', 'export_note_eu' => 'Vývoz zboží osvobozený od daně (čl. 146 směrnice 2006/112/ES)',
                'reverse_charge_services_note' => 'Daň odvede zákazník — přenesení daňové povinnosti (čl. 196 směrnice 2006/112/ES)',
                'credit_title' => 'Dobropis č.',
                'refers_to' => 'Opravný daňový doklad k faktuře č.',
                'discount' => 'Sleva',
                'payment_fee' => 'Poplatek za platbu',
                'rounding' => 'Zaokrouhlení',
                'col_unit_net' => 'J. cena bez DPH',
                'col_net_total' => 'Celkem bez DPH',
            ],
            'pl' => [
                'title' => 'Faktura nr', 'issued_at' => 'Data wystawienia',
                'delivery_date' => 'Data dostawy', 'due_date' => 'Termin płatności',
                'seller' => 'Sprzedawca', 'customer' => 'Nabywca',
                'col_item' => 'Pozycja', 'col_qty' => 'Ilość', 'col_unit_price' => 'Cena j.',
                'col_vat' => 'VAT', 'col_total' => 'Razem',
                'shipping' => 'Wysyłka',
                'total_net' => 'Razem netto', 'total_vat' => 'VAT razem', 'total_gross' => 'Do zapłaty',
                'vat_recap' => 'Rekapitulacja VAT', 'col_net' => 'Netto', 'reverse_charge_note' => 'Wewnątrzwspólnotowa dostawa towarów, stawka 0% (art. 42 ustawy o VAT, art. 138 dyrektywy 2006/112/WE)',
                'export_note' => 'Eksport towarów, stawka 0% (art. 41 ust. 4 ustawy o VAT, art. 146 dyrektywy 2006/112/WE)', 'non_vat_payer_note' => 'Sprzedawca nie jest czynnym podatnikiem VAT',
                'payment_method' => 'Forma płatności', 'iban' => 'IBAN', 'variable_symbol' => 'Nr referencyjny',
                'ico' => 'NIP/REGON', 'dic' => 'NIP', 'ic_dph' => 'NIP UE',
                'reverse_charge_note_eu' => 'Wewnątrzwspólnotowa dostawa towarów zwolniona z podatku (art. 138 dyrektywy 2006/112/WE)', 'export_note_eu' => 'Eksport towarów zwolniony z podatku (art. 146 dyrektywy 2006/112/WE)',
                'reverse_charge_services_note' => 'Odwrotne obciążenie — podatek rozlicza nabywca (art. 196 dyrektywy 2006/112/WE)',
                'credit_title' => 'Faktura korygująca nr',
                'refers_to' => 'Korekta faktury nr',
                'discount' => 'Rabat',
                'payment_fee' => 'Opłata za płatność',
                'rounding' => 'Zaokrąglenie',
                'col_unit_net' => 'Cena j. netto',
                'col_net_total' => 'Wartość netto',
            ],
            'hu' => [
                'title' => 'Számla sz.', 'issued_at' => 'Kiállítás dátuma',
                'delivery_date' => 'Teljesítés dátuma', 'due_date' => 'Fizetési határidő',
                'seller' => 'Eladó', 'customer' => 'Vevő',
                'col_item' => 'Tétel', 'col_qty' => 'Menny.', 'col_unit_price' => 'Egys. ár',
                'col_vat' => 'ÁFA', 'col_total' => 'Összesen',
                'shipping' => 'Szállítás',
                'total_net' => 'Nettó összesen', 'total_vat' => 'ÁFA összesen', 'total_gross' => 'Fizetendő összesen',
                'vat_recap' => 'ÁFA összesítés', 'col_net' => 'Nettó', 'reverse_charge_note' => 'Adómentes Közösségen belüli termékértékesítés (Áfa tv. 89. §, 2006/112/EK irányelv 138. cikk)',
                'export_note' => 'Adómentes termékexport (Áfa tv. 98. §, 2006/112/EK irányelv 146. cikk)', 'non_vat_payer_note' => 'Az eladó nem ÁFA-alany',
                'payment_method' => 'Fizetési mód', 'iban' => 'IBAN', 'variable_symbol' => 'Hivatkozási szám',
                'ico' => 'Cégjegyzékszám', 'dic' => 'Adószám', 'ic_dph' => 'Közösségi adószám',
                'reverse_charge_note_eu' => 'Adómentes Közösségen belüli termékértékesítés (2006/112/EK irányelv 138. cikk)', 'export_note_eu' => 'Adómentes termékexport (2006/112/EK irányelv 146. cikk)',
                'reverse_charge_services_note' => 'Fordított adózás — az adót a vevő fizeti (2006/112/EK irányelv 196. cikk)',
                'credit_title' => 'Helyesbítő számla sz.',
                'refers_to' => 'Az alábbi számla helyesbítése:',
                'discount' => 'Kedvezmény',
                'payment_fee' => 'Fizetési díj',
                'rounding' => 'Kerekítés',
                'col_unit_net' => 'Nettó egységár',
                'col_net_total' => 'Nettó érték',
            ],
            'de' => [
                'title' => 'Rechnung Nr.', 'issued_at' => 'Rechnungsdatum',
                'delivery_date' => 'Leistungsdatum', 'due_date' => 'Fällig am',
                'seller' => 'Verkäufer', 'customer' => 'Kunde',
                'col_item' => 'Position', 'col_qty' => 'Menge', 'col_unit_price' => 'Einzelpreis',
                'col_vat' => 'MwSt.', 'col_total' => 'Gesamt',
                'shipping' => 'Versand',
                'total_net' => 'Netto gesamt', 'total_vat' => 'MwSt. gesamt', 'total_gross' => 'Gesamtbetrag',
                'vat_recap' => 'MwSt.-Aufschlüsselung', 'col_net' => 'Netto', 'reverse_charge_note' => 'Steuerfreie innergemeinschaftliche Lieferung (§ 4 Nr. 1b i.V.m. § 6a UStG, Art. 138 MwStSystRL)',
                'export_note' => 'Steuerfreie Ausfuhrlieferung (§ 4 Nr. 1a i.V.m. § 6 UStG, Art. 146 MwStSystRL)', 'non_vat_payer_note' => 'Der Verkäufer ist nicht umsatzsteuerpflichtig',
                'payment_method' => 'Zahlungsart', 'iban' => 'IBAN', 'variable_symbol' => 'Verwendungszweck',
                'ico' => 'HR-Nr.', 'dic' => 'Steuernr.', 'ic_dph' => 'USt-IdNr.',
                'reverse_charge_note_eu' => 'Steuerfreie innergemeinschaftliche Lieferung (Art. 138 MwStSystRL)', 'export_note_eu' => 'Steuerfreie Ausfuhrlieferung (Art. 146 MwStSystRL)',
                'reverse_charge_services_note' => 'Steuerschuldnerschaft des Leistungsempfängers — Reverse Charge (Art. 196 MwStSystRL)',
                'credit_title' => 'Rechnungskorrektur Nr.',
                'refers_to' => 'Korrektur zur Rechnung Nr.',
                'discount' => 'Rabatt',
                'payment_fee' => 'Zahlungsgebühr',
                'rounding' => 'Rundung',
                'col_unit_net' => 'Einzelpreis netto',
                'col_net_total' => 'Netto',
            ],
            'es' => [
                'title' => 'Factura n.º', 'issued_at' => 'Fecha de emisión',
                'delivery_date' => 'Fecha de entrega', 'due_date' => 'Vencimiento',
                'seller' => 'Vendedor', 'customer' => 'Cliente',
                'col_item' => 'Artículo', 'col_qty' => 'Cant.', 'col_unit_price' => 'Precio unit.',
                'col_vat' => 'IVA', 'col_total' => 'Total',
                'shipping' => 'Envío',
                'total_net' => 'Total sin IVA', 'total_vat' => 'IVA total', 'total_gross' => 'Total a pagar',
                'vat_recap' => 'Desglose del IVA', 'col_net' => 'Base', 'reverse_charge_note' => 'Entrega intracomunitaria de bienes exenta (art. 25 Ley 37/1992 del IVA, art. 138 Directiva 2006/112/CE)',
                'export_note' => 'Exportación de bienes exenta (art. 21 Ley 37/1992 del IVA, art. 146 Directiva 2006/112/CE)', 'non_vat_payer_note' => 'El vendedor no está registrado a efectos del IVA',
                'payment_method' => 'Forma de pago', 'iban' => 'IBAN', 'variable_symbol' => 'Referencia',
                'ico' => 'N.º de registro', 'dic' => 'NIF', 'ic_dph' => 'NIF-IVA',
                'reverse_charge_note_eu' => 'Entrega intracomunitaria de bienes exenta (art. 138 Directiva 2006/112/CE)', 'export_note_eu' => 'Exportación de bienes exenta (art. 146 Directiva 2006/112/CE)',
                'reverse_charge_services_note' => 'Inversión del sujeto pasivo (art. 196 Directiva 2006/112/CE)',
                'credit_title' => 'Factura rectificativa n.º', 'refers_to' => 'Rectifica la factura n.º', 'discount' => 'Descuento',
                'payment_fee' => 'Comisión de pago', 'rounding' => 'Redondeo', 'col_unit_net' => 'Precio unit. sin IVA', 'col_net_total' => 'Sin IVA',
            ],
            'en' => [
                'title' => 'Invoice No.', 'issued_at' => 'Issue date',
                'delivery_date' => 'Delivery date', 'due_date' => 'Due date',
                'seller' => 'Seller', 'customer' => 'Customer',
                'col_item' => 'Item', 'col_qty' => 'Qty', 'col_unit_price' => 'Unit price',
                'col_vat' => 'VAT', 'col_total' => 'Total',
                'shipping' => 'Shipping',
                'total_net' => 'Total net', 'total_vat' => 'Total VAT', 'total_gross' => 'Total due',
                'vat_recap' => 'VAT breakdown', 'col_net' => 'Net', 'reverse_charge_note' => 'Exempt intra-Community supply of goods (Article 138 of Directive 2006/112/EC)',
                'export_note' => 'Exempt export of goods (Article 146 of Directive 2006/112/EC)', 'non_vat_payer_note' => 'The seller is not registered for VAT',
                'payment_method' => 'Payment method', 'iban' => 'IBAN', 'variable_symbol' => 'Reference',
                'ico' => 'Company ID', 'dic' => 'Tax ID', 'ic_dph' => 'VAT ID',
                'reverse_charge_services_note' => 'Reverse charge — VAT to be accounted for by the recipient (Article 196 of Directive 2006/112/EC)',
                'credit_title' => 'Credit note No.',
                'refers_to' => 'Corrects invoice No.',
                'discount' => 'Discount',
                'payment_fee' => 'Payment fee',
                'rounding' => 'Rounding',
                'col_unit_net' => 'Unit price excl. VAT',
                'col_net_total' => 'Excl. VAT',
            ],
        ];
        return $all[$locale] ?? $all['en'];
    }
}
