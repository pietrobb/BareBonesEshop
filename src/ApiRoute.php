<?php
/**
 * Route groups of the single JSON API under /api/v1:
 *   /api/v1/storefront/<action>           browser actions (session, CSRF on writes), extension actions <code>.<name>
 *   /api/v1/options/<name>                option lists for BBF forms (options_from)
 *   /api/v1/webhooks/payment/<provider>   payment notifications (verified by the gateway signature/API)
 *   /api/v1/webhooks/shipping/<carrier>   carrier tracking notifications
 *   /api/v1/csp-report                    browser CSP violation reports
 * Any other path is the external machine API (API key, ApiV1).
 */
class ApiRoute {
    public const PREFIX = '/api/v1';

    /** Actions that have their own route group and are not reachable as /storefront/<action>. */
    private const NOT_STOREFRONT = ['webhook_payment', 'webhook_shipping', 'csp_report'];

    /**
     * The internal handler action and the $_GET values taken from the path, or null for the external API.
     * $baseUrl is the subdirectory the shop is installed in (default: Template::baseUrl()).
     *
     * @return array{action:string,get:array<string,string>}|null
     */
    public static function resolve(string $uri, ?string $baseUrl = null, ?array $stores = null): ?array {
        $baseUrl ??= Template::baseUrl();
        $path = '/' . trim((string)(parse_url($uri, PHP_URL_PATH) ?: '/'), '/');
        if ($baseUrl !== '' && ($path === $baseUrl || str_starts_with($path, $baseUrl . '/'))) {
            $path = substr($path, strlen($baseUrl)) ?: '/';
        }
        $path = self::withoutStorePrefix($path, $stores ?? (array)(Bootstrap::stores()['stores'] ?? []));
        if ($path !== self::PREFIX && !str_starts_with($path, self::PREFIX . '/')) return null;
        $rest = substr($path, strlen(self::PREFIX));

        if ($rest === '/csp-report') return ['action' => 'csp_report', 'get' => []];
        if (preg_match('#^/storefront/([a-z0-9_.-]+)$#D', $rest, $m)) {
            $action = $m[1];
            if (in_array($action, self::NOT_STOREFRONT, true) || str_starts_with($action, 'bbf_')) $action = '';
            return ['action' => $action, 'get' => []];
        }
        if (preg_match('#^/options/([a-z_]+)$#D', $rest, $m)) return ['action' => 'bbf_' . $m[1], 'get' => []];
        if (preg_match('#^/webhooks/payment/([a-z0-9_]+)$#D', $rest, $m)) {
            return ['action' => 'webhook_payment', 'get' => ['provider' => $m[1]]];
        }
        if (preg_match('#^/webhooks/shipping/([a-z0-9_]+)$#D', $rest, $m)) {
            return ['action' => 'webhook_shipping', 'get' => ['carrier' => $m[1]]];
        }
        if (preg_match('#^/(?:storefront|options|webhooks)(?:/|$)#', $rest)) return ['action' => '', 'get' => []];
        return null;
    }

    /**
     * Store-prefixed API path (/cs/api/v1/…) → /api/v1/…: the longest configured url_prefix, as StoreContext
     * resolves the store from the same REQUEST_URI.
     */
    private static function withoutStorePrefix(string $path, array $stores): string {
        if ($path === self::PREFIX || str_starts_with($path, self::PREFIX . '/')) return $path;
        $best = '';
        foreach ($stores as $store) {
            $prefix = rtrim((string)(is_array($store) ? ($store['url_prefix'] ?? '') : ''), '/');
            if ($prefix === '' || !str_starts_with($prefix, '/') || strlen($prefix) <= strlen($best)) continue;
            if (str_starts_with($path, $prefix . '/')) $best = $prefix;
        }
        return $best === '' ? $path : substr($path, strlen($best));
    }

    /** Absolute or base-relative URL of a payment webhook: $base is the shop URL without a trailing slash. */
    public static function paymentWebhook(string $base, string $provider): string {
        return $base . self::PREFIX . '/webhooks/payment/' . rawurlencode($provider);
    }
}
