<?php
/**
 * BareBones Shop — Schema Builder (init wrapper)
 *
 * Delegates schema (CREATE TABLE / ALTER / INDEX / FTS) to the Migrator
 * (cli/migrate.php). This script remains the single entry point that also
 * regenerates admin forms and CSV import templates from catalog.json + stores.json.
 *
 * Usage:
 *   php cli/schema-build.php          Apply pending migrations + regenerate forms/templates
 *   php cli/schema-build.php --fresh  DROP all tables, run all migrations from scratch (dev only!)
 */
require_once __DIR__ . '/../src/autoload.php';

$fresh = in_array('--fresh', $argv ?? []);

echo "BareBones Shop — Schema Builder\n";
echo "================================\n\n";

// Load JSON definitions (used for forms/templates regeneration)
$catalog = json_decode(file_get_contents(__DIR__ . '/../catalog.json'), true);
if (!$catalog) { echo "ERROR: Cannot read catalog.json\n"; exit(1); }

$stores = json_decode(file_get_contents(__DIR__ . '/../stores.json'), true);
if (!$stores) { echo "ERROR: Cannot read stores.json\n"; exit(1); }

echo "Loaded: " . count($catalog['product_types']) . " product types, "
    . count($stores['stores']) . " stores\n\n";

$db = Db::get();
$isSqlite = Db::isSqlite();

echo "Database: " . ($isSqlite ? 'SQLite' : 'MySQL') . "\n";

if ($fresh) {
    echo "FRESH mode: dropping all tables...\n";
    $tables = [
        'schema_migrations',
        'shipping_labels', 'shipping_rates',
        'password_reset_tokens', 'customer_addresses', 'customers',
        'abandoned_carts', 'coupon_uses', 'processed_events', 'email_log', 'payment_transactions', 'audit_log', 'order_items', 'orders',
        'product_vehicles', 'product_oe_numbers', 'product_specifications',
        'category_attributes', 'attribute_options', 'attribute_definitions',
        'pages', 'feeds', 'warehouse_stock', 'warehouses',
        'slug_registry', 'variants', 'product_attrs', 'products',
        'categories',
    ];
    if ($isSqlite) {
        $tables[] = 'products_fts';
        $tables[] = 'oe_fts';
    }
    foreach ($tables as $t) {
        $db->exec("DROP TABLE IF EXISTS {$t}");
    }
    echo "Done.\n\n";
}

// ============================================================
// Apply pending migrations
// ============================================================
echo "Applying pending migrations...\n";
$migrator = new Migrator($db);
$applied = $migrator->apply(function (string $msg): void { echo $msg . "\n"; });
$n = count($applied);
echo $n === 0 ? "  Schema is up to date.\n" : "  Applied {$n} migration(s).\n";
echo "\n";

// ============================================================
// Generate admin forms from catalog.json product types (GAP-H3)
// ============================================================
echo "Generating admin forms...\n";
$formsDir = __DIR__ . '/../forms';
if (!is_dir($formsDir)) mkdir($formsDir, 0750, true);

foreach ($catalog['product_types'] as $typeName => $typeDef) {
    $fields = [
        ['name' => 'sku', 'type' => 'text', 'label' => 'SKU', 'required' => true],
        ['name' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true, 'translatable' => true],
        ['name' => 'slug', 'type' => 'text', 'label' => 'URL Slug'],
        ['name' => 'category', 'type' => 'select', 'label' => 'Category', 'required' => true, 'options_from' => 'categories'],
        ['name' => 'price', 'type' => 'number', 'label' => 'Price (cents)', 'required' => true],
        ['name' => 'compare_price', 'type' => 'number', 'label' => 'Compare Price'],
        ['name' => 'stock', 'type' => 'number', 'label' => 'Stock', 'required' => true],
        ['name' => 'status', 'type' => 'select', 'label' => 'Status', 'options' => ['active', 'draft', 'archived']],
        ['name' => 'summary', 'type' => 'textarea', 'label' => 'Summary', 'translatable' => true],
        ['name' => 'description', 'type' => 'textarea', 'label' => 'Description', 'translatable' => true],
        ['name' => 'images', 'type' => 'file', 'label' => 'Images', 'multiple' => true],
        ['name' => 'weight_g', 'type' => 'number', 'label' => 'Weight (g)'],
        ['name' => 'tags', 'type' => 'text', 'label' => 'Tags (comma-separated)'],
    ];

    foreach ($typeDef['attributes'] ?? [] as $attrName => $attrDef) {
        $field = [
            'name'  => "attr:{$attrName}",
            'type'  => $attrDef['type'] ?? 'text',
            'label' => $attrDef['label'] ?? $attrName,
        ];
        if (!empty($attrDef['options']) && is_array($attrDef['options'])) {
            $field['options'] = $attrDef['options'];
        }
        if (!empty($attrDef['required'])) $field['required'] = true;
        if (!empty($attrDef['filterable'])) $field['filterable'] = true;
        if (!empty($attrDef['translatable'])) $field['translatable'] = true;
        $fields[] = $field;
    }

    $form = [
        'id'           => "admin-{$typeName}",
        'product_type' => $typeName,
        'has_variants'  => $typeDef['has_variants'] ?? false,
        'fields'       => $fields,
    ];
    if (!empty($typeDef['variant_dimensions'])) {
        $form['variant_dimensions'] = $typeDef['variant_dimensions'];
    }
    file_put_contents(
        "{$formsDir}/admin-{$typeName}.json",
        json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
    echo "  [OK] forms/admin-{$typeName}.json\n";
}

// ============================================================
// Generate import CSV templates per product type
// ============================================================
echo "\nGenerating import templates...\n";
$importDir = __DIR__ . '/../import';
if (!is_dir($importDir)) mkdir($importDir, 0750, true);

$allLangs = [];
foreach ($stores['stores'] ?? [] as $store) {
    foreach ($store['languages'] ?? [] as $l) $allLangs[$l] = true;
}
$defaultLocale = $stores['default_locale'] ?? 'en';

foreach ($catalog['product_types'] as $typeName => $typeDef) {
    $headers = ['sku', 'name', 'slug', 'category', 'price', 'compare_price', 'stock', 'status', 'summary', 'description', 'image', 'weight_g', 'tags'];

    foreach ($typeDef['attributes'] ?? [] as $attrName => $attrDef) {
        $headers[] = "attr:{$attrName}";
    }

    foreach (array_keys($allLangs) as $l) {
        if ($l !== $defaultLocale) {
            $headers[] = "name_{$l}";
            $headers[] = "summary_{$l}";
            $headers[] = "slug_{$l}";
        }
    }

    $fp = fopen("{$importDir}/template-{$typeName}.csv", 'w');
    fputcsv($fp, $headers);
    fclose($fp);
    echo "  [OK] import/template-{$typeName}.csv\n";
}

// ============================================================
// Summary
// ============================================================
echo "\nProduct types defined:\n";
foreach ($catalog['product_types'] as $typeName => $type) {
    $attrCount = count($type['attributes'] ?? []);
    $hasVariants = ($type['has_variants'] ?? false) ? 'yes' : 'no';
    echo "  - {$typeName}: {$attrCount} attributes, variants: {$hasVariants}\n";
}

echo "\nStores defined:\n";
foreach ($stores['stores'] as $code => $store) {
    $langs = implode(', ', $store['languages']);
    echo "  - {$code}: {$store['name']} ({$store['currency']}, langs: {$langs})\n";
}

echo "\nDone.\n";
