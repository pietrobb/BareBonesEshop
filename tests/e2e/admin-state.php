<?php
/**
 * Read-only state probe for the admin catalog E2E spec (disposable E2E storage only).
 *   php tests/e2e/admin-state.php product [sku]   one physical product + its ledger and queued stock alerts
 *   php tests/e2e/admin-state.php catalog         every product row + ledger size
 *   php tests/e2e/admin-state.php page <slug>     CMS page row
 *   php tests/e2e/admin-state.php attribute <slug> attribute definition with options and categories
 *   php tests/e2e/admin-state.php edit-csv <in> <out> <sku> <column> <value>   copy a CSV with one cell changed
 */
require_once __DIR__ . '/../../cli/cli-only.php';
require_once __DIR__ . '/../../src/autoload.php';

$config = require dirname(__DIR__, 2) . '/config.php';
$driver = (string)($config['db_driver'] ?? '');
$safe = $driver === 'sqlite'
    ? str_contains(strtolower(str_replace('\\', '/', (string)($config['db_sqlite_path'] ?? ''))), 'barebones-e2e')
    : preg_match('/^[A-Za-z0-9_]+_e2e_test$/', (string)($config['db_name'] ?? '')) === 1;
if (!$safe) {
    throw new RuntimeException('Refusing to inspect a database that is not explicitly disposable E2E storage');
}

Bootstrap::initStoreContext('/', '127.0.0.1');
$db = Db::get();
$command = (string)($argv[1] ?? '');
$arg = (string)($argv[2] ?? '');

$movements = static function (string $sku = '') use ($db): array {
    $stmt = $db->prepare('SELECT movement_type, quantity_delta, reference_type FROM stock_movements' . ($sku !== '' ? ' WHERE sku = :sku' : '') . ' ORDER BY id');
    $stmt->execute($sku !== '' ? [':sku' => $sku] : []);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

switch ($command) {
    case 'product':
        if ($arg === '') {
            // A physical product that the checkout fixtures do not depend on (they use the largest stock).
            $digital = implode(',', array_map([$db, 'quote'], VariantAvailability::DIGITAL_TYPES));
            $arg = (string)$db->query("SELECT sku FROM products WHERE status = 'active' AND product_type NOT IN ({$digital}) AND stock >= 10
                ORDER BY stock ASC, id DESC LIMIT 1")->fetchColumn();
        }
        $stmt = $db->prepare('SELECT * FROM products WHERE sku = :sku');
        $stmt->execute([':sku' => $arg]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('No suitable product in the E2E catalog');
        $jobs = 0;
        $jobsDir = (string)($config['jobs_dir'] ?? dirname(__DIR__, 3) . '/bareboneseshop-private/jobs');
        foreach (glob(rtrim($jobsDir, '/\\') . '/pending/*.json') ?: [] as $file) {
            $job = json_decode((string)file_get_contents($file), true);
            if (($job['type'] ?? '') === 'stock_alert' && in_array($arg, (array)($job['payload']['skus'] ?? []), true)) $jobs++;
        }
        $result = ['sku' => $arg, 'row' => $row, 'movements' => $movements($arg), 'available' => Warehouse::totalAvailable($arg), 'stock_alert_jobs' => $jobs];
        break;
    case 'catalog':
        $rows = [];
        foreach ($db->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[$row['sku']] = $row;
        $result = ['products' => $rows, 'movements' => count($movements())];
        break;
    case 'page':
        $stmt = $db->prepare('SELECT * FROM pages WHERE slug = :slug');
        $stmt->execute([':slug' => $arg]);
        $result = ['row' => $stmt->fetch(PDO::FETCH_ASSOC) ?: null];
        break;
    case 'attribute':
        $stmt = $db->prepare('SELECT * FROM attribute_definitions WHERE slug = :slug');
        $stmt->execute([':slug' => $arg]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $options = $categories = [];
        if ($row) {
            $stmt = $db->prepare('SELECT value, sort_order FROM attribute_options WHERE attribute_id = :id ORDER BY sort_order, value');
            $stmt->execute([':id' => $row['id']]);
            $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $db->prepare('SELECT category_id FROM category_attributes WHERE attribute_id = :id ORDER BY category_id');
            $stmt->execute([':id' => $row['id']]);
            $categories = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        $result = ['row' => $row, 'options' => $options, 'categories' => $categories,
            'first_category' => (int)$db->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn()];
        break;
    case 'edit-csv':
        [, , $in, $out, $sku, $column, $value] = $argv + array_fill(0, 7, '');
        $source = fopen($in, 'rb');
        $header = fgetcsv($source, 0, ',', '"', '');
        $index = array_search($column, (array)$header, true);
        if ($index === false) throw new RuntimeException("CSV has no {$column} column");
        $target = fopen($out, 'wb');
        fputcsv($target, $header, ',', '"', '');
        $edited = 0;
        while (($line = fgetcsv($source, 0, ',', '"', '')) !== false) {
            if (($line[0] ?? '') === $sku) { $line[$index] = $value; $edited++; }
            fputcsv($target, $line, ',', '"', '');
        }
        fclose($source);
        fclose($target);
        $result = ['edited' => $edited];
        break;
    default:
        throw new InvalidArgumentException('Unknown command');
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
