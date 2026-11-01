<?php

// R8 EU consumer law (texts and languages). Texts here are a technical baseline and must be reviewed by a lawyer.
// 1) Every store names the consumer ADR body of its seller's country on every page, and publishes an accessibility
//    statement (European Accessibility Act) in every shipped locale.
// 4) A guest changes or withdraws the cookie choice from the footer, without an account and without JavaScript.
echo "\n--- Audit round 8: EU consumer law texts (ADR, accessibility, cookie settings) ---\n";

$r8tRoot = test_fixture_path('r8-consumer-texts');
mkdir($r8tRoot);
mkdir($r8tRoot . '/sessions');
$r8tFixture = $r8tRoot . '/request.php';
file_put_contents($r8tFixture, <<<'PHP'
<?php
[, $phase, $root, $target, $dir, $extra] = $argv;
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate.lock'))->apply();
    $db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, NULL, ?, 0)')->execute(['default', 'r8tcat', 'R8T Cat', 'r8tcat']);
    $cat = (int)$db->query("SELECT id FROM categories WHERE slug = 'r8tcat'")->fetchColumn();
    $product = $db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, compare_price, stock, status) VALUES ('default', ?, ?, ?, ?, 1500, 2500, 5, 'active')");
    // Omnibus: R8T-NOHIST has a compare price but no recorded price; R8T-HIST was 2000 for 40 days, reduced to 1500 5 days ago.
    $product->execute([$cat, 'R8T-NOHIST', 'r8t-nohist', 'R8T No history']);
    $product->execute([$cat, 'R8T-HIST', 'r8t-hist', 'R8T History']);
    $hist = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO product_price_history (product_id, price, lowest_prior, changed_at) VALUES (?, 2000, NULL, ?), (?, 1500, 2000, ?)')
        ->execute([$hist, gmdate('Y-m-d H:i:s', time() - 45 * 86400), $hist, gmdate('Y-m-d H:i:s', time() - 5 * 86400)]);
    // Reviews of R8T-HIST: a buyer with a paid order, a customer who never bought it, a guest.
    $customer = $db->prepare("INSERT INTO customers (email, password_hash, name) VALUES (?, 'x', ?)");
    $customer->execute(['r8t-buyer@example.test', 'R8T Buyer']);
    $buyer = (int)$db->lastInsertId();
    $customer->execute(['r8t-other@example.test', 'R8T Other']);
    $other = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO orders (order_number, status, payment_status, customer_id, customer_name, customer_email, payment_method, subtotal, total, currency, store_id, locale)
        VALUES ('R8T-ORD', 'processing', 'paid', ?, 'R8T Buyer', 'r8t-buyer@example.test', 'card', 1500, 1500, 'eur', 'default', 'sk')")->execute([$buyer]);
    $db->prepare('INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, ?, ?, ?, 1, 1500, 1500)')
        ->execute([(int)$db->lastInsertId(), $hist, 'R8T-HIST', 'R8T History']);
    $review = $db->prepare("INSERT INTO product_reviews (product_id, customer_id, author_name, rating, title, body, status) VALUES (?, ?, ?, 5, '', 'Body text', 'approved')");
    foreach ([[$buyer, 'R8T-BUYER'], [$other, 'R8T-OTHER'], [null, 'R8T-GUEST']] as [$cid, $author]) $review->execute([$hist, $cid, $author]);
    exit(0);
}
$config = is_file($root . '/config.php') ? require $root . '/config.php' : require $root . '/config.example.php';
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate.sqlite'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.8.22';
$_SERVER['HTTP_HOST'] = 'shop.example';
$input = json_decode($extra, true) ?: [];
if ($phase === 'methods') {
    // Customer-facing method names per locale; a method the merchant renamed keeps the merchant's name.
    StoreContext::activate(StoreContext::resolve(json_decode((string)file_get_contents(DataOverrides::storesPath($root)), true), '/', '', 'default'));
    // The card method is offered only once Stripe has its keys (R10 UX2).
    (new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['stripe_secret_key' => 'sk_test_' . str_repeat('r8t', 8), 'stripe_webhook_secret' => 'whsec_' . str_repeat('r8t', 8)] + Security::config());
    if (!empty($input['rename'])) $db->exec("UPDATE payment_methods SET name = 'Merchant COD' WHERE code = 'cod'");
    $result = [];
    foreach (['sk', 'cs', 'en', 'de', 'pl', 'hu', 'es'] as $locale) {
        I18n::setLocale($locale);
        $result[$locale] = [
            'payment' => array_column(PaymentService::availableMethods('SK', 'eur', 2000), 'label', 'code'),
            'payment_desc' => array_column(PaymentService::availableMethods('SK', 'eur', 2000), 'description', 'code'),
            'shipping' => array_column(ShippingService::calculateRates('SK', 500, 2000), 'label', 'method_code'),
        ];
    }
    echo "\nR8TRESULT" . json_encode($result, JSON_UNESCAPED_UNICODE);
    exit(0);
}
$_GET = $input['get'] ?? [];
$_COOKIE = $input['cookie'] ?? [];
$_SERVER['REQUEST_URI'] = $input['uri'] ?? '/index.php?' . http_build_query($_GET);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = [];
http_response_code(200);
ob_start();
register_shutdown_function(static function(): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nR8TRESULT" . json_encode(['code' => http_response_code(), 'output' => $out], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
});
require $root . '/index.php';
PHP);
$r8tRun = static function(array $args) use ($r8tFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r8tFixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'R8TRESULT');
    $result = $at === false ? null : json_decode(strtok(substr($raw, $at + 9), "\n"), true);
    return [$exit, is_array($result) ? $result : null, $at === false ? substr($raw, 0, 800) : substr($raw, 0, min($at, 800))];
};
$r8tApp = dirname(TEST_CLI_DIR);
$r8tDb = $r8tRoot . '/shop.sqlite';
$r8tPage = static function(array $get, array $cookie = [], ?string $uri = null) use ($r8tRun, $r8tApp, $r8tDb, $r8tRoot): array {
    [, $result, $noise] = $r8tRun(['page', $r8tApp, $r8tDb, $r8tRoot, json_encode(['get' => $get, 'cookie' => $cookie, 'uri' => $uri])]);
    return [(int)($result['code'] ?? 0), (string)($result['output'] ?? $noise)];
};

[$r8tExit, , $r8tNoise] = $r8tRun(['setup', $r8tApp, $r8tDb, $r8tRoot, '{}']);
assert_equals([0, ''], [$r8tExit, trim($r8tNoise)], 'R8 texts: migrated fixture');

// 1) ADR body of each store's seller country (stores.json); a store without a known body names none.
$r8tStores = json_decode((string)file_get_contents($r8tApp . '/stores.example.json'), true)['stores'];
$r8tAdr = [];
foreach ($r8tStores as $r8tId => $r8tStore) $r8tAdr[$r8tId] = ConsumerNotice::adr((array)($r8tStore['legal'] ?? []))['name'];
assert_equals(['default' => 'Slovenská obchodná inšpekcia', 'cz' => 'Česká obchodní inspekce', 'pl' => 'Inspekcja Handlowa', 'hu' => 'Budapesti Békéltető Testület', 'de' => 'Universalschlichtungsstelle des Bundes', 'us' => ''],
    $r8tAdr, 'R8 ADR: each store names the consumer ADR body of its seller country');

[$r8tCode, $r8tHome] = $r8tPage([], ['cc_v1' => '{"necessary":true,"analytics":false,"marketing":false}']);
assert_true($r8tCode === 200 && preg_match('~class="footer-adr">[^<]*<a href="https://www\.soi\.sk"[^>]*>Slovenská obchodná inšpekcia</a>~u', $r8tHome) === 1,
    'R8 ADR: every storefront page names the ADR body with its link in the footer (' . $r8tCode . ')');
assert_true(str_contains($r8tHome, '?page=accessibility') && str_contains($r8tHome, 'cookie_settings=1'),
    'R8 footer: links the accessibility statement and the cookie settings');
assert_true(!str_contains($r8tHome, 'id="cc-banner"'), 'R8 cookies: a visitor who chose is not shown the banner on a normal page');

[$r8tCode, $r8tA11y] = $r8tPage(['page' => 'accessibility']);
assert_true($r8tCode === 200 && str_contains($r8tA11y, '<h1>Vyhlásenie o prístupnosti</h1>') && str_contains($r8tA11y, 'WCAG 2.1') && str_contains($r8tA11y, 'Woodle s.r.o.')
    && str_contains($r8tA11y, 'info@example.sk, +421 2 1234 5678') && !str_contains($r8tA11y, '{{'),
    'R8 accessibility: the statement page renders in the store locale with the seller and the feedback contact (' . $r8tCode . ')');
foreach (['sk', 'cs', 'en', 'de', 'pl', 'hu', 'es'] as $r8tLocale) {
    $r8tHtml = ConsumerNotice::renderLegal('accessibility', $r8tLocale, ['name' => 'R8T s.r.o.', 'email' => 'a11y@example.test', 'accessibility_authority' => 'R8T Authority']);
    assert_true(str_contains($r8tHtml, '<html') === false && preg_match_all('~<h2>~', $r8tHtml) === 5 && str_contains($r8tHtml, 'a11y@example.test')
        && str_contains($r8tHtml, ': R8T Authority') && str_contains($r8tHtml, '2019/882') && !str_contains($r8tHtml, '{{')
        && is_file($r8tApp . '/templates/legal/' . $r8tLocale . '/accessibility.html'),
        "R8 accessibility ({$r8tLocale}): own statement with standard, status, service, feedback and enforcement");
}

// 2) Omnibus: a struck-through price only with a recorded price history, capped at the lowest price of the 30 days
//    before the reduction and explained; a compare price without history is not shown.
$r8tCapped = PriceHistory::capCompare(['price' => 1500, 'compare_price' => 2500], 1500, null);
assert_true($r8tCapped['compare_price'] === null, 'R8 Omnibus: a product without price history shows no compare price');
[$r8tCode, $r8tNoHist] = $r8tPage(['page' => 'product', 'slug' => 'r8t-nohist']);
[, $r8tHist] = $r8tPage(['page' => 'product', 'slug' => 'r8t-hist']);
[, $r8tList] = $r8tPage(['page' => 'category', 'slug' => 'r8tcat']);
assert_true($r8tCode === 200 && str_contains($r8tNoHist, 'R8T No history') && !str_contains($r8tNoHist, 'class="old-price"'),
    'R8 Omnibus: the product page of a product without history shows no struck-through price (' . $r8tCode . ')');
assert_true(preg_match('~class="old-price">20,00\s*€</span> <small class="price-note">Prečiarknutá cena je najnižšia cena za 30 dní pred zľavou\.</small>~u', $r8tHist) === 1,
    'R8 Omnibus: with history the struck-through price is the 30-day lowest (20,00 €, not 25,00 €) and says so');
assert_equals(1, substr_count($r8tList, 'class="old-price"'), 'R8 Omnibus: the category listing strikes through only the product with history');

// 3) Reviews say whether they are verified purchases and how the shop checks it.
$r8tBadge = static function(string $html, string $author): string {
    return preg_match('~<strong>' . $author . '</strong> <span>5 / 5</span> <span class="review-verification( is-verified)?">([^<]*)</span>~u', $html, $m) === 1 ? trim($m[1] . ' ' . $m[2]) : '';
};
assert_equals(['is-verified Overený nákup', 'Neoverený nákup', 'Neoverený nákup'],
    [$r8tBadge($r8tHist, 'R8T-BUYER'), $r8tBadge($r8tHist, 'R8T-OTHER'), $r8tBadge($r8tHist, 'R8T-GUEST')],
    'R8 reviews: only the review of a customer who paid for the product is a verified purchase');
assert_true(str_contains($r8tHist, '<p class="review-policy">Hodnotenia zverejňujeme po kontrole.'), 'R8 reviews: the product page states how reviews are checked');

// 5) Shipping and payment names (and payment descriptions) in the shopper's language, not one Slovak name everywhere.
[, $r8tMethods, $r8tNoise] = $r8tRun(['methods', $r8tApp, $r8tDb, $r8tRoot, '{}']);
assert_equals(['sk' => 'Dobierka', 'cs' => 'Dobírka', 'en' => 'Cash on delivery', 'de' => 'Nachnahme', 'pl' => 'Za pobraniem', 'hu' => 'Utánvét', 'es' => 'Contra reembolso'],
    array_map(static fn(array $l): string => (string)($l['payment']['cod'] ?? ''), (array)$r8tMethods), 'R8 methods: the COD payment is named in each locale (' . $r8tNoise . ')');
assert_equals(['Kartou online', 'Kartenzahlung online', 'Online bankkártya'], [$r8tMethods['sk']['payment']['card'] ?? '', $r8tMethods['de']['payment']['card'] ?? '', $r8tMethods['hu']['payment']['card'] ?? ''],
    'R8 methods: the card payment is named in each locale');
assert_true(($r8tMethods['de']['payment_desc']['bank_transfer'] ?? '') === 'Überweisung auf unser Konto nach der Bestellung.' && ($r8tMethods['sk']['payment_desc']['card'] ?? '') === 'Platba kartou online cez platobnú bránu.',
    'R8 methods: payment descriptions are in the locale, not in English');
$r8tShipping = array_map(static fn(array $l): string => (string)($l['shipping']['pickup'] ?? ''), (array)$r8tMethods);
assert_true(($r8tShipping['de'] ?? '') === 'Selbstabholung' && ($r8tShipping['en'] ?? '') === 'In-store pickup' && ($r8tShipping['sk'] ?? '') === 'Osobný odber',
    'R8 methods: shipping methods are named in each locale (' . json_encode($r8tShipping, JSON_UNESCAPED_UNICODE) . ')');
[, $r8tRenamed] = $r8tRun(['methods', $r8tApp, $r8tDb, $r8tRoot, '{"rename":true}']);
assert_equals(['Merchant COD', 'Merchant COD'], [$r8tRenamed['de']['payment']['cod'] ?? '', $r8tRenamed['sk']['payment']['cod'] ?? ''],
    'R8 methods: a method name the merchant changed is shown as entered');

// 4) The guest reopens the banner from the footer: current choice ticked, "save" offered without JS, returns home.
[$r8tCode, $r8tSettings] = $r8tPage(['cookie_settings' => '1'], ['cc_v1' => '{"necessary":true,"analytics":true,"marketing":false}'], '/index.php?cookie_settings=1');
assert_true($r8tCode === 200 && preg_match('~<form id="cc-banner" class="show-prefs"~', $r8tSettings) === 1
    && preg_match('~id="cc-ana"[^>]*\bchecked~', $r8tSettings) === 1 && preg_match('~id="cc-mkt"[^>]*\bchecked~', $r8tSettings) === 0
    && preg_match('~id="cc-save"[^>]*value="preferences"\s*>~', $r8tSettings) === 1
    && preg_match('~name="return_to" value="[^"?]*/"~', $r8tSettings) === 1,
    'R8 cookies: the settings link reopens the banner for a guest with the current choice and a no-JS save that returns home');
foreach (['sk', 'cs', 'en', 'de', 'pl', 'hu', 'es'] as $r8tLocale) {
    $r8tLabel = (string)((require $r8tApp . '/lang/' . $r8tLocale . '.php')['cookie_settings'] ?? '');
    $r8tPolicy = (string)file_get_contents($r8tApp . '/templates/legal/' . $r8tLocale . '/cookies.html');
    assert_true($r8tLabel !== '' && str_contains($r8tPolicy, $r8tLabel) && !str_contains($r8tPolicy, 'zákazníckom účte, ak je dostupné') && !str_contains($r8tPolicy, 'customer account where available'),
        "R8 cookies ({$r8tLocale}): the cookie policy points to the footer settings link, not only to an account");
}
// R10 docs: Spanish has its own cookie and privacy texts (not the English fallback), flagged for legal review, in usted.
foreach (['cookies', 'privacy'] as $r10dPage) {
    $r10dFile = $r8tApp . '/templates/legal/es/' . $r10dPage . '.html';
    $r10dSource = is_file($r10dFile) ? (string)file_get_contents($r10dFile) : '';
    $r10dHtml = ConsumerNotice::renderLegal($r10dPage, 'es', ['name' => 'R10D S.L.', 'email' => 'privacidad@example.test']);
    assert_true($r10dSource !== '' && str_contains($r10dSource, 'asesor jurídico') && preg_match('~<!-- legal-updated: \d{4}-\d{2}-\d{2} -->~', $r10dSource) === 1
        && str_contains($r10dHtml, 'privacidad@example.test') && !str_contains($r10dHtml, 'Last updated') && !str_contains($r10dHtml, '{{')
        && preg_match('~\b(tu|tus|te|puedes|tienes|has|quieres)\b~iu', strip_tags($r10dSource)) === 0,
        "R10 legal (es): own Spanish {$r10dPage} text, marked for review by legal counsel, addressed as usted");
}

// 6) Invoice and payment wording: correct tax terms per language, and no "variable symbol" outside SK/CZ.
$r8tLabels = (new ReflectionMethod('InvoiceService', 'loadInvoiceLabels'));
$r8tInv = fn(string $l): array => $r8tLabels->invoke(null, $l);
assert_true(!str_contains($r8tInv('de')['credit_title'], 'Gutschrift') && str_contains($r8tInv('de')['credit_title'], 'Rechnungskorrektur'),
    'R8 invoices (de): a corrective document is a Rechnungskorrektur, not a Gutschrift (self-billing)');
assert_true(!str_contains($r8tInv('pl')['non_vat_payer_note'], 'płatnik') && str_contains($r8tInv('pl')['non_vat_payer_note'], 'podatnikiem VAT'),
    'R8 invoices (pl): a seller outside VAT is "nie jest czynnym podatnikiem VAT", not a płatnik');
assert_true($r8tInv('cs')['ic_dph'] !== 'DIČ DPH', 'R8 invoices (cs): no "DIČ DPH" label (a Czech VAT ID is a DIČ)');
foreach (['en', 'de', 'pl', 'hu', 'es'] as $r8tLocale) {
    $r8tLang = require $r8tApp . '/lang/' . $r8tLocale . '.php';
    $r8tWords = implode(' | ', [$r8tLang['bank_transfer.intro'], $r8tLang['bank_transfer.variable_symbol'], $r8tLang['admin.order.variable_symbol'],
        $r8tLang['admin.recovery.variable_symbol'], $r8tInv($r8tLocale)['variable_symbol']]);
    assert_true(preg_match('~variable symbol|variables symbol|variabelsymbol|zahlungssymbol|symbol zmienny|symbol var|változó szimbólum|símbolo variable~iu', $r8tWords) === 0,
        "R8 payment wording ({$r8tLocale}): a payment reference, not a Czech/Slovak variable symbol");
}

// 7) Language register: one form of address per language and returns are not called complaints.
$r8tRma = '~^(admin\.rma\.|admin\.nav\.rma$|admin\.title\.rma$|admin\.customers\.(returns|rma_queue)$|account\.returns$)~';
foreach (['sk', 'cs', 'pl', 'hu', 'de'] as $r8tLocale) {
    $r8tLang = require $r8tApp . '/lang/' . $r8tLocale . '.php';
    $r8tBad = array_keys(array_filter($r8tLang, static fn($v, $k): bool => preg_match($r8tRma, (string)$k) === 1 && preg_match('~reklam~iu', (string)$v) === 1, ARRAY_FILTER_USE_BOTH));
    assert_equals([], $r8tBad, "R8 language ({$r8tLocale}): return (RMA) screens are not labelled as complaints");
}
$r8tTexts = static function (string $locale) use ($r8tApp): array {
    $texts = array_map('strval', array_values(require $r8tApp . '/lang/' . $locale . '.php'));
    foreach (glob($r8tApp . '/templates/emails/' . $locale . '/*') ?: [] as $file) $texts[] = (string)file_get_contents($file);
    return $texts;
};
$r8tHits = static fn(array $texts, string $re): array => array_values(array_filter($texts, static fn(string $t): bool => preg_match($re, $t) === 1));
assert_equals([], $r8tHits($r8tTexts('es'), '~\b(tu|tus|te|puedes|tienes|has|quieres|inténtalo|regístrate|haz|mantén)\b|(^|[.!?¿>]\s*)(Selecciona|Revisa|Confirma|Introduce|Restablece)\b~iu'),
    'R8 language (es): the shopper is addressed as usted everywhere (no tú forms)');
assert_equals([], $r8tHits($r8tTexts('hu'), '~\b(kosarad|rendelésed|fiókod|jelszavad|leadtuk)\b~iu'),
    'R8 language (hu): the shopper is addressed formally (magázás) and an order is "megkaptuk", not "leadtuk"');
assert_equals([], $r8tHits($r8tTexts('pl'), '~\b\w+(łeś|łaś)\b~u'),
    'R8 language (pl): no gendered second-person forms (złożyłeś/zapomniałeś)');
