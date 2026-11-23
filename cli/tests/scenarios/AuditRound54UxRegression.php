<?php

// Review round 54 (kolo 19), usability and documentation:
//  - operations.worker_cron passed only as the five schedule fields although README asks for the whole crontab line, and a
//    value that failed was reported as "configured".
//  - Admin → Stores and seller had no field for a store's domains.
//  - the admin showed an order's payment method in the customer's language (the checkout snapshot).
//  - the CSV import help did not list vat_class and always spoke of euros; the import templates had no vat_class column.
//  - with the log mail provider the customer read "the e-mail could not be sent".
//  - installer presets: English prefilled the Slovak example store, Czech its name "Slovenský obchod" (R53 test), and
//    `setup.php install` left the admin in English over a Slovak shop.
//  - the Czech, Polish and Hungarian terms did not print the seller's VAT ID.

$r54uRoot = dirname(TEST_CLI_DIR);
$r54uFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R54 UX {$what}: " . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$pdo ??= test_commerce_fixture();
Bootstrap::initStoreContext('/', '', 'default');

// --- operations.worker_cron ----------------------------------------------------------------------------------------------
try {
    $r54uCron = static function (string $cron): array {
        $checks = [];
        $add = static function (string $status, string $name, string $message, string $action = '') use (&$checks): void {
            $checks[$name] = [$status, $message];
        };
        $method = new ReflectionMethod(Setup::class, 'operationsChecks');
        $method->setAccessible(true);
        $method->invoke(null, ['operations' => ['worker_cron' => $cron]], dirname(TEST_CLI_DIR), $add);
        return $checks['operations.cron'] ?? ['missing', ''];
    };
    $r54uLine = $r54uCron('* * * * * php /srv/shop/cli/worker.php');
    assert_equals('pass', $r54uLine[0], 'R54 doctor: the whole crontab line README asks to record passes (was: warn, only five fields passed)');
    assert_equals('pass', $r54uCron('* * * * *')[0], 'R54 doctor: the schedule alone still passes');
    // R56 (review round 21, low): the worker runs every minute; a valid schedule that runs it less often warns.
    assert_equals('warn', $r54uCron('@hourly php /srv/shop/cli/worker.php')[0], 'R54/R56 doctor: a @hourly line is valid but warns (the worker runs every minute)');
    $r54uBad = $r54uCron('every minute');
    assert_true($r54uBad[0] === 'warn' && !str_contains($r54uBad[1], 'configured') && str_contains($r54uBad[1], 'neither'),
        'R54 doctor: a value that is no cron line warns and says so (was: "Worker cron declaration configured."): ' . $r54uBad[1]);
    assert_equals('warn', $r54uCron('')[0], 'R54 doctor: no declaration still warns');
} catch (Throwable $e) { $r54uFail('worker cron', $e); }

// --- Store domains ------------------------------------------------------------------------------------------------------
$r54uDir = test_fixture_path('r54-ux-stores');
@mkdir($r54uDir, 0700, true);
try {
    $r54uStores = json_decode((string)file_get_contents($r54uRoot . '/stores.example.json'), true);
    $r54uStores['stores']['cz']['domains'] = ['shop.example.cz'];
    file_put_contents($r54uDir . '/stores.json', json_encode($r54uStores, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    StoreSettings::setPathForTesting($r54uDir . '/stores.json');
    $r54uInput = ['store_name' => 'US Store', 'payment_terms_days' => '14', 'vat_registered' => '1'];
    foreach (StoreSettings::LEGAL_TEXT as $r54uField) $r54uInput[$r54uField] = (string)($r54uStores['stores']['us']['legal'][$r54uField] ?? '');
    $r54uSaved = StoreSettings::save('us', $r54uInput + ['domains' => "Shop.Example.com\nwww.shop.example.com, shop.example.com."]);
    $r54uData = json_decode((string)file_get_contents($r54uDir . '/stores.json'), true);
    assert_true($r54uSaved['ok'] && ($r54uData['stores']['us']['domains'] ?? null) === ['shop.example.com', 'www.shop.example.com'],
        'R54 stores: the admin saves a store\'s domains, lower case and once each (was: only by editing stores.json): '
        . json_encode([$r54uSaved['errors'], $r54uData['stores']['us']['domains'] ?? null]));
    $r54uTaken = StoreSettings::save('us', $r54uInput + ['domains' => 'shop.example.cz']);
    $r54uInvalid = StoreSettings::save('us', $r54uInput + ['domains' => 'https://shop.example.com/path']);
    $r54uNoDot = StoreSettings::save('us', $r54uInput + ['domains' => 'localhost']);
    $r54uData = json_decode((string)file_get_contents($r54uDir . '/stores.json'), true);
    assert_true(!$r54uTaken['ok'] && !$r54uInvalid['ok'] && !$r54uNoDot['ok'] && ($r54uData['stores']['us']['domains'] ?? null) === ['shop.example.com', 'www.shop.example.com'],
        'R54 stores: another store\'s domain, a URL and a name without a dot are refused and change nothing');
    assert_true(StoreSettings::save('us', $r54uInput)['ok'] && (json_decode((string)file_get_contents($r54uDir . '/stores.json'), true)['stores']['us']['domains'] ?? null) === ['shop.example.com', 'www.shop.example.com'],
        'R54 stores: a form without the field keeps the domains');
    assert_true(StoreSettings::save('us', $r54uInput + ['domains' => ''])['ok'] && (json_decode((string)file_get_contents($r54uDir . '/stores.json'), true)['stores']['us']['domains'] ?? null) === [],
        'R54 stores: an empty field removes them');
    $r54uPage = (string)file_get_contents($r54uRoot . '/src/Entrypoints/Admin/StoreSettings.php');
    assert_true(str_contains($r54uPage, 'name="domains"') && str_contains($r54uPage, "if (Security::adminRole() !== 'owner') unset(\$input['domains']);"),
        'R54 stores: the page has the field, and only the owner saves it');
    assert_true(str_contains((string)file_get_contents($r54uRoot . '/README.md'), '*Store domains*'), 'R54 README: the store domains are documented');
} catch (Throwable $e) {
    $r54uFail('store domains', $e);
} finally {
    StoreSettings::setPathForTesting(null);
}

// --- Payment method in the admin's language -----------------------------------------------------------------------------
$r54uLocale = I18n::locale();
try {
    I18n::setLocale('sk');
    $r54uOrder = ['payment_method' => 'bank_transfer', 'locale' => 'de', 'payment_method_snapshot' => json_encode(['code' => 'bank_transfer', 'label' => 'Banküberweisung', 'service_type' => 'bank_transfer'])];
    $r54uLabel = PaymentService::adminLabel($r54uOrder);
    assert_true($r54uLabel !== 'Banküberweisung' && $r54uLabel === PaymentService::merchantLabel($r54uOrder, 'sk') && $r54uLabel !== '',
        "R54 admin: a German order's payment method reads in the Slovak admin's language ({$r54uLabel}; was: Banküberweisung)");
} catch (Throwable $e) { $r54uFail('payment label', $e); } finally { I18n::setLocale($r54uLocale); }

// --- CSV import help and templates --------------------------------------------------------------------------------------
try {
    foreach (['en', 'sk', 'cs', 'de', 'pl', 'hu', 'es'] as $r54uLang) {
        I18n::setLocale($r54uLang);
        $r54uHelp = (string)(AdminForm::definition('admin-import')['description'] ?? '');
        assert_true(str_contains($r54uHelp, strtoupper(Warehouse::catalogCurrency(StoreContext::current()->catalogId()))) && str_contains($r54uHelp, 'vat_class (')
            && str_contains($r54uHelp, implode(', ', VatService::productClasses())) && !str_contains($r54uHelp, '{'),
            "R54 import {$r54uLang}: the help names the catalog currency and the vat_class column with its classes (was: euros, no vat_class): {$r54uHelp}");
    }
    // R55: the header row comes from ImportTemplates (cli/schema-build.php and the admin's download).
    assert_true(in_array('vat_class', ImportTemplates::headers([], []), true) && str_contains((string)file_get_contents($r54uRoot . '/cli/schema-build.php'), 'ImportTemplates::template('), // R56: headers + sample row
        'R54 import: the generated CSV templates have a vat_class column');
} catch (Throwable $e) { $r54uFail('import help', $e); } finally { I18n::setLocale($r54uLocale); }

// --- Log mail provider: the customer is not told sending failed ----------------------------------------------------------
$r54uMailDir = test_fixture_path('r54-ux-mail');
@mkdir($r54uMailDir, 0700, true);
try {
    $r54uCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy) VALUES
        (98571, 'default', {$r54uCategory}, 'R54U-OUT', 'r54u-out', 'simple', 'R54U sold out', 1000, 'eur', 0, 'active', 'deny')");
    MailService::useAdapter(new LogMail(['log_file' => $r54uMailDir . '/emails.log']));
    $r54uCfg = new ReflectionProperty(Security::class, 'cfg');
    $r54uCfgBefore = $r54uCfg->getValue();
    $r54uCfg->setValue(null, ['app_url' => 'https://shop.example'] + (array)$r54uCfgBefore);
    try {
        $r54uOutcome = BackInStock::subscribe(98571, 'r54u-wait@example.test', 'en');
    } finally {
        $r54uCfg->setValue(null, $r54uCfgBefore);
    }
    assert_equals('logged', $r54uOutcome, 'R54 log mail: a back-in-stock confirmation the log provider only recorded is "logged", not "failed"');
    foreach (['en', 'sk', 'cs', 'de', 'pl', 'hu', 'es'] as $r54uLang) {
        foreach (['mail.logged_only', 'withdrawal.done_logged', 'complaint.done_logged'] as $r54uKey) {
            $r54uText = I18n::tIn($r54uKey, $r54uLang);
            assert_true($r54uText !== $r54uKey && $r54uText !== '', "R54 log mail {$r54uLang}: {$r54uKey} is translated");
        }
    }
    $r54uPages = (string)file_get_contents($r54uRoot . '/src/Entrypoints/Storefront/WithdrawalPages.php') . (string)file_get_contents($r54uRoot . '/src/Entrypoints/Storefront/ComplaintPages.php')
        . (string)file_get_contents($r54uRoot . '/src/Entrypoints/Storefront/GuestPages.php') . (string)file_get_contents($r54uRoot . '/src/Entrypoints/Storefront/Account.php');
    assert_true(str_contains($r54uPages, "'logged' => 'withdrawal.done_logged'") && str_contains($r54uPages, "'logged' => 'complaint.done_logged'")
        && substr_count($r54uPages, 'mail.logged_only') === 3,
        'R54 log mail: the withdrawal, complaint, account offer, back-in-stock and e-mail verification pages read "logged" as recorded, not failed');
} catch (Throwable $e) {
    $r54uFail('log mail', $e);
} finally {
    MailService::useAdapter(null);
    $pdo->exec("DELETE FROM back_in_stock_requests WHERE product_id = 98571");
    $pdo->exec('DELETE FROM products WHERE id = 98571');
}

// --- `setup.php install`: the admin opens in the main store's language ---------------------------------------------------
$r54uSeedRoot = test_fixture_path('r54-ux-seed');
@mkdir($r54uSeedRoot . '/app', 0700, true);
@mkdir($r54uSeedRoot . '/data', 0700, true);
try {
    copy($r54uRoot . '/stores.example.json', $r54uSeedRoot . '/app/stores.example.json');
    @unlink($r54uSeedRoot . '/data/stores.json');
    DataOverrides::setDirForTesting($r54uSeedRoot . '/data');
    DataOverrides::seedStores($r54uSeedRoot . '/app', []);
    $r54uSeeded = json_decode((string)file_get_contents($r54uSeedRoot . '/data/stores.json'), true);
    $r54uExample = json_decode((string)file_get_contents($r54uRoot . '/stores.example.json'), true);
    $r54uMain = (string)$r54uExample['stores'][(string)$r54uExample['default_store']]['default_locale'];
    $r54uExample['default_locale'] = $r54uMain;
    foreach ($r54uExample['stores'] as &$r54uStore) if (!empty($r54uStore['sample'])) { unset($r54uStore['sample']); $r54uStore['public'] = false; }
    unset($r54uStore);
    assert_true(($r54uSeeded['default_locale'] ?? null) === $r54uMain && is_array($r54uSeeded) && count($r54uSeeded['stores']) === count($r54uExample['stores'])
        && array_column($r54uSeeded['stores'], 'default_locale') === array_column($r54uExample['stores'], 'default_locale'),
        "R54 install: the installed stores.json makes the main store's language ({$r54uMain}) the admin's (was: \"en\"); nothing else changes");
} catch (Throwable $e) {
    $r54uFail('setup install locale', $e);
} finally {
    DataOverrides::setDirForTesting(null);
}

// --- Terms print the seller's VAT ID -------------------------------------------------------------------------------------
foreach (['cs', 'pl', 'hu', 'sk', 'en', 'de', 'es'] as $r54uLang) {
    assert_true(str_contains((string)file_get_contents($r54uRoot . "/templates/legal/{$r54uLang}/terms.html"), '{{ic_dph}}'),
        "R54 terms {$r54uLang}: the seller's VAT ID is printed (was: missing in cs, pl and hu)");
}
