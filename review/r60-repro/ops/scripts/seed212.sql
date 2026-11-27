SET SESSION sql_mode='';
SET SESSION time_zone='+00:00';
DROP TABLE IF EXISTS r50_d; CREATE TABLE r50_d (d INT PRIMARY KEY); INSERT INTO r50_d VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9);
DROP TABLE IF EXISTS r50_seq; CREATE TABLE r50_seq (n INT PRIMARY KEY);
INSERT INTO r50_seq SELECT 1 + a.d + 10*b.d + 100*c.d + 1000*e.d + 10000*f.d + 100000*g.d FROM r50_d a, r50_d b, r50_d c, r50_d e, r50_d f, r50_d g;
-- customers 50k
INSERT INTO customers (store_id, email, name, phone, password_hash, status, locale, created_at, updated_at, email_verified_at)
SELECT 'default', CONCAT('cust', n, '@example.test'), CONCAT('Customer ', n), CONCAT('+42190', LPAD(n, 7, '0')), '!x', 'active', 'en',
  DATE_FORMAT(NOW() - INTERVAL (n MOD 730) DAY, '%Y-%m-%d %H:%i:%s'), DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'), DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')
FROM r50_seq WHERE n <= 50000;
SELECT MIN(id) INTO @c0 FROM customers;
-- orders 500k
INSERT INTO orders (order_number, status, payment_status, customer_id, customer_name, customer_email, customer_phone, shipping_addr, payment_method,
  subtotal, shipping_cost, total, currency, locale, ip_address, created_at, updated_at, vat_total, net_total, shipping_method, store_id, vat_scenario)
SELECT CONCAT('R50-', LPAD(n, 8, '0')),
  ELT(1 + (n MOD 7), 'pending','paid','processing','shipped','delivered','cancelled','refunded'),
  IF(n MOD 7 IN (0,5), 'pending', 'paid'),
  IF(n MOD 2 = 0, @c0 + (n MOD 50000), NULL),
  CONCAT('Customer ', n MOD 50000 + 1), CONCAT('cust', n MOD 50000 + 1, '@example.test'), '+421900000000',
  JSON_OBJECT('name', CONCAT('Customer ', n), 'street', 'Main 1', 'city', 'Bratislava', 'zip', '81101', 'country', IF(n MOD 5 < 3, 'AT', 'SK')), 'card',
  1000 + (n MOD 9000), 399, 1399 + (n MOD 9000), 'EUR', 'en', CONCAT('10.0.', n MOD 255, '.', n MOD 253),
  DATE_FORMAT(NOW() - INTERVAL (n MOD 63072000) SECOND - INTERVAL (500000 - n) MINUTE, '%Y-%m-%d %H:%i:%s'),
  DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'), 200, 1199, 'gls', 'default', IF(n MOD 5 < 3, 'oss', 'domestic')
FROM r50_seq WHERE n <= 500000;
SELECT MIN(id) INTO @o0 FROM orders WHERE order_number LIKE 'R50-%';
SELECT MIN(id), MAX(id) INTO @p0, @p1 FROM products;
-- 2 items per order = 1M
INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, vat_rate, vat_amount, net_amount)
SELECT o.id, p.id, p.sku, p.name, 1 + (o.id MOD 3), p.price, p.price * (1 + (o.id MOD 3)), 20, 0, 0
FROM orders o JOIN r50_d k ON k.d < 2 JOIN products p ON p.id = @p0 + ((o.id * 7919 + k.d * 104729) MOD (@p1 - @p0 + 1))
WHERE o.order_number LIKE 'R50-%';
-- email log 500k, audit log 200k
INSERT INTO email_log (store_id, order_id, recipient, template, locale, subject, adapter, status, payload, created_at, sent_at)
SELECT 'default', o.id, o.customer_email, 'order_confirmation', 'en', CONCAT('Order ', o.order_number), 'smtp', 'sent', '{}', o.created_at, o.created_at FROM orders o WHERE o.order_number LIKE 'R50-%';
INSERT INTO audit_log (store_id, actor, action, entity, entity_id, old_value, new_value, created_at)
SELECT 'default', 'admin', 'order.status', 'order', o.id, '{"status":"paid"}', CONCAT('{"status":"shipped","email":"', o.customer_email, '"}'), o.created_at FROM orders o WHERE o.order_number LIKE 'R50-%' AND o.id MOD 5 = 0;
SELECT (SELECT COUNT(*) FROM customers), (SELECT COUNT(*) FROM orders), (SELECT COUNT(*) FROM order_items), (SELECT COUNT(*) FROM email_log), (SELECT COUNT(*) FROM audit_log);

-- 10k refunds (partial 500 cents) on paid orders
INSERT INTO payment_transactions (order_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response, created_at, updated_at, store_id)
SELECT o.id, 'mock', CONCAT('rf', o.id), 'refund_succeeded', 500, 'eur', CONCAT('r52rf-', o.id), '{}', o.created_at, o.created_at, 'default' FROM orders o WHERE o.payment_status = 'paid' AND o.id MOD 35 = 1;
UPDATE orders SET payment_status = 'partially_refunded' WHERE id IN (SELECT order_id FROM payment_transactions WHERE idempotency_key LIKE 'r52rf-%');
SELECT COUNT(*) FROM payment_transactions;
DROP TABLE r50_seq; DROP TABLE r50_d;
