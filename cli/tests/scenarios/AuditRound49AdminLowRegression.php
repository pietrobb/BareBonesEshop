<?php

// Review round 19 (R49, admin and other lows): the audit log showed the SMTP host and login of the mail settings and
// suppliers' contacts to reports.view alone (their pages are 403); the dashboard listed raw webhook codes and the queue
// "(file)"; the order's transaction table read in English ("Ref", "stripe", "refund_id: …", "Refund succeeded"); the
// chart's values were reachable by mouse only; audit values read "idempotency_key: …" and "Refund id"; the e-book e-mail
// said "no expiry" / "max 3 downloads" in every language; the profile took U+202E; the store switch wrapped at 390 px;
// the JS summary said "Shipping 0,00 €" where the page says "Free shipping".
echo "\n--- R49: admin and other lows (audit permissions, dashboard and order texts, e-book validity, bidi, 390 px) ---\n";
if (!function_exists('adminCodeLabel')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Shared.php';
if (!function_exists('pageOrder')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Orders.php';
if (!function_exists('pageAudit')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Operations.php';
if (!function_exists('pageDashboard')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Dashboard.php';

Bootstrap::initStoreContext('/', '', 'default');
$r49aRoot = dirname(TEST_CLI_DIR);
$r49aLocale = I18n::locale();
$r49aSession = $_SESSION ?? [];
$r49aAudit = [];
$r49aOrder = 0;
$r49aRender = static function (callable $page): string {
    ob_start();
    try { $page(); } finally { $html = (string)ob_get_clean(); }
    return $html;
};
try {
    // 1. Audit log: every entity follows its page's permission; a new, unmapped one is the owner's until it is mapped.
    assert_equals(['mail.manage', 'import', 'import', 'stores.manage', 'payments.manage', 'shipping.manage', null, null, 'admins.manage'], [
        AuditLogLabels::permission('mail_settings'), AuditLogLabels::permission('supplier'), AuditLogLabels::permission('purchase_order'),
        AuditLogLabels::permission('store_settings'), AuditLogLabels::permission('payment_methods'), AuditLogLabels::permission('shipping_zones'),
        AuditLogLabels::permission('product'), AuditLogLabels::permission('category'), AuditLogLabels::permission('r49_new_entity'),
    ], 'R49 audit: mail settings, suppliers, purchase orders, store, payment and shipping setup need their page\'s permission (was: reports.view)');
    I18n::setLocale('en');
    $r49aInsert = $pdo->prepare("INSERT INTO audit_log (entity, entity_id, action, old_value, new_value, note, actor, ip_address, store_id, created_at)
        VALUES (?, ?, 'update', ?, ?, ?, 'admin:r49', '', 'default', '2099-01-01 00:00:00')");
    foreach ([
        ['mail_settings', 0, '', '{"host":"smtp.r49-secret.test","username":"r49-smtp-login"}', 'E-mail delivery settings changed'],
        ['supplier', 4901, '', '{"email":"r49-supplier@example.test","phone":"+421 900 494 949"}', 'Admin supplier'],
        ['product', 4902, '', '', 'r49 product note'],
        ['rma_receipt', 4903, '', '{"idempotency_key":"r49-receipt-key"}', 'Admin receipt'],
        ['order_refund', 4904, '', 'refund_id', 'Refund: re_r49'],
    ] as $r49aRow) {
        $r49aInsert->execute($r49aRow);
        $r49aAudit[] = (int)$pdo->lastInsertId();
    }
    $r49aAs = static function (string $permissions, string $role = 'manager') use ($r49aRender): string {
        $_SESSION = ['admin_role' => $role, 'admin_identity_id' => 1, 'admin_store_permissions' => ['default' => explode(',', $permissions)]];
        $_GET = [];
        return $r49aRender('pageAudit');
    };
    $r49aHtml = $r49aAs('reports.view');
    assert_equals([false, false, false, false, true], [
        str_contains($r49aHtml, 'smtp.r49-secret.test'), str_contains($r49aHtml, 'r49-smtp-login'),
        str_contains($r49aHtml, 'r49-supplier@example.test'), str_contains($r49aHtml, '494 949'), str_contains($r49aHtml, 'r49 product note'),
    ], 'R49 audit (reports.view): no SMTP host or login, no supplier contact; a product entry still shows');
    $r49aHtml = $r49aAs('reports.view,import,rma.manage,orders.view');
    assert_equals([false, true], [str_contains($r49aHtml, 'smtp.r49-secret.test'), str_contains($r49aHtml, 'r49-supplier@example.test')],
        'R49 audit (manager with import): the supplier entry shows in full; the mail settings stay the owner\'s');
    $r49aHtml = $r49aAs('reports.view,mail.manage,import,rma.manage,orders.view', 'owner');
    assert_true(str_contains($r49aHtml, 'smtp.r49-secret.test') && str_contains($r49aHtml, 'r49-supplier@example.test'),
        'R49 audit (owner): the mail settings and supplier entries show in full');
    // Audit values the code writes, in words.
    assert_equals([true, false, true, false], [
        str_contains($r49aHtml, esc(I18n::t('admin.audit.field.idempotency_key') . ': r49-receipt-key')), str_contains($r49aHtml, 'idempotency_key'),
        str_contains($r49aHtml, esc(I18n::t('admin.audit.field.refund_id'))), str_contains($r49aHtml, 'Refund id'),
    ], 'R49 audit: "idempotency_key: …" and "Refund id" read as words');
    I18n::setLocale('sk');
    assert_equals(['Kľúč požiadavky: r49-k', 'Zapísané ID refundácie'],
        [AuditLogLabels::value('rma_receipt', '{"idempotency_key":"r49-k"}', 4903), AuditLogLabels::value('order_refund', 'refund_id', 4904)],
        'R49 audit: the field names in the admin\'s language');
    $_SESSION = $r49aSession;

    // 2. Dashboard: webhook events and the queue backend in words, the raw code kept as the tooltip; chart values without a mouse.
    I18n::setLocale('en');
    $r49aOps = OperationalDashboard::summary('default');
    $r49aOps['queue_backend'] = 'file';
    $r49aOps['webhook_incidents'] = [
        ['event_type' => 'stripe:charge.refunded', 'status' => 'unresolved_dead', 'attempts' => 3, 'last_error' => 'r49', 'correlation_id' => 'r49c1'],
        ['event_type' => 'order.created', 'status' => 'retry', 'attempts' => 1, 'last_error' => 'r49', 'correlation_id' => 'r49c2'],
        ['event_type' => 'stripe:r49.unknown_event', 'status' => 'dead', 'attempts' => 5, 'last_error' => 'r49', 'correlation_id' => 'r49c3'],
    ];
    $r49aOps['failed_jobs'] = $r49aOps['failed_mail'] = $r49aOps['payment_incidents'] = [];
    $r49aHtml = $r49aRender(static fn() => dashboardIncidents($r49aOps, true));
    assert_equals([true, true, true, true, false], [
        str_contains($r49aHtml, '<td title="stripe:charge.refunded">Stripe: Payment refunded</td>'),
        str_contains($r49aHtml, '<td title="order.created">Order created</td>'),
        str_contains($r49aHtml, '<td title="stripe:r49.unknown_event">Stripe: Other provider event</td>'),
        str_contains($r49aHtml, 'Unprocessed, stopped retrying'), (bool)preg_match('~<td>(?:stripe:)?[a-z_]+\.[a-z_.]+</td>~', $r49aHtml),
    ], 'R49 dashboard: webhook incidents name the provider and the event (was: "stripe:charge.refunded")');
    $r49aHtml = $r49aRender(static fn() => dashboardHealth($r49aOps, 0));
    assert_true(str_contains($r49aHtml, esc(I18n::t('admin.dashboard.jobs_queue')) . ' (files)') && !str_contains($r49aHtml, '(file)'),
        'R49 dashboard: the job queue says where it runs in words (was: "(file)")');
    assert_equals(['Bank transfer', 'Test gateway', 'GoPay', 'r49_custom'],
        [AdminEventLabels::provider('bank_transfer'), AdminEventLabels::provider('mock'), AdminEventLabels::provider('gopay'), AdminEventLabels::provider('r49_custom')],
        'R49 admin: payment providers by name, an unknown code as it is');

    // 3. The order's transaction table in the admin's language.
    I18n::setLocale('sk');
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method,
            payment_id, store_id, created_at)
        VALUES ('R49A-1', 'R49A', 'r49a@example.test', '{\"country\":\"SK\"}', 1000, 1000, 'eur', 'paid', 'partially_refunded', 'card', 'pi_r49', 'default', " . Db::now() . ")")->execute();
    $r49aOrder = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO payment_transactions (order_id, store_id, provider, provider_ref, status, amount, currency, idempotency_key, raw_response, created_at, updated_at)
        VALUES (?, 'default', 'stripe', 're_r49a', 'refund_succeeded', 400, 'eur', ?, ?, " . Db::now() . ', ' . Db::now() . ')')
        ->execute([$r49aOrder, 'r49a-refund-' . $r49aOrder, json_encode(['event_type' => 'refund.succeeded', 'refund_id' => 're_r49a', 'actor' => 'admin:r49'])]);
    $_GET = ['id' => (string)$r49aOrder];
    $r49aHtml = $r49aRender('pageOrder');
    $_GET = [];
    $r49aTable = preg_match('/<h3>' . preg_quote(esc(I18n::t('admin.order.payment_timeline')), '/') . '<\/h3>.*?<\/table>/s', $r49aHtml, $m) === 1 ? $m[0] : '';
    assert_equals([true, true, true, true, false, false, false], [
        str_contains($r49aTable, '<th>Referencia</th>'), str_contains($r49aTable, '<td>Stripe</td>'),
        str_contains($r49aTable, 'ID refundácie: re_r49a') && str_contains($r49aTable, 'Vykonal: admin:r49'),
        str_contains($r49aTable, esc(I18n::t('admin.order.tx_status.refund_succeeded'))),
        str_contains($r49aTable, '<th>Ref</th>'), str_contains($r49aTable, 'refund_id:'), str_contains($r49aTable, 'Refund succeeded'),
    ], 'R49 order: the transaction table reads in Slovak (was: "Ref", "stripe", "refund_id: …", "Refund succeeded") — ' . substr(strip_tags($r49aTable), 0, 300));
    I18n::setLocale('en');
    $r49aHtml = $r49aRender(static fn() => dashboardTrend('default'));
    // The day's row as the report counts it (other scenarios' orders of today are in it too in a full run).
    $r49aToday = SalesReport::trend('default', DASHBOARD_TREND_DAYS)['eur'][date('Y-m-d')] ?? ['orders' => 0, 'revenue' => 0];
    assert_true(str_contains($r49aHtml, '<details class="chart-values"><summary>Values by day</summary>') && str_contains($r49aHtml, '<th>Day</th><th>Orders</th><th>Revenue</th>')
        && (int)$r49aToday['orders'] >= 1
        && str_contains($r49aHtml, '<td>' . esc(I18n::date(date('Y-m-d'))) . '</td><td>' . (int)$r49aToday['orders'] . '</td><td>' . esc(I18n::price((int)$r49aToday['revenue'], 'eur')) . '</td>'),
        'R49 dashboard: the chart\'s values by day are a table for keyboard and touch (was: hover tooltips only)');

    // 4. The e-book e-mail's link validity in the order's language.
    I18n::setLocale('en');
    $r49aAt = 1893456000; // 2030-01-01 00:00 UTC
    assert_equals([
        'bez obmedzenia platnosti', 'bez obmedzenia platnosti, najviac 3× stiahnuť', 'ważny do ' . date('d.m.Y H:i T', $r49aAt), 'no expiry, at most 5 downloads',
    ], [
        WorkerJobs::digitalValidity(['expires_at' => null, 'download_limit' => null], 'sk'), WorkerJobs::digitalValidity(['expires_at' => null, 'download_limit' => 3], 'sk'),
        WorkerJobs::digitalValidity(['expires_at' => $r49aAt, 'download_limit' => null], 'pl'), WorkerJobs::digitalValidity(['expires_at' => null, 'download_limit' => '5'], 'en'),
    ], 'R49 e-book e-mail: the link\'s validity in the order\'s language (was: "no expiry", "max 3 downloads")');
    assert_equals('en', I18n::locale(), 'R49 e-book e-mail: the worker\'s language is left as it was');

    // 5. The profile refuses the bidi controls that turn text around; ordinary names stay valid.
    $r49aBidi = [];
    foreach (["\u{202A}", "\u{202B}", "\u{202C}", "\u{202D}", "\u{202E}", "\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}"] as $r49aMark) {
        $r49aBidi[] = (Customers::profileErrors(['name' => "Peter{$r49aMark}kováN", 'phone' => ''])['name'] ?? '') === 'account.err_invalid_text';
    }
    assert_equals(array_fill(0, 9, true), $r49aBidi, 'R49 profile: U+202A–U+202E and U+2066–U+2069 are refused (was: "Peter‮kováN" saved)');
    assert_equals([], Customers::profileErrors(['name' => 'Žofia O’Connor 李', 'phone' => '+421 900 000 000']), 'R49 profile: ordinary names and phones stay valid');

    // 6. Storefront: free shipping in words with JS too; the store switch wraps as a whole at 390 px.
    $r49aJs = (string)file_get_contents($r49aRoot . '/themes/default/assets/storefront.js');
    $r49aCss = (string)file_get_contents($r49aRoot . '/themes/default/assets/storefront.css');
    assert_true(str_contains($r49aJs, "textContent = shipping === 0 ? (text.freeShipping || text.free) : formatCents(shipping)")
        && str_contains((string)file_get_contents($r49aRoot . '/src/Entrypoints/Storefront/Layout.php'), "'freeShipping' => I18n::t('free_shipping')"),
        'R49 checkout: the JS summary says "Free shipping" as the page does (was: "Shipping 0,00 €")');
    assert_true(str_contains($r49aCss, '.lang-switch,.store-switch{display:flex;flex-wrap:wrap;') && str_contains($r49aCss, '.store-switch a{white-space:nowrap;')
        && str_contains($r49aCss, '.header-top .wrap{flex-wrap:wrap;'),
        'R49 storefront: a store name never breaks inside at 390 px; the switch wraps between names (screenshots r49_hdr_after*.png)');
} finally {
    $_SESSION = $r49aSession;
    $_GET = [];
    I18n::setLocale($r49aLocale);
    if ($r49aAudit) $pdo->exec('DELETE FROM audit_log WHERE id IN (' . implode(',', $r49aAudit) . ')');
    if ($r49aOrder) {
        $pdo->prepare('DELETE FROM payment_transactions WHERE order_id = ?')->execute([$r49aOrder]);
        $pdo->prepare('DELETE FROM audit_log WHERE entity_id = ? AND entity IN (\'order\', \'order_status\', \'payment_status\')')->execute([$r49aOrder]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$r49aOrder]);
    }
}
