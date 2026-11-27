<?php
$p = new PDO('sqlite:' . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$N = (int)$argv[2];
$p->exec('BEGIN');
$p->exec("WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM s WHERE n < 10000)
 INSERT INTO customers (store_id, email, name, phone, password_hash, status, locale, email_verified_at, created_at) SELECT 'default', 'cust'||n||'@example.test', 'Customer '||n, '+421', '!x', 'active', 'en', datetime('now'), datetime('now') FROM s");
$c0 = (int)$p->query('SELECT MIN(id) FROM customers')->fetchColumn();
$p->exec("WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM s WHERE n < $N)
 INSERT INTO orders (order_number, status, payment_status, customer_id, customer_name, customer_email, customer_phone, shipping_addr, payment_method, subtotal, shipping_cost, total, currency, locale, ip_address, created_at, updated_at, shipping_method, store_id, vat_scenario, net_total)
 SELECT 'R50-'||printf('%08d', n), CASE n % 7 WHEN 0 THEN 'pending' WHEN 1 THEN 'paid' WHEN 2 THEN 'processing' WHEN 3 THEN 'shipped' WHEN 4 THEN 'delivered' WHEN 5 THEN 'cancelled' ELSE 'refunded' END,
  CASE WHEN n % 7 IN (0,5) THEN 'pending' ELSE 'paid' END, CASE WHEN n % 2 = 0 THEN $c0 + n % 10000 END, 'Customer '||(n % 10000 + 1), 'cust'||(n % 10000 + 1)||'@example.test', '+421',
  json_object('street','Main 1','city','BA','zip','81101','country',CASE WHEN n % 5 < 3 THEN 'AT' ELSE 'SK' END), 'card', 1000 + n % 9000, 399, 1399 + n % 9000, 'EUR', 'en', '10.0.0.1',
  datetime('now', '-' || (($N - n) * 90) || ' seconds'), datetime('now'), 'gls', 'default', CASE WHEN n % 5 < 3 THEN 'oss' ELSE 'domestic' END, 1199 FROM s");
$o0 = (int)$p->query("SELECT MIN(id) FROM orders WHERE order_number LIKE 'R50-%'")->fetchColumn();
[$p0, $p1] = $p->query('SELECT MIN(id), MAX(id) FROM products')->fetch(PDO::FETCH_NUM);
$span = $p1 - $p0 + 1;
$p->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, vat_rate) SELECT o.id, pr.id, pr.sku, pr.name, 1, pr.price, pr.price, 20 FROM orders o JOIN (SELECT 0 k UNION ALL SELECT 1) k JOIN products pr ON pr.id = $p0 + ((o.id * 7919 + k.k * 104729) % $span) WHERE o.order_number LIKE 'R50-%'");
$p->exec("INSERT INTO email_log (store_id, order_id, recipient, template, locale, subject, adapter, status, payload, created_at) SELECT 'default', id, customer_email, 'order_confirmation', 'en', 's', 'smtp', 'sent', '{}', created_at FROM orders WHERE order_number LIKE 'R50-%'");
$p->exec('COMMIT'); $p->exec('ANALYZE');
echo json_encode($p->query("SELECT (SELECT COUNT(*) FROM orders), (SELECT COUNT(*) FROM order_items), (SELECT COUNT(*) FROM products)")->fetch(PDO::FETCH_NUM)), "\n";
