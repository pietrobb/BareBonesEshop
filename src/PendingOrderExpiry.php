<?php
/**
 * Worker sweep that releases stock held by abandoned unpaid orders in every configured store.
 *
 * Offline methods expire only when listed in `offline_order_expiry_seconds`
 * (payment service_type => seconds). Without that key, bank transfers are due 14 days after the order (end of
 * that day on the shop's calendar), are reminded bank_transfer_reminder_days before and are cancelled only after
 * bank_transfer_grace_days more; COD, invoice and generic offline orders wait for the merchant.
 */
class PendingOrderExpiry {
    public const DEFAULT_OFFLINE_SECONDS = ['bank_transfer' => 1209600];

    /** @return array<string,int> */
    public static function offlineTtls(array $cfg): array {
        $raw = $cfg['offline_order_expiry_seconds'] ?? self::DEFAULT_OFFLINE_SECONDS;
        $ttls = [];
        foreach (is_array($raw) ? $raw : [] as $type => $seconds) {
            if (is_string($type) && preg_match('/^[a-z0-9_-]{1,40}$/', $type) && (int)$seconds > 0) {
                $ttls[$type] = max(3600, (int)$seconds);
            }
        }
        return $ttls;
    }

    /**
     * The provider's status of a pending order's payment. A payment the provider reports paid is booked
     * here: its notification may never come (e.g. sent to a route a deploy removed), and the expiry
     * sweep only skips paid payments, which would leave the order pending forever.
     */
    public static function providerStatus(array $order, PaymentAdapter $gateway, string $provider): string {
        $paymentId = (string)$order['payment_id'];
        if ($gateway instanceof PaymentStatusEventAdapter) {
            $event = $gateway->statusEvent($paymentId);
            if ($event['event_type'] === 'payment.paid') {
                PaymentService::applyProviderStatusEvent($event, $provider);
                return 'paid';
            }
        }
        return $gateway->getStatus($paymentId);
    }

    public static function sweep(array $cfg, ?callable $paymentStatusResolver): int {
        $storeIds = array_map('strval', array_keys((array)(Bootstrap::stores()['stores'] ?? [])));
        if ($storeIds === []) $storeIds = [StoreContext::current()->id()];
        $offline = self::offlineTtls($cfg);
        $expired = 0;
        foreach ($storeIds as $storeId) {
            try {
                // Remind unpaid transfers before their due date (once), then release those past due and grace.
                PaymentWebhookRouting::within($storeId, static fn(): int => BankTransferInstructions::remindDue($offline['bank_transfer'] ?? null));
                $expired += PaymentWebhookRouting::within($storeId, static fn(): int => Orders::expirePending(
                    (int)($cfg['pending_order_expiry_seconds'] ?? 172800),
                    (int)($cfg['pending_order_expiry_batch'] ?? 25),
                    $paymentStatusResolver,
                    $offline
                ));
            } catch (\Throwable $e) {
                // A failing store must not stop the sweep of the remaining stores.
                Logger::phpLog("Pending order expiry failed for store {$storeId}: " . $e->getMessage());
                IncidentMonitor::report('order.expiry_failed', 'orders', 'critical', ['exception' => $e]);
            }
        }
        return $expired;
    }
}
