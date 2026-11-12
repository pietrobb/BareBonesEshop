<?php
/** Deduplicated merchant order-event notifications. */
class MerchantNotifier {
    public static function send(int $orderId, string $event): int {
        $order = Orders::byId($orderId);
        if (!$order) throw new RuntimeException("Order #{$orderId} not found");
        $cfg = require AppConfig::path(dirname(__DIR__));
        $notificationCfg = (array)($cfg['merchant_notifications'] ?? []);
        if (array_key_exists('enabled', $notificationCfg) && !$notificationCfg['enabled']) return 0;
        // Per-event switch, e.g. ['created' => true, 'paid' => false]; unlisted events stay on.
        if (array_key_exists($event, (array)($notificationCfg['events'] ?? [])) && !$notificationCfg['events'][$event]) return 0;
        $recipients = self::recipients($cfg);
        if (!$recipients) return 0;

        // 'withdrawal:<id>' is one event per withdrawal statement (an order may get several partial ones).
        $kind = explode(':', $event, 2)[0];
        if (array_key_exists($kind, (array)($notificationCfg['events'] ?? [])) && !$notificationCfg['events'][$kind]) return 0;
        $template = (string)($notificationCfg['templates'][$kind] ?? match ($kind) {
            'created' => 'merchant_order_created',
            'withdrawal' => 'merchant_withdrawal_received',
            'complaint' => 'merchant_complaint_received',
            default => 'merchant_order_paid',
        });
        // R29: written for the merchant: merchant_notifications.locale, else the store's language (the admin's default), not
        // the language the customer ordered in (a German order told a Slovak shop about it in German).
        $locale = trim((string)($notificationCfg['locale'] ?? ''));
        if ($locale === '') $locale = StoreContext::hasCurrent() ? StoreContext::current()->defaultLocale() : '';
        $paymentLabel = PaymentService::orderLabel($order, $locale !== '' ? $locale : null) ?: (string)($order['payment_method'] ?? '');
        // R23 UX: the payment state in the e-mail's language ("Zaplatená"), not the code ("paid", "— pending").
        $paymentStatus = OrderStatusLabels::paymentOf($order, $locale);
        $sent = 0;
        foreach ($recipients as $recipient) {
            if (!self::claim($orderId, $event, $recipient)) continue;
            try {
                $items = Orders::items($orderId);
                // A withdrawal notice names only the lines of that statement and their sum, not the whole order.
                if ($kind === 'withdrawal') $items = self::withdrawnItems($orderId, (int)(explode(':', $event, 2)[1] ?? 0), $items);
                $result = MailService::send($template, $recipient, $locale, OrderAddress::mailVars($order, $locale) + [
                    'event' => $event,
                    'order_number' => (string)$order['order_number'],
                    'order_total' => I18n::price((int)$order['total'], $order['currency'] ?? null),
                    'customer_name' => (string)$order['customer_name'],
                    'customer_email' => (string)$order['customer_email'],
                    'payment_id' => (string)($order['payment_id'] ?? ''),
                    'payment_status' => $paymentStatus,
                    'payment_method' => $paymentLabel,
                    'awaiting_payment' => ($order['payment_status'] ?? '') !== 'paid',
                    'admin_url' => self::adminOrderUrl($cfg, $orderId),
                    'withdrawal_total' => I18n::price(array_sum(array_map(static fn(array $item): int => (int)$item['total'], $items)), $order['currency'] ?? null),
                    'items' => array_map(static fn(array $item): array => [
                        'name' => $item['name'],
                        'sku' => $item['sku'],
                        'quantity' => $item['quantity'],
                        'total' => I18n::price((int)$item['total'], $order['currency'] ?? null),
                    ], $items),
                ], $orderId);
                if (($result['status'] ?? '') !== 'sent') {
                    throw new RuntimeException((string)($result['error'] ?? 'Mail send failed'));
                }
                // R25: the log provider only wrote it down; the merchant was not told, so it is not recorded as sent.
                self::finish($orderId, $event, $recipient, MailService::shownStatus($result), '');
                $sent++;
            } catch (Throwable $e) {
                self::finish($orderId, $event, $recipient, 'failed', $e->getMessage());
                throw new RuntimeException("Merchant notification failed for {$recipient}", 0, $e);
            }
        }
        return $sent;
    }

    /** Valid merchant inbox addresses: explicit override list, else merchant_notifications.recipients, else admin_email. */
    public static function recipients(array $cfg, array $override = []): array {
        $recipients = $override ?: (array)($cfg['merchant_notifications']['recipients'] ?? []);
        if (!$recipients && !empty($cfg['admin_email'])) $recipients = [(string)$cfg['admin_email']];
        return array_values(array_unique(array_filter(
            array_map(static fn($email): string => trim((string)$email), $recipients),
            static fn(string $email): bool => (bool)filter_var($email, FILTER_VALIDATE_EMAIL)
        )));
    }

    /** The order lines a withdrawal statement covers (withdrawal_requests.items maps order_item id => quantity). */
    private static function withdrawnItems(int $orderId, int $statementId, array $items): array {
        $stmt = Db::get()->prepare('SELECT items FROM withdrawal_requests WHERE id = ? AND order_id = ?');
        $stmt->execute([$statementId, $orderId]);
        $withdrawn = (array)(json_decode((string)$stmt->fetchColumn(), true) ?: []);
        return array_values(array_filter($items, static fn(array $item): bool => array_key_exists((string)$item['id'], $withdrawn)));
    }

    private static function adminOrderUrl(array $cfg, int $orderId): string {
        try {
            return Security::appUrl($cfg) . '/admin.php?p=order&id=' . $orderId;
        } catch (RuntimeException $e) {
            return '';
        }
    }

    private static function claim(int $orderId, string $event, string $recipient): bool {
        $db = Db::get();
        $insert = $db->prepare(Db::insertIgnore() . " INTO merchant_notifications
            (order_id, event_key, recipient, status) VALUES (:order_id, :event, :recipient, 'processing')");
        $insert->execute([':order_id' => $orderId, ':event' => $event, ':recipient' => $recipient]);
        if ($insert->rowCount() === 1) return true;
        $staleBefore = Db::dateAddSeconds('updated_at', '900') . ' <= ' . Db::now();
        $retry = $db->prepare("UPDATE merchant_notifications SET status = 'processing', error = '', updated_at = " . Db::now() . "
            WHERE order_id = :order_id AND event_key = :event AND recipient = :recipient
              AND (status = 'failed' OR (status = 'processing' AND {$staleBefore}))");
        $retry->execute([':order_id' => $orderId, ':event' => $event, ':recipient' => $recipient]);
        return $retry->rowCount() === 1;
    }

    private static function finish(int $orderId, string $event, string $recipient, string $status, string $error): void {
        $sentAt = $status === 'sent' ? Db::now() : 'NULL';
        $stmt = Db::get()->prepare("UPDATE merchant_notifications SET status = :status, error = :error,
            sent_at = {$sentAt}, updated_at = " . Db::now() . "
            WHERE order_id = :order_id AND event_key = :event AND recipient = :recipient");
        $stmt->execute([
            ':status' => $status,
            ':error' => substr($error, 0, 1000),
            ':order_id' => $orderId,
            ':event' => $event,
            ':recipient' => $recipient,
        ]);
    }
}
