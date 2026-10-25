<?php
/**
 * R10 UX3: ?page=pay — the e-mailed payment link of an unpaid order (see PaymentLink). Without a token it shows the
 * order this browser is paying (the link to the gateway when a redirect would leave the CSP form-action).
 */
function renderPayOrder(): string {
    $t = static fn(string $key, array $vars = []): string => I18n::format($key, $vars);
    $token = Security::str($_POST['token'] ?? $_GET['token'] ?? '');
    $pending = CheckoutSession::pendingPayment();
    if ($token === '' && $pending !== null) return renderPendingPayment($pending, false);
    $order = $token !== '' && Security::rateLimit('pay_link', 30, 600) ? PaymentLink::orderForToken($token) : null;
    if ($order === null) {
        http_response_code(404);
        return Template::render('storefront/status-message', ['message' => $t('pay_link.invalid')]);
    }
    if (!PaymentLink::payable($order)) {
        return Template::render('storefront/status-message', ['message' => $t('pay_link.not_payable', ['order' => (string)$order['order_number']])]);
    }
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $url = Security::csrfValidate() ? PaymentLink::start($order) : null;
        if ($url !== null && Security::formActionAllows($url)) {
            header('Location: ' . $url, true, 303);
            exit;
        }
        if ($url !== null) return renderPendingPayment(['order_number' => (string)$order['order_number'], 'url' => $url], false);
        $error = $t('pay_link.failed');
    }
    return Template::render('storefront/pay-order', [
        'message' => $t('pay_link.message', ['order' => (string)$order['order_number'], 'total' => I18n::price((int)$order['total'], $order['currency'] ?? null)]),
        'error' => $error, 'action' => Template::storeUrl('/?page=pay'), 'csrf_token' => Security::csrfToken(), 'token' => $token,
        'pay_label' => $t('checkout_pay_now'), 'home_url' => Template::storeUrl('/'), 'continue_label' => $t('continue_shopping'),
    ]);
}
