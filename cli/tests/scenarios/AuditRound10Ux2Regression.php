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

// UX8: a guest finds an order by number + e-mail; a guest order's confirmation offers an account that takes the order;
// a signed-in checkout can keep the address; an out-of-stock product takes "notify me" requests that are mailed on restock.
echo "\n--- Audit round 10 (UX 8): guest order status, account after a guest order, saved addresses, back in stock ---\n";
if (!function_exists('renderOrderStatus')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Storefront/GuestPages.php';
if (!function_exists('renderProduct')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Storefront/CatalogPages.php';
(require TEST_CLI_DIR . '/../migrations/20261025120000_back_in_stock_requests.php')->up($pdo, Db::isSqlite());
$r10xGuestOrder = Orders::byId((int)($r10xCheckout['order_id'] ?? 0)) ?? [];
$r10xLookup = static function (string $number, string $email, ?string $csrf = null): string {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf_token' => $csrf ?? Security::csrfToken(), 'order_number' => $number, 'email' => $email];
    $html = @renderOrderStatus(); // @: the CLI cannot set the 403/422 status once the test has printed
    $_POST = [];
    return $html;
};
$r10xHtml = $r10xLookup((string)($r10xGuestOrder['order_number'] ?? '?'), 'R10X-Web@Example.com');
assert_true(str_contains($r10xHtml, (string)$r10xGuestOrder['order_number']) && str_contains($r10xHtml, esc(OrderStatusLabels::order('cancelled')))
    && str_contains($r10xHtml, 'R10X-A item'), 'R10 UX8: number + e-mail (any case) shows the order status, the items and the total');
assert_true(!str_contains($r10xHtml, 'Telefónna') && !str_contains($r10xHtml, '+421900000000'), 'R10 UX8: the guest status page shows no address and no phone');
$r10xHtml = $r10xLookup((string)($r10xGuestOrder['order_number'] ?? '?'), 'someone-else@example.com');
assert_true(str_contains($r10xHtml, esc(I18n::t('order_status.not_found'))) && !str_contains($r10xHtml, 'R10X-A item'), 'R10 UX8: the number with another e-mail reveals nothing');
$r10xHtml = $r10xLookup((string)($r10xGuestOrder['order_number'] ?? '?'), 'r10x-web@example.com', 'forged');
assert_true(str_contains($r10xHtml, esc(I18n::t('order_status.expired'))) && !str_contains($r10xHtml, 'R10X-A item'), 'R10 UX8: the lookup form needs the CSRF token');

// The confirmation page of that guest order offers an account; creating it moves the order in and signs the shopper in.
Customers::logout();
assert_true(GuestCheckoutAccount::offered($r10xGuestOrder) && guestAccountOfferView($r10xGuestOrder, '') !== null, 'R10 UX8: a guest order with a new e-mail offers an account');
$r10xWeak = GuestCheckoutAccount::create($r10xGuestOrder, 'short', 'sk');
assert_equals([false, null], [$r10xWeak['ok'], Orders::byId((int)$r10xGuestOrder['id'])['customer_id'] ?? null], 'R10 UX8: a too-short password creates nothing and leaves the order a guest order');
$r10xAccount = GuestCheckoutAccount::create($r10xGuestOrder, 'R10x-Strong-Pass', 'sk');
$r10xCustomerId = (int)($r10xAccount['customer_id'] ?? 0);
assert_true($r10xAccount['ok'] && $r10xCustomerId > 0, 'R10 UX8: the account is created (' . ($r10xAccount['error'] ?? 'ok') . ')');
assert_equals([$r10xCustomerId, $r10xCustomerId], [(int)(Orders::byId((int)$r10xGuestOrder['id'])['customer_id'] ?? 0), (int)($_SESSION['customer_id'] ?? 0)],
    'R10 UX8: the order belongs to the new account and the shopper is signed in');
assert_true(!GuestCheckoutAccount::offered(Orders::byId((int)$r10xGuestOrder['id']) ?? []) && !GuestCheckoutAccount::create($r10xGuestOrder, 'R10x-Strong-Pass', 'sk')['ok'],
    'R10 UX8: the offer is gone once the account exists (no second account, no takeover)');

// Signed in: the checkout keeps the address once, and offers no sign-in link.
$r10xView = checkoutAccountView($r10xCustomerId, false, true, false);
assert_true($r10xView['login_url'] === '' && $r10xView['save_address_label'] !== '' && $r10xView['save_address_checked'], 'R10 UX8: a signed-in checkout offers to save the address (pre-checked while the book is empty)');
$r10xAddress = ['name' => 'R10 Phone Buyer', 'street' => 'Telefónna 1', 'city' => 'Bratislava', 'zip' => '811 01', 'country' => 'sk', 'phone' => '+421900000000'];
assert_equals([true, false, 1], [GuestCheckoutAccount::rememberAddress($r10xCustomerId, $r10xAddress), GuestCheckoutAccount::rememberAddress($r10xCustomerId, ['zip' => '81101'] + $r10xAddress),
    count(Customers::addresses($r10xCustomerId, 'shipping'))], 'R10 UX8: the checkout address is saved once (same address again is not duplicated)');
assert_true(!empty(Customers::addresses($r10xCustomerId, 'shipping')[0]['is_default']), 'R10 UX8: the first saved address becomes the default');
Customers::logout();
$r10xGuestView = checkoutAccountView(0, false, false, false);
assert_true(str_contains($r10xGuestView['login_url'], 'p=login') && str_contains($r10xGuestView['login_url'], 'next=') && $r10xGuestView['save_address_label'] === '',
    'R10 UX8: a guest checkout links to sign-in and back, and offers no address book');

// Back in stock: R10X-D is sold out; a request waits, the restock queues one job, the worker mails it once and forgets the address.
$r10xProduct->execute([':id' => 9774, ':name' => 'R10X-D item', ':slug' => 'r10x-d', ':sku' => 'R10X-D', ':price' => 900, ':stock' => 0]);
$r10xStock->execute([':wh' => $r10xWarehouse, ':sku' => 'R10X-D', ':qty' => 0]);
$r10xRow = static fn(int $id): array => (array)$pdo->query("SELECT * FROM products WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
assert_true(BackInStock::offered($r10xRow(9774)) && !BackInStock::offered($r10xRow(9772)), 'R10 UX8: the request is offered for a sold-out product only');
$r10xSoldOutPage = (string)renderProduct($r10xRow(9774));
$r10xInStockModel = StorefrontViewModel::product($r10xRow(9772))['product'] ?? [];
assert_true(is_array(StorefrontViewModel::product($r10xRow(9774))['product']['back_in_stock'] ?? null) && array_key_exists('back_in_stock', $r10xInStockModel) && $r10xInStockModel['back_in_stock'] === null,
    'R10 UX8: the product model carries the "notify me" form only when the product cannot be bought');
assert_true(str_contains($r10xSoldOutPage, 'data-back-in-stock') && str_contains($r10xSoldOutPage, 'page=stock-notify') && str_contains($r10xSoldOutPage, esc(I18n::t('back_in_stock.submit'))),
    'R10 UX8: the sold-out product page shows the "notify me" form');
assert_equals(['subscribed', 'subscribed', 'invalid', 'invalid', 'available'], [BackInStock::subscribe(9774, 'R10x-Wait@Example.com', 'sk'), BackInStock::subscribe(9774, 'r10x-wait@example.com', 'sk'),
    BackInStock::subscribe(9774, 'not-an-email', 'sk'), BackInStock::subscribe(999999, 'r10x-wait@example.com', 'sk'), BackInStock::subscribe(9772, 'r10x-wait@example.com', 'sk')],
    'R10 UX8: subscribe outcomes (repeat, bad e-mail, unknown product, product in stock)');
$r10xWaiting = static fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM back_in_stock_requests WHERE product_id = 9774")->fetchColumn();
assert_equals(1, $r10xWaiting(), 'R10 UX8: one request per product and e-mail');
$r10xExport = GdprService::PII_REGISTRY['back_in_stock_requests'] ?? [];
assert_equals(['delete', 'stock_notifications'], [$r10xExport['erase'] ?? null, $r10xExport['export'] ?? null], 'R10 UX8: the request e-mail is in the GDPR registry (export + erase)');

JobQueue::pull(500);
BackInStock::flush();
Warehouse::setStock($r10xWarehouse, 'R10X-D', 4, 'count');
Warehouse::syncProductStock(['R10X-D']);
assert_equals(1, BackInStock::flush(), 'R10 UX8: the restock queues one back_in_stock job');
$r10xJobs = array_values(array_filter(JobQueue::pull(500), static fn(array $job): bool => ($job['type'] ?? '') === 'back_in_stock'
    && in_array('R10X-D', (array)($job['payload']['skus'] ?? []), true)));
assert_equals([['R10X-D']], array_map(static fn(array $job): array => (array)($job['payload']['skus'] ?? []), $r10xJobs), 'R10 UX8: one job names the refilled SKU');
$r10xMailLog = static fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM email_log WHERE template = 'back_in_stock' AND recipient = 'r10x-wait@example.com'")->fetchColumn();
$r10xMailsBefore = $r10xMailLog();
assert_equals(1, BackInStock::process(['R10X-D']), 'R10 UX8: the worker mails the waiting request');
assert_equals([1, 0, 0], [$r10xMailLog() - $r10xMailsBefore, $r10xWaiting(), BackInStock::process(['R10X-D'])], 'R10 UX8: one e-mail, then the request is deleted and a second run sends nothing');
assert_true(is_file(dirname(TEST_CLI_DIR) . '/templates/emails/sk/back_in_stock.html') && is_file(dirname(TEST_CLI_DIR) . '/templates/emails/en/back_in_stock.subject.txt'), 'R10 UX8: the e-mail template exists');

// UX9: the installer takes MySQL switches (password from the environment), warns when it reuses an earlier install's
// private data, shows the owner's TOTP as a terminal QR code and names the shop's real address.
echo "\n--- Audit round 10 (UX 9): installer ---\n";
$r10xBase = test_fixture_path('r10x-install');
$r10xRemove = static function (string $path) use (&$r10xRemove): void {
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $r10xRemove($path . '/' . $entry);
    @rmdir($path);
};
$r10xRefused = static function (array $database): string {
    try {
        Setup::databaseValues($database);
        return 'accepted';
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
};
try {
    foreach (['shop', 'other/shop'] as $r10xDir) {
        mkdir($r10xBase . '/' . $r10xDir, 0777, true);
        copy(dirname(TEST_CLI_DIR) . '/config.example.php', $r10xBase . '/' . $r10xDir . '/config.example.php');
    }
    $r10xRefusals = ['--db-driver' => ['driver' => 'postgres'], '--db-port' => ['port' => '70000'], '--db-name' => ['name' => 'shop; DROP'], '--db-host' => ['host' => 'evil host'],
        '--db-driver=mysql' => ['driver' => 'sqlite', 'host' => 'db']];
    assert_equals([], array_keys(array_filter($r10xRefusals, static fn(array $input, string $switch): bool => !str_contains($r10xRefused($input), $switch), ARRAY_FILTER_USE_BOTH)),
        'R10 UX9: bad --db-* values are refused, naming the switch');
    assert_equals([[], ['db_driver' => 'sqlite']], [Setup::databaseValues([]), Setup::databaseValues(['driver' => 'SQLite'])], 'R10 UX9: no switch keeps the template; --db-driver=sqlite stays sqlite');

    $r10xPassword = "p\$1'\\x \"q";
    $r10xCreated = Setup::createConfig($r10xBase . '/shop', 'https://shop.example/', '', ['host' => 'db.internal', 'port' => '3307', 'name' => 'shop_r10x', 'user' => 'shopuser', 'password' => $r10xPassword]);
    $r10xConfig = require $r10xBase . '/shop/config.php';
    assert_equals(['created', 'mysql', 'db.internal', 3307, 'shop_r10x', 'shopuser', $r10xPassword, 'https://shop.example'],
        [$r10xCreated['action'], $r10xConfig['db_driver'] ?? null, $r10xConfig['db_host'] ?? null, $r10xConfig['db_port'] ?? null, $r10xConfig['db_name'] ?? null,
         $r10xConfig['db_user'] ?? null, $r10xConfig['db_password'] ?? null, $r10xConfig['app_url'] ?? null], 'R10 UX9: MySQL switches land in config.php (port as a number, the password byte for byte)');
    assert_true(!isset($r10xCreated['warnings']), 'R10 UX9: a first install has nothing to warn about');
    assert_equals('unchanged', Setup::createConfig($r10xBase . '/shop', '', '', ['driver' => 'sqlite'])['action'], 'R10 UX9: an existing config.php is never rewritten');
    $r10xMysql = Setup::mysqlProblem(['db_host' => '127.0.0.1', 'db_port' => 1, 'db_name' => 'r10x', 'db_user' => 'r10x', 'db_password' => '']);
    assert_true(is_string($r10xMysql) && $r10xMysql !== '', 'R10 UX9: an unreachable MySQL server is reported before config.php is written (' . $r10xMysql . ')');

    // A fresh config.php over the data of an earlier install (same private directory) says so.
    mkdir($r10xBase . '/other/bareboneseshop-private/db', 0777, true);
    file_put_contents($r10xBase . '/other/bareboneseshop-private/db/shop.sqlite', 'SQLite format 3');
    $r10xReused = Setup::createConfig($r10xBase . '/other/shop');
    assert_true(str_contains(str_replace('\\', '/', implode(' ', $r10xReused['warnings'] ?? [])), 'other/bareboneseshop-private/db/shop.sqlite'), 'R10 UX9: reusing an earlier install\'s database is warned about');

    // The terminal QR decodes back to exactly the QR code of the otpauth URI.
    $r10xUri = Totp::provisioningUri(Totp::generateSecret(), 'owner');
    $r10xMatrix = QrCode::matrix($r10xUri);
    $r10xRows = [];
    foreach (explode("\n", rtrim(Setup::terminalQr($r10xUri), "\n")) as $r10xLine) {
        $r10xCells = preg_split('//u', (string)preg_replace('/\e\[[0-9;]*m/', '', $r10xLine), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $r10xRows[] = array_map(static fn(string $c): bool => $c === '█' || $c === '▀', $r10xCells);
        $r10xRows[] = array_map(static fn(string $c): bool => $c === '█' || $c === '▄', $r10xCells);
    }
    $r10xDecoded = array_map(static fn(array $row): array => array_slice($row, 4, count($r10xMatrix)), array_slice($r10xRows, 4, count($r10xMatrix)));
    assert_true($r10xDecoded === $r10xMatrix && !in_array(true, array_merge(...array_slice($r10xRows, 0, 4)), true), 'R10 UX9: the terminal QR is the TOTP QR code with a quiet zone');

    $r10xLive = implode("\n", Setup::nextSteps(['app_url' => 'https://shop.example/']));
    $r10xLocal = implode("\n", Setup::nextSteps(['app_url' => '']));
    assert_true(str_contains($r10xLive, 'https://shop.example/admin.php') && !str_contains($r10xLive, 'localhost'), 'R10 UX9: the install ends with the shop\'s real address');
    assert_true(str_contains($r10xLocal, 'php -S localhost:8080 router.php') && str_contains($r10xLocal, 'app_url'), 'R10 UX9: without app_url it names the development server and what to set');
    $r10xCli = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/cli/setup.php');
    assert_true(str_contains($r10xCli, "getenv('BBE_DB_PASSWORD')") && !str_contains($r10xCli, "'db-password:'"), 'R10 UX9: the database password comes from the environment, never an argument');
} finally {
    $r10xRemove($r10xBase);
}
