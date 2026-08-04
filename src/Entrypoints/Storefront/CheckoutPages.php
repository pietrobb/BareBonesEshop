<?php
/** Cart/checkout data presenters and checkout transport. Markup is theme-owned. */
function renderCart(): string {
    global $price, $t, $csrfField;
    $cartData = Cart::enriched();
    $cart = $cartData['items'];
    $breadcrumb = storefrontBreadcrumb([
        ['url' => '?', 'label' => $t('home'), 'current' => false],
        ['url' => '', 'label' => $t('cart'), 'current' => true],
    ]);
    if (!$cart) {
        return Template::render('storefront/cart-empty', [
            'breadcrumb_html' => $breadcrumb, 'title' => $t('cart_title'), 'message' => $t('cart_empty'),
            'home_url' => '?', 'continue_label' => $t('continue_shopping'),
        ]);
    }

    $subtotal = (int)$cartData['subtotal'];
    $shipping = $subtotal >= 5000 ? 0 : 499;
    $items = array_map(static fn(array $item): array => [
        'id' => (int)$item['product_id'], 'url' => '?page=product&slug=' . rawurlencode((string)$item['slug']),
        'name' => (string)$item['name'], 'sku' => (string)$item['sku'], 'price' => $price((int)$item['price']),
        'quantity' => (int)$item['qty'], 'line_total' => $price((int)$item['total']),
        'csrf_field_html' => $csrfField, 'remove_label' => $t('remove'),
    ], $cart);

    return Template::render('storefront/cart', [
        'breadcrumb_html' => $breadcrumb, 'title' => $t('cart_title'), 'items' => $items,
        'product_label' => $t('product'), 'price_label' => $t('price'), 'quantity_label' => $t('quantity'), 'total_label' => $t('total'),
        'summary_label' => $t('order_summary'), 'subtotal_label' => $t('subtotal'), 'subtotal' => $price($subtotal), 'shipping_label' => $t('shipping'),
        'shipping_html' => Template::render('storefront/cart-shipping', ['free' => $shipping === 0, 'free_label' => $t('free_shipping'), 'price' => $price($shipping)]),
        'free_over_text' => $shipping > 0 ? $t('free_over') . ' ' . $price(5000) : '',
        'total_price_label' => $t('total_price'), 'total' => $price($subtotal + $shipping), 'checkout_url' => '?page=checkout',
        'checkout_label' => $t('to_checkout'), 'csrf_field_html' => $csrfField, 'clear_label' => $t('clear_cart'),
    ]);
}

function renderCheckout(): string {
    global $price, $t, $csrfField, $storeCurrency;
    $cartData = Cart::enriched();
    $cart = $cartData['items'];
    $breadcrumb = storefrontBreadcrumb([
        ['url' => '?', 'label' => $t('home'), 'current' => false],
        ['url' => '?page=cart', 'label' => $t('cart'), 'current' => false],
        ['url' => '', 'label' => $t('checkout_title'), 'current' => true],
    ]);
    if (!$cart) {
        return Template::render('storefront/checkout-empty', [
            'breadcrumb_html' => $breadcrumb, 'message' => $t('cart_empty'), 'home_url' => '?', 'continue_label' => $t('continue_shopping'),
        ]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['checkout'])) return processCheckout();

    $subtotal = (int)$cartData['subtotal'];
    $countryInput = $_POST['country'] ?? 'SK';
    $country = strtoupper(Security::clean(is_string($countryInput) ? $countryInput : 'SK'));
    $weightG = 0;
    foreach ($cart as $item) $weightG += (int)$item['weight_g'] * (int)$item['qty'];
    $shippingRates = ShippingService::calculateRates($country, $weightG, $subtotal, false, 0);
    $shippingInput = $_POST['shipping_method'] ?? ($shippingRates[0]['method_code'] ?? '');
    $selectedShipping = Security::clean(is_string($shippingInput) ? $shippingInput : (string)($shippingRates[0]['method_code'] ?? ''));
    $selectedRate = $shippingRates[0] ?? null;
    foreach ($shippingRates as $rate) if (($rate['method_code'] ?? '') === $selectedShipping) { $selectedRate = $rate; break; }
    $shipping = $selectedRate ? (int)$selectedRate['price'] : 0;
    $customerId = (int)($_SESSION['customer_id'] ?? 0);
    $paymentMethods = $selectedRate ? PaymentService::availableMethods($country, $storeCurrency, $subtotal + $shipping, (string)$selectedRate['method_code'], $customerId ?: null) : [];
    $selectedPayment = $paymentMethods[0] ?? null;
    $paymentFee = $selectedPayment ? (int)$selectedPayment['fee'] : 0;
    $freeLabel = $t('free_shipping');

    return Template::render('storefront/checkout', [
        'breadcrumb_html' => $breadcrumb, 'title' => $t('checkout_title'), 'csrf_field_html' => $csrfField,
        'contact_label' => $t('contact'), 'name_label' => $t('name'), 'email_label' => $t('email'), 'phone_label' => $t('phone'),
        'address_label' => $t('address'), 'street_label' => $t('street'), 'city_label' => $t('city'), 'zip_label' => $t('zip'),
        'shipping_label' => $t('shipping'), 'shipping_options' => array_map(static fn(array $rate): array => [
            'code' => (string)$rate['method_code'], 'label' => (string)$rate['label'], 'delivery_days' => (string)($rate['delivery_days'] ?? ''),
            'price' => (int)$rate['price'] === 0 ? $freeLabel : $price((int)$rate['price']),
            'checked' => (string)$rate['method_code'] === $selectedShipping ? ' checked' : '',
        ], $shippingRates),
        'shipping_unavailable' => $shippingRates ? '' : $t('shipping_unavailable'), 'payment_label' => $t('payment'),
        'payment_options' => array_map(static fn(array $method, int $index): array => [
            'code' => (string)$method['code'], 'label' => (string)$method['label'], 'description' => (string)($method['description'] ?: $method['provider']),
            'fee' => (int)$method['fee'] === 0 ? $freeLabel : $price((int)$method['fee']), 'checked' => $index === 0 ? ' checked' : '',
        ], $paymentMethods, array_keys($paymentMethods)),
        'payment_unavailable' => $paymentMethods ? '' : $t('payment_unavailable'), 'notes_label' => $t('notes'), 'notes_placeholder' => $t('notes_placeholder'),
        'submit_label' => $t('submit_order'), 'your_order_label' => $t('your_order'),
        'items' => array_map(static fn(array $item): array => ['name' => (string)$item['name'], 'quantity' => (int)$item['qty'], 'total' => $price((int)$item['total'])], $cart),
        'subtotal_cents' => $subtotal, 'subtotal_label' => $t('subtotal'), 'subtotal' => $price($subtotal),
        'shipping' => $shipping === 0 ? $freeLabel : $price($shipping), 'payment_fee' => $paymentFee === 0 ? $freeLabel : $price($paymentFee),
        'total_price_label' => $t('total_price'), 'total' => $price($subtotal + $shipping + $paymentFee),
    ]);
}

function processCheckout(): string {
    if (!Security::csrfValidate()) {
        return Template::render('storefront/checkout-error', ['home_url' => '?', 'home_label' => 'Domov', 'error_label' => 'Chyba', 'message' => 'CSRF token mismatch.']);
    }
    if (!Security::rateLimit('checkout', 10)
        || !Security::rateLimit('checkout_global', 200, 60, false)
        || !Security::rateLimit('checkout_session_' . hash('sha256', session_id()), 5, 900, false)) {
        return Template::render('storefront/checkout-error', ['home_url' => '?', 'home_label' => 'Domov', 'error_label' => 'Chyba', 'message' => 'Príliš veľa pokusov o objednávku. Skúste to neskôr.']);
    }

    $input = $_POST;
    $paymentInput = $input['payment'] ?? 'cod';
    $input['payment_method'] = Security::clean(is_string($paymentInput) ? $paymentInput : 'cod');
    $result = CheckoutService::execute($input, require dirname(__DIR__, 3) . '/config.php');
    if (!empty($result['success'])) {
        $target = !empty($result['payment_url'])
            ? (string)$result['payment_url']
            : '?page=order-done&order=' . rawurlencode((string)$result['order_number'])
                . '&confirmation_token=' . rawurlencode((string)$result['confirmation_token']);
        header('Location: ' . $target);
        exit;
    }

    return Template::render('storefront/checkout-error', [
        'home_url' => '?', 'home_label' => 'Domov', 'error_label' => 'Chyba',
        'message' => (string)($result['error'] ?? 'Chyba pri spracovaní objednávky. Skúste to znova.'),
    ]);
}

function renderOrderDone(string $orderNum): string {
    global $t;
    $order = $orderNum !== '' ? Orders::byNumber($orderNum) : null;
    $confirmationInput = $_GET['confirmation_token'] ?? '';
    $confirmationToken = Security::clean(is_string($confirmationInput) ? $confirmationInput : '');
    if ($order && !CheckoutSession::verifyConfirmation((int)$order['id'], $confirmationToken, CheckoutSession::browserSessionId())) $order = null;
    $paymentSummary = '';
    if ($order) {
        $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? '{}'), true) ?: [];
        $status = (string)($order['payment_status'] ?? 'pending');
        $transactions = PaymentService::transactionsForOrder((int)$order['id']);
        $lastTransaction = $transactions ? end($transactions) : null;
        $paymentSummary = Template::render('storefront/order-payment-summary', [
            'status_class' => preg_replace('/[^a-z0-9_-]/i', '', $status), 'status' => $status,
            'label' => (string)($snapshot['label'] ?? $snapshot['name'] ?? $order['payment_method'] ?? ''),
            'reference' => $lastTransaction ? (string)($lastTransaction['provider_ref'] ?? '') : '',
        ]);
    }
    return Template::render('storefront/order-done', [
        'title' => $t('order_sent'), 'order_number_label' => $t('order_num'), 'order_number' => $orderNum,
        'payment_summary_html' => $paymentSummary, 'email_message' => $t('order_email'), 'home_url' => '?', 'continue_label' => $t('continue_shopping'),
    ]);
}
