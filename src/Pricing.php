<?php
/**
 * BareBones Shop — Pricing
 * Catalog rules, customer group discounts, cart rules, tier pricing.
 * Reads config from pricing.json.
 */
class Pricing {
    private static ?array $config = null;

    private static function config(): array {
        if (!self::$config) {
            $file = __DIR__ . '/../pricing.json';
            self::$config = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        }
        return self::$config;
    }

    /** Override config (for testing) */
    public static function setConfig(array $config): void {
        self::$config = $config;
    }

    /**
     * Apply catalog-level pricing to a product (pre-computable rules).
     * Returns product with adjusted price + original as compare_price.
     */
    public static function apply(array $product, string $group = 'guest', ?string $date = null): array {
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

            if (!self::matchesConditions($product, $rule['conditions'] ?? [])) continue;

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

        foreach ($cfg['cart_rules'] ?? [] as $rule) {
            if (!($rule['active'] ?? false)) continue;
            if (($rule['type'] ?? '') !== 'coupon') continue;
            if (strtoupper($rule['code'] ?? '') !== strtoupper($code)) continue;

            // Check min subtotal
            if (!empty($rule['min_subtotal']) && $subtotal < $rule['min_subtotal']) {
                return ['discount' => 0, 'name' => null, 'error' => 'Minimum order not met'];
            }

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

            if ($rule['discount_type'] === 'percent') {
                $discount = (int)round($subtotal * $rule['discount_value'] / 100);
            } elseif ($rule['discount_type'] === 'fixed') {
                $discount = min($rule['discount_value'], $subtotal);
            } else {
                continue;
            }

            return ['discount' => $discount, 'name' => $rule['name'], 'error' => null];
        }

        return ['discount' => 0, 'name' => null, 'error' => 'Invalid coupon'];
    }

    /** Record a coupon use after successful order */
    public static function recordCouponUse(string $code, int $orderId, string $email, int $discount): void {
        $db = Db::get();
        $stmt = $db->prepare("INSERT INTO coupon_uses (coupon_code, order_id, customer_email, discount) VALUES (:code, :oid, :email, :disc)");
        $stmt->execute([':code' => strtoupper($code), ':oid' => $orderId, ':email' => $email, ':disc' => $discount]);
    }

    /** Count coupon uses (total or per customer) */
    private static function couponUseCount(string $code, ?string $email = null): int {
        $db = Db::get();
        if ($email) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM coupon_uses WHERE coupon_code = :code AND customer_email = :email");
            $stmt->execute([':code' => strtoupper($code), ':email' => $email]);
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM coupon_uses WHERE coupon_code = :code");
            $stmt->execute([':code' => strtoupper($code)]);
        }
        return (int)$stmt->fetchColumn();
    }

    /**
     * Check automatic cart rules (free shipping etc.)
     * Returns array of triggered actions.
     */
    public static function autoCartRules(int $subtotal): array {
        $cfg = self::config();
        $actions = [];

        foreach ($cfg['cart_rules'] ?? [] as $rule) {
            if (!($rule['active'] ?? false)) continue;
            if (($rule['type'] ?? '') !== 'automatic') continue;

            $conditions = $rule['conditions'] ?? [];
            if (!empty($conditions['min_subtotal']) && $subtotal < $conditions['min_subtotal']) continue;

            $actions[] = [
                'name'   => $rule['name'],
                'action' => $rule['action'] ?? 'discount',
            ];
        }

        return $actions;
    }

    /**
     * Check if product matches rule conditions
     */
    private static function matchesConditions(array $product, array $conditions): bool {
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

        return true;
    }

    /**
     * Get customer group info
     */
    public static function customerGroups(): array {
        return self::config()['customer_groups'] ?? [];
    }
}
