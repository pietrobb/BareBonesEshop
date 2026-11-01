<?php
/**
 * R10 UX8: accounts around the checkout.
 *  - After a guest order the confirmation page offers an account for the order's e-mail. R11 H1: the account is
 *    created only from a one-time link e-mailed to that address (sendLink → ?page=account-claim → claim), so seeing the
 *    confirmation page (through a payment link or the status lookup) cannot take over the address or its orders.
 *  - A signed-in customer can save the checkout address into the address book.
 */
class GuestCheckoutAccount {
    /** How long the e-mailed "create your account" link works. */
    public const LINK_TTL = 86400;
    private const PROVIDER = 'account_offer';

    /**
     * A guest order whose e-mail has no account in this store, seen by a browser that is not signed in.
     * The offer is optional on the order confirmation page: a failed lookup hides it instead of breaking that page.
     */
    public static function offered(array $order): bool {
        if (!empty($order['customer_id']) || Customers::isLoggedIn()) return false;
        try {
            $stmt = Db::get()->prepare('SELECT 1 FROM customers WHERE store_id = ? AND email = ? LIMIT 1');
            $stmt->execute([StoreContext::current()->id(), Str::normalizeEmail((string)$order['customer_email'])]);
            return $stmt->fetchColumn() === false;
        } catch (PDOException $e) {
            Logger::exception('guest_account.offer_failed', $e);
            return false;
        }
    }

    /**
     * E-mail the order's address a one-time link that creates the account. Reaching the confirmation page proves
     * nothing about the address (a payment link, the order-status lookup by number + e-mail, an old browser session all
     * lead there), so the account, and the order in it, wait for whoever reads that mailbox.
     * Outcome: sent|not_offered|throttled|unavailable|failed.
     */
    public static function sendLink(array $order, string $locale): string {
        if (!self::offered($order)) return 'not_offered';
        $orderId = (int)$order['id'];
        if (!Security::rateLimit('account_offer_' . $orderId, 3, 3600, false)) return 'throttled';
        try {
            $baseUrl = AbandonedCarts::storeBaseUrl(StoreContext::current()->id(), Security::appUrl(Security::config()));
        } catch (RuntimeException $e) {
            Logger::error('guest_account.app_url_invalid', ['message' => $e->getMessage()]);
            return 'unavailable';
        }
        $token = bin2hex(random_bytes(32));
        CheckoutSession::bind($orderId, hash('sha256', $token), self::PROVIDER);
        $email = Str::normalizeEmail((string)$order['customer_email']);
        $mail = MailService::send('account_offer', $email, $locale, [
            'customer_name' => (string)(($order['customer_name'] ?? '') ?: $email), 'order_number' => (string)$order['order_number'],
            'claim_url' => $baseUrl . '/?page=account-claim&token=' . $token, 'valid_hours' => (string)intdiv(self::LINK_TTL, 3600),
        ]);
        return ($mail['status'] ?? '') === 'sent' ? 'sent' : 'failed';
    }

    /** The order an unused link of this store was sent for, while the link is fresh and the e-mail still has no account. */
    public static function orderForToken(string $token): ?array {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $stmt = Db::get()->prepare("SELECT order_id FROM checkout_sessions
            WHERE provider = :provider AND session_id = :hash AND status = 'pending' AND created_at >= :since LIMIT 1");
        $stmt->execute([':provider' => self::PROVIDER, ':hash' => hash('sha256', $token), ':since' => gmdate('Y-m-d H:i:s', time() - self::LINK_TTL)]);
        $orderId = $stmt->fetchColumn();
        $order = $orderId === false ? null : Orders::byId((int)$orderId);
        return $order !== null && self::offered($order) ? $order : null;
    }

    /**
     * The e-mailed link was opened and a password set: the address is proven, so the account is created verified,
     * the order moves into it and the shopper is signed in. The link works once.
     * @return array{ok: bool, error: ?string, customer_id: ?int}
     */
    public static function claim(string $token, string $password, string $locale): array {
        $order = self::orderForToken($token);
        if ($order === null) return ['ok' => false, 'error' => 'invalid_or_expired_token', 'customer_id' => null];
        $email = Str::normalizeEmail((string)$order['customer_email']);
        $name = (string)($order['customer_name'] ?? '');
        [$ok, $id, $err] = Customers::register($email, $password, $name, ['locale' => $locale, 'phone' => (string)($order['customer_phone'] ?? '')]);
        if (!$ok) return ['ok' => false, 'error' => (string)$err, 'customer_id' => null];
        $db = Db::get();
        $store = StoreContext::current()->id();
        $db->prepare("UPDATE checkout_sessions SET status = 'completed' WHERE provider = ? AND order_id = ?")->execute([self::PROVIDER, (int)$order['id']]);
        $db->prepare('UPDATE customers SET email_verified_at = ? WHERE id = ? AND store_id = ? AND email_verified_at IS NULL')
            ->execute([gmdate('Y-m-d H:i:s'), (int)$id, $store]);
        $db->prepare('UPDATE orders SET customer_id = ? WHERE id = ? AND store_id = ? AND customer_id IS NULL')->execute([(int)$id, (int)$order['id'], $store]);
        MailService::send('account_welcome', $email, $locale, ['customer_name' => $name ?: $email, 'shop_name' => (string)Template::branding()['name']]);
        Customers::login($email, $password);
        return ['ok' => true, 'error' => null, 'customer_id' => (int)$id];
    }

    /** Save the posted checkout address for the signed-in customer unless the book already holds the same one. */
    public static function rememberAddress(int $customerId, array $input): bool {
        $field = static fn(string $key, int $max): string => is_string($input[$key] ?? null) ? mb_substr(trim(Security::str($input[$key])), 0, $max) : '';
        $address = ['type' => 'shipping', 'name' => $field('name', 255), 'company' => $field('company', 255), 'vat_id' => $field('vat_id', 40),
            'street' => $field('street', 255), 'city' => $field('city', 255), 'zip' => $field('zip', 32),
            'country' => strtoupper($field('country', 2)), 'phone' => $field('phone', 64)];
        if (Customers::addressErrors($address) !== []) return false;
        $key = static fn(array $a): string => mb_strtolower(implode('|', [trim((string)$a['name']), trim((string)$a['street']), trim((string)$a['city']),
            str_replace(' ', '', (string)$a['zip']), strtoupper((string)$a['country'])]));
        $existing = Customers::addresses($customerId, 'shipping');
        foreach ($existing as $saved) {
            if ($key($saved) === $key($address)) return false;
        }
        $address['is_default'] = $existing === [];
        Customers::addAddress($customerId, $address);
        return true;
    }
}
