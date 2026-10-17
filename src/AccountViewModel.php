<?php
/** Structured customer-account data for theme-owned rendering. */
final class AccountViewModel
{
    /** Pages account.php renders; any other ?p= is a 404. */
    public const PAGES = ['login', 'register', 'forgot', 'reset', 'dashboard', 'orders', 'order', 'returns', 'downloads', 'addresses', 'profile', 'privacy', 'change-password'];

    public static function build(
        string $page,
        ?array $customer,
        array $supportedLocales,
        array $fieldValues,
        ?string $flash,
        ?string $flashType,
        ?array $order = null,
        array $fieldErrors = []
    ): array {
        $loggedIn = $customer !== null;
        if (!in_array($page, self::PAGES, true)) $page = 'unknown';
        $model = [
            'page' => $page,
            'is_' . str_replace('-', '_', $page) => true,
            'logged_in' => $loggedIn,
            'home_url' => Template::storeUrl('/'),
            'store_name' => (string)Template::branding()['name'],
            'action_url' => Template::storeUrl('/account.php'),
            'csrf_token' => Security::csrfToken(),
            'customer_email' => (string)($customer['email'] ?? ''),
            'flash' => $flash === null ? null : ['message' => $flash, 'ok' => $flashType === 'ok'],
            'navigation' => $loggedIn ? self::navigation($page === 'order' ? 'orders' : $page) : [],
            'values' => $fieldValues,
        ];
        if ($page === 'login') {
            $model['next'] = Security::safeLocalPath($_GET['next'] ?? $fieldValues['next'] ?? null, Template::storeUrl('/account.php'));
        } elseif ($page === 'register') {
            $model['register_token'] = Security::formTrapToken('account_register');
        } elseif ($page === 'reset') {
            $model['reset_token'] = Security::clean($_GET['token'] ?? $fieldValues['token'] ?? '');
        } elseif ($page === 'dashboard' && $customer) {
            $id = (int)$customer['id'];
            $model['welcome_name'] = (string)($customer['name'] ?: $customer['email']);
            $model['email_unverified'] = empty($customer['email_verified_at']);
            $model['order_count'] = Customers::orderCount($id);
            $model['default_address'] = Customers::defaultAddress($id, 'shipping');
        } elseif ($page === 'orders' && $customer) {
            $model['orders'] = self::orders((int)$customer['id']);
        } elseif ($page === 'order' && $customer && $order) {
            $model['order'] = self::orderDetail((int)$customer['id'], $order);
        } elseif ($page === 'returns' && $customer) {
            $model += self::returns((int)$customer['id']);
        } elseif ($page === 'downloads' && $customer) {
            $model['downloads'] = self::downloads((int)$customer['id']);
        } elseif ($page === 'addresses' && $customer) {
            $model += self::addresses((int)$customer['id'], $fieldValues, $fieldErrors);
        } elseif ($page === 'profile' && $customer) {
            $model['profile'] = $customer;
            $model['locales'] = array_map(static fn(string $locale): array => [
                'code' => $locale, 'selected' => $locale === (string)$customer['locale'],
            ], $supportedLocales);
        } elseif ($page === 'privacy') {
            // The same cookie (cc_v1, legacy cookie_consent as fallback) the banner and the marketing hooks honour.
            $consent = MarketingConsent::categories($_COOKIE);
            $model['consent'] = ['analytics' => $consent['analytics'], 'marketing' => $consent['marketing']];
        }
        return ['account' => $model, 't' => I18n::all()];
    }

    private static function navigation(string $active): array
    {
        $pages = [
            'dashboard' => 'account.dashboard', 'orders' => 'account.orders', 'returns' => 'account.returns',
            'downloads' => 'account.downloads', 'addresses' => 'account.addresses', 'profile' => 'account.profile',
            'change-password' => 'account.change_password', 'privacy' => 'account.privacy',
        ];
        $items = [];
        foreach ($pages as $page => $label) {
            $items[] = [
                'url' => Template::storeUrl('/account.php?p=' . rawurlencode($page)),
                'label' => I18n::t($label),
                'active' => $page === $active,
            ];
        }
        return $items;
    }

    private static function orders(int $customerId): array
    {
        $orders = Customers::orders($customerId, 100);
        $invoices = [];
        $ids = array_map(static fn(array $order): int => (int)$order['id'], $orders);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $statement = Db::get()->prepare("SELECT order_id, number, download_token FROM invoices WHERE order_id IN ({$placeholders}) AND status != 'cancelled' ORDER BY id DESC");
            $statement->execute($ids);
            foreach ($statement->fetchAll() as $invoice) $invoices[(int)$invoice['order_id']] = $invoice;
        }
        return array_map(static function(array $order) use ($invoices): array {
            $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? '{}'), true) ?: [];
            $invoice = $invoices[(int)$order['id']] ?? null;
            return [
                'number' => (string)$order['order_number'],
                'url' => Template::storeUrl('/account.php?p=order&id=' . (int)$order['id']),
                'status' => (string)$order['status'],
                'status_label' => self::statusLabel('order_status', (string)$order['status']),
                'payment_status' => (string)($order['payment_status'] ?? 'pending'),
                'payment_status_label' => self::statusLabel('payment_status', (string)($order['payment_status'] ?? 'pending')),
                'payment_label' => (string)($snapshot['label'] ?? $snapshot['name'] ?? $order['payment_method'] ?? ''),
                'transactions' => array_map(static fn(array $transaction): array => [
                    'date' => I18n::dateTime((string)$transaction['created_at']),
                    'status' => self::statusLabel('payment_status', (string)$transaction['status']),
                    'reference' => (string)($transaction['provider_ref'] ?? ''),
                ], PaymentService::transactionsForOrder((int)$order['id'])),
                'total' => I18n::price((int)$order['total'], (string)$order['currency']),
                'date' => I18n::dateTime((string)$order['created_at']),
                'invoice' => $invoice ? [
                    'number' => (string)$invoice['number'],
                    'url' => Template::storeUrl('/account.php?p=invoice&token=' . rawurlencode((string)$invoice['download_token'])),
                ] : null,
            ];
        }, $orders);
    }

    /** $order is already owner- and store-checked by Customers::order(). Amounts stay in the order's own currency. */
    private static function orderDetail(int $customerId, array $order): array
    {
        $id = (int)$order['id'];
        $currency = (string)$order['currency'];
        $money = static fn($cents): string => I18n::price((int)$cents, $currency);
        $payment = json_decode((string)($order['payment_method_snapshot'] ?? '{}'), true) ?: [];
        $shipping = json_decode((string)($order['shipping_rate_snapshot'] ?? '{}'), true) ?: [];
        $address = json_decode((string)($order['shipping_addr'] ?? '{}'), true) ?: [];
        $invoice = Db::get()->prepare("SELECT number, download_token FROM invoices WHERE order_id = ? AND status != 'cancelled' ORDER BY id DESC LIMIT 1");
        $invoice->execute([$id]);
        $invoice = $invoice->fetch() ?: null;
        $line = static fn(string $label, $cents): array => ['label' => I18n::t($label), 'amount' => $money($cents)];
        $totals = [$line('subtotal', $order['subtotal'])];
        if ((int)$order['discount_amount'] !== 0) $totals[] = $line('discount', -abs((int)$order['discount_amount']));
        $totals[] = $line('shipping', $order['shipping_cost']);
        if ((int)($order['payment_fee'] ?? 0) !== 0) $totals[] = $line('account.payment_fee', $order['payment_fee']);
        if ((int)$order['tax_amount'] !== 0) $totals[] = $line('tax', $order['tax_amount']);
        return [
            'id' => $id,
            'number' => (string)$order['order_number'],
            'date' => I18n::dateTime((string)$order['created_at']),
            'status' => (string)$order['status'],
            'status_label' => self::statusLabel('order_status', (string)$order['status']),
            'payment_status' => (string)($order['payment_status'] ?? 'pending'),
            'payment_status_label' => self::statusLabel('payment_status', (string)($order['payment_status'] ?? 'pending')),
            'payment_label' => (string)($payment['label'] ?? $payment['name'] ?? $order['payment_method'] ?? ''),
            'shipping_label' => (string)($shipping['name'] ?? $shipping['label'] ?? $order['shipping_method'] ?? ''),
            'tracking' => trim((string)($order['carrier'] ?? '') . ' ' . (string)($order['tracking_number'] ?? '')),
            'pickup_point' => is_array($shipping['pickup_point'] ?? null)
                ? trim((string)($shipping['pickup_point']['name'] ?? '') . ' — ' . (string)($shipping['pickup_point']['address'] ?? ''), ' —') : '',
            'address' => array_values(array_filter([
                (string)($order['customer_name'] ?? ''), (string)($address['street'] ?? ''),
                trim((string)($address['zip'] ?? '') . ' ' . (string)($address['city'] ?? '')), (string)($address['country'] ?? ''),
                ...CheckoutService::companyLines($address),
            ], static fn(string $part): bool => trim($part) !== '')),
            'items' => array_map(static fn(array $item): array => [
                'name' => (string)$item['name'], 'sku' => (string)$item['sku'], 'quantity' => (int)$item['quantity'],
                'unit_price' => $money($item['unit_price']), 'total' => $money($item['total']),
            ], Customers::orderItems($customerId, $id)),
            'totals' => $totals,
            'total' => $money($order['total']),
            'invoice' => $invoice ? [
                'number' => (string)$invoice['number'],
                'url' => Template::storeUrl('/account.php?p=invoice&token=' . rawurlencode((string)$invoice['download_token'])),
            ] : null,
            'bank_transfer_html' => BankTransferInstructions::html($order),
            'orders_url' => Template::storeUrl('/account.php?p=orders'),
            'cart_url' => Template::storeUrl('/?page=cart'),
        ];
    }

    /** Translated order_status.<code> / payment_status.<code>; an unknown code stays readable as itself. */
    private static function statusLabel(string $group, string $code): string
    {
        $key = $group . '.' . $code;
        $label = I18n::t($key);
        return $label === $key ? $code : $label;
    }

    private static function addresses(int $customerId, array $values, array $errors): array
    {
        $field = static fn(string $name, string $label, string $autocomplete, bool $required = false): array => [
            'name' => $name, 'id' => 'account-address-' . str_replace('_', '-', $name), 'label' => I18n::t($label),
            'autocomplete' => $autocomplete, 'required' => $required, 'value' => (string)($values[$name] ?? ''),
            'error' => isset($errors[$name]) ? I18n::t((string)$errors[$name]) : null,
        ];
        $country = strtoupper((string)($values['country'] ?? ''));
        if ($country === '') $country = strtoupper((string)(StoreContext::current()->legal()['country'] ?? ''));
        $type = (string)($values['type'] ?? 'shipping');
        $countries = ShippingService::destinationCountries();
        return [
            'addresses' => array_map(static fn(array $address): array => $address + [
                'type_label' => I18n::t('account.addr_type_' . ($address['type'] === 'billing' ? 'billing' : 'shipping')),
            ], Customers::addresses($customerId)),
            'address_saved' => !empty($_GET['ok']),
            'address_fields' => [
                $field('name', 'account.addr_name', 'name', true),
                $field('company', 'account.addr_company', 'organization'),
                $field('vat_id', 'account.addr_vat', 'off'),
                $field('street', 'account.addr_street', 'street-address', true),
                $field('city', 'account.addr_city', 'address-level2', true),
                $field('zip', 'account.addr_zip', 'postal-code', true),
                $field('phone', 'account.addr_phone', 'tel'),
            ],
            'address_country' => [
                'error' => isset($errors['country']) ? I18n::t((string)$errors['country']) : null,
                'options' => array_map(static fn(string $code, string $name): array => [
                    'code' => $code, 'name' => $name, 'selected' => $code === $country,
                ], array_keys($countries), $countries),
            ],
            'address_types' => array_map(static fn(string $code): array => [
                'code' => $code, 'label' => I18n::t('account.addr_type_' . $code), 'selected' => $code === $type,
            ], ['shipping', 'billing']),
            'address_default' => !empty($values['is_default']),
        ];
    }

    /** The customer's digital entitlements in this store with what is left of them. */
    private static function downloads(int $customerId): array
    {
        return array_map(static function(array $row): array {
            $limit = $row['download_limit'] === null ? null : (int)$row['download_limit'];
            return [
                'id' => (int)$row['id'],
                'label' => (string)($row['label'] ?: $row['download_name'] ?: $row['asset_key']),
                'order_number' => (string)$row['order_number'],
                'order_url' => Template::storeUrl('/account.php?p=order&id=' . (int)$row['order_id']),
                'expires' => $row['expires_at'] === null ? null : I18n::dateTime(gmdate('Y-m-d H:i:s', (int)$row['expires_at'])),
                'remaining' => $limit === null ? I18n::t('account.download_unlimited') : (string)max(0, $limit - (int)$row['download_count']),
                'available' => DigitalDelivery::downloadable($row),
            ];
        }, DigitalDelivery::forCustomer($customerId));
    }

    private static function returns(int $customerId): array
    {
        $returns = [];
        foreach (Rma::forCustomer($customerId) as $return) {
            $order = Customers::order($customerId, (int)$return['order_id']);
            $returns[] = [
                'number' => (string)$return['rma_number'],
                'order_number' => (string)($order['order_number'] ?? ('#' . $return['order_id'])),
                'status' => (string)$return['status'],
                'date' => I18n::dateTime((string)$return['created_at']),
            ];
        }
        $returnable = [];
        foreach (Customers::orders($customerId, 100) as $order) {
            if (($order['status'] ?? '') !== 'delivered') continue;
            $items = Rma::returnableItems((int)$order['id']);
            if (!$items) continue;
            $returnable[] = [
                'id' => (int)$order['id'], 'number' => (string)$order['order_number'],
                'date' => I18n::dateTime((string)$order['created_at'], false),
                'items' => array_map(static fn(array $item): array => [
                    'id' => (int)$item['id'], 'name' => (string)$item['name'], 'maximum' => (int)$item['returnable_quantity'],
                ], $items),
            ];
        }
        $message = Security::str($_GET['msg'] ?? '');
        return [
            'returns' => $returns, 'returnable_orders' => $returnable,
            'return_message' => $message === '' ? null : [
                'ok' => $message === 'ok',
                'text' => $message === 'ok' ? I18n::t('account.return_submitted') : I18n::t('account.return_error') . ': ' . $message,
            ],
        ];
    }
}
