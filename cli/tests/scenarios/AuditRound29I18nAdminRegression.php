<?php

// Review round 29 (2026-11-11), lows (goal #413): "variable symbol" in the PL/HU/DE/ES order e-mail, English audit-log
// notes, "2 chýb" in the SK admin, no "Fix" link for the e-mail check, a payment method code saved as "bank-transfer",
// and a product without a price in the store's currency shown at its EUR price in the PLN store.
echo "\n--- R29: admin language, plural forms, payment codes, no foreign-currency price where a store does not sell ---\n";

$r29iLocale = I18n::locale();
$r29iRoot = dirname(TEST_CLI_DIR);
try {
    // 1. The order confirmation's opening line names the payment reference in the e-mail's language.
    $r29iLeft = [];
    foreach (['pl', 'hu', 'de', 'es'] as $r29iLang) {
        $r29iMail = (string)file_get_contents("{$r29iRoot}/templates/emails/{$r29iLang}/order_confirmation.html");
        if (stripos($r29iMail, 'variable symbol') !== false) $r29iLeft[] = $r29iLang;
    }
    assert_equals([], $r29iLeft, 'R29 mail: the PL/HU/DE/ES order confirmation no longer says "variable symbol"');

    // 2. Slovak plural: 1 chyba, 2–4 chyby, 5+ chýb (import, feed sync, feed status).
    I18n::setLocale('sk');
    assert_equals(['1 chyba', '2 chyby', '4 chyby', '5 chýb'],
        array_map(static fn(int $n): string => I18n::plural('admin.common.errors_count', $n), [1, 2, 4, 5]),
        'R29 SK admin: the error count takes the plural form ("2 chyby", not "2 chýb")');
    $r29iFeedSrc = (string)file_get_contents("{$r29iRoot}/src/FeedMessages.php") . (string)file_get_contents("{$r29iRoot}/src/Entrypoints/Admin/Operations.php");
    assert_true(substr_count($r29iFeedSrc, "I18n::plural('admin.common.errors_count'") >= 3 && str_contains(I18n::t('admin.feed.status_partial'), '{errors}'),
        'R29 SK admin: import, feed sync and feed status count errors with the plural form ("Čiastočne: 2 chyby")');

    // 3. Audit notes the R23 list missed are in the admin's language; amounts in money.
    $r29iNotes = [
        AuditLogLabels::note('page', 1, 'Content updated'),
        AuditLogLabels::note('invoice', 1, 'Refund credit issued'),
        AuditLogLabels::note('admin_user', 1, 'Admin login succeeded'),
        AuditLogLabels::note('order', 1, 'Order line 3: 2 units reserved after restock'),
        AuditLogLabels::note('order_refund', 1, 'stripe refund re_x of ' . AuditLogLabels::amount(1290, 'eur') . ' was booked before refunds were booked by their id; not booked again'),
    ];
    assert_equals([], array_values(array_filter($r29iNotes, static fn(string $n): bool => preg_match('/\b(updated|issued|succeeded|units reserved|booked before)\b/', $n) === 1)),
        'R29 audit log: page, credit-note, login, backorder and legacy-refund notes are in the admin\'s language (' . implode(' | ', $r29iNotes) . ')');

    // 4. The e-mail checks of the dashboard checklist link to E-mail delivery.
    $r29iMap = (new ReflectionClassConstant(SetupChecklist::class, 'FIX_PAGES'))->getValue();
    assert_equals('mail', $r29iMap['provider.mail'] ?? null, 'R29 checklist: the e-mail check has a "Fix" link to E-mail delivery');

    // 5. Payment and shipping codes keep their underscore (PaymentMethods knows "bank_transfer", not "bank-transfer").
    assert_equals(['bank_transfer', 'cod', 'packeta_pickup', 'my-carrier'], array_map([Security::class, 'code'], ['bank_transfer', ' COD ', 'Packeta_Pickup', 'My Carrier']),
        'R29 admin: a saved payment/shipping code keeps its underscores');

    // 6. Not sold in this store (no price in its currency): no price in another currency, no structured-data offer.
    $r29iPrice = new ReflectionMethod(StorefrontViewModel::class, 'price');
    $r29iOther = strtolower(StoreContext::current()->currency()) === 'pln' ? 'eur' : 'pln';
    $r29iShown = $r29iPrice->invoke(null, ['price' => 1290, 'compare_price' => 1590, 'currency' => $r29iOther]);
    $r29iSold = $r29iPrice->invoke(null, ['price' => 1290, 'currency' => StoreContext::current()->currency()]);
    assert_equals([['', '', false], true], [[$r29iShown['formatted'], $r29iShown['compare_formatted'], $r29iShown['sold_in_store']], $r29iSold['formatted'] !== '' && $r29iSold['sold_in_store']],
        'R29 storefront: a product the store does not sell shows no price in another currency (was: "12,90 €" in the PLN store); a sold one keeps its price');
    $r29iVm = (string)file_get_contents("{$r29iRoot}/src/StorefrontViewModel.php");
    assert_true(str_contains($r29iVm, "if (\$product['price']['sold_in_store']) {\n            \$jsonLd['offers']")
        && str_contains($r29iVm, "'price_formatted' => Pricing::soldInStore(\$currency) ?"),
        'R29 storefront: the product JSON-LD has no offer and a variant no price where the store does not sell it');
} catch (Throwable $e) {
    assert_true(false, 'R29 i18n admin (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    I18n::setLocale($r29iLocale);
}
