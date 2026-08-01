<?php
require_once __DIR__ . '/cli-only.php';

/**
 * Extract product specifications from names/descriptions into product_specifications.
 * Run: php cli/extract-specs.php
 *
 * Discovered specs become sidebar filters automatically via TecDoc::specFilterValues().
 */

require __DIR__ . '/../src/Db.php';

$db = new PDO('sqlite:' . __DIR__ . '/../db/shop.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Clear existing extracted specs (keep manually-added ones if any)
$db->exec("DELETE FROM product_specifications WHERE lang = 'sk'");

$insert = $db->prepare("
    INSERT INTO product_specifications (product_id, criteria_name, criteria_value, numeric_val, lang)
    VALUES (:pid, :name, :value, :num, :lang)
");

$products = $db->query("
    SELECT p.id, p.name, p.description, p.attrs, c.path as cat_path, c.name as cat_name
    FROM products p
    JOIN categories c ON c.id = p.category_id
    WHERE p.status = 'active'
")->fetchAll(PDO::FETCH_ASSOC);

$stats = ['total' => 0, 'power' => 0, 'brand' => 0, 'type' => 0, 'controller' => 0, 'placement' => 0, 'material' => 0, 'color' => 0];

function addSpec(PDOStatement $insert, int $pid, string $name, string $value, ?float $numeric = null): void {
    global $stats;
    $insert->execute([
        ':pid'   => $pid,
        ':name'  => $name,
        ':value' => $value,
        ':num'   => $numeric,
        ':lang'  => 'sk',
    ]);
    $stats['total']++;
}

// Known brand patterns
$brands = [
    'Harvia', 'SENTIOTEC', 'Sentiotec', 'SAWO', 'Sawo', 'DragonFire',
    'Helo', 'Tylö', 'EOS', 'Narvi', 'Kastor', 'Fiber', 'Scandia',
    'Aries', 'Fénix', 'Tower', 'Concept', 'Home', 'Mini', 'Wall',
];

foreach ($products as $p) {
    $name = $p['name'];
    $desc = $p['description'] ?? '';
    $catPath = $p['cat_path'] ?? '';
    $pid = (int)$p['id'];

    // === Power (kW) — from name, then description ===
    $kw = null;
    if (preg_match('/(\d+[.,]?\d*)\s*kW/i', $name, $m)) {
        $kw = (float)str_replace(',', '.', $m[1]);
    } elseif (preg_match('/výkon[:\s]*(\d+[.,]?\d*)\s*kW/i', $desc, $m)) {
        $kw = (float)str_replace(',', '.', $m[1]);
    }
    if ($kw !== null && $kw > 0 && $kw < 100) {
        addSpec($insert, $pid, 'Výkon (kW)', str_replace(',', '.', (string)$kw) . ' kW', $kw);
        $stats['power']++;
    }

    // === Brand — first known brand found in name ===
    $brand = null;
    // Special patterns first
    if (preg_match('/\b(Harvia|SENTIOTEC|Sentiotec|SAWO|Sawo|DragonFire|Helo|Tylö|EOS|Narvi|Kastor)\b/i', $name, $m)) {
        $brand = ucfirst(strtolower($m[1]));
        // Fix casing
        $brandMap = ['Sentiotec' => 'Sentiotec', 'Sawo' => 'Sawo', 'Dragonfire' => 'DragonFire', 'Eos' => 'EOS'];
        $brand = $brandMap[$brand] ?? $brand;
    }
    if ($brand) {
        addSpec($insert, $pid, 'Značka', $brand, null);
        $stats['brand']++;
    }

    // Only extract stove-specific specs for stove categories
    if (str_starts_with($catPath, 'saunove-pece')) {

        // === Stove type ===
        $type = null;
        if (stripos($name, 'combi') !== false || stripos($name, 'výparník') !== false) {
            $type = 'Kombi (s výparníkom)';
        } elseif (stripos($name, 'parná') !== false || stripos($name, 'parn') !== false || stripos($name, 'geyser') !== false) {
            $type = 'Parná';
        }
        if ($type) {
            addSpec($insert, $pid, 'Typ pece', $type, null);
            $stats['type']++;
        }

        // === Controller ===
        if (stripos($name, 's ovládačom') !== false || stripos($name, 's ovládacom') !== false) {
            addSpec($insert, $pid, 'Ovládač', 'Vstavaný', null);
            $stats['controller']++;
        } elseif (preg_match('/\bE\b/', $name) && stripos($catPath, 'elektricke') !== false) {
            // "E" suffix typically means external controller needed
            addSpec($insert, $pid, 'Ovládač', 'Externý (nutné dokúpiť)', null);
            $stats['controller']++;
        }

        // === Placement ===
        if (stripos($name, 'rohov') !== false || stripos($name, 'corner') !== false) {
            addSpec($insert, $pid, 'Umiestnenie', 'Rohová', null);
            $stats['placement']++;
        } elseif (stripos($name, 'wall') !== false && stripos($name, 'wall tower') === false) {
            addSpec($insert, $pid, 'Umiestnenie', 'Nástenná', null);
            $stats['placement']++;
        } elseif (stripos($name, 'tower') !== false) {
            addSpec($insert, $pid, 'Umiestnenie', 'Tower (stĺpová)', null);
            $stats['placement']++;
        }

        // === Volume from description ===
        if (preg_match('/(\d+[.,]?\d*)\s*[-–]\s*(\d+[.,]?\d*)\s*m[³3]/iu', $desc, $m)) {
            $volMin = (float)str_replace(',', '.', $m[1]);
            $volMax = (float)str_replace(',', '.', $m[2]);
            addSpec($insert, $pid, 'Objem sauny (m³)', "{$volMin}-{$volMax} m³", $volMax);
        }

        // === Stone capacity from description ===
        if (preg_match('/kapacita\s+kameňov[:\s]*(\d+)\s*kg/i', $desc, $m)) {
            addSpec($insert, $pid, 'Kapacita kameňov', $m[1] . ' kg', (float)$m[1]);
        }
    }

    // === Material (from attrs JSON) ===
    $attrs = json_decode($p['attrs'] ?? '{}', true);
    if (!empty($attrs['wood'])) {
        addSpec($insert, $pid, 'Materiál', $attrs['wood'], null);
        $stats['material']++;
    }

    // === Color from name (all Slovak grammatical forms + English) ===
    $colors = [
        'čierna' => 'Čierna', 'čierny' => 'Čierna', 'čierne' => 'Čierna', 'čiernej' => 'Čierna', 'black' => 'Čierna',
        'biela' => 'Biela', 'biely' => 'Biela', 'biele' => 'Biela', 'bielej' => 'Biela', 'white' => 'Biela',
        'červen' => 'Červená', 'red' => 'Červená',
        'modra' => 'Modrá', 'modrá' => 'Modrá', 'modrý' => 'Modrá', 'modré' => 'Modrá', 'blue' => 'Modrá',
        'sivá' => 'Sivá', 'sivý' => 'Sivá', 'sivé' => 'Sivá', 'grey' => 'Sivá', 'gray' => 'Sivá',
        'ružov' => 'Ružová', 'pink' => 'Ružová',
        'nerez' => 'Nerez', 'steel' => 'Nerez',
    ];
    foreach ($colors as $pattern => $colorName) {
        if (stripos($name, $pattern) !== false) {
            addSpec($insert, $pid, 'Farba', $colorName, null);
            $stats['color']++;
            break;
        }
    }
}

echo "=== Spec extraction complete ===\n";
echo "Total specs inserted: {$stats['total']}\n";
echo "Power (kW): {$stats['power']}\n";
echo "Brand: {$stats['brand']}\n";
echo "Stove type: {$stats['type']}\n";
echo "Controller: {$stats['controller']}\n";
echo "Placement: {$stats['placement']}\n";
echo "Material: {$stats['material']}\n";
echo "Color: {$stats['color']}\n";

// Verify
$count = $db->query("SELECT COUNT(*) FROM product_specifications")->fetchColumn();
$distinct = $db->query("SELECT COUNT(DISTINCT criteria_name) FROM product_specifications")->fetchColumn();
$products_with = $db->query("SELECT COUNT(DISTINCT product_id) FROM product_specifications")->fetchColumn();
echo "\nDB check: {$count} specs, {$distinct} criteria types, {$products_with} products with specs\n";
