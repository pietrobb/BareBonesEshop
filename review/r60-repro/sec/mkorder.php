<?php
// mkorder.php APPDIR : inserts a delivered, paid order (provider mock) and prints its id
chdir($argv[1]); require $argv[1] . '/src/autoload.php';
$pdo = Db::get();
$ctx = ['seller_country' => 'SK', 'customer_country' => 'SK', 'tax_provider' => 'vat'];
$pid = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$line = ['product_id' => $pid, 'variant_id' => null, 'sku' => 'R59-PB', 'price' => 4200, 'qty' => 1, 'total' => 4200, 'available' => true,
    'currency_mismatch' => false, 'product_type' => 'simple', 'vat_class' => 'standard', 'currency' => 'eur', 'price_formatted' => '', 'total_formatted' => ''];
$items = Cart::applyVat(['items' => [$line], 'subtotal' => 4200, 'currency' => 'eur'], $ctx)['items'];
$snapshot = VatService::buildSnapshot($items, $ctx, ['currency' => 'eur', 'effective_date' => ShopTime::date('Y-m-d')]);
$num = 'R59-' . bin2hex(random_bytes(3));
$pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_addr, subtotal, shipping_cost, tax_amount, discount_amount,
        total, currency, status, payment_status, payment_method, payment_id, payment_method_snapshot, shipping_method, vat_scenario, financial_snapshot, store_id, created_at)
    VALUES (?, 'Attacker', 'att@example.test', '', '{\"country\":\"SK\"}', 4200, 0, 0, 0, ?, 'eur', 'delivered', 'paid', 'mock', ?, ?, 'pickup', 'domestic', ?, 'default', " . Db::now() . ")")
    ->execute([$num, (int)$snapshot['totals']['gross'], 'mock_pay_' . $num, json_encode(['provider' => 'mock', 'service_type' => 'online_card']), json_encode($snapshot)]);
$id = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, 4200, 4200)')->execute([$id, $pid, 'R59-PB', 'R59 item']);
echo $id, "\n";
