<?php
declare(strict_types=1);
/**
 * BareBones Shop — Pricing
 * Catalog rules, customer group discounts, cart rules, tier pricing.
 * Reads config from pricing.json.
 */
class Pricing {
    private static ?array $config = null;

    public static function config(): array {
        if (!self::$config) {
            $file = __DIR__ . '/../pricing.json';
            self::$config = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        }
        if (!class_exists('StoreContext')) return self::$config;
        StoreContext::current()->assertPermission('pricing');
        $override = StoreContext::current()->config()['pricing'] ?? [];
        return is_array($override) ? array_replace_recursive(self::$config, $override) : self::$config;
    }

    /** Override config (for testing) */
    public static function setConfig(array $config): void {
        self::$config = $config;
    }

    /** Resolve a store/currency/customer price list without changing the base catalog row. */
    public static function applyPriceList(
        array $product,
        ?int $variantId = null,
        int $qty = 1,
        ?int $customerId = null,
        ?string $at = null
    ): array {
        if (empty($product['id'])) return $product;
        $basePrice = max(0, (int)($product['price'] ?? 0));
        $currency = strtolower((string)($product['currency'] ?? StoreContext::current()->currency()));
        $profile = Customers::commerceProfile($customerId);
        $groupId = (int)($profile['customer_group_id'] ?? 0);
        $at = $at ?? gmdate('Y-m-d H:i:s');

        try {
            $variant = false;
            if ($variantId !== null) {
                $variantStmt = Db::get()->prepare('SELECT price_absolute, price_delta FROM variants WHERE id = :id AND product_id = :product_id');
                $variantStmt->execute([':id' => $variantId, ':product_id' => (int)$product['id']]);
                $variant = $variantStmt->fetch(PDO::FETCH_ASSOC);
            }
            // A variant with its own absolute price is priced only by rows written for that variant;
            // product-level rows price the product base, and other variants keep their delta on top.
            if ($variantId === null) {
                $variantCondition = 'pp.variant_id IS NULL';
            } elseif ($variant && $variant['price_absolute'] !== null) {
                $variantCondition = 'pp.variant_id = :variant_id';
            } else {
                $variantCondition = '(pp.variant_id = :variant_id OR pp.variant_id IS NULL)';
            }
            $sql = "SELECT pp.price, pp.compare_price, pp.min_qty, pp.variant_id,
                    pl.code AS price_list_code, pl.priority, pl.customer_group_id
                FROM price_list_prices pp
                JOIN price_lists pl ON pl.id = pp.price_list_id
                WHERE pp.product_id = :product_id AND {$variantCondition}
                  AND pp.min_qty <= :qty
                  AND pl.store_id = :store AND LOWER(pl.currency) = :currency AND pl.active = 1
                  AND (pl.customer_group_id IS NULL OR pl.customer_group_id = :group_id)
                  AND (pl.valid_from IS NULL OR pl.valid_from <= :at_from)
                  AND (pl.valid_to IS NULL OR pl.valid_to >= :at_to)
                ORDER BY
                  CASE WHEN pl.customer_group_id IS NULL THEN 0 ELSE 1 END DESC,
                  CASE WHEN pp.variant_id IS NULL THEN 0 ELSE 1 END DESC,
                  pp.min_qty DESC, pl.priority DESC, pp.id DESC";
            $stmt = Db::get()->prepare($sql);
            $params = [
                ':product_id' => (int)$product['id'],
                ':qty' => max(1, $qty),
                ':store' => StoreContext::current()->id(),
                ':currency' => $currency,
                ':group_id' => $groupId,
                ':at_from' => $at,
                ':at_to' => $at,
            ];
            if ($variantId !== null) $params[':variant_id'] = $variantId;
            $stmt->execute($params);
            // The best group row and the best public row: a group price list is a benefit, so a customer in the
            // group never pays more than a guest (a product-level group row plus the variant surcharge could
            // otherwise outprice a public row written for the variant).
            $row = false;
            $rowPrice = 0;
            $seen = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
                $scope = $candidate['customer_group_id'] === null ? 'public' : 'group';
                if (isset($seen[$scope])) continue;
                $seen[$scope] = true;
                $delta = $candidate['variant_id'] === null && $variant ? (int)$variant['price_delta'] : 0;
                // Never below zero, even for rows saved before the editors refused a too-low base for a negative surcharge.
                $price = max(0, max(0, (int)$candidate['price']) + $delta);
                if ($row === false || $price < $rowPrice) [$row, $rowPrice] = [$candidate, $price];
            }
        } catch (PDOException $e) {
            $row = false;
        }

        if ($row) {
            $product['price'] = $rowPrice;
            $product['compare_price'] = $row['compare_price'] === null
                ? max($basePrice, (int)($product['compare_price'] ?? 0))
                : max(0, (int)$row['compare_price']);
            $product['price_list_snapshot'] = [
                'version' => 1,
                'code' => (string)$row['price_list_code'],
                'customer_group_id' => $row['customer_group_id'] === null ? null : (int)$row['customer_group_id'],
                'currency' => $currency,
                'min_qty' => (int)$row['min_qty'],
                'price' => (int)$product['price'],
            ];
            return $product;
        }

        $groupBps = max(0, min(10000, (int)($profile['discount_bps'] ?? 0)));
        if ($groupBps > 0) {
            $discount = intdiv($basePrice * $groupBps + 5000, 10000);
            $product['price'] = max(0, $basePrice - $discount);
            $product['compare_price'] = max($basePrice, (int)($product['compare_price'] ?? 0));
            $product['price_list_snapshot'] = [
                'version' => 1,
                'code' => 'customer_group',
                'customer_group_id' => $groupId,
                'currency' => $currency,
                'min_qty' => 1,
                'price' => (int)$product['price'],
            ];
        }
        return $product;
    }

    /** Resolve listing price-list rows in one query, keyed by product id. */
    public static function priceListRows(array $products, ?array $profile, int $qty, string $at): array {
        return self::priceListRowsWithEnded($products, $profile, $qty, $at)[0];
    }

    /**
     * priceListRows() and, from the same query, the product-level prices of price lists whose window ended in the year
     * before $at (keyed by product id: list of price, valid_from, valid_to): for the Omnibus prior price they still count
     * during the 30 days after they ended (ProductEnrichmentContext, PriceHistory::capCompare()).
     * @return array{0: array<int, array>, 1: array<int, list<array{price: int, valid_from: ?string, valid_to: string}>>}
     */
    public static function priceListRowsWithEnded(array $products, ?array $profile, int $qty, string $at): array {
        $productIds = [];
        $currencies = [];
        foreach ($products as $product) {
            $productId = (int)($product['id'] ?? 0);
            if ($productId <= 0) continue;
            $productIds[$productId] = $productId;
            $currencies[$productId] = strtolower((string)($product['currency'] ?? StoreContext::current()->currency()));
        }
        if ($productIds === []) return [[], []];

        $params = [
            ':qty' => max(1, $qty),
            ':store' => StoreContext::current()->id(),
            ':group_id' => (int)($profile['customer_group_id'] ?? 0),
            ':at_from' => $at,
            ':since' => gmdate('Y-m-d H:i:s', (strtotime($at . ' UTC') ?: time()) - 365 * 86400),
        ];
        $placeholders = [];
        foreach (array_values($productIds) as $index => $productId) {
            $placeholder = ':product_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $productId;
        }

        $sql = "SELECT pp.product_id, pp.price, pp.compare_price, pp.min_qty,
                pl.code AS price_list_code, pl.priority, pl.customer_group_id,
                LOWER(pl.currency) AS price_list_currency, pl.valid_from, pl.valid_to,
                CASE WHEN pl.customer_group_id IS NULL THEN 0 ELSE 1 END AS group_rank, pp.id AS row_id, 0 AS archived
            FROM price_list_prices pp
            JOIN price_lists pl ON pl.id = pp.price_list_id
            WHERE pp.product_id IN (" . implode(', ', $placeholders) . ")
              AND pp.variant_id IS NULL AND pp.min_qty <= :qty
              AND pl.store_id = :store AND pl.active = 1
              AND (pl.customer_group_id IS NULL OR pl.customer_group_id = :group_id)
              AND (pl.valid_from IS NULL OR pl.valid_from <= :at_from)
              AND (pl.valid_to IS NULL OR pl.valid_to >= :since)";
        // Product-level prices of runs an admin change rewrote (PricingRuns); they always count as ended.
        $runParams = [':run_store' => $params[':store'], ':run_group' => $params[':group_id'], ':run_at' => $at, ':run_since' => $params[':since']];
        foreach ($placeholders as $index => $placeholder) $runParams[':run_product_' . $index] = $params[$placeholder];
        $runs = " UNION ALL SELECT r.product_id, r.price, NULL, 1, '', 0, r.customer_group_id, LOWER(r.currency), r.valid_from, r.valid_to, 0, r.id, 1
            FROM price_list_runs r
            WHERE r.product_id IN (" . implode(', ', array_slice(array_keys($runParams), 4)) . ")
              AND r.store_id = :run_store AND (r.customer_group_id IS NULL OR r.customer_group_id = :run_group)
              AND (r.valid_from IS NULL OR r.valid_from <= :run_at) AND r.valid_to >= :run_since";
        $order = ' ORDER BY product_id, group_rank DESC, min_qty DESC, priority DESC, row_id DESC';
        try {
            $rows = self::fetchRows($sql . $runs . $order, $params + $runParams);
        } catch (PDOException) {
            try {
                // price_list_runs is missing until migration 20261022150000 is applied.
                $rows = self::fetchRows($sql . $order, $params);
            } catch (PDOException) {
                return [[], []];
            }
        }

        $result = [];
        $ended = [];
        foreach ($rows as $row) {
            $productId = (int)$row['product_id'];
            if (($currencies[$productId] ?? '') !== $row['price_list_currency']) continue;
            if ((int)$row['archived'] === 1 || ($row['valid_to'] !== null && (string)$row['valid_to'] < $at)) {
                if ((int)$row['min_qty'] <= 1) {
                    $ended[$productId][] = ['price' => max(0, (int)$row['price']), 'valid_from' => $row['valid_from'], 'valid_to' => (string)$row['valid_to']];
                }
                continue;
            }
            if (isset($result[$productId])) continue;
            $result[$productId] = $row;
        }
        return [$result, $ended];
    }

    private static function fetchRows(string $sql, array $params): array {
        $stmt = Db::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Apply a pre-resolved listing price row without further database access. */
    public static function applyPriceListContext(array $product, ?array $row, ?array $profile): array {
        if (empty($product['id'])) return $product;
        $basePrice = max(0, (int)($product['price'] ?? 0));
        $currency = strtolower((string)($product['currency'] ?? StoreContext::current()->currency()));
        $groupId = (int)($profile['customer_group_id'] ?? 0);

        if ($row) {
            $product['price'] = max(0, (int)$row['price']);
            $product['compare_price'] = $row['compare_price'] === null
                ? max($basePrice, (int)($product['compare_price'] ?? 0))
                : max(0, (int)$row['compare_price']);
            $product['price_list_snapshot'] = [
                'version' => 1,
                'code' => (string)$row['price_list_code'],
                'customer_group_id' => $row['customer_group_id'] === null ? null : (int)$row['customer_group_id'],
                'currency' => $currency,
                'min_qty' => (int)$row['min_qty'],
                'price' => (int)$product['price'],
            ];
            return $product;
        }

        $groupBps = max(0, min(10000, (int)($profile['discount_bps'] ?? 0)));
        if ($groupBps > 0) {
            $discount = intdiv($basePrice * $groupBps + 5000, 10000);
            $product['price'] = max(0, $basePrice - $discount);
            $product['compare_price'] = max($basePrice, (int)($product['compare_price'] ?? 0));
            $product['price_list_snapshot'] = [
                'version' => 1,
                'code' => 'customer_group',
                'customer_group_id' => $groupId,
                'currency' => $currency,
                'min_qty' => 1,
                'price' => (int)$product['price'],
            ];
        }
        return $product;
    }

    /**
     * Apply catalog-level pricing to a product (pre-computable rules).
     * Returns product with adjusted price + original as compare_price.
     */
    public static function apply(array $product, string $group = 'guest', ?string $date = null, int $qty = 1): array {
        $cfg = self::config();
        $date = $date ?? date('Y-m-d');
        $originalPrice = $product['price'];
        $bestDiscount = 0;

        // 1. Customer group discount
        $groupDiscount = $cfg['customer_groups'][$group]['discount'] ?? 0;
        if ($groupDiscount > 0) {
            $bestDiscount = $groupDiscount;
        }

        // 2. Catalog rules (find best matching)
        foreach ($cfg['catalog_rules'] ?? [] as $rule) {
            if (!($rule['active'] ?? false)) continue;
            if (!empty($rule['from']) && $date < $rule['from']) continue;
            if (!empty($rule['to']) && $date > $rule['to']) continue;

            if (!self::matchesConditions($product, $rule['conditions'] ?? [], $qty)) continue;

            if ($rule['discount_type'] === 'percent') {
                $bestDiscount = max($bestDiscount, $rule['discount_value']);
            } elseif ($rule['discount_type'] === 'fixed' && $originalPrice > 0) {
                $fixedPercent = min(100.0, ($rule['discount_value'] / $originalPrice) * 100);
                $bestDiscount = max($bestDiscount, $fixedPercent);
            }
        }

        // Apply best discount
        if ($bestDiscount > 0) {
            $discountedPrice = max(0, (int)round($originalPrice * (1 - $bestDiscount / 100)));
            if ($discountedPrice < $originalPrice) {
                $product['price'] = $discountedPrice;
                // Only set compare_price if there isn't already a higher one
                if (empty($product['compare_price']) || $originalPrice > $product['compare_price']) {
                    $product['compare_price'] = $originalPrice;
                }
                $product['discount_percent'] = (int)round($bestDiscount);
            }
        }

        return $product;
    }

    /**
     * Apply tier pricing to a line item (runtime, depends on qty).
     * Returns discounted unit price in cents.
     */
    public static function tierPrice(int $unitPrice, int $qty): int {
        $cfg = self::config();
        if (!($cfg['tier_pricing']['enabled'] ?? false)) return $unitPrice;

        $discount = 0;
        foreach ($cfg['tier_pricing']['rules'] ?? [] as $tier) {
            if ($qty >= $tier['min_qty']) {
                $discount = $tier['discount_percent'];
            }
        }

        if ($discount > 0) {
            return (int)round($unitPrice * (1 - $discount / 100));
        }
        return $unitPrice;
    }

    /**
     * Apply cart-level coupon code.
     * Returns [discount_amount, rule_name] or [0, null].
     */
    public static function applyCoupon(string $code, int $subtotal, ?string $customerEmail = null): array {
        $cfg = self::config();
        $code = trim($code);
        $customerEmail = $customerEmail === null ? null : self::normalizeEmail($customerEmail);

        foreach ($cfg['cart_rules'] ?? [] as $rule) {
            if (!($rule['active'] ?? false)) continue;
            if (($rule['type'] ?? '') !== 'coupon') continue;
            if (strtoupper($rule['code'] ?? '') !== strtoupper($code)) continue;

            // Check max_uses limit
            if (!empty($rule['max_uses'])) {
                $totalUses = self::couponUseCount($code);
                if ($totalUses >= $rule['max_uses']) {
                    return ['discount' => 0, 'name' => null, 'error' => 'Coupon expired'];
                }
            }

            // Check per_customer limit
            if (!empty($rule['per_customer']) && $customerEmail) {
                $customerUses = self::couponUseCount($code, $customerEmail);
                if ($customerUses >= $rule['per_customer']) {
                    return ['discount' => 0, 'name' => null, 'error' => 'Coupon already used'];
                }
            }

            // Min subtotal last, as for admin coupons: it is reported only for an otherwise usable code.
            if (!empty($rule['min_subtotal']) && $subtotal < $rule['min_subtotal']) {
                return ['discount' => 0, 'name' => null, 'error' => 'Minimum order not met'];
            }

            if ($rule['discount_type'] === 'percent') {
                $discount = (int)round($subtotal * $rule['discount_value'] / 100);
            } elseif ($rule['discount_type'] === 'fixed') {
                $discount = min($rule['discount_value'], $subtotal);
            } elseif ($rule['discount_type'] === 'free_shipping') {
                return ['discount' => 0, 'name' => $rule['name'], 'error' => null, 'free_shipping' => true, 'stacking' => (string)($rule['stacking'] ?? 'stack')];
            } else {
                continue;
            }

            return ['discount' => $discount, 'name' => $rule['name'], 'error' => null, 'stacking' => (string)($rule['stacking'] ?? 'stack')];
        }

        // Coupon promotions from the admin (promotions.coupon_code); pricing.json keeps precedence and the admin refuses its codes.
        $promotion = class_exists('Promotions') ? Promotions::couponRule($code) : null;
        if ($promotion === null) return ['discount' => 0, 'name' => null, 'error' => 'Invalid coupon'];
        $limits = Promotions::couponLimits($promotion);
        if ($limits['max_uses'] > 0 && self::couponUseCount($code) >= $limits['max_uses']) {
            return ['discount' => 0, 'name' => null, 'error' => 'Coupon expired'];
        }
        if ($limits['per_customer'] > 0 && $customerEmail && self::couponUseCount($code, $customerEmail) >= $limits['per_customer']) {
            return ['discount' => 0, 'name' => null, 'error' => 'Coupon already used'];
        }
        return Promotions::couponDiscount($promotion, $subtotal) + ['stacking' => (string)($promotion['stacking'] ?? 'exclusive')];
    }

    /**
     * Whether an applyCoupon() result is an accepted coupon: it discounts the goods or waives the shipping.
     * A free-shipping coupon discounts nothing, so it never competes with automatic discounts (combineDiscounts).
     */
    public static function couponApplies(array $coupon): bool {
        return (int)($coupon['discount'] ?? 0) > 0 || !empty($coupon['free_shipping']);
    }

    /**
     * Coupon plus automatic cart discounts. A coupon whose stacking is not 'stack' is never added on top of
     * automatic discounts: the larger of the two applies, so entering a coupon never costs the buyer more.
     * @return array{coupon:int, promotion:int}
     */
    public static function combineDiscounts(int $coupon, string $stacking, int $promotion): array {
        if ($coupon <= 0 || $promotion <= 0 || $stacking === 'stack') return ['coupon' => max(0, $coupon), 'promotion' => max(0, $promotion)];
        return $coupon >= $promotion ? ['coupon' => $coupon, 'promotion' => 0] : ['coupon' => 0, 'promotion' => $promotion];
    }

    /** Upper-case coupon codes that pricing.json defines for the active store. */
    public static function configCouponCodes(): array {
        $codes = [];
        foreach (self::config()['cart_rules'] ?? [] as $rule) {
            if (is_array($rule) && ($rule['type'] ?? '') === 'coupon' && trim((string)($rule['code'] ?? '')) !== '') $codes[] = strtoupper(trim((string)$rule['code']));
        }
        return array_values(array_unique($codes));
    }

    /**
     * Record a coupon use after successful order, inside the caller's order transaction.
     * max_uses and per_customer are claimed with a conditional counter UPDATE, so concurrent
     * redemptions serialize on the counter row instead of racing a COUNT + INSERT.
     * Throws \RuntimeException if a limit is exhausted (caller must rollback).
     */
    public static function recordCouponUse(string $code, int $orderId, string $email, int $discount): void {
        $cfg = self::config();
        $upperCode = strtoupper(trim($code));
        $email = self::normalizeEmail($email);

        $limits = null;
        foreach ($cfg['cart_rules'] ?? [] as $rule) {
            if (($rule['type'] ?? '') !== 'coupon') continue;
            if (strtoupper($rule['code'] ?? '') !== $upperCode) continue;
            $limits = ['max_uses' => (int)($rule['max_uses'] ?? 0), 'per_customer' => (int)($rule['per_customer'] ?? 0)];
            break;
        }
        if ($limits === null && class_exists('Promotions')) {
            $promotion = Promotions::couponRule($upperCode, false);
            if ($promotion !== null) $limits = Promotions::couponLimits($promotion);
        }
        if (!empty($limits['max_uses']) && !self::claimCouponUse($upperCode, null, (int)$limits['max_uses'])) {
            throw new \RuntimeException("Coupon {$upperCode} exceeded max_uses limit");
        }
        if (!empty($limits['per_customer']) && !self::claimCouponUse($upperCode, $email, (int)$limits['per_customer'])) {
            throw new \RuntimeException("Coupon {$upperCode} already used by this customer");
        }

        $db = Db::get();
        $stmt = $db->prepare("INSERT INTO coupon_uses (coupon_code, order_id, customer_email, discount, store_id) VALUES (:code, :oid, :email, :disc, :store)");
        $stmt->execute([':code' => $upperCode, ':oid' => $orderId, ':email' => $email, ':disc' => $discount, ':store' => StoreContext::current()->id()]);
    }

    /** A cancelled or expired order gives its coupon use back: the usage row and both limit counters. */
    public static function releaseCouponUse(int $orderId): void {
        $db = Db::get();
        $store = StoreContext::current()->id();
        $uses = $db->prepare("SELECT id, coupon_code, customer_email FROM coupon_uses WHERE order_id = :order AND store_id = :store");
        $uses->execute([':order' => $orderId, ':store' => $store]);
        $delete = $db->prepare("DELETE FROM coupon_uses WHERE id = :id");
        $decrement = $db->prepare("UPDATE coupon_usage_counters SET uses = uses - 1
            WHERE store_id = :store AND coupon_code = :code AND subject = :subject AND uses > 0");
        foreach ($uses->fetchAll(PDO::FETCH_ASSOC) as $use) {
            $delete->execute([':id' => (int)$use['id']]);
            foreach ([null, self::normalizeEmail((string)$use['customer_email'])] as $email) {
                $decrement->execute([':store' => $store, ':code' => strtoupper((string)$use['coupon_code']), ':subject' => self::couponSubject($email)]);
            }
        }
    }

    public static function normalizeEmail(string $email): string {
        return Str::normalizeEmail($email);
    }

    /** Counter subject: '' for all uses, a hash for one customer (no plain e-mail outside coupon_uses). */
    private static function couponSubject(?string $email): string {
        return $email === null ? '' : 'customer:' . hash('sha256', $email);
    }

    /** Seed the counter from historic uses once, then claim one use only while below the limit. */
    private static function claimCouponUse(string $code, ?string $email, int $limit): bool {
        $db = Db::get();
        $store = StoreContext::current()->id();
        $subject = self::couponSubject($email);
        $seed = $db->prepare(Db::insertIgnore() . " INTO coupon_usage_counters (store_id, coupon_code, subject, uses)
            SELECT :store, :code, :subject, COUNT(*) FROM coupon_uses WHERE store_id = :use_store AND coupon_code = :use_code"
            . ($email === null ? '' : ' AND customer_email = :email'));
        $params = [':store' => $store, ':code' => $code, ':subject' => $subject, ':use_store' => $store, ':use_code' => $code];
        if ($email !== null) $params[':email'] = $email;
        $seed->execute($params);
        $claim = $db->prepare("UPDATE coupon_usage_counters SET uses = uses + 1
            WHERE store_id = :store AND coupon_code = :code AND subject = :subject AND uses < :limit");
        $claim->execute([':store' => $store, ':code' => $code, ':subject' => $subject, ':limit' => $limit]);
        return $claim->rowCount() === 1;
    }

    /** Count coupon uses (total or per normalized customer e-mail) for the checkout preview. */
    private static function couponUseCount(string $code, ?string $email = null): int {
        $db = Db::get();
        $code = strtoupper($code);
        $store = StoreContext::current()->id();
        $counter = $db->prepare("SELECT uses FROM coupon_usage_counters WHERE store_id = :store AND coupon_code = :code AND subject = :subject");
        $counter->execute([':store' => $store, ':code' => $code, ':subject' => self::couponSubject($email)]);
        $claimed = $counter->fetchColumn();
        if ($claimed !== false) return (int)$claimed;
        if ($email !== null) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM coupon_uses WHERE coupon_code = :code AND customer_email = :email AND store_id = :store");
            $stmt->execute([':code' => $code, ':email' => $email, ':store' => $store]);
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM coupon_uses WHERE coupon_code = :code AND store_id = :store");
            $stmt->execute([':code' => $code, ':store' => $store]);
        }
        return (int)$stmt->fetchColumn();
    }

    /**
     * Check automatic cart rules (free shipping etc.)
     * Returns array of triggered actions.
     */
    public static function autoCartRules(int $subtotal, array $items = [], ?int $customerId = null, ?string $currency = null): array {
        $cfg = self::config();
        $actions = [];
        $rulesCurrency = $currency === null ? null : self::cartRulesCurrency();

        foreach ($cfg['cart_rules'] ?? [] as $rule) {
            if (!($rule['active'] ?? false)) continue;
            if (($rule['type'] ?? '') !== 'automatic') continue;

            $conditions = $rule['conditions'] ?? [];
            // min_subtotal is an amount in the rule's currency: a cart in another currency is not compared with it.
            if ($currency !== null && strtolower((string)($conditions['currency'] ?? $rulesCurrency)) !== strtolower($currency)) continue;
            if (!empty($conditions['min_subtotal']) && $subtotal < $conditions['min_subtotal']) continue;

            $actions[] = [
                'name'   => $rule['name'],
                'action' => $rule['action'] ?? 'discount',
                'discount' => 0,
            ];
        }

        if ($items !== [] && class_exists('Promotions')) {
            $actions = array_merge($actions, Promotions::cartActions($items, $subtotal, $customerId));
        }
        // A percentage of a cart is charged in units the cart's currency shows (whole Kč/Ft).
        if ($currency !== null) {
            foreach ($actions as $i => $action) $actions[$i]['discount'] = min(max(0, $subtotal), Money::round((int)($action['discount'] ?? 0), $currency));
        }
        return $actions;
    }

    /**
     * Whether an automatic rule gives free shipping. Like a shipping method's "free from", it is earned by what the
     * buyer pays for the goods ($paidSubtotal = subtotal after the coupon and automatic discounts) in the cart's currency.
     */
    public static function freeShippingEarned(int $paidSubtotal, array $items, ?int $customerId, string $currency): bool {
        foreach (self::autoCartRules(max(0, $paidSubtotal), $items, $customerId, $currency) as $rule) {
            if (($rule['action'] ?? '') === 'free_shipping') return true;
        }
        return false;
    }

    /**
     * Currency of pricing.json cart-rule amounts without their own conditions.currency: a store that defines its own
     * cart_rules (stores.json pricing) prices them in its currency, shared rules are in the default store's currency.
     */
    private static function cartRulesCurrency(): string {
        if (class_exists('StoreContext') && StoreContext::hasCurrent()) {
            $store = StoreContext::current()->config();
            if (isset($store['pricing']['cart_rules'])) return strtolower((string)($store['currency'] ?? 'eur'));
        }
        $stores = class_exists('Bootstrap') ? Bootstrap::stores() : [];
        $default = (string)($stores['default_store'] ?? 'default');
        return strtolower((string)($stores['stores'][$default]['currency'] ?? 'eur'));
    }

    /**
     * Check if product matches rule conditions
     */
    private static function matchesConditions(array $product, array $conditions, int $qty = 1): bool {
        if (empty($conditions)) return true;

        if (!empty($conditions['category'])) {
            // Check by category path or slug
            $catSlug = $conditions['category'];
            if (!empty($product['category_path'])) {
                if (strpos($product['category_path'], $catSlug) === false) return false;
            } elseif (!empty($product['category_slug'])) {
                if ($product['category_slug'] !== $catSlug) return false;
            }
        }

        if (!empty($conditions['product_type'])) {
            if (($product['product_type'] ?? 'simple') !== $conditions['product_type']) return false;
        }

        if (!empty($conditions['min_qty'])) {
            if ($qty < (int)$conditions['min_qty']) return false;
        }

        return true;
    }

    /**
     * Get customer group info
     */
    public static function customerGroups(): array {
        return self::config()['customer_groups'] ?? [];
    }
}
