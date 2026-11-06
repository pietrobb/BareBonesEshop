<?php

// Review round 19 (2026-11-06):
// - medium: the merchant's withdrawal e-mail listed every line of the order and the order total, not only the lines the
//   customer withdrew from in that statement and their sum;
// - low: on a partly shipped order the admin said "do not ship" also for a withdrawn line that had already left, and the
//   acknowledgement column showed the raw mail status ("sent").
echo "\n--- R19: withdrawal e-mail to the merchant names only the withdrawn lines; per-line shipped state in the admin ---\n";
require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Shared.php';
require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Shipments.php';
(require TEST_CLI_DIR . '/../migrations/20261022090000_withdrawal_requests.php')->up($pdo, Db::isSqlite());
Db::forgetSchema();

$r19wDb = Db::get();
$r19wProduct = $r19wDb->query("SELECT id FROM products WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
$r19wNumber = 'R19W-' . bin2hex(random_bytes(3));
$r19wDb->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_id, subtotal, total, currency, store_id, locale)
    VALUES (?, 'processing', 'paid', 'R19W Buyer', 'r19w@example.test', 'card', '', 10000, 10000, 'eur', 'default', 'sk')")->execute([$r19wNumber]);
$r19wOrderId = (int)$r19wDb->lastInsertId();
$r19wItem = static function (string $sku, string $name, int $total) use ($r19wDb, $r19wOrderId, $r19wProduct): int {
    $r19wDb->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, attrs) VALUES (?, ?, ?, ?, 1, ?, ?, '{}')")
        ->execute([$r19wOrderId, (int)$r19wProduct, $sku, $name, $total, $total]);
    return (int)$r19wDb->lastInsertId();
};
$r19wSentItem = $r19wItem('R19W-SENT', 'R19W Lamp already sent', 7000);
$r19wHereItem = $r19wItem('R19W-HERE', 'R19W Vase still here', 3000);
// The lamp left in a shipment; the vase did not.
$r19wDb->prepare("INSERT INTO shipments (store_id, order_id, status, idempotency_key) VALUES ('default', ?, 'shipped', ?)")->execute([$r19wOrderId, 'r19w-' . $r19wOrderId]);
$r19wDb->prepare("INSERT INTO shipment_items (shipment_id, order_item_id, sku, quantity, status) VALUES (?, ?, 'R19W-SENT', 1, 'shipped')")
    ->execute([(int)$r19wDb->lastInsertId(), $r19wSentItem]);
$r19wStatement = static function (array $itemIds, string $confirmation) use ($r19wDb, $r19wOrderId, $r19wNumber): int {
    $r19wDb->prepare("INSERT INTO withdrawal_requests (store_id, order_id, order_number, customer_name, customer_email, items, reason, received_at, confirmation_status)
        VALUES ('default', ?, ?, 'R19W Buyer', 'r19w@example.test', ?, '', ?, ?)")
        ->execute([$r19wOrderId, $r19wNumber, json_encode(array_fill_keys($itemIds, 1)), gmdate('Y-m-d H:i:s'), $confirmation]);
    return (int)$r19wDb->lastInsertId();
};
$r19wFirst = $r19wStatement([$r19wHereItem], 'sent');

// 1) The merchant e-mail for the first statement: the vase and 30,00 €, not the lamp or the 100,00 € order.
$r19wSuiteConfig = TEST_DATA_DIR . '/' . AppConfig::SUITE_FILE;
$r19wSavedConfig = (string)file_get_contents($r19wSuiteConfig);
$r19wMails = [];
$r19wMailer = new class($r19wMails) implements MailAdapter {
    public function __construct(private array &$mails) {}
    public function send(array $msg): array { $this->mails[] = $msg; return ['status' => 'sent', 'message' => '', 'raw' => null]; }
    public function adapterId(): string { return 'r19-withdrawal'; }
};
try {
    $r19wConfig = require $r19wSuiteConfig;
    $r19wConfig['merchant_notifications'] = ['enabled' => true, 'recipients' => ['r19w-merchant@example.test']];
    file_put_contents($r19wSuiteConfig, '<?php return ' . var_export($r19wConfig, true) . ";\n");
    MailService::useAdapter($r19wMailer);
    $r19wSent = MerchantNotifier::send($r19wOrderId, 'withdrawal:' . $r19wFirst);
    $r19wHtml = html_entity_decode((string)($r19wMails[0]['html'] ?? json_encode($r19wMails[0] ?? [], JSON_UNESCAPED_UNICODE)));
    assert_equals([1, true, false, true, false], [
        $r19wSent, str_contains($r19wHtml, 'R19W Vase still here'), str_contains($r19wHtml, 'R19W Lamp already sent'),
        str_contains($r19wHtml, I18n::price(3000, 'eur')), str_contains($r19wHtml, I18n::price(10000, 'eur')),
    ], 'R19 withdrawal: the merchant e-mail lists only the lines of that statement and their sum (was: every line, the order total)');
} finally {
    MailService::useAdapter(null);
    file_put_contents($r19wSuiteConfig, $r19wSavedConfig);
}

// 2) A second statement for the lamp: the admin tells which withdrawn line already left and which must not ship.
$r19wStatement([$r19wSentItem], 'rate_limited');
ob_start();
renderOrderWithdrawals((array)Orders::byId($r19wOrderId), Withdrawal::forOrder($r19wOrderId));
$r19wAdmin = (string)ob_get_clean();
$r19wLine = static fn(string $name, string $key): bool => preg_match('~' . preg_quote(esc($name), '~') . '[^<]*?' . preg_quote(esc(I18n::t($key)), '~') . '~u', $r19wAdmin) === 1;
assert_equals([true, true, true, true], [
    $r19wLine('R19W Lamp already sent', 'admin.withdrawal.line_sent'), $r19wLine('R19W Vase still here', 'admin.withdrawal.line_not_sent'),
    str_contains($r19wAdmin, esc(I18n::t('admin.withdrawal.notice'))), str_contains($r19wAdmin, esc(I18n::t('admin.withdrawal.notice_sent'))),
], 'R19 withdrawal: on a partly shipped order the shipped line waits for the return and only the unshipped one is "do not ship"');
assert_true(str_contains($r19wAdmin, esc(I18n::t('admin.email.status.sent'))) && str_contains($r19wAdmin, esc(I18n::t('admin.email.status.rate_limited')))
    && !preg_match('~>\s*(sent|rate_limited)\s*<~', $r19wAdmin),
    'R19 withdrawal: the acknowledgement column shows the mail status in words, not its code');

$r19wDb->prepare('DELETE FROM withdrawal_requests WHERE order_id = ?')->execute([$r19wOrderId]);
$r19wDb->prepare('DELETE FROM shipment_items WHERE order_item_id IN (?, ?)')->execute([$r19wSentItem, $r19wHereItem]);
$r19wDb->prepare('DELETE FROM shipments WHERE order_id = ?')->execute([$r19wOrderId]);
$r19wDb->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$r19wOrderId]);
$r19wDb->prepare('DELETE FROM orders WHERE id = ?')->execute([$r19wOrderId]);
