<?php
/**
 * R10 UX8: "notify me when back in stock" for shoppers.
 *
 * A shopper leaves an e-mail on the page of a product that cannot be bought now. When a ledger movement
 * refills one of the product's SKUs (Inventory::movement), one `back_in_stock` job per store selling that catalog is queued at
 * the end of the request; the worker mails every waiting request of a product that is purchasable again
 * and deletes it (one e-mail per request, nothing kept afterwards). Requests nobody acted on lapse after
 * RETENTION_DAYS. Personal data: the e-mail only (GdprService::PII_REGISTRY 'back_in_stock_requests').
 */
class BackInStock {
    public const RETENTION_DAYS = 180;
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

    /** 'subscribed' | 'invalid' (e-mail or product) | 'available' (can be bought now) | 'limit'. Repeating a request is not an error. */
    public static function subscribe(int $productId, string $email, string $locale): string {
        $email = Str::normalizeEmail($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) return 'invalid';
        $product = self::product($productId);
        if ($product === null) return 'invalid';
        if (!self::offered($product)) return 'available';
        $db = Db::get();
        $store = StoreContext::current()->id();
        $count = $db->prepare('SELECT COUNT(*) FROM back_in_stock_requests WHERE store_id = :store AND email = :email');
        $count->execute([':store' => $store, ':email' => $email]);
        if ((int)$count->fetchColumn() >= self::MAX_PER_EMAIL) return 'limit';
        $now = Db::now();
        $db->prepare(Db::insertIgnore() . " INTO back_in_stock_requests (store_id, product_id, email, locale, created_at)
            VALUES (:store, :product, :email, :locale, {$now})")
            ->execute([':store' => $store, ':product' => $productId, ':email' => $email, ':locale' => mb_substr($locale, 0, 10)]);
        return 'subscribed';
    }

    /** Called by Inventory::movement when a SKU's stock row goes from empty to available; cheap, never throws. */
    public static function touch(string $sku): void {
        if ($sku === '') return;
        try {
            self::$pending[StoreContext::current()->catalogId()][$sku] = true;
        } catch (Throwable) {
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
                $waiting = Db::get()->prepare("SELECT DISTINCT store_id FROM back_in_stock_requests WHERE store_id IN ({$marks}) ORDER BY store_id");
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
     * Worker: mail the waiting requests of the products behind these SKUs that can be bought again, and delete
     * them. A request whose mail failed stays and the job throws, so the retry sends only what is left.
     * @param list<string> $skus
     */
    public static function process(array $skus): int {
        $db = Db::get();
        $store = StoreContext::current()->id();
        $db->prepare('DELETE FROM back_in_stock_requests WHERE store_id = ? AND created_at < ?')
            ->execute([$store, gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * 86400)]);
        $sent = 0;
        $failed = false;
        foreach (self::productIds($skus) as $productId) {
            $product = self::product($productId);
            if ($product === null || self::offered($product)) continue;
            $stmt = $db->prepare('SELECT * FROM back_in_stock_requests WHERE store_id = ? AND product_id = ? ORDER BY id LIMIT 500');
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
