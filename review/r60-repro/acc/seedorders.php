<?php
// php seedorders.php DB N
$db = new PDO('sqlite:' . $argv[1]); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$n = (int)$argv[2]; mt_srand(59);
$tpl = $db->query('select * from orders order by id limit 1')->fetch(PDO::FETCH_ASSOC);
$itpl = $db->query('select * from order_items order by id limit 1')->fetch(PDO::FETCH_ASSOC);
$prods = $db->query("select id, sku, name, price from products where status='active'")->fetchAll(PDO::FETCH_ASSOC);
unset($tpl['id'], $itpl['id']);
$cols = array_keys($tpl); $icols = array_keys($itpl);
$ins = $db->prepare('insert into orders (' . implode(',', $cols) . ') values (' . implode(',', array_fill(0, count($cols), '?')) . ')');
$iins = $db->prepare('insert into order_items (' . implode(',', $icols) . ') values (' . implode(',', array_fill(0, count($icols), '?')) . ')');
$db->beginTransaction();
$statuses = [['delivered','paid'],['delivered','paid'],['shipped','paid'],['paid','paid'],['pending','pending'],['cancelled','cancelled'],['refunded','refunded']];
for ($i = 0; $i < $n; $i++) {
  $ts = time() - mt_rand(0, 400 * 86400); $created = gmdate('Y-m-d H:i:s', $ts);
  $o = $tpl; [$o['status'], $o['payment_status']] = $statuses[mt_rand(0, count($statuses) - 1)];
  $o['order_number'] = 'SEED' . ($argv[3] ?? '') . '-' . sprintf('%06d', $i); $o['created_at'] = $created; $o['updated_at'] = $created;
  $o['customer_email'] = "seed$i@example.com"; $o['customer_name'] = "Seed $i";
  $lines = []; $sub = 0;
  for ($k = 0, $m = mt_rand(1, 3); $k < $m; $k++) { $p = $prods[mt_rand(0, count($prods) - 1)]; $q = mt_rand(1, 3); $lines[] = [$p, $q]; $sub += $p['price'] * $q; }
  $o['subtotal'] = $sub; $o['total'] = $sub + $o['shipping_cost'] + $o['payment_fee']; $o['tax_amount'] = $o['vat_total'] = (int)round($o['total'] * 23 / 123); $o['net_total'] = $o['total'] - $o['vat_total'];
  $ins->execute(array_values($o)); $oid = (int)$db->lastInsertId();
  foreach ($lines as [$p, $q]) { $it = $itpl; $it['order_id'] = $oid; $it['product_id'] = $p['id']; $it['sku'] = $p['sku']; $it['name'] = $p['name']; $it['quantity'] = $q; $it['unit_price'] = $p['price']; $it['total'] = $p['price'] * $q; $it['vat_amount'] = (int)round($it['total'] * 23 / 123); $it['net_amount'] = $it['total'] - $it['vat_amount']; $it['reserved_qty'] = 0; $iins->execute(array_values($it)); }
}
$db->commit(); echo "inserted $n\n";
