<?php
/**
 * R11 H1: ?page=account-claim — the link GuestCheckoutAccount::sendLink() e-mails to a guest order's address. Opening it
 * creates nothing (mail scanners follow links); the posted password creates the verified account with the order in it.
 */
function renderAccountClaim(): string {
    $t = static fn(string $key): string => I18n::t($key);
    $token = Security::str($_POST['token'] ?? $_GET['token'] ?? '');
    $order = $token !== '' && Security::rateLimit('account_claim', 30, 600) ? GuestCheckoutAccount::orderForToken($token) : null;
    if ($order === null) {
        http_response_code(404);
        return Template::render('storefront/status-message', ['message' => $t('account_claim.invalid')]);
    }
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!Security::csrfValidate()) {
            $error = $t('checkout_csrf_error');
        } else {
            $result = GuestCheckoutAccount::claim($token, Security::str($_POST['password'] ?? ''), I18n::locale());
            if ($result['ok']) {
                header('Location: ' . Template::storeUrl('/account.php?p=order&id=' . (int)$order['id']), true, 303);
                exit;
            }
            $error = $t('account.err_' . $result['error']) ?: (string)$result['error'];
        }
    }
    return Template::render('storefront/account-claim', [
        'heading' => $t('order_done.account_heading'),
        'message' => str_replace(['{email}', '{order}'], [(string)$order['customer_email'], (string)$order['order_number']], $t('account_claim.message')),
        'error' => $error, 'action' => Template::storeUrl('/?page=account-claim'), 'csrf_token' => Security::csrfToken(), 'token' => $token,
        'password_label' => $t('account.password'), 'min_length' => Customers::MIN_PASSWORD_LEN, 'submit_label' => $t('order_done.account_submit'),
    ]);
}
