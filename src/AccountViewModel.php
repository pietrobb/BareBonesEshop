<?php
/** Structured customer-account data for theme-owned rendering. */
final class AccountViewModel
{
    public static function build(
        string $page,
        ?array $customer,
        array $supportedLocales,
        array $fieldValues,
        ?string $flash,
        ?string $flashType
    ): array {
        $loggedIn = $customer !== null;
        $knownPages = ['login', 'register', 'forgot', 'reset', 'dashboard', 'orders', 'returns', 'addresses', 'profile', 'privacy', 'change-password'];
        if (!in_array($page, $knownPages, true)) $page = 'unknown';
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
            'navigation' => $loggedIn ? self::navigation($page) : [],
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
            $model['order_count'] = Customers::orderCount($id);
            $model['default_address'] = Customers::defaultAddress($id, 'shipping');
        } elseif ($page === 'orders' && $customer) {
            $model['orders'] = self::orders((int)$customer['id']);
        } elseif ($page === 'returns' && $customer) {
            $model += self::returns((int)$customer['id']);
        } elseif ($page === 'addresses' && $customer) {
            $model['addresses'] = Customers::addresses((int)$customer['id']);
            $model['address_saved'] = !empty($_GET['ok']);
            $model['address_fields'] = [
                ['name' => 'name', 'label' => I18n::t('account.addr_name')],
                ['name' => 'company', 'label' => I18n::t('account.addr_company')],
                ['name' => 'vat_id', 'label' => I18n::t('account.addr_vat')],
                ['name' => 'street', 'label' => I18n::t('account.addr_street')],
                ['name' => 'city', 'label' => I18n::t('account.addr_city')],
                ['name' => 'zip', 'label' => I18n::t('account.addr_zip')],
                ['name' => 'country', 'label' => I18n::t('account.addr_country')],
                ['name' => 'phone', 'label' => I18n::t('account.addr_phone')],
            ];
        } elseif ($page === 'profile' && $customer) {
            $model['profile'] = $customer;
            $model['locales'] = array_map(static fn(string $locale): array => [
                'code' => $locale, 'selected' => $locale === (string)$customer['locale'],
            ], $supportedLocales);
        } elseif ($page === 'privacy') {
            $consent = isset($_COOKIE['cookie_consent']) ? json_decode((string)$_COOKIE['cookie_consent'], true) : [];
            $model['consent'] = ['analytics' => !empty($consent['analytics']), 'marketing' => !empty($consent['marketing'])];
        }
        return ['account' => $model, 't' => I18n::all()];
    }

    private static function navigation(string $active): array
    {
        $pages = [
            'dashboard' => 'account.dashboard', 'orders' => 'account.orders', 'returns' => 'account.returns',
            'addresses' => 'account.addresses', 'profile' => 'account.profile',
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
                'status' => (string)$order['status'],
                'payment_status' => (string)($order['payment_status'] ?? 'pending'),
                'payment_label' => (string)($snapshot['label'] ?? $snapshot['name'] ?? $order['payment_method'] ?? ''),
                'transactions' => array_map(static fn(array $transaction): array => [
                    'date' => substr((string)$transaction['created_at'], 0, 16),
                    'status' => (string)$transaction['status'],
                    'reference' => (string)($transaction['provider_ref'] ?? ''),
                ], PaymentService::transactionsForOrder((int)$order['id'])),
                'total' => I18n::price((int)$order['total'], (string)$order['currency']),
                'date' => substr((string)$order['created_at'], 0, 16),
                'invoice' => $invoice ? [
                    'number' => (string)$invoice['number'],
                    'url' => Template::storeUrl('/account.php?p=invoice&token=' . rawurlencode((string)$invoice['download_token'])),
                ] : null,
            ];
        }, $orders);
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
                'date' => substr((string)$return['created_at'], 0, 16),
            ];
        }
        $returnable = [];
        foreach (Customers::orders($customerId, 100) as $order) {
            if (($order['status'] ?? '') !== 'delivered') continue;
            $items = Rma::returnableItems((int)$order['id']);
            if (!$items) continue;
            $returnable[] = [
                'id' => (int)$order['id'], 'number' => (string)$order['order_number'],
                'date' => substr((string)$order['created_at'], 0, 10),
                'items' => array_map(static fn(array $item): array => [
                    'id' => (int)$item['id'], 'name' => (string)$item['name'], 'maximum' => (int)$item['returnable_quantity'],
                ], $items),
            ];
        }
        $message = (string)($_GET['msg'] ?? '');
        return [
            'returns' => $returns, 'returnable_orders' => $returnable,
            'return_message' => $message === '' ? null : [
                'ok' => $message === 'ok',
                'text' => $message === 'ok' ? I18n::t('account.return_submitted') : I18n::t('account.return_error') . ': ' . $message,
            ],
        ];
    }
}
