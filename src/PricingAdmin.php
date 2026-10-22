<?php
/**
 * A12.5: admin CRUD for the active store's promotions (catalog and cart rules, coupon codes) and price lists.
 * Rows belong to the store (store_id = StoreContext id); products, variants, categories and brands named in a rule or a
 * price row must belong to the store's catalog, customer groups to the store. Money is integer cents, percentages are
 * entered in % and stored as basis points, validity is UTC. A coupon promotion is a cart rule redeemed only with its code
 * (Pricing::applyCoupon); a code that pricing.json already defines is refused so one code never means two discounts.
 * Every write is audited and marks the store's guest product and category caches stale, because they hold resolved prices.
 * Write methods return ['ok' => bool, 'id' => int, 'message' => flash text].
 */
final class PricingAdmin {
    private const MAX_TEXT = 191;
    private const MAX_INT = 2000000000;
    private const MAX_PRICE_LINES = 5000;
    /** Allowed action types per kind of promotion (a coupon is a cart rule with a code). */
    private const ACTIONS = [
        'catalog' => ['discount_percent', 'discount_fixed', 'fixed_price'],
        'cart' => ['discount_percent', 'discount_fixed', 'free_shipping', 'buy_x_get_y', 'gift'],
        'coupon' => ['discount_percent', 'discount_fixed', 'free_shipping'],
    ];
    /** Every promotion action type; its label is the lang key admin.promo.action.<type>. */
    public const ACTION_TYPES = ['discount_percent', 'discount_fixed', 'fixed_price', 'free_shipping', 'buy_x_get_y', 'gift'];
    private const PROMOTION_FIELDS = ['code', 'name', 'scope', 'coupon_code', 'priority', 'stacking', 'valid_from', 'valid_to', 'active',
        'action_type', 'action_value', 'action_sku', 'action_qty', 'cond_min_qty', 'cond_product_skus', 'cond_min_subtotal', 'cond_buy_sku',
        'cond_buy_qty', 'cond_max_uses', 'cond_per_customer'];
    private const PROMOTION_LISTS = ['cond_category_ids', 'cond_brand_ids', 'cond_customer_group_ids'];

    public static function promotions(): array {
        return self::storeRows('SELECT * FROM promotions WHERE store_id = :store ORDER BY scope, priority, id');
    }

    public static function promotion(int $id): ?array {
        return self::storeRows('SELECT * FROM promotions WHERE id = :id AND store_id = :store', [':id' => $id])[0] ?? null;
    }

    /** Form values of a stored promotion (product ids shown as SKUs, basis points as %, UTC dates for datetime-local). */
    public static function promotionForm(?array $row): array {
        $c = self::json($row['conditions'] ?? '{}');
        $a = self::json($row['actions'] ?? '{}');
        $type = (string)($a['type'] ?? 'discount_percent');
        $int = static fn(array $source, string $key): string => isset($source[$key]) ? (string)(int)$source[$key] : '';
        $ids = static fn(string $key): array => is_array($c[$key] ?? null) ? array_map('intval', $c[$key]) : [];
        return [
            'code' => (string)($row['code'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'scope' => (string)($row['scope'] ?? 'catalog'),
            'coupon_code' => (string)($row['coupon_code'] ?? ''),
            'priority' => (string)($row['priority'] ?? '100'),
            'stacking' => (string)($row['stacking'] ?? 'exclusive'),
            'valid_from' => self::formDate($row['valid_from'] ?? null),
            'valid_to' => self::formDate($row['valid_to'] ?? null),
            'active' => $row === null ? '1' : (string)(int)$row['active'],
            'action_type' => $type,
            'action_value' => match ($type) {
                'discount_percent' => self::bpsToPercent((int)($a['bps'] ?? 0)),
                'discount_fixed' => $int($a, 'amount'),
                'fixed_price' => $int($a, 'price'),
                default => '',
            },
            'action_sku' => isset($a['product_id']) ? self::skuOf((int)$a['product_id'], isset($a['variant_id']) ? (int)$a['variant_id'] : null) : '',
            'action_qty' => $int($a, 'qty'),
            'cond_min_qty' => $int($c, 'min_qty'),
            'cond_product_skus' => implode(', ', array_map(static fn(int $id): string => self::skuOf($id, null), $ids('product_ids'))),
            'cond_category_ids' => $ids('category_ids'),
            'cond_brand_ids' => $ids('brand_ids'),
            'cond_customer_group_ids' => $ids('customer_group_ids'),
            'cond_min_subtotal' => $int($c, 'min_subtotal'),
            'cond_buy_sku' => isset($c['buy_product_id']) ? self::skuOf((int)$c['buy_product_id'], null) : '',
            'cond_buy_qty' => $int($c, 'buy_qty'),
            'cond_max_uses' => $int($c, 'max_uses'),
            'cond_per_customer' => $int($c, 'per_customer'),
        ];
    }

    public static function savePromotion(int $id, mixed $post): array {
        if (!StoreContext::current()->allows('pricing')) return self::result(false, 0, I18n::t('admin.msg.pricing.disabled'));
        $old = $id > 0 ? self::promotion($id) : null;
        if ($id > 0 && !$old) return self::result(false, 0, I18n::t('admin.msg.promo.missing'));
        $fields = self::scalars($post, self::PROMOTION_FIELDS);
        $lists = self::idLists($post, self::PROMOTION_LISTS);
        if ($fields === null || $lists === null) return self::result(false, $id, I18n::t('admin.msg.invalid_form'));
        try {
            $row = self::promotionRow($fields, $lists, $id);
        } catch (DomainException $e) {
            return self::result(false, $id, $e->getMessage());
        }
        $params = array_combine(array_map(static fn(string $k): string => ":{$k}", array_keys($row)), $row) + [':store' => StoreContext::current()->id()];
        $db = Db::get();
        $db->beginTransaction();
        try {
            if ($old) {
                PricingRuns::promotion($db, $old, $row);
                $db->prepare('UPDATE promotions SET code = :code, name = :name, scope = :scope, coupon_code = :coupon_code, priority = :priority, stacking = :stacking,
                    conditions = :conditions, actions = :actions, valid_from = :valid_from, valid_to = :valid_to, active = :active, updated_at = ' . Db::now() . '
                    WHERE id = :id AND store_id = :store')->execute($params + [':id' => $id]);
            } else {
                $db->prepare('INSERT INTO promotions (store_id, code, name, scope, coupon_code, priority, stacking, conditions, actions, valid_from, valid_to, active)
                    VALUES (:store, :code, :name, :scope, :coupon_code, :priority, :stacking, :conditions, :actions, :valid_from, :valid_to, :active)')->execute($params);
                $id = (int)$db->lastInsertId();
            }
            AuditLog::log('promotion', $id, $old, $row, 'Admin promotion');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            if ($e instanceof PDOException && str_starts_with((string)$e->getCode(), '23')) return self::result(false, $old ? $id : 0, I18n::format('admin.msg.promo.code_taken', ['code' => $row['code']]));
            throw $e;
        }
        self::invalidatePrices();
        return self::result(true, $id, I18n::t($old ? 'admin.msg.promo.saved' : 'admin.msg.promo.created'));
    }

    public static function deletePromotion(int $id): array {
        if (!StoreContext::current()->allows('pricing')) return self::result(false, 0, I18n::t('admin.msg.pricing.disabled'));
        $promotion = $id > 0 ? self::promotion($id) : null;
        if (!$promotion) return self::result(false, 0, I18n::t('admin.msg.promo.missing'));
        $coupon = (string)($promotion['coupon_code'] ?? '');
        if ($coupon !== '' && self::storeRows('SELECT 1 AS used FROM coupon_uses WHERE store_id = :store AND coupon_code = :code', [':code' => $coupon])) {
            return self::result(false, $id, I18n::format('admin.msg.promo.delete_used', ['code' => $promotion['code'], 'coupon' => $coupon]));
        }
        $db = Db::get();
        $db->beginTransaction();
        try {
            PricingRuns::promotion($db, $promotion, null);
            $db->prepare('DELETE FROM promotions WHERE id = :id AND store_id = :store')->execute([':id' => $id, ':store' => StoreContext::current()->id()]);
            AuditLog::log('promotion', $id, $promotion, null, 'Admin promotion delete');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        self::invalidatePrices();
        return self::result(true, 0, I18n::t('admin.msg.promo.deleted'));
    }

    /** Price lists of the store with the number of their prices. */
    public static function priceLists(): array {
        return self::storeRows('SELECT pl.*, (SELECT COUNT(*) FROM price_list_prices pp WHERE pp.price_list_id = pl.id) AS price_count
            FROM price_lists pl WHERE pl.store_id = :store ORDER BY pl.priority DESC, pl.code');
    }

    public static function priceList(int $id): ?array {
        return self::storeRows('SELECT * FROM price_lists WHERE id = :id AND store_id = :store', [':id' => $id])[0] ?? null;
    }

    /** Currencies a price list may use: the store currency and the currencies of the catalog's products. */
    public static function currencies(): array {
        $product = array_map('strtoupper', array_column(CatalogScope::rows('SELECT DISTINCT currency FROM products WHERE store_id = :catalog'), 'currency'));
        return array_values(array_unique([strtoupper(StoreContext::current()->currency()), ...$product]));
    }

    public static function customerGroups(): array {
        return self::storeRows('SELECT id, code, name, active FROM customer_groups WHERE store_id = :store ORDER BY name, code');
    }

    public static function savePriceList(int $id, mixed $post): array {
        if (!StoreContext::current()->allows('pricing')) return self::result(false, 0, I18n::t('admin.msg.pricing.disabled'));
        $old = $id > 0 ? self::priceList($id) : null;
        if ($id > 0 && !$old) return self::result(false, 0, I18n::t('admin.msg.pricelist.missing'));
        $fields = self::scalars($post, ['code', 'name', 'currency', 'customer_group_id', 'priority', 'valid_from', 'valid_to', 'active']);
        if ($fields === null) return self::result(false, $id, I18n::t('admin.msg.invalid_form'));
        try {
            $name = trim($fields['name']);
            if (!self::validText($name, true)) self::fail(I18n::format('admin.msg.name_required', ['max' => self::MAX_TEXT]));
            $code = self::code($fields['code'], $name);
            if (self::storeRows('SELECT 1 AS taken FROM price_lists WHERE store_id = :store AND code = :code AND id != :id', [':code' => $code, ':id' => $id ?: -1])) {
                self::fail(I18n::format('admin.msg.pricelist.code_taken', ['code' => $code]));
            }
            $currency = strtoupper(trim($fields['currency']));
            if (!in_array($currency, self::currencies(), true)) self::fail(I18n::format('admin.msg.pricelist.currency', ['currencies' => implode(I18n::t('admin.msg.or'), self::currencies())]));
            $group = trim($fields['customer_group_id']);
            $groupId = $group === '' || $group === '0' ? null : self::ownedGroups([$group])[0];
            [$from, $to] = self::validity($fields['valid_from'], $fields['valid_to']);
            $row = ['code' => $code, 'name' => $name, 'currency' => $currency, 'customer_group_id' => $groupId,
                'priority' => self::int($fields['priority'] === '' ? '0' : $fields['priority'], -1000000, 1000000, I18n::t('admin.msg.promo.priority')),
                'valid_from' => $from, 'valid_to' => $to, 'active' => self::flag($fields['active'])];
        } catch (DomainException $e) {
            return self::result(false, $id, $e->getMessage());
        }
        $params = array_combine(array_map(static fn(string $k): string => ":{$k}", array_keys($row)), $row) + [':store' => StoreContext::current()->id()];
        $db = Db::get();
        $db->beginTransaction();
        try {
            if ($old) {
                PricingRuns::priceList($db, $old, self::priceRows($id), $row);
                $db->prepare('UPDATE price_lists SET code = :code, name = :name, currency = :currency, customer_group_id = :customer_group_id, priority = :priority,
                    valid_from = :valid_from, valid_to = :valid_to, active = :active, updated_at = ' . Db::now() . ' WHERE id = :id AND store_id = :store')->execute($params + [':id' => $id]);
            } else {
                $db->prepare('INSERT INTO price_lists (store_id, code, name, currency, customer_group_id, priority, valid_from, valid_to, active)
                    VALUES (:store, :code, :name, :currency, :customer_group_id, :priority, :valid_from, :valid_to, :active)')->execute($params);
                $id = (int)$db->lastInsertId();
            }
            AuditLog::log('price_list', $id, $old, $row, 'Admin price list');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            if ($e instanceof PDOException && str_starts_with((string)$e->getCode(), '23')) return self::result(false, $old ? $id : 0, I18n::format('admin.msg.pricelist.code_taken', ['code' => $row['code']]));
            throw $e;
        }
        self::invalidatePrices();
        return self::result(true, $id, I18n::t($old ? 'admin.msg.pricelist.saved' : 'admin.msg.pricelist.created'));
    }

    public static function deletePriceList(int $id): array {
        if (!StoreContext::current()->allows('pricing')) return self::result(false, 0, I18n::t('admin.msg.pricing.disabled'));
        $list = $id > 0 ? self::priceList($id) : null;
        if (!$list) return self::result(false, 0, I18n::t('admin.msg.pricelist.missing'));
        $db = Db::get();
        $db->beginTransaction();
        try {
            $prices = self::priceRows($id);
            PricingRuns::priceList($db, $list, $prices, null);
            // Explicit: SQLite enforces the ON DELETE CASCADE only with PRAGMA foreign_keys.
            $db->prepare('DELETE FROM price_list_prices WHERE price_list_id = :id')->execute([':id' => $id]);
            $db->prepare('DELETE FROM price_lists WHERE id = :id AND store_id = :store')->execute([':id' => $id, ':store' => StoreContext::current()->id()]);
            AuditLog::log('price_list', $id, $list + ['prices' => $prices], null, 'Admin price list delete');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        self::invalidatePrices();
        return self::result(true, 0, I18n::format('admin.msg.pricelist.deleted', ['count' => count($prices)]));
    }

    /** The list's prices as editor lines "SKU | min. quantity | price | original price". */
    public static function priceLines(int $listId): string {
        return implode("\n", array_map(static fn(array $r): string => implode(' | ', array_filter([$r['sku'], (string)$r['min_qty'], (string)$r['price'],
            $r['compare_price'] === null ? null : (string)$r['compare_price']], static fn(?string $v): bool => $v !== null)), self::priceRows($listId)));
    }

    /** Replace all prices of a list from the editor lines; every SKU must be a product or variant of the store's catalog. */
    public static function savePrices(int $listId, mixed $post): array {
        if (!StoreContext::current()->allows('pricing')) return self::result(false, 0, I18n::t('admin.msg.pricing.disabled'));
        $list = $listId > 0 ? self::priceList($listId) : null;
        if (!$list) return self::result(false, 0, I18n::t('admin.msg.pricelist.missing'));
        $fields = self::scalars($post, ['prices']);
        if ($fields === null) return self::result(false, $listId, I18n::t('admin.msg.invalid_form'));
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $fields['prices'])), static fn(string $l): bool => $l !== ''));
        if (count($lines) > self::MAX_PRICE_LINES) return self::result(false, $listId, I18n::format('admin.msg.pricelist.max_lines', ['max' => self::MAX_PRICE_LINES]));
        $rows = [];
        try {
            foreach ($lines as $n => $line) {
                $label = I18n::format('admin.msg.line', ['n' => $n + 1]);
                $parts = array_map('trim', explode('|', $line));
                if (count($parts) < 3 || count($parts) > 4) self::fail(I18n::format('admin.msg.pricelist.line_format', ['line' => $label]));
                $target = self::skuTarget($parts[0], true, $label);
                $minQty = self::int($parts[1], 1, 1000000, I18n::format('admin.msg.min_qty_line', ['line' => $label]));
                $price = self::int($parts[2], 0, self::MAX_INT, I18n::format('admin.msg.pricelist.price', ['line' => $label]));
                $compare = ($parts[3] ?? '') === '' ? null : self::int($parts[3], $price, self::MAX_INT, I18n::format('admin.msg.pricelist.compare', ['line' => $label]));
                // A product-level row is the base of variants priced by a surcharge: a negative surcharge must not end below zero.
                $floor = ($target['variant_id'] ?? null) === null ? ProductVariants::minBasePrice((int)$target['product_id']) : 0;
                if ($price < $floor) self::fail(I18n::format('admin.msg.pricelist.variant_floor', ['line' => $label, 'sku' => $parts[0], 'min' => $floor]));
                $key = $target['product_id'] . ':' . ($target['variant_id'] ?? '-') . ':' . $minQty;
                if (isset($rows[$key])) self::fail(I18n::format('admin.msg.pricelist.duplicate', ['line' => $label, 'sku' => $parts[0], 'qty' => $minQty]));
                $rows[$key] = ['product_id' => $target['product_id'], 'variant_id' => $target['variant_id'] ?? null, 'min_qty' => $minQty, 'price' => $price, 'compare_price' => $compare];
            }
        } catch (DomainException $e) {
            return self::result(false, $listId, $e->getMessage());
        }
        $db = Db::get();
        $db->beginTransaction();
        try {
            $before = self::priceRows($listId);
            PricingRuns::priceList($db, $list, PricingRuns::changedPrices($before, $rows), null);
            $db->prepare('DELETE FROM price_list_prices WHERE price_list_id = :id')->execute([':id' => $listId]);
            $insert = $db->prepare('INSERT INTO price_list_prices (price_list_id, product_id, variant_id, min_qty, price, compare_price)
                VALUES (:list, :product_id, :variant_id, :min_qty, :price, :compare_price)');
            foreach ($rows as $row) $insert->execute([':list' => $listId] + array_combine(array_map(static fn(string $k): string => ":{$k}", array_keys($row)), $row));
            AuditLog::log('price_list', $listId, ['prices' => $before], ['prices' => self::priceRows($listId)], 'Admin price list prices');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        self::invalidatePrices();
        return self::result(true, $listId, I18n::format('admin.msg.pricelist.prices_saved', ['count' => count($rows)]));
    }

    /** @throws DomainException */
    private static function promotionRow(array $f, array $lists, int $id): array {
        $name = trim($f['name']);
        if (!self::validText($name, true)) self::fail(I18n::format('admin.msg.name_required', ['max' => self::MAX_TEXT]));
        $code = self::code($f['code'], $name);
        if (self::storeRows('SELECT 1 AS taken FROM promotions WHERE store_id = :store AND code = :code AND id != :id', [':code' => $code, ':id' => $id ?: -1])) {
            self::fail(I18n::format('admin.msg.promo.code_taken', ['code' => $code]));
        }
        $scope = $f['scope'];
        if (!in_array($scope, ['catalog', 'cart'], true)) self::fail(I18n::t('admin.msg.promo.scope'));
        $coupon = strtoupper(trim($f['coupon_code']));
        if ($coupon !== '') {
            if ($scope !== 'cart') self::fail(I18n::t('admin.msg.promo.coupon_cart_only'));
            if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,63}$/', $coupon)) self::fail(I18n::t('admin.msg.promo.coupon_format'));
            if (self::storeRows('SELECT 1 AS taken FROM promotions WHERE store_id = :store AND UPPER(coupon_code) = :coupon AND id != :id', [':coupon' => $coupon, ':id' => $id ?: -1])) {
                self::fail(I18n::format('admin.msg.promo.coupon_taken', ['coupon' => $coupon]));
            }
            if (in_array($coupon, Pricing::configCouponCodes(), true)) self::fail(I18n::format('admin.msg.promo.coupon_config', ['coupon' => $coupon]));
        }
        $stacking = $f['stacking'] === '' ? 'exclusive' : $f['stacking'];
        if (!in_array($stacking, ['exclusive', 'stack'], true)) self::fail(I18n::t('admin.msg.promo.stacking'));
        [$from, $to] = self::validity($f['valid_from'], $f['valid_to']);

        $kind = $coupon !== '' ? 'coupon' : $scope;
        $type = $f['action_type'];
        if (!in_array($type, self::ACTIONS[$kind], true)) {
            $label = static fn(string $t): string => in_array($t, self::ACTION_TYPES, true) ? I18n::t('admin.promo.action.' . $t) : $t;
            self::fail(I18n::format('admin.msg.promo.action_refused', ['action' => $label($type), 'kind' => I18n::t('admin.msg.promo.kind_' . $kind),
                'allowed' => implode(', ', array_map($label, self::ACTIONS[$kind]))]));
        }
        $actions = ['type' => $type] + match ($type) {
            'discount_percent' => ['bps' => self::percentBps($f['action_value'])],
            'discount_fixed' => ['amount' => self::int($f['action_value'], 1, self::MAX_INT, I18n::t('admin.msg.promo.discount_fixed'))],
            'fixed_price' => ['price' => self::int($f['action_value'], 0, self::MAX_INT, I18n::t('admin.msg.promo.fixed_price'))],
            'free_shipping' => [],
            'buy_x_get_y', 'gift' => self::skuTarget($f['action_sku'], $type === 'gift', I18n::t($type === 'gift' ? 'admin.msg.promo.label_gift' : 'admin.msg.promo.label_free_product'))
                + ['qty' => self::int($f['action_qty'] === '' ? '1' : $f['action_qty'], 1, 1000, I18n::t('admin.msg.promo.free_qty'))],
        };

        $filled = static fn(string ...$keys): bool => array_filter($keys, static fn(string $k): bool => trim($f[$k]) !== '') !== [];
        $conditions = [];
        if ($scope === 'catalog') {
            if ($filled('cond_min_subtotal', 'cond_buy_sku', 'cond_buy_qty', 'cond_max_uses', 'cond_per_customer')) {
                self::fail(I18n::t('admin.msg.promo.catalog_cart_conditions'));
            }
            if ($filled('cond_min_qty')) $conditions['min_qty'] = self::int($f['cond_min_qty'], 1, 1000000, I18n::t('admin.msg.promo.min_qty'));
            if ($filled('cond_product_skus')) $conditions['product_ids'] = self::productIds($f['cond_product_skus']);
            if ($lists['cond_category_ids']) $conditions['category_ids'] = self::owned('categories', $lists['cond_category_ids'], I18n::t('admin.msg.promo.label_category'));
            if ($lists['cond_brand_ids']) $conditions['brand_ids'] = self::owned('brands', $lists['cond_brand_ids'], I18n::t('admin.msg.promo.label_brand'));
            if ($type === 'fixed_price' && !array_intersect_key($conditions, ['product_ids' => 1, 'category_ids' => 1, 'brand_ids' => 1])) {
                self::fail(I18n::t('admin.msg.promo.fixed_price_scope'));
            }
        } else {
            if ($filled('cond_min_qty', 'cond_product_skus') || $lists['cond_category_ids'] || $lists['cond_brand_ids']) {
                self::fail(I18n::t('admin.msg.promo.cart_product_conditions'));
            }
            if ($filled('cond_min_subtotal')) $conditions['min_subtotal'] = self::int($f['cond_min_subtotal'], 1, self::MAX_INT, I18n::t('admin.msg.promo.min_subtotal'));
            if ($type === 'buy_x_get_y') {
                $conditions['buy_product_id'] = self::skuTarget($f['cond_buy_sku'], false, I18n::t('admin.msg.promo.label_bought_product'))['product_id'];
                $conditions['buy_qty'] = self::int($f['cond_buy_qty'] === '' ? '1' : $f['cond_buy_qty'], 1, 1000, I18n::t('admin.msg.promo.buy_qty'));
            } elseif ($filled('cond_buy_sku', 'cond_buy_qty')) {
                self::fail(I18n::t('admin.msg.promo.buy_only'));
            }
            foreach (['cond_max_uses' => 'max_uses', 'cond_per_customer' => 'per_customer'] as $field => $key) {
                if (!$filled($field)) continue;
                if ($coupon === '') self::fail(I18n::t('admin.msg.promo.limits_coupon_only'));
                $conditions[$key] = self::int($f[$field], 1, self::MAX_INT, I18n::t('admin.msg.promo.limit_int'));
            }
        }
        if ($lists['cond_customer_group_ids']) $conditions['customer_group_ids'] = self::ownedGroups($lists['cond_customer_group_ids']);

        return [
            'code' => $code,
            'name' => $name,
            'scope' => $scope,
            'coupon_code' => $coupon === '' ? null : $coupon,
            'priority' => self::int($f['priority'] === '' ? '100' : $f['priority'], -1000000, 1000000, I18n::t('admin.msg.promo.priority')),
            'stacking' => $stacking,
            'conditions' => $conditions === [] ? '{}' : json_encode($conditions, JSON_THROW_ON_ERROR),
            'actions' => json_encode($actions, JSON_THROW_ON_ERROR),
            'valid_from' => $from,
            'valid_to' => $to,
            'active' => self::flag($f['active']),
        ];
    }

    private static function priceRows(int $listId): array {
        $stmt = Db::get()->prepare('SELECT pp.product_id, pp.variant_id, pp.min_qty, pp.price, pp.compare_price, COALESCE(v.sku, p.sku) AS sku
            FROM price_list_prices pp JOIN products p ON p.id = pp.product_id LEFT JOIN variants v ON v.id = pp.variant_id
            WHERE pp.price_list_id = :id ORDER BY p.sku, v.sku, pp.min_qty, pp.id');
        $stmt->execute([':id' => $listId]);
        return array_map(static fn(array $r): array => ['sku' => (string)$r['sku'], 'product_id' => (int)$r['product_id'],
            'variant_id' => $r['variant_id'] === null ? null : (int)$r['variant_id'], 'min_qty' => (int)$r['min_qty'], 'price' => (int)$r['price'],
            'compare_price' => $r['compare_price'] === null ? null : (int)$r['compare_price']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Product (or, when allowed, variant) of the store's catalog with this SKU. @throws DomainException */
    private static function skuTarget(string $sku, bool $variants, string $label): array {
        $sku = trim($sku);
        if ($sku === '') self::fail(I18n::format('admin.msg.promo.sku_required', ['label' => $label]));
        $product = CatalogScope::rows('SELECT id FROM products WHERE sku = :sku AND store_id = :catalog', [':sku' => $sku])[0] ?? null;
        if ($product) return ['product_id' => (int)$product['id']];
        if ($variants) {
            $variant = CatalogScope::rows('SELECT v.id, v.product_id FROM variants v JOIN products p ON p.id = v.product_id WHERE v.sku = :sku AND p.store_id = :catalog', [':sku' => $sku])[0] ?? null;
            if ($variant) return ['product_id' => (int)$variant['product_id'], 'variant_id' => (int)$variant['id']];
        }
        self::fail(I18n::format($variants ? 'admin.msg.sku_not_item' : 'admin.msg.promo.sku_not_product', ['label' => $label, 'sku' => $sku]));
    }

    /** @throws DomainException */
    private static function productIds(string $skus): array {
        $ids = [];
        foreach (preg_split('/[\s,;]+/', trim($skus)) as $sku) $ids[] = self::skuTarget($sku, false, I18n::t('admin.msg.promo.label_products'))['product_id'];
        return array_values(array_unique($ids));
    }

    private static function skuOf(int $productId, ?int $variantId): string {
        $stmt = Db::get()->prepare($variantId === null ? 'SELECT sku FROM products WHERE id = :id' : 'SELECT sku FROM variants WHERE id = :id');
        $stmt->execute([':id' => $variantId ?? $productId]);
        return (string)($stmt->fetchColumn() ?: "#{$productId}");
    }

    /** The ids, all of which must be rows of the store's catalog in $table. @throws DomainException */
    private static function owned(string $table, array $ids, string $label): array {
        $found = array_map('intval', array_column(self::inIds("SELECT id FROM {$table} WHERE store_id = :catalog", $ids, ':catalog', StoreContext::current()->catalogId()), 'id'));
        foreach ($ids as $id) if (!in_array($id, $found, true)) self::fail(I18n::format('admin.msg.promo.catalog_foreign', ['label' => $label, 'id' => $id]));
        return $ids;
    }

    /** @throws DomainException */
    private static function ownedGroups(array $ids): array {
        $ids = array_map(static fn(mixed $id): int => ctype_digit((string)$id) ? (int)$id : 0, $ids);
        $found = array_map('intval', array_column(self::inIds('SELECT id FROM customer_groups WHERE store_id = :store', $ids, ':store', StoreContext::current()->id()), 'id'));
        foreach ($ids as $id) if (!in_array($id, $found, true)) self::fail(I18n::format('admin.msg.promo.group_foreign', ['id' => $id]));
        return $ids;
    }

    private static function inIds(string $sql, array $ids, string $scopeParam, string $scope): array {
        $params = [$scopeParam => $scope];
        foreach (array_values($ids) as $i => $id) $params[":id{$i}"] = $id;
        $stmt = Db::get()->prepare($sql . ' AND id IN (' . implode(', ', array_slice(array_keys($params), 1)) . ')');
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Rows of a query bound to :store (the active store id). */
    private static function storeRows(string $sql, array $params = []): array {
        $stmt = Db::get()->prepare($sql);
        $stmt->execute($params + [':store' => StoreContext::current()->id()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** UTC validity window from datetime-local values; a date alone means the start (from) or the end (to) of that day. @throws DomainException */
    private static function validity(string $from, string $to): array {
        $parse = static function(string $value, bool $end, string $label): ?string {
            $value = trim($value);
            if ($value === '') return null;
            foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
                $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));
                if ($date === false || $date->format($format) !== $value) continue;
                return $format === 'Y-m-d' && $end ? $date->format('Y-m-d 23:59:59') : $date->format('Y-m-d H:i:s');
            }
            self::fail(I18n::format('admin.msg.promo.date_invalid', ['label' => $label]));
        };
        $start = $parse($from, false, I18n::t('admin.msg.promo.label_valid_from'));
        $finish = $parse($to, true, I18n::t('admin.msg.promo.label_valid_to'));
        if ($start !== null && $finish !== null && $finish < $start) self::fail(I18n::t('admin.msg.promo.validity_order'));
        return [$start, $finish];
    }

    private static function formDate(?string $value): string {
        return $value === null || $value === '' ? '' : str_replace(' ', 'T', substr($value, 0, 19));
    }

    /** "12,5" or "12.5" percent → 1250 basis points (0.01 % to 100 %). @throws DomainException */
    private static function percentBps(string $value): int {
        if (!preg_match('/^(\d{1,3})(?:[.,](\d{1,2}))?$/', trim($value), $m)) self::fail(I18n::t('admin.msg.promo.percent'));
        $bps = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
        if ($bps < 1 || $bps > 10000) self::fail(I18n::t('admin.msg.promo.percent'));
        return $bps;
    }

    private static function bpsToPercent(int $bps): string {
        $fraction = rtrim(sprintf('%02d', $bps % 100), '0');
        return intdiv($bps, 100) . ($fraction === '' ? '' : ',' . $fraction);
    }

    /** @throws DomainException */
    private static function int(string $value, int $min, int $max, string $message): int {
        $value = trim($value);
        if (!preg_match('/^-?\d{1,10}$/', $value) || (int)$value < $min || (int)$value > $max) self::fail($message);
        return (int)$value;
    }

    /** @throws DomainException */
    private static function code(string $code, string $name): string {
        $code = Str::slug(trim($code) !== '' ? $code : $name);
        if ($code === '' || strlen($code) > self::MAX_TEXT) self::fail(I18n::format('admin.msg.code_invalid', ['max' => self::MAX_TEXT]));
        return $code;
    }

    private static function flag(string $value): int {
        return $value !== '' && $value !== '0' ? 1 : 0;
    }

    private static function json(mixed $value): array {
        $decoded = is_array($value) ? $value : json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Guest product and category listings are cached with resolved prices; mark every locale stale, in every store sharing the catalog too. */
    private static function invalidatePrices(): void {
        foreach (ProductEditor::locales() as $locale) {
            Cache::invalidatePrefix(Cache::localePath($locale, 'product'));
            Cache::invalidatePrefix(Cache::localePath($locale, 'cat'));
        }
        Cache::catalogChanged();
    }

    /** @throws DomainException */
    private static function fail(string $message): never {
        throw new DomainException($message);
    }

    private static function result(bool $ok, int $id, string $message): array {
        return ['ok' => $ok, 'id' => $id, 'message' => $message];
    }

    /** The posted fields as strings (missing = ''), or null when the post or a field is not scalar. */
    private static function scalars(mixed $post, array $names): ?array {
        if (!is_array($post)) return null;
        $fields = [];
        foreach ($names as $name) {
            $value = $post[$name] ?? '';
            if (!is_scalar($value)) return null;
            $fields[$name] = (string)$value;
        }
        return $fields;
    }

    /** Posted multi-select id lists (missing = []), or null when one is not a list of digit strings. */
    private static function idLists(mixed $post, array $names): ?array {
        if (!is_array($post)) return null;
        $lists = [];
        foreach ($names as $name) {
            $values = $post[$name] ?? [];
            if (!is_array($values)) return null;
            $ids = [];
            foreach ($values as $value) {
                if (!is_scalar($value) || !ctype_digit((string)$value) || (int)$value < 1) return null;
                $ids[(int)$value] = (int)$value;
            }
            $lists[$name] = array_values($ids);
        }
        return $lists;
    }

    private static function validText(string $value, bool $required): bool {
        return ($value !== '' || !$required) && mb_strlen($value) <= self::MAX_TEXT && !preg_match('/[\x00-\x1F\x7F]/', $value);
    }
}
