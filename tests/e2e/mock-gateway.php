<?php
// Hosted payment page stand-in for browser tests (php -S router on its own origin).
// MockPayment sends the checkout here when config mock_checkout_url is set, so the
// checkout POST redirect leaves the shop origin exactly like Stripe Checkout does.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$link = static fn(string $id, string $key): string => '<a id="' . $id . '" href="'
    . htmlspecialchars((string)($_GET[$key] ?? ''), ENT_QUOTES, 'UTF-8') . '">' . $id . '</a>';
echo '<!doctype html><html lang="en"><title>Mock gateway</title><main>'
    . $link('gateway-pay', 'success') . ' ' . $link('gateway-back', 'cancel') . '</main></html>';
