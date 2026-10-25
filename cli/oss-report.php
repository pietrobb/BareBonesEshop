<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — OSS (One-Stop-Shop) report
 *
 * Generates EU OSS quarterly report for cross-border B2C sales.
 * Aggregates per (consumption_country, vat_rate, currency) over a quarter.
 *
 * Source of truth: invoices with vat_scenario='oss'. New invoices carry the
 * exact checkout financial snapshot; historical invoices use their immutable
 * vat_breakdown. Current rates and raw order items are never recomputed.
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

if (!defined('BB_TEST_LOAD_OSS_ONLY')) {
$argv = $_SERVER['argv'] ?? [];
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
}

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

    // Per-store filter via the number series (e.g. invoices "DE-20260001", credit notes "DE-CN-20260001"). The
    // credit notes of an unprefixed series ("CN-2026…") contain a hyphen, so they are matched by their own prefix.
    if ($storeCode !== null) {
        $stores = json_decode((string)@file_get_contents(DataOverrides::storesPath()), true);
        $legal = $stores['stores'][$storeCode]['legal'] ?? [];
        $legal = is_array($legal) ? $legal : [];
        $prefix = InvoiceService::numberPrefix($legal, 'invoice');
        $invoiceSeries = $prefix === '' ? "i.number NOT LIKE '%-%'" : 'i.number LIKE :prefix';
        if ($prefix !== '') $params[':prefix'] = $prefix . '%';
        $where .= " AND ((COALESCE(i.type, 'invoice') <> 'credit_note' AND {$invoiceSeries}) OR (i.type = 'credit_note' AND i.number LIKE :cn_prefix))";
        $params[':cn_prefix'] = InvoiceService::numberPrefix($legal, 'credit_note') . '%';
    }

    $sql = "
        SELECT i.id, i.number, i.issued_at, i.currency, i.customer_snapshot,
               i.vat_breakdown, i.financial_snapshot, i.type
        FROM invoices i
        $where
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $bucket = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $customerSnapshot = json_decode((string)$row['customer_snapshot'], true) ?: [];
        $cc = strtoupper(trim((string)($customerSnapshot['country'] ?? '')));
        if ($cc === '') continue;

        $financialJson = trim((string)($row['financial_snapshot'] ?? ''));
        $creditNote = (string)($row['type'] ?? 'invoice') === 'credit_note';
        if ($creditNote) {
            // A credit note carries its source invoice's snapshot for reference; what it credits is its own
            // breakdown, and it always reduces the period's OSS base and VAT.
            $rates = [];
            foreach ((json_decode((string)($row['vat_breakdown'] ?? '{}'), true) ?: []) as $rateKey => $group) {
                $rates[$rateKey] = ['net' => -abs((int)($group['net'] ?? 0)), 'vat' => -abs((int)($group['vat'] ?? 0))];
            }
        } elseif ($financialJson !== '') {
            $financialSnapshot = json_decode($financialJson, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($financialSnapshot)) throw new RuntimeException('Invoice financial snapshot is invalid');
            $taxProvider = Adapters::tax((string)($financialSnapshot['providers']['tax'] ?? Adapters::defaultProviderCode('tax')));
            $taxProvider::validateSnapshot($financialSnapshot);
            $rates = $financialSnapshot['breakdown'];
        } else {
            // Historical invoices remain reportable from their immutable invoice
            // breakdown; no current rate lookup or order-item recomputation occurs.
            $rates = json_decode((string)($row['vat_breakdown'] ?? '{}'), true) ?: [];
        }
        $cur = strtolower((string)($row['currency'] ?? 'eur'));
        foreach ($rates as $rateKey => $group) {
            $rate = (float)$rateKey;
            $key = "{$cc}|{$rate}|{$cur}";
            if (!isset($bucket[$key])) {
                $bucket[$key] = [
                    'consumption_country' => $cc,
                    'vat_rate' => $rate,
                    'currency' => $cur,
                    'net' => 0,
                    'vat' => 0,
                    'gross' => 0,
                    'invoice_ids' => [],
                ];
            }
            $net = (int)($group['net'] ?? 0);
            $vat = (int)($group['vat'] ?? 0);
            $bucket[$key]['net'] += $net;
            $bucket[$key]['vat'] += $vat;
            $bucket[$key]['gross'] += $net + $vat;
            $bucket[$key]['invoice_ids'][(int)$row['id']] = true;
        }
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
