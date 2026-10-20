<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — Magento 1.9 CE Migration
 *
 * Extracts products, categories, images, translations, and configurable/variant
 * relationships from a Magento 1.9 SQL dump and imports them into BareBonesEshop.
 *
 * Usage:
 *   php cli/migrate-magento.php --dump=/path/to/dump.sql --media=/path/to/media/catalog/product [--dry-run] [--fresh]
 *
 * Options:
 *   --dump    Path to Magento MySQL dump file (required)
 *   --media   Path to Magento media/catalog/product directory (optional, for image copy)
 *   --dry-run Parse and report without writing to database
 *   --fresh   Drop existing data and reimport from scratch
 *   --prefix  Magento table prefix (default: detected from the dump)
 */
require_once __DIR__ . '/../src/autoload.php';

// ============================================================
// CLI arguments
// ============================================================
$opts = getopt('', ['dump:', 'media:', 'dry-run', 'fresh', 'prefix:']);
$dumpPath  = $opts['dump'] ?? null;
$mediaPath = $opts['media'] ?? null;
$dryRun    = isset($opts['dry-run']);
$fresh     = isset($opts['fresh']);
// Magento table prefix; detected from the dump when not given (an unprefixed install has none).
$prefix    = isset($opts['prefix']) ? (string)$opts['prefix'] : null;

if (!$dumpPath) {
    echo "Usage: php cli/migrate-magento.php --dump=/path/to/dump.sql [--media=/path/to/media/catalog/product] [--prefix=mg_] [--dry-run] [--fresh]\n";
    exit(1);
}
if (!file_exists($dumpPath)) {
    echo "ERROR: Dump file not found: {$dumpPath}\n";
    exit(1);
}

echo "===========================================\n";
echo " Magento 1.9 → BareBonesEshop Migration\n";
echo "===========================================\n\n";
echo "Dump:  {$dumpPath}\n";
echo "Media: " . ($mediaPath ?: '(none)') . "\n";
echo "Mode:  " . ($dryRun ? 'DRY RUN' : 'LIVE') . "\n";
echo "Fresh: " . ($fresh ? 'YES' : 'no') . "\n\n";

// ============================================================
// Magento EAV attribute IDs (from analysis of this specific dump)
// ============================================================
const MAGE_ATTR = [
    'name'              => 71,
    'description'       => 72,
    'short_description' => 73,
    'price'             => 75,
    'special_price'     => 76,
    'weight'            => 80,
    'image'             => 85,
    'small_image'       => 86,
    'thumbnail'         => 87,
    'media_gallery'     => 88,
    'color'             => 92,
    'status'            => 96,
    'url_key'           => 97,
    'visibility'        => 102,
    'wood'              => 145,
    'mattress_width'    => 146,
    'mattress_length'   => 147,
    'radiator'          => 166,
    'surface'           => 167,
];

// Magento store_id → BareBonesEshop locale mapping
const STORE_MAP = [
    0 => 'sk',  // admin/default → Slovak
    1 => 'sk',  // woodle_sk
    2 => 'en',  // woodle_en
    3 => 'de',  // woodle_de
];

// Magento category attribute IDs
const CAT_ATTR = [
    'name'    => 41,
    'url_key' => 117,
];

// ============================================================
// Phase 1: Parse the SQL dump
// ============================================================
echo "Phase 1: Parsing SQL dump...\n";

$tables = [
    'catalog_product_entity'                     => [],
    'catalog_product_entity_varchar'             => [],
    'catalog_product_entity_text'                => [],
    'catalog_product_entity_decimal'             => [],
    'catalog_product_entity_int'                 => [],
    'catalog_category_entity'                    => [],
    'catalog_category_entity_varchar'            => [],
    'catalog_category_product'                   => [],
    'catalog_product_super_link'                 => [],
    'catalog_product_super_attribute'            => [],
    'catalog_product_entity_media_gallery'       => [],
    'catalog_product_entity_media_gallery_value' => [],
    'eav_attribute_option_value'                 => [],
    'eav_attribute_option'                       => [],
    'cataloginventory_stock_item'                => [],
];

// Column definitions for each table (from Magento 1.9 schema)
$columnDefs = [
    'catalog_product_entity' => [
        'entity_id', 'entity_type_id', 'attribute_set_id', 'type_id', 'sku',
        'has_options', 'required_options', 'created_at', 'updated_at',
    ],
    'catalog_product_entity_varchar' => [
        'value_id', 'entity_type_id', 'attribute_id', 'store_id', 'entity_id', 'value',
    ],
    'catalog_product_entity_text' => [
        'value_id', 'entity_type_id', 'attribute_id', 'store_id', 'entity_id', 'value',
    ],
    'catalog_product_entity_decimal' => [
        'value_id', 'entity_type_id', 'attribute_id', 'store_id', 'entity_id', 'value',
    ],
    'catalog_product_entity_int' => [
        'value_id', 'entity_type_id', 'attribute_id', 'store_id', 'entity_id', 'value',
    ],
    'catalog_category_entity' => [
        'entity_id', 'entity_type_id', 'attribute_set_id', 'parent_id',
        'created_at', 'updated_at', 'path', 'position', 'level', 'children_count',
    ],
    'catalog_category_entity_varchar' => [
        'value_id', 'entity_type_id', 'attribute_id', 'store_id', 'entity_id', 'value',
    ],
    'catalog_category_product' => [
        'category_id', 'product_id', 'position',
    ],
    'catalog_product_super_link' => [
        'link_id', 'product_id', 'parent_id',
    ],
    'catalog_product_super_attribute' => [
        'product_super_attribute_id', 'product_id', 'attribute_id', 'position',
    ],
    'catalog_product_entity_media_gallery' => [
        'value_id', 'attribute_id', 'entity_id', 'value',
    ],
    'catalog_product_entity_media_gallery_value' => [
        'value_id', 'store_id', 'label', 'position', 'disabled',
    ],
    'eav_attribute_option_value' => [
        'value_id', 'option_id', 'store_id', 'value',
    ],
    'eav_attribute_option' => [
        'option_id', 'attribute_id', 'sort_order',
    ],
    'cataloginventory_stock_item' => [
        'item_id', 'product_id', 'stock_id', 'qty', 'min_qty', 'use_config_min_qty',
        'is_qty_decimal', 'backorders', 'use_config_backorders', 'min_sale_qty',
        'use_config_min_sale_qty', 'max_sale_qty', 'use_config_max_sale_qty',
        'is_in_stock', 'low_stock_date', 'notify_stock_qty', 'use_config_notify_stock_qty',
        'manage_stock', 'use_config_manage_stock', 'stock_status_changed_auto',
        'use_config_qty_increments', 'qty_increments', 'use_config_enable_qty_inc',
        'enable_qty_increments', 'is_decimal_divided',
    ],
];

// Parse the SQL dump
$handle = fopen($dumpPath, 'r');
if (!$handle) { echo "ERROR: Cannot open dump file\n"; exit(1); }

$lineNum = 0;
$totalRows = 0;
$shortNames = array_keys($tables);
usort($shortNames, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
$tablePattern = '/^INSERT INTO `([A-Za-z0-9_]*?)(' . implode('|', array_map(static fn(string $t): string => preg_quote($t, '/'), $shortNames)) . ')`/';

while (($line = fgets($handle)) !== false) {
    $lineNum++;
    $line = rtrim($line, "\r\n");

    // Skip non-INSERT lines; the table is one of ours under the (given or first detected) prefix
    if (!preg_match($tablePattern, $line, $m)) continue;
    if ($prefix === null) {
        $prefix = $m[1];
        echo "  Detected table prefix: '" . $prefix . "'\n";
    }
    if ($m[1] !== $prefix) continue;
    $shortName = $m[2];

    // Extract VALUES portion
    $valuesPos = strpos($line, 'VALUES ');
    if ($valuesPos === false) continue;

    foreach (parseMysqlValues(substr($line, $valuesPos + 7), $columnDefs[$shortName]) as $row) {
        $tables[$shortName][] = $row;
        $totalRows++;
    }
}
fclose($handle);

echo "  Parsed {$lineNum} lines, extracted {$totalRows} data rows\n";
if ($tables['catalog_product_entity'] === []) {
    echo "ERROR: no Magento products found in the dump" . ($prefix !== null ? " for table prefix '{$prefix}'" : '') . " (check --prefix)\n";
    exit(1);
}
foreach ($tables as $name => $rows) {
    if (!empty($rows)) echo "  {$name}: " . count($rows) . " rows\n";
}
echo "\n";

// ============================================================
// Phase 2: Build product data from EAV
// ============================================================
echo "Phase 2: Building product data from EAV...\n";

// Index product entities by entity_id
$productEntities = [];
foreach ($tables['catalog_product_entity'] as $row) {
    $id = (int)$row['entity_id'];
    $productEntities[$id] = [
        'entity_id' => $id,
        'type_id'   => $row['type_id'],
        'sku'       => $row['sku'],
    ];
}
echo "  Products: " . count($productEntities) . "\n";

// Build EAV value lookup: [entity_id][attribute_id][store_id] = value
$eavData = [];
foreach (['catalog_product_entity_varchar', 'catalog_product_entity_text',
          'catalog_product_entity_decimal', 'catalog_product_entity_int'] as $tbl) {
    foreach ($tables[$tbl] as $row) {
        $eid = (int)$row['entity_id'];
        $aid = (int)$row['attribute_id'];
        $sid = (int)$row['store_id'];
        $eavData[$eid][$aid][$sid] = $row['value'];
    }
}

// Category entities
$categoryEntities = [];
foreach ($tables['catalog_category_entity'] as $row) {
    $id = (int)$row['entity_id'];
    $categoryEntities[$id] = [
        'entity_id'  => $id,
        'parent_id'  => (int)$row['parent_id'],
        'path'       => $row['path'],
        'level'      => (int)$row['level'],
        'position'   => (int)$row['position'],
    ];
}

// Category names from EAV
$categoryNames = [];
foreach ($tables['catalog_category_entity_varchar'] as $row) {
    $eid = (int)$row['entity_id'];
    $aid = (int)$row['attribute_id'];
    $sid = (int)$row['store_id'];
    if ($aid === CAT_ATTR['name']) {
        $locale = STORE_MAP[$sid] ?? 'sk';
        $categoryNames[$eid][$locale] = $row['value'];
    }
    if ($aid === CAT_ATTR['url_key']) {
        $categoryNames[$eid]['url_key_' . ($sid ?: 'default')] = $row['value'];
    }
}

// Category → product assignments
$categoryProducts = [];
foreach ($tables['catalog_category_product'] as $row) {
    $catId = (int)$row['category_id'];
    $prodId = (int)$row['product_id'];
    $categoryProducts[$prodId][] = $catId;
}

// Configurable → simple links
$superLinks = [];    // parent_id => [child_id, ...]
$childParent = [];   // child_id => parent_id
foreach ($tables['catalog_product_super_link'] as $row) {
    $parentId = (int)$row['parent_id'];
    $childId  = (int)$row['product_id'];
    $superLinks[$parentId][] = $childId;
    $childParent[$childId] = $parentId;
}

// Super attributes per configurable parent
$superAttrs = []; // parent_id => [attribute_id, ...]
foreach ($tables['catalog_product_super_attribute'] as $row) {
    $superAttrs[(int)$row['product_id']][] = (int)$row['attribute_id'];
}

// Media gallery: entity_id => [image_path, ...]
$mediaGallery = [];
$mediaValues  = [];
foreach ($tables['catalog_product_entity_media_gallery_value'] as $row) {
    $mediaValues[(int)$row['value_id']] = [
        'position' => (int)$row['position'],
        'disabled' => (int)$row['disabled'],
        'store_id' => (int)$row['store_id'],
    ];
}
foreach ($tables['catalog_product_entity_media_gallery'] as $row) {
    $vid = (int)$row['value_id'];
    $eid = (int)$row['entity_id'];
    $val = $row['value'];

    if ($val === '' || $val === 'no_selection') continue;

    // Check if disabled in store view 0 (admin)
    $meta = $mediaValues[$vid] ?? null;
    if ($meta && $meta['disabled']) continue;

    $pos = $meta['position'] ?? 999;
    $mediaGallery[$eid][] = ['path' => $val, 'position' => $pos];
}
// Sort by position
foreach ($mediaGallery as &$imgs) {
    usort($imgs, fn($a, $b) => $a['position'] <=> $b['position']);
}
unset($imgs);

// EAV attribute option values (for color etc.)
$optionToAttribute = []; // option_id => attribute_id
foreach ($tables['eav_attribute_option'] as $row) {
    $optionToAttribute[(int)$row['option_id']] = (int)$row['attribute_id'];
}

$optionLabels = []; // option_id => [store_id => label]
foreach ($tables['eav_attribute_option_value'] as $row) {
    $optionLabels[(int)$row['option_id']][(int)$row['store_id']] = $row['value'];
}

// Stock data
$stockData = []; // product_id => qty
foreach ($tables['cataloginventory_stock_item'] as $row) {
    $stockData[(int)$row['product_id']] = (int)((float)($row['qty'] ?? 0));
}

echo "  Categories: " . count($categoryEntities) . "\n";
echo "  Super links (configurable→simple): " . array_sum(array_map('count', $superLinks)) . "\n";
echo "  Media gallery images: " . array_sum(array_map('count', $mediaGallery)) . "\n";

// ============================================================
// Helper: get EAV value for entity
// ============================================================
function eavGet(array &$eavData, int $entityId, int $attrId, int $storeId = 0): ?string {
    // Try requested store first, fallback to admin (0)
    return $eavData[$entityId][$attrId][$storeId]
        ?? $eavData[$entityId][$attrId][0]
        ?? $eavData[$entityId][$attrId][1]  // Slovak store
        ?? null;
}

function eavGetStore(array &$eavData, int $entityId, int $attrId, int $storeId): ?string {
    return $eavData[$entityId][$attrId][$storeId] ?? null;
}

// ============================================================
// Phase 3: Build category tree
// ============================================================
echo "\nPhase 3: Building category tree...\n";

// Find real top-level categories (level 2 = direct children of "Default Category")
// Magento hierarchy: Root(1) → Default Category(2) → actual categories(level 2)
$topCategories = [];
foreach ($categoryEntities as $cat) {
    if ($cat['level'] >= 2 && $cat['parent_id'] !== 1) {
        // This is a real category visible in the store
    }
}

// Build the BareBonesEshop category structure
// Map Magento category_id → BareBonesEshop category data
$categoryMap = []; // mage_cat_id => ['name' => ..., 'path' => ..., 'parent_mage_id' => ...]

function buildCategoryPath(int $catId, array &$categoryEntities, array &$categoryNames, array &$cache): ?array {
    if (isset($cache[$catId])) return $cache[$catId];

    $cat = $categoryEntities[$catId] ?? null;
    if (!$cat || $cat['level'] < 2) return null;

    $name = $categoryNames[$catId]['sk'] ?? $categoryNames[$catId]['en'] ?? "Category-{$catId}";
    $urlKey = $categoryNames[$catId]['url_key_default']
           ?? $categoryNames[$catId]['url_key_0']
           ?? Import::slugify($name);

    // Translations
    $translations = [];
    foreach (['en', 'de'] as $locale) {
        $locName = $categoryNames[$catId][$locale] ?? null;
        if ($locName && $locName !== $name) {
            $translations[$locale] = ['name' => $locName];
        }
    }

    // Get parent path
    $parentId = $cat['parent_id'];
    if ($parentId > 2) { // parent is a real category (not root or default)
        $parentData = buildCategoryPath($parentId, $categoryEntities, $categoryNames, $cache);
        if ($parentData) {
            $path = $parentData['path'] . '/' . $urlKey;
        } else {
            $path = $urlKey;
        }
    } else {
        $path = $urlKey;
    }

    $result = [
        'name'         => $name,
        'slug'         => $urlKey,
        'path'         => $path,
        'level'        => $cat['level'] - 2, // Adjust to 0-based
        'position'     => $cat['position'],
        'parent_mage'  => $parentId > 2 ? $parentId : null,
        'translations' => $translations,
    ];

    $cache[$catId] = $result;
    return $result;
}

$catCache = [];
foreach ($categoryEntities as $catId => $cat) {
    if ($cat['level'] >= 2) {
        buildCategoryPath($catId, $categoryEntities, $categoryNames, $catCache);
    }
}

echo "  Mapped " . count($catCache) . " categories\n";

// Show category tree
foreach ($catCache as $catId => $catData) {
    $indent = str_repeat('  ', $catData['level']);
    $tr = !empty($catData['translations']) ? ' [' . implode(',', array_keys($catData['translations'])) . ']' : '';
    echo "  {$indent}{$catData['name']} ({$catData['path']}){$tr}\n";
}

// ============================================================
// Phase 4: Resolve which product gets its category from where
// ============================================================
echo "\nPhase 4: Resolving product categories...\n";

/**
 * Find the deepest (most specific) category for a product.
 * Magento assigns products to multiple categories; we pick the most specific one.
 */
function resolveProductCategory(int $entityId, array &$categoryProducts, array &$catCache): ?int {
    $cats = $categoryProducts[$entityId] ?? [];
    if (empty($cats)) return null;

    $best = null;
    $bestLevel = -1;
    foreach ($cats as $catId) {
        $data = $catCache[$catId] ?? null;
        if ($data && $data['level'] >= $bestLevel) {
            $bestLevel = $data['level'];
            $best = $catId;
        }
    }
    return $best;
}

// ============================================================
// Phase 5: Prepare final product data
// ============================================================
echo "\nPhase 5: Preparing product data...\n";

// Attribute name lookup for super attributes
$attrIdToName = array_flip(MAGE_ATTR);

// Resolve option values to labels
function resolveOptionLabel(int $optionId, array &$optionLabels, int $storeId = 0): ?string {
    return $optionLabels[$optionId][$storeId]
        ?? $optionLabels[$optionId][1]
        ?? $optionLabels[$optionId][0]
        ?? null;
}

// Collect products: configurables as parents with variants, standalone simples as products
$productsToImport = []; // SKU => product data
$variantsToImport = []; // parent_sku => [variant, ...]
$skippedChildren = 0;
$standaloneSimples = 0;
$configurables = 0;

foreach ($productEntities as $entityId => $entity) {
    $typeId = $entity['type_id'];
    $sku    = $entity['sku'];

    // Skip simple products that are children of configurables — they become variants
    if ($typeId === 'simple' && isset($childParent[$entityId])) {
        $skippedChildren++;
        continue;
    }

    // Get EAV values for default store (0/1)
    $name        = eavGet($eavData, $entityId, MAGE_ATTR['name']);
    $description = eavGet($eavData, $entityId, MAGE_ATTR['description']);
    $shortDesc   = eavGet($eavData, $entityId, MAGE_ATTR['short_description']);
    $urlKey      = eavGet($eavData, $entityId, MAGE_ATTR['url_key']);
    $price       = eavGet($eavData, $entityId, MAGE_ATTR['price']);
    $specialPrice = eavGet($eavData, $entityId, MAGE_ATTR['special_price']);
    $weight      = eavGet($eavData, $entityId, MAGE_ATTR['weight']);
    $status      = eavGet($eavData, $entityId, MAGE_ATTR['status']);
    $visibility  = eavGet($eavData, $entityId, MAGE_ATTR['visibility']);
    $image       = eavGet($eavData, $entityId, MAGE_ATTR['image']);

    if (!$name || !$sku) continue;

    // Status: 1=enabled, 2=disabled
    $bbStatus = ($status == 1) ? 'active' : 'draft';

    // Price in cents (Magento stores in EUR decimal)
    $priceCents = $price ? (int)round((float)$price * 100) : 0;
    $comparePriceCents = null;
    if ($specialPrice && (float)$specialPrice > 0 && (float)$specialPrice < (float)$price) {
        // In Magento, special_price is the SALE price, so original is the compare price
        $comparePriceCents = $priceCents;
        $priceCents = (int)round((float)$specialPrice * 100);
    }

    // Slug
    $slug = $urlKey ?: Import::slugify($name);

    // Weight in grams (Magento stores in kg)
    $weightG = $weight ? (int)round((float)$weight * 1000) : 0;

    // Images
    $images = [];
    if (isset($mediaGallery[$entityId])) {
        foreach ($mediaGallery[$entityId] as $img) {
            $images[] = $img['path'];
        }
    } elseif ($image && $image !== 'no_selection') {
        $images[] = $image;
    }

    // Translations (EN, DE)
    $translations = [];
    foreach ([2 => 'en', 3 => 'de'] as $storeId => $locale) {
        $trName = eavGetStore($eavData, $entityId, MAGE_ATTR['name'], $storeId);
        $trDesc = eavGetStore($eavData, $entityId, MAGE_ATTR['description'], $storeId);
        $trShort = eavGetStore($eavData, $entityId, MAGE_ATTR['short_description'], $storeId);
        $trSlug = eavGetStore($eavData, $entityId, MAGE_ATTR['url_key'], $storeId);

        $tr = [];
        if ($trName && $trName !== $name) $tr['name'] = $trName;
        if ($trShort && $trShort !== $shortDesc) $tr['summary'] = $trShort;
        if ($trDesc && $trDesc !== $description) $tr['description'] = $trDesc;
        if ($trSlug && $trSlug !== $slug) $tr['slug'] = $trSlug;
        if (!empty($tr)) $translations[$locale] = $tr;
    }

    // Category
    $mageCatId = resolveProductCategory($entityId, $categoryProducts, $catCache);
    $categoryPath = 'uncategorized';
    if ($mageCatId && isset($catCache[$mageCatId])) {
        $categoryPath = $catCache[$mageCatId]['path'];
    }

    // Build product attrs from EAV (color, wood, etc.)
    $attrs = [];
    $colorOptId = eavGet($eavData, $entityId, MAGE_ATTR['color']);
    if ($colorOptId && is_numeric($colorOptId)) {
        $colorLabel = resolveOptionLabel((int)$colorOptId, $optionLabels, 1); // SK label
        if ($colorLabel) $attrs['color'] = $colorLabel;
    }

    $woodOptId = eavGet($eavData, $entityId, MAGE_ATTR['wood']);
    if ($woodOptId && is_numeric($woodOptId)) {
        $woodLabel = resolveOptionLabel((int)$woodOptId, $optionLabels, 1);
        if ($woodLabel) $attrs['wood'] = $woodLabel;
    }

    $product = [
        'sku'            => $sku,
        'slug'           => $slug,
        'name'           => $name,
        'summary'        => $shortDesc ?: '',
        'description'    => $description ?: '',
        'price'          => $priceCents,
        'compare_price'  => $comparePriceCents,
        'stock'          => $stockData[$entityId] ?? 0,
        'status'         => $bbStatus,
        'weight_g'       => $weightG,
        'images'         => $images,
        'category_path'  => $categoryPath,
        'attrs'          => $attrs,
        'translations'   => $translations,
        'product_type'   => 'simple',
        'mage_entity_id' => $entityId,
    ];

    if ($typeId === 'configurable') {
        $configurables++;
        $product['product_type'] = 'simple'; // BareBonesEshop uses variants on simple products

        // Build variants from simple children
        $children = $superLinks[$entityId] ?? [];
        $superAttrIds = $superAttrs[$entityId] ?? [];
        $variants = [];

        foreach ($children as $childId) {
            $childEntity = $productEntities[$childId] ?? null;
            if (!$childEntity) continue;

            $childSku  = $childEntity['sku'];
            $childName = eavGet($eavData, $childId, MAGE_ATTR['name']) ?: $childSku;
            $childPrice = eavGet($eavData, $childId, MAGE_ATTR['price']);
            $childSpecial = eavGet($eavData, $childId, MAGE_ATTR['special_price']);
            $childStock = $stockData[$childId] ?? 0;

            // Variant attributes (the super attributes define which attrs differentiate variants)
            $variantAttrs = [];
            foreach ($superAttrIds as $saId) {
                $attrName = $attrIdToName[$saId] ?? "attr_{$saId}";
                $optVal = eavGet($eavData, $childId, $saId);

                if ($optVal && is_numeric($optVal)) {
                    // It's an option_id — resolve to label
                    $label = resolveOptionLabel((int)$optVal, $optionLabels, 1);
                    $variantAttrs[$attrName] = $label ?: $optVal;
                } elseif ($optVal) {
                    $variantAttrs[$attrName] = $optVal;
                }
            }

            // Build variant name from attrs
            $variantNameParts = array_values($variantAttrs);
            $variantDisplayName = implode(' / ', $variantNameParts) ?: $childName;

            // Price delta or absolute
            $childPriceCents = $childPrice ? (int)round((float)$childPrice * 100) : $priceCents;
            $priceDelta = $childPriceCents - $priceCents;

            $variant = [
                'sku'        => $childSku,
                'name'       => $variantDisplayName,
                'attrs'      => $variantAttrs,
                'price_delta' => $priceDelta,
                'stock'      => $childStock,
            ];

            // If the child has its own absolute price that differs significantly
            if ($priceDelta !== 0) {
                $variant['price_absolute'] = $childPriceCents;
            }

            $variants[] = $variant;
        }

        $variantsToImport[$sku] = $variants;
    } else {
        $standaloneSimples++;
    }

    $productsToImport[$sku] = $product;
}

echo "  Configurable products: {$configurables}\n";
echo "  Standalone simple products: {$standaloneSimples}\n";
echo "  Child simple products (→ variants): {$skippedChildren}\n";
echo "  Total products to import: " . count($productsToImport) . "\n";
echo "  Total variants to import: " . array_sum(array_map('count', $variantsToImport)) . "\n\n";

// ============================================================
// Phase 6: Import into BareBonesEshop
// ============================================================
if ($dryRun) {
    echo "DRY RUN — skipping database import.\n";
    echo "Sample products:\n";
    $i = 0;
    foreach ($productsToImport as $sku => $p) {
        if ($i++ >= 5) break;
        $hasVariants = isset($variantsToImport[$sku]) ? count($variantsToImport[$sku]) . ' variants' : 'no variants';
        $imgCount = count($p['images']);
        echo "  [{$sku}] {$p['name']} — €" . number_format($p['price']/100, 2) . " — {$p['category_path']} — {$imgCount} images — {$hasVariants}\n";
    }
    exit(0);
}

echo "Phase 6: Importing into BareBonesEshop...\n";

$db = Db::get();

if ($fresh) {
    echo "  Clearing existing data...\n";
    $db->exec("DELETE FROM variants");
    $db->exec("DELETE FROM product_attrs");
    $db->exec("DELETE FROM slug_registry");
    $db->exec("DELETE FROM products");
    $db->exec("DELETE FROM categories");
    if (Db::isSqlite()) {
        // Rebuild FTS
        $db->exec("INSERT INTO products_fts(products_fts) VALUES('rebuild')");
    }
}

// Step 1: Create categories
echo "  Creating categories...\n";
$bbCategoryIds = []; // mage_cat_id => bb_category_id
foreach ($catCache as $mageCatId => $catData) {
    $bbCatId = Import::ensureCategory($catData['path'], $db);
    $bbCategoryIds[$mageCatId] = $bbCatId;

    // Update category translations if any
    if (!empty($catData['translations'])) {
        $stmt = $db->prepare("UPDATE categories SET translations = :tr WHERE id = :id");
        $stmt->execute([
            ':tr' => json_encode($catData['translations']),
            ':id' => $bbCatId,
        ]);
    }
}
echo "    Created " . count($bbCategoryIds) . " categories\n";

// Step 2: Import products
echo "  Importing products...\n";
$imported = 0;
$importedSkus = [];
$errors = [];
$batchSize = 200;
$batch = 0;

$db->beginTransaction();

foreach ($productsToImport as $sku => $product) {
    try {
        $db->exec('SAVEPOINT product_import');

        // Resolve category
        $mageCatId = resolveProductCategory(
            $product['mage_entity_id'], $categoryProducts, $catCache
        );
        $categoryId = ($mageCatId && isset($bbCategoryIds[$mageCatId]))
            ? $bbCategoryIds[$mageCatId]
            : Import::ensureCategory('uncategorized', $db);

        // Process images: copy from media dir if available
        $processedImages = processImages($product['images'], $mediaPath);

        // Build filter tags
        $filterTags = Import::buildFilterTagsFromAttrs($product['attrs']);

        // UPSERT product
        Import::upsertProduct([
            'sku'           => $product['sku'],
            'slug'          => $product['slug'],
            'product_type'  => $product['product_type'],
            'name'          => $product['name'],
            'category_id'   => $categoryId,
            'price'         => $product['price'],
            'compare_price' => $product['compare_price'],
            'stock'         => $product['stock'],
            'status'        => $product['status'],
            'summary'       => $product['summary'],
            'description'   => $product['description'],
            'images'        => $processedImages,
            'attrs'         => $product['attrs'],
            'tags'          => [],
            'filter_tags'   => $filterTags,
            'weight_g'      => $product['weight_g'],
            'translations'  => $product['translations'],
        ], $db);

        // Get product ID for attrs/variants
        $stmt = $db->prepare("SELECT id FROM products WHERE sku = :sku");
        $stmt->execute([':sku' => $sku]);
        $productId = (int)$stmt->fetchColumn();

        // Sync product attributes
        if (!empty($product['attrs'])) {
            Import::syncProductAttrs($productId, $product['attrs'], $db);
        }

        // Register slug
        Import::registerSlug($product['slug'], 'sk', 'product', $productId, $db);
        foreach ($product['translations'] as $locale => $tr) {
            if (!empty($tr['slug'])) {
                Import::registerSlug($tr['slug'], $locale, 'product', $productId, $db);
            }
        }

        // Import variants
        if (isset($variantsToImport[$sku])) {
            $sortOrder = 0;
            foreach ($variantsToImport[$sku] as $variant) {
                $sortOrder++;

                if (Db::isSqlite()) {
                    $vstmt = $db->prepare("
                        INSERT INTO variants (product_id, sku, name, attrs, price_delta, price_absolute, stock, sort_order)
                        VALUES (:pid, :sku, :name, :attrs, :delta, :abs, :stock, :sort)
                        ON CONFLICT(sku) DO UPDATE SET
                            name = excluded.name,
                            attrs = excluded.attrs,
                            price_delta = excluded.price_delta,
                            price_absolute = excluded.price_absolute,
                            stock = excluded.stock,
                            sort_order = excluded.sort_order
                    ");
                } else {
                    $vstmt = $db->prepare("
                        INSERT INTO variants (product_id, sku, name, attrs, price_delta, price_absolute, stock, sort_order)
                        VALUES (:pid, :sku, :name, :attrs, :delta, :abs, :stock, :sort)
                        ON DUPLICATE KEY UPDATE
                            name = VALUES(name),
                            attrs = VALUES(attrs),
                            price_delta = VALUES(price_delta),
                            price_absolute = VALUES(price_absolute),
                            stock = VALUES(stock),
                            sort_order = VALUES(sort_order)
                    ");
                }

                $vstmt->execute([
                    ':pid'   => $productId,
                    ':sku'   => $variant['sku'],
                    ':name'  => $variant['name'],
                    ':attrs' => json_encode($variant['attrs']),
                    ':delta' => $variant['price_delta'],
                    ':abs'   => $variant['price_absolute'] ?? null,
                    ':stock' => $variant['stock'],
                    ':sort'  => $sortOrder,
                ]);
            }
        }

        $db->exec('RELEASE SAVEPOINT product_import');
        $importedSkus[] = $sku;
        $imported++;
        $batch++;

        if ($batch >= $batchSize) {
            $db->commit();
            $db->beginTransaction();
            $batch = 0;
            echo "    ... {$imported} products imported\n";
        }
    } catch (\Throwable $e) {
        $db->exec('ROLLBACK TO SAVEPOINT product_import');
        $errors[] = "[{$sku}]: " . $e->getMessage();
    }
}

if ($db->inTransaction()) $db->commit();

// Checkout reserves from the warehouse ledger: products.stock alone shows "in stock" but cannot be bought.
echo "  Booking stock into the default warehouse...\n";
$db->beginTransaction();
if (!Warehouse::defaultWarehouse()) Warehouse::save(['code' => 'magento', 'name' => 'Magento import', 'is_default' => 1]);
foreach ($importedSkus as $sku) {
    $quantities = [$sku => $productsToImport[$sku]['stock']] + array_column($variantsToImport[$sku] ?? [], 'stock', 'sku');
    foreach ($quantities as $stockSku => $qty) Warehouse::setAvailable((string)$stockSku, max(0, (int)$qty), 'import', 'magento_import');
}
Warehouse::syncProductStock();
$db->commit();

// Recount categories
echo "  Recounting category products...\n";
Import::recountCategories($db);

echo "\n===========================================\n";
echo " Migration complete!\n";
echo "===========================================\n";
echo "  Products imported: {$imported}\n";
echo "  Variants imported: " . array_sum(array_map('count', $variantsToImport)) . "\n";
echo "  Categories: " . count($bbCategoryIds) . "\n";
echo "  Errors: " . count($errors) . "\n";

if (!empty($errors)) {
    echo "\nErrors (first 20):\n";
    foreach (array_slice($errors, 0, 20) as $err) {
        echo "  {$err}\n";
    }
    if (count($errors) > 20) {
        echo "  ... and " . (count($errors) - 20) . " more\n";
    }
}

// ============================================================
// Image processing
// ============================================================
/**
 * Magento images (/h/e/hello.jpg under media/catalog/product) become uploads under storage path magento/h/e/hello.jpg,
 * stored without their EXIF/GPS metadata and queued for the resized copies; the product keeps the storage URL the
 * storefront serves. Without --media the URL points where the files are expected to be copied later.
 */
function processImages(array $magePaths, ?string $mediaBasePath): array {
    $storage = Adapters::storage();
    $processed = [];
    foreach ($magePaths as $magePath) {
        $relative = 'magento/' . ltrim(str_replace('\\', '/', $magePath), '/');
        if (!preg_match('#^magento/(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+\.(?:jpe?g|png|gif|webp)$#i', $relative) || str_contains($relative, '..')) continue;
        $source = $mediaBasePath ? rtrim($mediaBasePath, '/\\') . '/' . substr($relative, strlen('magento/')) : '';
        if ($source !== '' && is_file($source)) {
            $bytes = file_get_contents($source);
            if ($bytes === false) continue;
            $processed[] = $storage->store($relative, ImageMetadata::strip($bytes));
            JobQueue::push('image_resize', ['path' => $relative], 3);
        } else {
            $processed[] = $storage->url($relative, 'full');
        }
    }
    return $processed;
}

// ============================================================
// MySQL INSERT VALUES parser
// ============================================================
function parseMysqlValues(string $valuesPart, array $columns): array {
    $rows = [];
    $len = strlen($valuesPart);
    $pos = 0;

    while ($pos < $len) {
        // Skip to opening paren
        while ($pos < $len && $valuesPart[$pos] !== '(') $pos++;
        if ($pos >= $len) break;
        $pos++; // skip '('

        // Parse one row of values
        $values = [];
        $colIdx = 0;

        while ($pos < $len && $valuesPart[$pos] !== ')') {
            // Skip whitespace
            while ($pos < $len && $valuesPart[$pos] === ' ') $pos++;

            if ($valuesPart[$pos] === '\'') {
                // Quoted string
                $pos++; // skip opening quote
                $val = '';
                while ($pos < $len) {
                    $ch = $valuesPart[$pos];
                    if ($ch === '\\' && $pos + 1 < $len) {
                        $next = $valuesPart[$pos + 1];
                        if ($next === '\'') { $val .= '\''; $pos += 2; continue; }
                        if ($next === '\\') { $val .= '\\'; $pos += 2; continue; }
                        if ($next === 'n') { $val .= "\n"; $pos += 2; continue; }
                        if ($next === 'r') { $val .= "\r"; $pos += 2; continue; }
                        if ($next === 't') { $val .= "\t"; $pos += 2; continue; }
                        if ($next === '0') { $val .= "\0"; $pos += 2; continue; }
                        $val .= $next; $pos += 2; continue;
                    }
                    if ($ch === '\'') {
                        $pos++; // skip closing quote
                        break;
                    }
                    $val .= $ch;
                    $pos++;
                }
                $values[] = $val;
            } elseif (strtoupper(substr($valuesPart, $pos, 4)) === 'NULL') {
                $values[] = null;
                $pos += 4;
            } else {
                // Unquoted value (number)
                $start = $pos;
                while ($pos < $len && $valuesPart[$pos] !== ',' && $valuesPart[$pos] !== ')') {
                    $pos++;
                }
                $values[] = substr($valuesPart, $start, $pos - $start);
            }

            // Skip comma between values
            if ($pos < $len && $valuesPart[$pos] === ',') $pos++;
        }

        // Skip closing paren
        if ($pos < $len && $valuesPart[$pos] === ')') $pos++;

        // Skip comma/semicolon between rows
        while ($pos < $len && ($valuesPart[$pos] === ',' || $valuesPart[$pos] === ';' || $valuesPart[$pos] === ' ' || $valuesPart[$pos] === "\n")) {
            $pos++;
        }

        // Map values to column names
        if (count($values) >= count($columns)) {
            $row = [];
            foreach ($columns as $i => $col) {
                $row[$col] = $values[$i] ?? null;
            }
            $rows[] = $row;
        } elseif (count($values) > 0) {
            // Partial row — map what we can
            $row = [];
            foreach ($columns as $i => $col) {
                $row[$col] = $values[$i] ?? null;
            }
            $rows[] = $row;
        }
    }

    return $rows;
}
