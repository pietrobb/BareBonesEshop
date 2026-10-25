<?php
declare(strict_types=1);
/**
 * R10 UX7: orders the admin enters (phone, e-mail, counter sale) and edits of a pending order's lines. Lines are
 * priced exactly like the storefront cart (Cart::enriched with the buyer's price list, VAT scenario, automatic
 * promotions, shipping rate, payment method and fee, financial snapshot) and reserved by Orders::create /
 * Orders::replaceItems. Coupons are not applied here; an online payment is collected with the "send payment link" e-mail.
 */
final class ManualOrder {
    /** Most lines one manual order takes. */
    public const MAX_LINES = 50;

    /**
     * "SKU quantity" per line (quantity defaults to 1; "SKU x 2", "SKU;2" and "SKU 2" all work).
     * @return array{lines: list<array{product_id:int, variant_id:?int, qty:int, sku:string}>, errors: list<string>}
     */
    public static function parseLines(string $text): array {
        $lines = [];
        $errors = [];
        foreach (preg_split('/\R/', $text) ?: [] as $raw) {
            $raw = trim($raw);
            if ($raw === '') continue;
            if (!preg_match('/^(\S+?)(?:\s*[x×;,]\s*|\s+)?(\d{1,3})?$/u', $raw, $m)) {
                $errors[] = mb_substr($raw, 0, 60);
                continue;
            }
            $sku = $m[1];
            $qty = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 1;
            $resolved = self::resolveSku($sku);
            if ($resolved === null || $qty < 1 || $qty > Cart::MAX_QTY) {
                $errors[] = $sku;
                continue;
            }
            $lines[] = $resolved + ['qty' => $qty, 'sku' => $sku];
        }
        if (count($lines) > self::MAX_LINES) $errors[] = '…';
        return ['lines' => array_slice($lines, 0, self::MAX_LINES), 'errors' => $errors];
    }

    /** The current lines of an order in the parseLines() format, for the edit form. */
    public static function linesText(array $orderItems): string {
        return implode("\n", array_map(static fn(array $item): string => $item['sku'] . ' ' . (int)$item['quantity'], $orderItems));
    }

    /** @return array{shipping: list<array{code:string, label:string}>, payment: list<array{code:string, label:string}>} */
    public static function formOptions(string $country): array {
        $shipping = [];
        foreach (ShippingService::calculateRates($country, 0, 0, false, 0, 0, StoreContext::current()->currency()) as $rate) {
            $code = (string)($rate['method_code'] ?? $rate['key'] ?? '');
            if ($code !== '') $shipping[$code] = ['code' => $code, 'label' => (string)($rate['label'] ?? $rate['name'] ?? $code)];
        }
        $payment = [];
        $stmt = Db::get()->prepare("SELECT code, name FROM payment_methods WHERE store_id = :store AND active = 1 ORDER BY sort_order, name");
        $stmt->execute([':store' => StoreContext::current()->providerId()]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $payment[] = ['code' => (string)$row['code'], 'label' => (string)$row['name']];
        return ['shipping' => array_values($shipping), 'payment' => $payment];
    }

    /**
     * Create the order. $buyer: name, email, phone, country, street, city, zip, company, ico, dic, vat_id, pickup_point,
     * shipping_method, payment_method, notes, locale. With $notify the customer gets the confirmation (offline payment)
     * or the payment link (online payment).
     * @return array{ok: bool, error: ?string, order_id?: int, detail?: string}
     */
    public static function create(array $lines, array $buyer, bool $notify): array {
        foreach (['name', 'phone', 'street', 'city', 'zip', 'company', 'notes'] as $field) {
            if (isset($buyer[$field]) && !is_string($buyer[$field])) return self::refusal('admin.order.manual.invalid');
        }
        $name = mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', Security::str($buyer['name'] ?? ''))), 0, 255);
        $email = Security::email($buyer['email'] ?? '');
        if ($name === '' || !$email) return self::refusal('admin.order.manual.name_email');
        if ($lines === []) return self::refusal('admin.order.manual.no_lines');
        $customerId = self::customerIdFor($email);
        $priced = self::priced($lines, $buyer, $customerId, true);
        if (isset($priced['error'])) return self::refusal($priced['error'], (string)($priced['detail'] ?? ''));

        $customer = [
            'customer_id' => $customerId,
            'name' => $name,
            'email' => $email,
            'phone' => mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', Security::str($buyer['phone'] ?? ''))), 0, 64),
            'address' => $priced['address'],
            'currency' => $priced['currency'],
            'locale' => self::locale((string)($buyer['locale'] ?? '')),
            'shipping_cost' => $priced['shipping_cost'],
            'payment_fee' => $priced['payment_fee'],
            'payment_method_snapshot' => $priced['payment_method_snapshot'],
            'shipping_method' => $priced['shipping_method'],
            'shipping_zone' => $priced['shipping_zone'],
            'shipping_weight_g' => $priced['shipping_weight_g'],
            'shipping_rate_snapshot' => $priced['shipping_rate_snapshot'],
            'tax_amount' => $priced['financial_snapshot']['totals']['vat'],
            'coupon_discount' => $priced['discount'],
            'financial_snapshot' => $priced['financial_snapshot'],
            'vat_scenario' => $priced['vat_scenario'],
            'notes' => is_string($buyer['notes'] ?? null) ? mb_substr(trim((string)preg_replace('/[^\P{C}\n\t]/u', '', $buyer['notes'])), 0, 2000) : '',
        ];
        try {
            $orderId = Orders::create($priced['items'], $customer, $priced['payment_code']);
        } catch (OrderRejectedException $e) {
            return self::refusal('admin.order.edit.out_of_stock');
        }
        AuditLog::log('order', $orderId, null, ['source' => 'admin', 'admin' => Security::adminUser()], 'Manual order entered in the admin');
        if ($priced['payment_code'] === 'free') {
            PaymentService::updatePaymentStatus($orderId, 'paid');
            OrderWorkflow::transition($orderId, 'paid', 'Manual order without charge');
        }
        if ($notify) self::notify($orderId);
        return ['ok' => true, 'error' => null, 'order_id' => $orderId];
    }

    /**
     * Replace the lines of a pending order; buyer, address, shipping and payment method stay those of the order.
     * @return array{ok: bool, error: ?string, detail?: string}
     */
    public static function editItems(int $orderId, array $lines): array {
        $order = Orders::byId($orderId);
        if ($order === null) return self::refusal('admin.order.edit.not_found');
        $refusal = Orders::editRefusal($order);
        if ($refusal !== null) return self::refusal($refusal);
        if ($lines === []) return self::refusal('admin.order.manual.no_lines');
        $address = json_decode((string)($order['shipping_addr'] ?? ''), true) ?: [];
        $rate = json_decode((string)($order['shipping_rate_snapshot'] ?? ''), true) ?: [];
        $buyer = $address + [
            'vat_id' => (string)($address['ic_dph'] ?? ''),
            'shipping_method' => (string)($order['shipping_method'] ?? ''),
            'payment_method' => (string)$order['payment_method'],
            'pickup_point' => (string)($rate['pickup_point']['id'] ?? ''),
        ];
        $customerId = !empty($order['customer_id']) ? (int)$order['customer_id'] : null;
        $detail = '';
        $error = Orders::replaceItems($orderId, static function () use ($lines, $buyer, $customerId, $order, &$detail): array {
            $priced = self::priced($lines, $buyer, $customerId, false);
            if (isset($priced['error'])) {
                $detail = (string)($priced['detail'] ?? '');
                return $priced;
            }
            if (strtolower($priced['currency']) !== strtolower((string)$order['currency'])) return ['error' => 'admin.order.manual.currency'];
            return $priced;
        });
        if ($error !== null) return self::refusal($error, $detail);
        return ['ok' => true, 'error' => null];
    }

    /** The customer hears about the order: confirmation now for offline payment, else the e-mail with the payment link. */
    private static function notify(int $orderId): void {
        $order = Orders::byId($orderId);
        if ($order === null) return;
        try {
            if (PaymentLink::payable($order)) {
                PaymentLink::send($order);
            } elseif (!OrderWorkflow::confirmedAtPayment($order)) {
                JobQueue::push('email_send', ['template' => 'order_confirmation', 'to' => (string)$order['customer_email'], 'order_id' => $orderId]);
            }
        } catch (Throwable $e) {
            IncidentMonitor::report('admin.manual_order.notification_failed', 'notification', 'error', ['order_id' => $orderId, 'exception' => $e]);
        }
    }

    /**
     * Price $lines for $buyer the way checkout does. Returns ['error' => key, 'detail' => ...] or the order inputs.
     * @return array<string, mixed>
     */
    private static function priced(array $lines, array $buyer, ?int $customerId, bool $verifyVatId): array {
        if (!function_exists('shippingCartMetrics')) require_once __DIR__ . '/Http/ApiHandlers.php';
        return self::withShopperSession($lines, $customerId, static function () use ($lines, $buyer, $customerId, $verifyVatId): array {
            $country = strtoupper(trim(Security::str($buyer['country'] ?? '')));
            if (!preg_match('/^[A-Z]{2}$/', $country)) return ['error' => 'checkout.error.invalid_country'];
            $shippingMethod = Security::slug($buyer['shipping_method'] ?? '');
            $paymentMethod = Security::str($buyer['payment_method'] ?? '');
            if (!preg_match('/^[a-z0-9_-]{2,40}$/', $paymentMethod)) return ['error' => 'checkout.error.invalid_payment_method'];
            $company = CheckoutService::buyerCompany($buyer);
            if ($company['error'] !== null) return ['error' => 'admin.order.manual.company', 'detail' => (string)$company['error']];
            $vatId = $company['vat_id'];
            $vat = CheckoutService::vatContext($country, $vatId, $shippingMethod, $verifyVatId);
            $cart = Cart::enriched($vat['context']);
            $requested = [];
            foreach ($lines as $line) {
                $key = $line['variant_id'] === null ? 'p_' . $line['product_id'] : 'v_' . $line['variant_id'];
                $requested[$key] = ($requested[$key] ?? 0) + (int)$line['qty'];
            }
            $items = [];
            foreach ($cart['items'] as $item) {
                $label = (string)$item['sku'];
                if (!($item['available'] ?? true)) return ['error' => 'admin.order.manual.unavailable', 'detail' => $label];
                // The shop never sells fewer units than were asked for without saying so.
                if (!VariantAvailability::isDigitalProduct((int)$item['product_id'])
                    && (int)$item['qty'] < min(Cart::MAX_QTY, $requested[$item['key']] ?? 0)) {
                    return ['error' => 'admin.order.manual.not_enough_stock', 'detail' => $label];
                }
                $items[] = $item;
            }
            if ($items === []) return ['error' => 'admin.order.manual.no_lines'];
            if (count(array_unique(array_map(static fn(array $i): string => strtolower((string)$i['currency']), $items))) > 1) {
                return ['error' => 'checkout.error.mixed_currency'];
            }
            $currency = strtolower((string)$items[0]['currency']);
            $subtotal = array_sum(array_column($items, 'total'));
            $digitalOnly = CheckoutService::isDigitalOnly($items);
            if (!$digitalOnly) {
                foreach (['street', 'city', 'zip'] as $field) {
                    if (trim(Security::str($buyer[$field] ?? '')) === '') return ['error' => 'checkout.error.address_required'];
                }
                if (!array_key_exists($country, ShippingService::destinationCountries())) return ['error' => 'checkout.error.shipping_unavailable'];
            }

            $autoRules = Pricing::autoCartRules($subtotal, $items, $customerId, $currency);
            $promotion = 0;
            foreach ($autoRules as $rule) $promotion += max(0, (int)($rule['discount'] ?? 0));
            $promotion = Pricing::combineDiscounts(0, 'stack', $promotion)['promotion'];
            $discount = min($subtotal, $promotion);
            $freeShipping = Pricing::freeShippingEarned($subtotal - $discount, $items, $customerId, $currency);

            $metrics = shippingCartMetrics($items);
            $weight = $digitalOnly ? 0 : (int)$metrics['weight_g'];
            $rate = $digitalOnly
                ? digitalDeliveryRate()
                : ShippingService::selectRate($shippingMethod, $country, $weight, $subtotal - $discount, PaymentService::isCashOnDelivery($paymentMethod),
                    (int)$metrics['volume_cm3'], ShippingService::physicalQuantity($items), $currency);
            if (!$rate) return ['error' => 'checkout.error.shipping_unavailable'];
            if (!$digitalOnly) {
                $pickup = ShippingService::choosePickupPoint($rate, $country, trim(Security::str($buyer['zip'] ?? '')), Security::str($buyer['pickup_point'] ?? ''));
                if ($pickup['error'] !== null) return ['error' => (string)$pickup['error']];
                if ($pickup['point'] !== null) $rate['pickup_point'] = $pickup['point'];
            }
            $shippingCost = $freeShipping ? ShippingService::freeShippingPrice($rate) : (int)$rate['price'];
            $shippingCost = CheckoutService::chargedShipping($shippingCost, $vat, $currency);
            $paymentBase = max(0, $subtotal - $discount) + $shippingCost;
            if ($digitalOnly && $paymentBase === 0) {
                $paymentMethod = 'free';
                $payment = freeDigitalPayment();
            } else {
                $payment = PaymentService::selectMethod($paymentMethod, $country, $currency, $paymentBase, (string)$rate['method_code'], $customerId);
            }
            if (!$payment || ($digitalOnly && ($payment['service_type'] ?? '') === 'cod')) return ['error' => 'checkout.error.payment_unavailable'];
            $paymentFee = (int)CheckoutService::chargedFees([$payment], $vat)[0]['fee'];
            $providers = [
                'tax' => $vat['tax_provider'],
                'payment' => (string)$payment['provider'],
                'shipping' => (string)($rate['provider'] ?? $rate['carrier'] ?? 'none'),
                'mail' => Adapters::defaultProviderCode('mail'),
            ];
            if (Adapters::integration() !== null) $providers['erp'] = Adapters::defaultProviderCode('erp');
            $snapshot = Adapters::tax($vat['tax_provider'])::buildSnapshot($items, $vat['context'], [
                'discount' => $discount,
                'line_discounts' => $promotion > 0 && class_exists('Promotions') ? Promotions::lineDiscounts($autoRules, $items) : [],
                'shipping' => $shippingCost,
                'payment_fee' => $paymentFee,
                'currency' => $currency,
                'effective_date' => date('Y-m-d'),
                'providers' => $providers,
            ]);
            $text = static fn(string $field): string => $digitalOnly ? '' : mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', Security::str($buyer[$field] ?? ''))), 0, 255);
            return [
                'items' => $items,
                'currency' => $currency,
                'discount' => $discount,
                'address' => ['street' => $text('street'), 'city' => $text('city'), 'zip' => $text('zip'), 'country' => $country] + $company['fields'],
                'shipping_cost' => $shippingCost,
                'payment_fee' => $paymentFee,
                'payment_code' => $paymentMethod,
                'payment_method_snapshot' => json_encode($payment, JSON_UNESCAPED_UNICODE),
                'shipping_method' => (string)$rate['method_code'],
                'shipping_zone' => (string)($rate['zone_code'] ?? ''),
                'shipping_weight_g' => $weight,
                'shipping_rate_snapshot' => json_encode($rate, JSON_UNESCAPED_UNICODE),
                'financial_snapshot' => $snapshot,
                'vat_scenario' => $vat['scenario']['scenario'],
            ];
        });
    }

    /**
     * Run $fn with a session cart holding only $lines and the buyer as the signed-in customer (their price list and
     * promotions), then put the admin's own session back.
     */
    private static function withShopperSession(array $lines, ?int $customerId, callable $fn): array {
        $hadCart = isset($_SESSION) && array_key_exists('cart', $_SESSION);
        $hadCustomer = isset($_SESSION) && array_key_exists('customer_id', $_SESSION);
        $savedCart = $_SESSION['cart'] ?? null;
        $savedCustomer = $_SESSION['customer_id'] ?? null;
        $_SESSION['cart'] = [];
        if ($customerId !== null) $_SESSION['customer_id'] = $customerId;
        else unset($_SESSION['customer_id']);
        try {
            foreach ($lines as $line) {
                $added = Cart::addValidated((int)$line['product_id'], $line['variant_id'] === null ? null : (int)$line['variant_id'], (int)$line['qty']);
                if (!$added['ok']) return ['error' => 'admin.order.manual.unavailable', 'detail' => (string)($line['sku'] ?? '')];
            }
            return $fn();
        } finally {
            if ($hadCart) $_SESSION['cart'] = $savedCart;
            else unset($_SESSION['cart']);
            if ($hadCustomer) $_SESSION['customer_id'] = $savedCustomer;
            else unset($_SESSION['customer_id']);
        }
    }

    /** @return array{product_id:int, variant_id:?int}|null */
    private static function resolveSku(string $sku): ?array {
        $catalog = StoreContext::current()->catalogId();
        $stmt = Db::get()->prepare("SELECT v.id, v.product_id FROM variants v JOIN products p ON p.id = v.product_id
            WHERE v.sku = :sku AND p.store_id = :store LIMIT 1");
        $stmt->execute([':sku' => $sku, ':store' => $catalog]);
        $variant = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($variant) return ['product_id' => (int)$variant['product_id'], 'variant_id' => (int)$variant['id']];
        $product = Products::bySku($sku);
        return $product === null ? null : ['product_id' => (int)$product['id'], 'variant_id' => null];
    }

    private static function customerIdFor(string $email): ?int {
        $stmt = Db::get()->prepare("SELECT id FROM customers WHERE email = ? AND store_id = ? AND status = 'active'");
        $stmt->execute([Str::normalizeEmail($email), StoreContext::current()->id()]);
        $id = (int)$stmt->fetchColumn();
        return $id > 0 ? $id : null;
    }

    private static function locale(string $requested): string {
        // A shipped language (lang/<code>.php) the customer's e-mails are written in; else the store's default.
        if (preg_match('/^[a-z]{2}$/', $requested) && is_file(dirname(__DIR__) . '/lang/' . $requested . '.php')) return $requested;
        return StoreContext::current()->defaultLocale();
    }

    /** @return array{ok: false, error: string, detail: string} */
    private static function refusal(string $key, string $detail = ''): array {
        return ['ok' => false, 'error' => $key, 'detail' => $detail];
    }
}
