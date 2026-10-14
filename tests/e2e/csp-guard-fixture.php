<?php
// Shop-origin fixture for tests/e2e/csp-guard.spec.js: a checkout form whose POST is answered with a
// real 302 to a Stripe Checkout URL, under a chosen CSP form-action (the 1.9.0 failure). Browser test
// servers only (php -S); route-fulfilled redirects are not supported by every Playwright engine.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
const CSP_GUARD_STRIPE_SESSION = 'https://checkout.stripe.com/c/pay/cs_test_csp_guard_regression';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Location: ' . CSP_GUARD_STRIPE_SESSION, true, 302);
    exit;
}
$formAction = ($_GET['form_action'] ?? '') === 'stripe' ? "'self' https://checkout.stripe.com" : "'self'";
header("Content-Security-Policy: default-src 'self'; form-action {$formAction}");
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo '<!doctype html><html lang="en"><title>Checkout</title><form class="checkout-form" method="post" action="">'
    . '<input type="hidden" name="payment" value="card"><button type="submit">Pay</button></form></html>';
