<?php
[, $root, $dir, $driver] = $argv;
require $root . '/src/autoload.php';
require __DIR__ . '/rlib.php';
$dbn = 'bbe_r52_money_ra_scen8';
$db = rlib_boot($root, $dir, $driver, $dbn);
$J = fn() => implode('; ', rlib_jobs($dir, $dbn));
echo "S8b: bank transfer order 2 x 10.00, both units returned, admin Refunded (manual refund recorded): sales report\n";
[$o, $inv, $it] = rlib_order($db, [[1000, 2, 'standard']], 'eur', 'bank_transfer');
$r = rlib_return($o, [$it[0] => 2]); echo "  admin: ", rlib_admin_refunded($r), "; jobs: ", $J(), "\n";
echo "  order: ", json_encode($db->query("SELECT status, payment_status, total FROM orders WHERE id=$o")->fetch()), " ledger refunds ", PaymentService::refundedAmount($o), " order_refunds ", json_encode($db->query("SELECT status, amount FROM order_refunds WHERE order_id=$o")->fetchAll()), "\n";
$since = SalesReport::sinceUtc(1);
echo "  report today: totals ", json_encode(SalesReport::totals('default', $since)), " top ", json_encode(SalesReport::topProducts('default', $since)), "\n";
echo "  credit notes: ", $db->query("SELECT COALESCE(SUM(total_gross),0) FROM invoices WHERE order_id=$o AND type='credit_note'")->fetchColumn(), "\n";
