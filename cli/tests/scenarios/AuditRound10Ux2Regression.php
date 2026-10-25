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
