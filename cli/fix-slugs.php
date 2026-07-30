<?php
require_once __DIR__ . '/cli-only.php';

/**
 * Fix product and category slugs — re-slugify with proper diacritics handling.
 */
require_once __DIR__ . '/../src/autoload.php';

$db = Db::get();

echo "Fixing product slugs...\n";
$products = $db->query("SELECT id, name, sku, slug FROM products")->fetchAll();
$update = $db->prepare("UPDATE products SET slug = :slug WHERE id = :id");
$seen = [];
$fixed = 0;

foreach ($products as $p) {
    // Slug from name + sku suffix for uniqueness
    $base = Import::slugify($p['name']);
    if (empty($base)) $base = 'product';
    $slug = $base . '-' . Import::slugify($p['sku']);

    // Ensure uniqueness
    if (isset($seen[$slug])) {
        $slug .= '-' . $p['id'];
    }
    $seen[$slug] = true;

    if ($slug !== $p['slug']) {
        $update->execute([':slug' => $slug, ':id' => $p['id']]);
        $fixed++;
        if ($fixed <= 10) {
            echo "  [{$p['id']}] {$p['slug']} => {$slug}\n";
        }
    }
}
echo "Fixed {$fixed} product slugs out of " . count($products) . " total.\n\n";

echo "Fixing category slugs...\n";
$cats = $db->query("SELECT id, name, slug, path FROM categories")->fetchAll();
$catUpdate = $db->prepare("UPDATE categories SET slug = :slug, path = :path WHERE id = :id");
$catFixed = 0;

// Build parent map for path reconstruction
$catMap = [];
foreach ($cats as $c) $catMap[(int)$c['id']] = $c;

// Get parent_id info
$catsWithParent = $db->query("SELECT id, parent_id, name, slug, path FROM categories")->fetchAll();
$parentMap = [];
foreach ($catsWithParent as $c) $parentMap[(int)$c['id']] = $c['parent_id'] ? (int)$c['parent_id'] : null;

// First pass: fix slugs (ensure uniqueness)
$newSlugs = [];
$seenCatSlugs = [];
foreach ($cats as $c) {
    $newSlug = Import::slugify($c['name']);
    if (empty($newSlug)) $newSlug = 'category-' . $c['id'];
    // Ensure uniqueness
    if (isset($seenCatSlugs[$newSlug])) {
        $newSlug .= '-' . $c['id'];
    }
    $seenCatSlugs[$newSlug] = true;
    $newSlugs[(int)$c['id']] = $newSlug;
}

// Second pass: rebuild paths and update
foreach ($cats as $c) {
    $id = (int)$c['id'];
    $newSlug = $newSlugs[$id];

    // Rebuild path from root to this category
    $pathParts = [];
    $cur = $id;
    while ($cur !== null) {
        $pathParts[] = $newSlugs[$cur];
        $cur = $parentMap[$cur] ?? null;
    }
    $newPath = implode('/', array_reverse($pathParts));

    if ($newSlug !== $c['slug'] || $newPath !== $c['path']) {
        $catUpdate->execute([':slug' => $newSlug, ':path' => $newPath, ':id' => $id]);
        $catFixed++;
        if ($catFixed <= 10) {
            echo "  [{$id}] {$c['slug']} => {$newSlug}";
            if ($newPath !== $c['path']) echo "  path: {$c['path']} => {$newPath}";
            echo "\n";
        }
    }
}
echo "Fixed {$catFixed} category slugs out of " . count($cats) . " total.\n";
echo "\nDone!\n";
