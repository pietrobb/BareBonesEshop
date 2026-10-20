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

    /**
     * VAT scenario of a checkout for the buyer's country, VAT ID and delivery method. execute() charges by it and the
     * previews (cart, checkout form, shipping/payment API) price by it, so reverse charge and export show the net
     * amounts the order charges. $verifyVatId=false treats a VAT ID as unverified (no VIES call).
     * @return array{context: array, scenario: array, net: bool, vat_id_valid: bool, vat_id_status: ?string, seller_country: string, tax_provider: string}
     */
    public static function vatContext(string $country, string $vatId, string $shippingMethod, bool $verifyVatId = true): array {
        $store = StoreContext::current()->config();
        $sellerCountry = strtoupper((string)(($store['legal']['country'] ?? null) ?: 'SK'));
        $vatIdValid = false;
        $vatIdStatus = null;
        if ($vatId !== '' && $verifyVatId) {
            try {
                $vatIdStatus = (string)VatService::validateVatId($vatId, VatService::defaultValidator())['status'];
                $vatIdValid = $vatIdStatus === 'valid';
            } catch (\Throwable $e) {
                IncidentMonitor::report('checkout.vat_verification_failed', 'tax', 'warning', ['exception' => $e]);
                $vatIdStatus = 'unverified';
            }
        } elseif ($vatId !== '') {
            $vatIdStatus = 'unverified';
        }
        $digitalOnly = self::isDigitalOnly(self::availableItems(Cart::enriched()['items'] ?? []));
        // Goods collected in the seller's own store are supplied there, whatever the buyer's address.
        $rate = $digitalOnly ? null : ShippingService::selectRate($shippingMethod, $country, 0);
        $taxProviderCode = Adapters::defaultProviderCode('tax');
        $context = [
            'seller_country'        => $sellerCountry,
            'seller_vat_registered' => array_key_exists('vat_registered', $store['legal'] ?? []) ? (bool)$store['legal']['vat_registered'] : true,
            'seller_oss_enabled'    => (bool)($store['oss_enabled'] ?? false),
            'customer_country'      => vatSupplyCountry($rate, $country, $sellerCountry),
            // Reverse charge needs a VAT ID of another member state; Greece's prefix is EL.
            'customer_vat_id_valid' => $vatIdValid && (substr($vatId, 0, 2) === 'EL' ? 'GR' : substr($vatId, 0, 2)) !== $sellerCountry,
            'is_b2b'                => $vatId !== '',
            // Goods dispatched to the address back the country; a digital-only order has nothing but the shopper's claim.
            'customer_country_verified' => !$digitalOnly,
            'tax_provider'          => $taxProviderCode,
        ];
        $scenario = Adapters::tax($taxProviderCode)::resolveScenario($context);
        return ['context' => $context, 'scenario' => $scenario, 'net' => Cart::chargesNetOfDomesticVat($scenario),
            'vat_id_valid' => $vatIdValid, 'vat_id_status' => $vatIdStatus, 'seller_country' => $sellerCountry, 'tax_provider' => $taxProviderCode];
    }

    /** VAT scenario of a preview: a VAT ID is checked in VIES (cached) within a per-client budget, else it counts as unverified. */
    public static function previewVatContext(string $country, string $vatId, string $shippingMethod): array {
        $verify = $vatId !== '' && Security::rateLimit('checkout_vat_preview', 20, 600);
        return self::vatContext($country, $vatId, $shippingMethod, $verify);
    }

    /** A delivery charge (rates include domestic VAT like catalog prices) as checkout bills it under $vat (vatContext()). */
    public static function chargedShipping(int $price, array $vat, string $currency): int {
        if ($price > 0 && $vat['net']) $price = Cart::netOfDomesticVat($price, (string)$vat['seller_country'], 'standard', (string)$vat['tax_provider']);
        return Money::round($price, $currency);
    }

    /** A delivery quote (deliveryQuote/cartQuote) with every price as checkout charges it under $vat. */
    public static function chargedQuote(array $quote, array $vat, string $currency): array {
        $charge = static fn(array $rate): array => ['price' => self::chargedShipping((int)($rate['price'] ?? 0), $vat, $currency)] + $rate;
        $quote['rates'] = array_map($charge, $quote['rates'] ?? []);
        if (is_array($quote['selected'] ?? null)) $quote['selected'] = $charge($quote['selected']);
        if (isset($quote['delivery_price'])) $quote['delivery_price'] = self::chargedShipping((int)$quote['delivery_price'], $vat, $currency);
        $quote['price'] = self::chargedShipping((int)($quote['price'] ?? 0), $vat, $currency);
        return $quote;
    }

    /** Shipping shown in the summary: cash on delivery adds the carrier's COD surcharge, as checkout charges it. */
    public static function summaryShipping(array $cart, int $subtotal, string $country, string $method, ?array $payment, int $shipping, ?array $vat = null, string $currency = 'eur'): int {
        if (($payment['service_type'] ?? '') !== 'cod' || $method === '') return $shipping;
        $codQuote = self::deliveryQuote($cart, $subtotal, $country, $method, true);
        if (($codQuote['selected']['method_code'] ?? '') !== $method) return $shipping;
        return $vat !== null ? self::chargedShipping((int)$codQuote['price'], $vat, $currency) : (int)$codQuote['price'];
    }

    /** What a free-shipping promotion still charges: the carrier's COD surcharge when paying cash on delivery. */
    private static function freeShippingCharge(array $cart, int $subtotal, string $country, string $method, ?array $payment, ?array $vat, string $currency): int {
        if (($payment['service_type'] ?? '') !== 'cod' || $method === '') return 0;
        $rate = self::deliveryQuote($cart, $subtotal, $country, $method, true)['selected'] ?? null;
        $price = is_array($rate) && ($rate['method_code'] ?? '') === $method ? ShippingService::freeShippingPrice($rate) : 0;
        return $vat !== null ? self::chargedShipping($price, $vat, $currency) : $price;
    }

    /**
     * Payment methods of the checkout form, priced like execute(): on the goods after discounts plus the shipping
     * the order charges (cash on delivery adds the carrier surcharge, which a free-shipping promotion keeps).
     * $shipping is the selected rate's charged price ('' method = no rate offered, so no payment either). Under a
     * zero-rated $vat scenario (vatContext()) shipping and fixed fees lose the domestic VAT as execute() charges them.
     * @return array{methods: list<array>, selected: ?array, shipping: int}
     */
    public static function paymentPreview(array $cart, int $goods, string $country, string $currency, string $method, int $shipping, bool $freeShipping, string $postedPayment, ?int $customerId, ?array $vat = null): array {
        $methods = $method !== '' ? PaymentService::availableMethods($country, $currency, $goods + $shipping, $method, $customerId) : [];
        $selected = array_values(array_filter($methods, static fn(array $m): bool => (string)$m['code'] === $postedPayment))[0] ?? $methods[0] ?? null;
        $charged = $freeShipping ? self::freeShippingCharge($cart, $goods, $country, $method, $selected, $vat, $currency) : self::summaryShipping($cart, $goods, $country, $method, $selected, $shipping, $vat, $currency);
        if ($selected && $charged !== $shipping) {
            $selected = PaymentService::selectMethod((string)$selected['code'], $country, $currency, $goods + $charged, $method, $customerId) ?? $selected;
            $methods = array_map(static fn(array $m): array => $m['code'] === $selected['code'] ? $selected : $m, $methods);
        }
        if ($vat !== null) {
            $methods = self::chargedFees($methods, $vat);
            if ($selected) $selected = self::chargedFees([$selected], $vat)[0];
        }
        return ['methods' => $methods, 'selected' => $selected, 'shipping' => $charged];
    }

    /** Payment method quotes with the fee checkout charges under $vat (PaymentService::chargedFee). */
    public static function chargedFees(array $methods, array $vat): array {
        return array_map(static fn(array $m): array => ['fee' => PaymentService::chargedFee($m, (bool)$vat['net'], (string)$vat['seller_country'], (string)$vat['tax_provider'])] + $m, $methods);
    }

    /** The cart lines execute() orders: unavailable lines carry no weight, quantity or promotion in any preview either. */
    public static function availableItems(array $items): array {
        return array_values(array_filter($items, static fn(array $item): bool => $item['available'] ?? true));
    }

    public static function consentDefinitions(string $country, array $items): array {
        $available = self::availableItems($items);
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
    public static function pickupView(array $rates, ?array $selected, string $country, string $zip, string $postedPoint, string $search = ''): array {
        $capable = static fn(array $rate): bool => ShippingService::pickupAdapter($rate) !== null;
        // A search (town, street, point name or another postal code) replaces the address zip as the list's anchor.
        $options = $selected ? ShippingService::pickupOptions($selected, $country, $search !== '' ? $search : $zip) : ['points' => [], 'required' => false];
        if ($selected && $postedPoint !== '' && !in_array($postedPoint, array_column($options['points'], 'id'), true)) {
            $chosen = ShippingService::pickupPointById($selected, $country, $postedPoint);
            if ($chosen !== null) array_unshift($options['points'], $chosen);
        }
        $offerRefresh = !($selected && $capable($selected)) && array_filter($rates, $capable) !== [];
        return [
            'pickup_show' => $options['points'] || $options['required'] || $offerRefresh,
            'pickup_points' => array_map(static fn(array $p): array => [
                'id' => $p['id'], 'label' => $p['name'] . ($p['address'] !== '' ? ' — ' . $p['address'] : ''), 'selected' => $p['id'] === $postedPoint,
            ], $options['points']),
            'pickup_point_label' => I18n::t('pickup_point'), 'pickup_refresh_label' => I18n::t('pickup_point_refresh'),
            'pickup_search_capable' => $selected !== null && $capable($selected), 'pickup_search' => $search, 'pickup_search_label' => I18n::t('pickup_point_search'),
            'pickup_point_none' => I18n::t($options['required'] ? 'pickup_point_choose' : 'pickup_point_address'),
            'pickup_unavailable' => $options['required'] && !$options['points'] ? I18n::t('pickup_point_unavailable') : '',
        ];
    }

    /**
     * Coupon of checkout and its preview, priced after the automatic promotions ($promotionDiscount).
     * A stacking coupon applies (minimum order included) to the subtotal the promotions leave; any other
     * coupon is an alternative to them (Pricing::combineDiscounts), so it is priced on the full subtotal.
     */
    public static function couponFor(string $code, int $subtotal, int $promotionDiscount, ?string $email, string $currency): array {
        $coupon = Pricing::applyCoupon($code, $subtotal, $email);
        if ($promotionDiscount > 0 && (int)$coupon['discount'] > 0 && (string)($coupon['stacking'] ?? 'stack') === 'stack') {
            $coupon = Pricing::applyCoupon($code, max(0, $subtotal - $promotionDiscount), $email);
        }
        // A percentage coupon is charged in units the cart's currency shows (whole Kč/Ft).
        if ((int)$coupon['discount'] > 0) $coupon['discount'] = min($subtotal, Money::round((int)$coupon['discount'], $currency));
        return $coupon;
    }

    /**
     * Lang key (with a %s for the code) of a coupon refusal reason from Pricing::applyCoupon(). Unknown,
     * expired, used-up and already used codes look the same (no code-guessing oracle); the minimum order is
     * only reported for an otherwise valid code.
     */
    public static function couponErrorKey(string $error): string {
        return $error === 'Minimum order not met' ? 'coupon_min_subtotal' : 'coupon_invalid';
    }

    /** Coupon code guessing: 10 attempts per 10 minutes per client IP and per session, one budget for the coupon API and the checkout form. */
    public static function couponAttemptAllowed(): bool {
        $sessionKey = Security::sessionKey();
        return Security::rateLimit('validate_coupon', 10, 600)
            && ($sessionKey === '' || Security::rateLimit('validate_coupon_s' . $sessionKey, 10, 600, false));
    }

    /**
     * Whether the checkout form may price $code, whichever of its buttons sent it: the code this session last had
     * accepted ($_SESSION['checkout_coupon']) is free, any other spends a coupon attempt (couponAttemptAllowed()).
     */
    public static function formCouponAllowed(string $code): bool {
        return $code === '' || $code === ($_SESSION['checkout_coupon'] ?? null) || self::couponAttemptAllowed();
    }

    /**
     * Discount the checkout form shows before the order exists, with the same rules execute() applies:
     * the coupon (i18n key of the refusal reason when it gives nothing) plus automatic promotions.
     * @return array{coupon_discount: int, coupon_error: ?string, promotion_discount: int, free_shipping: bool}
     */
    public static function discountPreview(string $couponCode, int $subtotal, ?string $email, array $items, ?int $customerId): array {
        $promotionDiscount = 0;
        $currency = strtolower((string)($items[0]['currency'] ?? 'eur'));
        foreach (Pricing::autoCartRules($subtotal, $items, $customerId, $currency) as $rule) {
            $promotionDiscount += max(0, (int)($rule['discount'] ?? 0));
        }
        $couponDiscount = 0;
        $couponError = null;
        $couponStacking = 'stack';
        if ($couponCode !== '') {
            $coupon = self::couponFor($couponCode, $subtotal, $promotionDiscount, $email, $currency);
            $couponDiscount = max(0, (int)$coupon['discount']);
            $couponStacking = (string)($coupon['stacking'] ?? 'stack');
            if ($couponDiscount === 0) $couponError = self::couponErrorKey((string)($coupon['error'] ?? ''));
        }
        $combined = Pricing::combineDiscounts($couponDiscount, $couponStacking, $promotionDiscount);
        if ($couponDiscount > 0 && $combined['coupon'] === 0) $couponError = 'coupon_not_combinable';
        $couponDiscount = $combined['coupon'];
        $promotionDiscount = $combined['promotion'];
        $freeShipping = Pricing::freeShippingEarned($subtotal - min($subtotal, $couponDiscount + $promotionDiscount), $items, $customerId, $currency);
        return ['coupon_discount' => $couponDiscount, 'coupon_error' => $couponError, 'promotion_discount' => $promotionDiscount, 'free_shipping' => $freeShipping];
    }
}
