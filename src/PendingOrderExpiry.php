<?php
/**
 * Worker sweep that releases stock held by abandoned unpaid orders in every configured store.
 *
 * Offline methods expire only when listed in `offline_order_expiry_seconds`
 * (payment service_type => seconds). Without that key, bank transfers expire after
 * 14 days; COD, invoice and generic offline orders wait for the merchant.
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

    public static function sweep(array $cfg, ?callable $paymentStatusResolver): int {
        $storeIds = array_map('strval', array_keys((array)(Bootstrap::stores()['stores'] ?? [])));
        if ($storeIds === []) $storeIds = [StoreContext::current()->id()];
        $offline = self::offlineTtls($cfg);
        $expired = 0;
        foreach ($storeIds as $storeId) {
            $expired += PaymentWebhookRouting::within($storeId, static fn(): int => Orders::expirePending(
                (int)($cfg['pending_order_expiry_seconds'] ?? 172800),
                (int)($cfg['pending_order_expiry_batch'] ?? 25),
                $paymentStatusResolver,
                $offline
            ));
        }
        return $expired;
    }
}
