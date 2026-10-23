<?php
/** Cart/checkout data presenters and checkout transport. Markup is theme-owned. */
function renderCart(): string {
    global $t;
    $breadcrumbs = [
        ['url' => Template::storeUrl('/'), 'label' => $t('home'), 'current' => false],
        ['url' => '', 'label' => $t('cart'), 'current' => true],
    ];
    if (!Cart::enriched()['items']) {
        return Template::render('storefront/cart-empty', [
            'breadcrumbs' => $breadcrumbs, 'title' => $t('cart_title'), 'message' => $t('cart_empty'),
            'home_url' => Template::storeUrl('/'), 'continue_label' => $t('continue_shopping'), 't' => I18n::all(),
        ]);
    }

    // Priced like checkout in the buyer's VAT scenario (default country and a signed-in company's VAT ID).
    $state = CheckoutService::formState([], false);
    ['vat' => $vat, 'cart' => $cartData, 'goods' => $goods] = CheckoutService::previewCart($state['country'], $state['values'], ''); // goods: what checkout orders; unavailable lines stay listed
    [$price, $cart] = [storefrontCartPrice($cartData), $cartData['items']];
    $subtotal = (int)$cartData['subtotal'];
    // Free shipping thresholds see the goods after automatic promotions, as checkout does (no coupon entered yet).
    $promotion = CheckoutService::discountPreview('', $subtotal, null, $goods, (int)($_SESSION['customer_id'] ?? 0) ?: null)['promotion_discount'];
    $quote = CheckoutService::chargedQuote(ShippingService::cartQuote($goods, $subtotal - min($subtotal, $promotion), $state['country']), $vat, (string)$cartData['currency']);
    $shipping = $quote['delivery_price'];
    $items = array_map(static fn(array $item): array => [
        'id' => (int)$item['product_id'], 'cart_key' => (string)$item['key'],
        'url' => Template::storeUrl('/product/' . rawurlencode((string)$item['slug'])), // canonical product path
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
        't' => I18n::all(),
    ]);
}

function renderCheckout(): string {
    global $t;
    $breadcrumbs = [
        ['url' => Template::storeUrl('/'), 'label' => $t('home'), 'current' => false],
        ['url' => Template::storeUrl('/?page=cart'), 'label' => $t('cart'), 'current' => false],
        ['url' => '', 'label' => $t('checkout_title'), 'current' => true],
    ];
    $cartData = Cart::enriched();
    if (!$cartData['items']) {
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
            't' => I18n::all(),
        ]);
    }
    // The coupon, saved-address and country buttons submit the whole checkout form (no JS needed) to re-price
    // or refill it, not to place the order. A refused order re-renders the form with every value kept.
    $posted = $_SERVER['REQUEST_METHOD'] === 'POST';
    $couponAction = $posted && is_string($_POST['coupon_action'] ?? null) ? $_POST['coupon_action'] : '';
    $formAction = $couponAction !== '' || ($posted && (isset($_POST['address_action']) || !empty($_POST['checkout_refresh'])));
    if ($formAction && !Security::csrfValidate()) {
        return Template::render('storefront/checkout-error', ['home_url' => Template::storeUrl('/'), 'home_label' => $t('home'), 'error_label' => $t('checkout_error_title'), 'message' => $t('checkout_csrf_error')]);
    }
    $placed = !$formAction && $posted && !empty($_POST['checkout']) ? processCheckout() : ['error' => ''];
    if (isset($placed['page'])) return $placed['page'];
    $checkoutError = $placed['error'];
    if ($checkoutError !== '') {
        // A refusal can drop sold-out lines from the cart: show what is left (re-read below), or only the reason.
        $cart = Cart::enriched()['items'];
        if (!$cart) return Template::render('storefront/checkout-error', ['home_url' => Template::storeUrl('/'), 'home_label' => $t('home'), 'error_label' => $t('checkout_error_title'), 'message' => $checkoutError]);
    }
    $state = CheckoutService::formState($_POST, $posted);
    $form = static fn(string $key): string => $posted && is_string($_POST[$key] ?? null) ? mb_substr(trim($_POST[$key]), 0, 64) : '';
    $country = $state['country'];
    // Priced in the order's VAT scenario: reverse charge and export show (and reach thresholds with) the net amounts charged.
    ['vat' => $vat, 'cart' => $cartData, 'goods' => $goods] = CheckoutService::previewCart($country, $state['values'], Security::clean($form('shipping_method'))); // goods: what execute() orders
    [$price, $cart] = [storefrontCartPrice($cartData), $cartData['items']];
    $subtotal = (int)$cartData['subtotal'];
    $customerId = (int)($_SESSION['customer_id'] ?? 0);

    // Same discount rules as CheckoutService::execute: coupon + automatic promotions, capped at the subtotal. A reload (GET) keeps the accepted coupon.
    $couponCode = $couponAction === 'remove' ? '' : ($posted ? $form('coupon_code') : CheckoutService::acceptedCoupon());
    $couponThrottled = !CheckoutService::formCouponAllowed($couponCode);
    $preview = CheckoutService::discountPreview($couponThrottled ? '' : $couponCode, $subtotal, Security::email($state['values']['email']) ?? null, $goods, $customerId ?: null);
    $couponDiscount = $preview['coupon_discount'];
    $couponError = $couponThrottled ? $t('coupon_too_many_attempts') : ($preview['coupon_error'] === null ? '' : sprintf($t($preview['coupon_error']), $couponCode));
    if ($couponError !== '') $couponCode = '';
    CheckoutService::rememberCoupon($preview['coupon_applied'] ? $couponCode : ''); // accepted: re-pricing it is free
    $discount = min($subtotal, $couponDiscount + $preview['promotion_discount']);

    // As at checkout: free shipping thresholds and payment fees see the goods after discounts.
    $quote = CheckoutService::chargedQuote(CheckoutService::deliveryQuote($goods, $subtotal - $discount, $country, $_POST['shipping_method'] ?? ''), $vat, (string)$cartData['currency']);
    [$shippingRates, $selectedRate] = [$quote['rates'], $quote['selected']];
    $selectedShipping = (string)($selectedRate['method_code'] ?? '');
    $payment = CheckoutService::paymentPreview($goods, $subtotal - $discount, $country, (string)$cartData['currency'], $selectedShipping, $preview['free_shipping'] ? 0 : $quote['price'], $preview['free_shipping'], $form('payment'), $customerId ?: null, $vat);
    [$paymentMethods, $selectedPayment, $shipping] = [$payment['methods'], $payment['selected'], $payment['shipping']];
    $paymentFee = $selectedPayment ? (int)$selectedPayment['fee'] : 0;
    [$freeLabel, $freeFeeLabel] = [$t('free_shipping'), $t('free')]; // a free payment fee is not "free shipping"

    return Template::render('storefront/checkout', [
        'breadcrumbs' => $breadcrumbs, 'title' => $t('checkout_title'), 'csrf_token' => Security::csrfToken(), 'address_required' => !$quote['digital'],
        'refresh_label' => $t('checkout.refresh'), 'consents' => array_values(CheckoutService::consentDefinitions($country, $cart)),
        'contact_label' => $t('contact'), 'name_label' => $t('name'), 'email_label' => $t('email'), 'phone_label' => $t('phone'),
        'address_label' => $t('address'), 'street_label' => $t('street'), 'city_label' => $t('city'), 'zip_label' => $t('zip'),
        'shipping_label' => $t('shipping'), 'shipping_options' => array_map(static fn(array $rate): array => [
            'code' => (string)$rate['method_code'], 'label' => (string)$rate['label'], 'delivery_days' => ShippingService::deliveryLabel($rate['delivery_days'] ?? ''),
            'price' => (int)$rate['price'] === 0 ? $freeLabel : $price((int)$rate['price']), 'price_cents' => (int)$rate['price'],
            'checked' => (string)$rate['method_code'] === $selectedShipping,
        ], $shippingRates),
        'shipping_unavailable' => $shippingRates ? '' : $t('shipping_unavailable'), 'free_shipping_hint' => $quote['hint'], 'payment_label' => $t('payment'),
        'payment_options' => array_map(static fn(array $method): array => [
            'code' => (string)$method['code'], 'label' => (string)$method['label'], 'description' => (string)($method['description'] ?: $method['provider']),
            'fee' => (int)$method['fee'] === 0 ? $freeFeeLabel : $price((int)$method['fee']), 'checked' => (string)$method['code'] === (string)($selectedPayment['code'] ?? ''),
            'online' => !empty($method['requires_online']),
        ], $paymentMethods, array_keys($paymentMethods)),
        'submitting_label' => $t('checkout_submitting'), 'redirecting_label' => $t('checkout_redirecting'),
        'payment_unavailable' => $paymentMethods ? '' : $t('payment_unavailable'), 'notes_label' => $t('notes'), 'notes_placeholder' => $t('notes_placeholder'),
        'submit_label' => $t('submit_order'), 'your_order_label' => $t('your_order'),
        'items' => array_map(static fn(array $item): array => ['name' => (string)$item['name'], 'quantity' => (int)$item['qty'], 'total' => $price((int)$item['total'])], $cart),
        'subtotal_cents' => $subtotal, 'vat_scenario' => (string)$vat['scenario']['scenario'], 'subtotal_label' => $t('subtotal'), 'subtotal' => $price($subtotal),
        'shipping' => $shipping === 0 ? $freeLabel : $price($shipping), 'payment_fee' => $paymentFee === 0 ? $freeFeeLabel : $price($paymentFee),
        'discount_cents' => $discount, 'discount_label' => $t('discount'), 'discount' => $discount > 0 ? $price(-$discount) : '',
        'total_price_label' => $t('total_price'), 'total' => $price($subtotal - $discount + $shipping + $paymentFee),
        'values' => $state['values'], 'checkout_error' => $checkoutError, 'saved_addresses' => $state['addresses'],
        'saved_address_label' => $t('checkout_saved_address'), 'saved_address_use_label' => $t('checkout_saved_address_use'),
        'country_label' => $t('country'), 'country_options' => array_map(static fn(string $code, string $name): array => ['code' => $code, 'name' => $name, 'selected' => $code === $country],
            array_keys($state['countries']), $state['countries']),
        'coupon_label' => $t('coupon_code'), 'coupon_apply_label' => $t('coupon_apply'), 'coupon_remove_label' => $t('coupon_remove'),
        'coupon_code' => $couponCode, 'coupon_error' => $couponError,
        'coupon_applied' => $couponDiscount > 0 ? sprintf($t('coupon_applied'), $couponCode, $price($couponDiscount)) : ($preview['coupon_applied'] ? sprintf($t('coupon_applied_free_shipping'), $couponCode) : ''),
        't' => I18n::all(),
    ] + CheckoutService::pickupView($shippingRates, $selectedRate, $country, $state['values']['zip'], $form('pickup_point'), $form('pickup_search')) + CheckoutService::companyLabels());
}

/**
 * Formatter for cart amounts: they are in the cart's (product) currency, which in a store sharing another store's
 * catalog is not the store currency. Also hands that currency to the storefront runtime config (Layout.php).
 */
function storefrontCartPrice(array $cartData): Closure {
    $currency = isset($cartData['currency']) ? (string)$cartData['currency'] : null; // absent for an empty cart
    if ($currency !== null) $GLOBALS['storefront_currency'] = $currency;
    return static fn(int $cents): string => I18n::price($cents, $currency);
}

/**
 * Places the order and redirects. Returns ['page' => html] when the created order must be paid through a
 * link instead, or ['error' => reason] when no order was created.
 * @return array{page: string}|array{error: string}
 */
function processCheckout(): array {
    global $t;
    if (!Security::csrfValidate()) return ['error' => $t('checkout_csrf_error')];
    if (!Security::checkoutAllowed()) return ['error' => $t('checkout_too_many_attempts')];

    $input = $_POST;
    $input['consents'] = array_map(static fn($value): bool => $value === '1', is_array($input['consents'] ?? null) ? $input['consents'] : []);
    $paymentInput = $input['payment'] ?? 'cod';
    $input['payment_method'] = Security::clean(is_string($paymentInput) ? $paymentInput : 'cod');
    $result = CheckoutService::execute($input, require dirname(__DIR__, 3) . '/config.php');
    if (!empty($result['success'])) {
        CheckoutService::rememberCoupon(''); // used by this order; the next checkout starts without it
        // A redirect outside form-action is dropped silently by the browser; offer a link instead.
        $pending = !empty($result['payment_url']) && !Security::formActionAllows((string)$result['payment_url'])
            ? CheckoutSession::pendingPayment() : null;
        if ($pending) {
            Logger::phpLog('Checkout payment origin missing from CSP form-action: ' . (string)parse_url((string)$result['payment_url'], PHP_URL_HOST));
            return ['page' => renderPendingPayment($pending, false)];
        }
        $target = !empty($result['payment_url'])
            ? (string)$result['payment_url']
            : Template::storeUrl('/?page=order-done&order=' . rawurlencode((string)$result['order_number'])
                . '&confirmation_token=' . rawurlencode((string)$result['confirmation_token']));
        header('Location: ' . $target);
        exit;
    }

    if (!empty($result['consent_errors'])) return ['error' => $t('checkout.consent_required')];
    return ['error' => (string)($result['error'] ?? '') ?: $t('checkout_failed')];
}

function renderOrderDone(string $orderNum): string {
    global $t;
    $order = $orderNum !== '' ? Orders::byNumber($orderNum) : null;
    $confirmationInput = $_GET['confirmation_token'] ?? '';
    $confirmationToken = Security::clean(is_string($confirmationInput) ? $confirmationInput : '');
    if ($order && !CheckoutSession::verifyConfirmation((int)$order['id'], $confirmationToken, CheckoutSession::browserSessionId())) $order = null;
    // Never echo an unverified ?order= value back: only a confirmed order shows its number.
    if (!$order) return Template::render('storefront/status-message', ['message' => $t('order_done_unverified')]);
    $payUrl = '';
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
        'status_class' => preg_replace('/[^a-z0-9_-]/i', '', $status), 'status' => OrderStatusLabels::payment($status),
        'heading' => $t('payment'), 'status_label' => $t('order_payment.status'),
        'method_label' => $t('order_payment.method'), 'reference_label' => $t('order_payment.reference'),
        'label' => (string)($snapshot['label'] ?? $snapshot['name'] ?? $order['payment_method'] ?? ''),
        'reference' => $lastTransaction ? (string)($lastTransaction['provider_ref'] ?? '') : '',
    ];
    // An online payment that failed with no way to retry here (e.g. the gateway could not start it): say so, the
    // confirmation e-mail only follows a payment.
    $paymentFailed = $status === 'failed' && $payUrl === '' && OrderWorkflow::confirmedAtPayment($order);
    return Template::render('storefront/order-done', [
        'title' => $t('order_sent'), 'order_number_label' => $t('order_num'), 'order_number' => (string)$order['order_number'],
        'payment' => $payment, 'pay_url' => $payUrl, 'pay_label' => $t('checkout_pay_now'),
        'payment_error' => $paymentFailed ? str_replace('{order}', (string)$order['order_number'], $t('order_done.payment_failed')) : '',
        'bank_transfer_html' => BankTransferInstructions::html($order),
        'email_message' => $paymentFailed ? '' : $t('order_email'), 'home_url' => Template::storeUrl('/'), 'continue_label' => $t('continue_shopping'),
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
        'home_url' => Template::storeUrl('/'), 'continue_label' => $t('continue_shopping'), 't' => I18n::all(),
    ]);
}

/** Gateway cancel_url (also served at /checkout/cancel): the order stays and can still be paid. */
function renderCheckoutCancel(): string {
    global $t;
    $pending = CheckoutSession::pendingPayment();
    if ($pending) return renderPendingPayment($pending, true);
    return Template::render('storefront/status-message', ['message' => $t('checkout_payment_cancelled')]);
}
