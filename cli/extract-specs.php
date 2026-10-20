<?php
require_once __DIR__ . '/cli-only.php';

/**
 * Extract product specifications from names/descriptions into product_specifications (SpecExtraction).
 * Run: php cli/extract-specs.php — uses the configured database; replaces only previously extracted specs.
 */
require_once __DIR__ . '/../src/autoload.php';

$db = Db::get();
$stats = SpecExtraction::run($db);
foreach ($db->query('SELECT DISTINCT store_id FROM products')->fetchAll(PDO::FETCH_COLUMN) as $catalogId) Cache::catalogChanged((string)$catalogId);

echo "=== Spec extraction complete ===\n";
echo "Total specs inserted: {$stats['total']}\n";
echo "Kept (entered in the admin or imported): {$stats['kept']}\n";
echo "Power (kW): {$stats['power']}\n";
echo "Brand: {$stats['brand']}\n";
echo "Stove type: {$stats['type']}\n";
echo "Controller: {$stats['controller']}\n";
echo "Placement: {$stats['placement']}\n";
echo "Material: {$stats['material']}\n";
echo "Color: {$stats['color']}\n";

$count = $db->query("SELECT COUNT(*) FROM product_specifications")->fetchColumn();
$distinct = $db->query("SELECT COUNT(DISTINCT criteria_name) FROM product_specifications")->fetchColumn();
$productsWith = $db->query("SELECT COUNT(DISTINCT product_id) FROM product_specifications")->fetchColumn();
echo "\nDB check: {$count} specs, {$distinct} criteria types, {$productsWith} products with specs\n";
