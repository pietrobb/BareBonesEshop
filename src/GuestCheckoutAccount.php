<?php
/**
 * R10 UX8: accounts around the checkout.
 *  - After a guest order the confirmation page offers an account for the order's e-mail: the shopper only sets a
 *    password, and that order (verified by its confirmation token in this browser) moves into the new account.
 *    Other guest orders under the address are not attached: typing an e-mail proves nothing.
 *  - A signed-in customer can save the checkout address into the address book.
 */
class GuestCheckoutAccount {
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
     * Create the account for $order (already confirmation-checked by the caller), attach the order and sign in.
     * @return array{ok: bool, error: ?string, customer_id: ?int}
     */
    public static function create(array $order, string $password, string $locale): array {
        if (!self::offered($order)) return ['ok' => false, 'error' => 'email_taken', 'customer_id' => null];
        $email = Str::normalizeEmail((string)$order['customer_email']);
        $name = (string)($order['customer_name'] ?? '');
        [$ok, $id, $err] = Customers::register($email, $password, $name, ['locale' => $locale, 'phone' => (string)($order['customer_phone'] ?? '')]);
        if (!$ok) return ['ok' => false, 'error' => (string)$err, 'customer_id' => null];
        Db::get()->prepare('UPDATE orders SET customer_id = ? WHERE id = ? AND store_id = ? AND customer_id IS NULL')
            ->execute([(int)$id, (int)$order['id'], StoreContext::current()->id()]);
        MailService::send('account_welcome', $email, $locale, ['customer_name' => $name ?: $email, 'shop_name' => (string)Template::branding()['name']]);
        Customers::requestEmailVerification((int)$id, $locale, Security::config());
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
