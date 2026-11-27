<?php
[, $root, $dir, $driver] = $argv;
require $root . '/src/autoload.php';
require __DIR__ . '/rlib.php';
class DeclineStripe extends PStripe { public function refundWithIdempotency(string $paymentId, int $amount, string $key): array { self::$calls[] = [0, $key]; return ['status' => 'failed', 'id' => 're_f_' . (++self::$n), 'amount' => $amount, 'failure_reason' => 'declined']; } }
$dbn = 'bbe_r52_money_ra_scen2';
$db = rlib_boot($root, $dir, $driver, $dbn);
$J = fn() => implode('; ', rlib_jobs($dir, $dbn));
echo "S10: 2 x 10.00; order-page refund 10.00 requested (job not run yet); return 1 unit; Refunded; then the provider declines the request\n";
[$o, $inv, $it] = rlib_order($db, [[1000, 2, 'standard']]);
PaymentService::requestRefundAndQueue($o, 1000, 'r49-req-' . $o);
foreach (glob($dir . '/jobs-' . $dbn . '/pending/*.json') as $f) rename($f, $f . '.held');
echo "  outside now: ", Rma::refundedOutsideReturns($o), "\n";
$r = rlib_return($o, [$it[0] => 1]); echo "  admin: ", rlib_admin_refunded($r), "\n";
foreach (glob($dir . '/jobs-' . $dbn . '/pending/*.held') as $f) rename($f, substr($f, 0, -5));
(new ReflectionProperty(Adapters::class, 'instances'))->setValue(null, ['payment' => new DeclineStripe()]);
echo "  jobs (provider declines): ", $J(), "\n";
(new ReflectionProperty(Adapters::class, 'instances'))->setValue(null, ['payment' => new PStripe()]);
echo "  state: ", json_encode(rlib_state($db, $o)), " rma status ", Rma::byId($r)['status'], " outside now ", Rma::refundedOutsideReturns($o), "; unexecuted request: ", json_encode(PaymentService::unexecutedRefundRequest(Orders::byId($o)) !== null), "\n";
if ($driver === 'mysql') $db->exec("DROP DATABASE {$dbn}");
