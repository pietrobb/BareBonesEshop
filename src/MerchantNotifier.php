<?php
/** Deduplicated merchant order-event notifications. */
class MerchantNotifier {
    public static function send(int $orderId, string $event): int {
        $order = Orders::byId($orderId);
        if (!$order) throw new RuntimeException("Order #{$orderId} not found");
        $cfg = require __DIR__ . '/../config.php';
        $notificationCfg = (array)($cfg['merchant_notifications'] ?? []);
        if (array_key_exists('enabled', $notificationCfg) && !$notificationCfg['enabled']) return 0;
        // Per-event switch, e.g. ['created' => true, 'paid' => false]; unlisted events stay on.
        if (array_key_exists($event, (array)($notificationCfg['events'] ?? [])) && !$notificationCfg['events'][$event]) return 0;
        $recipients = self::recipients($cfg);
        if (!$recipients) return 0;

        $template = (string)($notificationCfg['templates'][$event] ?? ($event === 'created' ? 'merchant_order_created' : 'merchant_order_paid'));
        $paymentSnapshot = json_decode((string)($order['payment_method_snapshot'] ?? ''), true) ?: [];
        $sent = 0;
        foreach ($recipients as $recipient) {
            if (!self::claim($orderId, $event, $recipient)) continue;
            try {
                $items = Orders::items($orderId);
                $result = MailService::send($template, $recipient, (string)($order['locale'] ?? ''), [
                    'event' => $event,
                    'order_number' => (string)$order['order_number'],
                    'order_total' => I18n::price((int)$order['total'], $order['currency'] ?? null),
                    'customer_name' => (string)$order['customer_name'],
                    'customer_email' => (string)$order['customer_email'],
                    'payment_id' => (string)($order['payment_id'] ?? ''),
                    'payment_status' => (string)($order['payment_status'] ?? ''),
                    'payment_method' => (string)($paymentSnapshot['label'] ?? $paymentSnapshot['name'] ?? $order['payment_method'] ?? ''),
                    'awaiting_payment' => ($order['payment_status'] ?? '') !== 'paid',
                    'admin_url' => self::adminOrderUrl($cfg, $orderId),
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
                self::finish($orderId, $event, $recipient, 'sent', '');
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
