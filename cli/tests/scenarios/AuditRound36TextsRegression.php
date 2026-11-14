<?php

// Review round 36 (2026-11-13), low "texts" and "tests" (goal #461): the reversal of a released item refund's credit
// note read "Item refund released by admin: …" in English, the receipt of a return entered in the admin "Admin receipt";
// the order history row of a released refund paid out late read "Payment failed → Payment failed"; the new R34/R35
// regressions failed when run on their own (--only), the tables of the other scenarios missing.
echo "\n--- Audit round 36: texts and standalone tests ---\n";

try {
    $r36tLocale = I18n::locale();
    $r36tReversal = 'Credit note reversed: Item refund released by admin@r36: nothing at Stripe';
    $r36tRead = [];
    foreach (['en', 'sk', 'cs', 'de', 'es', 'hu', 'pl'] as $r36tLang) {
        I18n::setLocale($r36tLang);
        $r36tRead[$r36tLang] = [AuditLogLabels::note('credit_note', 0, $r36tReversal), AuditLogLabels::note('rma_receipt', 0, 'Admin receipt')];
    }
    I18n::setLocale($r36tLocale);
    $r36tEnglish = [];
    foreach ($r36tRead as $r36tLang => [$reversal, $receipt]) {
        $ok = str_contains($reversal, 'admin@r36') && str_contains($reversal, 'nothing at Stripe') && $receipt !== 'Admin receipt'
            && ($r36tLang === 'en' || (!str_contains($reversal, 'Credit note reversed') && !str_contains($reversal, 'Item refund released by')));
        if (!$ok) $r36tEnglish[] = "{$r36tLang}: {$reversal} | {$receipt}";
    }
    assert_equals([], $r36tEnglish, 'R36 texts: the reversal of a released refund and "Admin receipt" read in every admin language, with the actor and the note');
    assert_true(str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Orders.php'), "'admin-receive-' . \$id, 'Admin receipt')"),
        'R36 texts: the note the label reads is the note the admin receipt writes');

    // The released refund paid out late: a note without a status pair (the booking logs the payment's own change).
    $r36tSource = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/PaymentService.php');
    assert_true(str_contains($r36tSource, "AuditLog::log('order_refund', \$orderId, null, null, \"{\$provider} refund {\$refundId} of a released item refund was executed\")")
        && !str_contains($r36tSource, "'failed', 'failed', \"{\$provider} refund {\$refundId} of a released item refund"),
        'R36 texts: the late payout of a released refund no longer reads "Payment failed → Payment failed"');

    // --only=FoundationScenario.php,X.php brings the schema to every migration, so one regression runs on its own.
    $r36tSuite = (string)file_get_contents(TEST_CLI_DIR . '/TestSuite.php');
    assert_true(str_contains($r36tSuite, "\$scenarioFile === 'FoundationScenario.php'") && str_contains($r36tSuite, "test_fixture_path('only-migrate')"),
        'R36 tests: a regression run with --only after FoundationScenario.php gets the full migrated schema');
} catch (Throwable $e) {
    assert_true(false, 'R36 texts (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
