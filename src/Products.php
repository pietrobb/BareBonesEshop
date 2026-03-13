<?php
/**
 * BareBones Shop — Product Queries
 * All product-related database operations.
 */
class Products {
    /**
     * Get product by slug (with locale-aware slug lookup)
     */
    public static function bySlug(string $slug): ?array {
        $db = Db::get();

        // Direct slug match first
        $stmt = $db->prepare("SELECT * FROM products WHERE slug = :slug AND status = 'active'");
        $stmt->execute([':slug' => $slug]);
        $product = $stmt->fetch();

        if ($product) return $product;

        // Check slug_registry for locale-specific slugs
        $stmt = $db->prepare("
            SELECT p.* FROM slug_registry sr
            JOIN products p ON p.id = sr.entity_id
            WHERE sr.slug = :slug AND sr.entity = 'product' AND p.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([':slug' => $slug]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get product by ID
     */
    public static function byId(int $id): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM products WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get product by SKU
     */
    public static function bySku(string $sku): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM products WHERE sku = :sku");
        $stmt->execute([':sku' => $sku]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get products for a category (paginated, sorted, filtered)
     */
    public static function byCategory(
        int $categoryId,
        int $page = 1,
        int $perPage = 24,
        string $sort = 'price_asc',
        array $filters = [],
        ?int $priceMin = null,
        ?int $priceMax = null
    ): array {
        $db = Db::get();
        $offset = ($page - 1) * $perPage;

        // Base conditions
        $where = "p.category_id = :cat AND p.status = 'active'";
        $params = [':cat' => $categoryId];

        // Price range
        if ($priceMin !== null) {
            $where .= " AND p.price >= :pmin";
            $params[':pmin'] = $priceMin;
        }
        if ($priceMax !== null) {
            $where .= " AND p.price <= :pmax";
            $params[':pmax'] = $priceMax;
        }

        // Attribute filters via FTS
        if (!empty($filters)) {
            $ftsTokens = [];
            foreach ($filters as $attrName => $values) {
                if (!is_array($values)) $values = [$values];
                $orTokens = [];
                foreach ($values as $v) {
                    $n = self::normalizeToken($attrName);
                    $val = self::normalizeToken($v);
                    $orTokens[] = "attr_{$n}_{$val}";
                }
                // Within same attribute: OR (pick any size)
                if (count($orTokens) === 1) {
                    $ftsTokens[] = $orTokens[0];
                } else {
                    $ftsTokens[] = '(' . implode(' OR ', $orTokens) . ')';
                }
            }
            // Between different attributes: AND (must match all)
            $ftsQuery = implode(' AND ', $ftsTokens);

            if (Db::isSqlite()) {
                $where .= " AND p.id IN (
                    SELECT rowid FROM products_fts WHERE products_fts MATCH :fts
                )";
            } else {
                $where .= " AND MATCH(p.filter_tags) AGAINST(:fts IN BOOLEAN MODE)";
            }
            $params[':fts'] = $ftsQuery;
        }

        // Sort
        $orderBy = match($sort) {
            'price_asc'  => 'p.price ASC',
            'price_desc' => 'p.price DESC',
            'name_asc'   => 'p.name ASC',
            'name_desc'  => 'p.name DESC',
            'newest'     => 'p.created_at DESC',
            default      => 'p.price ASC',
        };

        // Count total
        $countSql = "SELECT COUNT(*) FROM products p WHERE {$where}";
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        // Fetch page
        $sql = "SELECT p.* FROM products p WHERE {$where} ORDER BY {$orderBy} LIMIT :limit OFFSET :offset";
        $params[':limit'] = $perPage;
        $params[':offset'] = $offset;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        return [
            'products' => $products,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int)ceil($total / $perPage),
        ];
    }

    /**
     * Get products also in subcategories (using materialized path)
     */
    public static function byCategoryTree(int $categoryId, int $page = 1, int $perPage = 24, string $sort = 'price_asc'): array {
        $db = Db::get();

        // Get category path for LIKE query
        $stmt = $db->prepare("SELECT path FROM categories WHERE id = :id");
        $stmt->execute([':id' => $categoryId]);
        $catPath = $stmt->fetchColumn();

        if (!$catPath) return ['products' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'pages' => 0];

        // Get all category IDs in subtree
        $stmt = $db->prepare("SELECT id FROM categories WHERE path = :path OR path LIKE :pathPrefix");
        $stmt->execute([':path' => $catPath, ':pathPrefix' => $catPath . '/%']);
        $catIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($catIds)) $catIds = [$categoryId];

        $placeholders = implode(',', array_fill(0, count($catIds), '?'));
        $offset = ($page - 1) * $perPage;

        $orderBy = match($sort) {
            'price_asc'  => 'p.price ASC',
            'price_desc' => 'p.price DESC',
            'name_asc'   => 'p.name ASC',
            'name_desc'  => 'p.name DESC',
            'newest'     => 'p.created_at DESC',
            default      => 'p.price ASC',
        };

        // Count
        $stmt = $db->prepare("SELECT COUNT(*) FROM products p WHERE p.category_id IN ({$placeholders}) AND p.status = 'active'");
        $stmt->execute($catIds);
        $total = (int)$stmt->fetchColumn();

        // Fetch
        $stmt = $db->prepare("SELECT p.* FROM products p WHERE p.category_id IN ({$placeholders}) AND p.status = 'active' ORDER BY {$orderBy} LIMIT ? OFFSET ?");
        $stmt->execute([...$catIds, $perPage, $offset]);
        $products = $stmt->fetchAll();

        return [
            'products' => $products,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int)ceil($total / $perPage),
        ];
    }

    /**
     * Get variants for a product
     */
    public static function variants(int $productId): array {
        $stmt = Db::get()->prepare("SELECT * FROM variants WHERE product_id = :pid ORDER BY sort_order, id");
        $stmt->execute([':pid' => $productId]);
        return $stmt->fetchAll();
    }

    /**
     * Get attributes for a product
     */
    public static function attrs(int $productId): array {
        $stmt = Db::get()->prepare("SELECT attr_name, attr_value, numeric_val FROM product_attrs WHERE product_id = :pid ORDER BY attr_name");
        $stmt->execute([':pid' => $productId]);
        return $stmt->fetchAll();
    }

    /**
     * Get available filter values for a category
     */
    public static function filterValues(int $categoryId): array {
        $stmt = Db::get()->prepare("
            SELECT pa.attr_name, pa.attr_value, COUNT(*) as cnt
            FROM product_attrs pa
            JOIN products p ON p.id = pa.product_id
            WHERE p.category_id = :cat AND p.status = 'active'
            GROUP BY pa.attr_name, pa.attr_value
            ORDER BY pa.attr_name, cnt DESC
        ");
        $stmt->execute([':cat' => $categoryId]);
        $rows = $stmt->fetchAll();

        // Group by attribute name
        $filters = [];
        foreach ($rows as $row) {
            $filters[$row['attr_name']][] = [
                'value' => $row['attr_value'],
                'count' => (int)$row['cnt'],
            ];
        }
        return $filters;
    }

    /**
     * Related products (same category, excluding current)
     */
    public static function related(int $productId, int $limit = 4): array {
        $product = self::byId($productId);
        if (!$product) return [];

        $stmt = Db::get()->prepare("
            SELECT * FROM products
            WHERE category_id = :cat AND id != :id AND status = 'active'
            ORDER BY RANDOM()
            LIMIT :limit
        ");
        $stmt->execute([':cat' => $product['category_id'], ':id' => $productId, ':limit' => $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Latest products
     */
    public static function latest(int $limit = 8): array {
        $stmt = Db::get()->prepare("
            SELECT * FROM products WHERE status = 'active'
            ORDER BY created_at DESC LIMIT :limit
        ");
        $stmt->execute([':limit' => $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Products on sale (has compare_price)
     */
    public static function onSale(int $limit = 8): array {
        $stmt = Db::get()->prepare("
            SELECT * FROM products
            WHERE status = 'active' AND compare_price IS NOT NULL AND compare_price > price
            ORDER BY (compare_price - price) DESC
            LIMIT :limit
        ");
        $stmt->execute([':limit' => $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Increment stock (for order cancellation)
     */
    public static function incrementStock(int $id, int $qty): void {
        $stmt = Db::get()->prepare("UPDATE products SET stock = stock + :qty WHERE id = :id");
        $stmt->execute([':qty' => $qty, ':id' => $id]);
    }

    /**
     * Bulk update stock from ERP data [{sku, qty}, ...]
     */
    public static function bulkUpdateStock(array $stockData): int {
        $db = Db::get();
        $stmt = $db->prepare("UPDATE products SET stock = :qty, updated_at = " . Db::now() . " WHERE sku = :sku");
        $updated = 0;

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) $db->beginTransaction();
        foreach ($stockData as $item) {
            $stmt->execute([':qty' => (int)$item['qty'], ':sku' => $item['sku']]);
            if ($stmt->rowCount() > 0) $updated++;
        }
        if ($ownTransaction) $db->commit();

        return $updated;
    }

    /**
     * Enrich product with computed fields for display
     */
    public static function enrich(array $product): array {
        $product['images'] = json_decode($product['images'] ?? '[]', true);
        $product['attrs'] = json_decode($product['attrs'] ?? '{}', true);
        $product['tags'] = json_decode($product['tags'] ?? '[]', true);
        $currency = $product['currency'] ?? null;
        $product['price_formatted'] = I18n::price($product['price'], $currency);

        if (!empty($product['compare_price']) && $product['compare_price'] > 0) {
            $product['compare_price_formatted'] = I18n::price($product['compare_price'], $currency);
            $product['discount_percent'] = round(
                (1 - $product['price'] / $product['compare_price']) * 100
            );
        }

        $product['url'] = '/product/' . $product['slug'];
        $product['image'] = $product['images'][0] ?? null;

        // Add category info for pricing rule matching
        if (!empty($product['category_id']) && empty($product['category_path'])) {
            $cat = Db::get()->prepare("SELECT slug, path FROM categories WHERE id = :id");
            $cat->execute([':id' => $product['category_id']]);
            $catRow = $cat->fetch();
            if ($catRow) {
                $product['category_slug'] = $catRow['slug'];
                $product['category_path'] = $catRow['path'];
            }
        }

        return $product;
    }

    private static function normalizeToken(string $s): string {
        return Str::normalizeToken($s);
    }
}
