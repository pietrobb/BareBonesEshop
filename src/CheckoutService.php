<?php
/** Canonical application boundary shared by HTML and JSON checkout transports. */
class CheckoutService {
    public static function execute(array $input, ?array $runtimeConfig = null): array {
        if (!function_exists('checkoutExecute')) {
            require_once __DIR__ . '/Http/ApiHandlers.php';
        }
        return checkoutExecute($input, $runtimeConfig);
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
        foreach (['name' => 255, 'email' => 255, 'phone' => 64, 'street' => 255, 'city' => 255, 'zip' => 32, 'notes' => 2000] as $key => $max) {
            $values[$key] = $posted && is_string($post[$key] ?? null) ? mb_substr(trim($post[$key]), 0, $max) : '';
        }
        $country = $posted && is_string($post['country'] ?? null) ? strtoupper(trim($post['country'])) : '';
        $customer = Customers::current();
        $addresses = $customer ? Customers::addresses((int)$customer['id'], 'shipping') : [];
        $pickedId = $posted && ($post['address_action'] ?? '') === 'use' && is_string($post['saved_address'] ?? null) ? (int)$post['saved_address'] : 0;
        $picked = $posted ? null : ($addresses[0] ?? null);
        foreach ($addresses as $address) if ((int)$address['id'] === $pickedId) $picked = $address;
        if (!$posted && $customer) {
            $values = array_merge($values, ['name' => (string)$customer['name'], 'email' => (string)$customer['email'], 'phone' => (string)($customer['phone'] ?? '')]);
        }
        if ($picked) {
            foreach (['name', 'phone', 'street', 'city', 'zip'] as $key) {
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
     * Discount the checkout form shows before the order exists, with the same rules execute() applies:
     * the coupon (i18n key of the refusal reason when it gives nothing) plus automatic promotions.
     * @return array{coupon_discount: int, coupon_error: ?string, promotion_discount: int, free_shipping: bool}
     */
    public static function discountPreview(string $couponCode, int $subtotal, ?string $email, array $items, ?int $customerId): array {
        $couponDiscount = 0;
        $couponError = null;
        if ($couponCode !== '') {
            $coupon = Pricing::applyCoupon($couponCode, $subtotal, $email);
            $couponDiscount = max(0, (int)$coupon['discount']);
            if ($couponDiscount === 0) $couponError = match ((string)($coupon['error'] ?? '')) {
                'Minimum order not met' => 'coupon_min_subtotal', 'Coupon expired' => 'coupon_used_up',
                'Coupon already used' => 'coupon_already_used', default => 'coupon_invalid',
            };
        }
        $promotionDiscount = 0;
        $freeShipping = false;
        foreach (Pricing::autoCartRules($subtotal, $items, $customerId) as $rule) {
            if ($rule['action'] === 'free_shipping') $freeShipping = true;
            $promotionDiscount += max(0, (int)($rule['discount'] ?? 0));
        }
        return ['coupon_discount' => $couponDiscount, 'coupon_error' => $couponError, 'promotion_discount' => $promotionDiscount, 'free_shipping' => $freeShipping];
    }
}
