<?php

// Round 9 review (2026-10-23), law and complaints still open from round 8.
echo "\n--- Audit round 9 (law): no consumer surcharge for card or bank transfer ---\n";

Bootstrap::initStoreContext('/', '', 'default');

// 1. PSD2 art. 62(4) / Directive 2011/83/EU art. 19: a consumer is not charged for paying by card or SEPA transfer.
//    A fee configured on those methods applies to an approved business account only; cash on delivery keeps its fee.
$pdo->exec("INSERT INTO payment_methods (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order)
    VALUES ('r9lcard', 'stripe', 'R9L card', '', 'online_card', 0, 0, 50, 150, NULL, 1, 1, 1),
           ('r9lbank', 'manual', 'R9L bank', '', 'bank_transfer', 0, 0, 30, 0, NULL, 0, 1, 2),
           ('r9lcod', 'manual', 'R9L COD', '', 'cod', 0, 0, 100, 0, NULL, 0, 1, 3)");
$pdo->exec("INSERT INTO payment_method_countries (method_code, country) VALUES ('r9lcard', 'SK'), ('r9lbank', 'SK'), ('r9lcod', 'SK')");
$pdo->exec("INSERT INTO companies (store_id, code, legal_name, approval_status, active) VALUES ('default', 'r9l-co', 'R9L s.r.o.', 'approved', 1), ('default', 'r9l-pend', 'R9L pending s.r.o.', 'pending', 1)");
$r9lCompany = (int)$pdo->query("SELECT id FROM companies WHERE code = 'r9l-co'")->fetchColumn();
$r9lPending = (int)$pdo->query("SELECT id FROM companies WHERE code = 'r9l-pend'")->fetchColumn();
$pdo->prepare("INSERT INTO customers (store_id, email, password_hash, name, company_id, status) VALUES
    ('default', 'r9l-b2b@example.test', '!test!', 'R9L B2B', ?, 'active'),
    ('default', 'r9l-pend@example.test', '!test!', 'R9L Pending', ?, 'active'),
    ('default', 'r9l-consumer@example.test', '!test!', 'R9L Consumer', NULL, 'active')")->execute([$r9lCompany, $r9lPending]);
$r9lCustomer = static fn(string $email): int => (int)$pdo->query("SELECT id FROM customers WHERE email = " . $pdo->quote($email))->fetchColumn();
$r9lFees = static fn(?int $customerId): array => array_map('intval', array_column(array_filter(
    PaymentService::availableMethods('SK', 'eur', 10000, '', $customerId),
    static fn(array $m): bool => str_starts_with((string)$m['code'], 'r9l')), 'fee', 'code'));

assert_equals(['r9lcard' => 0, 'r9lbank' => 0, 'r9lcod' => 100], $r9lFees(null),
    'R9 PSD2: a guest pays no card or bank-transfer fee; cash on delivery keeps its fee');
assert_equals(['r9lcard' => 0, 'r9lbank' => 0, 'r9lcod' => 100], $r9lFees($r9lCustomer('r9l-consumer@example.test')),
    'R9 PSD2: a signed-in consumer pays no card or bank-transfer fee');
assert_equals(['r9lcard' => 0, 'r9lbank' => 0, 'r9lcod' => 100], $r9lFees($r9lCustomer('r9l-pend@example.test')),
    'R9 PSD2: a company account still awaiting approval is charged as a consumer');
assert_equals(['r9lcard' => 200, 'r9lbank' => 30, 'r9lcod' => 100], $r9lFees($r9lCustomer('r9l-b2b@example.test')),
    'R9 PSD2: an approved business account is charged the configured card (0,50 € + 1,5 %) and transfer fees');

$r9lOps = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Admin/Operations.php');
assert_true(str_contains($r9lOps, "PaymentService::NO_CONSUMER_SURCHARGE") && str_contains($r9lOps, "'admin.payments.saved_consumer_fee'"),
    'R9 PSD2: saving a fee on a card or transfer method tells the admin consumers are not charged it');
foreach (['sk', 'cs', 'en', 'de', 'pl', 'hu', 'es'] as $r9lLang) {
    $r9lStrings = require TEST_CLI_DIR . '/../lang/' . $r9lLang . '.php';
    assert_true(str_contains((string)($r9lStrings['admin.payments.saved_consumer_fee'] ?? ''), 'PSD2'),
        "R9 PSD2: the consumer-fee notice is translated ({$r9lLang})");
}

$pdo->exec("DELETE FROM payment_method_countries WHERE method_code LIKE 'r9l%'");
$pdo->exec("DELETE FROM payment_methods WHERE code LIKE 'r9l%'");
$pdo->exec("DELETE FROM customers WHERE email LIKE 'r9l-%'");
$pdo->exec("DELETE FROM companies WHERE code LIKE 'r9l-%'");

// 2. Germany (PAngV § 3, DDG § 5): every price says what it contains, and the seller has an Impressum. The note is
//    on the product page and in listings, outside the element the variant picker rewrites.
$r9lLocale = I18n::locale();
$r9lVatNote = new ReflectionMethod(StorefrontViewModel::class, 'vatNote');
$r9lNotes = [];
foreach (['default' => 'sk', 'de' => 'de', 'us' => 'en'] as $r9lStore => $r9lLang) {
    Bootstrap::initStoreContext('/', '', $r9lStore);
    I18n::setLocale($r9lLang);
    $r9lNotes[$r9lStore] = $r9lVatNote->invoke(null);
}
assert_equals(['default' => 'vrátane DPH, bez nákladov na dopravu', 'de' => 'inkl. MwSt., zzgl. Versandkosten', 'us' => ''], $r9lNotes,
    'R9 price note: the SK and DE stores state VAT included and shipping extra; the US store (no EU VAT) states nothing');
Bootstrap::initStoreContext('/', '', 'default');
$r9lTheme = static fn(string $block): string => (string)file_get_contents(TEST_CLI_DIR . '/../themes/default/blocks/storefront/' . $block . '.html');
$r9lProductPage = $r9lTheme('product-page');
assert_true(preg_match('#<div class="price" data-product-price>.*?</div>\s*\{\{\#if product\.price\.vat_note\}\}<p class="price-vat-note#s', $r9lProductPage) === 1,
    'R9 price note: the product page shows the note after (not inside) the price the variant picker rewrites');
foreach (['product-card', 'section-product-list'] as $r9lBlock) {
    assert_true(str_contains($r9lTheme($r9lBlock), '{{price.vat_note}}'), "R9 price note: the {$r9lBlock} listing shows the note");
}
$r9lImprint = ConsumerNotice::renderLegal('imprint', 'de', ['name' => 'Muster GmbH', 'street' => 'Hauptstr. 1', 'zip' => '10115', 'city' => 'Berlin',
    'country' => 'DE', 'ic_dph' => 'DE123456789', 'email' => 'info@muster.test', 'register_note' => 'Amtsgericht Berlin HRB 1']);
assert_true(str_contains($r9lImprint, '<h1>Impressum</h1>') && str_contains($r9lImprint, 'Umsatzsteuer-Identifikationsnummer gemäß § 27a UStG: DE123456789')
    && str_contains($r9lImprint, 'Registereintrag: Amtsgericht Berlin HRB 1') && str_contains($r9lImprint, 'Universalschlichtungsstelle'),
    'R9 Impressum: the DE page names the seller, its VAT ID, register entry and the consumer dispute body');
assert_true(!str_contains($r9lImprint, 'Vertreten durch') && !str_contains($r9lImprint, 'Steuernummer:') && !str_contains($r9lImprint, 'Telefon:') && !str_contains($r9lImprint, '{{'),
    'R9 Impressum: a line whose key the store does not set is left out entirely, label included');
foreach (['sk', 'cs', 'en', 'de', 'pl', 'hu', 'es'] as $r9lLang) {
    $r9lStrings = require TEST_CLI_DIR . '/../lang/' . $r9lLang . '.php';
    assert_true(is_file(TEST_CLI_DIR . "/../templates/legal/{$r9lLang}/imprint.html") && ($r9lStrings['imprint'] ?? '') !== ''
        && ($r9lStrings['price_incl_vat'] ?? '') !== '' && ($r9lStrings['price_no_vat'] ?? '') !== '',
        "R9 Impressum: the page, its title and the price notes exist in {$r9lLang}");
}
assert_true(in_array('imprint', ConsumerNotice::LEGAL_PAGES, true) && str_contains($r9lTheme('footer'), '<a href="{{imprint_url}}">{{t.imprint}}</a>')
    && str_contains((string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/Storefront/Layout.php'), "'imprint_url' => Template::storeUrl('/?page=imprint')"),
    'R9 Impressum: ?page=imprint is a legal page linked from every footer');

// 3. Cookies: the banner links the cookie policy (not the privacy policy), "reject all" looks exactly like "accept
//    all", and a checkout consent with a link prints its text once (the link), not the text and then the link again.
$r9lBanner = $r9lTheme('cookie-banner');
assert_true(str_contains($r9lBanner, '<a href="{{cookies_url}}" class="cc-link">') && !str_contains($r9lBanner, 'privacy_url'),
    'R9 cookies: the banner links the cookie policy page');
assert_true(preg_match('#class="([^"]+)" id="cc-reject"#', $r9lBanner, $r9lReject) === 1 && preg_match('#class="([^"]+)" id="cc-accept"#', $r9lBanner, $r9lAccept) === 1
    && $r9lReject[1] === $r9lAccept[1], 'R9 cookies: "reject all" and "accept all" have the same style');
$r9lConsent = Template::process('{{#each consents}}' . (preg_match('#\{\{\#each consents\}\}(.*?)\{\{/each\}\}#s', $r9lTheme('checkout'), $r9lM) ? $r9lM[1] : '') . '{{/each}}',
    ['consents' => [['key' => 'terms', 'text' => 'R9L terms consent', 'url' => '/terms', 'required' => true], ['key' => 'news', 'text' => 'R9L newsletter', 'url' => '', 'required' => false]]]);
assert_equals([1, 1], [substr_count($r9lConsent, 'R9L terms consent'), substr_count($r9lConsent, 'R9L newsletter')],
    'R9 consent: each checkout consent text is shown once, linked when it has a page');
I18n::setLocale($r9lLocale);

// 4. Digital content (Directive 2011/83/EU art. 16(m)): without the consumer's consent to lose the withdrawal right it
//    is supplied only once the 14 days have passed; a business buyer is never held. The withdrawal period runs from
//    delivery, from the order for digital-only orders, and ends at the latest 12 months after the original period.
$r9lCat = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status, product_type) VALUES
    ('default', ?, 'R9L-EBOOK', 'r9l-ebook', 'R9L E-book', 500, 0, 'active', 'downloadable'),
    ('default', ?, 'R9L-CHAIR', 'r9l-chair', 'R9L Chair', 2000, 5, 'active', 'simple')")->execute([$r9lCat, $r9lCat]);
$r9lProduct = static fn(string $sku): int => (int)$pdo->query("SELECT id FROM products WHERE sku = " . $pdo->quote($sku))->fetchColumn();
$pdo->exec("INSERT INTO companies (store_id, code, legal_name, approval_status, active) VALUES ('default', 'r9l-dig-co', 'R9L Digital s.r.o.', 'approved', 1)");
$pdo->prepare("INSERT INTO customers (store_id, email, password_hash, name, company_id, status) VALUES ('default', 'r9l-dig-b2b@example.test', '!test!', 'R9L Dig B2B', ?, 'active')")
    ->execute([(int)$pdo->query("SELECT id FROM companies WHERE code = 'r9l-dig-co'")->fetchColumn()]);
$r9lB2b = (int)$pdo->query("SELECT id FROM customers WHERE email = 'r9l-dig-b2b@example.test'")->fetchColumn();
$r9lNow = time();
$r9lOrder = static function (string $number, array $skus, int $createdAgo, ?int $deliveredAgo = null, ?int $customerId = null, bool $consent = false) use ($pdo, $r9lProduct, $r9lNow): array {
    $pdo->prepare("INSERT INTO orders (order_number, status, payment_status, customer_id, customer_name, customer_email, payment_method, subtotal, total, currency, store_id, locale, created_at, delivered_at)
        VALUES (?, 'paid', 'paid', ?, 'R9L Buyer', 'r9l-buyer@example.test', 'card', 500, 500, 'eur', 'default', 'sk', ?, ?)")
        ->execute([$number, $customerId, gmdate('Y-m-d H:i:s', $r9lNow - $createdAgo), $deliveredAgo === null ? null : gmdate('Y-m-d H:i:s', $r9lNow - $deliveredAgo)]);
    $id = (int)$pdo->lastInsertId();
    foreach ($skus as $sku) {
        $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, 500, 500)')->execute([$id, $r9lProduct($sku), $sku, $sku]);
    }
    if ($consent) $pdo->prepare("INSERT INTO order_consents (order_id, consent_key, version, consent_text, user_agent) VALUES (?, ?, 'r9l', 'r9l', '')")->execute([$id, ConsentService::DIGITAL_KEY]);
    return Orders::byId($id);
};
$r9lDay = 86400;
$r9lHeld = $r9lOrder('R9L-HOLD', ['R9L-EBOOK'], 3600);
$r9lHoldUntil = DigitalDelivery::holdUntil($r9lHeld, $r9lNow);
assert_true($r9lHoldUntil !== null && abs($r9lHoldUntil - ($r9lNow - 3600 + 14 * $r9lDay)) <= 120,
    'R9 digital: a consumer order without the delivery consent is held until 14 days after the order');
assert_equals([null, null, null], [
    DigitalDelivery::holdUntil($r9lOrder('R9L-CONS', ['R9L-EBOOK'], 3600, null, null, true), $r9lNow),
    DigitalDelivery::holdUntil($r9lOrder('R9L-B2B', ['R9L-EBOOK'], 3600, null, $r9lB2b), $r9lNow),
    DigitalDelivery::holdUntil($r9lOrder('R9L-PAST', ['R9L-EBOOK'], 15 * $r9lDay), $r9lNow),
], 'R9 digital: supplied at once with the consent, to a business buyer, or once the period has passed');
assert_true(!DigitalDelivery::withdrawn((int)$r9lHeld['id']), 'R9 digital: an order without a withdrawal statement is not withdrawn');
$r9lWorker = (string)file_get_contents(TEST_CLI_DIR . '/worker.php');
assert_true(preg_match('/function handleDigitalFulfill\(.*?DigitalDelivery::holdUntil\(\$order\).*?JobQueue::pushDelayed\(\'digital_fulfill\'.*?\'held\' => true.*?return;.*?DigitalDelivery::withdrawn\(\$orderId\)\)\) return;.*?DigitalDelivery::claimFulfilment/s', $r9lWorker) === 1,
    'R9 digital: the worker re-queues a held order for the end of the period and then skips it if withdrawn or no longer paid');

$r9lPeriod = static fn(array $order): bool => Withdrawal::withinPeriod($order, $r9lNow);
assert_equals(
    ['digital 10 d' => true, 'digital 15 d' => false, 'delivered 10 d' => true, 'delivered 15 d' => false, 'undelivered 100 d' => true, 'undelivered 13 months' => false, 'mixed undelivered 20 d' => true],
    ['digital 10 d' => $r9lPeriod($r9lOrder('R9L-W1', ['R9L-EBOOK'], 10 * $r9lDay)),
     'digital 15 d' => $r9lPeriod($r9lOrder('R9L-W2', ['R9L-EBOOK'], 15 * $r9lDay)),
     'delivered 10 d' => $r9lPeriod($r9lOrder('R9L-W3', ['R9L-CHAIR'], 30 * $r9lDay, 10 * $r9lDay)),
     'delivered 15 d' => $r9lPeriod($r9lOrder('R9L-W4', ['R9L-CHAIR'], 30 * $r9lDay, 15 * $r9lDay)),
     'undelivered 100 d' => $r9lPeriod($r9lOrder('R9L-W5', ['R9L-CHAIR'], 100 * $r9lDay)),
     'undelivered 13 months' => $r9lPeriod($r9lOrder('R9L-W6', ['R9L-CHAIR'], 396 * $r9lDay)),
     'mixed undelivered 20 d' => $r9lPeriod($r9lOrder('R9L-W7', ['R9L-CHAIR', 'R9L-EBOOK'], 20 * $r9lDay))],
    'R9 withdrawal period: from delivery, from the order for digital-only orders, never longer than 12 months past the original period');
foreach (['cs', 'de', 'hu', 'pl'] as $r9lLang) {
    $r9lDir = TEST_CLI_DIR . "/../templates/emails/{$r9lLang}/digital_delivery";
    assert_true(is_file($r9lDir . '.html') && is_file($r9lDir . '.txt') && is_file($r9lDir . '.subject.txt')
        && str_contains((string)file_get_contents($r9lDir . '.html'), '{{#each download_links}}'),
        "R9 digital: the download e-mail exists in {$r9lLang}");
}
$pdo->exec("DELETE FROM order_consents WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE 'R9L-%')");
$pdo->exec("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE 'R9L-%')");
$pdo->exec("DELETE FROM orders WHERE order_number LIKE 'R9L-%'");
$pdo->exec("DELETE FROM products WHERE sku LIKE 'R9L-%'");
$pdo->exec("DELETE FROM customers WHERE email LIKE 'r9l-%'");
$pdo->exec("DELETE FROM companies WHERE code LIKE 'r9l-%'");
