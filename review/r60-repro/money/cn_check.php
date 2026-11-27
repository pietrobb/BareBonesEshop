<?php
// R59 credit-note invariants on raw tables (default store, card orders with an issued invoice).
// cn_check($db, $orderIds, $final) -> list of violation strings.
function cn_key_rate(string $key): float { return (float)explode(' ', $key)[0]; }

function cn_check(PDO $db, array $orderIds, bool $final = false, array $skipMoney = []): array {
    $v = [];
    foreach ($orderIds as $oid) {
        $o = $db->query("SELECT * FROM orders WHERE id = " . (int)$oid)->fetch(PDO::FETCH_ASSOC);
        if (!$o) continue;
        $inv = $db->query("SELECT * FROM invoices WHERE order_id = $oid AND type = 'invoice' AND status = 'issued'")->fetch(PDO::FETCH_ASSOC);
        $cns = $db->query("SELECT * FROM invoices WHERE order_id = $oid AND type = 'credit_note' AND status <> 'cancelled' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $refunded = (int)$db->query("SELECT COALESCE(SUM(amount),0) FROM payment_transactions WHERE order_id = $oid AND status IN ('partially_refunded','refunded','refund_succeeded') AND amount > 0")->fetchColumn();
        $pendingItem = (int)$db->query("SELECT COUNT(*) FROM order_refunds WHERE order_id = $oid AND status = 'pending'")->fetchColumn();
        $inflight = 0;
        try { $inflight = PaymentService::requestedRefundInFlight($o); } catch (Throwable $e) {}
        $credited = 0;
        foreach ($cns as $c) $credited += -(int)$c['total_gross'];
        if (!$inv) { if ($cns) $v[] = "CN_WITHOUT_INVOICE o$oid"; continue; }
        $tag = "o$oid inv{$inv['id']}";
        // M: credit notes == money that moved (when nothing is in flight)
        $quiet = $pendingItem === 0 && $inflight === 0 && !isset($skipMoney[$oid]);
        if ($quiet && $credited !== $refunded) $v[] = "MONEY_NE_CN $tag credited=$credited refunded=$refunded ps={$o['payment_status']} st={$o['status']}";
        if ($credited > (int)$inv['total_gross']) $v[] = "CN_OVER_INVOICE $tag credited=$credited invoice={$inv['total_gross']}";
        // per CN: breakdown == totals, line arithmetic
        foreach ($cns as $c) {
            $bd = json_decode((string)$c['vat_breakdown'], true) ?: [];
            $bn = 0; $bv = 0; foreach ($bd as $b) { $bn += (int)($b['net'] ?? 0); $bv += (int)($b['vat'] ?? 0); }
            if ($bn !== (int)$c['total_net'] || $bv !== (int)$c['total_vat'] || (int)$c['total_net'] + (int)$c['total_vat'] !== (int)$c['total_gross']) $v[] = "CN_SUMS {$c['number']} $tag bd=$bn/$bv tot={$c['total_net']}/{$c['total_vat']}/{$c['total_gross']}";
            foreach ($db->query("SELECT * FROM credit_note_items WHERE credit_note_id = {$c['id']}")->fetchAll(PDO::FETCH_ASSOC) as $li) {
                if ((int)$li['net'] + (int)$li['vat'] !== (int)$li['gross']) $v[] = "CNI_ARITH {$c['number']} item{$li['id']}";
                $r = (int)$li['rate_basis_points'] / 10000;
                $g = (int)$li['gross'];
                if ($g !== 0 && (($g > 0 && ((int)$li['vat'] < 0 || (int)$li['net'] < 0)) || ($g < 0 && ((int)$li['vat'] > 0 || (int)$li['net'] > 0)))) $v[] = "CNI_SIGN {$c['number']} item{$li['id']} g/n/v={$li['gross']}/{$li['net']}/{$li['vat']}";
                if ($g !== 0 && abs((int)$li['vat'] - $g * $r / (1 + $r)) > 2.01) $v[] = "CNI_VAT_OFF_RATE {$c['number']} item{$li['id']} rate={$li['rate_basis_points']} g/n/v={$li['gross']}/{$li['net']}/{$li['vat']}";
            }
        }
        // R: per rate never negative, never above the invoice; fully credited rate takes its net rest
        $ib = json_decode((string)$inv['vat_breakdown'], true) ?: [];
        $cred = [];
        foreach ($cns as $c) foreach (json_decode((string)$c['vat_breakdown'], true) ?: [] as $k => $b) { $cred[$k]['net'] = ($cred[$k]['net'] ?? 0) - (int)($b['net'] ?? 0); $cred[$k]['vat'] = ($cred[$k]['vat'] ?? 0) - (int)($b['vat'] ?? 0); }
        foreach ($cred as $k => $c) {
            if (!isset($ib[$k])) { if ($c['net'] !== 0 || $c['vat'] !== 0) $v[] = "CN_FOREIGN_RATE $tag key=$k " . json_encode($c); continue; }
            $in = (int)$ib[$k]['net']; $iv = (int)$ib[$k]['vat'];
            if ($c['vat'] < 0 || $c['net'] < 0) $v[] = "RATE_NEG $tag key=$k credited net/vat={$c['net']}/{$c['vat']}";
            if ($c['vat'] > $iv || $c['net'] > $in) $v[] = "RATE_OVER $tag key=$k credited net/vat={$c['net']}/{$c['vat']} invoice=$in/$iv";
            if ($c['net'] + $c['vat'] === $in + $iv && ($c['net'] !== $in || $c['vat'] !== $iv)) $v[] = "RATE_FULL_REST $tag key=$k credited net/vat={$c['net']}/{$c['vat']} invoice=$in/$iv";
            $r = cn_key_rate((string)$k) / 100;
            $ncn = count($cns);
            if ($c['net'] + $c['vat'] > 0 && abs($c['vat'] - ($c['net'] + $c['vat']) * $r / (1 + $r)) > 1 + $ncn) $v[] = "RATE_VAT_OFF $tag key=$k credited net/vat={$c['net']}/{$c['vat']} (#cn $ncn)";
        }
        // U: units credited once at their own rate
        $snap = json_decode((string)$inv['financial_snapshot'], true) ?: [];
        $comps = array_values(array_filter($snap['components'] ?? [], fn($c) => ($c['type'] ?? '') === 'product'));
        $items = $db->query("SELECT * FROM order_items WHERE order_id = $oid ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $compOf = []; foreach ($items as $pos => $it) $compOf[(int)$it['id']] = $comps[$pos] ?? null;
        $live = "(r.status IN ('pending','succeeded'))";
        foreach ($items as $it) {
            $iid = (int)$it['id'];
            $cq = (int)$db->query("SELECT COALESCE(SUM(cni.quantity),0) FROM credit_note_items cni JOIN order_refunds r ON r.id = cni.refund_id WHERE cni.order_item_id = $iid AND cni.rma_item_id > 0 AND $live")->fetchColumn();
            $closedQ = (int)$db->query("SELECT COALESCE(SUM(ri.refunded_quantity),0) FROM rma_items ri JOIN rma_requests q ON q.id = ri.rma_id WHERE ri.order_item_id = $iid AND q.outside_refund_applied > 0 AND NOT EXISTS (SELECT 1 FROM credit_note_items x JOIN order_refunds r ON r.id = x.refund_id WHERE x.rma_item_id = ri.id AND $live)")->fetchColumn();
            $recv = (int)$db->query("SELECT COALESCE(SUM(ri.received_quantity),0) FROM rma_items ri WHERE ri.order_item_id = $iid")->fetchColumn();
            if ($cq > (int)$it['quantity'] || $cq > $recv) $v[] = "UNITS_OVER $tag item$iid credited=$cq received=$recv ordered={$it['quantity']}";
            $comp = $compOf[$iid];
            if ($comp) foreach ($db->query("SELECT cni.*, i.number FROM credit_note_items cni JOIN invoices i ON i.id = cni.credit_note_id WHERE cni.order_item_id = $iid AND cni.rma_item_id > 0")->fetchAll(PDO::FETCH_ASSOC) as $li) {
                if ((int)$li['rate_basis_points'] !== (int)$comp['rate_basis_points']) $v[] = "UNIT_WRONG_RATE $tag {$li['number']} item$iid line rate={$li['rate_basis_points']} goods rate={$comp['rate_basis_points']}";
            }
        }
        // T: what came back (returned units, cancelled unshipped goods) is credited at its own rate
        $back = []; $backTotal = 0;
        foreach ($items as $pos => $it) {
            $comp = $compOf[(int)$it['id']]; if (!$comp) continue;
            $key = (string)VatService::breakdownKey((int)$comp['rate_basis_points'], isset($comp['rate_country']) ? strtoupper((string)$comp['rate_country']) : '', isset($comp['rate_country']) ? (string)($comp['supply'] ?? 'oss') : '');
            $ret = (int)$db->query("SELECT COALESCE(SUM(MAX_Q),0) FROM (SELECT CASE WHEN ri.refunded_quantity > COALESCE((SELECT SUM(x.quantity) FROM credit_note_items x JOIN order_refunds r ON r.id = x.refund_id WHERE x.rma_item_id = ri.id AND $live),0) THEN ri.refunded_quantity ELSE COALESCE((SELECT SUM(x.quantity) FROM credit_note_items x JOIN order_refunds r ON r.id = x.refund_id WHERE x.rma_item_id = ri.id AND $live),0) END AS MAX_Q FROM rma_items ri WHERE ri.order_item_id = {$it['id']}) t")->fetchColumn();
            $q = (int)$it['quantity'];
            $val = intdiv((int)$comp['gross'] * min($q, $ret), max(1, $q));
            if (in_array($o['status'], ['cancelled', 'refunded'], true)) {
                [$sh, $wh] = Fulfillment::shippedShare($it);
                $val += (int)$comp['gross'] - intdiv((int)$comp['gross'] * $sh, $wh);
            }
            if ($val > 0) { $back[$key] = ($back[$key] ?? 0) + $val; $backTotal += $val; }
        }
        if ($quiet && $backTotal > 0 && $credited >= $backTotal + count($items)) {
            foreach ($back as $k => $val) {
                $got = ($cred[$k]['net'] ?? 0) + ($cred[$k]['vat'] ?? 0);
                if ($got + count($items) + 1 < $val) $v[] = "RATE_SHORT $tag key=$k credited=$got goods_back=$val (all credited=$credited, all back=$backTotal)";
            }
        }
        // Z: no credit note while no money moved (balance notes of 0 excluded)
        if ($refunded === 0 && $quiet && $credited !== 0) foreach ($cns as $c) if ((int)$c['total_gross'] !== 0) $v[] = "CN_NO_MONEY {$c['number']} $tag gross={$c['total_gross']}";
        if ($final) {
            if ($credited === (int)$inv['total_gross']) foreach ($ib as $k => $b) { $c = $cred[$k] ?? ['net' => 0, 'vat' => 0]; if ($c['net'] !== (int)$b['net'] || $c['vat'] !== (int)$b['vat']) $v[] = "FINAL_RATE_LEFT $tag key=$k credited=" . json_encode($c) . " invoice=" . json_encode([(int)$b['net'], (int)$b['vat']]); }
        }
    }
    return $v;
}
