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
 *   2. Resolve scenario via VatService for ctx (seller_country and OSS of the
 *      order's own store, customer_country, no VAT ID assumed → B2C).
 *   3. For each order_item, compute per-line VAT from unit_price * qty:
 *        rate = VatService::rate(rate_country, vat_class, issued_at)
 *        net  = round(gross / (1 + rate/100))   (or gross if reverse-charge)
 *        vat  = gross - net
 *      Apply only if order_items lack vat_amount/net_amount.
 *   4. Aggregate vat_total/net_total back to orders over the whole order total: the order discount lowers the goods
 *      VAT in proportion, shipping and fees (the rest of the total) carry the standard rate; net + VAT = total.
 *
 * Usage:
 *   php cli/vat-backfill.php [--dry-run | --apply] [--limit=N] [--order-id=N]
 */

require_once __DIR__ . '/../src/autoload.php';

/** Seller country and OSS registration of the store that sold the order (unknown store: the default store). */
function vatBackfillSeller(array $stores, string $storeId): array
{
    $all = is_array($stores['stores'] ?? null) ? $stores['stores'] : [];
    $default = $all[$stores['default_store'] ?? ''] ?? (reset($all) ?: []);
    $store = $all[$storeId] ?? $default;
    // A store without its own legal block sells as the default store's seller.
    if (empty($store['legal']['country'])) $store = $default;
    $country = strtoupper((string)($store['legal']['country'] ?? 'SK'));
    return ['country' => $country !== '' ? $country : 'SK', 'oss' => (bool)($store['oss_enabled'] ?? false)];
}

/**
 * VAT snapshot of one legacy order.
 * @return array{scenario: string, rate_country: string, vat_total: int, net_total: int, items: array<int, array{vat_class: string, rate: float, vat: int, net: int}>}
 */
function vatBackfillOrder(array $order, array $items, array $seller): array
{
    $addr = json_decode((string)($order['shipping_addr'] ?? ''), true) ?: [];
    $cc   = strtoupper(trim((string)($addr['country'] ?? 'SK')));
    $sc   = VatService::resolveScenario([
        'seller_country'        => $seller['country'],
        'seller_oss_enabled'    => $seller['oss'],
        'customer_country'      => $cc,
        'customer_vat_id_valid' => false,
        'is_b2b'                => false,
    ]);
    // created_at is stored in UTC; the rate applies on the shop's local date of the sale.
    $createdAt = strtotime((string)($order['created_at'] ?? '') . ' UTC');
    $issuedAt = $createdAt === false ? date('Y-m-d') : date('Y-m-d', $createdAt);

    $lines = [];
    $goodsGross = 0;
    $goodsVat = 0;
    foreach ($items as $it) {
        $gross = (int)$it['total'];
        $vc    = (string)($it['vat_class'] ?? 'standard');
        $rate  = $sc['applies_vat'] ? VatService::rate($sc['rate_country'], $vc, $issuedAt) : 0.0;
        $net   = $sc['applies_vat'] ? (int)round($gross / (1 + $rate / 100)) : $gross;
        $lines[(int)$it['id']] = ['vat_class' => $vc, 'rate' => $rate, 'vat' => $gross - $net, 'net' => $net];
        $goodsGross += $gross;
        $goodsVat += $gross - $net;
    }
    $total = (int)($order['total'] ?? $goodsGross);
    $discount = max(0, min($goodsGross, (int)($order['discount_amount'] ?? 0)));
    $vatTotal = $goodsGross > 0 ? (int)round($goodsVat * ($goodsGross - $discount) / $goodsGross) : 0;
    $other = $total - ($goodsGross - $discount);
    if ($other !== 0 && $sc['applies_vat']) {
        $standard = VatService::rate($sc['rate_country'], 'standard', $issuedAt);
        $vatTotal += $other - (int)round($other / (1 + $standard / 100));
    }
    return ['scenario' => (string)$sc['scenario'], 'rate_country' => (string)$sc['rate_country'],
        'vat_total' => $vatTotal, 'net_total' => $total - $vatTotal, 'items' => $lines];
}

if (defined('BB_TEST_LOAD_VAT_BACKFILL_ONLY')) return;

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

$stores = json_decode(@file_get_contents(__DIR__ . '/../stores.json') ?: '{}', true) ?: [];

$where = "(o.vat_scenario IS NULL OR o.vat_total IS NULL OR o.net_total IS NULL)";
if ($orderOne > 0) $where = "o.id = " . (int)$orderOne;
$lim = $limit > 0 ? "LIMIT " . (int)$limit : '';

$ordersStmt = $db->query("SELECT o.id, o.store_id, o.shipping_addr, o.subtotal, o.discount_amount, o.shipping_cost, o.tax_amount, o.total, o.currency, o.created_at FROM orders o WHERE {$where} ORDER BY o.id ASC {$lim}");
$orders = $ordersStmt->fetchAll();

echo "Found " . count($orders) . " orders to backfill" . ($dryRun ? " (DRY RUN — no writes)" : " — APPLYING") . "\n";
$touched = ['orders' => 0, 'items' => 0];

if ($apply) $db->beginTransaction();

try {
    foreach ($orders as $o) {
        $itemsStmt = $db->prepare("SELECT id, total, vat_class, vat_amount, net_amount FROM order_items WHERE order_id = ?");
        $itemsStmt->execute([$o['id']]);
        $items = $itemsStmt->fetchAll();
        $snapshot = vatBackfillOrder($o, $items, vatBackfillSeller($stores, (string)($o['store_id'] ?? '')));

        foreach ($items as $it) {
            // Backfill items whenever we are backfilling the order:
            // legacy rows often default to 0 instead of NULL. Skip only if a non-zero
            // VAT snapshot already exists (i.e. item was created post-VAT-feature).
            $needs = ((int)($it['vat_amount'] ?? 0) === 0 && (int)($it['net_amount'] ?? 0) === 0);
            if ($needs) {
                $line = $snapshot['items'][(int)$it['id']];
                if ($apply) {
                    $up = $db->prepare("UPDATE order_items SET vat_class=?, vat_rate=?, vat_amount=?, net_amount=? WHERE id=?");
                    $up->execute([$line['vat_class'], $line['rate'], $line['vat'], $line['net'], $it['id']]);
                }
                $touched['items']++;
            }
        }

        if ($apply) {
            $up = $db->prepare("UPDATE orders SET vat_scenario=?, vat_total=?, net_total=? WHERE id=?");
            $up->execute([$snapshot['scenario'], $snapshot['vat_total'], $snapshot['net_total'], $o['id']]);
        }
        $touched['orders']++;
        printf("  order #%d → %s rate_country=%s vat=%d net=%d\n",
               $o['id'], $snapshot['scenario'], $snapshot['rate_country'], $snapshot['vat_total'], $snapshot['net_total']);
    }

    if ($apply) $db->commit();
} catch (\Throwable $e) {
    if ($apply && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nSummary: orders={$touched['orders']} items={$touched['items']}" . ($dryRun ? " (dry-run, nothing written)" : " (committed)") . "\n";
exit(0);
