<?php
// Invariant checker. Returns list of violation strings (store-agnostic: reads raw tables).
function r50_check(PDO $db, bool $final = false): array {
    $v = [];
    // 1. stock rows
    foreach ($db->query("SELECT * FROM warehouse_stock")->fetchAll() as $r) {
        if ($r['qty'] < 0 || $r['reserved'] < 0 || $r['qty'] < $r['reserved']) $v[] = "STOCK_NEG wh{$r['warehouse_id']} {$r['sku']} qty={$r['qty']} res={$r['reserved']}";
    }
    // 2. ledger sums
    $ws = [];
    foreach ($db->query("SELECT warehouse_id, sku, qty, reserved FROM warehouse_stock")->fetchAll() as $r) $ws[$r['warehouse_id'] . '|' . $r['sku']] = [(int)$r['qty'], (int)$r['reserved']];
    $led = [];
    foreach ($db->query("SELECT warehouse_id, sku, SUM(quantity_delta) q, SUM(reserved_delta) r FROM stock_movements WHERE warehouse_id IS NOT NULL GROUP BY warehouse_id, sku")->fetchAll() as $r) $led[$r['warehouse_id'] . '|' . $r['sku']] = [(int)$r['q'], (int)$r['r']];
    foreach ($ws + $led as $k => $_) {
        $a = $ws[$k] ?? [0, 0]; $b = $led[$k] ?? [0, 0];
        if ($a !== $b) $v[] = "LEDGER $k stock=" . json_encode($a) . " ledger=" . json_encode($b);
    }
    // 3. reservations held by open orders == reserved
    $expect = [];
    $items = $db->query("SELECT oi.*, o.stock_restored, o.status AS ostatus, o.store_id AS ostore FROM order_items oi JOIN orders o ON o.id = oi.order_id")->fetchAll();
    $shipped = [];
    foreach ($db->query("SELECT si.order_item_id, si.sku, s.warehouse_id, SUM(si.quantity) q FROM shipment_items si JOIN shipments s ON s.id = si.shipment_id WHERE si.status = 'shipped' GROUP BY si.order_item_id, si.sku, s.warehouse_id")->fetchAll() as $r) $shipped[$r['order_item_id'] . '|' . $r['sku'] . '|' . $r['warehouse_id']] = (int)$r['q'];
    foreach ($items as $it) {
        if ((int)$it['digital'] === 1) continue;
        $attrs = json_decode((string)$it['attrs'], true) ?: [];
        $lines = !empty($attrs['_bundle_components']) ? $attrs['_bundle_components'] : [['sku' => $it['sku'], 'allocations' => $attrs['_inventory_allocations'] ?? []]];
        $resQty = 0; $boQty = 0;
        foreach ($lines as $line) foreach ($line['allocations'] ?? [] as $a) {
            if (($a['status'] ?? 'reserved') !== 'reserved' || empty($a['warehouse_id'])) continue;
            $k = $a['warehouse_id'] . '|' . $line['sku'];
            $sk = $it['id'] . '|' . $line['sku'] . '|' . $a['warehouse_id'];
            $take = min((int)$a['qty'], $shipped[$sk] ?? 0);
            if (isset($shipped[$sk])) $shipped[$sk] -= $take;
            $out = (int)$a['qty'] - $take;
            if ((int)$it['stock_restored'] === 0) $expect[$k] = ($expect[$k] ?? 0) + $out;
            else $expect[$k] = $expect[$k] ?? 0;
        }
        if ($it['reserved_qty'] + $it['backordered_qty'] != $it['quantity']) $v[] = "ITEMQTY item{$it['id']} res={$it['reserved_qty']} bo={$it['backordered_qty']} q={$it['quantity']}";
    }
    foreach ($shipped as $sk => $left) if ($left > 0) $v[] = "SHIP_OVER $sk shipped beyond allocation by $left";
    foreach ($ws as $k => [$q, $r]) {
        if (($expect[$k] ?? 0) !== $r) $v[] = "RESERVED $k reserved=$r open_orders=" . ($expect[$k] ?? 0);
    }
    // products.stock mirrors ledger
    foreach ($db->query("SELECT p.id, p.sku, p.stock, p.store_id, (SELECT COALESCE(SUM(qty-reserved),0) FROM warehouse_stock w WHERE w.sku=p.sku AND w.store_id=p.store_id) l, (SELECT COUNT(*) FROM warehouse_stock w WHERE w.sku=p.sku AND w.store_id=p.store_id) n FROM products p")->fetchAll() as $p) {
        if ((int)$p['n'] > 0 && (int)$p['stock'] !== (int)$p['l']) $v[] = "PSTOCK {$p['sku']} stock={$p['stock']} ledger={$p['l']}";
    }
    // 4. order money
    $orders = $db->query("SELECT * FROM orders")->fetchAll();
    $sumItems = [];
    foreach ($items as $it) $sumItems[$it['order_id']] = ($sumItems[$it['order_id']] ?? 0) + (int)$it['total'];
    foreach ($orders as $o) {
        $id = $o['id'];
        if ((int)$o['subtotal'] !== ($sumItems[$id] ?? 0)) $v[] = "SUBTOTAL o$id subtotal={$o['subtotal']} lines=" . ($sumItems[$id] ?? 0);
        $calc = max(0, (int)$o['subtotal'] - (int)$o['discount_amount']) + (int)$o['shipping_cost'] + (int)$o['payment_fee'];
        if ($calc !== (int)$o['total']) $v[] = "TOTAL o$id total={$o['total']} calc=$calc";
        if ((int)$o['vat_total'] + (int)$o['net_total'] !== (int)$o['total']) $v[] = "VATSUM o$id vat={$o['vat_total']} net={$o['net_total']} total={$o['total']}";
        $fs = json_decode((string)$o['financial_snapshot'], true);
        if (is_array($fs)) {
            $g = 0; $n = 0; $t = 0;
            foreach ($fs['components'] as $c) { $g += (int)$c['gross']; $n += (int)$c['net']; $t += (int)$c['vat']; if ((int)$c['net'] + (int)$c['vat'] !== (int)$c['gross']) $v[] = "COMPVAT o$id"; }
            if ($g !== (int)$o['total'] || $n !== (int)$o['net_total'] || $t !== (int)$o['vat_total']) $v[] = "SNAPSUM o$id comp g/n/v=$g/$n/$t order {$o['total']}/{$o['net_total']}/{$o['vat_total']}";
            $bg = 0; foreach ($fs['breakdown'] as $b) $bg += (int)$b['gross'];
            if ($bg !== (int)$o['total']) $v[] = "BREAKDOWN o$id $bg vs {$o['total']}";
        }
    }
    // 5. invoices
    $inv = $db->query("SELECT * FROM invoices ORDER BY id")->fetchAll();
    $nums = [];
    $series = [];
    $perOrder = [];
    foreach ($inv as $i) {
        if (isset($nums[$i['number']])) $v[] = "INVNUM_DUP {$i['number']}";
        $nums[$i['number']] = 1;
        $t = $i['type'] === 'credit_note' ? 'credit_note' : 'invoice';
        if (preg_match('/^(.*?)(\d{4})(\d{4,})$/', $i['number'], $m)) $series["{$i['seller_code']}|$t|{$m[2]}|{$m[1]}"][] = (int)$m[3];
        if ($i['type'] === 'invoice') $perOrder[$i['order_id']][] = $i;
        if ((int)$i['total_net'] + (int)$i['total_vat'] !== (int)$i['total_gross']) $v[] = "INVVAT {$i['number']}";
        $bd = json_decode((string)$i['vat_breakdown'], true) ?: [];
        $bg = 0; $bv = 0; foreach ($bd as $b) { $bg += (int)($b['net'] ?? 0) + (int)($b['vat'] ?? 0); $bv += (int)($b['vat'] ?? 0); }
        if ($bd && (abs($bg) !== abs((int)$i['total_gross']) || abs($bv) !== abs((int)$i['total_vat']))) $v[] = "INVBREAKDOWN {$i['number']} $bg/$bv vs {$i['total_gross']}/{$i['total_vat']}";
    }
    $counters = [];
    foreach ($db->query("SELECT * FROM invoice_number_counters")->fetchAll() as $c) $counters["{$c['store_id']}|{$c['type']}|{$c['year']}|{$c['prefix']}"] = (int)$c['last_number'];
    foreach ($series as $k => $list) {
        sort($list);
        if ($list !== range(1, count($list))) $v[] = "INVGAP $k " . json_encode($list);
        if (($counters[$k] ?? -1) !== count($list)) $v[] = "INVCOUNTER $k counter=" . ($counters[$k] ?? 'none') . " docs=" . count($list);
    }
    $oById = array_column($orders, null, 'id');
    foreach ($perOrder as $oid => $list) {
        $live = array_filter($list, fn($i) => $i['status'] === 'issued');
        if (count($live) > 1) $v[] = "INV_MULTI o$oid";
        foreach ($live as $i) if ((int)$i['total_gross'] !== (int)$oById[$oid]['total']) $v[] = "INVTOTAL o$oid inv={$i['total_gross']} order={$oById[$oid]['total']}";
    }
    // credit notes never exceed invoice
    $cn = [];
    foreach ($inv as $i) if ($i['type'] === 'credit_note' && $i['status'] === 'issued') $cn[$i['refers_to_invoice_id']] = ($cn[$i['refers_to_invoice_id']] ?? 0) - (int)$i['total_gross'];
    $invById = array_column($inv, null, 'id');
    foreach ($cn as $src => $sum) {
        $s = $invById[$src] ?? null;
        if (!$s) { $v[] = "CN_ORPHAN $src"; continue; }
        if ($sum > (int)$s['total_gross']) $v[] = "CN_OVER inv{$src} {$s['number']} credited=$sum gross={$s['total_gross']}";
        if ($sum < 0) $v[] = "CN_NEG inv{$src} credited=$sum";
    }
    // 6. payment ledger
    $tx = [];
    foreach ($db->query("SELECT order_id, status, SUM(amount) a, COUNT(*) n FROM payment_transactions GROUP BY order_id, status")->fetchAll() as $r) $tx[$r['order_id']][$r['status']] = (int)$r['a'];
    foreach ($orders as $o) {
        $id = $o['id']; $t = $tx[$id] ?? [];
        $paid = $t['paid'] ?? 0;
        $ref = ($t['partially_refunded'] ?? 0) + ($t['refunded'] ?? 0) + ($t['refund_succeeded'] ?? 0);
        $ps = $o['payment_status']; $st = $o['status'];
        $manual = $db->query("SELECT COALESCE(SUM(amount),0) FROM order_refunds WHERE order_id={$id} AND status='succeeded'")->fetchColumn();
        $offline = trim((string)$o['payment_id']) === '';
        if ($ref > $paid && $paid > 0) $v[] = "REFUND_GT_PAID o$id refunded=$ref paid=$paid";
        if ($ref > (int)$o['total']) $v[] = "REFUND_GT_TOTAL o$id refunded=$ref total={$o['total']}";
        if ($paid > (int)$o['total']) $v[] = "PAID_GT_TOTAL o$id paid=$paid total={$o['total']}";
        if (in_array($ps, ['paid', 'refund_pending', 'partially_refunded', 'refunded', 'disputed'], true) && $paid <= 0 && $ref <= 0 && (int)$o['total'] > 0) $v[] = "PAID_NO_LEDGER o$id ps=$ps";
        if ($ps === 'refunded' && $ref + ($offline ? (int)$manual : 0) < (int)$o['total']) $v[] = "REFUNDED_SHORT o$id ref=$ref manual=$manual total={$o['total']}";
        if ($ps === 'partially_refunded' && $ref <= 0 && !($offline && $manual > 0)) $v[] = "PARTIAL_NO_REFUND o$id";
        if ($ps === 'paid' && $ref > 0) $v[] = "PAID_BUT_REFUNDED o$id ref=$ref";
        // order/payment status consistency
        if ($st === 'pending' && in_array($ps, ['paid'], true)) $v[] = "STUCK_PENDING_PAID o$id";
        if (in_array($st, ['paid', 'processing', 'shipped', 'delivered'], true) && in_array($ps, ['pending', 'failed', 'cancelled', 'authorized'], true)) {
            $snap = json_decode((string)$o['payment_method_snapshot'], true) ?: [];
            if (!in_array($snap['service_type'] ?? '', ['cod', 'invoice'], true) || $st === 'paid') $v[] = "FULFIL_UNPAID o$id st=$st ps=$ps";
            if ($st === 'delivered' && ($snap['service_type'] ?? '') === 'cod') $v[] = "COD_DELIVERED_UNPAID o$id";
        }
        if ($st === 'cancelled' && in_array($ps, ['paid', 'partially_refunded'], true) && (int)$o['total'] > 0) {
            $manualFlag = ($t['refund_manual_required'] ?? null) !== null;
            $partShipped = (int)$db->query("SELECT COUNT(*) FROM shipments WHERE order_id={$id} AND status='shipped'")->fetchColumn() > 0;
            if (!$manualFlag && !$partShipped) $v[] = "CANCELLED_KEEPS_MONEY o$id ps=$ps paid=$paid ref=$ref";
        }
        if ($st === 'refunded' && !in_array($ps, ['refunded'], true) && (int)$o['total'] > 0) {
            if (!($offline && (int)$manual >= (int)$o['total'])) $v[] = "REFUNDED_STATUS_PS o$id ps=$ps ref=$ref";
        }
        if ($final) {
            if ($ps === 'refund_pending') $v[] = "FINAL_REFUND_PENDING o$id st=$st";
            if ($st === 'pending' && $ps === 'cancelled') $v[] = "FINAL_PENDING_CANCELLED_PAY o$id";
        }
    }
    // 7. coupons
    foreach ($db->query("SELECT coupon_code, COUNT(*) n FROM coupon_uses GROUP BY coupon_code")->fetchAll() as $r) {
        $lim = ['ONCE' => 1, 'SHIPFREE' => 3][$r['coupon_code']] ?? 0;
        if ($lim && $r['n'] > $lim) $v[] = "COUPON_OVER {$r['coupon_code']} uses={$r['n']} limit=$lim";
    }
    foreach ($db->query("SELECT c.store_id, c.coupon_code, c.uses, (SELECT COUNT(*) FROM coupon_uses u WHERE u.coupon_code=c.coupon_code AND (c.store_id='*' OR u.store_id=c.store_id)) n FROM coupon_usage_counters c WHERE c.subject=''")->fetchAll() as $r) {
        if ((int)$r['uses'] !== (int)$r['n']) $v[] = "COUPON_COUNTER {$r['store_id']} {$r['coupon_code']} counter={$r['uses']} uses={$r['n']}";
    }
    foreach ($db->query("SELECT u.order_id, o.status FROM coupon_uses u JOIN orders o ON o.id=u.order_id WHERE o.status='cancelled'")->fetchAll() as $r) $v[] = "COUPON_ON_CANCELLED o{$r['order_id']}";
    // 8. digital entitlements
    foreach ($db->query("SELECT de.id, de.order_id, o.payment_status, o.status FROM digital_entitlements de JOIN orders o ON o.id=de.order_id WHERE de.status='active'")->fetchAll() as $r) {
        if (in_array($r['status'], ['cancelled', 'refunded'], true) || in_array($r['payment_status'], ['refunded', 'failed', 'cancelled', 'pending'], true)) $v[] = "ENTITLEMENT_ACTIVE o{$r['order_id']} st={$r['status']} ps={$r['payment_status']}";
    }
    // 9. order_refunds sanity
    foreach ($db->query("SELECT order_id, SUM(amount) s FROM order_refunds WHERE status IN ('pending','succeeded') GROUP BY order_id")->fetchAll() as $r) {
        $o = $oById[$r['order_id']] ?? null;
        if ($o && (int)$r['s'] > (int)$o['total']) $v[] = "ITEMREFUND_GT_TOTAL o{$r['order_id']} {$r['s']} > {$o['total']}";
    }
    return $v;
}
