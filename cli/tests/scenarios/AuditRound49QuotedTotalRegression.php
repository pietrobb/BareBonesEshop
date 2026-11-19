<?php

// Review round 19 (R49, finding 2): quoted_total sat only in the checkout's <noscript>, so with JS the order never
// posted the total on screen and ApiHandlers' check never ran: a price raised after the page loaded (18,90 € shown,
// 21,90 € charged), two tabs racing the OSS threshold, or a shipping that passes it were charged unseen. The script
// now posts the total it shows. The browser run is tests/e2e/oss-pricing.spec.js (CI e2e, three engines).
echo "\n--- R49: the JS checkout posts the total on screen (quoted_total) ---\n";

$r49qRoot = dirname(TEST_CLI_DIR);
$r49qJs = (string)file_get_contents($r49qRoot . '/themes/default/assets/storefront.js');
$r49qTpl = (string)file_get_contents($r49qRoot . '/themes/default/blocks/storefront/checkout.html');
$r49qFn = static function (string $name) use ($r49qJs): string {
    $start = strpos($r49qJs, "function {$name}(");
    if ($start === false) return '';
    $end = strpos($r49qJs, "\n  }\n", $start);
    return substr($r49qJs, $start, $end === false ? 0 : $end - $start);
};
$r49qUpdate = $r49qFn('updateCheckoutTotal');
$r49qQuote = $r49qFn('quoteCheckoutTotal');
assert_true(str_contains($r49qUpdate, 'quoteCheckoutTotal(subtotal - discount + shipping + paymentFee)'),
    'R49 quoted total: every total the script shows is also the total the order posts (was: nothing posted with JS)');
assert_true(str_contains($r49qQuote, "input.name = 'quoted_total'") && str_contains($r49qQuote, "input.type = 'hidden'") && str_contains($r49qQuote, 'form[data-checkout-form]'),
    'R49 quoted total: the script keeps one hidden quoted_total in the checkout form, also for a theme override without it');
assert_true(str_contains($r49qTpl, '<input type="hidden" id="checkout-total" value="{{total_cents}}" autocomplete="off">'),
    'R49 quoted total: the server-rendered total is quoted until the script re-prices (a failed rates request still posts it)');
assert_true(str_contains($r49qJs, "document.getElementById('checkout-total')") && str_contains($r49qJs, 'quoteCheckoutTotal(parseInt(renderedTotal.value, 10))'),
    'R49 quoted total: the script quotes the rendered total on load');
// The no-JS form keeps its own quote; with JS the <noscript> copy is not parsed, so exactly one value is posted.
assert_true(str_contains($r49qTpl, '<noscript><input type="hidden" name="quoted_total" value="{{total_cents}}"></noscript>'),
    'R49 quoted total: the no-JS form still posts its total (R34)');
$r49qHandler = (string)file_get_contents($r49qRoot . '/src/Http/ApiHandlers.php');
assert_true(str_contains($r49qHandler, "(int)\$quotedTotal !== (int)\$financialSnapshot['totals']['gross']") && str_contains($r49qHandler, "checkoutRefusal('checkout.error.total_changed')"),
    'R49 quoted total: a posted total other than the one charged refuses the order and shows the new total');
$r49qE2e = (string)file_get_contents($r49qRoot . '/tests/e2e/oss-pricing.spec.js');
assert_true(str_contains($r49qE2e, "product-price.php', 'e2e-oss-mug', '13530'") && str_contains($r49qE2e, 'toContainText(/132[,.]00/)'),
    'R49 quoted total: the browser test raises the price after the page loaded and expects the new total shown, not charged');
