<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — CLI Import
 * Usage: php cli/import.php path/to/products.csv [--type clothing]
 */
require_once __DIR__ . '/../src/autoload.php';

$argv = $_SERVER['argv'] ?? [];
$file = $argv[1] ?? null;
$type = null;

// Parse --type flag
for ($i = 2; $i < ($argc ?? 0); $i++) {
    if ($argv[$i] === '--type' && isset($argv[$i + 1])) {
        $type = $argv[$i + 1];
        $i++;
    }
}

if (!$file || !file_exists($file)) {
    echo "Usage: php cli/import.php <file.csv> [--type <product_type>]\n";
    echo "  file: CSV or JSON file to import\n";
    echo "  --type: Product type override (e.g. clothing, electronics)\n";
    exit(1);
}

echo "BareBones Shop — Import\n";
echo "=======================\n";
echo "File: {$file}\n";
if ($type) echo "Type: {$type}\n";
echo "\n";

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

if ($ext === 'csv') {
    $result = Import::csv($file, $type);
} elseif ($ext === 'json') {
    $data = json_decode(file_get_contents($file), true);
    if (!$data) {
        echo "Error: Invalid JSON file.\n";
        exit(1);
    }
    $result = Import::csv($file, $type); // Import handles both via file extension
} else {
    echo "Error: Unsupported file format '{$ext}'. Use CSV or JSON.\n";
    exit(1);
}

echo "Imported: {$result['imported']} products\n";
if (!empty($result['errors'])) {
    echo "Errors:\n";
    foreach ($result['errors'] as $err) {
        echo "  - {$err}\n";
    }
}

// Recount categories
echo "\nRecounting categories...\n";
Import::recountCategories();
echo "Done.\n";
