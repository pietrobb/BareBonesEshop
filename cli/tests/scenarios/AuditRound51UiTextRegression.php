<?php
$pdo ??= test_commerce_fixture();

// Review round 51 (UI and text lows):
//  - the price list and promotion forms said "(UTC)" while they take and show the store's wall clock;
//  - the merchant's payment e-mail named a merchant-named payment method in the customer's language (the snapshot);
//  - the card of pricing.json rules showed raw codes (free_shipping, fixed) instead of what the rule gives;
//  - the sample US store could not be saved (a VAT ID was required from a seller outside the EU);
//  - the web installer always set up a Slovak shop in EUR (no language or currency choice);
//  - the return acknowledgement said "we will let you know whether the return is approved" also within the
//    withdrawal period, where the consumer needs no approval;
//  - checkout kept U+202E (RIGHT-TO-LEFT OVERRIDE) and the other bidi controls in names and addresses.
echo "\n--- Review round 51: UI and text lows ---\n";

$r51uRoot = dirname(TEST_CLI_DIR);
$r51uFail = static function (string $what, Throwable $e): void {
    assert_true(false, 'R51 ' . $what . ' (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r51uLocale = I18n::locale();

// --- Low: pricing forms name the store's time, not UTC ---------------------------------------------------------------
try {
    $r51uUtc = [];
    foreach (WebInstaller::LOCALES as $r51uLang => $r51uName) {
        foreach (['admin.pricing.valid_from', 'admin.pricing.valid_to', 'admin.pricing.validity', 'admin.msg.promo.date_invalid', 'admin.promo.hint'] as $r51uKey) {
            if (str_contains(I18n::tIn($r51uKey, $r51uLang), 'UTC')) $r51uUtc[] = $r51uLang . ':' . $r51uKey;
        }
    }
    assert_equals([], $r51uUtc, 'R51 pricing: no pricing form label or message says UTC (the forms use the store\'s time zone)');
} catch (Throwable $e) { $r51uFail('pricing UTC', $e); }

// --- Low: the merchant's payment e-mail names the method in the merchant's language ---------------------------------
$r51uMails = [];
$r51uMailer = new class($r51uMails) implements MailAdapter {
    public function __construct(private array &$mails) {}
    public function send(array $msg): array { $this->mails[] = $msg; return ['status' => 'sent', 'message' => '', 'raw' => null]; }
    public function adapterId(): string { return 'r51u-mail'; }
};
$r51uSuiteConfig = TEST_DATA_DIR . '/' . AppConfig::SUITE_FILE;
$r51uSavedConfig = (string)file_get_contents($r51uSuiteConfig);
$r51uOrderId = 0;
try {
    $pdo->exec("DELETE FROM payment_methods WHERE code = 'r51u_card'");
    $pdo->exec("INSERT INTO payment_methods (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order)
        VALUES ('r51u_card', 'manual', 'Platba kartou R51U', '', 'offline', 0, NULL, 0, 0, NULL, 0, 1, 99)");
    $pdo->exec("UPDATE payment_methods SET translations = '{\"de\":{\"name\":\"Kreditkarte R51U\"}}' WHERE code = 'r51u_card'");
    MethodLabels::forget();
    $r51uSnapshot = json_encode(['code' => 'r51u_card', 'provider' => 'manual', 'label' => 'Kreditkarte R51U', 'service_type' => 'offline']);
    $pdo->prepare("INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_method_snapshot, payment_id, subtotal, total, currency, store_id, locale)
        VALUES (?, 'processing', 'paid', 'R51U Kunde', 'r51u@example.test', 'r51u_card', ?, 'r51u-pay', 5000, 5000, 'eur', 'default', 'de')")
        ->execute(['R51U-' . bin2hex(random_bytes(3)), $r51uSnapshot]);
    $r51uOrderId = (int)$pdo->lastInsertId();
    $r51uOrder = Orders::byId($r51uOrderId);
    assert_equals(['Platba kartou R51U', 'Kreditkarte R51U'], [PaymentService::merchantLabel($r51uOrder, 'sk'), PaymentService::merchantLabel($r51uOrder, 'de')],
        'R51 merchant mail: the method reads in the merchant\'s language (sk: the name as entered, de: the merchant\'s translation)');
    $r51uCfg = require $r51uSuiteConfig;
    $r51uCfg['merchant_notifications'] = ['enabled' => true, 'recipients' => ['r51u-merchant@example.test'], 'locale' => 'sk'];
    file_put_contents($r51uSuiteConfig, '<?php return ' . var_export($r51uCfg, true) . ";\n");
    MailService::useAdapter($r51uMailer);
    MerchantNotifier::send($r51uOrderId, 'paid');
    $r51uText = (string)($r51uMails[0]['text'] ?? '') . (string)($r51uMails[0]['html'] ?? '');
    assert_true(str_contains($r51uText, 'Platba kartou R51U') && !str_contains($r51uText, 'Kreditkarte R51U'),
        'R51 merchant mail: the Slovak merchant\'s payment e-mail of a German order names the method in Slovak (got: ' . substr(strip_tags($r51uText), 0, 300) . ')');
} catch (Throwable $e) {
    $r51uFail('merchant mail', $e);
} finally {
    MailService::useAdapter(null);
    file_put_contents($r51uSuiteConfig, $r51uSavedConfig);
    if ($r51uOrderId > 0) {
        $pdo->prepare('DELETE FROM merchant_notifications WHERE order_id = ?')->execute([$r51uOrderId]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$r51uOrderId]);
    }
    $pdo->exec("DELETE FROM payment_methods WHERE code = 'r51u_card'");
    MethodLabels::forget();
}

// --- Low: the pricing.json rules card says what a rule gives, not its codes ----------------------------------------
try {
    $r51uRules = Pricing::activeCartRules(['cart_rules' => [
        ['name' => 'WELCOME10', 'type' => 'coupon', 'code' => 'welcome10', 'discount_type' => 'percent', 'discount_value' => 10, 'min_subtotal' => 5000, 'active' => true],
        ['name' => 'Ship free', 'type' => 'automatic', 'conditions' => ['min_subtotal' => 5000], 'action' => 'free_shipping', 'active' => true],
        ['name' => 'Five off', 'type' => 'coupon', 'code' => 'five', 'discount_type' => 'fixed', 'discount_value' => 500, 'active' => true],
    ]]);
    I18n::setLocale('sk');
    $r51uLabels = array_map([Pricing::class, 'cartRuleEffectLabel'], $r51uRules);
    I18n::setLocale($r51uLocale);
    assert_true(str_contains($r51uLabels[0], '10 %') && str_contains($r51uLabels[0], I18n::price(5000, $r51uRules[0]['currency']))
        && str_starts_with($r51uLabels[1], I18n::tIn('admin.promo.rule_effect.free_shipping', 'sk')) && str_contains($r51uLabels[2], '5')
        && array_filter($r51uLabels, static fn(string $l): bool => (bool)preg_match('/free_shipping|fixed|discount|min /', $l)) === [],
        'R51 rules card: each rule reads in the admin\'s language (' . implode(' | ', $r51uLabels) . ')');
    $r51uPage = (string)file_get_contents($r51uRoot . '/src/Entrypoints/Admin/PricingRules.php');
    assert_true(str_contains($r51uPage, 'Pricing::cartRuleEffectLabel($rule)') && !str_contains($r51uPage, "esc(\$rule['effect'])"),
        'R51 rules card: the Promotions page prints the translated effect, not the doctor\'s raw one');
} catch (Throwable $e) { I18n::setLocale($r51uLocale); $r51uFail('rules card', $e); }

// --- Low: the sample US store can be saved ----------------------------------------------------------------------------
$r51uDir = test_fixture_path('r51-ui-stores');
@mkdir($r51uDir, 0700, true);
try {
    $r51uStores = json_decode((string)file_get_contents($r51uRoot . '/stores.example.json'), true);
    file_put_contents($r51uDir . '/stores.json', json_encode($r51uStores, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    StoreSettings::setPathForTesting($r51uDir . '/stores.json');
    $r51uInput = ['store_name' => 'US Store', 'payment_terms_days' => '14', 'vat_registered' => '1'];
    foreach (StoreSettings::LEGAL_TEXT as $r51uField) $r51uInput[$r51uField] = (string)($r51uStores['stores']['us']['legal'][$r51uField] ?? '');
    $r51uSaved = StoreSettings::save('us', $r51uInput);
    assert_true($r51uSaved['ok'], 'R51 stores: the sample US store saves unchanged (a seller outside the EU needs no EU VAT ID; errors: ' . implode(' ', $r51uSaved['errors']) . ')');
    $r51uCzInput = ['store_name' => 'CZ', 'payment_terms_days' => '14', 'vat_registered' => '1'];
    foreach (StoreSettings::LEGAL_TEXT as $r51uField) $r51uCzInput[$r51uField] = (string)($r51uStores['stores']['cz']['legal'][$r51uField] ?? '');
    $r51uCzInput['ic_dph'] = '';
    assert_true(!StoreSettings::save('cz', $r51uCzInput)['ok'], 'R51 stores: a VAT-registered seller in the EU still needs its VAT ID');
} catch (Throwable $e) {
    $r51uFail('US store', $e);
} finally {
    StoreSettings::setPathForTesting(null);
}

// --- Low: the web installer picks the shop's language and currency ---------------------------------------------------
try {
    $r51uForm = ['shop_name' => 'R51 Laden', 'app_url' => 'https://r51.example.test', 'private_parent' => '', 'email' => 'owner@r51-laden.de', 'company' => 'R51 GmbH',
        'street' => 'Hauptstr. 1', 'city' => 'Berlin', 'zip' => '10115', 'country' => 'DE', 'timezone' => 'Europe/Berlin', 'admin_user' => 'owner',
        'admin_password' => 'r51-long-password', 'admin_password_repeat' => 'r51-long-password', 'pay_cod' => '1', 'shop_locale' => 'de', 'currency' => 'czk'];
    [$r51uValues, $r51uErrors] = WebInstaller::validate($r51uForm, $r51uRoot);
    assert_true(!isset($r51uErrors['shop_locale']) && !isset($r51uErrors['currency']) && $r51uValues['shop_locale'] === 'de' && $r51uValues['currency'] === 'czk',
        'R51 installer: a German shop in CZK passes the form');
    [, $r51uBad] = WebInstaller::validate(['shop_locale' => 'xx', 'currency' => 'btc'] + $r51uForm, $r51uRoot);
    assert_true(isset($r51uBad['shop_locale'], $r51uBad['currency']), 'R51 installer: an unknown language or currency is refused at its field');
    $r51uExample = json_decode((string)file_get_contents($r51uRoot . '/stores.example.json'), true);
    $r51uInstalled = WebInstaller::withIdentity($r51uExample, $r51uValues + ['vat_id' => '', 'iban' => '', 'ico' => '', 'dic' => '', 'bic' => '']);
    $r51uDefault = $r51uInstalled['stores'][$r51uInstalled['default_store']];
    assert_equals(['de', 'czk', 'de', 'czk'], [$r51uDefault['default_locale'], $r51uDefault['currency'], $r51uDefault['languages'][0], $r51uInstalled['default_currency']],
        'R51 installer: the default store gets the chosen language (first of its languages) and currency');
    $r51uKept = WebInstaller::withIdentity($r51uExample, ['shop_locale' => '', 'currency' => ''] + $r51uValues + ['vat_id' => '', 'iban' => '', 'ico' => '', 'dic' => '', 'bic' => '']);
    assert_equals(['sk', 'eur'], [$r51uKept['stores']['default']['default_locale'], $r51uKept['stores']['default']['currency']],
        'R51 installer: a form without the fields keeps the starter\'s language and currency');
    $r51uDefaults = WebInstaller::defaults($r51uRoot, ['HTTP_HOST' => 'r51.example.test']);
    $r51uPage = (string)file_get_contents($r51uRoot . '/src/Entrypoints/Install.php');
    assert_true(($r51uDefaults['shop_locale'] ?? '') !== '' && ($r51uDefaults['currency'] ?? '') !== ''
        && str_contains($r51uPage, "\$select('shop_locale', WebInstaller::LOCALES)") && str_contains($r51uPage, "\$select('currency', WebInstaller::CURRENCIES)"),
        'R51 installer: the form offers the language and the currency, preset from the starter');
    $r51uSelect = WebInstaller::selectField('currency', WebInstaller::CURRENCIES, 'czk', 'bad');
    assert_true(str_contains($r51uSelect, '<option value="czk" selected>') && str_contains($r51uSelect, 'aria-invalid="true"')
        && str_contains($r51uSelect, 'aria-describedby="f-currency-hint f-currency-error"') && str_contains($r51uSelect, '<label for="f-currency">'),
        'R51 installer: the currency select is labelled, preselected and reports its error (' . $r51uSelect . ')');
} catch (Throwable $e) { $r51uFail('installer', $e); }

// --- Low: within the withdrawal period the return acknowledgement asks for no approval -------------------------------
$r51uMails = [];
try {
    MailService::useAdapter($r51uMailer);
    $r51uAck = new ReflectionMethod(Rma::class, 'acknowledge');
    $r51uBase = ['id' => 0, 'order_number' => 'R51U-RMA', 'customer_name' => 'R51U', 'customer_email' => 'r51u-rma@example.test', 'locale' => 'en', 'store_id' => 'default', 'status' => 'processing'];
    $r51uAck->invoke(null, $r51uBase + ['created_at' => gmdate('Y-m-d H:i:s', time() - 3 * 86400)], 'RMA-R51U-1', '', []);
    $r51uAck->invoke(null, $r51uBase + ['created_at' => gmdate('Y-m-d H:i:s', time() - 800 * 86400)], 'RMA-R51U-2', '', []);
    $r51uRecent = strip_tags((string)($r51uMails[0]['html'] ?? ''));
    $r51uLate = strip_tags((string)($r51uMails[1]['html'] ?? ''));
    assert_true(!str_contains($r51uRecent, 'whether the return is approved') && str_contains($r51uRecent, 'without our approval') && str_contains($r51uRecent, 'Keep this e-mail'),
        'R51 return mail: within the withdrawal period it says no approval is needed (got: ' . substr(trim(preg_replace('/\s+/', ' ', $r51uRecent)), 0, 300) . ')');
    assert_true(str_contains($r51uLate, 'whether the return is approved') && !str_contains($r51uLate, 'without our approval'),
        'R51 return mail: a return past the period still waits for the merchant\'s decision');
    $r51uMissing = [];
    foreach (array_keys(WebInstaller::LOCALES) as $r51uLang) {
        $r51uTpl = (string)@file_get_contents($r51uRoot . '/templates/emails/' . $r51uLang . '/rma_received.html');
        if (!str_contains($r51uTpl, '{{#if within_withdrawal}}') || !str_contains($r51uTpl, '{{#if needs_approval}}')) $r51uMissing[] = $r51uLang;
    }
    assert_equals([], $r51uMissing, 'R51 return mail: every language has both wordings');
} catch (Throwable $e) {
    $r51uFail('return mail', $e);
} finally {
    MailService::useAdapter(null);
}

// --- Low: checkout removes bidi controls from the buyer's fields ----------------------------------------------------
try {
    $r51uClean = CheckoutService::withoutBidiControls(['name' => "J\u{00E1}n\u{202E}gnp.exe", 'street' => "Hlavn\u{00E1}\u{2066} 1\u{2069}", 'city' => "Ko\u{0161}ice\u{200F}",
        'billing_name' => "\u{202D}Firma", 'zip' => "040\u{0085}01", 'email' => 'r51u@example.test', 'note' => "a\u{061C}b", 'street_bytes' => "x\u{202E}"]);
    assert_equals(['Jángnp.exe', 'Hlavná 1', 'Košice', 'Firma', '04001', 'ab', "x\u{202E}"],
        [$r51uClean['name'], $r51uClean['street'], $r51uClean['city'], $r51uClean['billing_name'], $r51uClean['zip'], $r51uClean['note'], $r51uClean['street_bytes']],
        'R51 checkout: U+202E and the other bidi and C1 controls are removed from the buyer\'s fields (others untouched)');
    assert_equals("Hlavn\xE1", CheckoutService::withoutBidiControls(['street' => "Hlavn\xE1"])['street'], 'R51 checkout: a field that is not UTF-8 is left for the refusal');
    if (!function_exists('checkoutExecute')) require_once $r51uRoot . '/src/Http/ApiHandlers.php';
    $r51uLong = checkoutExecute(['name' => 'R51U', 'email' => 'r51u-bidi@example.test', 'street' => 'S 1', 'city' => 'C', 'country' => 'SK',
        'zip' => '04001' . str_repeat("\u{202E}", 20)]);
    http_response_code(200);
    assert_true(($r51uLong['error_code'] ?? '') !== 'checkout.error.field_too_long', 'R51 checkout: the controls are removed before the field checks (a zip of controls is not "too long")');
} catch (Throwable $e) { http_response_code(200); $r51uFail('checkout bidi', $e); }
