<?php
[, $root, $dir, $driver] = $argv;
require $root . '/src/autoload.php';
require __DIR__ . '/rlib.php';
$dbn = 'bbe_r52_money_ra_scen';
$db = rlib_boot($root, $dir, $driver, $dbn);
$J = fn() => implode('; ', rlib_jobs($dir, $dbn));
$show = function (string $label, int $order, int $expect) use ($db) { $s = rlib_state($db, $order); printf("  => %-58s refunded %5d credit notes %5d | expected refunded %5d %s\n", $label, $s['refunded'], $s['credit_notes'], $expect, $s['refunded'] === $expect && $s['credit_notes'] === $expect ? 'OK' : '<<< MISMATCH'); };
echo "S1: 2 x 10.00; dashboard refund 10.00 (credit-note job ran); return both units\n";
[$o, $inv, $it] = rlib_order($db, [[1000, 2, 'standard']]);
rlib_dashboard($o, 1000); echo "  jobs: ", $J(), "\n";
$r = rlib_return($o, [$it[0] => 2]); echo "  admin: ", rlib_admin_refunded($r), "; jobs: ", $J(), "\n";
$show('both units back, 10 outside', $o, 2000);

echo "S1b: same, the return marked Refunded before the dashboard refund's credit-note job ran\n";
[$o, $inv, $it] = rlib_order($db, [[1000, 2, 'standard']]);
rlib_dashboard($o, 1000);
foreach (glob($dir . '/jobs-' . $dbn . '/pending/*.json') as $f) { $j = json_decode(file_get_contents($f), true); if ($j['type'] === 'refund_credit_note') { $held = $f . '.held'; rename($f, $held); } }
$r = rlib_return($o, [$it[0] => 2]); echo "  admin: ", rlib_admin_refunded($r), "\n";
foreach (glob($dir . '/jobs-' . $dbn . '/pending/*.held') as $f) rename($f, substr($f, 0, -5));
echo "  jobs: ", $J(), "\n";
$show('both units back, 10 outside, credit note job late', $o, 2000);

echo "S2: 2 x 10.00; dashboard 5.00; return unit 1; later return unit 2\n";
[$o, $inv, $it] = rlib_order($db, [[1000, 2, 'standard']]);
rlib_dashboard($o, 500); $J();
$r = rlib_return($o, [$it[0] => 1]); echo "  admin #1: ", rlib_admin_refunded($r), "; jobs: ", $J(), "\n";
$show('one unit back, 5 outside', $o, 1000);
$r = rlib_return($o, [$it[0] => 1]); echo "  admin #2: ", rlib_admin_refunded($r), "; jobs: ", $J(), "\n";
$show('both units back', $o, 2000);

echo "S3: 2 x 10.00; return unit 1 (refunded); then dashboard 5.00; then return unit 2\n";
[$o, $inv, $it] = rlib_order($db, [[1000, 2, 'standard']]);
$r = rlib_return($o, [$it[0] => 1]); echo "  admin #1: ", rlib_admin_refunded($r), "; jobs: ", $J(), "\n";
rlib_dashboard($o, 500); $J();
$r = rlib_return($o, [$it[0] => 1]); echo "  admin #2: ", rlib_admin_refunded($r), "; jobs: ", $J(), "\n";
$show('both units back, 5 goodwill in between', $o, 2000);

echo "S4: 3 x 10.00; order-page admin refund 12.00 (no return); return 2 units\n";
[$o, $inv, $it] = rlib_order($db, [[1000, 3, 'standard']]);
PaymentService::requestRefundAndQueue($o, 1200, 'r49-req-' . $o); echo "  jobs: ", $J(), "\n";
$r = rlib_return($o, [$it[0] => 2]); echo "  admin: ", rlib_admin_refunded($r), "; jobs: ", $J(), "\n";
$show('2 of 3 back, 12 outside', $o, 2000);

echo "S5: two VAT rates 20.00 (23%) + 10.00 (5%); dashboard 7.00; return both lines\n";
[$o, $inv, $it] = rlib_order($db, [[2000, 1, 'standard'], [1000, 1, 'books']]);
rlib_dashboard($o, 700); $J();
$r = rlib_return($o, [$it[0] => 1, $it[1] => 1]); echo "  admin: ", rlib_admin_refunded($r), "; jobs: ", $J(), "\n";
$show('all back, 7 outside', $o, 3000);

echo "S6: 2 x 10.00; dashboard 10.00; two returns of one unit each, both received, then Refunded on each\n";
[$o, $inv, $it] = rlib_order($db, [[1000, 2, 'standard']]);
rlib_dashboard($o, 1000); $J();
$ra = rlib_return($o, [$it[0] => 1]); $rb = rlib_return($o, [$it[0] => 1]);
echo "  admin A: ", rlib_admin_refunded($ra), "; jobs: ", $J(), "\n";
echo "  admin B: ", rlib_admin_refunded($rb), "; jobs: ", $J(), "\n";
$show('both back via two returns, 10 outside', $o, 2000);

echo "S7: CZK 2 x 250.00; dashboard 250.00; return both\n";
[$o, $inv, $it] = rlib_order($db, [[25000, 2, 'standard']], 'czk');
rlib_dashboard($o, 25000); $J();
$r = rlib_return($o, [$it[0] => 2]); echo "  admin: ", rlib_admin_refunded($r), "; jobs: ", $J(), "\n";
$show('CZK both back, half outside', $o, 50000);

echo "S8: bank transfer order 2 x 10.00 (manual refunds); manual order refund 10.00; return both\n";
[$o, $inv, $it] = rlib_order($db, [[1000, 2, 'standard']], 'eur', 'bank_transfer');
try { PaymentService::requestRefundAndQueue($o, 1000, 'r49-man-' . $o); echo "  jobs: ", $J(), "\n"; } catch (Throwable $e) { echo "  manual refund: ", $e->getMessage(), "\n"; }
echo "  state after manual refund: ", json_encode(rlib_state($db, $o)), " outside ", Rma::refundedOutsideReturns($o), "\n";
$r = rlib_return($o, [$it[0] => 2]); echo "  admin: ", rlib_admin_refunded($r), "; jobs: ", $J(), "\n";
$show('manual: both back, 10 outside', $o, 2000);

echo "S9: 2 x 10.00; dashboard 15.00; return 1 unit (closes); return the other unit\n";
[$o, $inv, $it] = rlib_order($db, [[1000, 2, 'standard']]);
rlib_dashboard($o, 1500); $J();
$r = rlib_return($o, [$it[0] => 1]); echo "  admin #1: ", rlib_admin_refunded($r), "; jobs: ", $J(), "\n";
$r = rlib_return($o, [$it[0] => 1]); echo "  admin #2: ", rlib_admin_refunded($r), "; jobs: ", $J(), "\n";
$show('both back, 15 outside', $o, 2000);
if ($driver === 'mysql') $db->exec("DROP DATABASE {$dbn}");
