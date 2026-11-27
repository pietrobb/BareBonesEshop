<?php
// R60: backdated order rows and refunds of old orders written without touchOrders() after the days were built.
require __DIR__ . '/lib50.php';
[, $driver, $target] = $argv;
$db = r50_boot($driver, $target, false); r36_store('default');
$id = (int)$db->query('SELECT MAX(id) FROM orders')->fetchColumn() + 1;
$old = gmdate('Y-m-d H:i:s', time() - 400 * 86400);
$db->prepare("INSERT INTO orders (id, order_number, status, payment_status, customer_name, customer_email, shipping_addr, payment_method, subtotal, total, currency, store_id, locale, is_synthetic, created_at) VALUES (?, ?, 'delivered', 'paid', 'B', 'b@example.test', '{}', 'card', 77777, 77777, 'EUR', 'default', 'sk', 0, ?)")->execute([$id, "BACK-$id", $old]);
$o2 = $db->query("SELECT id, created_at FROM orders WHERE payment_status='paid' AND status='delivered' AND created_at < '" . gmdate('Y-m-d', time() - 200 * 86400) . "' ORDER BY id LIMIT 1")->fetch();
$db->prepare("UPDATE orders SET payment_status='partially_refunded' WHERE id=?")->execute([$o2['id']]);
$db->prepare("INSERT INTO payment_transactions (order_id, provider, status, amount, currency, idempotency_key, store_id, created_at, updated_at) VALUES (?, 'stripe', 'refund_succeeded', 333, 'eur', ?, 'default', ?, ?)")->execute([$o2['id'], 'late-' . $o2['id'], gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]);
echo "backdated order $id at $old; refund of old order {$o2['id']} ({$o2['created_at']})\n";
