<?php

// Review round 32 (2026-11-11, main @ f21a3cff), UI lows (goal #427): a disabled "Add to cart" looked active, the audit note
// "Admin payment" stayed English, the "Audit Log" heading was English in SK and CS, and the admin showed the zone code
// near_eu and the payment code bank_transfer where the admin reads names.
echo "\n--- R32: disabled cart buttons, admin payment note, audit log title, zone and payment names in the admin ---\n";
if (!function_exists('pageOrder')) {
    if (!function_exists('adminFieldErrors')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Shared.php';
    require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Orders.php';
}
if (!function_exists('pageShipping')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Operations.php';

Bootstrap::initStoreContext('/', '', 'default');
$r32uRoot = dirname(TEST_CLI_DIR);
$r32uLocale = I18n::locale();
$r32uOrder = 0;
try {
    // 1. A disabled cart button looks disabled in both themes (it kept the active colour and pointer).
    $r32uStyled = [];
    foreach (['default/assets/storefront.css', 'bookshop/assets/bookshop.css'] as $r32uCss) {
        $css = (string)file_get_contents("{$r32uRoot}/themes/{$r32uCss}");
        foreach (['.btn-cart:disabled', '.btn-saved-to-cart:disabled'] as $r32uSelector) {
            if (preg_match('/' . preg_quote($r32uSelector, '/') . '[^{]*\{[^}]*cursor:not-allowed/', $css) === 1) $r32uStyled[] = "{$r32uCss} {$r32uSelector}";
        }
    }
    assert_equals(4, count($r32uStyled), 'R32 UI: a disabled "Add to cart" (product and saved-for-later) has its own grey style in both themes (' . implode(', ', $r32uStyled) . ')');

    // 2. "Admin payment" (with or without the admin's note) in the admin's language; the note itself is kept.
    I18n::setLocale('sk');
    $r32uNotes = [AuditLogLabels::note('order_status', 1, 'Admin payment'), AuditLogLabels::note('order_status', 1, 'Admin payment: VS 2026123')];
    assert_equals(['Platba zaznamenaná v administrácii', 'Platba zaznamenaná v administrácii: VS 2026123'], $r32uNotes,
        'R32 audit log: "Admin payment" is in the admin\'s language (was English, or "Uložené: …" with a note)');

    // 3. The audit log heading and menu item in every language, not "Audit Log" outside English.
    $r32uEnglish = [];
    foreach (['en', 'sk', 'cs', 'de', 'es', 'hu', 'pl'] as $r32uLang) {
        $texts = require "{$r32uRoot}/lang/{$r32uLang}.php";
        foreach (['admin.nav.audit', 'admin.title.audit_log', 'admin.audit.note.admin_payment', 'admin.audit.note.admin_payment_detail'] as $r32uKey) {
            $text = trim((string)($texts[$r32uKey] ?? ''));
            if ($text === '' || ($r32uLang !== 'en' && $text === 'Audit Log')) $r32uEnglish[] = "{$r32uLang}:{$r32uKey}";
        }
    }
    assert_equals([], $r32uEnglish, 'R32 i18n: the audit log title and the admin payment notes exist in every language (SK/CS read "Audit Log")');

    // 4. The shipping page names the zone (the code stays the value and the tooltip).
    I18n::setLocale('en');
    $pdo->prepare("DELETE FROM shipping_zone_countries WHERE store_id = 'default' AND zone_code = 'r32u_near'")->execute();
    $pdo->prepare("DELETE FROM shipping_zones WHERE store_id = 'default' AND code = 'r32u_near'")->execute();
    $pdo->prepare("INSERT INTO shipping_zones (store_id, code, name, description, sort_order, active) VALUES ('default', 'r32u_near', 'R32U Nearby EU', '', 990, 1)")->execute();
    $pdo->prepare("INSERT INTO shipping_zone_countries (store_id, zone_code, country) VALUES ('default', 'r32u_near', 'AT')")->execute();
    ob_start();
    try { pageShipping(); } finally { $r32uShipping = (string)ob_get_clean(); }
    assert_equals([true, false, false], [
        str_contains($r32uShipping, '<td title="r32u_near">R32U Nearby EU</td>'),
        str_contains($r32uShipping, '<td>r32u_near</td>'),
        str_contains($r32uShipping, '>r32u_near</option>'),
    ], 'R32 admin shipping: zones in the country table and the rate selects read their name, not the code (near_eu)');

    // 5. The order detail names the payment method; its code (bank_transfer) is only the tooltip.
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method,
            payment_id, payment_method_snapshot, store_id, created_at)
        VALUES ('R32U-1', 'R32U', 'r32u@example.test', '{\"country\":\"SK\"}', 1000, 1000, 'eur', 'pending', 'pending', 'bank_transfer', '', ?, 'default', " . Db::now() . ")")
        ->execute([json_encode(['code' => 'bank_transfer', 'provider' => 'manual', 'service_type' => 'bank_transfer', 'label' => 'Bank transfer'])]);
    $r32uOrder = (int)$pdo->lastInsertId();
    $_GET = ['id' => (string)$r32uOrder];
    ob_start();
    try { pageOrder(); } finally { $r32uPage = (string)ob_get_clean(); $_GET = []; }
    $r32uLine = preg_match('/<p [^>]*>' . preg_quote(esc(I18n::t('admin.order.payment_method')), '/') . ': .*?<\/p>/s', $r32uPage, $m) === 1 ? $m[0] : '';
    assert_equals([true, false, true], [$r32uLine !== '' && str_contains(strip_tags($r32uLine), PaymentService::adminLabel(Orders::byId($r32uOrder))),
        str_contains(strip_tags($r32uLine), 'bank_transfer'), str_contains($r32uLine, 'title="bank_transfer · manual"')],
        'R32 admin order: the payment line reads the method\'s name, its code bank_transfer only in the tooltip (' . trim(strip_tags($r32uLine)) . ')');
} catch (Throwable $e) {
    assert_true(false, 'R32 UI (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    I18n::setLocale($r32uLocale);
    $_GET = [];
    try {
        $pdo->prepare("DELETE FROM shipping_zone_countries WHERE store_id = 'default' AND zone_code = 'r32u_near'")->execute();
        $pdo->prepare("DELETE FROM shipping_zones WHERE store_id = 'default' AND code = 'r32u_near'")->execute();
        if ($r32uOrder > 0) $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$r32uOrder]);
    } catch (Throwable $e) {}
}
