<?php
/**
 * R31: the two addresses of an order. The delivery address (orders.shipping_addr) is where the parcel goes and what
 * shipping zones, pick-up points and the VAT place of supply read. The billing address (orders.billing_addr) is who
 * the invoice is issued to; NULL means "same as delivery" (every order placed before R31), so billing() then falls
 * back to the delivery address with the order's customer name, exactly what invoices printed before.
 */
final class OrderAddress
{
    /** The buyer company keys that belong to the billing address. */
    public const COMPANY_KEYS = ['company', 'ico', 'dic', 'ic_dph'];
    /** Billing fields of the checkout form / JSON body and their maximum length. */
    public const BILLING_FIELDS = ['billing_name' => 191, 'billing_street' => 200, 'billing_city' => 100, 'billing_zip' => 20];

    /** @return array<string,mixed> the delivery address with the recipient name */
    public static function delivery(array $order): array
    {
        $address = self::decode($order['shipping_addr'] ?? null) ?? [];
        return ['name' => (string)($order['customer_name'] ?? '')] + $address;
    }

    /** @return array<string,mixed> the address the order is invoiced to, with the buyer name */
    public static function billing(array $order): array
    {
        $billing = self::decode($order['billing_addr'] ?? null);
        if ($billing === null || $billing === []) return self::delivery($order);
        if (trim((string)($billing['name'] ?? '')) === '') $billing['name'] = (string)($order['customer_name'] ?? '');
        return $billing;
    }

    /**
     * R33: the delivery address a parcel is labelled with. Billed elsewhere, the company lives on the billing address;
     * the parcel still goes to that company, so its name is added when the delivery address has none.
     * @return array<string,mixed>
     */
    public static function recipientAddress(array $order): array
    {
        $address = self::decode($order['shipping_addr'] ?? null) ?? [];
        if (trim((string)($address['company'] ?? '')) === '' && self::billingDiffers($order)) {
            $company = trim((string)(self::billing($order)['company'] ?? ''));
            if ($company !== '') $address['company'] = $company;
        }
        return $address;
    }

    /**
     * R33: where the goods are handed over, for documents and e-mails: the chosen pick-up point (Packeta), none for
     * collection at the shop, otherwise the delivery address; null also for an order with nothing to deliver. The
     * shopper's home address that a pick-up order also carries is not where it is delivered.
     * @return ?array<string,mixed>
     */
    public static function deliveryPlace(array $order): ?array
    {
        $rate = self::decode($order['shipping_rate_snapshot'] ?? null) ?? [];
        $delivery = self::delivery($order);
        $point = $rate['pickup_point'] ?? null;
        if (is_array($point) && trim((string)($point['name'] ?? '')) !== '') {
            return ['name' => trim((string)$point['name']), 'street' => trim((string)($point['address'] ?? '')), 'zip' => '', 'city' => '',
                'country' => strtoupper((string)($delivery['country'] ?? ''))];
        }
        if (($rate['carrier'] ?? '') === 'pickup') return null;
        return trim((string)($delivery['street'] ?? '')) !== '' ? $delivery : null;
    }

    public static function billingDiffers(array $order): bool
    {
        $billing = self::decode($order['billing_addr'] ?? null);
        return $billing !== null && $billing !== [];
    }

    /**
     * Address lines for screens and e-mails: name, company and its IDs, street, zip + city, country name.
     * @return list<string>
     */
    public static function lines(array $address, ?string $locale = null): array
    {
        $lines = [trim((string)($address['name'] ?? ''))];
        foreach (CheckoutService::companyLines($address) as $line) $lines[] = $line;
        $lines[] = trim((string)($address['street'] ?? $address['address'] ?? ''));
        $lines[] = trim(trim((string)($address['zip'] ?? '')) . ' ' . trim((string)($address['city'] ?? '')));
        $country = strtoupper(trim((string)($address['country'] ?? '')));
        if ($country !== '') $lines[] = $locale !== null ? ShippingService::countryNameIn($country, $locale) : ShippingService::countryName($country);
        return array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
    }

    /**
     * E-mail variables: delivery_address (lines of deliveryPlace(); empty for an order with nothing to deliver) and billing_address
     * (lines, only when the order is billed to another address).
     * @return array{delivery_address: list<string>, billing_address: list<string>}
     */
    public static function mailVars(array $order, string $locale = ''): array
    {
        $delivery = self::deliveryPlace($order);
        $loc = $locale !== '' ? $locale : null;
        return [
            'delivery_address' => $delivery !== null ? self::lines($delivery, $loc) : [],
            'billing_address' => self::billingDiffers($order) ? self::lines(self::billing($order), $loc) : [],
        ];
    }

    /**
     * The billing address a checkout submit asks for. The HTML form posts billing_different=1 with billing_name,
     * billing_street, billing_city, billing_zip and billing_country; the JSON body may send the same keys or a
     * billing_address object {name, street, city, zip, country}. Not asked for → address null (billed to delivery).
     * Lengths are checked by the caller (CheckoutService::FIELD_MAX_LENGTH covers the billing_* keys).
     * @return array{address: ?array<string,string>, error: ?string, error_code: ?string}
     */
    public static function billingFromInput(array $input): array
    {
        // R33: a JSON billing_address that is not an object (a string) was silently ignored and the order billed to
        // the delivery address; it is refused like a missing billing address.
        $object = $input['billing_address'] ?? null;
        if ($object !== null && $object !== '' && !is_array($object)) {
            return ['address' => null, 'error' => I18n::t('checkout.error.billing_address_required'), 'error_code' => 'checkout.error.billing_address_required'];
        }
        $input = self::flattenBillingInput($input);
        if (!self::billingRequested($input)) return ['address' => null, 'error' => null, 'error_code' => null];
        $text = static fn(string $key): string => is_string($input[$key] ?? null)
            ? trim(Security::str((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $input[$key]))) : '';
        $address = ['name' => $text('billing_name'), 'street' => $text('billing_street'), 'city' => $text('billing_city'),
            'zip' => $text('billing_zip'), 'country' => strtoupper($text('billing_country'))];
        if ($address['name'] === '' || $address['street'] === '' || $address['city'] === '' || $address['zip'] === '') {
            return ['address' => null, 'error' => I18n::t('checkout.error.billing_address_required'), 'error_code' => 'checkout.error.billing_address_required'];
        }
        if (!array_key_exists($address['country'], ShippingService::billingCountries())) {
            return ['address' => null, 'error' => I18n::t('checkout.error.billing_country_invalid'), 'error_code' => 'checkout.error.billing_country_invalid'];
        }
        return ['address' => $address, 'error' => null, 'error_code' => null];
    }

    /** A JSON billing_address object becomes the flat billing_* keys the form posts; flat keys win. */
    public static function flattenBillingInput(array $input): array
    {
        $object = $input['billing_address'] ?? null;
        if (!is_array($object)) return $input;
        foreach (['name', 'street', 'city', 'zip', 'country'] as $key) {
            if (!isset($input['billing_' . $key]) && array_key_exists($key, $object)) $input['billing_' . $key] = $object[$key];
        }
        if (!isset($input['billing_different'])) $input['billing_different'] = true;
        return $input;
    }

    public static function billingRequested(array $input): bool
    {
        $flag = $input['billing_different'] ?? null;
        return $flag === true || $flag === 1 || $flag === '1' || $flag === 'on' || $flag === 'true';
    }

    private static function decode(mixed $json): ?array
    {
        if (is_array($json)) return $json;
        if (!is_string($json) || trim($json) === '') return null;
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }
}
