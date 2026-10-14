<?php
/** Cart/checkout data presenters and checkout transport. Markup is theme-owned. */
function renderCart(): string {
    global $price, $t;
    $cartData = Cart::enriched();
    $cart = $cartData['items'];
    $breadcrumbs = [
        ['url' => Template::storeUrl('/'), 'label' => $t('home'), 'current' => false],
        ['url' => '', 'label' => $t('cart'), 'current' => true],
    ];
    if (!$cart) {
        return Template::render('storefront/cart-empty', [
            'breadcrumbs' => $breadcrumbs, 'title' => $t('cart_title'), 'message' => $t('cart_empty'),
            'home_url' => Template::storeUrl('/'), 'continue_label' => $t('continue_shopping'),
        ]);
    }

    $subtotal = (int)$cartData['subtotal'];
    $quote = ShippingService::cartQuote($cart, $subtotal, 'SK');
    $shipping = $quote['delivery_price'];
    $items = array_map(static fn(array $item): array => [
        'id' => (int)$item['product_id'], 'cart_key' => (string)$item['key'],
        'url' => Template::storeUrl('/?page=product&slug=' . rawurlencode((string)$item['slug'])),
        'name' => (string)$item['name'], 'sku' => (string)$item['sku'], 'price' => $price((int)$item['price']),
        'quantity' => (int)$item['qty'], 'line_total' => $price((int)$item['total']),
        'remove_label' => $t('remove'),
    ], $cart);

    return Template::render('storefront/cart', [
        'breadcrumbs' => $breadcrumbs, 'title' => $t('cart_title'), 'items' => $items,
        'product_label' => $t('product'), 'price_label' => $t('price'), 'quantity_label' => $t('quantity'), 'total_label' => $t('total'),
        'summary_label' => $t('order_summary'), 'subtotal_label' => $t('subtotal'), 'subtotal' => $price($subtotal), 'shipping_label' => $t('shipping'),
        'shipping' => ['free' => $shipping === 0, 'free_label' => $t('free_shipping'), 'price' => $price($shipping)],
        'free_over_text' => $shipping > 0 && $quote['free_above'] ? $t('free_over') . ' ' . $price($quote['free_above']) : '',
        'free_shipping_hint' => $quote['hint'],
        'total_price_label' => $t('total_price'), 'total' => $price($subtotal + $shipping), 'checkout_url' => Template::storeUrl('/?page=checkout'),
        'checkout_label' => $t('to_checkout'), 'csrf_token' => Security::csrfToken(), 'cart_url' => Template::storeUrl('/?page=cart'), 'clear_label' => $t('clear_cart'),
    ]);
}

function renderCheckout(): string {
    global $price, $t, $storeCurrency;
    $cartData = Cart::enriched();
    $cart = $cartData['items'];
    $breadcrumbs = [
        ['url' => Template::storeUrl('/'), 'label' => $t('home'), 'current' => false],
        ['url' => Template::storeUrl('/?page=cart'), 'label' => $t('cart'), 'current' => false],
        ['url' => '', 'label' => $t('checkout_title'), 'current' => true],
    ];
    if (!$cart) {
        // The order was already created (cart cleared): a resubmit or a return from the
        // gateway continues that payment instead of reporting an empty cart.
        $pending = CheckoutSession::pendingPayment();
        if ($pending) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['checkout']) && Security::formActionAllows((string)$pending['url'])) {
                header('Location: ' . $pending['url']);
                exit;
            }
            return renderPendingPayment($pending, false);
        }
        return Template::render('storefront/checkout-empty', [
            'breadcrumbs' => $breadcrumbs, 'message' => $t('cart_empty'), 'home_url' => Template::storeUrl('/'), 'continue_label' => $t('continue_shopping'),
        ]);
    }
    // The coupon, saved-address and country buttons submit the whole checkout form (no JS needed) to re-price
    // or refill it, not to place the order. A refused order re-renders the form with every value kept.
    $posted = $_SERVER['REQUEST_METHOD'] === 'POST';
    $couponAction = $posted && is_string($_POST['coupon_action'] ?? null) ? $_POST['coupon_action'] : '';
    $formAction = $couponAction !== '' || ($posted && (isset($_POST['address_action']) || !empty($_POST['checkout_refresh'])));
    if ($formAction && !Security::csrfValidate()) {
        return Template::render('storefront/checkout-error', ['home_url' => Template::storeUrl('/'), 'home_label' => 'Domov', 'error_label' => 'Chyba', 'message' => 'CSRF token mismatch.']);
    }
    $placed = !$formAction && $posted && !empty($_POST['checkout']) ? processCheckout() : ['error' => ''];
    if (isset($placed['page'])) return $placed['page'];
    $checkoutError = $placed['error'];
    if ($checkoutError !== '') {
        // A refusal can drop sold-out lines from the cart: show what is left, or only the reason.
        $cart = ($cartData = Cart::enriched())['items'];
        if (!$cart) return Template::render('storefront/checkout-error', ['home_url' => Template::storeUrl('/'), 'home_label' => 'Domov', 'error_label' => 'Chyba', 'message' => $checkoutError]);
    }
    $state = CheckoutService::formState($_POST, $posted);
    $form = static fn(string $key): string => $posted && is_string($_POST[$key] ?? null) ? mb_substr(trim($_POST[$key]), 0, 64) : '';
    $subtotal = (int)$cartData['subtotal'];
    $country = $state['country'];
    $quote = CheckoutService::deliveryQuote($cart, $subtotal, $country, $_POST['shipping_method'] ?? '');
    $shippingRates = $quote['rates'];
    $selectedRate = $quote['selected'];
    $selectedShipping = (string)($selectedRate['method_code'] ?? '');
    $shipping = $quote['price'];
    $customerId = (int)($_SESSION['customer_id'] ?? 0);
    $paymentMethods = $selectedRate ? PaymentService::availableMethods($country, $storeCurrency, $subtotal + $shipping, (string)$selectedRate['method_code'], $customerId ?: null) : [];
    $postedPayment = $form('payment');
    $selectedPayment = null;
    foreach ($paymentMethods as $method) if ((string)$method['code'] === $postedPayment) $selectedPayment = $method;
    $selectedPayment ??= $paymentMethods[0] ?? null;
    $paymentFee = $selectedPayment ? (int)$selectedPayment['fee'] : 0;
    $freeLabel = $t('free_shipping');

    // Same discount rules as CheckoutService::execute: coupon + automatic promotions, capped at the subtotal.
    $couponCode = $couponAction === 'remove' ? '' : $form('coupon_code');
    $preview = CheckoutService::discountPreview($couponCode, $subtotal, Security::email($state['values']['email']) ?? null, $cart, $customerId ?: null);
    $couponDiscount = $preview['coupon_discount'];
    $couponError = $preview['coupon_error'] === null ? '' : sprintf($t($preview['coupon_error']), $couponCode);
    if ($couponError !== '') $couponCode = '';
    if ($preview['free_shipping']) $shipping = 0;
    $discount = min($subtotal, $couponDiscount + $preview['promotion_discount']);

    return Template::render('storefront/checkout', [
        'breadcrumbs' => $breadcrumbs, 'title' => $t('checkout_title'), 'csrf_token' => Security::csrfToken(), 'address_required' => !$quote['digital'],
        'refresh_label' => $t('checkout.refresh'), 'consents' => array_values(CheckoutService::consentDefinitions($country, $cart)),
        'contact_label' => $t('contact'), 'name_label' => $t('name'), 'email_label' => $t('email'), 'phone_label' => $t('phone'),
        'address_label' => $t('address'), 'street_label' => $t('street'), 'city_label' => $t('city'), 'zip_label' => $t('zip'),
        'shipping_label' => $t('shipping'), 'shipping_options' => array_map(static fn(array $rate): array => [
            'code' => (string)$rate['method_code'], 'label' => (string)$rate['label'], 'delivery_days' => (string)($rate['delivery_days'] ?? ''),
            'price' => (int)$rate['price'] === 0 ? $freeLabel : $price((int)$rate['price']),
            'checked' => (string)$rate['method_code'] === $selectedShipping,
        ], $shippingRates),
        'shipping_unavailable' => $shippingRates ? '' : $t('shipping_unavailable'), 'free_shipping_hint' => $quote['hint'], 'payment_label' => $t('payment'),
        'payment_options' => array_map(static fn(array $method): array => [
            'code' => (string)$method['code'], 'label' => (string)$method['label'], 'description' => (string)($method['description'] ?: $method['provider']),
            'fee' => (int)$method['fee'] === 0 ? $freeLabel : $price((int)$method['fee']), 'checked' => (string)$method['code'] === (string)($selectedPayment['code'] ?? ''),
            'online' => !empty($method['requires_online']),
        ], $paymentMethods, array_keys($paymentMethods)),
        'submitting_label' => $t('checkout_submitting'), 'redirecting_label' => $t('checkout_redirecting'),
        'payment_unavailable' => $paymentMethods ? '' : $t('payment_unavailable'), 'notes_label' => $t('notes'), 'notes_placeholder' => $t('notes_placeholder'),
        'submit_label' => $t('submit_order'), 'your_order_label' => $t('your_order'),
        'items' => array_map(static fn(array $item): array => ['name' => (string)$item['name'], 'quantity' => (int)$item['qty'], 'total' => $price((int)$item['total'])], $cart),
        'subtotal_cents' => $subtotal, 'subtotal_label' => $t('subtotal'), 'subtotal' => $price($subtotal),
        'shipping' => $shipping === 0 ? $freeLabel : $price($shipping), 'payment_fee' => $paymentFee === 0 ? $freeLabel : $price($paymentFee),
        'discount_cents' => $discount, 'discount_label' => $t('discount'), 'discount' => $discount > 0 ? $price(-$discount) : '',
        'total_price_label' => $t('total_price'), 'total' => $price($subtotal - $discount + $shipping + $paymentFee),
        'values' => $state['values'], 'checkout_error' => $checkoutError, 'saved_addresses' => $state['addresses'],
        'saved_address_label' => $t('checkout_saved_address'), 'saved_address_use_label' => $t('checkout_saved_address_use'),
        'country_label' => $t('country'), 'country_options' => array_map(static fn(string $code, string $name): array => ['code' => $code, 'name' => $name, 'selected' => $code === $country],
            array_keys($state['countries']), $state['countries']),
        'coupon_label' => $t('coupon_code'), 'coupon_apply_label' => $t('coupon_apply'), 'coupon_remove_label' => $t('coupon_remove'),
        'coupon_code' => $couponCode, 'coupon_error' => $couponError,
        'coupon_applied' => $couponDiscount > 0 ? sprintf($t('coupon_applied'), $couponCode, $price($couponDiscount)) : '',
    ] + CheckoutService::pickupView($shippingRates, $selectedRate, $country, $state['values']['zip'], $form('pickup_point')) + CheckoutService::companyLabels());
}

/**
 * Places the order and redirects. Returns ['page' => html] when the created order must be paid through a
 * link instead, or ['error' => reason] when no order was created.
 * @return array{page: string}|array{error: string}
 */
function processCheckout(): array {
    if (!Security::csrfValidate()) return ['error' => 'CSRF token mismatch.'];
    if (!Security::rateLimit('checkout', 10)
        || !Security::rateLimit('checkout_global', 200, 60, false)
        || !Security::rateLimit('checkout_session_' . hash('sha256', session_id()), 5, 900, false)) {
        return ['error' => 'Príliš veľa pokusov o objednávku. Skúste to neskôr.'];
    }

    $input = $_POST;
    $input['consents'] = array_map(static fn($value): bool => $value === '1', is_array($input['consents'] ?? null) ? $input['consents'] : []);
    $paymentInput = $input['payment'] ?? 'cod';
    $input['payment_method'] = Security::clean(is_string($paymentInput) ? $paymentInput : 'cod');
    $result = CheckoutService::execute($input, require dirname(__DIR__, 3) . '/config.php');
    if (!empty($result['success'])) {
        // A redirect outside form-action is dropped silently by the browser; offer a link instead.
        $pending = !empty($result['payment_url']) && !Security::formActionAllows((string)$result['payment_url'])
            ? CheckoutSession::pendingPayment() : null;
        if ($pending) {
            error_log('Checkout payment origin missing from CSP form-action: ' . (string)parse_url((string)$result['payment_url'], PHP_URL_HOST));
            return ['page' => renderPendingPayment($pending, false)];
        }
        $target = !empty($result['payment_url'])
            ? (string)$result['payment_url']
            : Template::storeUrl('/?page=order-done&order=' . rawurlencode((string)$result['order_number'])
                . '&confirmation_token=' . rawurlencode((string)$result['confirmation_token']));
        header('Location: ' . $target);
        exit;
    }

    global $t;
    if (!empty($result['consent_errors'])) return ['error' => $t('checkout.consent_required')];
    return ['error' => (string)($result['error'] ?? '') ?: 'Chyba pri spracovaní objednávky. Skúste to znova.'];
}

function renderOrderDone(string $orderNum): string {
    global $t;
    $order = $orderNum !== '' ? Orders::byNumber($orderNum) : null;
    $confirmationInput = $_GET['confirmation_token'] ?? '';
    $confirmationToken = Security::clean(is_string($confirmationInput) ? $confirmationInput : '');
    if ($order && !CheckoutSession::verifyConfirmation((int)$order['id'], $confirmationToken, CheckoutSession::browserSessionId())) $order = null;
    $payment = null;
    $payUrl = '';
    if ($order) {
        $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? '{}'), true) ?: [];
        $status = (string)($order['payment_status'] ?? 'pending');
        // Back from the gateway: a declined payment can be retried; otherwise the payment is
        // done or being confirmed by the webhook and must not be offered again.
        $pending = CheckoutSession::pendingPayment();
        if ($pending && (int)$pending['order_id'] === (int)$order['id'] && $status === 'failed') {
            $payUrl = (string)$pending['url'];
        } else {
            CheckoutSession::forgetPendingPayment((int)$order['id']);
        }
        $transactions = PaymentService::transactionsForOrder((int)$order['id']);
        $lastTransaction = $transactions ? end($transactions) : null;
        $payment = [
            'status_class' => preg_replace('/[^a-z0-9_-]/i', '', $status), 'status' => $status,
            'label' => (string)($snapshot['label'] ?? $snapshot['name'] ?? $order['payment_method'] ?? ''),
            'reference' => $lastTransaction ? (string)($lastTransaction['provider_ref'] ?? '') : '',
        ];
    }
    return Template::render('storefront/order-done', [
        'title' => $t('order_sent'), 'order_number_label' => $t('order_num'), 'order_number' => $orderNum,
        'payment' => $payment, 'pay_url' => $payUrl, 'pay_label' => $t('checkout_pay_now'),
        'bank_transfer_html' => $order ? BankTransferInstructions::html($order) : '',
        'email_message' => $t('order_email'), 'home_url' => Template::storeUrl('/'), 'continue_label' => $t('continue_shopping'),
    ]);
}

/** Created but unpaid order of this browser, with a link back to its hosted payment page. */
function renderPendingPayment(array $pending, bool $cancelled): string {
    global $t;
    return Template::render('storefront/checkout-pending', [
        'breadcrumbs' => [
            ['url' => Template::storeUrl('/'), 'label' => $t('home'), 'current' => false],
            ['url' => '', 'label' => $t('checkout_title'), 'current' => true],
        ],
        'cancelled_message' => $cancelled ? $t('checkout_payment_cancelled') : '',
        'message' => str_replace('{order}', (string)$pending['order_number'], $t('checkout_pending_payment')),
        'order_number' => (string)$pending['order_number'], 'pay_url' => (string)$pending['url'], 'pay_label' => $t('checkout_pay_now'),
        'home_url' => Template::storeUrl('/'), 'continue_label' => $t('continue_shopping'),
    ]);
}

/** Gateway cancel_url (also served at /checkout/cancel): the order stays and can still be paid. */
function renderCheckoutCancel(): string {
    global $t;
    $pending = CheckoutSession::pendingPayment();
    if ($pending) return renderPendingPayment($pending, true);
    return Template::render('storefront/status-message', ['message' => $t('checkout_payment_cancelled')]);
}
