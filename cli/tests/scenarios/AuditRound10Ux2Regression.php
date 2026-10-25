<?php

// Round 10 review (2026-10-25): UX 6–10.
echo "\n--- Audit round 10 (UX 6-10): translated admin statuses ---\n";

// UX6: admin lists show order, payment, return, invoice, e-mail, customer and admin-user statuses in the admin's
// language, never the raw code; the badge class keeps the code.
$r10xAdminDir = dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin';
$r10xRaw = [];
foreach (glob($r10xAdminDir . '/*.php') ?: [] as $r10xFile) {
    foreach (file($r10xFile) ?: [] as $r10xNo => $r10xLine) {
        if (preg_match('/"><\?= esc\(\(?(?:string\))?\$\w+\[\'(?:status|payment_status)\'\](?: \?\? \'\w*\')?\) \?><\/span>/', $r10xLine)) {
            $r10xRaw[] = basename($r10xFile) . ':' . ($r10xNo + 1);
        }
    }
}
assert_equals([], $r10xRaw, 'R10 UX6: no admin status badge prints the raw status code');
$r10xOrders = (string)file_get_contents($r10xAdminDir . '/Orders.php');
assert_true(!preg_match('/<option value="<\?= (?:esc\()?\$(?:s|value)\)? \?>"[^>]*><\?= (?:esc\()?\$(?:s|value)\)? \?><\/option>/', $r10xOrders),
    'R10 UX6: order and payment status filters and transitions are labelled, not raw codes');
$r10xLocale = I18n::locale();
try {
    I18n::setLocale('sk');
    assert_true(OrderStatusLabels::order('shipped') !== 'shipped' && OrderStatusLabels::payment('refund_pending') !== 'refund_pending', 'R10 UX6: order and payment statuses have Slovak labels');
    foreach (['admin.rma.status.' => Rma::STATUSES, 'admin.customer.status.' => Customers::ADMIN_STATUSES, 'admin.user.status.' => ['active', 'disabled'], 'admin.entitlement.status.' => ['active', 'revoked'],
              'admin.invoice.status.' => [InvoiceService::STATUS_ISSUED, InvoiceService::STATUS_CANCELLED]] as $r10xPrefix => $r10xCodes) {
        $r10xMissing = array_values(array_filter($r10xCodes, static fn(string $code): bool => I18n::t($r10xPrefix . $code) === $r10xPrefix . $code));
        assert_equals([], $r10xMissing, "R10 UX6: every {$r10xPrefix}* code has a label");
    }
} finally {
    I18n::setLocale($r10xLocale);
}

// UX7: an order entered in the admin is priced and reserved like a storefront checkout, and a pending order's lines
// can be changed while the units it already holds count as available.
echo "\n--- Audit round 10 (UX 7): manual orders and editing a pending order's lines ---\n";
ConsentService::setDefinitionsForTesting([]);
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
$r10xWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
$r10xProduct = $pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
    VALUES (:id, 'default', 1, :name, :slug, :sku, 'simple', :price, 'eur', 'active', :stock, 'deny')");
$r10xStock = $pdo->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', :wh, :sku, :qty, 0)");
foreach ([[9771, 'R10X-A', 1000, 3], [9772, 'R10X-B', 500, 10], [9773, 'R10X-C', 700, 10]] as [$r10xId, $r10xSku, $r10xPrice, $r10xQty]) {
    $r10xProduct->execute([':id' => $r10xId, ':name' => $r10xSku . ' item', ':slug' => strtolower($r10xSku), ':sku' => $r10xSku, ':price' => $r10xPrice, ':stock' => $r10xQty]);
    $r10xStock->execute([':wh' => $r10xWarehouse, ':sku' => $r10xSku, ':qty' => $r10xQty]);
}
$r10xReserved = static fn(string $sku): int => (int)$pdo->query("SELECT reserved FROM warehouse_stock WHERE sku = " . $pdo->quote($sku))->fetchColumn();
$r10xLines = static fn(int $orderId): array => array_map(static fn(array $i): string => $i['sku'] . '×' . $i['quantity'], Orders::items($orderId));
$r10xBuyer = ['name' => 'R10 Phone Buyer', 'email' => 'r10x-phone@example.com', 'phone' => '+421900000000', 'country' => 'SK',
    'street' => 'Telefónna 1', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'packeta', 'payment_method' => 'bank_transfer', 'locale' => 'sk'];

$r10xParsed = ManualOrder::parseLines("R10X-A 2\nR10X-B x 1\n\nR10X-NOPE 1");
assert_equals([['R10X-A', 2], ['R10X-B', 1]], array_map(static fn(array $l): array => [$l['sku'], $l['qty']], $r10xParsed['lines']), 'R10 UX7: "SKU qty" lines are parsed');
assert_equals(['R10X-NOPE'], $r10xParsed['errors'], 'R10 UX7: an unknown SKU is reported, not dropped silently');

// The admin's own session cart survives the manual order; the order matches what the storefront charges for the same lines.
// What the storefront charges for the same lines (that order is cancelled again, releasing its stock).
$_SESSION['cart'] = [];
foreach ($r10xParsed['lines'] as $r10xLine) Cart::add($r10xLine['product_id'], null, $r10xLine['qty']);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.10.77';
$r10xCheckout = CheckoutService::execute(['email' => 'r10x-web@example.com'] + $r10xBuyer);
$r10xWebTotal = (int)($r10xCheckout['total'] ?? -2);
if (!empty($r10xCheckout['order_id'])) OrderWorkflow::transition((int)$r10xCheckout['order_id'], 'cancelled', 'R10 UX7 parity order');
Cart::clear();
assert_equals([true, 0, 0], [!empty($r10xCheckout['success']), $r10xReserved('R10X-A'), $r10xReserved('R10X-B')], 'R10 UX7: the storefront reference order was placed and cancelled');
$_SESSION['cart'] = ['p_9773' => ['product_id' => 9773, 'variant_id' => null, 'qty' => 1]];
JobQueue::pull(500);
$r10xOrdersBefore = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$r10xCreated = ManualOrder::create($r10xParsed['lines'], $r10xBuyer, true);
$r10xId = (int)($r10xCreated['order_id'] ?? 0);
$r10xOrder = Orders::byId($r10xId) ?? [];
assert_true($r10xCreated['ok'] && $r10xId > 0, 'R10 UX7: the manual order is created (' . ($r10xCreated['error'] ?? 'ok') . ' ' . ($r10xCreated['detail'] ?? '') . ')');
assert_equals(['pending', 'bank_transfer', 'sk', ['R10X-A×2', 'R10X-B×1'], 2500], [(string)($r10xOrder['status'] ?? ''), (string)($r10xOrder['payment_method'] ?? ''),
    (string)($r10xOrder['locale'] ?? ''), $r10xLines($r10xId), (int)($r10xOrder['subtotal'] ?? 0)], 'R10 UX7: status, payment, language, lines and subtotal of the manual order');
assert_equals([2, 1], [$r10xReserved('R10X-A'), $r10xReserved('R10X-B')], 'R10 UX7: the manual order reserves its stock');
assert_equals(['p_9773' => ['product_id' => 9773, 'variant_id' => null, 'qty' => 1]], $_SESSION['cart'] ?? null, "R10 UX7: the admin's session cart is left as it was");
assert_true(!array_key_exists('customer_id', $_SESSION), 'R10 UX7: pricing for the buyer leaves no customer signed in to the admin session');
$r10xSnapshot = json_decode((string)($r10xOrder['financial_snapshot'] ?? ''), true);
assert_equals((int)($r10xOrder['total'] ?? -1), (int)($r10xSnapshot['totals']['gross'] ?? -2), 'R10 UX7: the order total is the frozen financial snapshot');
$r10xMails = array_values(array_filter(JobQueue::pull(500), static fn(array $job): bool => ($job['payload']['template'] ?? '') === 'order_confirmation'
    && (int)($job['payload']['order_id'] ?? 0) === $r10xId));
assert_equals(1, count($r10xMails), 'R10 UX7: "notify" e-mails the bank-transfer buyer the order confirmation');
assert_equals($r10xWebTotal, (int)($r10xOrder['total'] ?? -1), 'R10 UX7: the manual order costs what the storefront checkout charges for the same lines');

$r10xShort = ManualOrder::create(ManualOrder::parseLines('R10X-A 5')['lines'], $r10xBuyer, false);
assert_equals([false, 'admin.order.manual.not_enough_stock', $r10xOrdersBefore + 1], [$r10xShort['ok'], $r10xShort['error'], (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()],
    'R10 UX7: more units than the stock holds are refused, never silently reduced, and no order is created');

// Edit: 3 × A while the order already holds 2 of the 3 units — the held units count, so the change succeeds.
$r10xEdit = ManualOrder::editItems($r10xId, ManualOrder::parseLines("R10X-A 3\nR10X-C 1")['lines']);
$r10xEdited = Orders::byId($r10xId) ?? [];
assert_true($r10xEdit['ok'], 'R10 UX7: the pending order\'s lines are changed (' . ($r10xEdit['error'] ?? 'ok') . ' ' . ($r10xEdit['detail'] ?? '') . ')');
assert_equals([['R10X-A×3', 'R10X-C×1'], 3700, 3, 0, 1], [$r10xLines($r10xId), (int)($r10xEdited['subtotal'] ?? 0), $r10xReserved('R10X-A'), $r10xReserved('R10X-B'), $r10xReserved('R10X-C')],
    'R10 UX7: lines, subtotal and reservations follow the edit (B released, C reserved)');
$r10xEditedSnapshot = json_decode((string)($r10xEdited['financial_snapshot'] ?? ''), true);
assert_equals([(int)$r10xEdited['total'], 3700], [(int)($r10xEditedSnapshot['totals']['gross'] ?? -1), (int)($r10xEditedSnapshot['totals']['subtotal'] ?? -1)], 'R10 UX7: the edit freezes a new financial snapshot');
$r10xTooMany = ManualOrder::editItems($r10xId, ManualOrder::parseLines('R10X-A 4')['lines']);
assert_equals([false, 'admin.order.manual.not_enough_stock', ['R10X-A×3', 'R10X-C×1'], 3, 1], [$r10xTooMany['ok'], $r10xTooMany['error'], $r10xLines($r10xId), $r10xReserved('R10X-A'), $r10xReserved('R10X-C')],
    'R10 UX7: a refused edit leaves the lines and the reservation untouched');
$r10xAudit = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE entity = 'order' AND entity_id = :id AND note = 'Order items edited by admin'");
$r10xAudit->execute([':id' => $r10xId]);
assert_equals(1, (int)$r10xAudit->fetchColumn(), 'R10 UX7: the edit is in the audit log');

// Only an order nobody has started paying can change: a gateway session would charge the old amount.
$pdo->prepare("UPDATE orders SET payment_id = 'cs_r10x' WHERE id = :id")->execute([':id' => $r10xId]);
assert_equals('admin.order.edit.payment_started', Orders::editRefusal(Orders::byId($r10xId)), 'R10 UX7: no edit once a payment session exists');
$pdo->prepare("UPDATE orders SET payment_id = NULL, status = 'paid', payment_status = 'paid' WHERE id = :id")->execute([':id' => $r10xId]);
assert_equals('admin.order.edit.not_pending', Orders::editRefusal(Orders::byId($r10xId)), 'R10 UX7: no edit of a paid order');
assert_equals([false, 'admin.order.edit.not_pending'], array_values(array_intersect_key(ManualOrder::editItems($r10xId, ManualOrder::parseLines('R10X-B 1')['lines']), ['ok' => 1, 'error' => 1])),
    'R10 UX7: editItems refuses a paid order');

// Admin screens: the form and the line editor render; the editor names the reason when the lines cannot change.
if (!function_exists('esc')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Shared.php';
if (!function_exists('pageOrderNew')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/ManualOrders.php';
ob_start();
pageOrderNew();
$r10xForm = (string)ob_get_clean();
assert_true(str_contains($r10xForm, 'name="lines"') && str_contains($r10xForm, 'value="packeta"') && str_contains($r10xForm, 'value="bank_transfer"'),
    'R10 UX7: the new-order form offers the lines, the shipping and the payment methods');
ob_start();
renderOrderItemsEditor(Orders::byId($r10xId), Orders::items($r10xId));
assert_true(str_contains((string)ob_get_clean(), esc(I18n::t('admin.order.edit.not_pending'))), 'R10 UX7: the editor of a paid order explains why it is locked');
$pdo->prepare("UPDATE orders SET status = 'cancelled', payment_status = 'cancelled' WHERE id = :id")->execute([':id' => $r10xId]);
Orders::restoreStock($r10xId);
