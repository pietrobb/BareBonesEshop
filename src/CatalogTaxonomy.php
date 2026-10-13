<?php
/**
 * Admin CRUD for the categories and brands of the active store's catalog (catalog_scope).
 * Categories: ASCII slug unique among siblings, parent from the same catalog and never the category itself or one of its
 * descendants; a move or slug change rewrites path/depth of the whole subtree. Categories with subcategories, products or
 * promotion conditions and brands with products or promotion conditions cannot be deleted.
 * Every method returns ['ok' => bool, 'id' => int, 'message' => flash text].
 */
final class CatalogTaxonomy {
    private const MAX_TEXT = 191;

    /** Categories of the active catalog in tree order with child and product counts (primary or extra category). */
    public static function categories(): array {
        $children = self::counts('SELECT parent_id AS id, COUNT(*) AS n FROM categories WHERE store_id = :catalog AND parent_id IS NOT NULL GROUP BY parent_id');
        $primary = self::counts('SELECT category_id AS id, COUNT(*) AS n FROM products WHERE store_id = :catalog GROUP BY category_id');
        $extra = self::counts('SELECT pc.category_id AS id, COUNT(*) AS n FROM product_categories pc JOIN products p ON p.id = pc.product_id
            WHERE p.store_id = :catalog AND pc.category_id != p.category_id GROUP BY pc.category_id');
        return array_map(static function(array $row) use ($children, $primary, $extra): array {
            $id = (int)$row['id'];
            return $row + ['child_count' => $children[$id] ?? 0, 'product_total' => ($primary[$id] ?? 0) + ($extra[$id] ?? 0)];
        }, CatalogScope::rows('SELECT id, slug, name, parent_id, path, depth, sort_order, translations FROM categories WHERE store_id = :catalog ORDER BY path, sort_order, name'));
    }

    public static function saveCategory(int $id, mixed $post): array {
        $old = $id > 0 ? Categories::byId($id) : null;
        if ($id > 0 && !$old) return self::result(false, 0, I18n::t('admin.msg.category.missing'));
        $fields = self::scalars($post, ['name', 'slug', 'parent_id', 'sort_order']);
        if ($fields === null) return self::result(false, $id, I18n::t('admin.msg.invalid_form'));
        $name = trim($fields['name']);
        if (!self::validText($name, true)) return self::result(false, $id, I18n::format('admin.msg.category.name', ['max' => self::MAX_TEXT]));
        $slug = Str::slug(trim($fields['slug']) !== '' ? $fields['slug'] : $name);
        if ($slug === '') return self::result(false, $id, I18n::t('admin.msg.category.slug_empty'));
        $parent = null;
        $parentRaw = trim($fields['parent_id']);
        if ($parentRaw !== '' && $parentRaw !== '0') {
            $parent = ctype_digit($parentRaw) ? Categories::byId((int)$parentRaw) : null;
            if (!$parent) return self::result(false, $id, I18n::t('admin.msg.category.parent_foreign'));
            if ($old && isset(self::subtree($id)[(int)$parent['id']])) return self::result(false, $id, I18n::t('admin.msg.category.parent_cycle'));
        }
        $sort = trim($fields['sort_order']) ?: '0';
        if (!preg_match('/^-?\d{1,6}$/', $sort)) return self::result(false, $id, I18n::t('admin.msg.category.sort_int'));
        if (self::siblingSlugTaken($slug, $parent ? (int)$parent['id'] : null, $id)) return self::result(false, $id, I18n::format('admin.msg.category.slug_taken', ['slug' => $slug]));
        $translations = self::translations((string)($old['translations'] ?? '{}'), is_array($post) ? ($post['tr'] ?? []) : []);
        if (is_string($translations)) return self::result(false, $id, $translations);
        $path = ($parent ? $parent['path'] . '/' : '') . $slug;
        $depth = $parent ? (int)$parent['depth'] + 1 : 0;
        $moves = $old ? self::subtreeMoves($id, $path, $depth) : [];
        foreach ([$path, ...array_column($moves, 'path')] as $newPath) {
            if (strlen($newPath) > self::MAX_TEXT) return self::result(false, $id, I18n::format('admin.msg.category.path_long', ['max' => self::MAX_TEXT]));
        }
        $row = ['slug' => $slug, 'name' => $name, 'parent_id' => $parent ? (int)$parent['id'] : null, 'path' => $path, 'depth' => $depth,
            'sort_order' => (int)$sort, 'translations' => $translations === [] ? '{}' : json_encode($translations, JSON_UNESCAPED_UNICODE)];
        $db = Db::get();
        $db->beginTransaction();
        try {
            $params = array_combine(array_map(static fn(string $k): string => ":{$k}", array_keys($row)), $row) + [':catalog' => StoreContext::current()->catalogId()];
            if ($old) {
                $db->prepare('UPDATE categories SET slug = :slug, name = :name, parent_id = :parent_id, path = :path, depth = :depth, sort_order = :sort_order,
                    translations = :translations WHERE id = :id AND store_id = :catalog')->execute($params + [':id' => $id]);
                $move = $db->prepare('UPDATE categories SET path = :path, depth = :depth WHERE id = :id AND store_id = :catalog');
                foreach ($moves as $childId => $child) $move->execute([':path' => $child['path'], ':depth' => $child['depth'], ':id' => $childId, ':catalog' => $params[':catalog']]);
            } else {
                $db->prepare("INSERT INTO categories (store_id, slug, name, parent_id, path, depth, sort_order, product_count, translations, meta)
                    VALUES (:catalog, :slug, :name, :parent_id, :path, :depth, :sort_order, 0, :translations, '{}')")->execute($params);
                $id = (int)$db->lastInsertId();
            }
            AuditLog::log('category', $id, $old ?: null, $row + ['moved_descendants' => count($moves)], 'Admin category');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            if ($e instanceof PDOException && str_starts_with((string)$e->getCode(), '23')) return self::result(false, $old ? $id : 0, I18n::format('admin.msg.category.slug_taken', ['slug' => $slug]));
            throw $e;
        }
        if ($old && ($old['path'] !== $path)) self::recount([...self::ancestors((string)$old['path']), ...self::ancestors($path)]);
        self::invalidateTree();
        return self::result(true, $id, $old ? ($moves ? I18n::format('admin.msg.category.saved_moved', ['count' => count($moves)]) : I18n::t('admin.msg.category.saved')) : I18n::t('admin.msg.category.created'));
    }

    public static function deleteCategory(int $id): array {
        $category = $id > 0 ? Categories::byId($id) : null;
        if (!$category) return self::result(false, 0, I18n::t('admin.msg.category.missing'));
        $blocked = self::firstHit([
            'admin.msg.category.has_children' => ['SELECT 1 FROM categories WHERE parent_id = :id', [':id' => $id]],
            'admin.msg.category.has_products' => ['SELECT 1 FROM products WHERE category_id = :id', [':id' => $id]],
            'admin.msg.category.extra_products' => ['SELECT 1 FROM product_categories WHERE category_id = :id', [':id' => $id]],
        ]) ?? self::promotionUses('category_ids', $id);
        if ($blocked !== null) return self::result(false, $id, I18n::format('admin.msg.category.delete_blocked', ['name' => $category['name'], 'reason' => $blocked]));
        $db = Db::get();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM category_attributes WHERE category_id = :id')->execute([':id' => $id]);
            $db->prepare('DELETE FROM categories WHERE id = :id AND store_id = :catalog')->execute([':id' => $id, ':catalog' => StoreContext::current()->catalogId()]);
            AuditLog::log('category', $id, $category, null, 'Admin category delete');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        self::invalidateTree();
        return self::result(true, 0, I18n::t('admin.msg.category.deleted'));
    }

    /** Brands of the active catalog with the number of products that use them. */
    public static function brands(): array {
        $used = self::counts('SELECT brand_id AS id, COUNT(*) AS n FROM products WHERE store_id = :catalog AND brand_id IS NOT NULL GROUP BY brand_id');
        return array_map(static fn(array $row): array => $row + ['product_total' => $used[(int)$row['id']] ?? 0],
            CatalogScope::rows('SELECT id, code, name, manufacturer_name, active FROM brands WHERE store_id = :catalog ORDER BY name, code'));
    }

    public static function brand(int $id): ?array {
        return CatalogScope::rows('SELECT id, code, name, manufacturer_name, active FROM brands WHERE id = :id AND store_id = :catalog', [':id' => $id])[0] ?? null;
    }

    public static function saveBrand(int $id, mixed $post): array {
        $old = $id > 0 ? self::brand($id) : null;
        if ($id > 0 && !$old) return self::result(false, 0, I18n::t('admin.msg.brand.missing'));
        $fields = self::scalars($post, ['code', 'name', 'manufacturer_name', 'active']);
        if ($fields === null) return self::result(false, $id, I18n::t('admin.msg.invalid_form'));
        $name = trim($fields['name']);
        $manufacturer = trim($fields['manufacturer_name']);
        if (!self::validText($name, true) || !self::validText($manufacturer, false)) return self::result(false, $id, I18n::format('admin.msg.brand.name', ['max' => self::MAX_TEXT]));
        $code = Str::slug(trim($fields['code']) !== '' ? $fields['code'] : $name);
        if ($code === '' || strlen($code) > self::MAX_TEXT) return self::result(false, $id, I18n::format('admin.msg.brand.code', ['max' => self::MAX_TEXT]));
        if (CatalogScope::rows('SELECT 1 AS taken FROM brands WHERE store_id = :catalog AND code = :code AND id != :id', [':code' => $code, ':id' => $id ?: -1])) {
            return self::result(false, $id, I18n::format('admin.msg.brand.code_taken', ['code' => $code]));
        }
        $row = ['code' => $code, 'name' => $name, 'manufacturer_name' => $manufacturer === '' ? null : $manufacturer, 'active' => $fields['active'] !== '' && $fields['active'] !== '0' ? 1 : 0];
        $params = [':code' => $row['code'], ':name' => $row['name'], ':manufacturer' => $row['manufacturer_name'], ':active' => $row['active'], ':catalog' => StoreContext::current()->catalogId()];
        $db = Db::get();
        $db->beginTransaction();
        try {
            if ($old) {
                $db->prepare('UPDATE brands SET code = :code, name = :name, manufacturer_name = :manufacturer, active = :active WHERE id = :id AND store_id = :catalog')->execute($params + [':id' => $id]);
            } else {
                $db->prepare('INSERT INTO brands (store_id, code, name, manufacturer_name, active) VALUES (:catalog, :code, :name, :manufacturer, :active)')->execute($params);
                $id = (int)$db->lastInsertId();
            }
            AuditLog::log('brand', $id, $old, $row, 'Admin brand');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            if ($e instanceof PDOException && str_starts_with((string)$e->getCode(), '23')) return self::result(false, $old ? $id : 0, I18n::format('admin.msg.brand.code_taken', ['code' => $code]));
            throw $e;
        }
        return self::result(true, $id, I18n::t($old ? 'admin.msg.brand.saved' : 'admin.msg.brand.created'));
    }

    public static function deleteBrand(int $id): array {
        $brand = $id > 0 ? self::brand($id) : null;
        if (!$brand) return self::result(false, 0, I18n::t('admin.msg.brand.missing'));
        $blocked = self::firstHit(['admin.msg.brand.has_products' => ['SELECT 1 FROM products WHERE brand_id = :id', [':id' => $id]]])
            ?? self::promotionUses('brand_ids', $id);
        if ($blocked !== null) return self::result(false, $id, I18n::format('admin.msg.brand.delete_blocked', ['name' => $brand['name'], 'reason' => $blocked]));
        $db = Db::get();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM brands WHERE id = :id AND store_id = :catalog')->execute([':id' => $id, ':catalog' => StoreContext::current()->catalogId()]);
            AuditLog::log('brand', $id, $brand, null, 'Admin brand delete');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return self::result(true, 0, I18n::t('admin.msg.brand.deleted'));
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

    private static function validText(string $value, bool $required): bool {
        return ($value !== '' || !$required) && mb_strlen($value) <= self::MAX_TEXT && !preg_match('/[\x00-\x1F\x7F]/', $value);
    }

    /** id => n of a grouped count query bound to :catalog. */
    private static function counts(string $sql): array {
        return array_map('intval', array_column(CatalogScope::rows($sql), 'n', 'id'));
    }

    private static function siblingSlugTaken(string $slug, ?int $parentId, int $id): bool {
        return (bool)CatalogScope::rows('SELECT 1 AS taken FROM categories WHERE store_id = :catalog AND slug = :slug AND '
            . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = :parent') . ' AND id != :id',
            [':slug' => $slug, ':id' => $id ?: -1] + ($parentId === null ? [] : [':parent' => $parentId]));
    }

    /** id => row of the category and all its descendants, following parent_id (not the stored paths). */
    private static function subtree(int $id): array {
        $byParent = [];
        foreach (CatalogScope::rows('SELECT id, parent_id, slug FROM categories WHERE store_id = :catalog AND parent_id IS NOT NULL') as $row) {
            $byParent[(int)$row['parent_id']][] = $row;
        }
        $found = [$id => ['id' => $id]];
        for ($queue = [$id]; $queue;) {
            foreach ($byParent[array_shift($queue)] ?? [] as $child) {
                if (isset($found[(int)$child['id']])) continue;
                $found[(int)$child['id']] = $child;
                $queue[] = (int)$child['id'];
            }
        }
        return $found;
    }

    /** New path/depth of every descendant when the category gets $path/$depth. */
    private static function subtreeMoves(int $id, string $path, int $depth): array {
        $tree = self::subtree($id);
        $placed = [$id => ['path' => $path, 'depth' => $depth]];
        foreach (array_slice($tree, 1, null, true) as $childId => $child) {
            $parent = $placed[(int)$child['parent_id']];
            $placed[$childId] = ['path' => $parent['path'] . '/' . $child['slug'], 'depth' => $parent['depth'] + 1];
        }
        unset($placed[$id]);
        return $placed;
    }

    /** Ids of the categories on a materialized path (the category itself included). */
    private static function ancestors(string $path): array {
        $paths = [];
        foreach (explode('/', $path) as $segment) $paths[] = $paths ? end($paths) . '/' . $segment : $segment;
        $placeholders = implode(', ', array_map(static fn(int $i): string => ":p{$i}", array_keys($paths)));
        $params = array_combine(array_map(static fn(int $i): string => ":p{$i}", array_keys($paths)), $paths);
        return array_map('intval', array_column(CatalogScope::rows("SELECT id FROM categories WHERE store_id = :catalog AND path IN ({$placeholders})", $params), 'id'));
    }

    /** The storefront serves categories.json for an hour; mark every locale's tree of this store stale. */
    private static function invalidateTree(): void {
        foreach (ProductEditor::locales() as $locale) Cache::invalidate(Cache::localePath($locale, 'categories.json'));
    }

    private static function recount(array $ids): void {
        if ($ids) Import::recountCategories(Db::get(), array_values(array_unique($ids)));
    }

    /** Translated reason (an i18n key) of the first query that finds a row, or null. */
    private static function firstHit(array $checks): ?string {
        foreach ($checks as $reason => [$sql, $params]) {
            $stmt = Db::get()->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);
            if ($stmt->fetchColumn()) return I18n::t($reason);
        }
        return null;
    }

    /** Codes of promotions whose conditions[$key] lists $id (category and brand ids are global, so all stores are checked). */
    private static function promotionUses(string $key, int $id): ?string {
        $codes = [];
        foreach (Db::get()->query('SELECT code, conditions FROM promotions')->fetchAll(PDO::FETCH_ASSOC) as $promotion) {
            $conditions = json_decode((string)$promotion['conditions'], true);
            if (is_array($conditions[$key] ?? null) && in_array($id, array_map('intval', $conditions[$key]), true)) $codes[] = $promotion['code'];
        }
        return $codes ? I18n::format('admin.msg.promo_uses', ['codes' => implode(', ', $codes)]) : null;
    }

    /** Merge posted tr[locale][name] into the stored translations; locales outside the catalog are refused, other keys kept. */
    private static function translations(string $stored, mixed $posted): array|string {
        $translations = json_decode($stored, true);
        if (!is_array($translations)) $translations = [];
        if (!is_array($posted)) return I18n::t('admin.msg.translations_invalid');
        $locales = ProductEditor::locales();
        foreach ($posted as $locale => $fields) {
            $locale = (string)$locale;
            if (!in_array($locale, $locales, true) || !is_array($fields) || !is_scalar($fields['name'] ?? '')) return I18n::format('admin.msg.locale_refused', ['locale' => $locale]);
            $name = trim((string)($fields['name'] ?? ''));
            if (!self::validText($name, false)) return I18n::format('admin.msg.category.tr_name_long', ['locale' => $locale, 'max' => self::MAX_TEXT]);
            $entry = is_array($translations[$locale] ?? null) ? $translations[$locale] : [];
            if ($name === '') unset($entry['name']); else $entry['name'] = $name;
            if ($entry) $translations[$locale] = $entry; else unset($translations[$locale]);
        }
        return $translations;
    }
}
