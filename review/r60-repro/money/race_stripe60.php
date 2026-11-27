<?php
// R60: Stripe Checkout handler vs an admin edit committing between the handler's start and its lock; currency mismatch.
require __DIR__ . '/lib50.php';
[, $target] = $argv;
$db = r50_boot('mysql', $target, true);
$stores = r36_stores(['stores.default.legal.iban' => 'SK3112000000198742637541']);
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores); InvoiceService::setStoresConfigForTesting($stores); r36_store('default'); r50_set_pricing();
$wh = (int)$db->query("SELECT id FROM warehouses WHERE store_id='default' AND is_default=1")->fetchColumn();
$p = r50_product($db, 'default', 'RP', 2000, 'eur', [$wh => 100]); r50_product($db, 'default', 'RQ', 1500, 'eur', [$wh => 100]);
$mk = function () use ($p) { $r = r36_checkout([[$p, 1]], ['country' => 'SK', 'payment_method' => 'card', 'shipping_method' => 'pickup']); return (int)$r['order_id']; };
// 1. edit holds the lock with a raised total while the handler starts
$o = $mk(); $t = (int)Orders::byId($o)['total'];
$pid = pcntl_fork();
if ($pid === 0) { $db2 = r50_boot('mysql', $target, false); r36_store('default'); $db2->beginTransaction(); Orders::lockWithin($o); $db2->exec("UPDATE orders SET total = total + 1500 WHERE id = $o"); usleep(1500000); $db2->commit(); exit(0); }
usleep(300000);
$db = r50_boot('mysql', $target, false); r36_store('default');
try { applyStripePaymentCompleted(['id' => 'cs_test_' . $o, 'amount_total' => $t, 'currency' => 'eur', 'payment_intent' => 'pi_' . $o, 'payment_status' => 'paid'], 'cs_test_' . $o, $o); echo "1: no exception\n"; } catch (Throwable $e) { echo "1: EXC ", $e->getMessage(), "\n"; }
pcntl_waitpid($pid, $st);
$x = Orders::byId($o); echo "1: order total {$x['total']} payment {$x['payment_status']} inTx=", var_export($db->inTransaction(), true), "\n";
// 2. currency mismatch
$o = $mk(); $t = (int)Orders::byId($o)['total'];
try { applyStripePaymentCompleted(['id' => 'cs_test_x' . $o, 'amount_total' => $t, 'currency' => 'czk', 'payment_intent' => 'pi_x' . $o], 'cs_test_x' . $o, $o); echo "2: no exception\n"; } catch (Throwable $e) { echo "2: EXC ", $e->getMessage(), "\n"; }
$x = Orders::byId($o); echo "2: payment {$x['payment_status']}\n";
// 3. partial (smaller) amount, then correct one
try { applyStripePaymentCompleted(['id' => 'cs_test_y' . $o, 'amount_total' => $t - 1, 'currency' => 'eur', 'payment_intent' => 'pi_y' . $o], 'cs_test_y' . $o, $o); echo "3: no exception\n"; } catch (Throwable $e) { echo "3: EXC ", $e->getMessage(), "\n"; }
applyStripePaymentCompleted(['id' => 'cs_test_z' . $o, 'amount_total' => $t, 'currency' => 'eur', 'payment_intent' => 'pi_z' . $o], 'cs_test_z' . $o, $o);
$x = Orders::byId($o); echo "3: payment {$x['payment_status']} payment_id {$x['payment_id']}\n";
// 4. generic: partial capture amount < total
$o = $mk(); $t = (int)Orders::byId($o)['total'];
try { PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => "pp$o", 'data' => ['order_id' => $o, 'amount' => $t - 100, 'currency' => 'eur', 'payment_intent' => 'pi_' . $o]], 'stripe'); echo "4: no exc\n"; } catch (Throwable $e) { echo "4: EXC ", $e->getMessage(), "\n"; }
$x = Orders::byId($o); echo "4: payment {$x['payment_status']}; ledger ", json_encode($db->query("SELECT status, amount FROM payment_transactions WHERE order_id=$o")->fetchAll(PDO::FETCH_ASSOC)), "\n";
// retry of the same event id after the mismatch: still refused (idempotency unmarked)
try { PaymentService::applyProviderStatusEvent(['event_type' => 'payment.paid', 'event_id' => "pp$o", 'data' => ['order_id' => $o, 'amount' => $t, 'currency' => 'eur', 'payment_intent' => 'pi_' . $o]], 'stripe'); echo "4b: same event id, right amount -> applied\n"; } catch (Throwable $e) { echo "4b: EXC ", $e->getMessage(), "\n"; }
$x = Orders::byId($o); echo "4b: payment {$x['payment_status']}\n";
