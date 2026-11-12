<?php

// Review round 34 (low, texts): the invoice printed the parties' country as a code although the CHANGELOG promised its
// name; an OSS invoice named the rate country of its e-books but not of its goods; order history notes stayed in English
// ("Payment confirmed via webhook", "All goods shipped", "Item refund released…") and two were written in Slovak; the
// payment history showed event codes ("admin.paid"); the customer saw the raw return status ("requested"); the variant
// table's availability and policy read as codes; an e-book edited to quantity 3 was saved as 1 with a plain "saved".
echo "\n--- Audit round 34: texts ---\n";

try {
    $r34tLocale = I18n::locale();
    $r34tCall = static function (string $class, string $method, mixed ...$args): mixed {
        $reflection = new ReflectionMethod($class, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke(null, ...$args);
    };

    // 1. The parties' country by name in the document's language.
    $r34tParty = ['name' => 'R34T Kunde', 'street' => 'Ring 1', 'zip' => '1010', 'city' => 'Wien', 'country' => 'AT'];
    $r34tLines = $r34tCall(InvoiceService::class, 'partyLines', $r34tParty, ['ico' => 'IČO', 'dic' => 'DIČ', 'ic_dph' => 'IČ DPH', 'ic_dph_unverified' => ''], 'en');
    assert_true(in_array('Austria', $r34tLines, true) && !in_array('AT', $r34tLines, true), 'R34 texts: an English invoice names the country "Austria", not "AT" (' . json_encode($r34tLines) . ')');
    $r34tSk = $r34tCall(InvoiceService::class, 'partyLines', $r34tParty, ['ico' => 'IČO', 'dic' => 'DIČ', 'ic_dph' => 'IČ DPH', 'ic_dph_unverified' => ''], 'sk');
    assert_true(in_array(ShippingService::countryNameIn('AT', 'sk'), $r34tSk, true) && !in_array('AT', $r34tSk, true), 'R34 texts: a Slovak invoice names the country as the addresses elsewhere do');

    // 2. An OSS invoice names the rate country on goods and shipping lines too (an untaxed line stays plain).
    $r34tOss = $r34tCall(InvoiceService::class, 'ossCountry', ['vat_scenario' => 'oss', 'financial_snapshot' => json_encode(['rate_country' => 'at'])]);
    assert_equals('AT', $r34tOss, 'R34 texts: the OSS invoice\'s rate country comes from its snapshot');
    assert_equals('', $r34tCall(InvoiceService::class, 'ossCountry', ['vat_scenario' => 'domestic', 'financial_snapshot' => json_encode(['rate_country' => 'sk'])]),
        'R34 texts: a domestic invoice names no rate country on its lines');
    assert_equals(['20% AT', '0%', '10% DE', '23%'], [
        $r34tCall(InvoiceService::class, 'rateLabel', 20.0, ['name' => 'Kniha'], 'AT'),
        $r34tCall(InvoiceService::class, 'rateLabel', 0.0, ['label' => 'payment_fee'], 'AT'),
        $r34tCall(InvoiceService::class, 'rateLabel', 10.0, ['country' => 'DE', 'supply' => 'oss'], 'AT'),
        $r34tCall(InvoiceService::class, 'rateLabel', 23.0, ['name' => 'Kniha']),
    ], 'R34 texts: goods on an OSS invoice read "20% AT"; a 0% fee, a line of its own country and a domestic line are unchanged');

    // 3. Order history notes in the admin's language; the two Slovak writers write English now.
    I18n::setLocale('en');
    $r34tNotes = [
        'All goods shipped' => 'All goods shipped', 'Payment confirmed via webhook' => 'Payment confirmed by the payment gateway',
        'Bezplatná digitálna objednávka' => 'Free digital order', 'Platba prevodom prijatá' => 'Bank transfer received',
        'Platba prevodom prijatá: VS 123' => 'Bank transfer received: VS 123', 'Stripe webhook: cs_test_1' => 'Payment confirmed through Stripe: cs_test_1',
        'Expired unpaid online checkout' => 'Unpaid online order expired', 'Stripe refund re_1 succeeded' => 'Stripe refund re_1 completed',
    ];
    assert_equals($r34tNotes, array_combine(array_keys($r34tNotes), array_map(static fn(string $note): string => AuditLogLabels::note('order_status', 0, $note), array_keys($r34tNotes))),
        'R34 texts: order history notes (and the old Slovak ones) read in English in an English admin');
    I18n::setLocale('sk');
    $r34tSlovak = array_map(static fn(string $note): string => AuditLogLabels::note('order_status', 0, $note),
        ['All goods shipped', 'Payment confirmed via webhook', 'Manual order without charge', 'Free digital order', 'Bank transfer received', 'Manual refund completed', 'Expired unpaid offline order']);
    assert_true(array_intersect($r34tSlovak, ['All goods shipped', 'Payment confirmed via webhook', 'Manual order without charge', 'Free digital order', 'Bank transfer received', 'Manual refund completed', 'Expired unpaid offline order']) === [],
        'R34 texts: the same notes read in Slovak in a Slovak admin (' . json_encode($r34tSlovak, JSON_UNESCAPED_UNICODE) . ')');
    assert_equals('Vrátenie peňazí za položky uvoľnil admin@r34: duplicitná platba', AuditLogLabels::note('order_refund', 0, 'Item refund released by admin@r34: duplicitná platba'),
        'R34 texts: a released item refund is noted in the admin\'s language with its actor and reason');
    $r34tSources = implode("\n", array_map(static fn(string $file): string => (string)file_get_contents(__DIR__ . '/../../../src/' . $file), ['Http/ApiHandlers.php', 'OfflinePaymentQueue.php']));
    assert_true(!str_contains($r34tSources, "'Bezplatná digitálna objednávka'") && !str_contains($r34tSources, "'Platba prevodom prijatá'"),
        'R34 texts: no order note is written in Slovak any more');

    // 4. The payment history reads events in words.
    assert_true(AuditLogLabels::note('payment_status', 0, 'admin.paid') !== 'admin.paid' && AuditLogLabels::note('payment_status', 0, 'refund.requested') !== 'refund.requested',
        'R34 texts: "admin.paid" and "refund.requested" read in words');
    assert_true(str_contains((string)file_get_contents(__DIR__ . '/../../../src/Entrypoints/Admin/Orders.php'), "AuditLogLabels::note('payment_status', \$id, (string)(\$raw['event_type'] ?? ''))"),
        'R34 texts: the admin payment history labels its event column');

    // 5. The customer's return status in words.
    assert_equals('Požiadané', OrderStatusLabels::rma('requested'), 'R34 texts: a requested return reads "Požiadané"');
    assert_true(str_contains((string)file_get_contents(__DIR__ . '/../../../themes/default/blocks/storefront/account-page.html'), '<span class="badge badge-{{status}}">{{status_label}}</span></td><td class="muted">{{date}}</td></tr>{{/each}}</table>{{else}}<p class="muted">{{t.account.no_returns}}'),
        'R34 texts: the account returns table shows the status label, the code stays the badge class');

    // 6. Variant availability and policy options in words, in every language.
    $r34tMissing = [];
    foreach (['sk', 'en', 'cs', 'de', 'es', 'hu', 'pl'] as $r34tLang) {
        foreach (VariantAvailability::statuses() as $status) if (I18n::tIn('admin.variant.availability.' . $status, $r34tLang) === 'admin.variant.availability.' . $status) $r34tMissing[] = "{$r34tLang}:{$status}";
        foreach (ProductVariants::POLICIES as $policy) if (I18n::tIn('admin.variant.policy.' . $policy, $r34tLang) === 'admin.variant.policy.' . $policy) $r34tMissing[] = "{$r34tLang}:{$policy}";
    }
    assert_equals([], $r34tMissing, 'R34 texts: every variant availability and policy has a label in every language');
    assert_true(str_contains((string)file_get_contents(__DIR__ . '/../../../src/Entrypoints/Admin/CatalogEditor.php'), 'esc(adminCodeLabel($prefix, $option))'),
        'R34 texts: the variant selects show the labels, the values stay the codes');

    // 7. An e-book asked for 3 times and saved once is reported, a physical line or a single e-book is not.
    $pdo->prepare("INSERT INTO orders (order_number, store_id, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, locale)
        VALUES ('R34T-1', 'default', 'pending', 'pending', 'R34T', 'r34t@example.test', 'bank_transfer', 3000, 3000, 'EUR', 'sk')")->execute();
    $r34tOrder = (int)$pdo->lastInsertId();
    $r34tProduct = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
    $r34tItem = $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total, digital) VALUES (?, ?, ?, ?, ?, 1000, ?, ?)');
    $r34tItem->execute([$r34tOrder, $r34tProduct, 'R34T-EBOOK', 'E-kniha', 1, 1000, 1]);
    $r34tItem->execute([$r34tOrder, $r34tProduct, 'R34T-BOOK', 'Kniha', 2, 2000, 0]);
    $r34tItem->execute([$r34tOrder, $r34tProduct, 'R34T-EBOOK2', 'E-kniha 2', 1, 1000, 1]);
    assert_equals('R34T-EBOOK', $r34tCall(ManualOrder::class, 'limitedDownloads', $r34tOrder, [['sku' => 'R34T-EBOOK', 'qty' => 3], ['sku' => 'R34T-BOOK', 'qty' => 2], ['sku' => 'R34T-EBOOK2', 'qty' => 1]]),
        'R34 texts: only the e-book edited to 3 and saved as 1 is reported');
    assert_true(str_contains(I18n::format('admin.order.edit.download_once', ['skus' => 'R34T-EBOOK']), 'R34T-EBOOK')
        && str_contains((string)file_get_contents(__DIR__ . '/../../../src/Entrypoints/Admin/ManualOrders.php'), "I18n::format('admin.order.edit.download_once', ['skus' => \$result['notice']])"),
        'R34 texts: the admin\'s "saved" message says the download quantity was limited to 1');
    $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$r34tOrder]);
    $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$r34tOrder]);
    I18n::setLocale($r34tLocale);
} catch (Throwable $e) {
    assert_true(false, 'R34 texts (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
