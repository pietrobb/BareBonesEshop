<?php

// Review round 34 (low, texts): the order history notes of a release that found its refund at the provider (R34) stayed
// in English; the order detail printed the last payment event raw ("Last event: partially_refunded"); the Spanish
// status history header read "To" (es.php lacked 8 keys); AuditRound34VatLowRegression failed when run on its own.
echo "\n--- Audit round 35: texts ---\n";

try {
    $r35tLocale = I18n::locale();

    // 1. The release notes of R34 in the admin's language, with provider, refund id and actor.
    $r35tNotes = [
        'Item refund release by admin@r35: Stripe refund re_r35a was executed, booked instead',
        'Item refund release by admin@r35: Stripe refund re_r35b is still processed',
        'Stripe refund re_r35c of a released item refund was executed',
    ];
    I18n::setLocale('en');
    assert_equals([
        'Item refund release by admin@r35: Stripe refund re_r35a had been executed, booked to the credit note instead',
        'Item refund release by admin@r35: Stripe refund re_r35b is still being processed',
        'Stripe refund re_r35c of a released item refund was executed',
    ], array_map(static fn(string $note): string => AuditLogLabels::note('order_refund', 0, $note), $r35tNotes), 'R35 texts: the release notes read in English with their refund id');
    foreach (['sk', 'cs', 'de', 'es', 'hu', 'pl'] as $r35tLang) {
        I18n::setLocale($r35tLang);
        $r35tRead = array_map(static fn(string $note): string => AuditLogLabels::note('order_refund', 0, $note), $r35tNotes);
        $r35tOk = array_intersect($r35tRead, $r35tNotes) === [] && !array_filter($r35tRead, static fn(string $text): bool => str_contains($text, 'Item refund released by'));
        foreach (['re_r35a', 're_r35b', 're_r35c'] as $i => $r35tId) $r35tOk = $r35tOk && str_contains($r35tRead[$i], $r35tId) && str_contains($r35tRead[$i], 'Stripe');
        $r35tOk = $r35tOk && str_contains($r35tRead[0], 'admin@r35') && str_contains($r35tRead[1], 'admin@r35');
        assert_true($r35tOk, "R35 texts: the release notes read in {$r35tLang} with provider, refund id and actor (" . json_encode($r35tRead, JSON_UNESCAPED_UNICODE) . ')');
    }
    $r35tSource = (string)file_get_contents(__DIR__ . '/../../../src/PaymentService.php');
    assert_true(str_contains($r35tSource, '{$provider} refund {$providerRefundId} was executed, booked instead")') && str_contains($r35tSource, '{$provider} refund {$providerRefundId} is still processed")')
        && str_contains($r35tSource, '"{$provider} refund {$refundId} of a released item refund was executed"'), 'R35 texts: the notes the labels read are the notes PaymentService writes');

    // 2. The last payment event in words: the transaction's own events and the payment statuses it also records.
    $r35tMissing = [];
    foreach (['sk', 'en', 'cs', 'de', 'es', 'hu', 'pl'] as $r35tLang) {
        I18n::setLocale($r35tLang);
        foreach (['initiated', 'refund_attempted', 'refund_succeeded', 'refund_failed', 'refund_canceled', 'refund_confirmed', 'refund_pending', 'refund_manual_required', 'paid', 'partially_refunded', 'refunded'] as $r35tStatus) {
            $r35tLabel = OrderStatusLabels::transaction($r35tStatus);
            if ($r35tLabel === $r35tStatus || str_contains($r35tLabel, '_') || str_starts_with($r35tLabel, 'admin.')) $r35tMissing[] = "{$r35tLang}:{$r35tStatus}={$r35tLabel}";
        }
    }
    assert_equals([], $r35tMissing, 'R35 texts: every payment event the order detail can show reads in words in every language');
    I18n::setLocale('sk');
    assert_equals('Čiastočne vrátené', OrderStatusLabels::transaction('partially_refunded'), 'R35 texts: "partially_refunded" reads as the payment status label');
    assert_true(str_contains((string)file_get_contents(__DIR__ . '/../../../src/Entrypoints/Admin/Orders.php'), "esc(OrderStatusLabels::transaction((string)\$latestPaymentTx['status']))"),
        'R35 texts: the order detail labels its last payment event');

    // 3. Spanish defines the keys it fell back to English for; the status history header is Spanish.
    assert_equals('A', I18n::tIn('admin.order.to', 'es'), 'R35 texts: the Spanish status history header is "A", not "To"');
    $r35tEn = require __DIR__ . '/../../../lang/en.php';
    $r35tEsSource = (string)file_get_contents(__DIR__ . '/../../../lang/es.php');
    $r35tEsMissing = array_values(array_filter(array_keys($r35tEn), static fn(string $key): bool => !str_contains($r35tEsSource, "'" . $key . "'")));
    assert_equals([], $r35tEsMissing, 'R35 texts: es.php defines every key of en.php');

    // 4. AuditRound34VatLowRegression applies the migrations it needs (it failed on its own: no table digital_assets).
    assert_true(str_contains((string)file_get_contents(__DIR__ . '/AuditRound34VatLowRegression.php'), "!Db::tableExists('digital_assets') || !Db::tableExists('invoices')"),
        'R35 texts: the R34 VAT lows test sets up the tables it uses');
    I18n::setLocale($r35tLocale);
} catch (Throwable $e) {
    assert_true(false, 'R35 texts (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
