<?php
/**
 * Abandoned-cart capture, conversion tracking and the single recovery reminder.
 *
 * Invariants:
 *  - a cart is only stored with an e-mail, and only reminded, when the shopper ticked the checkout
 *    consent CONSENT_KEY (config checkout_consents); its version, text and time are kept with the cart,
 *  - a cart gets at most one reminder (claimed atomically before the mail job is queued),
 *  - a cart that became an order is linked to it and never reminded,
 *  - a cart whose e-mail ordered later, or opted out, is never reminded,
 *  - an e-mail address gets at most one reminder per email_window_hours (default 7 days), however many carts
 *    (sessions, stores) were captured with it — the consent box cannot turn the shop into a mail cannon,
 *  - carts older than max_age_hours are never reminded (no mail burst after enabling).
 */
class AbandonedCarts {
    public const STATUS_SENT = 'sent';
    public const STATUS_ORDERED = 'ordered';
    public const STATUS_SUPPRESSED = 'suppressed';
    /** Another cart of the same e-mail address was reminded within email_window_hours. */
    public const STATUS_LIMITED = 'limited';
    /** Checkout consent (config checkout_consents) that allows the reminder e-mail. */
    public const CONSENT_KEY = 'cart_reminder';

    /**
     * Persist the current session cart with the shopper's e-mail and reminder consent. Never resets a
     * sent reminder. Without consent nothing is stored, and a cart stored earlier in the session
     * loses its e-mail and consent (the shopper unticked the box), so it is never reminded.
     * @param array{version?:string,text?:string}|null $consent the accepted CONSENT_KEY definition
     */
    public static function capture(string $email, ?string $sessionId = null, ?array $consent = null): void {
        $email = strtolower(trim($email));
        $sessionId ??= Security::sessionKey();
        if ($sessionId === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
        if ($consent === null) {
            Db::get()->prepare("UPDATE abandoned_carts SET customer_email = NULL, reminder_consent_version = NULL,
                    reminder_consent_text = NULL, reminder_consent_at = NULL
                WHERE session_id = :sid AND order_id IS NULL AND reminded_at IS NULL")
                ->execute([':sid' => $sessionId]);
            return;
        }
        $summary = Cart::enriched();
        if (empty($summary['items'])) return;

        $items = array_map(static fn(array $item): array => [
            'product_id' => (int)$item['product_id'],
            'variant_id' => $item['variant_id'] !== null ? (int)$item['variant_id'] : null,
            'qty' => (int)$item['qty'],
            'name' => trim((string)$item['name'] . ' ' . (string)($item['variant_name'] ?? '')),
            'sku' => (string)$item['sku'],
            'total' => (int)$item['total'],
        ], $summary['items']);
        $data = [
            ':email' => $email,
            ':data' => json_encode($items, JSON_UNESCAPED_UNICODE),
            ':value' => (int)$summary['subtotal'],
            ':count' => (int)$summary['count'],
            ':currency' => (string)($summary['currency'] ?? 'eur'),
            ':locale' => I18n::locale(),
            ':sid' => $sessionId,
            ':consent_version' => substr((string)($consent['version'] ?? '1'), 0, 64),
            ':consent_text' => (string)($consent['text'] ?? ''),
        ];

        $db = Db::get();
        $now = Db::now();
        $update = $db->prepare("UPDATE abandoned_carts SET customer_email = :email, cart_data = :data, cart_value = :value,
            item_count = :count, currency = :currency, locale = :locale, updated_at = {$now},
            reminder_consent_version = :consent_version, reminder_consent_text = :consent_text, reminder_consent_at = {$now}
            WHERE session_id = :sid AND order_id IS NULL");
        $update->execute($data);
        if ($update->rowCount() > 0) return;
        try {
            $db->prepare("INSERT INTO abandoned_carts (session_id, customer_email, cart_data, cart_value, item_count, currency, locale,
                    store_id, recovery_token, updated_at, reminder_consent_version, reminder_consent_text, reminder_consent_at)
                VALUES (:sid, :email, :data, :value, :count, :currency, :locale, :store, :token, {$now},
                    :consent_version, :consent_text, {$now})")
               ->execute($data + [':store' => StoreContext::current()->id(), ':token' => bin2hex(random_bytes(16))]);
        } catch (\PDOException $e) {
            // Concurrent capture for the same session already created the row.
        }
    }

    /** Link the session's cart to the order created from it. */
    public static function markConverted(int $orderId, ?string $sessionId = null): void {
        $sessionId ??= Security::sessionKey();
        if ($sessionId === '' || $orderId <= 0) return;
        $now = Db::now();
        Db::get()->prepare("UPDATE abandoned_carts SET order_id = :order, converted_at = {$now},
                reminder_status = COALESCE(reminder_status, :status), session_id = :archived
            WHERE session_id = :sid AND order_id IS NULL")
            ->execute([':order' => $orderId, ':status' => self::STATUS_ORDERED, ':archived' => 'order:' . $orderId, ':sid' => $sessionId]);
    }

    /**
     * Queue the single reminder for every due cart with recorded consent. Returns the number of
     * reminders queued. Links point to the cart's own store (its domain / URL prefix), with the
     * app_url in $options['base_url'] as the scheme and base path.
     */
    public static function sweep(array $options = []): int {
        $hours = max(1, (int)($options['delay_hours'] ?? 24));
        $maxAge = max($hours + 1, (int)($options['max_age_hours'] ?? 72));
        $emailWindow = max(1, (int)($options['email_window_hours'] ?? 168));
        $baseUrl = rtrim((string)($options['base_url'] ?? ''), '/');
        if (!preg_match('#^https?://#i', $baseUrl)) {
            throw new RuntimeException('Cart reminders need an absolute app_url for recovery and opt-out links');
        }
        $db = Db::get();
        $sqlite = Db::isSqlite();
        $ago = static fn(int $h): string => $sqlite ? "datetime('now', '-{$h} hours')" : "DATE_SUB(NOW(), INTERVAL {$h} HOUR)";
        $activity = 'COALESCE(updated_at, created_at)';
        $stmt = $db->prepare("SELECT * FROM abandoned_carts
            WHERE order_id IS NULL AND reminded_at IS NULL AND reminder_status IS NULL
              AND customer_email IS NOT NULL AND customer_email <> '' AND reminder_consent_at IS NOT NULL
              AND {$activity} < {$ago($hours)} AND {$activity} >= {$ago($maxAge)}
            ORDER BY id LIMIT 50");
        $stmt->execute();

        $sent = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $cart) {
            $email = strtolower((string)$cart['customer_email']);
            $orderId = self::orderPlacedSince($email, (string)$cart['created_at']);
            if ($orderId !== null) {
                self::close((int)$cart['id'], self::STATUS_ORDERED, $orderId);
                continue;
            }
            if (self::isSuppressed($email)) {
                self::close((int)$cart['id'], self::STATUS_SUPPRESSED);
                continue;
            }
            $recent = $db->prepare("SELECT 1 FROM abandoned_carts WHERE LOWER(customer_email) = :email AND reminded_at >= {$ago($emailWindow)} LIMIT 1");
            $recent->execute([':email' => $email]);
            if ($recent->fetchColumn() !== false) {
                self::close((int)$cart['id'], self::STATUS_LIMITED);
                continue;
            }
            $now = Db::now();
            $claim = $db->prepare("UPDATE abandoned_carts SET reminded_at = {$now}, reminder_status = :status
                WHERE id = :id AND reminded_at IS NULL AND reminder_status IS NULL AND order_id IS NULL");
            $claim->execute([':status' => self::STATUS_SENT, ':id' => (int)$cart['id']]);
            if ($claim->rowCount() !== 1) continue;
            if (!preg_match('/^[a-f0-9]{32}$/', (string)($cart['recovery_token'] ?? ''))) {
                $cart['recovery_token'] = bin2hex(random_bytes(16));
                $db->prepare('UPDATE abandoned_carts SET recovery_token = :token WHERE id = :id')
                   ->execute([':token' => $cart['recovery_token'], ':id' => (int)$cart['id']]);
            }

            JobQueue::push('email_send', [
                'template' => 'cart_reminder',
                'to' => $email,
                'locale' => (string)($cart['locale'] ?? ''),
                '_store_id' => (string)($cart['store_id'] ?? '') ?: 'default',
                // The links carry the recovery token: the worker builds them at send time (no token in the queue).
                'cart_id' => (int)$cart['id'],
                'base_url' => self::storeBaseUrl((string)($cart['store_id'] ?? ''), $baseUrl),
            ], 8);
            $sent++;
        }
        return $sent;
    }

    /**
     * Public base URL of a store for links in mail sent outside a request: the store's first
     * domain (keeping the app_url scheme and path) or the app_url, plus the store's url_prefix.
     */
    public static function storeBaseUrl(string $storeId, string $appUrl): string {
        $store = (Bootstrap::stores()['stores'] ?? [])[$storeId] ?? null;
        if (!is_array($store)) return rtrim($appUrl, '/');
        $base = Security::storeAppUrl($appUrl, $store);
        $prefix = rtrim((string)($store['url_prefix'] ?? ''), '/');
        if ($prefix !== '' && str_starts_with($prefix, '/') && !str_contains($prefix, '..') && preg_match('#^[A-Za-z0-9/_-]+$#', $prefix)) {
            $base .= $prefix;
        }
        return $base;
    }

    /** @return array<int,array> newest first, current store only; filter = abandoned | converted | all */
    public static function list(string $filter = 'abandoned', int $limit = 200): array {
        $where = match ($filter) {
            'converted' => 'order_id IS NOT NULL',
            'all' => '1=1',
            default => "order_id IS NULL AND customer_email IS NOT NULL AND customer_email <> ''",
        };
        // Rows from before carts carried a store belong to the default store.
        $store = StoreContext::current()->id();
        $legacy = $store === (string)(Bootstrap::stores()['default_store'] ?? 'default') ? ' OR store_id IS NULL' : '';
        $stmt = Db::get()->prepare("SELECT * FROM abandoned_carts WHERE (store_id = :store{$legacy}) AND {$where} ORDER BY id DESC LIMIT :limit");
        $stmt->bindValue(':store', $store);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return array_map(static function (array $row): array {
            $row['items'] = self::items($row);
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Refill an empty session cart from a reminder link. */
    public static function restore(string $token): bool {
        $cart = self::byToken($token);
        if (!$cart || $cart['order_id'] !== null) return false;
        if (Cart::get() === []) Cart::restore(self::items($cart));
        return true;
    }

    /** Opt the reminder's recipient out of all future reminders. */
    public static function unsubscribe(string $token): bool {
        $cart = self::byToken($token);
        if (!$cart || trim((string)$cart['customer_email']) === '') return false;
        self::suppress((string)$cart['customer_email'], 'cart_reminder_link');
        return true;
    }

    public static function suppress(string $email, string $source): void {
        $email = strtolower(trim($email));
        if ($email === '' || self::isSuppressed($email)) return;
        try {
            Db::get()->prepare('INSERT INTO email_suppressions (email, scope, source) VALUES (:email, :scope, :source)')
                ->execute([':email' => $email, ':scope' => 'marketing', ':source' => $source]);
        } catch (\PDOException $e) {
            // Already suppressed by a concurrent request.
        }
    }

    public static function isSuppressed(string $email): bool {
        $stmt = Db::get()->prepare('SELECT 1 FROM email_suppressions WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => strtolower(trim($email))]);
        return (bool)$stmt->fetchColumn();
    }

    private static function byToken(string $token): ?array {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        $stmt = Db::get()->prepare('SELECT * FROM abandoned_carts WHERE recovery_token = :token LIMIT 1');
        $stmt->execute([':token' => $token]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function orderPlacedSince(string $email, string $since): ?int {
        $stmt = Db::get()->prepare('SELECT id FROM orders WHERE LOWER(customer_email) = :email AND created_at >= :since ORDER BY id LIMIT 1');
        $stmt->execute([':email' => $email, ':since' => $since]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    private static function close(int $id, string $status, ?int $orderId = null): void {
        $now = Db::now();
        Db::get()->prepare("UPDATE abandoned_carts SET reminder_status = :status,
                order_id = COALESCE(order_id, :order), converted_at = CASE WHEN :order2 IS NULL THEN converted_at ELSE {$now} END
            WHERE id = :id")
            ->execute([':status' => $status, ':order' => $orderId, ':order2' => $orderId, ':id' => $id]);
    }

    /** Items from the stored snapshot; legacy session-shaped rows get names from the catalog. */
    private static function items(array $cart): array {
        $raw = json_decode((string)($cart['cart_data'] ?? ''), true);
        $items = [];
        foreach (is_array($raw) ? $raw : [] as $item) {
            if (!is_array($item) || (int)($item['product_id'] ?? 0) <= 0) continue;
            if (!isset($item['name'])) {
                $product = Products::byId((int)$item['product_id']);
                $item['name'] = (string)($product['name'] ?? ('#' . (int)$item['product_id']));
            }
            $items[] = $item;
        }
        return $items;
    }

    /** Reminder mail variables of a queued cart (null when the cart is gone or has no token). */
    public static function reminderVars(int $cartId, string $baseUrl): ?array {
        $stmt = Db::get()->prepare('SELECT * FROM abandoned_carts WHERE id = :id');
        $stmt->execute([':id' => $cartId]);
        $cart = $stmt->fetch(PDO::FETCH_ASSOC);
        return $cart && (string)($cart['recovery_token'] ?? '') !== '' ? self::mailVars($cart, $baseUrl) : null;
    }

    private static function mailVars(array $cart, string $baseUrl): array {
        $token = (string)$cart['recovery_token'];
        $currency = (string)($cart['currency'] ?? '') ?: null;
        return [
            // Single-parameter URLs survive the mail engine's HTML escaping in the plain-text part.
            'cart_url' => $baseUrl . '/?cart_recover=' . $token,
            'unsubscribe_url' => $baseUrl . '/?cart_unsubscribe=' . $token,
            'cart_total' => I18n::price((int)($cart['cart_value'] ?? 0), $currency),
            'items' => array_map(static fn(array $item): array => [
                'name' => (string)$item['name'],
                'quantity' => (int)($item['qty'] ?? 1),
            ], self::items($cart)),
        ];
    }
}
