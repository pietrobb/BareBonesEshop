<?php
/** Deterministic DB-backed catalog and cart promotion evaluation. */
class Promotions {
    public static function applyToProduct(
        array $product,
        int $qty = 1,
        ?int $customerId = null,
        ?string $at = null
    ): array {
        $customerId ??= self::sessionCustomerId();
        $profile = Customers::commerceProfile($customerId);
        $rules = self::activeRules('catalog', $at);
        if ($rules === []) return $product;

        $basePrice = max(0, (int)($product['price'] ?? 0));
        $price = $basePrice;
        $applied = [];
        foreach ($rules as $rule) {
            $conditions = self::jsonObject($rule['conditions'] ?? '{}');
            if (!self::matchesProduct($product, $conditions, $qty, $profile)) continue;
            $actions = self::jsonObject($rule['actions'] ?? '{}');
            $next = self::catalogAction($price, $actions);
            if ($next >= $price) continue;
            $price = $next;
            $applied[] = ['code' => (string)$rule['code'], 'amount' => $basePrice - $price];
            if (($rule['stacking'] ?? 'exclusive') !== 'stack') break;
        }

        if ($price < $basePrice) {
            $product['price'] = $price;
            $product['compare_price'] = max($basePrice, (int)($product['compare_price'] ?? 0));
            $product['promotion_snapshot'] = [
                'version' => 1,
                'base_price' => $basePrice,
                'price' => $price,
                'applied' => $applied,
            ];
        }
        return $product;
    }

    /**
     * Resolve automatic cart actions. Discounts are integer cents; gift actions
     * are explicit entitlements and never silently mutate stock.
     */
    public static function cartActions(
        array $items,
        int $subtotal,
        ?int $customerId = null,
        ?string $at = null
    ): array {
        $profile = Customers::commerceProfile($customerId ?? self::sessionCustomerId());
        $result = [];
        $discountUsed = 0;
        foreach (self::activeRules('cart', $at) as $rule) {
            if (!empty($rule['coupon_code'])) continue;
            $conditions = self::jsonObject($rule['conditions'] ?? '{}');
            if (!self::matchesCart($items, $subtotal, $conditions, $profile)) continue;
            $actions = self::jsonObject($rule['actions'] ?? '{}');
            $action = (string)($actions['type'] ?? 'discount_fixed');
            $discount = match ($action) {
                'discount_fixed' => max(0, (int)($actions['amount'] ?? 0)),
                'discount_percent' => self::percent(max(0, $subtotal - $discountUsed), (int)($actions['bps'] ?? 0)),
                'buy_x_get_y' => self::buyXGetYDiscount($items, $conditions, $actions),
                default => 0,
            };
            $discount = min($discount, max(0, $subtotal - $discountUsed));
            $entry = [
                'code' => (string)$rule['code'],
                'name' => (string)$rule['name'],
                'action' => $action,
                'discount' => $discount,
            ];
            if ($action === 'gift') {
                $entry['gift'] = [
                    'product_id' => (int)($actions['product_id'] ?? 0),
                    'variant_id' => isset($actions['variant_id']) ? (int)$actions['variant_id'] : null,
                    'qty' => max(1, (int)($actions['qty'] ?? 1)),
                ];
            }
            $result[] = $entry;
            $discountUsed += $discount;
            if (($rule['stacking'] ?? 'exclusive') !== 'stack') break;
        }
        return $result;
    }

    private static function activeRules(string $scope, ?string $at): array {
        $at = $at ?? gmdate('Y-m-d H:i:s');
        try {
            $stmt = Db::get()->prepare("SELECT * FROM promotions
                WHERE store_id = :store AND scope = :scope AND active = 1
                  AND (valid_from IS NULL OR valid_from <= :at_from)
                  AND (valid_to IS NULL OR valid_to >= :at_to)
                ORDER BY priority ASC, id ASC");
            $stmt->execute([
                ':store' => StoreContext::current()->id(),
                ':scope' => $scope,
                ':at_from' => $at,
                ':at_to' => $at,
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    private static function matchesProduct(array $product, array $conditions, int $qty, ?array $profile): bool {
        if (isset($conditions['min_qty']) && $qty < (int)$conditions['min_qty']) return false;
        if (!empty($conditions['product_ids']) && !in_array((int)($product['id'] ?? 0), array_map('intval', $conditions['product_ids']), true)) return false;
        if (!empty($conditions['brand_ids']) && !in_array((int)($product['brand_id'] ?? 0), array_map('intval', $conditions['brand_ids']), true)) return false;
        if (!empty($conditions['customer_group_ids']) && !in_array((int)($profile['customer_group_id'] ?? 0), array_map('intval', $conditions['customer_group_ids']), true)) return false;
        if (!empty($conditions['category_ids'])) {
            $categoryIds = isset($product['category_ids']) && is_array($product['category_ids'])
                ? array_map('intval', $product['category_ids'])
                : Products::categoryIds((int)($product['id'] ?? 0));
            if (array_intersect($categoryIds, array_map('intval', $conditions['category_ids'])) === []) return false;
        }
        return true;
    }

    private static function matchesCart(array $items, int $subtotal, array $conditions, ?array $profile): bool {
        if (isset($conditions['min_subtotal']) && $subtotal < (int)$conditions['min_subtotal']) return false;
        if (!empty($conditions['customer_group_ids']) && !in_array((int)($profile['customer_group_id'] ?? 0), array_map('intval', $conditions['customer_group_ids']), true)) return false;
        if (isset($conditions['buy_product_id'])) {
            $qty = 0;
            foreach ($items as $item) {
                if ((int)($item['product_id'] ?? 0) === (int)$conditions['buy_product_id']) $qty += (int)($item['qty'] ?? 0);
            }
            if ($qty < max(1, (int)($conditions['buy_qty'] ?? 1))) return false;
        }
        return true;
    }

    private static function catalogAction(int $price, array $actions): int {
        return match ((string)($actions['type'] ?? 'discount_percent')) {
            'fixed_price' => max(0, min($price, (int)($actions['price'] ?? $price))),
            'discount_fixed' => max(0, $price - max(0, (int)($actions['amount'] ?? 0))),
            'discount_percent' => max(0, $price - self::percent($price, (int)($actions['bps'] ?? 0))),
            default => $price,
        };
    }

    private static function buyXGetYDiscount(array $items, array $conditions, array $actions): int {
        $buyId = (int)($conditions['buy_product_id'] ?? 0);
        $buyQty = max(1, (int)($conditions['buy_qty'] ?? 1));
        $getId = (int)($actions['product_id'] ?? 0);
        $getQty = max(1, (int)($actions['qty'] ?? 1));
        $bought = 0;
        $eligible = 0;
        $unitPrice = null;
        foreach ($items as $item) {
            if ((int)($item['product_id'] ?? 0) === $buyId) $bought += (int)($item['qty'] ?? 0);
            if ((int)($item['product_id'] ?? 0) === $getId) {
                $eligible += (int)($item['qty'] ?? 0);
                $candidate = max(0, (int)($item['price'] ?? 0));
                $unitPrice = $unitPrice === null ? $candidate : min($unitPrice, $candidate);
            }
        }
        if ($unitPrice === null) return 0;
        $freeQty = min($eligible, intdiv($bought, $buyQty) * $getQty);
        return $unitPrice * $freeQty;
    }

    private static function percent(int $amount, int $bps): int {
        $bps = max(0, min(10000, $bps));
        return intdiv($amount * $bps + 5000, 10000);
    }

    private static function jsonObject(mixed $value): array {
        if (is_array($value)) return $value;
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function sessionCustomerId(): ?int {
        $id = (int)($_SESSION['customer_id'] ?? 0);
        return $id > 0 ? $id : null;
    }
}
