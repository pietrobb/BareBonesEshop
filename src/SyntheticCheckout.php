<?php
/**
 * Synthetic (post-deploy smoke) checkout orders.
 *
 * cli/smoke-checkout.php walks the real HTML checkout up to the payment gateway
 * redirect. Its request carries X-BareBones-Synthetic: <monitoring.synthetic_token>.
 * Such an order is flagged is_synthetic, sends no customer/merchant mail, emits no
 * integration events, and is closed (stock released) as soon as the gateway session
 * exists, so it never reaches statistics, ERP or the payment funnel alarm.
 */
final class SyntheticCheckout
{
    public const HEADER = 'X-BareBones-Synthetic';
    private const MIN_TOKEN_LENGTH = 32;

    public static function isRequest(array $config): bool
    {
        $expected = (string)($config['monitoring']['synthetic_token'] ?? '');
        if (strlen($expected) < self::MIN_TOKEN_LENGTH) return false;
        $submitted = $_SERVER['HTTP_X_BAREBONES_SYNTHETIC'] ?? '';
        return is_string($submitted) && $submitted !== '' && hash_equals($expected, $submitted);
    }

    /** Release a synthetic order the moment the smoke test has its gateway redirect. */
    public static function close(int $orderId): bool
    {
        $db = Db::get();
        $db->beginTransaction();
        try {
            $claim = $db->prepare("UPDATE orders
                SET status = 'cancelled', payment_status = 'cancelled', updated_at = " . Db::now() . "
                WHERE id = :id AND store_id = :store AND is_synthetic = 1 AND status = 'pending'");
            $claim->execute([':id' => $orderId, ':store' => StoreContext::current()->id()]);
            if ($claim->rowCount() !== 1) {
                $db->rollBack();
                return false;
            }
            Orders::restoreStock($orderId, 'release');
            $db->prepare("UPDATE checkout_sessions SET status = 'expired', updated_at = " . Db::now() . " WHERE order_id = :id AND status = 'pending'")
               ->execute([':id' => $orderId]);
            AuditLog::log('order_status', $orderId, 'pending', 'cancelled', 'Synthetic smoke checkout closed');
            $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
