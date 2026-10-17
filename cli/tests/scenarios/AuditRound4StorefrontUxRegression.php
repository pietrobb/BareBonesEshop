<?php

// Round 4 storefront UX review: no-JS add to cart, variant products in listings, translated checkout refusals,
// order e-mail currency/status/tracking, Spanish e-mails, translated payment summary and statuses, free payment
// fee label, plurals, attribute labels, clamped quantities and notices, non-destructive shipping refresh,
// keyboard-safe auto-submit, per-locale consent texts, delivery-time phrases, q[] input and canonical links.
echo "\n--- Audit round 4: storefront UX (no-JS cart, i18n, notices, e-mails, canonical links) ---\n";
$r4Root = dirname(TEST_CLI_DIR);
$r4Theme = $r4Root . '/themes/default/blocks/storefront';
$r4Locale = I18n::locale();
$r4Session = $_SESSION ?? [];
$r4Server = array_intersect_key($_SERVER, array_flip(['SCRIPT_NAME', 'REQUEST_URI']));
$r4Catalog = StoreContext::current()->catalogId();
require_once $r4Root . '/src/Entrypoints/Storefront/CatalogPages.php';
require_once $r4Root . '/src/Entrypoints/Storefront/CheckoutPages.php';
require_once $r4Root . '/cli/worker.php';
try {
    I18n::setLocale('sk');
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['REQUEST_URI'] = '/category/r4-cat?pg=2';
    $_SESSION = ['csrf_token' => 'r4-csrf', 'cart' => []];
    $r4Product = $pdo->prepare("INSERT INTO products (id, store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES (?, ?, 1, ?, ?, ?, 'simple', 1000, 'eur', 'active', ?, ?)");
    $r4Product->execute([9841, $r4Catalog, 'R4 simple', 'r4-simple', 'R4-SIMPLE', 3, 'deny']);
    $r4Product->execute([9842, $r4Catalog, 'R4 variants', 'r4-variants', 'R4-VARIANTS', 5, 'deny']);
    $r4Product->execute([9843, $r4Catalog, 'R4 backorder', 'r4-backorder', 'R4-BACKORDER', 0, 'backorder']);
    $pdo->prepare("INSERT INTO variants (product_id, sku, name, stock) VALUES (9842, 'R4-VARIANTS-RED', 'Red', 5)")->execute();
    $r4VariantId = (int)$pdo->query("SELECT id FROM variants WHERE sku = 'R4-VARIANTS-RED'")->fetchColumn();

    // 1 + 2. Cards: one batched variant lookup, a no-JS POST form for simple products, a link for variant products.
    $r4Cards = StorefrontViewModel::productCards([Products::byId(9841), Products::byId(9842)]);
    assert_true($r4Cards[0]['requires_variant'] === false && $r4Cards[1]['requires_variant'] === true,
        'R4 cards: requires_variant comes from the variants table (batched, no per-card query)');
    assert_equals(['action' => Template::storeUrl('/'), 'csrf_token' => 'r4-csrf', 'redirect' => '/category/r4-cat?pg=2'], $r4Cards[0]['cart_form'],
        'R4 cards: the add-to-cart form posts to the store URL with the CSRF token and returns to the current page');
    $r4Grid = Template::render('storefront/product-grid', ['products' => array_values($r4Cards), 't' => I18n::all()]);
    assert_true(preg_match('~<form class="cart-add-form" method="post" action="[^"]*"><input type="hidden" name="csrf_token" value="r4-csrf"><input type="hidden" name="cart_action" value="add"><input type="hidden" name="product_id" value="9841"><input type="hidden" name="quantity" value="1"><input type="hidden" name="redirect" value="/category/r4-cat\?pg=2"><button class="btn-cart" type="submit" data-storefront-action="cart-add" data-product-id="9841"~', $r4Grid) === 1,
        'R4 no-JS cart: a product card submits a real POST form (cart_action, product_id, quantity, csrf_token, redirect)');
    assert_true(!str_contains($r4Grid, 'data-product-id="9842"') && str_contains($r4Grid, '<a class="btn-cart" href="' . StorefrontViewModel::productUrl('r4-variants') . '">' . I18n::t('choose_option') . '</a>'),
        'R4 variant card: a product that needs a variant links to its page instead of offering add to cart');
    $r4Section = Template::render('storefront/section-product-list', ['id' => 'r4', 'settings' => ['variant' => 'grid'], 'content' => [], 'data' => ['items' => array_values($r4Cards)], 't' => I18n::all()]);
    assert_true(str_contains($r4Section, 'name="cart_action" value="add"') && str_contains($r4Section, I18n::t('choose_option')) && !str_contains($r4Section, 'type="button" data-storefront-action'),
        'R4 section product list: same guarded no-JS form as the product grid');
    $r4ProductPage = (string)file_get_contents($r4Theme . '/product-page.html');
    assert_true(str_contains($r4ProductPage, '<form class="product-detail__actions cart-add-form" id="cart-add-{{product.id}}" method="post" action="{{product.cart_form.action}}">')
        && str_contains($r4ProductPage, 'form="cart-add-{{../product.id}}"') && str_contains($r4ProductPage, 'name="quantity" value="1" min="1" max="99"')
        && !preg_match('~type="button"[^>]*data-storefront-action~', implode('', array_map('file_get_contents', glob($r4Theme . '/*.html') ?: []))),
        'R4 product page: quantity and variant radios belong to the POST form; no add-to-cart button is type=button any more');
    $_SESSION['cart'] = [];
    $r4Variant = StorefrontCartAction::execute(['cart_action' => 'add', 'product_id' => '9842', 'variant-9842' => (string)$r4VariantId, 'quantity' => '1']);
    assert_true($r4Variant['ok'] && isset($_SESSION['cart']['v_' . $r4VariantId]), 'R4 no-JS cart: the variant radio (name="variant-{id}") is accepted as variant_id');
    $r4StorefrontSrc = (string)file_get_contents($r4Root . '/src/Entrypoints/Storefront.php');
    assert_true(str_contains($r4StorefrontSrc, "if (!\$result['ok']) http_response_code(422);\n        header('Content-Type: application/json');")
        && str_contains($r4StorefrontSrc, 'StorefrontCartAction::flash($result)') && str_contains($r4StorefrontSrc, "Security::safeLocalPath(\$_POST['redirect']"),
        'R4 no-JS cart: only the AJAX answer is a 422; a form post redirects (safe local path) with a notice');

    // 10. Clamped quantities are reported; notices for the no-JS flow and the wishlist.
    $_SESSION['cart'] = [];
    $r4Clamped = Cart::addValidated(9841, null, 5);
    assert_true($r4Clamped['ok'] && $r4Clamped['clamped'] === true && $r4Clamped['quantity'] === 3, 'R4 clamp: adding more than the stock reports the 3 units the cart holds');
    assert_equals(I18n::format('cart_quantity_clamped', ['count' => 3]), StorefrontCartAction::notice($r4Clamped), 'R4 clamp: the notice names the clamped quantity');
    $r4Max = Cart::addValidated(9843, null, 150);
    assert_true($r4Max['clamped'] === true && $r4Max['quantity'] === Cart::MAX_QTY, 'R4 clamp: a backorder line above 99 reports Cart::MAX_QTY');
    $_SESSION['cart'] = [];
    $r4Plain = Cart::addValidated(9841, null, 1);
    assert_true($r4Plain['clamped'] === false && StorefrontCartAction::notice($r4Plain) === I18n::t('cart_added_notice'), 'R4 clamp: a normal add is not reported as clamped');
    assert_equals(I18n::t('variant_required'), StorefrontCartAction::notice(Cart::addValidated(9842, null, 1)), 'R4 notice: a refused add explains why');
    StorefrontFlash::set('R4 notice');
    assert_true(StorefrontFlash::pull() === 'R4 notice' && StorefrontFlash::pull() === '', 'R4 flash: a notice is shown once');
    assert_true(str_contains((string)file_get_contents($r4Theme . '/shell.html'), '<p class="storefront-flash" id="storefront-flash" role="status">{{flash}}</p>')
        && str_contains((string)file_get_contents($r4Root . '/src/Entrypoints/Storefront/Layout.php'), "'flash' => StorefrontFlash::pull()"),
        'R4 flash: the storefront shell has a status live region with the one-shot notice');
    $r4Wishlist = (string)file_get_contents($r4Root . '/wishlist.php');
    assert_true(str_contains($r4Wishlist, "StorefrontFlash::set(I18n::t('wishlist.removed'))") && str_contains($r4Wishlist, "Wishlist::toggle(\$pid) === 'added' ? 'wishlist.added' : 'wishlist.removed'"),
        'R4 wishlist: toggle and remove tell the shopper what happened');

    // 3. Checkout refusals carry a code and the message in the shopper's locale.
    $r4Refused = CheckoutService::execute(['name' => 'R4', 'email' => 'r4@example.test', 'country' => 'XYZ']);
    assert_true(($r4Refused['error_code'] ?? '') === 'checkout.error.invalid_country' && $r4Refused['error'] === I18n::t('checkout.error.invalid_country') && $r4Refused['error'] !== 'checkout.error.invalid_country',
        'R4 checkout errors: a refusal returns error_code and the translated error');
    I18n::setLocale('en');
    $r4Refused = CheckoutService::execute(['name' => '', 'email' => '', 'country' => 'SK']);
    assert_equals(['checkout.error.name_email_required', 'Name and e-mail are required.'], [$r4Refused['error_code'] ?? '', $r4Refused['error'] ?? ''], 'R4 checkout errors: the same refusal in English');
    I18n::setLocale('sk');
    $r4Api = (string)file_get_contents($r4Root . '/src/Http/ApiHandlers.php');
    $r4Hardcoded = array_filter(['Too many checkout attempts', 'Name and email are required', 'Cart is empty', 'Žiadne dostupné', 'rôznych menách', 'Doručovacia adresa je', 'Doručenie nie je', 'Platba nie je dostupná', 'Payment processing unavailable', 'Required consent missing'],
        static fn(string $text): bool => str_contains($r4Api, $text));
    assert_equals([], array_values($r4Hardcoded), 'R4 checkout errors: no hard-coded Slovak/English checkout refusal is left in the handler');

    // 7. A free payment method is "free", not "free shipping".
    $r4Layout = (string)file_get_contents($r4Root . '/src/Entrypoints/Storefront/Layout.php');
    $r4CheckoutPages = (string)file_get_contents($r4Root . '/src/Entrypoints/Storefront/CheckoutPages.php');
    assert_true(str_contains($r4Layout, "'free' => I18n::t('free')") && str_contains($r4CheckoutPages, "'fee' => (int)\$method['fee'] === 0 ? \$freeFeeLabel")
        && str_contains($r4CheckoutPages, "'payment_fee' => \$paymentFee === 0 ? \$freeFeeLabel") && I18n::t('free') !== I18n::t('free_shipping'),
        'R4 free fee: payment fees use the plain "free" label (HTML and JS)');

    // 8 + 14. Plurals and delivery phrases.
    assert_equals(['1 produkt', '3 produkty', '5 produktov', '2 výsledky pre'], [I18n::plural('products_count', 1), I18n::plural('products_count', 3), I18n::plural('products_count', 5), I18n::plural('search_results_count', 2)],
        'R4 plural: listing totals use the Slovak plural forms');
    assert_true(str_contains((string)file_get_contents($r4Theme . '/category-page.html'), '<span class="total">{{total_label}}</span>')
        && substr_count((string)file_get_contents($r4Theme . '/search-results.html'), '{{total_label}} “{{query}}”') === 2, 'R4 plural: category and search pages render the plural label');
    assert_equals(['', '', 'Doručenie do 1 dňa', 'Doručenie do 2 dní', 'Doručenie do 5–7 dní', 'ihneď po úhrade'],
        array_map([ShippingService::class, 'deliveryLabel'], ['', '0', '1', '2', '5-7', 'ihneď po úhrade']), 'R4 delivery: days become a translated phrase, 0/unknown is omitted');
    I18n::setLocale('en');
    assert_equals('Delivery in 1–2 days', ShippingService::deliveryLabel('1-2'), 'R4 delivery: a range uses the plural of its upper bound');
    I18n::setLocale('sk');
    assert_true(str_contains((string)file_get_contents($r4Theme . '/checkout.html'), '{{#if delivery_days}}<small>{{delivery_days}}</small>{{/if}}')
        && str_contains($r4CheckoutPages, "'delivery_days' => ShippingService::deliveryLabel(") && str_contains($r4Api, "'delivery_label' => ShippingService::deliveryLabel("),
        'R4 delivery: the checkout page and the shipping_rates API show the phrase');

    // 4 + 6. Status labels, order e-mails in the order currency with the shipment's tracking.
    assert_equals(['Čaká na platbu', 'Paid', 'Odeslaná', 'Weird state'], [OrderStatusLabels::payment('pending'), OrderStatusLabels::order('paid', 'en'), OrderStatusLabels::order('shipped', 'cs'), OrderStatusLabels::order('weird_state')],
        'R4 statuses: translated labels per locale, a readable fallback for unknown codes');
    assert_equals('sk', I18n::locale(), 'R4 statuses: a label in another locale leaves the current locale alone');
    $r4Summary = (string)file_get_contents($r4Theme . '/order-payment-summary.html');
    assert_true(!preg_match('~>(Payment|Status:|Method:|Reference:)~', $r4Summary) && str_contains($r4CheckoutPages, "'status' => OrderStatusLabels::payment(\$status)"),
        'R4 payment summary: no hard-coded English labels, the status is translated');
    $pdo->prepare("INSERT INTO orders (order_number, store_id, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, locale)
        VALUES ('R4-CZK-1', ?, 'shipped', 'paid', 'R4 Buyer', 'r4-buyer@example.test', 'bank_transfer', 121000, 121000, 'czk', 'cs')")->execute([StoreContext::current()->id()]);
    $r4OrderId = (int)$pdo->query("SELECT id FROM orders WHERE order_number = 'R4-CZK-1'")->fetchColumn();
    $pdo->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES (?, 9841, 'R4-SIMPLE', 'R4 simple', 1, 121000, 121000)")->execute([$r4OrderId]);
    $r4Shipment = $pdo->prepare("INSERT INTO shipments (store_id, order_id, status, carrier, tracking_number, tracking_url, idempotency_key, shipped_at) VALUES (?, ?, 'shipped', ?, ?, ?, ?, ?)");
    $r4Shipment->execute([StoreContext::current()->id(), $r4OrderId, 'DPD', 'R4TRACK1', 'https://track.example/R4TRACK1', 'r4-ship-1', '2026-10-01 10:00:00']);
    $r4Shipment->execute([StoreContext::current()->id(), $r4OrderId, null, null, null, 'r4-ship-2', '2026-10-02 10:00:00']);
    assert_equals(['carrier' => 'DPD', 'tracking_number' => 'R4TRACK1', 'tracking_url' => 'https://track.example/R4TRACK1'], Fulfillment::latestTracking($r4OrderId),
        'R4 tracking: the latest shipped shipment with tracking details is used');
    LogMail::clearCaptured();
    MailService::useAdapter(new LogMail(['log_file' => TEST_CLI_DIR . '/../db/emails.test.log']));
    handleEmailSend(['template' => 'order_shipped', 'to' => 'r4-buyer@example.test', 'order_id' => $r4OrderId], []);
    $r4Mail = (string)(LogMail::lastCaptured()['html'] ?? '');
    assert_true(str_contains($r4Mail, 'DPD') && str_contains($r4Mail, 'R4TRACK1') && str_contains($r4Mail, 'href="https://track.example/R4TRACK1"'),
        'R4 order_shipped: the e-mail carries the admin shipment carrier, tracking number and tracking link');
    handleEmailSend(['template' => 'order_confirmation', 'to' => 'r4-buyer@example.test', 'order_id' => $r4OrderId], []);
    $r4Mail = (string)(LogMail::lastCaptured()['html'] ?? '');
    $r4Czk = esc(I18n::price(121000, 'czk'));
    assert_true(substr_count($r4Mail, $r4Czk) >= 2 && !str_contains($r4Mail, '€') && str_contains($r4Mail, '<strong>' . I18n::tIn('order_status.shipped', 'cs') . '</strong>') && !str_contains($r4Mail, '<strong>shipped</strong>'),
        'R4 order e-mails: line items use the order currency (CZK) and the status is a translated label (' . substr(strip_tags($r4Mail), 0, 200) . ')');
    MailService::useAdapter(null);
    I18n::setLocale('sk');

    // 5. Spanish e-mails: the full template set.
    $r4Names = static fn(string $locale): array => array_map('basename', glob($r4Root . '/templates/emails/' . $locale . '/*') ?: []);
    assert_equals($r4Names('en'), $r4Names('es'), 'R4 Spanish e-mails: every English template exists in Spanish');
    assert_true(str_contains((string)file_get_contents($r4Root . '/templates/emails/es/order_shipped.html'), '{{tracking_url}}'), 'R4 Spanish e-mails: same placeholders as English');

    // 9. Attribute labels.
    assert_true(str_contains((string)file_get_contents($r4Root . '/src/StorefrontViewModel.php'), "'label' => I18n::attrLabel(\$label),"), 'R4 attributes: product attribute labels are translated');

    // 11 + 12. Storefront JS: submit handler, non-destructive shipping errors, keyboard-safe auto-submit.
    $r4Js = (string)file_get_contents($r4Root . '/themes/default/assets/storefront.js');
    $r4Refresh = substr($r4Js, (int)strpos($r4Js, 'async function refreshCheckoutShipping'), 2400);
    assert_true(str_contains($r4Js, "document.addEventListener('submit', (event) => {\n    const cartButton = event.target.querySelector('[data-storefront-action=\"cart-add\"]');")
        && str_contains($r4Js, 'event.preventDefault();') && str_contains($r4Js, 'result.clamped'), 'R4 JS: the add-to-cart form submit is intercepted and a clamp is reported');
    assert_true(!str_contains($r4Refresh, "showCheckoutError(box, 'shipping-error'") && !str_contains($r4Refresh, "box.innerHTML = `<div class=\"shipping-loading\"")
        && str_contains($r4Refresh, "insertAdjacentHTML('afterbegin', `<div class=\"shipping-error shipping-notice\" role=\"alert\">"),
        'R4 JS: a failed rate request keeps the server-rendered options and adds a notice');
    assert_true(str_contains($r4Js, 'keyboardSelect !== control') && str_contains($r4Js, 'setTimeout(() => control.form.requestSubmit(), 800)')
        && str_contains((string)file_get_contents($r4Theme . '/category-page.html'), '<button type="submit" class="btn-sort">{{t.sort}}</button>')
        && !str_contains((string)file_get_contents($r4Theme . '/search-results.html'), '<noscript>'),
        'R4 JS: sort selects keep a visible submit button and do not submit on arrow keys; quantities are debounced');

    // 13. Consents: per-locale text and store-prefixed relative URLs.
    ConsentService::setDefinitionsForTesting(['r4_terms' => ['version' => '1', 'required' => true, 'text' => ['sk' => 'Súhlasím s podmienkami', 'en' => 'I accept the terms'], 'url' => '/?page=terms']]);
    $r4Consent = ConsentService::definitions()['r4_terms'];
    assert_equals(['Súhlasím s podmienkami', Template::storeUrl('/?page=terms')], [$r4Consent['text'], $r4Consent['url']], 'R4 consents: the text follows the locale and a relative URL gets the store prefix');
    I18n::setLocale('en');
    assert_equals('I accept the terms', ConsentService::definitions()['r4_terms']['text'], 'R4 consents: English shoppers read the English text');
    I18n::setLocale('sk');
    ConsentService::setDefinitionsForTesting(null);
    $r4Schema = json_decode((string)file_get_contents($r4Root . '/config/config.schema.json'), true);
    assert_true(isset($r4Schema['properties']['checkout_consents']['additionalProperties']['properties']['text']['oneOf']), 'R4 consents: the schema documents text as a string or a locale map');

    // 15 + 16. Array query values and canonical links.
    assert_true(str_contains($r4Layout, "'search_query' => Security::str(\$_GET['q'] ?? '')") && str_contains($r4StorefrontSrc, "StorefrontCartAction::recover(Security::str(\$_GET['cart_recover']))")
        && str_contains((string)file_get_contents($r4Root . '/redirect.php'), "Security::str(\$_GET['path']"), 'R4 input: q[]=x and other array query values no longer raise Array to string conversion');
    assert_true(!str_contains($r4Layout, '?page=category') && !str_contains((string)file_get_contents($r4Root . '/src/Entrypoints/Storefront/CatalogPages.php'), "storeUrl('/?page=category")
        && !str_contains($r4CheckoutPages, '?page=product&slug') && str_contains($r4Layout, "Template::menuUrl((string)\$item['url'])"),
        'R4 links: navigation, footer, breadcrumbs and cart lines use canonical paths; managed menu links get the store prefix');
    assert_equals([Template::storeUrl('/category/r4-cat'), '/category/r4-cat?sort=price_desc&pmin=10'], [StorefrontViewModel::categoryUrl('r4-cat'), buildCategoryUrl('r4-cat', 'price_desc', [], 1000, null)],
        'R4 links: category links and pagination bases use /category/{slug}');
} finally {
    ConsentService::setDefinitionsForTesting(null);
    MailService::useAdapter(null);
    $pdo->exec("DELETE FROM shipments WHERE idempotency_key IN ('r4-ship-1', 'r4-ship-2')");
    $pdo->exec("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE order_number = 'R4-CZK-1')");
    $pdo->exec("DELETE FROM orders WHERE order_number = 'R4-CZK-1'");
    $pdo->exec("DELETE FROM variants WHERE product_id IN (9841, 9842, 9843)");
    $pdo->exec("DELETE FROM products WHERE id IN (9841, 9842, 9843)");
    $_SESSION = $r4Session;
    foreach (['SCRIPT_NAME', 'REQUEST_URI'] as $r4Key) {
        if (array_key_exists($r4Key, $r4Server)) $_SERVER[$r4Key] = $r4Server[$r4Key]; else unset($_SERVER[$r4Key]);
    }
    I18n::setLocale($r4Locale);
}
