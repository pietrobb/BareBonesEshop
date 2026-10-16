<?php
declare(strict_types=1);
/** Canonical application boundary shared by HTML and JSON checkout transports. */
class CheckoutService {
    public static function isDigitalOnly(array $items): bool {
        if (!function_exists('cartIsDigitalOnly')) require_once __DIR__ . '/Http/ApiHandlers.php';
        return cartIsDigitalOnly($items);
    }

    public static function digitalRate(): array {
        if (!function_exists('digitalDeliveryRate')) require_once __DIR__ . '/Http/ApiHandlers.php';
        return digitalDeliveryRate();
    }

    /** Delivery quote of the checkout form: a digital-only cart gets only the free electronic delivery. */
    public static function deliveryQuote(array $cart, int $subtotal, string $country, mixed $method, bool $cod = false): array {
        if (self::isDigitalOnly(array_values(array_filter($cart, static fn(array $item): bool => $item['available'] ?? true)))) {
            $rate = self::digitalRate();
            return ['rates' => [$rate], 'selected' => $rate, 'price' => 0, 'hint' => '', 'digital' => true];
        }
        return ShippingService::cartQuote($cart, $subtotal, $country, is_string($method) ? Security::clean($method) : '', $cod) + ['digital' => false];
    }

    /** Shipping shown in the summary: cash on delivery adds the carrier's COD surcharge, as checkout charges it. */
    public static function summaryShipping(array $cart, int $subtotal, string $country, string $method, ?array $payment, int $shipping): int {
        if (($payment['service_type'] ?? '') !== 'cod' || $method === '') return $shipping;
        $codQuote = self::deliveryQuote($cart, $subtotal, $country, $method, true);
        return ($codQuote['selected']['method_code'] ?? '') === $method ? (int)$codQuote['price'] : $shipping;
    }

    /**
     * Payment methods of the checkout form, priced like execute(): on the goods after discounts plus the shipping
     * the order charges (cash on delivery adds the carrier surcharge; a free-shipping promotion keeps it at zero).
     * $shipping is the selected rate's price ('' method = no rate offered, so no payment either).
     * @return array{methods: list<array>, selected: ?array, shipping: int}
     */
    public static function paymentPreview(array $cart, int $goods, string $country, string $currency, string $method, int $shipping, bool $freeShipping, string $postedPayment, ?int $customerId): array {
        $methods = $method !== '' ? PaymentService::availableMethods($country, $currency, $goods + $shipping, $method, $customerId) : [];
        $selected = array_values(array_filter($methods, static fn(array $m): bool => (string)$m['code'] === $postedPayment))[0] ?? $methods[0] ?? null;
        $charged = $freeShipping ? 0 : self::summaryShipping($cart, $goods, $country, $method, $selected, $shipping);
        if ($selected && $charged !== $shipping) {
            $selected = PaymentService::selectMethod((string)$selected['code'], $country, $currency, $goods + $charged, $method, $customerId) ?? $selected;
            $methods = array_map(static fn(array $m): array => $m['code'] === $selected['code'] ? $selected : $m, $methods);
        }
        return ['methods' => $methods, 'selected' => $selected, 'shipping' => $charged];
    }

    public static function consentDefinitions(string $country, array $items): array {
        $available = array_filter($items, static fn(array $item): bool => $item['available'] ?? true);
        return ConsentService::definitions(['country' => $country, 'product_types' => array_values(array_unique(array_column($available, 'product_type')))]);
    }

    /**
     * The accepted abandoned-cart reminder consent of a checkout submit: the configured
     * checkout_consents entry AbandonedCarts::CONSENT_KEY, ticked (consents[cart_reminder] === true).
     * Null when it is not configured for this country or not given; the cart is then not kept for reminders.
     * @return array{version:string,text:string}|null
     */
    public static function cartReminderConsent(array $input, string $country): ?array {
        $definition = ConsentService::definitions(['country' => $country])[AbandonedCarts::CONSENT_KEY] ?? null;
        $submitted = is_array($input['consents'] ?? null) ? $input['consents'] : [];
        if ($definition === null || ($submitted[AbandonedCarts::CONSENT_KEY] ?? null) !== true) return null;
        return ['version' => (string)$definition['version'], 'text' => (string)$definition['text']];
    }

    public static function execute(array $input, ?array $runtimeConfig = null): array {
        if (!function_exists('checkoutExecute')) {
            require_once __DIR__ . '/Http/ApiHandlers.php';
        }
        foreach (['name', 'email', 'phone', 'country', 'street', 'city', 'zip', 'shipping_method', 'payment_method', 'vat_id', 'coupon_code', 'notes'] as $key) {
            if (isset($input[$key]) && !is_string($input[$key])) {
                http_response_code(422);
                return ['success' => false, 'error' => I18n::t('checkout.failed'), 'error_code' => 'checkout.invalid_input'];
            }
        }
        try {
            return checkoutExecute($input, $runtimeConfig);
        } catch (Throwable $e) {
            IncidentMonitor::report('checkout.failed', 'checkout', 'error', ['exception' => $e]);
            http_response_code(503);
            return ['success' => false, 'error' => I18n::t('checkout.failed'), 'error_code' => 'checkout.failed', 'correlation_id' => Logger::correlationId()];
        }
    }

    /**
     * Buyer company details for the invoice, from the HTML form or the JSON body: company name,
     * company ID (IČO), tax ID (DIČ) and EU VAT ID (IČ DPH). All optional, but any ID needs the name.
     * Returns the address fields the invoice reads (company, ico, dic, ic_dph) or an i18n refusal.
     * @return array{fields: array<string,string>, vat_id: string, error: ?string}
     */
    public static function buyerCompany(array $input): array {
        $text = static fn(string $key, int $max): string => is_string($input[$key] ?? null) ? mb_substr(trim(Security::str($input[$key])), 0, $max) : '';
        $company = $text('company', 255);
        $ico = strtoupper((string)preg_replace('/\s+/', '', $text('ico', 40)));
        $dic = strtoupper((string)preg_replace('/\s+/', '', $text('dic', 40)));
        $vatId = strtoupper((string)preg_replace('/[\s.-]+/', '', $text('vat_id', 40)));
        $invalid = match (true) {
            $ico !== '' && !preg_match('/^[A-Z0-9][A-Z0-9\/-]{1,19}$/', $ico) => 'checkout_ico',
            $dic !== '' && !preg_match('/^[A-Z0-9][A-Z0-9\/-]{1,19}$/', $dic) => 'checkout_dic',
            $vatId !== '' && !preg_match('/^[A-Z]{2}[A-Z0-9+*]{6,14}$/', $vatId) => 'checkout_vat_id',
            default => null,
        };
        $error = $invalid !== null ? sprintf(I18n::t('checkout_company_invalid'), I18n::t($invalid))
            : ($company === '' && ($ico . $dic . $vatId) !== '' ? I18n::t('checkout_company_required') : null);
        $fields = array_filter(['company' => $company, 'ico' => $ico, 'dic' => $dic, 'ic_dph' => $vatId], static fn(string $v): bool => $v !== '');
        return ['fields' => $error === null ? $fields : [], 'vat_id' => $error === null ? $vatId : '', 'error' => $error];
    }

    /** Company name and labelled IDs stored on an order address, for order detail screens. */
    public static function companyLines(array $address): array {
        $lines = [];
        foreach (['company' => null, 'ico' => 'checkout_ico', 'dic' => 'checkout_dic', 'ic_dph' => 'checkout_vat_id'] as $key => $label) {
            $value = trim((string)($address[$key] ?? ''));
            if ($value !== '') $lines[] = $label === null ? $value : I18n::t($label) . ': ' . $value;
        }
        return $lines;
    }

    /** Labels of the optional company part of the checkout form. */
    public static function companyLabels(): array {
        return ['company_heading' => I18n::t('checkout_company_heading'), 'company_label' => I18n::t('checkout_company'),
            'ico_label' => I18n::t('checkout_ico'), 'dic_label' => I18n::t('checkout_dic'), 'vat_id_label' => I18n::t('checkout_vat_id')];
    }

    /**
     * What the checkout form shows. A posted form keeps the shopper's values (after any refusal);
     * a fresh form starts from the signed-in customer's profile and default shipping address.
     * Picking one of the customer's own saved addresses replaces the address fields; an id of
     * another customer's address is ignored. Countries come from the store's shipping zones.
     * @return array{values: array<string,string>, country: string, countries: array<string,string>, addresses: list<array{id:int,label:string,selected:bool}>}
     */
    public static function formState(array $post, bool $posted): array {
        $values = [];
        foreach (['name' => 255, 'email' => 255, 'phone' => 64, 'street' => 255, 'city' => 255, 'zip' => 32, 'notes' => 2000,
            'company' => 255, 'ico' => 40, 'dic' => 40, 'vat_id' => 40] as $key => $max) {
            $values[$key] = $posted && is_string($post[$key] ?? null) ? mb_substr(trim($post[$key]), 0, $max) : '';
        }
        $country = $posted && is_string($post['country'] ?? null) ? strtoupper(trim($post['country'])) : '';
        $customer = Customers::current();
        $addresses = $customer ? Customers::addresses((int)$customer['id'], 'shipping') : [];
        $pickedId = $posted && ($post['address_action'] ?? '') === 'use' && is_string($post['saved_address'] ?? null) ? (int)$post['saved_address'] : 0;
        $picked = $posted ? null : ($addresses[0] ?? null);
        foreach ($addresses as $address) if ((int)$address['id'] === $pickedId) $picked = $address;
        if (!$posted && $customer) {
            // A B2B member buys for their company: its legal name and VAT ID come first, then the customer's own VAT ID.
            $profile = Customers::commerceProfile((int)$customer['id']) ?? [];
            $values = array_merge($values, ['name' => (string)$customer['name'], 'email' => (string)$customer['email'], 'phone' => (string)($customer['phone'] ?? ''),
                'company' => (string)($profile['legal_name'] ?? ''), 'ico' => (string)($profile['registration_number'] ?? ''),
                'vat_id' => (string)(($profile['vat_id'] ?? '') ?: ($customer['vat_id'] ?? ''))]);
        }
        if ($picked) {
            foreach (['name', 'phone', 'street', 'city', 'zip', 'company', 'vat_id'] as $key) {
                if (trim((string)($picked[$key] ?? '')) !== '') $values[$key] = (string)$picked[$key];
            }
            $country = strtoupper((string)$picked['country']);
        }
        $countries = ShippingService::destinationCountries();
        if (!isset($countries[$country])) {
            $storeCountry = strtoupper((string)(StoreContext::current()->legal()['country'] ?? 'SK'));
            $country = isset($countries[$storeCountry]) ? $storeCountry : (string)array_key_first($countries);
        }
        return ['values' => $values, 'country' => $country, 'countries' => $countries, 'addresses' => array_map(static fn(array $a): array => [
            'id' => (int)$a['id'], 'selected' => $picked !== null && (int)$a['id'] === (int)$picked['id'],
            'label' => implode(', ', array_filter([(string)$a['name'], (string)$a['street'], trim($a['zip'] . ' ' . $a['city']), strtoupper((string)$a['country'])], static fn(string $p): bool => $p !== '')),
        ], $addresses)];
    }

    /**
     * Pick-up point part of the checkout form for the selected rate, keeping the posted choice.
     * The refresh button re-renders the list without JS after the shopper switches carrier or zip.
     */
    public static function pickupView(array $rates, ?array $selected, string $country, string $zip, string $postedPoint): array {
        $capable = static fn(array $rate): bool => ShippingService::pickupAdapter($rate) !== null;
        $options = $selected ? ShippingService::pickupOptions($selected, $country, $zip) : ['points' => [], 'required' => false];
        $offerRefresh = !($selected && $capable($selected)) && array_filter($rates, $capable) !== [];
        return [
            'pickup_show' => $options['points'] || $options['required'] || $offerRefresh,
            'pickup_points' => array_map(static fn(array $p): array => [
                'id' => $p['id'], 'label' => $p['name'] . ($p['address'] !== '' ? ' — ' . $p['address'] : ''), 'selected' => $p['id'] === $postedPoint,
            ], $options['points']),
            'pickup_point_label' => I18n::t('pickup_point'), 'pickup_refresh_label' => I18n::t('pickup_point_refresh'),
            'pickup_point_none' => I18n::t($options['required'] ? 'pickup_point_choose' : 'pickup_point_address'),
            'pickup_unavailable' => $options['required'] && !$options['points'] ? I18n::t('pickup_point_unavailable') : '',
        ];
    }

    /**
     * Coupon of checkout and its preview, priced after the automatic promotions ($promotionDiscount).
     * A stacking coupon applies (minimum order included) to the subtotal the promotions leave; any other
     * coupon is an alternative to them (Pricing::combineDiscounts), so it is priced on the full subtotal.
     */
    public static function couponFor(string $code, int $subtotal, int $promotionDiscount, ?string $email): array {
        $coupon = Pricing::applyCoupon($code, $subtotal, $email);
        if ($promotionDiscount > 0 && (int)$coupon['discount'] > 0 && (string)($coupon['stacking'] ?? 'stack') === 'stack') {
            $coupon = Pricing::applyCoupon($code, max(0, $subtotal - $promotionDiscount), $email);
        }
        return $coupon;
    }

    /**
     * Discount the checkout form shows before the order exists, with the same rules execute() applies:
     * the coupon (i18n key of the refusal reason when it gives nothing) plus automatic promotions.
     * @return array{coupon_discount: int, coupon_error: ?string, promotion_discount: int, free_shipping: bool}
     */
    public static function discountPreview(string $couponCode, int $subtotal, ?string $email, array $items, ?int $customerId): array {
        $promotionDiscount = 0;
        $freeShipping = false;
        foreach (Pricing::autoCartRules($subtotal, $items, $customerId) as $rule) {
            if ($rule['action'] === 'free_shipping') $freeShipping = true;
            $promotionDiscount += max(0, (int)($rule['discount'] ?? 0));
        }
        $couponDiscount = 0;
        $couponError = null;
        $couponStacking = 'stack';
        if ($couponCode !== '') {
            $coupon = self::couponFor($couponCode, $subtotal, $promotionDiscount, $email);
            $couponDiscount = max(0, (int)$coupon['discount']);
            $couponStacking = (string)($coupon['stacking'] ?? 'stack');
            if ($couponDiscount === 0) $couponError = match ((string)($coupon['error'] ?? '')) {
                'Minimum order not met' => 'coupon_min_subtotal', 'Coupon expired' => 'coupon_used_up',
                'Coupon already used' => 'coupon_already_used', default => 'coupon_invalid',
            };
        }
        $combined = Pricing::combineDiscounts($couponDiscount, $couponStacking, $promotionDiscount);
        if ($couponDiscount > 0 && $combined['coupon'] === 0) $couponError = 'coupon_not_combinable';
        $couponDiscount = $combined['coupon'];
        $promotionDiscount = $combined['promotion'];
        return ['coupon_discount' => $couponDiscount, 'coupon_error' => $couponError, 'promotion_discount' => $promotionDiscount, 'free_shipping' => $freeShipping];
    }
}
