<?php
/**
 * R10 UX8: guest order status lookup (?page=order-status), the "notify me when back in stock" form (?page=stock-notify),
 * and the account parts of the checkout (sign-in link, save address) and of a guest order's confirmation page.
 */

/** Checkout: a guest can sign in and come back; a signed-in customer can keep the address (pre-checked while the book is empty). */
function checkoutAccountView(int $customerId, bool $digital, bool $noSavedAddress, bool $posted): array {
    $t = static fn(string $key): string => I18n::t($key);
    return [
        'login_url' => $customerId ? '' : Template::storeUrl('/account.php?p=login&next=' . rawurlencode(Template::storeUrl('/?page=checkout'))),
        'login_hint' => $t('checkout.login_hint'), 'login_label' => $t('checkout.login'),
        'save_address_label' => $customerId && !$digital ? $t('checkout.save_address') : '',
        'save_address_checked' => $posted ? ($_POST['save_address'] ?? '') === '1' : $noSavedAddress,
    ];
}

/**
 * Confirmation page of a guest order: the "create an account" offer, or null when not offered. It takes no password:
 * the account is created from the link e-mailed to the order's address. $outcome is the result of a posted request.
 */
function guestAccountOfferView(array $order, array $outcome): ?array {
    if (!GuestCheckoutAccount::offered($order)) return null;
    $t = static fn(string $key): string => I18n::t($key);
    $email = (string)$order['customer_email'];
    return [
        'heading' => $t('order_done.account_heading'), 'hint' => str_replace('{email}', $email, $t('order_done.account_hint')),
        'submit_label' => $t('order_done.account_send'), 'csrf_token' => Security::csrfToken(),
        'sent' => !empty($outcome['sent']) ? str_replace('{email}', $email, $t('order_done.account_sent')) : '',
        'error' => (string)($outcome['error'] ?? ''),
    ];
}

/** "Create an account" posted on the confirmation page of a guest order: e-mails the order's address the link. */
function requestGuestAccount(array $order): array {
    $t = static fn(string $key): string => I18n::t($key);
    if (!Security::csrfValidate()) return ['error' => $t('checkout_csrf_error')];
    if (!Security::rateLimit('account_offer_ip_' . md5(Security::clientIp()), 10)) return ['error' => $t('account.rate_limited')];
    return match (GuestCheckoutAccount::sendLink($order, I18n::locale())) {
        'sent' => ['sent' => true],
        'throttled' => ['error' => $t('account.rate_limited')],
        'not_offered' => ['error' => $t('account.err_email_taken')],
        default => ['error' => $t('order_done.account_failed')],
    };
}

function renderOrderStatus(): string {
    $t = static fn(string $key, array $vars = []): string => I18n::format($key, $vars);
    $values = [
        'order_number' => mb_substr(Security::str($_POST['order_number'] ?? $_GET['order'] ?? ''), 0, 64),
        'email' => mb_substr(Security::str($_POST['email'] ?? ''), 0, 191),
    ];
    $view = ['error' => '', 'order' => null];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!Security::csrfValidate()) {
            http_response_code(403);
            $view['error'] = $t('order_status.expired');
        } elseif (!Security::rateLimit('order_status', 20, 600)) {
            $view['error'] = $t('order_status.throttled');
        } else {
            // Number AND e-mail must match: either one alone reveals nothing (same answer as a typo).
            $order = Withdrawal::findOrder($values['order_number'], $values['email']);
            if ($order === null) {
                http_response_code(422);
                $view['error'] = $t('order_status.not_found');
            } else {
                $view['order'] = orderStatusView($order);
            }
        }
    }
    return Template::render('storefront/order-status', $view + [
        'title' => $t('order_status.title'), 'intro' => $t('order_status.intro'), 'csrf_token' => Security::csrfToken(), 'values' => $values,
        'order_number_label' => $t('order_status.order_number'), 'email_label' => $t('order_status.email'), 'submit_label' => $t('order_status.submit'),
        'status_label' => $t('order_status.status'), 'payment_label' => $t('order_status.payment'), 'date_label' => $t('order_status.date'),
        'tracking_label' => $t('order_status.tracking'), 'items_label' => $t('order_status.items'), 'total_label' => $t('total_price'),
        'pay_label' => $t('checkout_pay_now'),
    ]);
}

/** What a guest sees of an order found by number and e-mail: progress, items and total, no address or phone. */
function orderStatusView(array $order): array {
    $currency = (string)$order['currency'];
    return [
        'number' => (string)$order['order_number'],
        'date' => I18n::dateTime((string)$order['created_at']),
        'status' => OrderStatusLabels::order((string)$order['status']),
        'payment_status' => OrderStatusLabels::payment((string)($order['payment_status'] ?? 'pending')),
        'tracking' => trim((string)($order['carrier'] ?? '') . ' ' . (string)($order['tracking_number'] ?? '')),
        'items' => array_map(static fn(array $item): array => ['name' => (string)$item['name'], 'quantity' => (int)$item['quantity']], Orders::items((int)$order['id'])),
        'total' => I18n::price((int)$order['total'], $currency),
        'pay_url' => PaymentLink::payable($order) ? PaymentLink::issue($order) : '',
    ];
}

/** POST only: store the request and say so; the product page offers the form when the product cannot be bought. */
function renderStockNotify(): string {
    $t = static fn(string $key): string => I18n::t($key);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return Template::render('storefront/status-message', ['message' => $t('back_in_stock.invalid')]);
    }
    if (!Security::csrfValidate()) {
        http_response_code(403);
        return Template::render('storefront/status-message', ['message' => $t('back_in_stock.expired')]);
    }
    if (!Security::rateLimit('back_in_stock', 10, 3600)) {
        return Template::render('storefront/status-message', ['message' => $t('back_in_stock.throttled')]);
    }
    $result = BackInStock::subscribe(Security::int($_POST['product_id'] ?? 0), Security::str($_POST['email'] ?? ''), I18n::locale());
    if ($result === 'invalid') http_response_code(422);
    return Template::render('storefront/status-message', ['message' => $t('back_in_stock.' . $result)]);
}
