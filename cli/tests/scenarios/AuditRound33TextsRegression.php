<?php

// Review round 33 (medium 3 + UI lows): the IČO / DIČ / IČ DPH labels of an order e-mail were in the worker's language,
// not the order's (a Slovak e-mail read "Company ID: / Tax ID:", a Polish one the Spanish "Número de registro:"); the
// order history kept the English "Admin payment" / "Admin status change"; the payment settings and the shipping rates
// listed method codes (bank_transfer, cod, gls) where the admin reads names.
echo "\n--- R33: company labels in the order's language, order history notes, method names in settings ---\n";
if (!function_exists('pageOrder')) {
    if (!function_exists('adminFieldErrors')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Shared.php';
    require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Orders.php';
}
if (!function_exists('pageShipping')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Operations.php';

Bootstrap::initStoreContext('/', '', 'default');
$r33tRoot = dirname(TEST_CLI_DIR);
$r33tLocale = I18n::locale();
$r33tOrder = 0;
try {
    // 1. Company lines of an e-mail address block in the order's language, whatever language the worker runs in.
    $r33tAddress = ['name' => 'R33T Odberateľ', 'company' => 'R33T s.r.o.', 'ico' => '12345678', 'dic' => '2020202020', 'ic_dph' => 'SK2020202020',
        'street' => 'Hlavná 1', 'zip' => '81101', 'city' => 'Bratislava', 'country' => 'SK'];
    $r33tWrong = [];
    foreach (['en', 'sk', 'cs', 'de', 'es', 'hu', 'pl'] as $r33tLang) {
        $texts = require "{$r33tRoot}/lang/{$r33tLang}.php";
        I18n::setLocale($r33tLang === 'es' ? 'pl' : 'es'); // the worker is in another language than the order
        $lines = OrderAddress::lines($r33tAddress, $r33tLang);
        foreach (['checkout_ico' => '12345678', 'checkout_dic' => '2020202020', 'checkout_vat_id' => 'SK2020202020'] as $r33tKey => $r33tValue) {
            if (!in_array($texts[$r33tKey] . ': ' . $r33tValue, $lines, true)) $r33tWrong[] = "{$r33tLang}:{$r33tKey} (" . implode(' | ', $lines) . ')';
        }
    }
    assert_equals([], $r33tWrong, 'R33 e-mail: IČO, DIČ and IČ DPH are labelled in the order\'s language in all 7 languages (a Polish e-mail read "Número de registro:")');
    I18n::setLocale('es');
    OrderAddress::lines($r33tAddress, 'sk');
    assert_equals('es', I18n::locale(), 'R33 e-mail: labelling an address in the order\'s language leaves the worker\'s language as it was');
    $r33tMail = OrderAddress::mailVars(['customer_name' => 'R33T', 'shipping_addr' => json_encode(['street' => 'Dodacia 2', 'zip' => '81101', 'city' => 'Bratislava', 'country' => 'SK']),
        'billing_addr' => json_encode($r33tAddress)], 'sk');
    assert_true(in_array('IČO: 12345678', $r33tMail['billing_address'], true) && in_array('IČ DPH: SK2020202020', $r33tMail['billing_address'], true),
        'R33 e-mail: the Slovak order e-mail\'s billing block reads "IČO:" and "IČ DPH:" (was "Company ID:")');

    // 2. The order history translates the notes the code writes; the admin's own note is kept.
    I18n::setLocale('sk');
    $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_addr, subtotal, total, currency, status, payment_status, payment_method,
            payment_id, store_id, created_at)
        VALUES ('R33T-1', 'R33T', 'r33t@example.test', '{\"country\":\"SK\"}', 1000, 1000, 'eur', 'paid', 'paid', 'bank_transfer', '', 'default', " . Db::now() . ")")->execute();
    $r33tOrder = (int)$pdo->lastInsertId();
    AuditLog::log('order_status', $r33tOrder, 'pending', 'paid', 'Admin payment');
    AuditLog::log('order_status', $r33tOrder, 'paid', 'processing', 'Admin status change');
    AuditLog::log('order_status', $r33tOrder, 'processing', 'paid', 'R33T vlastná poznámka');
    $_GET = ['id' => (string)$r33tOrder];
    ob_start();
    try { pageOrder(); } finally { $r33tPage = (string)ob_get_clean(); $_GET = []; }
    $r33tHistory = preg_match('/<h3>' . preg_quote(esc(I18n::t('admin.order.history')), '/') . '<\/h3>.*?<\/table>/s', $r33tPage, $m) === 1 ? $m[0] : '';
    assert_equals([true, true, true, false, false], [
        str_contains($r33tHistory, esc(I18n::t('admin.audit.note.admin_payment'))),
        str_contains($r33tHistory, esc(I18n::t('admin.audit.note.status_change'))),
        str_contains($r33tHistory, 'R33T vlastná poznámka'),
        str_contains($r33tHistory, 'Admin payment'), str_contains($r33tHistory, 'Admin status change'),
    ], 'R33 order history: "Admin payment" and "Admin status change" read in the admin\'s language; an admin\'s note stays as typed');

    // 3. Payment settings and shipping rates name the methods; the code stays the value and the tooltip.
    I18n::setLocale('sk');
    $pdo->prepare("DELETE FROM payment_method_countries WHERE store_id = 'default' AND method_code = 'r33t_pay'")->execute();
    $pdo->prepare("DELETE FROM payment_method_shipping WHERE store_id = 'default' AND method_code = 'r33t_pay'")->execute();
    $pdo->prepare("DELETE FROM payment_methods WHERE store_id = 'default' AND code = 'r33t_pay'")->execute();
    $pdo->prepare("DELETE FROM shipping_methods WHERE store_id = 'default' AND code = 'r33t_ship'")->execute();
    $pdo->prepare("INSERT INTO payment_methods (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, requires_online, active, sort_order)
        VALUES ('default', 'r33t_pay', 'manual', 'R33T Platba', '', 'offline', 0, 0, 0, 0, 0, 1, 990)")->execute();
    $pdo->prepare("INSERT INTO shipping_methods (store_id, code, carrier, name, service_type, supports_cod, supports_pickup, sort_order) VALUES ('default', 'r33t_ship', 'r33t', 'R33T Kuriér', 'parcel', 0, 0, 990)")->execute();
    $pdo->prepare("INSERT INTO payment_method_countries (store_id, method_code, country) VALUES ('default', 'r33t_pay', 'SK')")->execute();
    $pdo->prepare("INSERT INTO payment_method_shipping (store_id, method_code, shipping_method_code) VALUES ('default', 'r33t_pay', 'r33t_ship')")->execute();
    ob_start();
    try { pagePayments(); } finally { $r33tPayments = (string)ob_get_clean(); }
    ob_start();
    try { pageShipping(); } finally { $r33tShipping = (string)ob_get_clean(); }
    $r33tCodes = [];
    foreach (['bank_transfer', 'cod', 'gls', 'packeta', 'r33t_pay', 'r33t_ship'] as $r33tCode) {
        foreach (['payments' => $r33tPayments, 'shipping' => $r33tShipping] as $r33tWhere => $r33tHtml) {
            if (str_contains($r33tHtml, "<td>{$r33tCode}</td>") || str_contains($r33tHtml, ">{$r33tCode}</option>") || str_contains($r33tHtml, ">{$r33tCode} — ")) $r33tCodes[] = "{$r33tWhere}:{$r33tCode}";
        }
    }
    assert_equals([], $r33tCodes, 'R33 admin: payment countries, payment–shipping limits and rate selects show method names, not codes (bank_transfer, cod, gls)');
    assert_true(str_contains($r33tPayments, '<td title="r33t_pay">R33T Platba</td>') && str_contains($r33tPayments, '<td title="r33t_ship">R33T Kuriér</td>')
        && str_contains($r33tPayments, '<option value="r33t_pay" title="r33t_pay">R33T Platba</option>') && str_contains($r33tShipping, 'title="r33t_ship">R33T Kuriér</option>'),
        'R33 admin: a method reads its name with the code as tooltip and value');
} catch (Throwable $e) {
    assert_true(false, 'R33 texts (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    I18n::setLocale($r33tLocale);
    $_GET = [];
    try {
        $pdo->prepare("DELETE FROM payment_method_countries WHERE store_id = 'default' AND method_code = 'r33t_pay'")->execute();
        $pdo->prepare("DELETE FROM payment_method_shipping WHERE store_id = 'default' AND method_code = 'r33t_pay'")->execute();
        $pdo->prepare("DELETE FROM payment_methods WHERE store_id = 'default' AND code = 'r33t_pay'")->execute();
        $pdo->prepare("DELETE FROM shipping_methods WHERE store_id = 'default' AND code = 'r33t_ship'")->execute();
        if ($r33tOrder > 0) {
            $pdo->prepare("DELETE FROM audit_log WHERE entity = 'order_status' AND entity_id = ?")->execute([$r33tOrder]);
            $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$r33tOrder]);
        }
    } catch (Throwable $e) {}
}
