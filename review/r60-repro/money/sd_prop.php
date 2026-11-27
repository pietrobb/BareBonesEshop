<?php
// R52 sales_daily property test. php sd_prop.php driver target seed steps
// Random orders in 3 stores/timezones (Bratislava, Kolkata +5:30, Santiago: DST at midnight) backdated to random
// times, midnight edges and DST days; late/failed payments, cancels, admin edits, refunds (partial/full/dashboard),
// RMA credit notes, raw SQL; after each step every report figure (totals/trend/top for 1..365 days) is compared
// with a from-scratch oracle computed in PHP from orders/order_items/payment_transactions/credit notes.
require __DIR__ . '/lib50.php';
[, $driver, $target] = $argv;
$seed = (int)($argv[3] ?? 1); $steps = (int)($argv[4] ?? 150);
$verbose = getenv('SD_VERBOSE') !== false;
mt_srand($seed);
$db = r50_boot($driver, $target, true);
$TZ = ['default' => 'Europe/Bratislava', 'cz' => 'Asia/Kolkata', 'hu' => 'America/Santiago'];
$storePatch = ['stores.default.legal.iban' => 'SK3112000000198742637541', 'stores.cz.legal.iban' => 'CZ6508000000192000145399',
    'stores.cz.timezone' => $TZ['cz'], 'stores.cz.catalog_scope' => 'cz', 'stores.hu.timezone' => $TZ['hu'], 'stores.hu.catalog_scope' => 'hu'];
$applyStores = function () use (&$storePatch) {
    $stores = r36_stores($storePatch);
    (new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores); InvoiceService::setStoresConfigForTesting($stores);
};
$applyStores();
foreach (array_keys($TZ) as $s) { r36_store($s); Adapters::set('payment.stripe', new R50Gateway()); }
r50_set_pricing();
$CUR = ['default' => ['eur', 'SK'], 'cz' => ['czk', 'CZ'], 'hu' => ['huf', 'HU']];
$P = []; $SHIP = [];
foreach ($CUR as $s => [$cur, $c]) {
    r36_store($s);
    $wh = (int)$db->query("SELECT id FROM warehouses WHERE store_id='$s' ORDER BY is_default DESC, id LIMIT 1")->fetchColumn();
    if (!$wh) { $db->prepare("INSERT INTO warehouses (code, name, priority, is_default, store_id) VALUES (?, 'W', 10, 1, ?)")->execute(['w-' . $s, $s]); $wh = (int)$db->lastInsertId(); }
    $mult = $s === 'default' ? 1 : ($s === 'cz' ? 25 : 400);
    foreach (['A' => 1990, 'B' => 500, 'C' => 12345] as $k => $price) $P[$s][] = [r50_product($db, $s, strtoupper($s) . "-$k", $price * $mult, $cur, [$wh => 100000]), null];
    if ($s === 'default') {
        $v = r50_product($db, $s, 'V', 2500, $cur, []);
        foreach (['V-RED', 'V-BLUE'] as $i => $vs) { $db->prepare("INSERT INTO variants (product_id, sku, name, stock, sort_order) VALUES (?, ?, ?, 0, ?)")->execute([$v, $vs, $vs, $i]); $vid = (int)$db->lastInsertId(); Warehouse::setStock($wh, $vs, 100000, 'count', 'r52', 'o-' . $vs); Warehouse::syncProductStock([$vs]); $P[$s][] = [$v, $vid]; }
    }
    $SHIP[$s] = (string)$db->query("SELECT code FROM shipping_methods WHERE store_id IN ('$s','default') AND active=1 ORDER BY (store_id='$s') DESC LIMIT 1")->fetchColumn();
}
r36_store('default');
$log = [];
$orders = [];   // id => store
$stats = []; $viol = []; $staleExpected = 0; $handDirty = false;
$far = '9999-12-31 00:00:00';

function sd_local(string $utc, string $tz): DateTimeImmutable { return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz)); }
function sd_utc(DateTimeImmutable $t): string { return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }

/** A random UTC creation time for store $tz: random past, local midnight edges, DST transitions, around now. */
$pickTime = function (string $tz): string {
    $z = new DateTimeZone($tz); $now = new DateTimeImmutable('now', $z);
    $k = mt_rand(0, 9);
    if ($k <= 2) return sd_utc($now->modify('-' . mt_rand(0, 400 * 86400) . ' seconds'));
    if ($k <= 5) { // local midnight of a random recent day +- edge
        $d = $now->setTime(0, 0)->modify('-' . mt_rand(0, [3, 40, 400][mt_rand(0, 2)]) . ' days');
        $off = [0, 1, -1, 59, -59, 1799, -1800, 3599, -3601, 900, -900][mt_rand(0, 10)];
        $t = $d->modify(($off >= 0 ? '+' : '') . $off . ' seconds');
        return sd_utc($t > $now ? $now : $t);
    }
    if ($k <= 7) { // a DST transition of the zone in the last 400 days
        $tr = array_slice($z->getTransitions($now->getTimestamp() - 400 * 86400, $now->getTimestamp()), 1);
        if ($tr) { $x = $tr[mt_rand(0, count($tr) - 1)]['ts'] + [0, -1, 1, -3600, 3600, -7200, 1800, -86400 + 3600, 82800][mt_rand(0, 8)]; return gmdate('Y-m-d H:i:s', min($x, time())); }
    }
    if ($k === 8) return gmdate('Y-m-d H:i:s', time() - mt_rand(0, 3600));            // now-ish (open day / settle window)
    return sd_utc($now->setTime(0, 0)->modify('-' . mt_rand(0, 1200) . ' seconds')); // just before today's local midnight
};

/** From-scratch oracle for store $s in zone $tz: per local day per currency [orders, revenue]; product groups per day. */
$oracle = function (string $s, string $tz) use ($db): array {
    $PAID = ['paid', 'refund_pending', 'partially_refunded', 'disputed'];
    $ref = []; foreach ($db->query("SELECT order_id, amount FROM payment_transactions WHERE store_id = " . $db->quote($s) . " AND status IN ('partially_refunded','refunded','refund_succeeded')")->fetchAll() as $r) $ref[(int)$r['order_id']] = ($ref[(int)$r['order_id']] ?? 0) + (int)$r['amount'];
    $cl = []; $co = []; $credited = [];
    foreach ($db->query("SELECT cni.order_item_id, rf.order_id, cni.gross FROM credit_note_items cni JOIN order_refunds rf ON rf.id = cni.refund_id WHERE rf.store_id = " . $db->quote($s) . " AND rf.status = 'succeeded'")->fetchAll() as $r) {
        $cl[(int)$r['order_item_id']] = ($cl[(int)$r['order_item_id']] ?? 0) + (int)$r['gross']; $co[(int)$r['order_id']] = ($co[(int)$r['order_id']] ?? 0) + (int)$r['gross']; $credited[(int)$r['order_id']] = true; }
    $days = []; $prod = [];
    $items = []; foreach ($db->query("SELECT oi.* FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.store_id = " . $db->quote($s))->fetchAll() as $it) $items[(int)$it['order_id']][] = $it;
    foreach ($db->query("SELECT * FROM orders WHERE store_id = " . $db->quote($s))->fetchAll() as $o) {
        if ((int)$o['is_synthetic'] !== 0 || in_array($o['status'], ['cancelled', 'refunded'], true) || !in_array($o['payment_status'], $PAID, true)) continue;
        $id = (int)$o['id']; $day = sd_local($o['created_at'], $tz)->format('Y-m-d'); $cur = strtolower($o['currency']);
        // Known (pre-R52) raw-path behaviour: trend() buckets the open days by UTC hour, so in a zone off by a half hour
        // the first minutes of an open day land on the day before. Kept apart as $bucketDay to classify such mismatches.
        $open = SalesDaily::openDay();
        $bucket = sd_local(substr($o['created_at'], 0, 13) . ':00:00', $tz)->format('Y-m-d');
        if (false && $day >= $open && $bucket !== $day) $GLOBALS['sd_bucket'][$id] = [$day, $bucket, (int)$o['total'] - ($ref[$id] ?? 0)];
        $days[$day][$cur][0] = ($days[$day][$cur][0] ?? 0) + 1;
        $days[$day][$cur][1] = ($days[$day][$cur][1] ?? 0) + (int)$o['total'] - ($ref[$id] ?? 0);
        $affected = isset($ref[$id]) || isset($credited[$id]);
        foreach ($items[$id] ?? [] as $it) {
            $lt = (int)$it['total']; $ot = (int)$o['total'];
            if (!$affected) $kept = ($lt <= 0 || $ot <= 0) ? 0 : $lt;
            else {
                $line = $lt - ($cl[(int)$it['id']] ?? 0); $ord = $ot - ($co[$id] ?? 0); $rest = ($ref[$id] ?? 0) - ($co[$id] ?? 0);
                $kept = ($line <= 0 || $ord <= 0) ? 0 : ($rest <= 0 ? $line : ($rest >= $ord ? 0 : $line * 1.0 * ($ord - $rest) / $ord));
            }
            $key = $cur . '|' . (int)$it['product_id'] . '|' . (int)($it['variant_id'] ?? 0);
            $prod[$day][$key][0] = ($prod[$day][$key][0] ?? 0) + (int)$it['quantity'];
            $prod[$day][$key][1] = ($prod[$day][$key][1] ?? 0) + $kept;
        }
    }
    return [$days, $prod];
};

$compare = function (string $label) use (&$TZ, $oracle, $far, &$viol, &$handDirty, &$staleExpected, $db): int {
    $bad = 0; $msgs = []; $GLOBALS['sd_bucket'] = [];
    foreach ($TZ as $s => $tz) {
        r36_store($s);
        if (date_default_timezone_get() !== $tz) { $msgs[] = "$s: tz " . date_default_timezone_get() . " != $tz"; }
        [$days, $prod] = $oracle($s, $tz);
        $today = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d');
        foreach (SalesReport::PERIODS as $p) {
            $first = (new DateTimeImmutable($today . ' 12:00', new DateTimeZone('UTC')))->modify('-' . ($p - 1) . ' days')->format('Y-m-d');
            $exp = []; $expTrend = []; $expTop = [];
            foreach ($days as $d => $byCur) { if ($d < $first) continue; $dd = $d > $today ? $today : $d; foreach ($byCur as $c => [$n, $r]) { $exp[$c][0] = ($exp[$c][0] ?? 0) + $n; $exp[$c][1] = ($exp[$c][1] ?? 0) + $r; $expTrend[$c][$dd][0] = ($expTrend[$c][$dd][0] ?? 0) + $n; $expTrend[$c][$dd][1] = ($expTrend[$c][$dd][1] ?? 0) + $r; } }
            foreach ($prod as $d => $groups) { if ($d < $first) continue; foreach ($groups as $k => [$q, $r]) { $expTop[$k][0] = ($expTop[$k][0] ?? 0) + $q; $expTop[$k][1] = ($expTop[$k][1] ?? 0) + $r; } }
            $since = SalesReport::sinceUtc($p);
            // live SQL path (no daily table) as a second oracle
            $live = SalesReport::revenueRows($s, $since, $far);
            $got = SalesReport::totals($s, $since);
            foreach (array_unique(array_merge(array_keys($exp), array_keys($got), array_keys($live))) as $c) {
                $e = [$exp[$c][0] ?? 0, $exp[$c][1] ?? 0]; $g = [$got[$c]['orders'] ?? 0, $got[$c]['revenue'] ?? 0]; $l = [$live[$c]['orders'] ?? 0, $live[$c]['revenue'] ?? 0];
                if ($e !== $g) $msgs[] = "$s totals {$p}d $c report=" . json_encode($g) . " oracle=" . json_encode($e) . " live=" . json_encode($l);
                elseif ($e !== $l) $msgs[] = "$s ORACLE-vs-live {$p}d $c live=" . json_encode($l) . " oracle=" . json_encode($e);
            }
            $tr = SalesReport::trend($s, $p);
            foreach ($GLOBALS['sd_bucket'] as $bid => [$bd, $bb, $brev]) { if ((string)Db::get()->query("SELECT store_id FROM orders WHERE id=$bid")->fetchColumn() !== $s) continue; $bc = strtolower((string)Db::get()->query("SELECT currency FROM orders WHERE id=$bid")->fetchColumn());
                if (isset($expTrend[$bc][$bd]) && $bb >= $first) { $expTrend[$bc][$bd][0]--; $expTrend[$bc][$bd][1] -= $brev; $expTrend[$bc][$bb][0] = ($expTrend[$bc][$bb][0] ?? 0) + 1; $expTrend[$bc][$bb][1] = ($expTrend[$bc][$bb][1] ?? 0) + $brev; $GLOBALS['sd_bucket_hits'] = ($GLOBALS['sd_bucket_hits'] ?? 0) + 1; } }
            foreach (array_unique(array_merge(array_keys($tr), array_keys($expTrend))) as $c) {
                foreach (array_unique(array_merge(array_keys($tr[$c] ?? []), array_keys($expTrend[$c] ?? []))) as $d) {
                    $g = [$tr[$c][$d]['orders'] ?? 0, $tr[$c][$d]['revenue'] ?? 0]; $e = [$expTrend[$c][$d][0] ?? 0, $expTrend[$c][$d][1] ?? 0];
                    if ($g !== $e) $msgs[] = "$s trend {$p}d $c $d report=" . json_encode($g) . " oracle=" . json_encode($e);
                }
            }
            $top = SalesReport::topProducts($s, $since, 100000); $gTop = [];
            foreach ($top as $c => $rows) foreach ($rows as $row) $gTop[$c . '|' . $row['product_id'] . '|' . (int)($row['variant_id'] ?? 0)] = [$row['quantity'], $row['revenue']];
            foreach (array_unique(array_merge(array_keys($gTop), array_keys($expTop))) as $k) {
                $g = $gTop[$k] ?? [0, 0]; $e = isset($expTop[$k]) ? [$expTop[$k][0], (int)round($expTop[$k][1])] : [0, 0];
                if ($g[0] !== $e[0] || abs($g[1] - $e[1]) > 1) $msgs[] = "$s top {$p}d $k report=" . json_encode($g) . " oracle=" . json_encode($e);
            }
        }
    }
    r36_store('default');
    if ($msgs) {
        if ($handDirty) { $staleExpected++; }
        else { $viol[] = "[$label] " . implode(' || ', array_slice(array_unique($msgs), 0, 6)); }
        return count($msgs);
    }
    return 0;
};

$pick = function (callable $f) use (&$orders, $db) { $ids = array_keys($orders); shuffle($ids); foreach ($ids as $id) { $o = Orders::byId($id) ?? (function () use ($db, $id) { return null; })(); if ($o && $f($o)) return $o; } return null; };
$inStore = function (array $o, callable $f) { r36_store((string)$o['store_id']); try { return $f(); } finally { r36_store('default'); } };
$drain = function () use (&$log) { foreach (['default', 'cz', 'hu'] as $s) { r36_store($s); r50_drain($log, 500, true); } r36_store('default'); };
$paid = fn(array $o) => in_array($o['payment_status'], ['paid', 'partially_refunded'], true) && !in_array($o['status'], ['cancelled', 'refunded'], true);

$ops = [
    'create' => 30, 'pay' => 8, 'fail' => 3, 'transfer_paid' => 4, 'cancel' => 7, 'edit' => 5, 'refund_part' => 7, 'refund_full' => 3, 'dashboard' => 4,
    'rma' => 5, 'invoice' => 4, 'sql_insert_refund' => 3, 'sql_insert_order' => 2, 'sql_update' => 2, 'sql_move_day' => 2, 'tz_change' => 1, 'rebuild' => 2,
];
$bag = []; foreach ($ops as $op => $w) for ($i = 0; $i < $w; $i++) $bag[] = $op;
$k = 0;
for ($step = 1; $step <= $steps; $step++) {
    $op = $step <= 15 ? 'create' : $bag[mt_rand(0, count($bag) - 1)];
    $note = '';
    try {
        switch ($op) {
            case 'create':
                $s = array_keys($TZ)[mt_rand(0, 2)]; r36_store($s);
                $lines = []; $n = mt_rand(1, 3); for ($i = 0; $i < $n; $i++) { [$pid, $vid] = $P[$s][mt_rand(0, count($P[$s]) - 1)]; $lines[] = [$pid, $vid, mt_rand(1, 3)]; }
                $_SESSION = []; Security::setRateDbForTesting(new PDO('sqlite::memory:'));
                foreach ($lines as [$pid, $vid, $q]) Cart::add($pid, $vid, $q);
                $pm = mt_rand(0, 9) < 7 ? 'card' : ($s === 'hu' ? 'card' : 'bank_transfer');
                $in = ['name' => 'B', 'email' => 'b' . mt_rand() . '@example.test', 'phone' => '+421900000036', 'street' => 'S 1', 'city' => 'C', 'zip' => '81101', 'country' => $CUR[$s][1], 'shipping_method' => $SHIP[$s], 'payment_method' => $pm];
                $cart = Cart::enriched(); $in['consents'] = array_fill_keys(array_column(CheckoutService::consentDefinitions($CUR[$s][1], $cart['items'] ?? []), 'key'), true);
                if (mt_rand(0, 4) === 0) $in['coupon_code'] = 'PCT15';
                VatService::reset();
                $r = checkoutExecute($in, require R36_ROOT . '/config.php');
                if (empty($r['order_id'])) { $note = 'checkout refused ' . ($r['error_code'] ?? '?'); break; }
                $oid = (int)$r['order_id']; $orders[$oid] = $s;
                $o = Orders::byId($oid);
                if ($pm === 'card' && mt_rand(0, 9) < 6) PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => "p$oid", 'data' => ['order_id' => $oid, 'amount' => (int)$o['total'], 'currency' => $CUR[$s][0], 'payment_intent' => 'pi_' . $oid]], 'stripe');
                $t = $pickTime($TZ[$s]);
                $db->prepare('UPDATE orders SET created_at = ? WHERE id = ?')->execute([$t, $oid]);   // backdated import of a new row (id watcher)
                $drain();
                $note = "#$oid $s $pm at $t (local " . sd_local($t, $TZ[$s])->format('Y-m-d H:i:s T') . ')';
                break;
            case 'pay':
                $o = $pick(fn($o) => $o['payment_status'] === 'pending' && $o['status'] === 'pending' && $o['payment_method'] === 'card'); if (!$o) break;
                $inStore($o, fn() => PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => "lp{$o['id']}", 'data' => ['order_id' => (int)$o['id'], 'amount' => (int)$o['total'], 'currency' => strtolower($o['currency']), 'payment_intent' => 'pi_' . $o['id']]], 'stripe'));
                $drain(); $note = "late pay #{$o['id']}"; break;
            case 'fail':
                $o = $pick(fn($o) => $o['payment_status'] === 'pending' && $o['payment_method'] === 'card'); if (!$o) break;
                $inStore($o, fn() => PaymentService::applyProviderStatusEvent(['event_type' => 'payment.failed', 'event_id' => "f{$o['id']}", 'data' => ['order_id' => (int)$o['id'], 'amount' => (int)$o['total'], 'currency' => strtolower($o['currency']), 'payment_intent' => 'pi_' . $o['id']]], 'stripe'));
                $note = "fail #{$o['id']}"; break;
            case 'transfer_paid':
                $o = $pick(fn($o) => $o['payment_status'] === 'pending' && $o['payment_method'] === 'bank_transfer' && $o['status'] === 'pending'); if (!$o) break;
                $inStore($o, fn() => PaymentService::recordAdminPayment($o, 'paid', (int)$o['total'], 'transfer in', 'admin'));
                $drain(); $note = "transfer paid #{$o['id']}"; break;
            case 'cancel':
                $o = $pick(fn($o) => !in_array($o['status'], ['cancelled', 'refunded', 'shipped', 'delivered'], true)); if (!$o) break;
                $inStore($o, fn() => OrderWorkflow::transition((int)$o['id'], 'cancelled', 'prop'));
                $drain(); $note = "cancel #{$o['id']} (was {$o['payment_status']})"; break;
            case 'edit':
                $o = $pick(fn($o) => $o['status'] === 'pending' && $o['payment_status'] === 'pending'); if (!$o) break;
                $s = (string)$o['store_id'];
                $r = $inStore($o, function () use ($o, $P, $s) { [$pid] = $P[$s][mt_rand(0, 2)]; $sku = (string)Db::get()->query("SELECT sku FROM products WHERE id=$pid")->fetchColumn(); return ManualOrder::editItems((int)$o['id'], ManualOrder::parseLines($sku . ' ' . mt_rand(1, 4))['lines']); });
                $note = "edit #{$o['id']} " . json_encode($r['error'] ?? 'ok'); break;
            case 'refund_part': case 'refund_full':
                $o = $pick($paid); if (!$o) break;
                $amt = $inStore($o, function () use ($o, $op) { $bal = PaymentService::claimableRefund((int)$o['id']); if ($bal <= 0) return 0; $a = $op === 'refund_full' ? $bal : max(1, (int)($bal * mt_rand(5, 60) / 100)); PaymentService::requestRefundAndQueue((int)$o['id'], $a, 'rq-' . bin2hex(random_bytes(4))); return $a; });
                $drain(); $note = "$op #{$o['id']} $amt"; break;
            case 'dashboard':
                $o = $pick(fn($o) => $paid($o) && $o['payment_method'] === 'card'); if (!$o) break;
                $amt = $inStore($o, function () use ($o) { $bal = PaymentService::refundableBalance((int)$o['id']); if ($bal <= 0) return 0; $a = max(1, (int)($bal * mt_rand(10, 100) / 100)); $rid = 're_d' . bin2hex(random_bytes(4)); PaymentService::bookProviderRefund((int)$o['id'], 'stripe', $rid, $a, 'refund.succeeded', 'stripe_refund_' . $rid, []); return $a; });
                $drain(); $note = "dashboard refund #{$o['id']} $amt"; break;
            case 'invoice':
                $o = $pick(fn($o) => $paid($o) && !InvoiceService::byOrderId((int)$o['id'])); if (!$o) break;
                $inStore($o, fn() => InvoiceService::issue((int)$o['id'])); $drain(); $note = "invoice #{$o['id']}"; break;
            case 'rma':
                $o = $pick(fn($o) => $paid($o) && $o['payment_method'] === 'card'); if (!$o) break;
                $note = $inStore($o, function () use ($o, &$log) {
                    $oid = (int)$o['id'];
                    $inv = InvoiceService::byOrderId($oid) ?? InvoiceService::issue($oid);
                    foreach (Fulfillment::planOrder($oid) as $sid) Fulfillment::ship($sid);
                    foreach (['shipped', 'delivered'] as $st) { try { OrderWorkflow::transition($oid, $st, 'prop'); } catch (Throwable $e) {} }
                    $items = Orders::items($oid); $it = $items[mt_rand(0, count($items) - 1)];
                    [$ok, $rma, $err] = Rma::create($oid, null, 'return', [(int)$it['id'] => 1]);
                    if (!$ok) return "rma #$oid refused $err";
                    Rma::setStatus((int)$rma, 'approved'); $dec = []; foreach (Rma::items((int)$rma) as $ri) $dec[(int)$ri['id']] = ['quantity' => (int)$ri['quantity'], 'disposition' => 'restock'];
                    Rma::receive((int)$rma, $dec, 'rc' . $rma);
                    if (Rma::refundInFlight($oid)) return "rma #$oid received, refund in flight";
                    $outside = Rma::refundedOutsideReturns($oid); $q = InvoiceService::creditableQuantities((int)$rma);
                    if ($outside > 0 && InvoiceService::rmaCreditOwed((int)$inv['id'], (int)$rma, $q, $outside)[1] === 0) { Rma::closeWithoutPayout((int)$rma); return "rma #$oid closed w/o payout"; }
                    $cn = InvoiceService::credit((int)$inv['id'], (int)$rma, $q, 'ic' . $rma, $outside > 0);
                    return "rma #$oid credit {$cn['total_gross']}";
                });
                $drain(); break;
            case 'sql_insert_refund':  // watched by id: should be picked up
                $o = $pick($paid); if (!$o) break;
                $db->prepare("INSERT INTO payment_transactions (order_id, provider, status, amount, currency, idempotency_key, store_id, created_at, updated_at) VALUES (?, 'stripe', 'refund_succeeded', ?, ?, ?, ?, '2026-01-01 00:00:00', '2026-01-01 00:00:00')")
                    ->execute([(int)$o['id'], mt_rand(1, 300), strtolower($o['currency']), 'sql-' . bin2hex(random_bytes(4)), $o['store_id']]);
                $note = "SQL refund row #{$o['id']}"; break;
            case 'sql_insert_order':   // an imported old order: watched by id
                $s = array_keys($TZ)[mt_rand(0, 2)]; [$pid] = $P[$s][mt_rand(0, 2)]; $t = $pickTime($TZ[$s]);
                $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, shipping_addr, payment_method, subtotal, total, currency, store_id, locale, is_synthetic, created_at) VALUES (?, 'delivered', 'paid', 'I', 'i@example.test', '{}', 'card', 777, 777, ?, ?, 'sk', 0, ?)")
                    ->execute(['IMP-' . bin2hex(random_bytes(4)), strtoupper($CUR[$s][0]), $s, $t]);
                $oid = (int)$db->lastInsertId(); $orders[$oid] = $s;
                $db->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, 777, 777)')->execute([$oid, $pid, 'IMP', 'Imported']);
                $note = "SQL imported order #$oid $s at $t"; break;
            case 'sql_update':         // by hand: documented to need --rebuild
                $o = $pick(fn($o) => true); if (!$o) break;
                if (mt_rand(0, 1)) $db->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid' WHERE id = ?")->execute([(int)$o['id']]);
                else $db->prepare("UPDATE orders SET total = total + 100 WHERE id = ?")->execute([(int)$o['id']]);
                $handDirty = true; $note = "SQL update #{$o['id']} (by hand)"; break;
            case 'sql_move_day':       // backdated change by hand
                $o = $pick(fn($o) => true); if (!$o) break;
                $db->prepare("UPDATE orders SET created_at = ? WHERE id = ?")->execute([$pickTime($TZ[$o['store_id']]), (int)$o['id']]);
                $handDirty = true; $note = "SQL move day #{$o['id']} (by hand)"; break;
            case 'tz_change':
                $TZ['cz'] = $TZ['cz'] === 'Asia/Kolkata' ? 'Asia/Kathmandu' : 'Asia/Kolkata';
                $storePatch['stores.cz.timezone'] = $TZ['cz']; $applyStores(); $note = "cz timezone -> {$TZ['cz']}"; break;
            case 'rebuild':
                SalesDaily::refreshAll(true); $handDirty = false; $note = 'refreshAll(rebuild)'; break;
        }
    } catch (Throwable $e) { $note .= ' EXC ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 150); }
    r36_store('default');
    $stats[$op] = ($stats[$op] ?? 0) + 1;
    $n = $compare("step $step $op: $note");
    if ($n && $handDirty) { SalesDaily::refreshAll(true); $handDirty = false; if ($compare("after rebuild, step $step") === 0) {} }
    if ($verbose) echo "$step $op $note" . ($n ? " MISMATCH($n)" : '') . "\n";
}
$jf = array_filter($log, fn($l) => str_starts_with($l, 'JOBFAIL'));
echo json_encode(['driver' => $driver, 'seed' => $seed, 'steps' => $steps, 'orders' => count($orders), 'ops' => $stats, 'violations' => count($viol), 'stale_by_hand' => $staleExpected, 'jobfails' => count($jf), 'halfhour_bucket_shifts' => $GLOBALS['sd_bucket_hits'] ?? 0]), "\n";
foreach (array_slice($viol, 0, 12) as $v) echo "VIOL ", substr($v, 0, 1500), "\n";
foreach (array_slice($jf, 0, 5) as $l) echo "  ", substr($l, 0, 400), "\n";
