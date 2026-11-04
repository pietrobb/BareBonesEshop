<?php
/**
 * R10 UX8: "notify me when back in stock" for shoppers.
 *
 * A shopper leaves an e-mail on the page of a product that cannot be bought now. Double opt-in (R15): the address
 * gets a confirmation link and the request waits unconfirmed; one not confirmed within CONFIRM_HOURS is deleted.
 * When a ledger movement refills one of the product's SKUs (Inventory::movement), one `back_in_stock` job per store
 * selling that catalog is queued at the end of the request; the worker mails every confirmed request of a product
 * that is purchasable again and deletes it (one e-mail per request, nothing kept afterwards). Requests nobody acted
 * on lapse after RETENTION_DAYS. Personal data: the e-mail only (GdprService::PII_REGISTRY 'back_in_stock_requests').
 */
class BackInStock {
    public const RETENTION_DAYS = 180;
    /** Hours the confirmation link stays valid; an unconfirmed request is deleted after it. */
    public const CONFIRM_HOURS = 48;
    /** Open requests per address and store: bounds what one form can make the shop send to a stranger. */
    private const MAX_PER_EMAIL = 20;

    private static array $pending = [];
    private static bool $shutdownRegistered = false;

    /** A product of the store's catalog that is out of stock (not digital, not purchasable) — the form is offered. */
    public static function offered(array $product): bool {
        return !VariantAvailability::isDigitalType((string)($product['product_type'] ?? ''))
            && ($product['status'] ?? 'active') === 'active'
            && !VariantAvailability::isPurchasable((int)$product['id']);
    }

    /**
     * 'confirm_sent' | 'invalid' (e-mail or product) | 'available' (can be bought now) | 'limit' | 'failed' (no link sent).
     * The request is stored unconfirmed and the address is mailed a confirmation link; asking again sends a fresh link.
     * An address that already confirmed gets no new mail, but the same answer: the form does not tell a stranger which
     * addresses wait for which product.
     */
    public static function subscribe(int $productId, string $email, string $locale): string {
        $email = Str::normalizeEmail($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) return 'invalid';
        $product = self::product($productId);
        if ($product === null) return 'invalid';
        if (!self::offered($product)) return 'available';
        $db = Db::get();
        $store = StoreContext::current()->id();
        $count = $db->prepare('SELECT COUNT(*) FROM back_in_stock_requests WHERE store_id = :store AND email = :email AND product_id <> :product');
        $count->execute([':store' => $store, ':email' => $email, ':product' => $productId]);
        if ((int)$count->fetchColumn() >= self::MAX_PER_EMAIL) return 'limit';
        try {
            $baseUrl = AbandonedCarts::storeBaseUrl($store, Security::appUrl(Security::config()));
        } catch (RuntimeException $e) {
            Logger::error('back_in_stock.app_url_invalid', ['message' => $e->getMessage()]);
            return 'failed';
        }
        $token = bin2hex(random_bytes(32));
        $params = [':store' => $store, ':product' => $productId, ':email' => $email, ':locale' => mb_substr($locale, 0, 10), ':hash' => hash('sha256', $token)];
        $now = Db::now();
        $insert = $db->prepare(Db::insertIgnore() . " INTO back_in_stock_requests (store_id, product_id, email, locale, created_at, token_hash)
            VALUES (:store, :product, :email, :locale, {$now}, :hash)");
        $insert->execute($params);
        if ($insert->rowCount() === 0) {
            // Asked before: a waiting request gets a fresh link (and a fresh CONFIRM_HOURS); a confirmed one stays as it is.
            $renew = $db->prepare("UPDATE back_in_stock_requests SET token_hash = :hash, locale = :locale, created_at = {$now}
                WHERE store_id = :store AND product_id = :product AND email = :email AND confirmed_at IS NULL");
            $renew->execute($params);
            if ($renew->rowCount() === 0) return 'confirm_sent';
        }
        $localized = I18n::product($product);
        $mail = MailService::send('back_in_stock_confirm', $email, $locale, [
            'product_name' => (string)$localized['name'],
            'confirm_url' => $baseUrl . '/?page=stock-notify&confirm=' . $token,
            'valid_hours' => (string)self::CONFIRM_HOURS,
            'shop_name' => (string)Template::branding()['name'],
        ]);
        return ($mail['status'] ?? '') === 'sent' ? 'confirm_sent' : 'failed';
    }

    /** The unconfirmed request of the current store behind an e-mailed link that is still valid, with the product name; else null. */
    public static function pendingForToken(string $token): ?array {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return null;
        $stmt = Db::get()->prepare('SELECT * FROM back_in_stock_requests WHERE store_id = ? AND token_hash = ? AND confirmed_at IS NULL AND created_at >= ?');
        $stmt->execute([StoreContext::current()->id(), hash('sha256', $token), self::confirmCutoff()]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        $product = $request ? self::product((int)$request['product_id']) : null;
        return $request && $product !== null ? $request + ['product_name' => (string)I18n::product($product)['name']] : null;
    }

    /** Confirms the request behind a valid link (once: the token is cleared). */
    public static function confirm(string $token): bool {
        if (self::pendingForToken($token) === null) return false;
        $stmt = Db::get()->prepare('UPDATE back_in_stock_requests SET confirmed_at = ' . Db::now() . ', token_hash = NULL
            WHERE store_id = ? AND token_hash = ? AND confirmed_at IS NULL');
        $stmt->execute([StoreContext::current()->id(), hash('sha256', $token)]);
        return $stmt->rowCount() === 1;
    }

    private static function confirmCutoff(): string {
        return gmdate('Y-m-d H:i:s', time() - self::CONFIRM_HOURS * 3600);
    }

    /** Called by Inventory::movement when a SKU's stock row goes from empty to available; cheap, never throws. */
    public static function touch(string $sku): void {
        if ($sku === '') return;
        try {
            self::$pending[StoreContext::current()->catalogId()][$sku] = true;
        } catch (Throwable $e) {
            // Never break the stock movement; the missed notification is logged instead.
            Logger::exception('back_in_stock.touch_failed', $e, ['sku' => $sku]);
            return;
        }
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'flush']);
        }
    }

    /**
     * Stock is shared by every store selling the same catalog (Warehouse), so a refill in one store queues one job
     * for each store of that catalog that has waiting requests; the job runs in that store's context. Never throws.
     */
    public static function flush(): int {
        $pending = self::$pending;
        self::$pending = [];
        $queued = 0;
        foreach ($pending as $catalogId => $skus) {
            try {
                $stores = self::catalogStores((string)$catalogId);
                $marks = implode(',', array_fill(0, count($stores), '?'));
                $waiting = Db::get()->prepare("SELECT DISTINCT store_id FROM back_in_stock_requests WHERE store_id IN ({$marks}) AND confirmed_at IS NOT NULL ORDER BY store_id");
                $waiting->execute($stores);
                foreach ($waiting->fetchAll(PDO::FETCH_COLUMN) as $storeId) {
                    JobQueue::push('back_in_stock', ['skus' => array_keys($skus), '_store_id' => (string)$storeId], 5);
                    $queued++;
                }
            } catch (Throwable $e) {
                Logger::exception('back_in_stock.enqueue_failed', $e);
            }
        }
        return $queued;
    }

    /** @return non-empty-list<string> the configured stores selling this catalog (a store's catalog_scope defaults to its own id) */
    private static function catalogStores(string $catalogId): array {
        $ids = [$catalogId];
        foreach ((array)(Bootstrap::stores()['stores'] ?? []) as $code => $store) {
            if (is_array($store) && (string)($store['catalog_scope'] ?? $code) === $catalogId) $ids[] = (string)$code;
        }
        return array_values(array_unique($ids));
    }

    /**
     * Worker: mail the confirmed requests of the products behind these SKUs that can be bought again, and delete
     * them. A request whose mail failed stays and the job throws, so the retry sends only what is left. Lapsed
     * requests and links nobody confirmed in time are deleted first.
     * @param list<string> $skus
     */
    public static function process(array $skus): int {
        $db = Db::get();
        $store = StoreContext::current()->id();
        $db->prepare('DELETE FROM back_in_stock_requests WHERE store_id = ? AND (created_at < ? OR (confirmed_at IS NULL AND created_at < ?))')
            ->execute([$store, gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * 86400), self::confirmCutoff()]);
        $sent = 0;
        $failed = false;
        foreach (self::productIds($skus) as $productId) {
            $product = self::product($productId);
            if ($product === null || self::offered($product)) continue;
            $stmt = $db->prepare('SELECT * FROM back_in_stock_requests WHERE store_id = ? AND product_id = ? AND confirmed_at IS NOT NULL ORDER BY id LIMIT 500');
            $stmt->execute([$store, $productId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $request) {
                $result = self::mail($product, $request);
                if (($result['status'] ?? '') === 'failed') {
                    $failed = true;
                    continue;
                }
                $db->prepare('DELETE FROM back_in_stock_requests WHERE id = ?')->execute([(int)$request['id']]);
                $sent++;
            }
        }
        if ($failed) throw new RuntimeException('back_in_stock.delivery_failed');
        return $sent;
    }

    private static function mail(array $product, array $request): array {
        $locale = (string)$request['locale'];
        $previous = I18n::locale();
        if ($locale !== '') I18n::setLocale($locale);
        try {
            $localized = I18n::product($product);
            return MailService::send('back_in_stock', (string)$request['email'], $locale, [
                'product_name' => (string)$localized['name'],
                'product_url' => Template::absoluteUrl(StorefrontViewModel::productUrl((string)$localized['slug'])),
                'shop_name' => (string)Template::branding()['name'],
            ]);
        } finally {
            I18n::setLocale($previous);
        }
    }

    private static function product(int $productId): ?array {
        $stmt = Db::get()->prepare('SELECT * FROM products WHERE id = ? AND store_id = ?');
        $stmt->execute([$productId, StoreContext::current()->catalogId()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param list<string> $skus @return list<int> */
    private static function productIds(array $skus): array {
        $skus = array_slice(array_values(array_unique(array_filter(array_map('strval', $skus), static fn(string $s): bool => $s !== ''))), 0, 1000);
        if ($skus === []) return [];
        $marks = implode(',', array_fill(0, count($skus), '?'));
        $catalog = StoreContext::current()->catalogId();
        $stmt = Db::get()->prepare("SELECT id FROM products WHERE store_id = ? AND sku IN ({$marks})
            UNION SELECT v.product_id FROM variants v JOIN products p ON p.id = v.product_id WHERE p.store_id = ? AND v.sku IN ({$marks})");
        $stmt->execute(array_merge([$catalog], $skus, [$catalog], $skus));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
