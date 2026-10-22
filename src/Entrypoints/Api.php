<?php
/**
 * BareBones Shop — the single JSON API entrypoint (/api/v1/…, route groups in src/ApiRoute.php).
 * Browser, option, webhook and CSP routes run the action router below; every other path is the
 * external machine API (src/Entrypoints/ApiExternal.php). Domain handlers live in src/Http/ApiHandlers.php.
 */
require_once dirname(__DIR__, 2) . '/src/autoload.php';
require_once dirname(__DIR__) . '/Http/ApiHandlers.php';

if (!defined('BB_TEST_LOAD_API_ONLY')) {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $route = ApiRoute::resolve($uri);
    if ($route === null) {
        require __DIR__ . '/ApiExternal.php';
        return;
    }
    $action = $route['action'];
    $_GET = array_merge($_GET, $route['get']);

    Security::headers();
    header('Content-Type: application/json; charset=utf-8');

    Security::sessionStart();
    Customers::isLoggedIn();

    Bootstrap::initLocale($uri);

    if (!Security::rateLimit('api_' . $action, 120)) {
        echo json_encode(['error' => 'Too many requests']);
        exit;
    }

    try {
        $result = match($action) {
            'categories' => apiCategories(),
            'products' => apiProducts(),
            'product' => apiProduct(),
            'search' => apiSearch(),
            'filters' => apiFilters(),
            'cart' => apiCart(),
            'cart_add' => apiCartAdd(),
            'cart_update' => apiCartUpdate(),
            'cart_restore' => apiCartRestore(),
            'cart_enriched' => apiCartEnriched(),
            'checkout' => apiCheckout(),
            'consent_definitions' => apiConsentDefinitions(),
            'shipping_methods' => apiShippingMethods(),
            'shipping_rates' => apiShippingRates(),
            'payment_methods' => apiPaymentMethods(),
            'search_suggest' => apiSearchSuggest(),
            'validate_coupon' => apiValidateCoupon(),
            'review_submit' => apiReviewSubmit(),
            'wishlist' => apiWishlist(),
            'wishlist_toggle' => apiWishlistToggle(),
            'webhook_payment' => apiWebhookPayment(),
            'webhook_shipping' => apiWebhookShipping(),
            'cookie_consent' => apiCookieConsent(),
            'csp_report' => apiCspReport(),
            'parametric_search' => apiParametricSearch(),
            'product_specs' => apiProductSpecs(),
            'page' => apiPage(),
            'bbf_categories', 'bbf_brands', 'bbf_product_types', 'bbf_attr_types', 'bbf_page_statuses',
            'bbf_product_statuses', 'bbf_payment_methods' => BbfOptions::forAction($action),
            '' => apiUnknownAction(),
            default => Extensions::api($action) ?? apiUnknownAction(),
        };

        // A row with invalid UTF-8 (legacy import) must not turn the whole response into an empty body.
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Internal error']);
        Logger::phpLog("API error [{$action}]: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    }
}
