<?php
/**
 * BareBones Shop — JSON API request lifecycle and action router.
 * Domain handlers live in src/Http/ApiHandlers.php.
 */
require_once dirname(__DIR__, 2) . '/src/autoload.php';
require_once dirname(__DIR__) . '/Http/ApiHandlers.php';

$action = $_GET['action'] ?? '';

if (!defined('BB_TEST_LOAD_API_ONLY')) {
    Security::headers();
    header('Content-Type: application/json; charset=utf-8');

    Security::sessionStart();
    Customers::isLoggedIn();

    $uri = $_SERVER['REQUEST_URI'] ?? '/';
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
            'webhook_stripe' => apiWebhookStripe(),
            'webhook_payment' => apiWebhookPayment(),
            'webhook_shipping' => apiWebhookShipping(),
            'cookie_consent' => apiCookieConsent(),
            'vehicle_manufacturers' => apiVehicleManufacturers(),
            'vehicle_models' => apiVehicleModels(),
            'vehicle_types' => apiVehicleTypes(),
            'vehicle_parts' => apiVehicleParts(),
            'oe_search' => apiOESearch(),
            'parametric_search' => apiParametricSearch(),
            'product_specs' => apiProductSpecs(),
            'product_oe' => apiProductOE(),
            'product_vehicles' => apiProductVehicles(),
            'page' => apiPage(),
            'bbf_categories' => apiBbfCategories(),
            'bbf_product_types' => apiBbfProductTypes(),
            'bbf_attr_types' => apiBbfAttrTypes(),
            'bbf_page_statuses' => apiBbfStaticOptions([
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'draft', 'label' => 'Draft'],
            ]),
            'bbf_product_statuses' => apiBbfStaticOptions([
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'draft', 'label' => 'Draft'],
                ['value' => 'archived', 'label' => 'Archived'],
            ]),
            'bbf_payment_methods' => apiBbfStaticOptions([
                ['value' => 'cod', 'label' => 'Dobierka'],
                ['value' => 'transfer', 'label' => 'Bankový prevod'],
                ['value' => 'card', 'label' => 'Kartou online'],
            ]),
            default => ['error' => 'Unknown action'],
        };

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Internal error']);
        error_log("API error [{$action}]: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    }
}
