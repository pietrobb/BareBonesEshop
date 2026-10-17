<?php
/**
 * Route groups of the single JSON API under /api/v1:
 *   /api/v1/storefront/<action>           browser actions (session, CSRF on writes), extension actions <code>.<name>
 *   /api/v1/options/<name>                option lists for BBF forms (options_from)
 *   /api/v1/webhooks/payment/<provider>   payment notifications (verified by the gateway signature/API)
 *   /api/v1/webhooks/shipping/<carrier>   carrier tracking notifications
 *   /api/v1/csp-report                    browser CSP violation reports
 * Any other path is the external machine API (API key, ApiV1).
 * Legacy for one release: /api.php with action=<webhook or CSP action> (notification URLs stored before
 * the upgrade) and /api/v1/storefront with action=<action> (storefront.js cached from before the upgrade).
 */
class ApiRoute {
    public const PREFIX = '/api/v1';

    /** Actions that have their own route group and are not reachable as /storefront/<action>. */
    private const NOT_STOREFRONT = ['webhook_payment', 'webhook_shipping', 'csp_report'];

    /** Legacy /api.php actions still routed for one release: notifications only, never the browser API. */
    private const LEGACY_API_PHP = ['webhook_payment', 'webhook_stripe', 'webhook_shipping', 'csp_report'];

    /**
     * The internal handler action and the $_GET values taken from the path, or null for the external API.
     * $baseUrl is the subdirectory the shop is installed in (default: Template::baseUrl()).
     *
     * @return array{action:string,get:array<string,string>}|null
     */
    public static function resolve(string $uri, ?string $baseUrl = null, ?array $stores = null): ?array {
        $path = self::normalize($uri, $baseUrl, $stores)['path'];
        parse_str((string)(parse_url($uri, PHP_URL_QUERY) ?: ''), $query);
        $legacyAction = is_string($query['action'] ?? null) ? $query['action'] : '';
        if ($path === '/api.php') {
            // Legacy for one release: GoPay/ThePay/PayU store the notification URL per payment.
            if (!in_array($legacyAction, self::LEGACY_API_PHP, true)) return ['action' => '', 'get' => []];
            return $legacyAction === 'webhook_stripe'
                ? ['action' => 'webhook_payment', 'get' => ['provider' => 'stripe']]
                : ['action' => $legacyAction, 'get' => []];
        }
        if ($path !== self::PREFIX && !str_starts_with($path, self::PREFIX . '/')) return null;
        $rest = substr($path, strlen(self::PREFIX));

        if ($rest === '/csp-report') return ['action' => 'csp_report', 'get' => []];
        // Legacy for one release: storefront.js cached from before the upgrade calls <apiUrl>?action=<action>.
        if ($rest === '/storefront' && $legacyAction !== '') $rest .= '/' . $legacyAction;
        if (preg_match('#^/storefront/([a-z0-9_.-]+)$#D', $rest, $m)) {
            $action = $m[1];
            if (in_array($action, self::NOT_STOREFRONT, true) || str_starts_with($action, 'bbf_')) $action = '';
            return ['action' => $action, 'get' => []];
        }
        if (preg_match('#^/options/([a-z_]+)$#D', $rest, $m)) return ['action' => 'bbf_' . $m[1], 'get' => []];
        if (preg_match('#^/webhooks/payment/([a-z0-9_]+)$#D', $rest, $m)) {
            return ['action' => 'webhook_payment', 'get' => ['provider' => $m[1]]];
        }
        // Same carrier code shape as apiWebhookShipping accepts (e.g. the default http-carrier).
        if (preg_match('#^/webhooks/shipping/([a-z0-9][a-z0-9_-]{0,39})$#D', $rest, $m)) {
            return ['action' => 'webhook_shipping', 'get' => ['carrier' => $m[1]]];
        }
        if (preg_match('#^/(?:storefront|options|webhooks)(?:/|$)#', $rest)) return ['action' => '', 'get' => []];
        return null;
    }

    /**
     * The request path without the install subdirectory and store prefix (/shop/cs/api/v1/x → /api/v1/x)
     * and the store whose url_prefix was stripped (null when none was).
     *
     * @return array{path:string,store:?string}
     */
    public static function normalize(string $uri, ?string $baseUrl = null, ?array $stores = null): array {
        $baseUrl ??= Template::baseUrl();
        $path = '/' . trim((string)(parse_url($uri, PHP_URL_PATH) ?: '/'), '/');
        if ($baseUrl !== '' && ($path === $baseUrl || str_starts_with($path, $baseUrl . '/'))) {
            $path = substr($path, strlen($baseUrl)) ?: '/';
        }
        return self::withoutStorePrefix($path, $stores ?? (array)(Bootstrap::stores()['stores'] ?? []));
    }

    /**
     * Store-prefixed API path (/cs/api/v1/…) → /api/v1/…: the longest configured url_prefix, as StoreContext
     * resolves the store from the same REQUEST_URI.
     *
     * @return array{path:string,store:?string}
     */
    private static function withoutStorePrefix(string $path, array $stores): array {
        if ($path === self::PREFIX || str_starts_with($path, self::PREFIX . '/')) return ['path' => $path, 'store' => null];
        $best = '';
        $bestStore = null;
        foreach ($stores as $storeId => $store) {
            $prefix = rtrim((string)(is_array($store) ? ($store['url_prefix'] ?? '') : ''), '/');
            if ($prefix === '' || !str_starts_with($prefix, '/') || strlen($prefix) <= strlen($best)) continue;
            if (str_starts_with($path, $prefix . '/')) [$best, $bestStore] = [$prefix, (string)$storeId];
        }
        return $best === '' ? ['path' => $path, 'store' => null] : ['path' => substr($path, strlen($best)), 'store' => $bestStore];
    }

    /** Absolute or base-relative URL of a payment webhook: $base is the shop URL without a trailing slash. */
    public static function paymentWebhook(string $base, string $provider): string {
        return $base . self::PREFIX . '/webhooks/payment/' . rawurlencode($provider);
    }
}
