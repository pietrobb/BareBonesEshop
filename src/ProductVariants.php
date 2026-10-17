<?php
/**
 * Admin editor for the variants of one product in the active catalog. Rows are validated as a whole before anything
 * is written; stock is booked through the warehouse ledger (legacy variants.stock + a movement without a default
 * warehouse), never written directly with the row. A variant that orders, bundles, reservations or the ledger refer
 * to keeps its SKU and cannot be deleted — set its availability to discontinued instead.
 */
final class ProductVariants {
    public const POLICIES = ['inherit', Inventory::DENY, Inventory::BACKORDER, Inventory::PREORDER];
    private const MAX_VARIANTS = 200;

    /** Validate and save a posted variant table; returns ['found' => product exists, 'ok' => bool, 'message' => flash text]. */
    public static function submit(int $productId, mixed $posted): array {
        $product = $productId > 0 ? Products::byId($productId) : null;
        if (!$product) return ['found' => false, 'ok' => false, 'message' => I18n::t('admin.product.not_found')];
        $validated = self::validate($productId, $posted);
        if (is_string($validated)) return ['found' => true, 'ok' => false, 'message' => I18n::format('admin.msg.variant.not_saved', ['error' => $validated])];
        $stockErrors = self::save($productId, $validated, (string)$product['product_type']);
        if (!$stockErrors) return ['found' => true, 'ok' => true, 'message' => I18n::t('admin.msg.variant.saved')];
        $failed = array_map(static fn($sku, $msg): string => "{$sku} ({$msg})", array_keys($stockErrors), $stockErrors);
        return ['found' => true, 'ok' => false, 'message' => I18n::format('admin.msg.variant.stock_failed', ['list' => implode('; ', $failed)])];
    }

    /** Variant availability values offered by the editor. */
    public static function availabilities(): array {
        return VariantAvailability::statuses();
    }

    /**
     * Lowest base price (cents) of the product that keeps every variant priced by a delta at zero or more: a price
     * change (editor, CSV import, bulk change) below it is refused or clamped, so no variant price turns negative.
     */
    public static function minBasePrice(int $productId): int {
        $stmt = Db::get()->prepare('SELECT MIN(price_delta) FROM variants WHERE product_id = :id AND price_absolute IS NULL');
        $stmt->execute([':id' => $productId]);
        return max(0, -(int)$stmt->fetchColumn());
    }

    /** The empty "add a variant" row of the editor. */
    public static function blank(int $sortOrder): array {
        return ['id' => 'new', 'sku' => '', 'name' => '', 'attrs_text' => '', 'price_absolute' => null, 'price_delta' => 0, 'stock' => 0,
            'availability' => VariantAvailability::ACTIVE, 'inventory_policy' => 'inherit', 'sort_order' => $sortOrder];
    }

    /** Variants of a product of the active catalog with the stock the editor shows (ledger total when the SKU is ledgered). */
    public static function forEditor(int $productId): array {
        return array_map(static function(array $row): array {
            $row['stock'] = self::ledgered((string)$row['sku']) ? Warehouse::totalAvailable((string)$row['sku']) : (int)$row['stock'];
            $row['attrs_text'] = self::attrsText((string)($row['attrs'] ?? ''));
            return $row;
        }, Products::variants($productId));
    }

    /** "key=value" lines of a variant's attrs JSON. */
    public static function attrsText(string $json): string {
        $attrs = json_decode($json, true);
        if (!is_array($attrs)) return '';
        return implode("\n", array_map(static fn($k, $v): string => $k . '=' . (is_scalar($v) ? (string)$v : json_encode($v)), array_keys($attrs), $attrs));
    }

    /**
     * Validate posted variants[key][field] rows for a product; returns ['rows' => [...normalized], 'delete' => [ids]] or an error.
     * A row with key 'new' and an empty SKU is the unused "add" row.
     */
    public static function validate(int $productId, mixed $posted): array|string {
        $product = Products::byId($productId);
        if (!$product) return I18n::t('admin.product.not_found');
        if (!is_array($posted)) return I18n::t('admin.msg.variant.invalid');
        $existing = array_column(Products::variants($productId), null, 'id');
        $rows = [];
        $delete = [];
        $skus = [];
        foreach ($posted as $key => $fields) {
            if (!is_array($fields) || array_filter($fields, static fn($v): bool => !is_scalar($v))) return I18n::t('admin.msg.variant.invalid');
            $id = $key === 'new' ? 0 : Security::int($key);
            if ($key !== 'new' && !isset($existing[$id])) return I18n::t('admin.msg.variant.foreign');
            $old = $existing[$id] ?? null;
            $sku = trim((string)($fields['sku'] ?? ''));
            if ($old === null && $sku === '') continue;
            if (!empty($fields['delete'])) {
                if ($old === null) continue;
                $blocked = self::referencedBy((int)$old['id'], (string)$old['sku'], true);
                if ($blocked !== null) return I18n::format('admin.msg.variant.delete_blocked', ['sku' => $old['sku'], 'reason' => $blocked]);
                $delete[] = (int)$old['id'];
                continue;
            }
            if ($sku === '' || mb_strlen($sku) > 100 || preg_match('/[\x00-\x1F\x7F]/', $sku)) return I18n::t('admin.msg.variant.sku_required');
            if (isset($skus[$sku])) return I18n::format('admin.msg.variant.sku_twice', ['sku' => $sku]);
            $skus[$sku] = true;
            if ($old !== null && $sku !== (string)$old['sku']) {
                $blocked = self::referencedBy((int)$old['id'], (string)$old['sku'], false);
                if ($blocked !== null) return I18n::format('admin.msg.variant.sku_change_blocked', ['sku' => $old['sku'], 'reason' => $blocked]);
            }
            if (SkuGuard::taken($sku, 0, $id)) return I18n::format('admin.msg.variant.sku_taken', ['sku' => $sku]);
            $name = trim((string)($fields['name'] ?? ''));
            if (mb_strlen($name) > 255) return I18n::format('admin.msg.variant.name_long', ['sku' => $sku]);
            $attrs = self::parseAttrs((string)($fields['attrs'] ?? ''));
            if (is_string($attrs)) return I18n::format('admin.msg.variant.row_error', ['sku' => $sku, 'error' => $attrs]);
            $absolute = trim((string)($fields['price_absolute'] ?? ''));
            $delta = trim((string)($fields['price_delta'] ?? '0')) ?: '0';
            $stock = trim((string)($fields['stock'] ?? '0')) ?: '0';
            $sort = trim((string)($fields['sort_order'] ?? '0')) ?: '0';
            if (($absolute !== '' && !ctype_digit($absolute)) || !preg_match('/^-?\d+$/', $delta) || !ctype_digit($stock) || !preg_match('/^-?\d+$/', $sort)) {
                return I18n::format('admin.msg.variant.row_error', ['sku' => $sku, 'error' => I18n::t('admin.msg.variant.numbers')]);
            }
            if ($absolute === '' && (int)$product['price'] + (int)$delta < 0) return I18n::format('admin.msg.variant.row_error', ['sku' => $sku, 'error' => I18n::t('admin.msg.variant.negative_price')]);
            $availability = (string)($fields['availability'] ?? VariantAvailability::ACTIVE);
            $policy = (string)($fields['inventory_policy'] ?? 'inherit');
            if (!in_array($availability, VariantAvailability::statuses(), true) || !in_array($policy, self::POLICIES, true)) return I18n::format('admin.msg.variant.row_error', ['sku' => $sku, 'error' => I18n::t('admin.msg.variant.availability')]);
            $rows[] = ['id' => $id, 'sku' => $sku, 'name' => $name, 'attrs' => $attrs ? json_encode($attrs, JSON_UNESCAPED_UNICODE) : '{}',
                'price_absolute' => $absolute === '' ? null : (int)$absolute, 'price_delta' => (int)$delta, 'stock' => (int)$stock,
                'availability' => $availability, 'inventory_policy' => $policy, 'sort_order' => (int)$sort];
        }
        if (count($rows) > self::MAX_VARIANTS) return I18n::format('admin.msg.variant.max', ['max' => self::MAX_VARIANTS]);
        return ['rows' => $rows, 'delete' => $delete];
    }

    /** Write validated rows (without stock) in one transaction, then book stock changes; returns stock errors per SKU. */
    public static function save(int $productId, array $validated, string $productType): array {
        $db = Db::get();
        $before = array_column(self::forEditor($productId), null, 'id');
        $db->beginTransaction();
        try {
            $deletePrices = $db->prepare('DELETE FROM price_list_prices WHERE variant_id = :id');
            $deleteVariant = $db->prepare('DELETE FROM variants WHERE id = :id AND product_id = :product');
            foreach ($validated['delete'] as $id) {
                $deletePrices->execute([':id' => $id]);
                $deleteVariant->execute([':id' => $id, ':product' => $productId]);
            }
            $update = $db->prepare('UPDATE variants SET sku = :sku, name = :name, attrs = :attrs, price_absolute = :price_absolute, price_delta = :price_delta,
                availability = :availability, inventory_policy = :inventory_policy, sort_order = :sort_order WHERE id = :id AND product_id = :product');
            $insert = $db->prepare('INSERT INTO variants (product_id, sku, name, attrs, price_absolute, price_delta, availability, inventory_policy, sort_order, stock)
                VALUES (:product, :sku, :name, :attrs, :price_absolute, :price_delta, :availability, :inventory_policy, :sort_order, 0)');
            foreach ($validated['rows'] as &$row) {
                $values = array_diff_key($row, ['id' => 1, 'stock' => 1]);
                $params = [':product' => $productId] + array_combine(array_map(static fn(string $k): string => ":{$k}", array_keys($values)), $values);
                if ($row['id'] > 0) $update->execute($params + [':id' => $row['id']]);
                else {
                    $insert->execute($params);
                    $row['id'] = (int)$db->lastInsertId();
                }
            }
            unset($row);
            AuditLog::log('product', $productId, ['variants' => array_values($before)], $validated, 'Admin variants');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        $errors = [];
        if (VariantAvailability::isDigitalType($productType)) return $errors;
        foreach ($validated['rows'] as $row) {
            if ((int)($before[$row['id']]['stock'] ?? 0) === $row['stock'] && isset($before[$row['id']])) continue;
            try {
                self::setStock($row['id'], $row['sku'], $row['stock']);
            } catch (RuntimeException | InvalidArgumentException $e) {
                $errors[$row['sku']] = $e->getMessage();
            }
        }
        return $errors;
    }

    /** Book a variant's available stock: through the default warehouse when there is one, else the legacy column + movement. */
    private static function setStock(int $variantId, string $sku, int $available): void {
        if (Warehouse::setAvailable($sku, $available, 'manual_adjustment', 'admin_variant')) {
            Warehouse::syncProductStock([$sku]);
            return;
        }
        $db = Db::get();
        $current = $db->prepare('SELECT stock FROM variants WHERE id = :id');
        $current->execute([':id' => $variantId]);
        $delta = $available - (int)$current->fetchColumn();
        if ($delta === 0) return;
        $db->prepare('UPDATE variants SET stock = :stock WHERE id = :id')->execute([':stock' => $available, ':id' => $variantId]);
        Inventory::movement(null, $sku, 'manual_adjustment', $delta, 0, $available, 0, 'admin_variant', null, null, ['variant_id' => $variantId]);
    }

    private static function ledgered(string $sku): bool {
        $stmt = Db::get()->prepare('SELECT 1 FROM warehouse_stock WHERE sku = :sku AND store_id = :store');
        $stmt->execute([':sku' => $sku, ':store' => StoreContext::current()->catalogId()]);
        return (bool)$stmt->fetchColumn();
    }

    /** Why a variant's identity is pinned (orders, bundles, reservations; for deletion also ledger stock), or null. */
    private static function referencedBy(int $variantId, string $sku, bool $deleting): ?string {
        $db = Db::get();
        $checks = [
            'admin.msg.variant.in_orders' => ['SELECT 1 FROM order_items WHERE variant_id = :id', [':id' => $variantId]],
            'admin.msg.variant.in_bundle' => ['SELECT 1 FROM bundle_components WHERE component_variant_id = :id', [':id' => $variantId]],
            'admin.msg.variant.reserved' => ['SELECT 1 FROM warehouse_stock WHERE sku = :sku AND reserved > 0', [':sku' => $sku]],
            $deleting ? 'admin.msg.variant.ledger_stock' : 'admin.msg.variant.ledger_records' =>
                [$deleting ? 'SELECT 1 FROM warehouse_stock WHERE sku = :sku AND qty > 0' : 'SELECT 1 FROM warehouse_stock WHERE sku = :sku', [':sku' => $sku]],
        ];
        foreach ($checks as $reason => [$sql, $params]) {
            $stmt = $db->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);
            if ($stmt->fetchColumn()) return I18n::t($reason);
        }
        if ($deleting) {
            $legacy = $db->prepare('SELECT stock FROM variants WHERE id = :id');
            $legacy->execute([':id' => $variantId]);
            if ((int)$legacy->fetchColumn() > 0 && !self::ledgered($sku)) return I18n::t('admin.msg.variant.legacy_stock');
        }
        return null;
    }

    /** "key=value" lines into an attrs object (keys ≤ 60, values ≤ 255 characters). */
    private static function parseAttrs(string $text): array|string {
        $attrs = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (trim($line) === '') continue;
            $parts = explode('=', $line, 2);
            $key = trim($parts[0]);
            $value = trim($parts[1] ?? '');
            if ($key === '' || count($parts) !== 2 || mb_strlen($key) > 60 || mb_strlen($value) > 255) return I18n::t('admin.msg.variant.attrs');
            $attrs[$key] = $value;
        }
        return $attrs;
    }
}
