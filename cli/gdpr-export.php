<?php
/**
 * BareBones Shop — GDPR Subject Access Request (SAR) export CLI
 *
 * Usage:
 *   php cli/gdpr-export.php --customer-id=42
 *   php cli/gdpr-export.php --email=alice@example.com
 *   php cli/gdpr-export.php --customer-id=42 --out=storage/gdpr-exports/42.json
 *   php cli/gdpr-export.php --customer-id=42 --pretty
 *
 * Exit codes:
 *   0 success
 *   1 customer not found / multiple matches
 *   2 invalid args
 *   3 write failed
 */
require_once __DIR__ . '/../src/autoload.php';

$argvLocal = $argv ?? [];
$opts = ['customer_id' => null, 'email' => null, 'out' => null, 'pretty' => false, 'actor' => 'admin'];
foreach (array_slice($argvLocal, 1) as $a) {
    if (str_starts_with($a, '--customer-id=')) { $opts['customer_id'] = (int)substr($a, 14); continue; }
    if (str_starts_with($a, '--email='))       { $opts['email']       = substr($a, 8);       continue; }
    if (str_starts_with($a, '--out='))         { $opts['out']         = substr($a, 6);       continue; }
    if (str_starts_with($a, '--actor='))       { $opts['actor']       = substr($a, 8);       continue; }
    if ($a === '--pretty') { $opts['pretty'] = true; continue; }
    fwrite(STDERR, "Unknown arg: {$a}\n");
    exit(2);
}

if (!$opts['customer_id'] && !$opts['email']) {
    fwrite(STDERR, "Must specify --customer-id=N or --email=ADDR\n");
    exit(2);
}

$db = Db::get();

// Resolve customer_id from email if needed
$cid = $opts['customer_id'];
if ($cid === null) {
    $stmt = $db->prepare("SELECT id FROM customers WHERE LOWER(email) = LOWER(?)");
    $stmt->execute([$opts['email']]);
    $matches = $stmt->fetchAll();
    if (count($matches) === 0) {
        fwrite(STDERR, "No customer found with email {$opts['email']}\n");
        exit(1);
    }
    if (count($matches) > 1) {
        fwrite(STDERR, "Multiple customers match {$opts['email']} — specify --customer-id explicitly\n");
        foreach ($matches as $m) fwrite(STDERR, "  id={$m['id']}\n");
        exit(1);
    }
    $cid = (int)$matches[0]['id'];
}

try {
    $data = GdprService::exportCustomer($cid);
} catch (Throwable $e) {
    fwrite(STDERR, "Export failed: " . $e->getMessage() . "\n");
    exit(1);
}

$flags = $opts['pretty'] ? (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$json = json_encode($data, $flags);
if ($json === false) {
    fwrite(STDERR, "JSON encode failed\n");
    exit(3);
}

// Audit
GdprService::logEvent([
    'action'      => 'export',
    'customer_id' => $cid,
    'email'       => (string)($data['customer']['email'] ?? ''),
    'actor'       => $opts['actor'],
    'note'        => 'cli/gdpr-export.php',
    'payload'     => ['orders' => count($data['orders']), 'addresses' => count($data['addresses'])],
]);

if ($opts['out']) {
    $outPath = $opts['out'];
    $dir = dirname($outPath);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    if (file_put_contents($outPath, $json) === false) {
        fwrite(STDERR, "Cannot write to {$outPath}\n");
        exit(3);
    }
    @chmod($outPath, 0640);
    echo "Wrote " . strlen($json) . " bytes to {$outPath}\n";
    echo "  customer_id={$cid} orders=" . count($data['orders']) . " addresses=" . count($data['addresses']) . "\n";
} else {
    echo $json;
    if ($opts['pretty']) echo "\n";
}
exit(0);
