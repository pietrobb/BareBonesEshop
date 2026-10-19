<?php
require_once __DIR__ . '/cli-only.php';

/**
 * cli/vat-backfill.php — backfill VAT snapshot for legacy orders / order_items
 *
 * Default: --dry-run (read-only). Use --apply to write (takes pre-migration
 * backup via BackupService first).
 *
 * Strategy per order missing vat_scenario or vat_total:
 *   1. Detect customer country from shipping_addr.country (fallback: SK).
 *   2. Resolve scenario via VatService for ctx (seller_country from store,
 *      seller_oss_enabled, customer_country, no VAT ID assumed → B2C).
 *   3. For each order_item, compute per-line VAT from unit_price * qty:
 *        rate = VatService::rate(rate_country, vat_class, issued_at)
 *        net  = round(gross / (1 + rate/100))   (or gross if reverse-charge)
 *        vat  = gross - net
 *      Apply only if order_items lack vat_amount/net_amount.
 *   4. Aggregate vat_total/net_total back to orders.
 *
 * Usage:
 *   php cli/vat-backfill.php [--dry-run | --apply] [--limit=N] [--order-id=N]
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/../config.php';

$argv = $_SERVER['argv'] ?? [];
$args = array_slice($argv, 1);
$apply    = in_array('--apply',   $args, true);
$dryRun   = !$apply || in_array('--dry-run', $args, true);
$limit    = 0;
$orderOne = 0;
foreach ($args as $a) {
    if (strpos($a, '--limit=') === 0)    { $limit    = (int)substr($a, 8); }
    if (strpos($a, '--order-id=') === 0) { $orderOne = (int)substr($a, 11); }
}

if ($apply && $dryRun) {
    fwrite(STDERR, "Pass either --dry-run OR --apply, not both.\n");
    exit(1);
}

$db = Db::get();

// Pre-apply backup — only when actually writing
if ($apply) {
    BackupService::init($db);
    $meta = BackupService::create(['label' => 'vat-backfill', 'trigger' => 'manual']);
    fwrite(STDOUT, "[backup] {$meta['filename']} sha256=" . substr($meta['sha256'], 0, 12) . "\n");
}

$stores  = json_decode(@file_get_contents(__DIR__ . '/../stores.json') ?: '{}', true) ?: [];
$defaultStore = $stores['stores'][$stores['default_store'] ?? array_key_first($stores['stores'] ?? []) ?? ''] ?? [];
$sellerCountry = strtoupper((string)($defaultStore['legal']['country'] ?? 'SK'));
$sellerOss     = (bool)($defaultStore['oss_enabled'] ?? false);

$where = "(o.vat_scenario IS NULL OR o.vat_total IS NULL OR o.net_total IS NULL)";
if ($orderOne > 0) $where = "o.id = " . (int)$orderOne;
$lim = $limit > 0 ? "LIMIT " . (int)$limit : '';

$ordersStmt = $db->query("SELECT o.id, o.shipping_addr, o.subtotal, o.discount_amount, o.shipping_cost, o.tax_amount, o.total, o.currency, o.created_at FROM orders o WHERE {$where} ORDER BY o.id ASC {$lim}");
$orders = $ordersStmt->fetchAll();

echo "Found " . count($orders) . " orders to backfill" . ($dryRun ? " (DRY RUN — no writes)" : " — APPLYING") . "\n";
$touched = ['orders' => 0, 'items' => 0];

if ($apply) $db->beginTransaction();

try {
    foreach ($orders as $o) {
        $addr = json_decode((string)$o['shipping_addr'], true) ?: [];
        $cc   = strtoupper(trim((string)($addr['country'] ?? 'SK')));
        $sc   = VatService::resolveScenario([
            'seller_country'        => $sellerCountry,
            'seller_oss_enabled'    => $sellerOss,
            'customer_country'      => $cc,
            'customer_vat_id_valid' => false,
            'is_b2b'                => false,
        ]);
        // created_at is stored in UTC; the rate applies on the shop's local date of the sale.
        $createdAt = strtotime((string)$o['created_at'] . ' UTC');
        $issuedAt = $createdAt === false ? date('Y-m-d') : date('Y-m-d', $createdAt);

        // Per-line backfill
        $itemsStmt = $db->prepare("SELECT id, total, vat_class, vat_amount, net_amount FROM order_items WHERE order_id = ?");
        $itemsStmt->execute([$o['id']]);
        $items = $itemsStmt->fetchAll();

        $vatTotal = 0;
        $netTotal = 0;
        foreach ($items as $it) {
            $gross = (int)$it['total'];
            $vc    = (string)($it['vat_class'] ?? 'standard');
            $rate  = $sc['applies_vat']
                   ? VatService::rate($sc['rate_country'], $vc, $issuedAt)
                   : 0.0;
            $net   = $sc['applies_vat'] ? (int)round($gross / (1 + $rate / 100)) : $gross;
            $vat   = $gross - $net;
            $vatTotal += $vat;
            $netTotal += $net;

            // Backfill items whenever we are backfilling the order:
            // legacy rows often default to 0 instead of NULL. Skip only if a non-zero
            // VAT snapshot already exists (i.e. item was created post-VAT-feature).
            $needs = ((int)($it['vat_amount'] ?? 0) === 0 && (int)($it['net_amount'] ?? 0) === 0);
            if ($needs) {
                if ($apply) {
                    $up = $db->prepare("UPDATE order_items SET vat_class=?, vat_rate=?, vat_amount=?, net_amount=? WHERE id=?");
                    $up->execute([$vc, $rate, $vat, $net, $it['id']]);
                }
                $touched['items']++;
            }
        }

        if ($apply) {
            $up = $db->prepare("UPDATE orders SET vat_scenario=?, vat_total=?, net_total=? WHERE id=?");
            $up->execute([$sc['scenario'], $vatTotal, $netTotal, $o['id']]);
        }
        $touched['orders']++;
        printf("  order #%d → %s rate_country=%s vat=%d net=%d\n",
               $o['id'], $sc['scenario'], $sc['rate_country'], $vatTotal, $netTotal);
    }

    if ($apply) $db->commit();
} catch (\Throwable $e) {
    if ($apply && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nSummary: orders={$touched['orders']} items={$touched['items']}" . ($dryRun ? " (dry-run, nothing written)" : " (committed)") . "\n";
exit(0);
