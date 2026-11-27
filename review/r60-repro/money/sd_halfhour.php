<?php
// Kolkata (+5:30) store: one paid order at today 00:10 local, one at yesterday 23:50 local. trend(7) per day.
require __DIR__ . '/' . ($argv[1] ?? 'lib50.php');
$db = r50_boot('sqlite', __DIR__ . '/tmp/hh' . md5($argv[1] ?? '') . '.db', true);
$stores = r36_stores(['stores.cz.timezone' => 'Asia/Kolkata', 'stores.cz.catalog_scope' => 'cz']);
(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, $stores); r36_store('cz');
$ins = $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, shipping_addr, payment_method, subtotal, total, currency, store_id, locale, is_synthetic, created_at) VALUES (?, 'paid', 'paid', 'H', 'h@example.test', '{}', 'card', ?, ?, 'CZK', 'cz', 'cs', 0, ?)");
$z = new DateTimeZone('Asia/Kolkata');
foreach ([['today 00:10', 100], ['yesterday 23:50', 1], ['-2 days 00:10', 10000]] as [$when, $amt]) {
    $t = (new DateTimeImmutable($when, $z))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $ins->execute(['H' . $amt, $amt, $amt, $t]); echo "order $amt at local $when = UTC $t\n";
}
foreach (SalesReport::trend('cz', 4)['czk'] as $d => $f) echo "  trend $d: orders {$f['orders']} revenue {$f['revenue']}\n";
