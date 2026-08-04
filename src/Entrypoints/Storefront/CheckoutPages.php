<?php
/** Cart rendering and HTML checkout transport. */
function renderCart(): string {
    global $e, $price, $t, $csrfField;
    $cartData = Cart::enriched();
    $cart = $cartData['items'];
    ob_start();

    echo '<div class="breadcrumb"><a href="?">' . $t('home') . '</a> &rsaquo; ' . $t('cart') . '</div>';
    echo '<h1 style="margin-bottom:20px">' . $t('cart_title') . '</h1>';

    if (empty($cart)) {
        echo '<div style="text-align:center;padding:60px;background:#fff;border-radius:8px">';
        echo '<p style="font-size:1.2em;color:#555;margin-bottom:16px">' . $t('cart_empty') . '</p>';
        echo '<a href="?" style="color:#a82d5c;font-weight:600">' . $t('continue_shopping') . '</a>';
        echo '</div>';
        return ob_get_clean();
    }

    $subtotal = (int)$cartData['subtotal'];
    echo '<div style="display:grid;grid-template-columns:1fr 320px;gap:24px">';

    // Cart table
    echo '<div><table class="cart-table">';
    echo '<tr><th>' . $t('product') . '</th><th>' . $t('price') . '</th><th>' . $t('quantity') . '</th><th>' . $t('total') . '</th><th></th></tr>';
    foreach ($cart as $p) {
        $pid = (int)$p['product_id'];
        $qty = (int)$p['qty'];
        $lineTotal = (int)$p['total'];
        echo '<tr>';
        echo '<td><a href="?page=product&slug=' . $e($p['slug']) . '" class="prod-name">' . $e($p['name']) . '</a>';
        echo '<div class="prod-sku">' . $e($p['sku']) . '</div></td>';
        echo '<td>' . $price($p['price']) . '</td>';
        echo '<td><form method="post" style="display:flex;gap:4px">';
        echo $csrfField;
        echo '<input type="hidden" name="cart_action" value="update">';
        echo '<input type="hidden" name="product_id" value="' . $pid . '">';
        echo '<input type="hidden" name="redirect" value="?page=cart">';
        echo '<input type="number" name="quantity" value="' . $qty . '" min="1" max="99" class="qty-input" data-submit-on-change>';
        echo '</form></td>';
        echo '<td style="font-weight:600">' . $price($lineTotal) . '</td>';
        echo '<td><form method="post">' . $csrfField . '<input type="hidden" name="cart_action" value="remove"><input type="hidden" name="product_id" value="' . $pid . '"><input type="hidden" name="redirect" value="?page=cart">';
        echo '<button type="submit" class="btn-remove" title="' . $t('remove') . '">✕</button></form></td>';
        echo '</tr>';
    }
    echo '</table></div>';

    // Summary sidebar
    $shipping = $subtotal >= 5000 ? 0 : 499; // Free shipping over 50€
    $total = $subtotal + $shipping;

    echo '<div class="cart-summary">';
    echo '<h3>' . $t('order_summary') . '</h3>';
    echo '<div class="row"><span>' . $t('subtotal') . '</span><span>' . $price($subtotal) . '</span></div>';
    echo '<div class="row"><span>' . $t('shipping') . '</span><span>' . ($shipping === 0 ? '<span style="color:#2e7d32">' . $t('free_shipping') . '</span>' : $price($shipping)) . '</span></div>';
    if ($shipping > 0) echo '<div style="font-size:.75em;color:#888">' . $t('free_over') . ' ' . $price(5000) . '</div>';
    echo '<div class="row total-row"><span>' . $t('total_price') . '</span><span>' . $price($total) . '</span></div>';
    echo '<a href="?page=checkout" class="btn-checkout">' . $t('to_checkout') . '</a>';
    echo '<form method="post" style="margin-top:8px">' . $csrfField . '<input type="hidden" name="cart_action" value="clear"><input type="hidden" name="redirect" value="?page=cart">';
    echo '<button type="submit" style="background:none;border:none;color:#888;cursor:pointer;font-size:.85em;width:100%;text-align:center">' . $t('clear_cart') . '</button></form>';
    echo '</div>';

    echo '</div>';
    return ob_get_clean();
}

function renderCheckout(): string {
    global $e, $price, $t, $csrfField;
    $cartData = Cart::enriched();
    $cart = $cartData['items'];

    if (empty($cart)) {
        return '<div class="breadcrumb"><a href="?">' . $t('home') . '</a> &rsaquo; ' . $t('checkout_title') . '</div>
                <p>' . $t('cart_empty') . '. <a href="?" style="color:#a82d5c">' . $t('continue_shopping') . '</a></p>';
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['checkout'])) {
        return processCheckout();
    }

    $subtotal = (int)$cartData['subtotal'];
    $country = strtoupper($_POST['country'] ?? 'SK');
    $weightG = 0;
    foreach ($cart as $item) {
        $weightG += (int)$item['weight_g'] * (int)$item['qty'];
    }
    $shippingRates = ShippingService::calculateRates($country, $weightG, $subtotal, false, 0);
    $selectedShipping = $_POST['shipping_method'] ?? ($shippingRates[0]['method_code'] ?? '');
    $selectedRate = $shippingRates[0] ?? null;
    foreach ($shippingRates as $rate) {
        if (($rate['method_code'] ?? '') === $selectedShipping) { $selectedRate = $rate; break; }
    }
    $shipping = $selectedRate ? (int)$selectedRate['price'] : 0;
    $currency = StoreContext::current()->currency();
    $customerId = (int)($_SESSION['customer_id'] ?? 0);
    $paymentMethods = $selectedRate ? PaymentService::availableMethods($country, $currency, $subtotal + $shipping, (string)$selectedRate['method_code'], $customerId > 0 ? $customerId : null) : [];
    $selectedPayment = $paymentMethods[0] ?? null;
    $paymentFee = $selectedPayment ? (int)$selectedPayment['fee'] : 0;
    $total = $subtotal + $shipping + $paymentFee;

    ob_start();
    echo '<div class="breadcrumb"><a href="?">' . $t('home') . '</a> &rsaquo; <a href="?page=cart">' . $t('cart') . '</a> &rsaquo; ' . $t('checkout_title') . '</div>';
    echo '<h1 style="margin-bottom:20px">' . $t('checkout_title') . '</h1>';

    echo '<div style="display:grid;grid-template-columns:1fr 340px;gap:24px">';

    echo '<form method="post" class="checkout-form">';
    echo $csrfField . '<input type="hidden" name="checkout" value="1">';
    echo '<h3>' . $t('contact') . '</h3>';
    echo '<label for="checkout-name">' . $t('name') . '</label><input id="checkout-name" name="name" autocomplete="name" required>';
    echo '<div class="form-row"><div><label for="checkout-email">' . $t('email') . '</label><input id="checkout-email" type="email" name="email" autocomplete="email" required></div><div><label for="checkout-phone">' . $t('phone') . '</label><input id="checkout-phone" name="phone" autocomplete="tel"></div></div>';
    echo '<h3 style="margin-top:20px">' . $t('address') . '</h3>';
    echo '<label for="checkout-street">' . $t('street') . '</label><input id="checkout-street" name="street" autocomplete="street-address" required>';
    echo '<div class="form-row"><div><label for="checkout-city">' . $t('city') . '</label><input id="checkout-city" name="city" autocomplete="address-level2" required></div><div><label for="checkout-zip">' . $t('zip') . '</label><input id="checkout-zip" name="zip" autocomplete="postal-code" required></div></div>';
    echo '<label for="checkout-country">Country</label><select name="country" id="checkout-country" autocomplete="country"><option value="SK">Slovensko</option><option value="CZ">Česko</option><option value="DE">Deutschland</option><option value="AT">Österreich</option><option value="HU">Magyarország</option><option value="PL">Polska</option></select>';
    echo '<h3 style="margin-top:20px">' . $t('shipping') . '</h3><div class="shipping-options" id="shipping-options">';
    foreach ($shippingRates as $i => $rate) {
        $checked = (($rate['method_code'] ?? '') === $selectedShipping || ($i === 0 && $selectedShipping === '')) ? ' checked' : '';
        echo '<label class="shipping-option"><span><input type="radio" name="shipping_method" value="' . $e($rate['method_code']) . '"' . $checked . '> ' . $e($rate['label']) . '<small>' . $e((string)($rate['delivery_days'] ?? '')) . '</small></span><strong>' . ($rate['price'] === 0 ? $t('free_shipping') : $price((int)$rate['price'])) . '</strong></label>';
    }
    if (empty($shippingRates)) echo '<p style="color:#c62828">' . $t('shipping_unavailable') . '</p>';
    echo '</div>';
    echo '<h3 style="margin-top:20px">' . $t('payment') . '</h3><div class="payment-options" id="payment-options">';
    foreach ($paymentMethods as $i => $method) {
        echo '<label class="payment-option"><span><input type="radio" name="payment" value="' . $e($method['code']) . '"' . ($i === 0 ? ' checked' : '') . '> ' . $e($method['label']) . '<small>' . $e((string)($method['description'] ?: $method['provider'])) . '</small></span><strong>' . ((int)$method['fee'] === 0 ? $t('free_shipping') : $price((int)$method['fee'])) . '</strong></label>';
    }
    if (empty($paymentMethods)) echo '<p style="color:#c62828">' . $t('payment_unavailable') . '</p>';
    echo '</div>';
    echo '<label for="checkout-notes">' . $t('notes') . '</label><textarea id="checkout-notes" name="notes" placeholder="' . $t('notes_placeholder') . '"></textarea>';
    echo '<button class="btn-checkout" type="submit">' . $t('submit_order') . '</button>';
    echo '</form>';

    // Order summary sidebar
    echo '<div class="cart-summary">';
    echo '<h3>' . $t('your_order') . '</h3>';
    foreach ($cart as $item) {
        echo '<div class="row" style="font-size:.85em"><span>' . $e((string)$item['name']) . ' × ' . (int)$item['qty'] . '</span><span>' . $price((int)$item['total']) . '</span></div>';
    }
    echo '<input type="hidden" id="checkout-subtotal" value="' . (int)$subtotal . '">';
    echo '<div class="row" style="margin-top:8px"><span>' . $t('subtotal') . '</span><span>' . $price($subtotal) . '</span></div>';
    echo '<div class="row"><span>' . $t('shipping') . '</span><span id="summary-shipping">' . ($shipping === 0 ? $t('free_shipping') : $price($shipping)) . '</span></div>';
    echo '<div class="row"><span>' . $t('payment') . '</span><span id="summary-payment">' . ($paymentFee === 0 ? $t('free_shipping') : $price($paymentFee)) . '</span></div>';
    echo '<div class="row total-row"><span>' . $t('total_price') . '</span><span id="summary-total">' . $price($total) . '</span></div>';
    echo '</div>';

    echo '</div>';
    return ob_get_clean();
}

function processCheckout(): string {
    if (!Security::csrfValidate()) {
        return '<div class="breadcrumb"><a href="?">Domov</a> &rsaquo; Chyba</div><p style="color:#c62828">CSRF token mismatch.</p>';
    }
    if (!Security::rateLimit('checkout', 10)
        || !Security::rateLimit('checkout_global', 200, 60, false)
        || !Security::rateLimit('checkout_session_' . hash('sha256', session_id()), 5, 900, false)) {
        return '<div class="breadcrumb"><a href="?">Domov</a> &rsaquo; Chyba</div><p style="color:#c62828">Príliš veľa pokusov o objednávku. Skúste to neskôr.</p>';
    }

    $input = $_POST;
    $input['payment_method'] = Security::clean($input['payment'] ?? 'cod');
    $result = CheckoutService::execute($input, require dirname(__DIR__, 3) . '/config.php');
    if (!empty($result['success'])) {
        $target = !empty($result['payment_url'])
            ? (string)$result['payment_url']
            : '?page=order-done&order=' . rawurlencode((string)$result['order_number'])
                . '&confirmation_token=' . rawurlencode((string)$result['confirmation_token']);
        header('Location: ' . $target);
        exit;
    }

    $message = (string)($result['error'] ?? 'Chyba pri spracovaní objednávky. Skúste to znova.');
    return '<div class="breadcrumb"><a href="?">Domov</a> &rsaquo; Chyba</div><p style="color:#c62828">'
        . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
}

function renderOrderDone(string $orderNum): string {
    global $e, $price, $t;
    $order = $orderNum !== '' ? Orders::byNumber($orderNum) : null;
    $confirmationToken = Security::clean($_GET['confirmation_token'] ?? '');
    if ($order && !CheckoutSession::verifyConfirmation((int)$order['id'], $confirmationToken, CheckoutSession::browserSessionId())) {
        $order = null;
    }
    ob_start();
    echo '<div class="order-done">';
    echo '<div style="font-size:3em;margin-bottom:16px">✓</div>';
    echo '<h1>' . $t('order_sent') . '</h1>';
    echo '<p style="font-size:1.1em;color:#555;margin:12px 0">' . $t('order_num') . ': <strong>' . $e($orderNum) . '</strong></p>';
    if ($order) {
        $paymentSnapshot = json_decode((string)($order['payment_method_snapshot'] ?? '{}'), true) ?: [];
        $paymentLabel = (string)($paymentSnapshot['label'] ?? $paymentSnapshot['name'] ?? $order['payment_method'] ?? '');
        $paymentStatus = (string)($order['payment_status'] ?? 'pending');
        $paymentTxs = PaymentService::transactionsForOrder((int)$order['id']);
        $lastTx = $paymentTxs ? end($paymentTxs) : null;
        echo '<div class="payment-summary" style="margin:18px auto 24px;max-width:420px;text-align:left;background:#f8f8f8;border-radius:10px;padding:16px">';
        echo '<strong>Payment</strong><br>';
        echo 'Status: <span class="badge badge-' . $e($paymentStatus) . '">' . $e($paymentStatus) . '</span><br>';
        if ($paymentLabel !== '') echo 'Method: ' . $e($paymentLabel) . '<br>';
        if ($lastTx && !empty($lastTx['provider_ref'])) echo 'Reference: ' . $e((string)$lastTx['provider_ref']) . '<br>';
        echo '</div>';
    }
    echo '<p style="color:#888;margin-bottom:24px">' . $t('order_email') . '</p>';
    echo '<a href="?" style="color:#a82d5c;font-weight:600;font-size:1.1em">' . $t('continue_shopping') . '</a>';
    echo '</div>';
    return ob_get_clean();
}
