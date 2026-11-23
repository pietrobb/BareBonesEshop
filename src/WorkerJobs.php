<?php
/**
 * Job handlers of cli/worker.php. They live in a class, not as global functions: the page-request worker
 * (WebWorker) runs cli/worker.php inside an admin or storefront request, whose own global functions
 * (e.g. the admin handleCacheRebuild()) would otherwise collide with "Cannot redeclare" — a fatal error.
 */
final class WorkerJobs
{
    public static function orderProviderCode(array $order, string $type): string {
        $snapshot = json_decode((string)($order['financial_snapshot'] ?? ''), true) ?: [];
        return strtolower((string)($snapshot['providers'][$type] ?? ''));
    }

    /**
     * R24: the provider an order's e-mail goes out through. Checkout freezes the mail provider with the others, but an
     * order placed before the shop's SMTP was set up froze 'log' (the default, which only writes the mail to the log):
     * its payment, shipping and invoice mails went to the log for good, reported as sent. The mail transport is no term
     * of the order, so 'log' (or a provider no longer installed) gives way to the one configured now.
     * R25: so does a real one. An order frozen on SMTP still mailed real customers after the shop was switched to 'log'
     * (a staging copy of the database), and one frozen on SES failed every attempt once the shop moved to SMTP. Every
     * order mail goes through the provider configured now; the order's frozen code (and a job's _provider_code) is not consulted.
     * A MAIL_ADAPTER naming no installed provider writes the mail to the log (it reaches nobody) and raises an incident,
     * instead of failing every order mail (R24) or sending through a provider nobody chose (MailService::providerCode()).
     */
    public static function orderMailProvider(?array $order, ?bool &$fellBack = null): string {
        return MailService::providerCode($order, $fellBack);
    }

    /** R26: how often an order mail written to the log for a misconfigured provider is tried again, and how far apart. */
    private const MAIL_FALLBACK_RETRIES = 48;
    private const MAIL_FALLBACK_DELAY = 1800;

    /**
     * Outbox sweep (see OutboxDispatcher): per-endpoint failures are recorded and retried with
     * Outbox::markRetry without blocking other endpoints, and the sweep is time-boxed.
     */
    public static function processOutbox(array $cfg, bool $verbose = false): void {
        OutboxDispatcher::run($cfg, $verbose);
    }

    public static function handleCacheRebuild(array $payload, array $job): void {
        CacheRebuild::run($payload, require AppConfig::path(dirname(__DIR__)));
    }

    public static function handleEmailSend(array $payload, array $job): void {
        $template = (string)($payload['template'] ?? '');
        $to       = (string)($payload['to'] ?? '');
        $orderId  = $payload['order_id'] ?? null;
        $locale   = (string)($payload['locale'] ?? '');
        $order = null;

        $allowedTemplates = [
            'order_confirmation', 'order_paid', 'order_cancelled', 'order_shipped',
            'refund_issued', 'invoice_issued', 'cart_reminder', 'bank_transfer_reminder',
        ];
        if (!$to || !in_array($template, $allowedTemplates, true)) return;
        if ($orderId === null && $template !== 'cart_reminder') return;
        if ($template === 'cart_reminder' && AbandonedCarts::isSuppressed($to)) return;

        $vars = (array)($payload['vars'] ?? []);
        if ($template === 'cart_reminder' && isset($payload['cart_id'])) {
            $vars = AbandonedCarts::reminderVars((int)$payload['cart_id'], (string)($payload['base_url'] ?? ''));
            if ($vars === null) return;
        }
        if ($orderId) {
            $order = Orders::byId((int)$orderId);
            if ($order) {
                // Order-linked jobs cannot redirect mail to a payload-controlled recipient.
                $to = (string)$order['customer_email'];
                // Prefer locale from order row, then payload, then default.
                if ($locale === '') $locale = (string)($order['locale'] ?? '');
                $currency = $order['currency'] ?? null;
                // Admin shipments carry the carrier and tracking; the legacy order columns are the fallback. A partial
                // shipment's e-mail names its parcel: that parcel's tracking or none, never another parcel's.
                $parcelId = isset($payload['shipment_id']) ? (int)$payload['shipment_id'] : null;
                $tracking = Fulfillment::latestTracking((int)$orderId, $parcelId);
                $carrier = trim(MethodLabels::carrier((string)($tracking['carrier'] ?? '') ?: (string)($order['carrier'] ?? ''), $locale));
                $vars = array_merge($vars, [
                    'order_number'   => $order['order_number'],
                    'order_status'   => OrderStatusLabels::order((string)$order['status'], $locale),
                    'order_total'    => I18n::price((int)$order['total'], $currency),
                    'customer_name'  => $order['customer_name'],
                    'customer_email' => $order['customer_email'],
                    'payment_method' => PaymentService::orderLabel($order, $locale !== '' ? $locale : null) ?: (string)($order['payment_method'] ?? ''),
                    'carrier'        => $carrier,
                    // Shipped by the merchant's own delivery ("Create and ship" without a carrier): the e-mail names none.
                    'without_carrier'=> $carrier === '',
                    'tracking_number'=> (string)($tracking['tracking_number'] ?? '') ?: ($parcelId === null ? (string)($order['tracking_number'] ?? '') : ''),
                    'tracking_url'   => (string)($tracking['tracking_url'] ?? ''),
                ]);
                if ($template === 'order_confirmation') $vars = array_merge($vars, BankTransferInstructions::mailVars($order), ConsumerNotice::mailVars($order), OrderAddress::mailVars($order, $locale));
                if ($template === 'refund_issued') {
                    // The refund just booked (partial, RMA credit note, by hand), not the order total; a legacy job names none.
                    $refundKey = (string)($payload['refund_key'] ?? '');
                    if ($refundKey !== '' && !PaymentService::refundBooked((int)$orderId, $refundKey)) return;
                    // R38 (usability): the credit note travels with the e-mail. Its job was queued with this one; while it
                    // is still open the mail waits for it (5 minutes, three times), then goes without it.
                    $refundCreditNote = $refundKey !== '' ? InvoiceService::refundCreditNote((int)$orderId, $refundKey) : null;
                    $creditWaits = (int)($payload['credit_note_waits'] ?? 0);
                    if ($refundKey !== '' && $refundCreditNote === null && $creditWaits < 3) {
                        $open = JobQueue::openJobs('refund_credit_note');
                        $pending = $open !== null && array_filter($open, static fn(array $p): bool => (int)($p['order_id'] ?? 0) === (int)$orderId && (string)($p['refund_key'] ?? '') === $refundKey) !== [];
                        if ($pending) {
                            JobQueue::pushDelayed('email_send', ['credit_note_waits' => $creditWaits + 1] + $payload, 300);
                            return;
                        }
                    }
                    $refundAmount = (int)($payload['refund_amount'] ?? 0);
                    $vars['refund_amount'] = I18n::price($refundAmount > 0 ? $refundAmount : PaymentService::refundedAmount((int)$orderId), $currency);
                }
                if ($template === 'bank_transfer_reminder') {
                    // Paid or cancelled since the reminder was queued: nothing to remind of.
                    $vars = array_merge($vars, BankTransferInstructions::mailVars($order));
                    if ($vars['bank_transfer'] !== true) return;
                }
                $items = Orders::items((int)$orderId);
                $vars['items'] = array_map(fn($it) => [
                    'name'       => $it['name'],
                    'sku'        => $it['sku'],
                    'quantity'   => $it['quantity'],
                    'unit_price' => I18n::price((int)$it['unit_price'], $currency),
                    'total'      => I18n::price((int)$it['total'], $currency),
                ], $items);
                // Subtotal, discount, shipping, payment fee and VAT: with the item lines they add up to order_total.
                $vars['totals'] = array_map(static fn(array $line): array => [
                    'label'  => I18n::tIn(Orders::TOTAL_LINE_LABELS[$line['key']], $locale !== '' ? $locale : 'en'),
                    'amount' => I18n::price((int)$line['cents'], $currency),
                ], Orders::totalLines($order));
            } else {
                return;
            }
        }

        // R25: the provider configured now (orderMailProvider()), not one frozen on the order or the job.
        $providerCode = self::orderMailProvider($order, $fellBack);
        // The confirmation carries the terms valid at the time of the order on a durable medium (Directive 2011/83/EU art. 8(7)).
        $attachments = $template === 'order_confirmation' && $order ? [ConsumerNotice::termsAttachment($order, $locale ?: (string)($order['locale'] ?? 'en'))] : [];
        // R23: the payment QR travels as a PNG (mail clients show no inline SVG) with the transfer instructions.
        $qr = in_array($template, ['order_confirmation', 'bank_transfer_reminder'], true) && $order ? BankTransferInstructions::qrAttachment($order) : null;
        if ($qr !== null) $attachments[] = $qr;
        $creditPdf = isset($refundCreditNote) ? InvoiceService::pdfFile($refundCreditNote) : null;
        if ($creditPdf !== null) $attachments[] = ['filename' => $refundCreditNote['number'] . '.pdf', 'path' => $creditPdf, 'content_type' => 'application/pdf'];
        $result = MailService::send($template, $to, $locale, $vars, $orderId !== null ? (int)$orderId : null, $attachments, $providerCode);
        if (($result['status'] ?? '') === 'failed') throw new RuntimeException('mail.delivery_failed');
        // Rate-limited order mail is queued again after the limit window instead of being dropped.
        if (($result['status'] ?? '') === 'rate_limited') MailService::deferRateLimited($payload, $result);
        // R26: written to the log only because the provider is misconfigured, it reached nobody: tried again once the
        // configuration may be fixed (was: never sent after the fix). Each try logs a copy until it goes out.
        if ($fellBack && ($result['status'] ?? '') !== 'rate_limited') self::requeueLoggedMail('email_send', $payload);
    }

    /**
     * R26/R27: queue job $type again whose mail was written to the log only because the mail provider is misconfigured
     * (it reached nobody), MAIL_FALLBACK_RETRIES times MAIL_FALLBACK_DELAY apart, so it goes out once the provider is fixed.
     * Order mail, download links, the invoice and a manual order's payment link alike.
     */
    public static function requeueLoggedMail(string $type, array $payload): void {
        $tries = (int)($payload['_mail_fallback_tries'] ?? 0);
        if ($tries < self::MAIL_FALLBACK_RETRIES) {
            JobQueue::pushDelayed($type, ['_mail_fallback_tries' => $tries + 1] + $payload, self::MAIL_FALLBACK_DELAY);
        }
    }

    public static function handleDigitalFulfill(array $payload, array $job): void {
        $orderId = (int)($payload['order_id'] ?? 0);
        if ($orderId <= 0 || !DigitalDelivery::orderHasAssets($orderId)) return;
        // No consent to supply within the withdrawal period: deliver once it has passed (the order may be withdrawn first).
        $order = Orders::byId($orderId);
        $holdUntil = $order ? DigitalDelivery::holdUntil($order) : null;
        if ($holdUntil !== null) {
            JobQueue::pushDelayed('digital_fulfill', ['order_id' => $orderId, 'held' => true], $holdUntil - time(), 2);
            return;
        }
        // A held order the consumer withdrew from, or that was refunded meanwhile, is not supplied after all.
        if (!empty($payload['held']) && (!$order || ($order['payment_status'] ?? '') !== 'paid' || DigitalDelivery::withdrawn($orderId))) return;

        // One fulfilment per order at a time: a concurrent job would rotate (revoke) the links this one mails.
        $claim = DigitalDelivery::claimFulfilment($orderId);
        // Another worker is fulfilling this order right now: retry with backoff, by then its links are
        // delivered and grant() has nothing left (a crashed holder's claim lapses after its lease).
        if ($claim === null) throw new RuntimeException("Digital fulfilment of order #{$orderId} is already in progress");
        try {
            $logged = self::fulfilDigitalOrder($orderId, $claim);
        } finally {
            DigitalDelivery::releaseFulfilment($claim);
        }
        if ($logged) self::requeueLoggedMail('digital_fulfill', $payload);
    }

    /**
     * R49 (review round 19, low): how long a download link is valid, in the order's language (was: "no expiry",
     * "max 3 downloads" in every e-mail).
     * @param array{expires_at: int|string|null, download_limit: int|string|null} $link
     */
    public static function digitalValidity(array $link, string $locale): string {
        $validity = $link['expires_at'] === null
            ? I18n::tIn('email.digital.no_expiry', $locale)
            : strtr(I18n::tIn('email.digital.valid_until', $locale), ['{date}' => date(I18n::tIn('format.datetime', $locale) . ' T', (int)$link['expires_at'])]);
        if ($link['download_limit'] !== null) $validity .= ', ' . strtr(I18n::tIn('email.digital.download_limit', $locale), ['{count}' => (string)(int)$link['download_limit']]);
        return $validity;
    }

    /**
     * Grant the order's downloads and mail their links. $claim (the fulfilment claim, renewed in place) is renewed right
     * before the mail, so its lease covers the delivery. A failed mail revokes the links and throws (the job retries; the
     * next grant() keeps the downloads already counted, R54). R27: true when the links were only written to the log for a
     * misconfigured mail provider: they reached nobody and are revoked (they stand in the log), and the caller tries again
     * with new ones (was: marked delivered, never sent).
     */
    public static function fulfilDigitalOrder(int $orderId, ?string &$claim = null): bool {
        $links = DigitalDelivery::grant($orderId);
        if (!$links) return false;
        $order = Orders::byId($orderId);
        if (!$order) throw new RuntimeException("Order #{$orderId} not found");

        $locale = (string)($order['locale'] ?? '');
        $formatted = array_map(static fn(array $link): array => ['label' => $link['label'], 'url' => $link['url'], 'validity' => self::digitalValidity($link, $locale)], $links);

        // Fresh leases for the slow mail: the job's, and the per-order claim (lost = another worker took the order
        // over and rotates these links; it delivers its own, so this run must not mail links about to be revoked).
        JobQueue::heartbeat();
        if ($claim !== null) {
            $renewed = DigitalDelivery::renewFulfilment($claim);
            if ($renewed === null) throw new RuntimeException("Digital fulfilment claim of order #{$orderId} was lost to another worker");
            $claim = $renewed;
        }
        $result = MailService::send('digital_delivery', (string)$order['customer_email'], (string)($order['locale'] ?? ''), [
            'order_number' => $order['order_number'],
            'customer_name' => $order['customer_name'],
            'download_links' => $formatted,
        ], $orderId, [], self::orderMailProvider($order, $fellBack));
        $ids = array_column($links, 'id');
        if (($result['status'] ?? '') !== 'sent') {
            DigitalDelivery::revokeIssued($ids, 'delivery_email_failed');
            throw new RuntimeException('Digital delivery email failed: ' . ($result['error'] ?? 'unknown error'));
        }
        if ($fellBack) {
            DigitalDelivery::revokeIssued($ids, 'delivery_email_logged');
            return true;
        }
        DigitalDelivery::markDelivered($ids);
        return false;
    }

    public static function handleDigitalRevoke(array $payload, array $job): void {
        $orderId = (int)($payload['order_id'] ?? 0);
        if ($orderId > 0) DigitalDelivery::revokeOrder($orderId, (string)($payload['reason'] ?? 'order_reversal'));
    }

    public static function handleMerchantNotify(array $payload, array $job): void {
        $orderId = (int)($payload['order_id'] ?? 0);
        if ($orderId > 0) MerchantNotifier::send($orderId, (string)($payload['event'] ?? 'paid'));
    }

    public static function handleStockAlert(array $payload, array $job): void {
        // Jobs without a SKU list (legacy {threshold} payloads) re-evaluate every tracked SKU.
        $skus = isset($payload['skus']) && is_array($payload['skus']) ? $payload['skus'] : null;
        StockAlerts::process($skus);
    }

    public static function handleSearchReindex(array $payload, array $job): void {
        $productId = $payload['product_id'] ?? null;
        // R50: the translated names are derived text; a reindex also repairs them (rows written past ProductEditor/Import).
        Search::refreshNames($productId ? [(int)$productId] : []);

        if (Db::isSqlite()) {
            if ($productId) {
                // Single product — FTS triggers handle this automatically on UPDATE
                $now = Db::now();
                Db::get()->prepare("UPDATE products SET updated_at = {$now} WHERE id = :id")
                    ->execute([':id' => $productId]);
            } else {
                // Full reindex — rebuild FTS5
                JobQueue::heartbeat(true); // a fresh lease for the rebuild (no-op outside a worker job)
                Db::get()->exec("INSERT INTO products_fts(products_fts) VALUES('rebuild')");
            }
        } else {
            // MySQL — FULLTEXT indexes are maintained automatically, but we can
            // OPTIMIZE TABLE to rebuild if full reindex is requested
            if (!$productId) {
                JobQueue::heartbeat(true);
                Db::get()->exec("OPTIMIZE TABLE products");
            }
            // Single product: no action needed — MySQL FULLTEXT auto-updates on change
        }
    }

    public static function orderPaymentGateway(array $payload, ?array $order): PaymentAdapter {
        $paymentSnapshot = $order ? (json_decode((string)($order['payment_method_snapshot'] ?? ''), true) ?: []) : [];
        $providerCode = (string)($payload['_provider_code'] ?? ($paymentSnapshot['provider'] ?? ($order ? self::orderProviderCode($order, 'payment') : '')));
        if ($providerCode === '') $providerCode = Adapters::defaultProviderCode('payment');
        return Adapters::payment($providerCode);
    }

    /**
     * Refund job. Item refunds carry their order_refunds id; whole-order refunds (cancellation,
     * payment captured on a cancelled order) carry a ledger idempotency key from
     * PaymentService::orderRefundJobsWithin(). A provider failure fails the job, so it is retried
     * and, when retries run out, raised as an incident — never recorded as done.
     */
    public static function handlePaymentRefund(array $payload, array $job): void {
        $orderId = (int)($payload['order_id'] ?? 0);
        $amount = (int)($payload['amount'] ?? 0);
        $paymentId = (string)($payload['payment_id'] ?? '');
        $itemRefundId = (int)($payload['refund_id'] ?? 0);
        $idempotencyKey = (string)($payload['idempotency_key'] ?? '');
        $order = $orderId > 0 ? Orders::byId($orderId) : null;

        if ($itemRefundId === 0 && $idempotencyKey === '' && $order) {
            // Job queued before the refund ledger: claim the order's refundable balance now.
            $db = Db::get();
            $db->beginTransaction();
            try {
                PaymentService::orderRefundJobsWithin($orderId, 'order.cancelled');
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            // Read back from the ledger, so a retry of this job refunds the same claim.
            $request = PaymentService::orderRefundRequest($orderId);
            if ($request === null) return; // unpaid or nothing left to refund
            [$amount, $idempotencyKey] = [$request['amount'], $request['idempotency_key']];
        }
        // R34 (low): an item refund released by the admin (or declined) has its credit note reversed: a job of it queued
        // again by hand pays nothing out.
        if ($itemRefundId > 0 && (string)(Rma::refundById($itemRefundId)['status'] ?? '') === 'failed') {
            Logger::info('refund.attempt', ['order_id' => $orderId, 'amount' => $amount, 'payment_id' => $paymentId, 'result' => ['status' => 'released']]);
            return;
        }
        if ($itemRefundId === 0 && $idempotencyKey !== '' && PaymentService::refundJobSuperseded($orderId, $idempotencyKey)) {
            Logger::info('refund.attempt', ['order_id' => $orderId, 'amount' => $amount, 'payment_id' => $paymentId, 'result' => ['status' => 'superseded']]);
            return;
        }
        // A redelivered whole-order job after the provider confirmed the refund only finishes the bookkeeping.
        // R54 (medium 3): so does the job of a credit note a refund event settled already (the payment refunded in the
        // GoPay/ThePay dashboard while it waited): calling the provider again would refund it twice or be declined.
        // R55 (review round 20, medium 2): and the job of a request a REFUNDED notification settled while it waited, unless the
        // provider replays a keyed refund (Stripe): then the call returns the same refund, which the job confirms.
        $alreadyRefunded = ($itemRefundId === 0 && $idempotencyKey !== '' && (PaymentService::refundSucceeded($idempotencyKey)
                || (PaymentService::refundSettledByEvent($orderId, $idempotencyKey)
                    && !self::orderPaymentGateway($payload, $order) instanceof IdempotentRefundAdapter)))
            || ($itemRefundId > 0 && (string)(Rma::refundById($itemRefundId)['status'] ?? '') === 'succeeded');

        $result = $alreadyRefunded ? ['status' => 'succeeded', 'replayed' => true] : ['status' => 'skipped'];
        if (!$alreadyRefunded && $paymentId !== '' && $orderId > 0 && $amount > 0) {
            // Renew the lease before moving money (outside any transaction); aborts if another worker owns the job.
            JobQueue::heartbeat(true);
            try {
                $result = PaymentService::refund($orderId, $paymentId, $amount, self::orderPaymentGateway($payload, $order), $idempotencyKey, (string)($order['currency'] ?? 'eur'));
            } catch (\PaymentRefundDeclined $e) {
                // R29: declined for good (a retry gets the same answer): released, not retried until the job dies.
                Logger::info('refund.attempt', ['order_id' => $orderId, 'amount' => $amount, 'payment_id' => $paymentId, 'result' => ['status' => 'declined', 'message' => $e->getMessage()]]);
                if ($itemRefundId > 0) {
                    Rma::failRefund($itemRefundId, 'Refund declined by the provider: ' . $e->getMessage());
                    IncidentMonitor::report('payment.refund_failed', 'payment', 'critical', ['order_id' => $orderId, 'item_refund_id' => $itemRefundId, 'declined' => true]);
                } else {
                    PaymentService::declinedRefundRequest($orderId, $idempotencyKey, $e->getMessage());
                }
                return;
            } catch (\PaymentRefundOutcomeUnknown $e) {
                // R38 (medium 8): only the admin, checking the provider, can tell (PaymentService::unconfirmedProviderRefund()).
                Logger::info('refund.attempt', ['order_id' => $orderId, 'amount' => $amount, 'payment_id' => $paymentId, 'result' => ['status' => 'unknown', 'message' => $e->getMessage()]]);
                IncidentMonitor::report('payment.refund_outcome_unknown', 'payment', 'critical', ['order_id' => $orderId, 'amount' => $amount, 'item_refund_id' => $itemRefundId]);
                return;
            } catch (\Throwable $e) {
                $result = ['status' => 'error', 'message' => $e->getMessage()];
            }
        }
        // Structured log in the private runtime directory (redacted), never a file under the web root.
        Logger::info('refund.attempt', ['order_id' => $orderId, 'amount' => $amount, 'payment_id' => $paymentId, 'result' => $result]);

        $status = (string)($result['status'] ?? '');
        if ($status === 'skipped') return;
        $refundId = (string)($result['refund_id'] ?? $result['id'] ?? '');
        // An asynchronous provider refund is finalised by its refund webhook, which finds an item refund by this id.
        if ($status === 'pending') {
            if ($itemRefundId > 0 && $refundId !== '') Rma::noteProviderRefund($itemRefundId, $refundId);
            return;
        }
        // R14 T18: a declined item refund was released with its credit note (PaymentService::refund()); retrying it
        // would only be declined again. The merchant is told and can refund the items anew.
        if ($itemRefundId > 0 && in_array($status, ['failed', 'canceled', 'cancelled'], true)) {
            // R55 (low): settled by the payment's REFUNDED status meanwhile (PaymentService::refund()): the customer has the money.
            if ((string)(Rma::refundById($itemRefundId)['status'] ?? '') === 'succeeded') return;
            IncidentMonitor::report('payment.refund_failed', 'payment', 'critical', ['order_id' => $orderId, 'item_refund_id' => $itemRefundId, 'released' => true]);
            return;
        }
        // R56 (review round 21, low): a request the provider refused because its payment was refunded in the dashboard
        // meanwhile was settled by the payment's REFUNDED status (PaymentService::refund()): the customer has the money.
        // Was: the job failed and was retried until it died, with a critical incident.
        if ($itemRefundId === 0 && in_array($status, ['failed', 'canceled', 'cancelled'], true) && $idempotencyKey !== ''
            && PaymentService::refundSettledByEvent($orderId, $idempotencyKey)) {
            PaymentService::closeRefundedOrder($orderId, "Payment refund settled by the provider's refunded status: {$paymentId}");
            return;
        }
        // R57 (review round 22, S3): refused and not settled by the payment's state (a partial refund GoPay/ThePay refused,
        // Comgate, which reports no refunds): released as a declined request, the payment back to paid / partially
        // refunded and the merchant told. Was: the job failed, its retry found the attempt answered ("reconcile manually")
        // and the payment stayed refund_pending with neither confirm nor release on the order.
        if ($itemRefundId === 0 && in_array($status, ['failed', 'canceled', 'cancelled'], true) && $idempotencyKey !== ''
            && PaymentService::declinedRefundRequest($orderId, $idempotencyKey, (string)($result['message'] ?? ('refund ' . $status)))) {
            return;
        }
        if ($status !== 'succeeded') {
            if ($status === 'error' && self::requeueRefundAfterError($payload, $job, $order)) return;
            throw new RuntimeException("Refund provider call did not succeed for order #{$orderId}");
        }

        if ($refundId !== '') Orders::setRefundId($orderId, $refundId);
        if ($itemRefundId > 0) Rma::completeRefund($itemRefundId, $refundId);
        $order = Orders::byId($orderId);
        if (!$order) return;
        $paymentStatus = PaymentService::settleRefundJob($orderId, $amount, $itemRefundId > 0, $refundId, $itemRefundId);
        // R54 (review round 19, medium 4): a job that returned all the money closes the order as the refund webhooks do; a
        // paid or processing order (processing → refunded is no transition) stayed to be shipped.
        if ($paymentStatus === 'refunded') PaymentService::closeRefundedOrder($orderId, "Payment refund completed: {$paymentId}");
    }

    /** Re-queues after the last attempt failed, at most this many times (5, 10, 20, 40 minutes, then hourly: about 9 hours). */
    private const REFUND_ERROR_REQUEUES = 12;

    /** Seconds a refund job keeps being requeued after errors (requeueRefundAfterError()), about 9 hours. */
    public static function refundRetryWindow(): int {
        $seconds = 0;
        for ($i = 0; $i < self::REFUND_ERROR_REQUEUES; $i++) $seconds += self::refundRequeueDelay($i);
        return $seconds;
    }

    private static function refundRequeueDelay(int $requeued): int {
        return min(3600, 300 * 2 ** $requeued);
    }

    /**
     * R32 (low): three network errors in a row (about a minute and a half) killed a refund job for good, and nothing
     * offered to send the credit note's refund again: it stayed pending. A provider refund with an idempotency key may be
     * asked again safely, so the last failed attempt is queued again with a growing delay, for less than the day Stripe
     * keeps the key; only then does the job fail and raise its incident. A provider without idempotent refunds is never
     * asked twice (PaymentService::refund()).
     */
    private static function requeueRefundAfterError(array $payload, array $job, ?array $order): bool {
        if ((int)($job['attempts'] ?? 0) + 1 < (int)($job['max_attempts'] ?? 3)) return false; // the queue's own backoff first
        $requeued = (int)($payload['error_requeues'] ?? 0);
        if ($requeued >= self::REFUND_ERROR_REQUEUES || (string)($payload['idempotency_key'] ?? '') === '' || $order === null
            || !self::orderPaymentGateway($payload, $order) instanceof IdempotentRefundAdapter) {
            return false;
        }
        JobQueue::pushDelayed('payment_refund', ['error_requeues' => $requeued + 1] + $payload, self::refundRequeueDelay($requeued), 1);
        Logger::warning('refund.requeued_after_error', ['order_id' => (int)($payload['order_id'] ?? 0), 'requeues' => $requeued + 1]);
        return true;
    }

    /** Void the provider session of a cancelled, unpaid order so it can no longer be paid. */
    public static function handlePaymentCancel(array $payload, array $job): void {
        $orderId = (int)($payload['order_id'] ?? 0);
        $order = $orderId > 0 ? Orders::byId($orderId) : null;
        $paymentId = (string)($payload['payment_id'] ?? '');
        // Paid meanwhile: PaymentService::settleCapture() refunds it instead.
        if (!$order || $paymentId === '' || ($order['payment_status'] ?? '') !== 'cancelled') return;
        $gateway = self::orderPaymentGateway($payload, $order);
        if ($gateway instanceof CancellablePaymentAdapter) $gateway->cancelPayment($paymentId);
    }

    public static function handleErpOrderPush(array $payload, array $job): void {
        $orderId = (int)($payload['order_id'] ?? 0);
        $order = $orderId > 0 ? Orders::byId($orderId) : null;
        if (!$order) return;
        $providerCode = (string)($payload['_provider_code'] ?? self::orderProviderCode($order, 'erp'));
        $adapter = $providerCode !== '' ? Adapters::integration($providerCode) : Adapters::integration();
        if (!$adapter) return;
        JobQueue::heartbeat(true); // a fresh lease for the ERP call
        Orders::markSynced($orderId, $adapter->pushOrder(Orders::erpPayload($order)));
    }

    public static function handleErpOrderUpdate(array $payload, array $job): void {
        $orderId = (int)($payload['order_id'] ?? 0);
        $order = $orderId > 0 ? Orders::byId($orderId) : null;
        if (!$order || empty($order['erp_id'])) return;
        $providerCode = (string)($payload['_provider_code'] ?? self::orderProviderCode($order, 'erp'));
        $adapter = $providerCode !== '' ? Adapters::integration($providerCode) : Adapters::integration();
        if (!$adapter) return;
        if (!$adapter instanceof OrderStatusIntegrationAdapter) {
            throw new RuntimeException("ERP provider does not support order status updates: {$providerCode}");
        }
        $status = strtolower((string)($payload['status'] ?? $order['status'] ?? ''));
        JobQueue::heartbeat(true); // a fresh lease for the ERP call
        $adapter->updateOrderStatus((string)$order['erp_id'], $status, [
            'order_id' => $orderId,
            'order_number' => (string)$order['order_number'],
            'store_id' => StoreContext::current()->id(),
        ]);
    }

    public static function handleCartAbandonment(array $payload, array $job): void {
        $cfg = require AppConfig::path(dirname(__DIR__));
        $options = (array)($cfg['cart_reminder'] ?? []);
        if (($options['enabled'] ?? false) !== true) return;
        if (isset($payload['hours'])) $options['delay_hours'] = (int)$payload['hours'];
        AbandonedCarts::sweep($options + ['base_url' => self::cartReminderBaseUrl($cfg)]);
    }

    public static function cartReminderBaseUrl(array $cfg): string {
        try {
            return Security::appUrl($cfg);
        } catch (\RuntimeException $e) {
            return '';
        }
    }

    public static function handleCleanup(array $payload, array $job): void {
        $cfg = require AppConfig::path(dirname(__DIR__));
        JobQueue::heartbeat(true); // a fresh lease for the retention sweep
        $deleted = RetentionCleanup::run(['jobs_dir' => self::workerJobsDir($cfg)]);
        Logger::info('worker.retention_cleanup', $deleted);
    }

    public static function workerJobsDir(array $cfg): string {
        return (string)($cfg['jobs_dir'] ?? dirname(__DIR__, 2) . '/bareboneseshop-private/jobs');
    }

    public static function handleImageResize(array $payload, array $job): void {
        $path = $payload['path'] ?? '';
        if (!$path) return;

        $storage = Adapters::storage();
        // Custom adapters written before the capability interface only define generateVariants().
        if (!$storage instanceof ImageVariantStorageAdapter && !method_exists($storage, 'generateVariants')) {
            Logger::info('worker.image_variants_unsupported', ['adapter' => get_class($storage)]);
            return;
        }
        JobQueue::heartbeat(true); // a fresh lease for the image processing
        $storage->generateVariants($path);
    }

    public static function handleInvoiceIssue(array $payload, array $job): void {
        $orderId = (int)($payload['order_id'] ?? 0);
        if (!$orderId) return;

        // Paid, then cancelled (or the payment failed) before the worker ran: nothing to invoice any more. Retrying would
        // only raise the same "not paid" incident until the job is dead; an invoice that already exists is still mailed.
        $current = Orders::byId($orderId);
        if (!$current) return;
        if (!InvoiceService::canIssue($current)) {
            $existing = InvoiceService::byOrderId($orderId);
            if ($existing === null || (string)($existing['status'] ?? '') === InvoiceService::STATUS_CANCELLED) {
                Logger::info('invoice.issue_skipped', ['order_id' => $orderId, 'status' => (string)($current['status'] ?? ''),
                    'payment_status' => (string)($current['payment_status'] ?? '')]);
                return;
            }
        }

        // Issue invoice (idempotent — returns existing invoice if already issued)
        JobQueue::heartbeat(true); // a fresh lease for the PDF rendering
        $invoice = InvoiceService::issue($orderId);
        if (!$invoice) return;
        JobQueue::heartbeat(); // renew (or abort if taken over) before the slow mail delivery

        $order = Orders::byId($orderId);
        if (!$order) return;
        // R33: a later step's job (shipped, paid) finds the invoice an earlier one issued and mailed: not mailed again.
        if (InvoiceService::mailed($invoice)) return;

        // Build PDF attachment from disk
        $pdfPath = InvoiceService::pdfFile($invoice);
        $attachments = [];
        if ($pdfPath !== null) {
            $attachments[] = [
                'filename'     => $invoice['number'] . '.pdf',
                'path'         => $pdfPath,
                'content_type' => 'application/pdf',
            ];
        }

        // Email the customer with the invoice
        $locale = (string)($invoice['locale'] ?? $order['locale'] ?? '');
        $vars = InvoiceService::mailVars($invoice, $order);

        $result = MailService::send(
            'invoice_issued',
            (string)$order['customer_email'],
            $locale,
            $vars,
            $orderId,
            $attachments,
            self::orderMailProvider($order, $fellBack)
        );
        // Fail the job so the queue retries delivery; issuing the invoice again is idempotent.
        if (($result['status'] ?? '') === 'failed') throw new RuntimeException('mail.delivery_failed');
        // R27: written to the log only (misconfigured provider): mailed again once it may be fixed, as order mail.
        if ($fellBack && ($result['status'] ?? '') === 'sent') self::requeueLoggedMail('invoice_issue', $payload);
    }

    /**
     * R27: the payment link of an order created by hand, once more after it was only written to the log for a misconfigured
     * mail provider (ManualOrder::notify()). While the provider is still misconfigured no new link is issued nor logged.
     */
    public static function handlePaymentLinkSend(array $payload, array $job): void {
        $order = Orders::byId((int)($payload['order_id'] ?? 0));
        if ($order === null || !PaymentLink::payable($order)) return;
        MailService::providerCode($order, $fellBack);
        $result = $fellBack ? ['fell_back' => true] : PaymentLink::send($order);
        if (!empty($result['fell_back'])) self::requeueLoggedMail('payment_link_send', $payload);
    }

    public static function handleImportProcess(array $payload, array $job): void {
        $file = $payload['file'] ?? '';
        if (!$file || !file_exists($file)) {
            throw new \RuntimeException("Import file not found: {$file}");
        }

        $result = Import::csv($file);

        Logger::info('import.processed', ['imported' => $result['imported'], 'error_count' => count($result['errors'] ?? []), 'file' => basename($file), 'encoding' => $result['encoding'] ?? 'utf-8']);

        // Clean up temp file only on success
        if (empty($result['errors'])) {
            @unlink($file);
        }
    }
}
