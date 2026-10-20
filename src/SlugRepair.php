<?php
/**
 * Re-slugify product and category slugs (cli/fix-slugs.php). Every replaced slug stays registered for its product or
 * category (slug_registry locale 'previous'), so the old URL still resolves and the storefront answers it with a 301
 * to the new canonical URL instead of a 404.
 */
final class SlugRepair
{
    public const PREVIOUS = 'previous';

    /** @return array{products: int, product_total: int, categories: int, category_total: int, log: list<string>} */
    public static function run(PDO $db): array
    {
        $log = [];
        $alias = $db->prepare((Db::isSqlite() ? 'INSERT OR IGNORE' : 'INSERT IGNORE')
            . " INTO slug_registry (slug, locale, entity, entity_id, store_id) VALUES (:slug, '" . self::PREVIOUS . "', :entity, :id, :store)");
        $changed = [];
        $keep = static function(string $entity, array $row) use ($alias, &$changed): void {
            $changed[(string)$row['store_id']] = true;
            if ((string)$row['slug'] === '') return;
            $alias->execute([':slug' => (string)$row['slug'], ':entity' => $entity, ':id' => (int)$row['id'], ':store' => (string)$row['store_id']]);
        };

        $products = $db->query('SELECT id, name, sku, slug, store_id FROM products')->fetchAll(PDO::FETCH_ASSOC);
        $update = $db->prepare('UPDATE products SET slug = :slug WHERE id = :id');
        $seen = [];
        $fixed = 0;
        foreach ($products as $p) {
            // Slug from name + sku suffix for uniqueness
            $base = Import::slugify((string)$p['name']);
            if ($base === '') $base = 'product';
            $slug = $base . '-' . Import::slugify((string)$p['sku']);
            if (isset($seen[$slug])) $slug .= '-' . $p['id'];
            $seen[$slug] = true;
            if ($slug === $p['slug']) continue;
            $keep('product', $p);
            $update->execute([':slug' => $slug, ':id' => $p['id']]);
            if (++$fixed <= 10) $log[] = "  [{$p['id']}] {$p['slug']} => {$slug}";
        }

        $cats = $db->query('SELECT id, parent_id, name, slug, path, store_id FROM categories')->fetchAll(PDO::FETCH_ASSOC);
        $catUpdate = $db->prepare('UPDATE categories SET slug = :slug, path = :path WHERE id = :id');
        $parentMap = [];
        $newSlugs = [];
        $seenCatSlugs = [];
        foreach ($cats as $c) {
            $parentMap[(int)$c['id']] = $c['parent_id'] ? (int)$c['parent_id'] : null;
            $newSlug = Import::slugify((string)$c['name']);
            if ($newSlug === '') $newSlug = 'category-' . $c['id'];
            if (isset($seenCatSlugs[$newSlug])) $newSlug .= '-' . $c['id'];
            $seenCatSlugs[$newSlug] = true;
            $newSlugs[(int)$c['id']] = $newSlug;
        }
        $catFixed = 0;
        foreach ($cats as $c) {
            $id = (int)$c['id'];
            $pathParts = [];
            for ($cur = $id; $cur !== null; $cur = $parentMap[$cur] ?? null) $pathParts[] = $newSlugs[$cur];
            $newPath = implode('/', array_reverse($pathParts));
            if ($newSlugs[$id] === $c['slug'] && $newPath === $c['path']) continue;
            $changed[(string)$c['store_id']] = true;
            if ($newSlugs[$id] !== $c['slug']) $keep('category', $c);
            $catUpdate->execute([':slug' => $newSlugs[$id], ':path' => $newPath, ':id' => $id]);
            if (++$catFixed <= 10) {
                $log[] = "  [{$id}] {$c['slug']} => {$newSlugs[$id]}" . ($newPath !== $c['path'] ? "  path: {$c['path']} => {$newPath}" : '');
            }
        }
        foreach (array_keys($changed) as $catalogId) Cache::catalogChanged((string)$catalogId);
        return ['products' => $fixed, 'product_total' => count($products), 'categories' => $catFixed, 'category_total' => count($cats), 'log' => $log];
    }

    /**
     * Where a storefront request for $requested must go when the page's canonical slug is $canonical (an old slug
     * kept by run(), or another language's slug): $url with the request's own query parameters; null when canonical.
     */
    public static function canonicalRedirect(string $requested, string $canonical, string $url, array $query): ?string
    {
        if ($canonical === '' || $requested === $canonical) return null;
        $query = array_diff_key($query, ['page' => true, 'slug' => true, 'category' => true, 'path' => true]);
        return $url . ($query === [] ? '' : (str_contains($url, '?') ? '&' : '?') . http_build_query($query));
    }
}
