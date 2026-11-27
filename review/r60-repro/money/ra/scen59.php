<?php
// R59 return credit notes at the returned goods' rates, balancing credit notes, legacy (pre-R58) closed returns.
// php scen59.php <tree> <dir> sqlite|mysql
[, $root, $dir, $driver] = $argv;
require $root . '/src/autoload.php';
require __DIR__ . '/rlib.php';
require dirname(__DIR__) . '/cn_check.php';
$dbn = 'bbe_r60_money_scen59';
$db = rlib_boot($root, $dir, $driver, $dbn);
$J = fn() => implode('; ', rlib_jobs($dir, $dbn));
$rates = function (int $o) use ($db) {
    $out = [];
    foreach ($db->query("SELECT number, total_gross, vat_breakdown, credit_snapshot FROM invoices WHERE order_id=$o ORDER BY id")->fetchAll() as $i) {
        $bd = []; foreach (json_decode($i['vat_breakdown'], true) ?: [] as $k => $b) $bd[] = rtrim(rtrim((string)$k, '0'), '.') . '%:' . ((int)$b['net'] + (int)$b['vat']);
        $out[] = $i['number'] . '[' . (json_decode((string)$i['credit_snapshot'], true)['kind'] ?? ($i['credit_snapshot'] ? 'item' : 'inv')) . ' ' . $i['total_gross'] . ' ' . implode(' ', $bd) . ']';
    }
    return implode(' ', $out);
};
$left = function (int $o) use ($db) {
    $inv = $db->query("SELECT * FROM invoices WHERE order_id=$o AND type='invoice'")->fetch(); $l = [];
    foreach (json_decode($inv['vat_breakdown'], true) as $k => $b) $l[$k] = (int)$b['net'] + (int)$b['vat'];
    foreach ($db->query("SELECT vat_breakdown FROM invoices WHERE order_id=$o AND type='credit_note'")->fetchAll(PDO::FETCH_COLUMN) as $bd) foreach (json_decode($bd, true) ?: [] as $k => $b) $l[$k] = ($l[$k] ?? 0) + (int)$b['net'] + (int)$b['vat'];
    $s = []; foreach ($l as $k => $g) $s[] = rtrim(rtrim((string)$k, '0'), '.') . '%:' . $g; return implode(' ', $s);
};
$fin = function (string $label, int $o, int $expect) use ($db, $rates, $left) {
    $s = rlib_state($db, $o);
    printf("  => %-48s refunded %5d CN %5d (expect %5d) %s | uncredited %s | cn_check %s\n", $label, $s['refunded'], $s['credit_notes'], $expect,
        $s['refunded'] === $expect && $s['credit_notes'] === $expect ? 'OK' : '<<< MISMATCH', $left($o), json_encode(cn_check($db, [$o], true)));
};
$names = function (int $o) use ($db) { return json_encode($db->query("SELECT cni.name, cni.gross, cni.rate_basis_points FROM credit_note_items cni JOIN invoices i ON i.id = cni.credit_note_id WHERE i.order_id = $o AND cni.gross < 0")->fetchAll(PDO::FETCH_NUM), JSON_UNESCAPED_UNICODE); };

echo "S1: A 20.00 @23 + B 10.00 @5; dashboard 20.00; return A (closed, balancing note); return B\n";
[$o, $inv, $it] = rlib_order($db, [[2000, 1, 'standard'], [1000, 1, 'reduced2']]);
rlib_dashboard($o, 2000); $J();
$r = rlib_return($o, [$it[0] => 1]); echo "  admin A: ", rlib_admin_refunded($r), "; ", $J(), "\n";
echo "  docs: ", $rates($o), "\n  reversing lines: ", $names($o), "\n";
$r = rlib_return($o, [$it[1] => 1]); echo "  admin B: ", rlib_admin_refunded($r), "; ", $J(), "\n";
echo "  docs: ", $rates($o), "\n"; $fin('S1 all back', $o, 3000);

echo "S2 (legacy, closed before round 23): as S1, A's return closed without its balancing note (as R57 left it); then B returned\n";
[$o, $inv, $it] = rlib_order($db, [[2000, 1, 'standard'], [1000, 1, 'reduced2']]);
rlib_dashboard($o, 2000); $J();
$r = rlib_return($o, [$it[0] => 1]); echo "  admin A: ", rlib_admin_refunded($r), "\n";
$bal = $db->query("SELECT id FROM invoices WHERE order_id=$o AND credit_snapshot LIKE '%outside_balance%'")->fetchColumn();
if ($bal) { $db->exec("DELETE FROM credit_note_items WHERE credit_note_id=$bal"); $db->exec("DELETE FROM invoices WHERE id=$bal"); }
$db->exec("UPDATE rma_requests SET outside_refund_keys = NULL WHERE id = $r");   // pre-R56: no keys
echo "  (balancing note $bal removed, keys cleared) docs: ", $rates($o), " uncredited ", $left($o), "\n";
$r = rlib_return($o, [$it[1] => 1]); echo "  admin B: ", rlib_admin_refunded($r), "; ", $J(), "\n";
echo "  docs: ", $rates($o), "\n"; $fin('S2 all back (B paid 10.00?)', $o, 3000);

echo "S3: A 20 @23 + B 10 @19 + C 10 @5; dashboard 10.00; return C (closed); return A (credit less nothing); refund rest\n";
[$o, $inv, $it] = rlib_order($db, [[2000, 1, 'standard'], [1000, 1, 'reduced'], [1000, 1, 'reduced2']]);
rlib_dashboard($o, 1000); $J();
$r = rlib_return($o, [$it[2] => 1]); echo "  admin C: ", rlib_admin_refunded($r), "; ", $J(), "\n";
echo "  docs: ", $rates($o), "\n  reversing lines: ", $names($o), "\n";
$r = rlib_return($o, [$it[0] => 1]); echo "  admin A: ", rlib_admin_refunded($r), "; ", $J(), "\n";
echo "  docs: ", $rates($o), " uncredited ", $left($o), "\n";
PaymentService::requestRefundAndQueue($o, PaymentService::claimableRefund($o), 'rest-' . $o); echo "  rest: ", $J(), "\n";
$fin('S3 all back', $o, 4000);

echo "S4: A 2 x 15.00 @23 + B 3 x 5.99 @5 (rounding); dashboard 7.77, 3.33; return 1 A, 2 B, closed/credited; repeat; refund rest\n";
[$o, $inv, $it] = rlib_order($db, [[1500, 2, 'standard'], [599, 3, 'reduced2']]);
rlib_dashboard($o, 777); rlib_dashboard($o, 333); $J();
foreach ([[$it[0] => 1, $it[1] => 2], [$it[1] => 1], [$it[0] => 1]] as $k => $units) { $r = rlib_return($o, $units); echo "  admin #$k: ", rlib_admin_refunded($r), "; ", $J(), " | uncredited ", $left($o), "\n"; }
$fin('S4 all back', $o, 4797);

echo "S5: net rest: 3 x 3.33 @23 credited unit by unit (rounding per note), then a refund\n";
[$o, $inv, $it] = rlib_order($db, [[333, 3, 'standard'], [101, 1, 'reduced']]);
foreach ([1, 1, 1] as $k => $q) { $r = rlib_return($o, [$it[0] => $q]); echo "  admin #$k: ", rlib_admin_refunded($r), "; ", $J(), " | uncredited ", $left($o), "\n"; }
$fin('S5 A credited in 3 notes', $o, 999);
echo "  docs: ", $rates($o), "\n";
if ($driver === 'mysql') $db->exec("DROP DATABASE {$dbn}");
