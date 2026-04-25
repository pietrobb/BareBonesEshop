<?php
/**
 * BareBones Shop — OSS (One-Stop-Shop) report
 *
 * Generates EU OSS quarterly report for cross-border B2C sales.
 * Aggregates per (consumption_country, vat_rate, currency) over a quarter.
 *
 * Source of truth: invoices with vat_scenario='oss', joined with order_items.
 * Why invoices, not orders: invoices snapshot the legally-binding VAT data
 * frozen at issuance time (per ESHOP-DESIGN immutability rule).
 *
 * Usage:
 *   php cli/oss-report.php --quarter=2026-Q2
 *   php cli/oss-report.php --quarter=2026-Q2 --format=csv > oss-2026-Q2.csv
 *   php cli/oss-report.php --quarter=2026-Q2 --format=json
 *   php cli/oss-report.php --from=2026-04-01 --to=2026-06-30
 *   php cli/oss-report.php --quarter=2026-Q2 --store=de
 *
 * Output (text default): human-readable table.
 * Output (csv): consumption_country,vat_rate,currency,net,vat,gross,invoice_count
 * Output (json): same shape as array.
 *
 * Exit codes:
 *   0 OK
 *   1 bad arguments / no data
 *   2 DB error
 */

require __DIR__ . '/../src/autoload.php';

$opts = parseArgs($argv);
if (!empty($opts['help'])) { printHelp(); exit(0); }

[$from, $to] = resolveRange($opts);
if (!$from || !$to) {
    fwrite(STDERR, "Error: must specify --quarter=YYYY-QN or --from=YYYY-MM-DD --to=YYYY-MM-DD\n");
    exit(1);
}

$format    = strtolower($opts['format'] ?? 'text');
$storeCode = $opts['store'] ?? null;

try {
    $rows = collect($from, $to, $storeCode);
} catch (\Throwable $e) {
    fwrite(STDERR, "DB error: " . $e->getMessage() . "\n");
    exit(2);
}

switch ($format) {
    case 'csv':  emitCsv($rows);                break;
    case 'json': emitJson($rows, $from, $to);   break;
    case 'text':
    default:     emitText($rows, $from, $to, $storeCode); break;
}

if (empty($rows)) exit(1);
exit(0);

// ---------------------------------------------------------------------------

function parseArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $a) {
        if ($a === '--help' || $a === '-h') { $out['help'] = true; continue; }
        if (preg_match('/^--([a-z_]+)=(.*)$/', $a, $m)) { $out[$m[1]] = $m[2]; continue; }
        if (preg_match('/^--([a-z_]+)$/',     $a, $m)) { $out[$m[1]] = true;  continue; }
    }
    return $out;
}

function printHelp(): void
{
    echo "OSS (One-Stop-Shop) report — cross-border B2C VAT aggregation\n";
    echo "\nUsage:\n";
    echo "  php cli/oss-report.php --quarter=YYYY-QN [--format=text|csv|json] [--store=CODE]\n";
    echo "  php cli/oss-report.php --from=YYYY-MM-DD --to=YYYY-MM-DD [...]\n";
    echo "\nDeterministic, read-only. Aggregates per (consumption_country, vat_rate, currency).\n";
}

function resolveRange(array $opts): array
{
    if (!empty($opts['quarter']) && preg_match('/^(\d{4})-Q([1-4])$/i', $opts['quarter'], $m)) {
        $year = (int)$m[1]; $q = (int)$m[2];
        $startMonth = ($q - 1) * 3 + 1;
        $from = sprintf('%04d-%02d-01', $year, $startMonth);
        $endMonth = $startMonth + 2;
        $to = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $endMonth)));
        return [$from, $to];
    }
    if (!empty($opts['from']) && !empty($opts['to'])) {
        return [$opts['from'], $opts['to']];
    }
    return [null, null];
}

/**
 * Aggregate per consumption_country × vat_rate × currency.
 *
 * Strategy:
 *   - JOIN invoices (issued_at in [from,to], vat_scenario='oss')
 *     with order_items via order_id.
 *   - GROUP BY (customer_country from invoice.customer_snapshot, vat_rate, currency).
 *
 * Reverse-charge / domestic / export scenarios are excluded — OSS reports
 * only cross-border B2C consumer sales.
 */
function collect(string $from, string $to, ?string $storeCode): array
{
    $db = Db::get();

    $where  = "WHERE i.issued_at BETWEEN :from AND :to AND i.vat_scenario = 'oss' AND i.status = 'issued'";
    $params = [':from' => $from, ':to' => $to];

    // Per-store filter via invoice number prefix (e.g. "DE-20260001").
    if ($storeCode !== null) {
        $stores = json_decode((string)@file_get_contents(dirname(__DIR__) . '/stores.json'), true);
        $prefix = $stores['stores'][$storeCode]['legal']['invoice_prefix'] ?? '';
        if ($prefix === '') {
            $where .= " AND i.number NOT LIKE '%-%'";
        } else {
            $where .= " AND i.number LIKE :prefix";
            $params[':prefix'] = $prefix . '%';
        }
    }

    $sql = "
        SELECT i.id, i.number, i.issued_at, i.currency, i.customer_snapshot,
               oi.vat_rate, oi.vat_amount, oi.net_amount
        FROM invoices i
        JOIN order_items oi ON oi.order_id = i.order_id
        $where
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $bucket = [];
    $invoiceIds = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $snap = json_decode((string)$row['customer_snapshot'], true) ?: [];
        $cc   = strtoupper(trim((string)($snap['country'] ?? '')));
        if ($cc === '') continue;

        $rate = (float)($row['vat_rate'] ?? 0);
        $cur  = strtolower((string)($row['currency'] ?? 'eur'));
        $key  = "{$cc}|{$rate}|{$cur}";

        if (!isset($bucket[$key])) {
            $bucket[$key] = [
                'consumption_country' => $cc,
                'vat_rate'            => $rate,
                'currency'            => $cur,
                'net'                 => 0,
                'vat'                 => 0,
                'gross'               => 0,
                'invoice_ids'         => [],
            ];
        }
        $bucket[$key]['net']   += (int)($row['net_amount'] ?? 0);
        $bucket[$key]['vat']   += (int)($row['vat_amount'] ?? 0);
        $bucket[$key]['gross'] += (int)($row['net_amount'] ?? 0) + (int)($row['vat_amount'] ?? 0);
        $bucket[$key]['invoice_ids'][(int)$row['id']] = true;
    }

    $rows = [];
    foreach ($bucket as $k => $b) {
        $b['invoice_count'] = count($b['invoice_ids']);
        unset($b['invoice_ids']);
        $rows[] = $b;
    }
    // Deterministic ordering for diff-friendly output.
    usort($rows, function($a, $b) {
        return [$a['consumption_country'], $a['vat_rate'], $a['currency']]
           <=> [$b['consumption_country'], $b['vat_rate'], $b['currency']];
    });
    return $rows;
}

function emitCsv(array $rows): void
{
    $out = fopen('php://stdout', 'w');
    fputcsv($out, ['consumption_country', 'vat_rate', 'currency', 'net_cents', 'vat_cents', 'gross_cents', 'invoice_count'], ',', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($out, [$r['consumption_country'], $r['vat_rate'], $r['currency'], $r['net'], $r['vat'], $r['gross'], $r['invoice_count']], ',', '"', '\\');
    }
    fclose($out);
}

function emitJson(array $rows, string $from, string $to): void
{
    echo json_encode([
        'from'         => $from,
        'to'           => $to,
        'rows'         => $rows,
        'totals'       => totals($rows),
        'generated_at' => date('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

function emitText(array $rows, string $from, string $to, ?string $storeCode): void
{
    echo "OSS Report — cross-border B2C VAT (vat_scenario=oss)\n";
    echo "Period: $from .. $to" . ($storeCode ? "  Store: $storeCode" : '') . "\n\n";
    if (empty($rows)) { echo "No OSS sales in period.\n"; return; }

    printf("%-3s  %-7s  %-3s  %12s  %12s  %12s  %5s\n",
        'CC', 'Rate', 'Cur', 'Net', 'VAT', 'Gross', 'Inv');
    echo str_repeat('-', 64) . "\n";
    foreach ($rows as $r) {
        printf("%-3s  %6.1f%%  %-3s  %12s  %12s  %12s  %5d\n",
            $r['consumption_country'], $r['vat_rate'], strtoupper($r['currency']),
            money($r['net']), money($r['vat']), money($r['gross']), $r['invoice_count']);
    }
    $t = totals($rows);
    echo str_repeat('-', 64) . "\n";
    printf("Totals (per currency):\n");
    foreach ($t as $cur => $sum) {
        printf("  %-3s  Net=%s  VAT=%s  Gross=%s  Inv=%d\n",
            strtoupper($cur), money($sum['net']), money($sum['vat']), money($sum['gross']), $sum['invoices']);
    }
}

function totals(array $rows): array
{
    $t = [];
    foreach ($rows as $r) {
        $cur = $r['currency'];
        if (!isset($t[$cur])) $t[$cur] = ['net' => 0, 'vat' => 0, 'gross' => 0, 'invoices' => 0];
        $t[$cur]['net']      += $r['net'];
        $t[$cur]['vat']      += $r['vat'];
        $t[$cur]['gross']    += $r['gross'];
        $t[$cur]['invoices'] += $r['invoice_count'];
    }
    return $t;
}

function money(int $cents): string
{
    return number_format($cents / 100, 2, '.', ' ');
}
