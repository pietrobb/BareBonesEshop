<?php
declare(strict_types=1);
/** Canonical application boundary shared by HTML and JSON checkout transports. */
class CheckoutService {
    /** Session key of the contact and address the shopper last posted at checkout (formState()). */
    public const DRAFT_KEY = 'checkout_draft';
    /** Longest contact and address fields a checkout accepts (characters); customer_name/email/phone are VARCHAR(191) on MySQL. */
    public const FIELD_MAX_LENGTH = ['name' => 191, 'email' => 191, 'phone' => 40, 'street' => 200, 'city' => 100, 'zip' => 20, 'company' => 255]
        + OrderAddress::BILLING_FIELDS;

    public static function isDigitalOnly(array $items): bool {
        if (!function_exists('cartIsDigitalOnly')) require_once __DIR__ . '/Http/ApiHandlers.php';
        return cartIsDigitalOnly($items);
    }

    /** R33: whether the lines hold a download — taxed in the billing country, so the form re-prices when it changes. */
    public static function hasDownload(array $items): bool {
        foreach ($items as $item) {
            if (VariantAvailability::isDigitalItem($item)) return true;
        }
        return false;
    }

    public static function digitalRate(): array {
        if (!function_exists('digitalDeliveryRate')) require_once __DIR__ . '/Http/ApiHandlers.php';
        return digitalDeliveryRate();
    }

    /**
     * Why checkout offers no payment, in words the buyer can act on (UX5): the deliveries that do have a payment method
     * when the chosen one has none (cash on delivery not with pickup, no IBAN for a transfer), else the store's e-mail.
     */
    public static function paymentUnavailableMessage(array $cart, int $goods, string $country, string $currency, string $shippingMethod, ?int $customerId): string {
        $chosen = $shippingMethod;
        $others = [];
        if ($shippingMethod !== '' && !self::isDigitalOnly($cart)) {
            foreach ((array)(self::deliveryQuote($cart, $goods, $country, $shippingMethod)['rates'] ?? []) as $rate) {
                $code = (string)($rate['method_code'] ?? '');
                if ($code === $shippingMethod) { $chosen = (string)($rate['label'] ?? $code); continue; }
                if (PaymentService::availableMethods($country, $currency, $goods + (int)($rate['price'] ?? 0), $code, $customerId) !== []) $others[] = (string)($rate['label'] ?? $code);
            }
        }
        if ($others !== []) return sprintf(I18n::t('payment_unavailable_shipping'), $chosen, implode(', ', $others));
        $email = trim((string)(StoreContext::current()->legal()['email'] ?? ''));
        return $email !== '' ? sprintf(I18n::t('payment_unavailable_contact'), $email) : I18n::t('payment_unavailable');
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
     * amounts the order charges. $verifyVatId=false treats a VAT ID as unverified (no VIES call); $knownVatIdStatus is
     * the result of an earlier check (an order being edited keeps the verification it was placed with).
     * R33: $billingCountry (the billing address, when the order is billed elsewhere) is where a download is supplied:
     * an e-service is taxed where the consumer lives or the business is established, not where a parcel goes.
     * @return array{context: array, scenario: array, net: bool, vat_id_valid: bool, vat_id_status: ?string, seller_country: string, tax_provider: string}
     */
    public static function vatContext(string $country, string $vatId, string $shippingMethod, bool $verifyVatId = true, ?string $knownVatIdStatus = null, string $billingCountry = ''): array {
        $store = StoreContext::current()->config();
        $sellerCountry = strtoupper((string)(($store['legal']['country'] ?? null) ?: 'SK'));
        $vatIdValid = false;
        $vatIdStatus = null;
        if ($vatId !== '' && $knownVatIdStatus !== null) {
            $vatIdStatus = $knownVatIdStatus;
            $vatIdValid = $knownVatIdStatus === 'valid';
        } elseif ($vatId !== '' && $verifyVatId) {
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
        $vatCart = Cart::enriched();
        $digitalOnly = self::isDigitalOnly(self::availableItems($vatCart['items'] ?? []));
        // Goods collected in the seller's own store are supplied there, whatever the buyer's address. R34: known from the
        // method itself; a quote at weight 0 missed the pickup rate of an address abroad.
        $rate = $digitalOnly ? null : ShippingService::selectRate($shippingMethod, $country, 0, 0, false, 0, 0, isset($vatCart['currency']) ? (string)$vatCart['currency'] : null);
        if (!$digitalOnly && ($rate['carrier'] ?? '') !== 'pickup' && ShippingService::isPickupMethod($shippingMethod)) $rate = ['carrier' => 'pickup'];
        $taxProviderCode = Adapters::defaultProviderCode('tax');
        $residence = strtoupper(trim($billingCountry)) ?: strtoupper($country);
        // Reverse charge needs a VAT ID of another member state; Greece's prefix is EL.
        $vatIdCountry = substr($vatId, 0, 2) === 'EL' ? 'GR' : substr($vatId, 0, 2);
        $vatIdOfSeller = $vatIdCountry === $sellerCountry;
        // R34 (low): an e-service to a business is supplied where it is established (art. 44): a valid VAT ID of another
        // member state puts a download there under reverse charge, wherever it is billed (the seller's state, outside the EU).
        if ($vatIdValid && !$vatIdOfSeller && VatService::isEuCountry($vatIdCountry)) $residence = $vatIdCountry;
        $context = [
            'seller_country'        => $sellerCountry,
            'seller_vat_registered' => array_key_exists('vat_registered', $store['legal'] ?? []) ? (bool)$store['legal']['vat_registered'] : true,
            'seller_oss_enabled'    => (bool)($store['oss_enabled'] ?? false),
            // Goods are supplied where they are delivered (or collected); a download where the buyer lives (R33).
            'customer_country'      => $digitalOnly ? $residence : vatSupplyCountry($rate, $country, $sellerCountry),
            'customer_vat_id_valid' => $vatIdValid && !$vatIdOfSeller,
            // R33: a business registered for VAT in the seller's own state is no consumer: no OSS distance sale.
            'customer_vat_id_domestic' => $vatIdValid && $vatIdOfSeller,
            'is_b2b'                => $vatId !== '',
            // Goods dispatched to the address back the country; a digital-only order has nothing but the shopper's claim.
            'customer_country_verified' => !$digitalOnly,
            // Downloads next to goods are supplied where the buyer lives: the billing country, else the delivery one.
            'digital_customer_country' => $digitalOnly ? '' : $residence,
            'tax_provider'          => $taxProviderCode,
        ];
        $scenario = Adapters::tax($taxProviderCode)::resolveScenario($context);
        return ['context' => $context, 'scenario' => $scenario, 'net' => Cart::chargesNetOfDomesticVat($scenario),
            'vat_id_valid' => $vatIdValid, 'vat_id_status' => $vatIdStatus, 'seller_country' => $sellerCountry, 'tax_provider' => $taxProviderCode];
    }

    /**
     * VAT scenario of a preview: a VAT ID is checked in VIES (cached) within a per-client budget, else it counts as
     * unverified. R34: the budget counts only lookups VIES has to answer (a cached answer is free) and running out of it
     * never answers the preview with HTTP 429: the shipping and payment previews of a business behind one office IP
     * kept failing after ~10 changes, and checkout could not be finished.
     */
    public static function previewVatContext(string $country, string $vatId, string $shippingMethod, string $billingCountry = ''): array {
        $known = $vatId !== '' ? VatService::cachedVatIdStatus($vatId) : null;
        $verify = $known === null && $vatId !== '' && Security::rateLimitAllows('checkout_vat_preview', 20, 600);
        if ($verify) Security::rateLimitRecord('checkout_vat_preview');
        if ($vatId !== '') self::rememberUncheckedVatId($vatId, $known === null && !$verify);
        return self::vatContext($country, $vatId, $shippingMethod, $verify, $known, $billingCountry);
    }

    /**
     * R35 (low): a VAT ID the previews could not check (budget used up) was shown with VAT, but the order checked it in
     * VIES and charged reverse charge (27,89 € shown, 22,68 € charged; without JS a "total changed" the buyer did not
     * cause). The session remembers it, and the order prices it as the previews did: unverified, at the total shown.
     */
    private static function rememberUncheckedVatId(string $vatId, bool $unchecked): void {
        Security::sessionStart();
        $ids = is_array($_SESSION['checkout_vat_unchecked'] ?? null) ? $_SESSION['checkout_vat_unchecked'] : [];
        unset($ids[$vatId]);
        if ($unchecked) $ids[$vatId] = true;
        $_SESSION['checkout_vat_unchecked'] = array_slice($ids, -10, null, true);
    }

    /** The status an order prices $vatId with: unverified when the previews could not check it, else null (check it). */
    public static function orderVatIdStatus(string $vatId): ?string {
        if ($vatId === '' || VatService::cachedVatIdStatus($vatId) !== null) return null;
        Security::sessionStart();
        return isset($_SESSION['checkout_vat_unchecked'][$vatId]) ? 'unverified' : null;
    }

    /**
     * The cart as an HTML preview shows it: priced in the order's VAT scenario, plus the lines checkout orders.
     * $billingCountry: the billing address's country when the order is billed elsewhere (formState()), else ''.
     * @return array{vat: array, cart: array, goods: list<array>}
     */
    public static function previewCart(string $country, array $values, string $shippingMethod, string $billingCountry = ''): array {
        $vat = self::previewVatContext($country, self::buyerCompany($values)['vat_id'], $shippingMethod, $billingCountry);
        $cart = Cart::enriched($vat['context']);
        return ['vat' => $vat, 'cart' => $cart, 'goods' => self::availableItems($cart['items'])];
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

    /** What a free-shipping promotion still charges: the carrier's COD surcharge when paying cash on delivery. */
    private static function freeShippingCharge(array $cart, int $subtotal, string $country, string $method, ?array $payment, ?array $vat, string $currency): int {
        if (($payment['service_type'] ?? '') !== 'cod' || $method === '') return 0;
        $rate = self::deliveryQuote($cart, $subtotal, $country, $method, true)['selected'] ?? null;
        $price = is_array($rate) && ($rate['method_code'] ?? '') === $method ? ShippingService::freeShippingPrice($rate) : 0;
        return $vat !== null ? self::chargedShipping($price, $vat, $currency) : $price;
    }

    /**
     * The COD surcharge as execute() charges it: a carrier rate's surcharge on its own (chargedShipping), never as the
     * difference of two rounded prices, which under a zero-rated scenario or a whole-unit currency can be a unit off.
     * A rate without a separate surcharge (adapter-priced) adds whatever its COD price adds to the carriage.
     */
    private static function codSurchargeCharge(array $cart, int $subtotal, string $country, string $method, int $shipping, ?array $vat, string $currency): int {
        $rate = self::deliveryQuote($cart, $subtotal, $country, $method, true)['selected'] ?? null;
        if (!is_array($rate) || ($rate['method_code'] ?? '') !== $method) return 0;
        if (!array_key_exists('base_price', $rate)) {
            $codPrice = $vat !== null ? self::chargedShipping((int)$rate['price'], $vat, $currency) : (int)$rate['price'];
            return max(0, $codPrice - $shipping);
        }
        $surcharge = self::rateCodSurcharge($rate, true);
        return $vat !== null ? self::chargedShipping($surcharge, $vat, $currency) : $surcharge;
    }

    /**
     * Payment methods of the checkout form, priced like execute(): on the goods after discounts plus the shipping
     * the order charges (cash on delivery adds the carrier surcharge to its fee, which a free-shipping promotion keeps).
     * $shipping is the selected rate's charged price ('' method = no rate offered, so no payment either). Under a
     * zero-rated $vat scenario (vatContext()) shipping and fixed fees lose the domestic VAT as execute() charges them.
     * @return array{methods: list<array>, selected: ?array, shipping: int}
     */
    public static function paymentPreview(array $cart, int $goods, string $country, string $currency, string $method, int $shipping, bool $freeShipping, string $postedPayment, ?int $customerId, ?array $vat = null): array {
        if ($method !== '' && $goods + $shipping === 0 && self::isDigitalOnly($cart)) {
            // As execute(): a digital order with nothing to pay is placed with the fee-less free method.
            if (!function_exists('freeDigitalPayment')) require_once __DIR__ . '/Http/ApiHandlers.php';
            $free = freeDigitalPayment();
            return ['methods' => [$free], 'selected' => $free, 'shipping' => 0];
        }
        $methods = $method !== '' ? PaymentService::availableMethods($country, $currency, $goods + $shipping, $method, $customerId) : [];
        $shipping = $freeShipping ? 0 : $shipping;
        // Cash on delivery charges one fee: the carrier rate's COD surcharge is part of the COD method's fee (priced on
        // the same amount as before), never a second, unlabelled charge inside the shipping price.
        $surcharge = 0;
        if ($method !== '') {
            $cod = ['service_type' => 'cod'];
            $surcharge = $freeShipping ? self::freeShippingCharge($cart, $goods, $country, $method, $cod, $vat, $currency) : self::codSurchargeCharge($cart, $goods, $country, $method, $shipping, $vat, $currency);
        }
        $methods = array_map(static function (array $m) use ($surcharge, $country, $currency, $goods, $shipping, $method, $customerId): array {
            if ($surcharge === 0 || ($m['service_type'] ?? '') !== 'cod') return $m;
            return (PaymentService::selectMethod((string)$m['code'], $country, $currency, $goods + $shipping + $surcharge, $method, $customerId) ?? $m) + ['cod_surcharge' => $surcharge];
        }, $methods);
        if ($vat !== null) $methods = self::chargedFees($methods, $vat);
        $methods = array_map(static fn(array $m): array => self::withCodSurcharge($m, (int)($m['cod_surcharge'] ?? 0)), $methods);
        $selected = array_values(array_filter($methods, static fn(array $m): bool => (string)$m['code'] === $postedPayment))[0] ?? $methods[0] ?? null;
        return ['methods' => $methods, 'selected' => $selected, 'shipping' => $shipping];
    }

    /** A payment quote whose fee includes the carrier's COD surcharge (already charged under the order's VAT scenario). */
    public static function withCodSurcharge(array $payment, int $surcharge): array {
        if ($surcharge <= 0) return $payment;
        return ['fee' => (int)($payment['fee'] ?? 0) + $surcharge, 'cod_surcharge' => $surcharge] + $payment;
    }

    /**
     * The carrier's COD surcharge a selected database rate carries inside its price (0 for other rates or when not
     * paying cash on delivery): checkout bills it as part of the COD payment fee, the shipping line is the carriage.
     */
    public static function rateCodSurcharge(array $rate, bool $cod): int {
        return $cod && array_key_exists('base_price', $rate) ? max(0, min((int)($rate['cod_surcharge'] ?? 0), (int)($rate['price'] ?? 0))) : 0;
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

    /** R34: a re-rendered form (recalculation, billing country, refusal) keeps the consents the buyer already ticked. */
    public static function consentsView(string $country, array $items, mixed $ticked): array {
        $ticked = is_array($ticked) ? $ticked : [];
        return array_values(array_map(static fn(array $consent): array => ['checked' => ($ticked[$consent['key'] ?? ''] ?? null) === '1'] + $consent, self::consentDefinitions($country, $items)));
    }

    /** R34: a free-shipping coupon or promotion waives every method's carriage, as execute() charges it (and the JSON rates list it). */
    public static function waiveCarriage(array $delivery, bool $free): array {
        if ($free) $delivery['rates'] = array_map(static fn(array $rate): array => ['price' => ShippingService::freeShippingPrice($rate)] + $rate, $delivery['rates']);
        return $delivery;
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
        $input = OrderAddress::flattenBillingInput($input);
        foreach (['name', 'email', 'phone', 'country', 'street', 'city', 'zip', 'shipping_method', 'payment_method', 'vat_id', 'coupon_code', 'notes',
            'billing_name', 'billing_street', 'billing_city', 'billing_zip', 'billing_country'] as $key) {
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

    /**
     * R33 (low): the company fields of an order address without an IČ DPH VIES answered is invalid: the invoice printed
     * it as the buyer's VAT registration. An unverified one (VIES unreachable) is kept: the order was charged VAT anyway.
     * R34 (low): it is marked unverified, so the invoice does not show it as a checked registration.
     */
    public static function withoutInvalidVatId(array $fields, ?string $vatIdStatus): array {
        if ($vatIdStatus === 'invalid') unset($fields['ic_dph']);
        if ($vatIdStatus === 'unverified' && isset($fields['ic_dph'])) $fields['ic_dph_unverified'] = '1';
        return $fields;
    }

    /**
     * Company name and labelled IDs stored on an order address, for order detail screens. $locale: label them in that
     * language (an order's e-mail, R33), else in the current one.
     */
    public static function companyLines(array $address, ?string $locale = null): array {
        $lines = [];
        foreach (['company' => null, 'ico' => 'checkout_ico', 'dic' => 'checkout_dic', 'ic_dph' => 'checkout_vat_id'] as $key => $label) {
            $value = trim((string)($address[$key] ?? ''));
            if ($value !== '') $lines[] = $label === null ? $value : ($locale !== null ? I18n::tIn($label, $locale) : I18n::t($label)) . ': ' . $value;
        }
        return $lines;
    }

    /** Labels of the optional company part of the checkout form. */
    public static function companyLabels(): array {
        return ['company_heading' => I18n::t('checkout_company_heading'), 'company_label' => I18n::t('checkout_company'),
            'ico_label' => I18n::t('checkout_ico'), 'dic_label' => I18n::t('checkout_dic'), 'vat_id_label' => I18n::t('checkout_vat_id')];
    }

    /**
     * R31: the billing part of the checkout form (another address than the delivery one), from formState().
     * R33: its fields are required while it is open, and a refusal ($placed from processCheckout) marks the field
     * it is about: an empty one for a missing billing address, the named one for a too long or invalid value.
     */
    public static function billingView(array $state, array $placed = []): array {
        $code = (string)($placed['error_code'] ?? '');
        $invalid = [];
        foreach (['name', 'street', 'city', 'zip'] as $key) {
            $invalid[$key] = ($placed['field'] ?? '') === 'billing_' . $key
                || ($code === 'checkout.error.billing_address_required' && trim((string)($state['values']['billing_' . $key] ?? '')) === '');
        }
        return ['billing_invalid' => $invalid, 'billing_country_invalid' => $code === 'checkout.error.billing_country_invalid',
            'delivery_address_label' => I18n::t('checkout_delivery_heading'), 'billing_heading' => I18n::t('checkout_billing_heading'),
            'billing_different_label' => I18n::t('checkout_billing_different'), 'billing_different' => $state['billing_different'],
            'billing_name_label' => I18n::t('checkout_billing_name'), 'saved_billing_addresses' => $state['billing_addresses'],
            'saved_billing_address_label' => I18n::t('checkout_saved_billing_address'),
            'billing_country_options' => array_map(static fn(string $code, string $name): array => ['code' => $code, 'name' => $name, 'selected' => $code === $state['billing_country']],
                array_keys($state['billing_countries']), $state['billing_countries'])];
    }

    /**
     * What the checkout form shows. A posted form keeps the shopper's values (after any refusal);
     * a fresh form starts from the signed-in customer's profile and default shipping address, then what the shopper
     * last posted in this session (R15 low: a reload or a trip back to the cart keeps the typed contact and address;
     * cleared when the order is placed and on logout).
     * Picking one of the customer's own saved addresses replaces the address fields; an id of
     * another customer's address is ignored. Countries come from the store's shipping zones.
     * R31: the billing part (billing_* values, billing_different, billing_country) works the same way with the
     * customer's saved billing addresses; billing countries are not limited to where the shop delivers.
     * @return array{values: array<string,string>, country: string, countries: array<string,string>, addresses: list<array{id:int,label:string,selected:bool}>,
     *     billing_different: bool, billing_country: string, billing_countries: array<string,string>, billing_addresses: list<array{id:int,label:string,selected:bool}>}
     */
    public static function formState(array $post, bool $posted, bool $remember = false): array {
        $values = [];
        foreach (['name' => 255, 'email' => 255, 'phone' => 64, 'street' => 255, 'city' => 255, 'zip' => 32, 'notes' => 2000,
            'company' => 255, 'ico' => 40, 'dic' => 40, 'vat_id' => 40,
            'billing_name' => 255, 'billing_street' => 255, 'billing_city' => 255, 'billing_zip' => 32] as $key => $max) {
            $values[$key] = $posted && is_string($post[$key] ?? null) ? mb_substr(trim($post[$key]), 0, $max) : '';
        }
        $country = $posted && is_string($post['country'] ?? null) ? strtoupper(trim($post['country'])) : '';
        $billingCountry = $posted && is_string($post['billing_country'] ?? null) ? strtoupper(trim($post['billing_country'])) : '';
        $billingDifferent = $posted && OrderAddress::billingRequested($post);
        $customer = Customers::current();
        $addresses = $customer ? Customers::addresses((int)$customer['id'], 'shipping') : [];
        $billingAddresses = $customer ? Customers::addresses((int)$customer['id'], 'billing') : [];
        $pickedId = $posted && ($post['address_action'] ?? '') === 'use' && is_string($post['saved_address'] ?? null) ? (int)$post['saved_address'] : 0;
        $picked = $posted ? null : ($addresses[0] ?? null);
        foreach ($addresses as $address) if ((int)$address['id'] === $pickedId) $picked = $address;
        // R31: a saved billing address is picked like a delivery one; a fresh form starts from the default one.
        $pickedBillingId = $posted && ($post['address_action'] ?? '') === 'use_billing' && is_string($post['saved_billing_address'] ?? null) ? (int)$post['saved_billing_address'] : 0;
        $pickedBilling = $posted ? null : ($billingAddresses[0] ?? null);
        foreach ($billingAddresses as $address) if ((int)$address['id'] === $pickedBillingId) $pickedBilling = $address;
        if (!$posted && $customer) {
            // A B2B member buys for their company: its legal name and VAT ID come first, then the customer's own VAT ID.
            $profile = Customers::commerceProfile((int)$customer['id']) ?? [];
            $values = array_merge($values, ['name' => (string)$customer['name'], 'email' => (string)$customer['email'], 'phone' => (string)($customer['phone'] ?? ''),
                'company' => (string)($profile['legal_name'] ?? ''), 'ico' => (string)($profile['registration_number'] ?? ''),
                'vat_id' => (string)(($profile['vat_id'] ?? '') ?: ($customer['vat_id'] ?? ''))]);
        }
        if ($picked) {
            foreach (['name', 'phone', 'street', 'city', 'zip', 'company', 'ico', 'dic', 'vat_id'] as $key) {
                if (trim((string)($picked[$key] ?? '')) !== '') $values[$key] = (string)$picked[$key];
            }
            $country = strtoupper((string)$picked['country']);
        }
        if ($pickedBilling) {
            foreach (['name', 'street', 'city', 'zip'] as $key) $values['billing_' . $key] = (string)($pickedBilling[$key] ?? '');
            foreach (['company', 'ico', 'dic', 'vat_id'] as $key) {
                if (trim((string)($pickedBilling[$key] ?? '')) !== '') $values[$key] = (string)$pickedBilling[$key];
            }
            $billingCountry = strtoupper((string)$pickedBilling['country']);
            $billingDifferent = true;
        }
        $draft = $_SESSION[self::DRAFT_KEY] ?? null;
        // R33: a draft belongs to who typed it. A guest's draft on a shared computer must not be mixed into the saved
        // address of a member who signs in afterwards (the guest's name and city with the member's street).
        $shopper = $customer ? (int)$customer['id'] : 0;
        if (is_array($draft) && (int)($draft['customer_id'] ?? 0) !== $shopper) $draft = null;
        if ($posted) {
            // Only a submit that passed CSRF ($remember): a forged one must not prefill every later form in the session.
            if ($remember) $_SESSION[self::DRAFT_KEY] = ['values' => $values, 'country' => $country, 'billing_country' => $billingCountry, 'billing_different' => $billingDifferent, 'customer_id' => $shopper];
        } elseif (is_array($draft)) {
            foreach ((array)($draft['values'] ?? []) as $key => $value) {
                if (array_key_exists($key, $values) && is_string($value) && $value !== '') $values[$key] = $value;
            }
            if (is_string($draft['country'] ?? null) && $draft['country'] !== '') $country = $draft['country'];
            if (is_string($draft['billing_country'] ?? null) && $draft['billing_country'] !== '') $billingCountry = $draft['billing_country'];
            if (is_bool($draft['billing_different'] ?? null)) $billingDifferent = $draft['billing_different'];
            // The picker marks a saved address only while the fields still hold it, not over a typed draft.
            $holds = static fn(?array $a, string $prefix, string $in): bool => $a !== null && strtoupper((string)$a['country']) === $in
                && array_filter(['street', 'city', 'zip'], static fn(string $k): bool => trim((string)($a[$k] ?? '')) !== trim($values[$prefix . $k])) === [];
            if (!$holds($picked, '', $country)) $picked = null;
            if (!$holds($pickedBilling, 'billing_', $billingCountry)) $pickedBilling = null;
        }
        $countries = ShippingService::destinationCountries();
        if (!isset($countries[$country])) {
            $storeCountry = strtoupper((string)(StoreContext::current()->legal()['country'] ?? 'SK'));
            $country = isset($countries[$storeCountry]) ? $storeCountry : (string)array_key_first($countries);
        }
        $billingCountries = ShippingService::billingCountries();
        if (!isset($billingCountries[$billingCountry])) $billingCountry = $country;
        $label = static fn(array $a): string => implode(', ', array_filter([(string)$a['name'], (string)$a['street'], trim($a['zip'] . ' ' . $a['city']), strtoupper((string)$a['country'])], static fn(string $p): bool => $p !== ''));
        return ['values' => $values, 'country' => $country, 'countries' => $countries, 'addresses' => array_map(static fn(array $a): array => [
            'id' => (int)$a['id'], 'selected' => $picked !== null && (int)$a['id'] === (int)$picked['id'], 'label' => $label($a),
        ], $addresses), 'billing_different' => $billingDifferent, 'billing_country' => $billingCountry, 'billing_countries' => $billingCountries,
            'billing_addresses' => array_map(static fn(array $a): array => [
                'id' => (int)$a['id'], 'selected' => $pickedBilling !== null && (int)$a['id'] === (int)$pickedBilling['id'], 'label' => $label($a),
            ], $billingAddresses)];
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
        $coupon = Pricing::applyCoupon($code, $subtotal, $email, $currency);
        if ($promotionDiscount > 0 && Pricing::couponApplies($coupon) && (string)($coupon['stacking'] ?? 'stack') === 'stack') {
            $coupon = Pricing::applyCoupon($code, max(0, $subtotal - $promotionDiscount), $email, $currency);
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

    /** The coupon code the checkout form last had accepted in this session ('' = none): a reload keeps pricing with it. */
    public static function acceptedCoupon(): string {
        $code = $_SESSION['checkout_coupon'] ?? '';
        return is_string($code) ? $code : '';
    }

    /** Remember the coupon the checkout form accepted ('' forgets it: removed, refused or used by an order). */
    public static function rememberCoupon(string $code): void {
        if ($code === '') unset($_SESSION['checkout_coupon']);
        else $_SESSION['checkout_coupon'] = $code;
    }

    /**
     * Discount the checkout form shows before the order exists, with the same rules execute() applies:
     * the coupon (i18n key of the refusal reason when it gives nothing) plus automatic promotions.
     * coupon_applied: the coupon was accepted (it may only waive the shipping, with coupon_discount 0).
     * @return array{coupon_discount: int, coupon_error: ?string, coupon_applied: bool, promotion_discount: int, free_shipping: bool}
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
        $couponFreeShipping = false;
        if ($couponCode !== '') {
            $coupon = self::couponFor($couponCode, $subtotal, $promotionDiscount, $email, $currency);
            $couponDiscount = max(0, (int)$coupon['discount']);
            $couponStacking = (string)($coupon['stacking'] ?? 'stack');
            $couponFreeShipping = !empty($coupon['free_shipping']);
            if (!Pricing::couponApplies($coupon)) $couponError = self::couponErrorKey((string)($coupon['error'] ?? ''));
        }
        $combined = Pricing::combineDiscounts($couponDiscount, $couponStacking, $promotionDiscount);
        if ($couponDiscount > 0 && $combined['coupon'] === 0) $couponError = 'coupon_not_combinable';
        $couponDiscount = $combined['coupon'];
        $promotionDiscount = $combined['promotion'];
        $freeShipping = $couponFreeShipping || Pricing::freeShippingEarned($subtotal - min($subtotal, $couponDiscount + $promotionDiscount), $items, $customerId, $currency);
        $couponApplied = $couponCode !== '' && $couponError === null;
        return ['coupon_discount' => $couponDiscount, 'coupon_error' => $couponError, 'coupon_applied' => $couponApplied, 'promotion_discount' => $promotionDiscount, 'free_shipping' => $freeShipping];
    }
}
