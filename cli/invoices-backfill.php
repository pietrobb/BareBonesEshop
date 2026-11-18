<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — Invoices Backfill CLI
 *
 * Issues invoices for legacy paid/processing/shipped/delivered orders that
 * don't yet have one. Forward-only (existing invoices are never touched).
 * Then queues the credit notes of refunds booked on invoiced orders that have none (R44; setup doctor reports them).
 *
 * Usage:
 *   php cli/invoices-backfill.php                  # do it
 *   php cli/invoices-backfill.php --dry-run        # report only
 *   php cli/invoices-backfill.php --limit=100      # cap batch size
 *   php cli/invoices-backfill.php --status=paid    # narrow status (default: paid,processing,shipped,delivered)
 */
require_once __DIR__ . '/../src/autoload.php';

$argvLocal = $argv ?? [];
$dryRun = in_array('--dry-run', $argvLocal, true);
$limit  = 0;
$statusFilter = ['paid', 'processing', 'shipped', 'delivered'];
foreach ($argvLocal as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) $limit = (int)$m[1];
    if (preg_match('/^--status=(.+)$/', $a, $m)) $statusFilter = array_map('trim', explode(',', $m[1]));
}

$db = Db::get();
$placeholders = implode(',', array_fill(0, count($statusFilter), '?'));
// Every store's orders; an order whose invoice was cancelled gets a replacement (InvoiceService::issue()).
$sql = "SELECT o.id, o.order_number, o.status, o.total, o.currency, o.created_at, o.store_id
          FROM orders o
          LEFT JOIN invoices i ON i.order_id = o.id AND i.type = 'invoice' AND i.status <> 'cancelled'
         WHERE o.status IN ($placeholders)
           AND i.id IS NULL
         ORDER BY o.id";
if ($limit > 0) $sql .= " LIMIT " . (int)$limit;

$stmt = $db->prepare($sql);
$stmt->execute($statusFilter);
$candidates = $stmt->fetchAll();

$total = count($candidates);
echo "Backfill candidates: {$total}\n";
echo "Status filter: " . implode(',', $statusFilter) . "\n";
echo "Mode: " . ($dryRun ? "DRY-RUN (no writes)" : "LIVE") . "\n";
echo "----\n";

$ok = 0;
$fail = 0;
$failures = [];

foreach ($candidates as $row) {
    $oid = (int)$row['id'];
    $num = $row['order_number'];
    $st  = $row['status'];

    if ($dryRun) {
        echo "  [DRY] order #{$oid} ({$num}, status={$st})\n";
        $ok++;
        continue;
    }

    try {
        // Issued in the order's own store: its invoice row, number series and lookups are store-scoped.
        $inv = PaymentWebhookRouting::within((string)$row['store_id'], static fn(): array => InvoiceService::issue($oid));
        echo "  [OK]  order #{$oid} ({$num}) -> invoice {$inv['number']}\n";
        $ok++;
    } catch (\Throwable $e) {
        echo "  [ERR] order #{$oid} ({$num}): " . $e->getMessage() . "\n";
        $fail++;
        $failures[] = ['order_id' => $oid, 'error' => $e->getMessage()];
    }
}

echo "----\n";
echo "Summary: {$ok} ok, {$fail} failed (of {$total})\n";

// R44 (low 5): refunds booked on invoiced orders without their credit note (a failed queue push lost the job; setup doctor
// reports them) get it queued again, each in its order's store.
$uncredited = InvoiceService::uncreditedBookedRefunds($db);
echo "Refunds without a credit note: " . count($uncredited) . "\n";
$seen = [];
foreach ($uncredited as $refund) {
    if ($dryRun) {
        echo "  [DRY] order #{$refund['order_id']} ({$refund['order_number']}) refund {$refund['refund_key']}\n";
        continue;
    }
    if (isset($seen[$refund['store_id'] . '|' . $refund['order_id']])) continue;
    $seen[$refund['store_id'] . '|' . $refund['order_id']] = true;
    try {
        $queued = PaymentWebhookRouting::within($refund['store_id'], static fn(): int => InvoiceService::queueCreditsOfBookedRefunds($refund['order_id']));
        echo "  [OK]  order #{$refund['order_id']} ({$refund['order_number']}): {$queued} credit note job(s) queued\n";
    } catch (\Throwable $e) {
        echo "  [ERR] order #{$refund['order_id']} ({$refund['order_number']}): " . $e->getMessage() . "\n";
        $fail++;
        $failures[] = ['order_id' => $refund['order_id'], 'error' => $e->getMessage()];
    }
}

if ($failures && !$dryRun) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  order #{$f['order_id']}: {$f['error']}\n";
    }
    exit(1);
}

exit(0);
