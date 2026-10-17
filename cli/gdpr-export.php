<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — GDPR Subject Access Request (SAR) export CLI
 *
 * Usage:
 *   php cli/gdpr-export.php --customer-id=42
 *   php cli/gdpr-export.php --email=alice@example.com
 *   php cli/gdpr-export.php --email=alice@example.com --store=cz   (the e-mail has an account in several stores)
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
$opts = ['customer_id' => null, 'email' => null, 'store' => null, 'out' => null, 'pretty' => false, 'actor' => 'admin'];
foreach (array_slice($argvLocal, 1) as $a) {
    if (str_starts_with($a, '--customer-id=')) { $opts['customer_id'] = (int)substr($a, 14); continue; }
    if (str_starts_with($a, '--email='))       { $opts['email']       = substr($a, 8);       continue; }
    if (str_starts_with($a, '--store='))       { $opts['store']       = substr($a, 8);       continue; }
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

// Resolve the customer (id or email, optionally narrowed to --store); the export runs in the customer's store.
$where = $opts['customer_id'] !== null ? 'id = ?' : 'LOWER(email) = LOWER(?)';
$params = [$opts['customer_id'] ?? $opts['email']];
if ($opts['store'] !== null) {
    $where .= ' AND store_id = ?';
    $params[] = $opts['store'];
}
$stmt = $db->prepare("SELECT id, store_id FROM customers WHERE {$where} ORDER BY store_id, id");
$stmt->execute($params);
$matches = $stmt->fetchAll();
if (count($matches) === 0) {
    fwrite(STDERR, $opts['customer_id'] !== null ? "Customer #{$opts['customer_id']} not found\n" : "No customer found with email {$opts['email']}\n");
    exit(1);
}
if (count($matches) > 1) {
    fwrite(STDERR, "Multiple customers match {$opts['email']} — specify --store=ID or --customer-id explicitly\n");
    foreach ($matches as $m) fwrite(STDERR, "  id={$m['id']} store={$m['store_id']}\n");
    exit(1);
}
$cid = (int)$matches[0]['id'];
$storeId = (string)$matches[0]['store_id'];

try {
    $data = PaymentWebhookRouting::within($storeId, static fn(): array => GdprService::exportCustomer($cid));
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
PaymentWebhookRouting::within($storeId, static fn() => GdprService::logEvent([
    'action'      => 'export',
    'customer_id' => $cid,
    'email'       => (string)($data['customer']['email'] ?? ''),
    'actor'       => $opts['actor'],
    'note'        => 'cli/gdpr-export.php',
    'payload'     => ['orders' => count($data['orders']), 'addresses' => count($data['addresses'])],
]));

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
