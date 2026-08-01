<?php
/**
 * BareBones Shop — Category Queries
 * Tree structure with materialized path.
 */
class Categories {
    /**
     * Full category tree
     */
    public static function tree(): array {
        $stmt = Db::get()->prepare("SELECT * FROM categories WHERE store_id = :store ORDER BY path, sort_order, name");
        $stmt->execute([':store' => StoreContext::current()->catalogId()]);
        $rows = $stmt->fetchAll();

        return self::buildTree($rows);
    }

    /**
     * Get category by slug
     */
    public static function bySlug(string $slug): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM categories WHERE slug = :slug AND store_id = :store");
        $stmt->execute([':slug' => $slug, ':store' => StoreContext::current()->catalogId()]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get category by ID
     */
    public static function byId(int $id): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM categories WHERE id = :id AND store_id = :store");
        $stmt->execute([':id' => $id, ':store' => StoreContext::current()->catalogId()]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get category by path (e.g., "oblecenie/tricka")
     */
    public static function byPath(string $path): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM categories WHERE path = :path AND store_id = :store");
        $stmt->execute([':path' => $path, ':store' => StoreContext::current()->catalogId()]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Direct children of a category
     */
    public static function children(int $parentId): array {
        $stmt = Db::get()->prepare("
            SELECT * FROM categories WHERE parent_id = :pid AND store_id = :store ORDER BY sort_order, name
        ");
        $stmt->execute([':pid' => $parentId, ':store' => StoreContext::current()->catalogId()]);
        return $stmt->fetchAll();
    }

    /**
     * Root categories (no parent)
     */
    public static function roots(): array {
        $stmt = Db::get()->prepare("SELECT * FROM categories WHERE parent_id IS NULL AND store_id = :store ORDER BY sort_order, name");
        $stmt->execute([':store' => StoreContext::current()->catalogId()]);
        return $stmt->fetchAll();
    }

    /**
     * Breadcrumb trail for a category
     */
    public static function breadcrumb(int $categoryId): array {
        $category = self::byId($categoryId);
        if (!$category) return [];

        $path = $category['path'];
        if (!$path) return [$category];

        // Build all ancestor paths and fetch in one query
        $segments = explode('/', $path);
        $paths = [];
        $current = '';
        foreach ($segments as $seg) {
            $current = $current ? $current . '/' . $seg : $seg;
            $paths[] = $current;
        }

        $ph = implode(',', array_fill(0, count($paths), '?'));
        $stmt = Db::get()->prepare("SELECT * FROM categories WHERE path IN ({$ph}) AND store_id = ? ORDER BY depth, path");
        $stmt->execute([...$paths, StoreContext::current()->catalogId()]);
        return $stmt->fetchAll();
    }

    /**
     * Featured categories (for homepage)
     * Returns root categories with product_count > 0
     */
    public static function featured(int $limit = 6): array {
        $stmt = Db::get()->prepare("
            SELECT * FROM categories
            WHERE parent_id IS NULL AND store_id = :store AND product_count > 0
            ORDER BY product_count DESC
            LIMIT :limit
        ");
        $stmt->execute([':store' => StoreContext::current()->catalogId(), ':limit' => $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get all descendant IDs (for subtree queries)
     */
    public static function descendantIds(int $categoryId): array {
        $category = self::byId($categoryId);
        if (!$category) return [$categoryId];

        $stmt = Db::get()->prepare("
            SELECT id FROM categories
            WHERE store_id = :store AND (path = :path OR path LIKE :pathPrefix)
        ");
        $stmt->execute([
            ':store'      => StoreContext::current()->catalogId(),
            ':path'       => $category['path'],
            ':pathPrefix' => $category['path'] . '/%',
        ]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Build hierarchical tree from flat rows
     */
    private static function buildTree(array $rows, ?int $parentId = null): array {
        $tree = [];
        foreach ($rows as $row) {
            if ($row['parent_id'] == $parentId) {
                $row['children'] = self::buildTree($rows, (int)$row['id']);
                $tree[] = $row;
            }
        }
        return $tree;
    }

    /**
     * Enrich category with computed fields
     */
    public static function enrich(array $category): array {
        $category['translations'] = json_decode($category['translations'] ?? '{}', true);
        $category['meta'] = json_decode($category['meta'] ?? '{}', true);
        $category['url'] = '/category/' . $category['path'];
        return $category;
    }
}
