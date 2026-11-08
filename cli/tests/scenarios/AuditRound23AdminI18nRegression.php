<?php

// Review round 23 (2026-11-08), UX "admin localisation and installer" (goal #376):
// - the dashboard's setup checklist showed the doctor's English messages in every admin language, and its count read
//   "1 problems" (not I18n::plural);
// - the audit log showed the notes the code writes in English ("Admin edit: price", "Order created");
// - the attribute types were Slovak ("Enum (zoznam hodnôt)", "Áno/Nie") and the payment methods of the BBF forms too,
//   whatever the admin language;
// - the web installer asked for no BIC, IČO or DIČ, which invoices and bank transfer instructions print.
echo "\n--- R23: doctor checklist and audit notes in the admin's language, plural counts, installer BIC/IČO/DIČ ---\n";
require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Shared.php';
require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Operations.php';
require_once TEST_CLI_DIR . '/../src/Entrypoints/Admin/Reports.php';

$r23iLocale = I18n::locale();
$r23iStore = StoreContext::current()->id();
try {
    // 1) Checklist items carry a title in the admin's language ({item} = the store, provider or extension); unknown checks none.
    I18n::setLocale('sk');
    $r23iSummary = SetupChecklist::summary(['checks' => [
        ['status' => 'warn', 'name' => 'provider.mail', 'message' => 'Mail provider selected: log', 'action' => 'Configure smtp'],
        ['status' => 'fail', 'name' => 'stores.legal.' . $r23iStore, 'message' => 'Store still has example seller data', 'action' => ''],
        ['status' => 'fail', 'name' => 'runtime.extension.sodium', 'message' => 'sodium missing', 'action' => 'Enable sodium'],
        ['status' => 'warn', 'name' => 'r23i.unknown_check', 'message' => 'R23I raw', 'action' => ''],
    ]]);
    $r23iTitles = array_column($r23iSummary['items'], 'title', 'name');
    ksort($r23iTitles);
    assert_equals([
        'provider.mail' => 'E-maily sa len zapisujú do logu, nedoručujú sa',
        'r23i.unknown_check' => null,
        'runtime.extension.sodium' => 'Chýba rozšírenie PHP sodium',
        'stores.legal.' . $r23iStore => "Obchod {$r23iStore} má stále vzorové údaje predajcu",
    ], $r23iTitles, 'R23 i18n: setup checklist items are titled in the admin\'s language (Slovak), an unknown check keeps its doctor text');

    // 2) Every check the doctor can report has a title in every admin language.
    $r23iMissing = [];
    $r23iNames = ['runtime.php', 'runtime.vendor_bbf', 'runtime.extension.intl', 'runtime.private_runtime_dir', 'config.app_url', 'database.connection',
        'database.schema', 'database.writable', 'mysql', 'extensions', 'stores.file', 'stores.default', 'stores.invoice_numbering', 'stores.legal.x',
        'stores.url_prefix.x', 'stores.timezone.x', 'stores.currency_prices.x', 'admin.accounts', 'admin.mfa_key', 'catalog.digital_files',
        'catalog.digital_bundles', 'pricing.legacy_rules', 'payment.online', 'payment.provider.stripe', 'operations.backup', 'operations.gdpr_retention_gc',
        'operations.health', 'operations.worker', 'provider.mail', 'provider.mail_from', 'provider.webhook', 'provider.webhook_config', 'provider.erp',
        'provider.shipping_labels', 'provider.shipping_lifecycle', 'upgrade.cart_reminder_consent', 'upgrade.nginx_denies', 'upgrade.nginx_php_fallback',
        'upgrade.shipping_webhook_timestamp', 'upgrade.webhook_http'];
    foreach (['en', 'sk', 'cs', 'de', 'pl', 'hu', 'es'] as $r23iLang) {
        I18n::setLocale($r23iLang);
        foreach ($r23iNames as $r23iName) if (SetupChecklist::title($r23iName) === null) $r23iMissing[] = "{$r23iLang}:{$r23iName}";
    }
    assert_equals([], $r23iMissing, 'R23 i18n: every doctor check has a translated checklist title in all seven admin languages');

    // 3) The dashboard: counts by I18n::plural ("1 problém", "3 upozornenia"; never "1 problems"), titles shown, doctor text as detail.
    $r23iCounts = static function (string $lang, int $fail, int $warn): string {
        I18n::setLocale($lang);
        return I18n::format('admin.dashboard.checklist_counts', ['problems' => I18n::plural('admin.dashboard.checklist_problems', $fail),
            'warnings' => I18n::plural('admin.dashboard.checklist_warnings', $warn)]);
    };
    assert_equals([
        '1 problem and 1 warning need attention before launch.',
        'Pred spustením treba vyriešiť: 1 problém a 3 upozornenia.',
        'Před spuštěním je třeba vyřešit: 5 problémů a 1 upozornění.',
        'Przed uruchomieniem do rozwiązania: 2 problemy i 5 ostrzeżeń.',
    ], [$r23iCounts('en', 1, 1), $r23iCounts('sk', 1, 3), $r23iCounts('cs', 5, 1), $r23iCounts('pl', 2, 5)],
        'R23 i18n: the checklist count agrees with the number in each language (was: "1 problems")');
    I18n::setLocale('sk');
    ob_start();
    pageDashboard();
    $r23iPage = (string)ob_get_clean();
    preg_match('~<div class="card" id="setup-checklist">(.*?)</div>~s', $r23iPage, $r23iCard);
    $r23iCard = html_entity_decode($r23iCard[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    assert_true($r23iCard !== '' && !str_contains($r23iCard, 'problems') && !preg_match('/\{(?:fail|warn|problems|warnings)\}/', $r23iCard)
        && (str_contains($r23iCard, I18n::t('admin.dashboard.checklist_ok')) || str_contains($r23iCard, I18n::t('admin.dashboard.checklist_detail'))),
        'R23 i18n: the Slovak dashboard checklist uses the plural forms and shows the doctor text only as detail (' . mb_substr(strip_tags($r23iCard), 0, 300) . ')');
    if (str_contains($r23iCard, 'data-check="provider.mail"')) {
        assert_true(str_contains($r23iCard, '<strong>' . I18n::t('admin.doctor.provider_mail') . '</strong>'), 'R23 i18n: the log-only mail warning is titled in Slovak on the dashboard');
    }

    // 4) Audit notes the code writes in English read in the admin's language; a note it does not know stays as written.
    I18n::setLocale('de');
    $r23iNotes = [
        AuditLogLabels::note('product', 7, 'Admin edit: price, stock'), AuditLogLabels::note('order', 7, 'Order created'),
        AuditLogLabels::note('category', 7, 'Admin category delete'), AuditLogLabels::note('brand', 7, 'Admin brand'),
        AuditLogLabels::note('shipment', 7, 'Admin shipment shipped'), AuditLogLabels::note('order_status', 7, 'Admin status change'),
        AuditLogLabels::note('order_refund', 7, 'Manual refund of 1290 completed by admin: bank', static fn(int $id): string => 'eur'),
        AuditLogLabels::note('product', 7, 'R23I free text'),
    ];
    assert_equals([
        'Im Admin bearbeitet: price, stock', 'Bestellung angelegt', 'Gelöscht', 'Im Admin gespeichert', 'Als versendet markiert', 'Status im Admin geändert',
        'Manuelle Erstattung von ' . I18n::price(1290, 'eur') . ' abgeschlossen von admin: bank', 'R23I free text',
    ], $r23iNotes, 'R23 i18n: audit-log notes are shown in German in the German admin (stored notes unchanged)');
    $r23iKeys = [];
    foreach (['en', 'sk', 'cs', 'de', 'pl', 'hu', 'es'] as $r23iLang) {
        $r23iLangKeys = array_keys(require TEST_CLI_DIR . "/../lang/{$r23iLang}.php");
        $r23iKeys[$r23iLang] = array_values(array_filter($r23iLangKeys, static fn(string $k): bool => str_starts_with($k, 'admin.audit.note.') || str_starts_with($k, 'admin.doctor.')));
    }
    assert_equals(array_fill_keys(['sk', 'cs', 'de', 'pl', 'hu', 'es'], $r23iKeys['en']), array_diff_key($r23iKeys, ['en' => 1]), 'R23 i18n: every language has the same audit-note and doctor keys');

    // 5) BBF option lists in the admin's language: attribute types English in the English admin, not "Áno/Nie".
    I18n::setLocale('en');
    $r23iEn = array_column((array)BbfOptions::forAction('bbf_attr_types'), 'label');
    $r23iPay = array_column((array)BbfOptions::forAction('bbf_payment_methods'), 'label');
    $r23iStatus = BbfOptions::forAction('bbf_product_statuses')[0]['label'] ?? null;
    I18n::setLocale('sk');
    $r23iSk = array_column((array)BbfOptions::forAction('bbf_attr_types'), 'label');
    assert_equals([['List of values', 'Number', 'Yes/No'], ['Cash on delivery', 'Bank transfer', 'Card online'], 'Active', true],
        [$r23iEn, $r23iPay, $r23iStatus, !in_array('Yes/No', $r23iSk, true) && count($r23iSk) === 3],
        'R23 i18n: attribute types and payment methods of the admin forms follow the admin language (were Slovak everywhere)');
} finally {
    I18n::setLocale($r23iLocale);
}

// 6) The web installer asks for BIC, IČO and DIČ, checks the BIC and writes all three into the seller.
$r23iRoot = TEST_CLI_DIR . '/..';
$r23iForm = ['shop_name' => 'R23I', 'app_url' => 'https://r23i.eu', 'private_parent' => sys_get_temp_dir(), 'email' => 'owner@r23i.eu', 'company' => 'R23I s.r.o.',
    'street' => 'Hlavná 1', 'city' => 'Košice', 'zip' => '04001', 'country' => 'SK', 'timezone' => 'Europe/Bratislava', 'pay_bank' => '1',
    'iban' => 'SK31 1200 0000 1987 4263 7541', 'admin_user' => 'owner', 'admin_password' => 'Long enough pass 1', 'admin_password_repeat' => 'Long enough pass 1'];
[$r23iOk, $r23iOkErrors] = WebInstaller::validate($r23iForm + ['bic' => 'tatr skbx', 'ico' => '12345678', 'dic' => '2020123456'], $r23iRoot);
[, $r23iBadErrors] = WebInstaller::validate($r23iForm + ['bic' => 'NOTABIC'], $r23iRoot);
$r23iLegal = WebInstaller::withIdentity(['default_store' => 'default', 'stores' => ['default' => ['legal' => []]]], $r23iOk)['stores']['default']['legal'];
$r23iFields = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Install.php');
assert_equals([[], true, 'TATRSKBX', '12345678', '2020123456', true],
    [array_diff_key($r23iOkErrors, ['private_parent' => 1]), isset($r23iBadErrors['bic']), $r23iLegal['bic'] ?? null, $r23iLegal['ico'] ?? null, $r23iLegal['dic'] ?? null,
     str_contains($r23iFields, "\$field('bic'") && str_contains($r23iFields, "\$field('ico'") && str_contains($r23iFields, "\$field('dic'")],
    'R23 i18n: the web installer asks for BIC, IČO and DIČ, refuses a malformed BIC and stores them with the seller');
