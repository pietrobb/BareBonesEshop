<?php
/**
 * BareBones Shop — API Router
 * All dynamic operations: products, categories, search, cart.
 * Cache-first, JSON responses, CSRF for POST.
 */
require_once __DIR__ . '/src/autoload.php';

$cfg = require __DIR__ . '/config.php';
$action = $_GET['action'] ?? '';

if (!defined('BB_TEST_LOAD_API_ONLY')) {
// Security headers
Security::headers();

// JSON response
header('Content-Type: application/json; charset=utf-8');

// Session (with secure cookie params) and fail-closed customer validation.
Security::sessionStart();
Customers::isLoggedIn();

// Resolve store/channel before any catalog, cache, tax, payment or shipping work.
$uri = $_SERVER['REQUEST_URI'] ?? '/';
Bootstrap::initLocale($uri);

// Rate-limit every endpoint, including signed webhooks. The generous webhook
// budget preserves provider retries while bounding signature-verification work.
if (!Security::rateLimit('api_' . $action, 120)) {
    echo json_encode(['error' => 'Too many requests']);
    exit;
}

try {
    $result = match($action) {
        'categories' => apiCategories(),
        'products'   => apiProducts(),
        'product'    => apiProduct(),
        'search'     => apiSearch(),
        'filters'    => apiFilters(),
        'cart'       => apiCart(),
        'cart_add'   => apiCartAdd(),
        'cart_update'=> apiCartUpdate(),
        'cart_restore'    => apiCartRestore(),
        'cart_enriched'   => apiCartEnriched(),
        'checkout'        => apiCheckout(),
        'consent_definitions' => apiConsentDefinitions(),
        'shipping_methods'=> apiShippingMethods(),
        'shipping_rates'  => apiShippingRates(),
        'payment_methods' => apiPaymentMethods(),
        'search_suggest'  => apiSearchSuggest(),
        'validate_coupon' => apiValidateCoupon(),
        'review_submit'   => apiReviewSubmit(),
        'wishlist'         => apiWishlist(),
        'wishlist_toggle'  => apiWishlistToggle(),
        'webhook_stripe'  => apiWebhookStripe(),
        'webhook_payment'  => apiWebhookPayment(),
        'webhook_shipping' => apiWebhookShipping(),
        'cookie_consent'   => apiCookieConsent(),
        // TecDoc / Auto Parts
        'vehicle_manufacturers' => apiVehicleManufacturers(),
        'vehicle_models'        => apiVehicleModels(),
        'vehicle_types'         => apiVehicleTypes(),
        'vehicle_parts'         => apiVehicleParts(),
        'oe_search'             => apiOESearch(),
        'parametric_search'     => apiParametricSearch(),
        'product_specs'         => apiProductSpecs(),
        'product_oe'            => apiProductOE(),
        'product_vehicles'      => apiProductVehicles(),
        'page'                  => apiPage(),
        // BBF dynamic options
        'bbf_categories'        => apiBbfCategories(),
        'bbf_product_types'     => apiBbfProductTypes(),
        'bbf_attr_types'        => apiBbfAttrTypes(),
        'bbf_page_statuses'     => apiBbfStaticOptions([
            ['value' => 'active', 'label' => 'Active'],
            ['value' => 'draft', 'label' => 'Draft'],
        ]),
        'bbf_product_statuses'  => apiBbfStaticOptions([
            ['value' => 'active', 'label' => 'Active'],
            ['value' => 'draft', 'label' => 'Draft'],
            ['value' => 'archived', 'label' => 'Archived'],
        ]),
        'bbf_payment_methods'   => apiBbfStaticOptions([
            ['value' => 'cod', 'label' => 'Dobierka'],
            ['value' => 'transfer', 'label' => 'Bankový prevod'],
            ['value' => 'card', 'label' => 'Kartou online'],
        ]),
        default                 => ['error' => 'Unknown action'],
    };

    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal error']);
    error_log("API error [{$action}]: " . $e->getMessage() . "\n" . $e->getTraceAsString());
}
}

// ============================================================
// API Handlers
// ============================================================

function apiCategories(): array {
    $locale = I18n::locale();
    $cached = Cache::readJson(Cache::localePath($locale, 'categories.json'), 3600);
    if ($cached) return $cached;

    $tree = Categories::tree();
    // Translate & enrich
    $tree = array_map(function($cat) {
        $cat = I18n::category($cat);
        $cat = Categories::enrich($cat);
        if (!empty($cat['children'])) {
            $cat['children'] = array_map(function($child) {
                return Categories::enrich(I18n::category($child));
            }, $cat['children']);
        }
        return $cat;
    }, $tree);

    $result = ['categories' => $tree];
    Cache::writeJson(Cache::localePath($locale, 'categories.json'), $result);
    return $result;
}

function apiProducts(): array {
    $catSlug = Security::slug($_GET['cat'] ?? '');
    $page = max(1, Security::int($_GET['page'] ?? 1));
    $sort = Security::slug($_GET['sort'] ?? 'price_asc');
    $filtersJson = $_GET['filters'] ?? '{}';
    $filters = json_decode($filtersJson, true) ?: [];
    $priceMin = isset($_GET['price_min']) ? Security::int($_GET['price_min']) : null;
    $priceMax = isset($_GET['price_max']) ? Security::int($_GET['price_max']) : null;

    $cfg = require __DIR__ . '/config.php';
    $perPage = $cfg['per_page'] ?? 24;

    // Get category
    $category = Categories::bySlug($catSlug);
    if (!$category) {
        return ['error' => 'Category not found', 'products' => [], 'total' => 0];
    }

    // Check cache (only for unfiltered first page)
    $locale = I18n::locale();
    if (empty($filters) && $page === 1 && !$priceMin && !$priceMax && $sort === 'price_asc') {
        $cached = Cache::readJson(Cache::localePath($locale, "cat/{$catSlug}/page-1.json"), 3600);
        if ($cached) return $cached;
    }

    $data = Products::byCategory($category['id'], $page, $perPage, $sort, $filters, $priceMin, $priceMax);

    // Translate & enrich products (batch category map to avoid N+1)
    $catMap = Products::categoryMap();
    $data['products'] = array_map(function($p) use ($catMap) {
        return Products::enrich(I18n::product($p), $catMap);
    }, $data['products']);

    $data['category'] = Categories::enrich(I18n::category($category));

    // Cache first page of unfiltered results
    if (empty($filters) && $page === 1 && !$priceMin && !$priceMax && $sort === 'price_asc') {
        Cache::writeJson(Cache::localePath($locale, "cat/{$catSlug}/page-1.json"), $data);
    }

    return $data;
}

function apiProduct(): array {
    $slug = Security::slug($_GET['slug'] ?? '');
    if (!$slug) return ['error' => 'Missing slug'];

    $locale = I18n::locale();

    // Check cache
    $cached = Cache::readJson(Cache::localePath($locale, "product/{$slug}.json"), 3600);
    if ($cached) return $cached;

    $product = Products::bySlug($slug);
    if (!$product) return ['error' => 'Product not found'];

    $product = Products::enrich(I18n::product($product));
    $product['variants'] = Products::variants($product['id']);
    $product['attributes'] = Products::attrs($product['id']);

    $result = ['product' => $product];

    Cache::writeJson(Cache::localePath($locale, "product/{$slug}.json"), $result);
    return $result;
}

function apiSearch(): array {
    $q = trim($_GET['q'] ?? '');
    $page = max(1, Security::int($_GET['page'] ?? 1));

    if (strlen($q) < 2) {
        return ['products' => [], 'total' => 0, 'query' => $q];
    }

    $data = Adapters::search()->search($q, ['page' => $page]);
    $catMap = Products::categoryMap();
    $data['products'] = array_map(function($p) use ($catMap) {
        return Products::enrich(I18n::product($p), $catMap);
    }, $data['products']);

    return $data;
}

function apiSearchSuggest(): array {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) return [];
    return Adapters::search()->suggest($q, 5);
}

function apiValidateCoupon(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }

    if (!Security::csrfValidate()) {
        http_response_code(403);
        return ['error' => 'Invalid CSRF token'];
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $code = trim($input['code'] ?? '');
    $subtotal = Security::int($input['subtotal'] ?? 0);
    $email = Security::email($input['email'] ?? '') ?? null;

    if (!$code) return ['valid' => false, 'error' => 'Coupon code is required'];

    $result = Pricing::applyCoupon($code, $subtotal, $email);
    if ($result['discount'] <= 0) {
        return ['valid' => false, 'error' => $result['error'] ?? 'Invalid coupon'];
    }

    return [
        'valid'    => true,
        'code'     => $code,
        'discount' => $result['discount'],
        'name'     => $result['name'] ?? $code,
    ];
}

function apiReviewSubmit(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }
    if (!Security::csrfValidate()) {
        http_response_code(403);
        return ['error' => 'Invalid CSRF token'];
    }
    // Stricter throttle than the generic api bucket — review POSTs are a spam target.
    // Reviews are moderated (always land pending), so a modest per-minute/IP cap suffices.
    if (!Security::rateLimit('review_submit', 20)) {
        http_response_code(429);
        return ['ok' => false, 'error' => 'rate_limited'];
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $productId = Security::int($input['product_id'] ?? 0);
    if ($productId <= 0 || !Products::byId($productId)) {
        return ['ok' => false, 'error' => 'invalid_product'];
    }

    $data = [
        'rating' => Security::int($input['rating'] ?? 0),
        'title'  => (string)($input['title'] ?? ''),
        'body'   => (string)($input['body'] ?? ''),
    ];

    // Logged-in customer: attribute the review and use the account name.
    $customerId = (int)($_SESSION['customer_id'] ?? 0);
    if ($customerId > 0) {
        $data['customer_id'] = $customerId;
        $c = Customers::byId($customerId);
        $data['author_name'] = trim((string)($c['name'] ?? '')) !== ''
            ? $c['name']
            : (string)($input['author_name'] ?? '');
    } else {
        $data['author_name'] = (string)($input['author_name'] ?? '');
    }

    [$ok, $id, $err] = Reviews::submit($productId, $data);
    if (!$ok) {
        return ['ok' => false, 'error' => $err];
    }
    // Reviews always land as pending — never visible until an admin approves.
    return ['ok' => true, 'id' => $id, 'status' => 'pending'];
}

function apiWishlist(): array {
    // Read-only: current owner's saved product ids + count.
    return [
        'ok'    => true,
        'ids'   => Wishlist::ids(),
        'count' => Wishlist::count(),
    ];
}

function apiWishlistToggle(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }
    if (!Security::csrfValidate()) {
        http_response_code(403);
        return ['error' => 'Invalid CSRF token'];
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $productId = Security::int($input['product_id'] ?? 0);
    if ($productId <= 0 || !Products::byId($productId)) {
        return ['ok' => false, 'error' => 'invalid_product'];
    }

    $state = Wishlist::toggle($productId);
    return [
        'ok'      => true,
        'state'   => $state,            // 'added' | 'removed'
        'in_list' => $state === 'added',
        'count'   => Wishlist::count(),
    ];
}

function apiFilters(): array {
    $catSlug = Security::slug($_GET['cat'] ?? '');
    $category = Categories::bySlug($catSlug);
    if (!$category) return ['filters' => []];

    $locale = I18n::locale();
    $cached = Cache::readJson(Cache::localePath($locale, "cat/{$catSlug}/filters.json"), 3600);
    if ($cached) return $cached;

    $raw = Products::filterValues($category['id']);

    // Translate labels
    $filters = [];
    foreach ($raw as $attrName => $options) {
        $filters[] = [
            'attr_name' => $attrName,
            'label'     => I18n::attrLabel($attrName),
            'options'   => array_map(function($opt) use ($attrName) {
                return [
                    'value' => $opt['value'],
                    'label' => I18n::attr($attrName, $opt['value']),
                    'count' => $opt['count'],
                ];
            }, $options),
        ];
    }

    $result = ['filters' => $filters];
    Cache::writeJson(Cache::localePath($locale, "cat/{$catSlug}/filters.json"), $result);
    return $result;
}

function apiCart(): array {
    return Cart::enriched();
}

function apiCartAdd(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }

    if (!Security::csrfValidate()) {
        http_response_code(403);
        return ['error' => 'Invalid CSRF token'];
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $productId = Security::int($input['product_id'] ?? 0);
    $variantId = isset($input['variant_id']) ? Security::int($input['variant_id']) : null;
    $qty = max(1, min(99, Security::int($input['qty'] ?? 1)));

    if ($productId <= 0) {
        return ['error' => 'Invalid product'];
    }

    // Verify product exists and has stock
    $product = Products::byId($productId);
    if (!$product || $product['status'] !== 'active') {
        return ['error' => 'Product not found'];
    }
    if (!VariantAvailability::isPurchasable($productId, $variantId)) {
        http_response_code(409);
        return ['error' => 'Product variant is not available'];
    }

    Cart::add($productId, $variantId, $qty);

    return ['success' => true, 'cart' => apiCart()];
}

function apiCartUpdate(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }

    if (!Security::csrfValidate()) {
        http_response_code(403);
        return ['error' => 'Invalid CSRF token'];
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $key = $input['key'] ?? '';
    if (!preg_match('/^(p|v)_\d{1,10}$/', $key)) {
        return ['error' => 'Invalid cart key'];
    }
    $qty = min(99, Security::int($input['qty'] ?? 0));

    Cart::update($key, $qty);

    return ['success' => true, 'cart' => apiCart()];
}

function apiCartRestore(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }

    if (!Security::csrfValidate()) {
        http_response_code(403);
        return ['error' => 'Invalid CSRF token'];
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!is_array($input)) return ['error' => 'Invalid data'];

    Cart::restore($input);

    return ['success' => true, 'cart' => apiCart()];
}

function apiCartEnriched(): array {
    return Cart::enriched();
}

function apiShippingMethods(): array {
    return apiShippingRates();
}

function apiShippingRates(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }

    $country = strtoupper(trim($_GET['country'] ?? 'SK'));
    if (!preg_match('/^[A-Z]{2}$/', $country)) {
        return ['error' => 'Invalid country'];
    }
    $subtotal = Security::int($_GET['subtotal'] ?? 0);
    $cart = Cart::enriched();
    if (cartIsDigitalOnly($cart['items'] ?? [])) {
        return [
            'country' => $country,
            'weight_g' => 0,
            'volume_cm3' => 0,
            'delivery_type' => 'digital',
            'methods' => [digitalDeliveryRate()],
        ];
    }
    $weightG = Security::int($_GET['weight_g'] ?? 0);
    $volumeCm3 = Security::int($_GET['volume_cm3'] ?? 0);
    $cod = !empty($_GET['cod']) && $_GET['cod'] !== '0';

    if ($weightG <= 0) {
        $cart = Cart::enriched();
        $metrics = shippingCartMetrics($cart['items'] ?? []);
        $subtotal = $subtotal > 0 ? $subtotal : (int)($cart['subtotal'] ?? 0);
        $weightG = $metrics['weight_g'];
        $volumeCm3 = $metrics['volume_cm3'];
    }

    $methods = ShippingService::calculateRates($country, $weightG, $subtotal, $cod, $volumeCm3);
    return [
        'country' => $country,
        'weight_g' => $weightG,
        'volume_cm3' => $volumeCm3,
        'methods' => $methods,
    ];
}

function apiPaymentMethods(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }

    $country = strtoupper(trim($_GET['country'] ?? 'SK'));
    if (!preg_match('/^[A-Z]{2}$/', $country)) {
        return ['error' => 'Invalid country'];
    }
    $currency = strtolower(trim($_GET['currency'] ?? 'eur'));
    if (!preg_match('/^[a-z]{3}$/', $currency)) {
        return ['error' => 'Invalid currency'];
    }
    $amount = Security::int($_GET['amount'] ?? 0);
    $shippingMethod = Security::slug($_GET['shipping_method'] ?? '');
    $cart = Cart::enriched();
    $digitalOnly = cartIsDigitalOnly($cart['items'] ?? []);
    if ($digitalOnly) {
        $shippingMethod = 'digital';
        $amount = (int)($cart['subtotal'] ?? $amount);
    }
    $methods = $digitalOnly && $amount === 0
        ? [freeDigitalPayment()]
        : PaymentService::availableMethods($country, $currency, $amount, $shippingMethod);
    if ($digitalOnly) {
        $methods = array_values(array_filter($methods, static fn(array $method): bool => ($method['service_type'] ?? '') !== 'cod'));
    }

    return [
        'country' => $country,
        'currency' => $currency,
        'amount' => $amount,
        'shipping_method' => $shippingMethod,
        'methods' => $methods,
    ];
}

/**
 * Resolve the active store from stores.json by current locale.
 * Pass 1: store whose PRIMARY language (languages[0]) matches the locale.
 * Pass 2: store whose languages list contains the locale.
 * Fallback: first store.
 */
function apiActiveStore(): array {
    return StoreContext::current()->config();
}

function apiConsentDefinitions(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }
    $country = strtoupper(trim((string)($_GET['country'] ?? '')));
    $cart = Cart::enriched();
    $context = [
        'country' => preg_match('/^[A-Z]{2}$/', $country) ? $country : '',
        'product_types' => array_values(array_unique(array_column($cart['items'] ?? [], 'product_type'))),
    ];
    return ['consents' => array_values(ConsentService::definitions($context))];
}

function apiCheckout(?array $runtimeConfig = null): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }

    if (!Security::csrfValidate()) {
        http_response_code(403);
        return ['error' => 'Invalid CSRF token'];
    }

    if (!Security::rateLimit('checkout', 10)
        || !Security::rateLimit('checkout_global', 200, 60, false)
        || !Security::rateLimit('checkout_session_' . hash('sha256', session_id()), 5, 900, false)) {
        return ['error' => 'Too many checkout attempts'];
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $name = trim($input['name'] ?? '');
    $email = Security::email($input['email'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $country = strtoupper(trim($input['country'] ?? 'SK'));
    if (!preg_match('/^[A-Z]{2}$/', $country)) {
        return ['error' => 'Invalid country'];
    }
    $shippingMethod = Security::slug($input['shipping_method'] ?? '');
    $paymentMethod = $input['payment_method'] ?? 'stripe';

    if (!$name || !$email) {
        return ['error' => 'Name and email are required'];
    }
    $checkoutSubject = !empty($_SESSION['customer_id'])
        ? 'customer_' . (int)$_SESSION['customer_id']
        : 'email_' . hash('sha256', strtolower($email));
    if (!Security::rateLimit('checkout_subject_' . $checkoutSubject, 3, 1800, false)) {
        return ['error' => 'Too many checkout attempts'];
    }

    // Capture email for abandoned cart recovery
    Cart::setEmail($email);

    if (!preg_match('/^[a-z0-9_-]{2,40}$/', $paymentMethod)) {
        return ['error' => 'Invalid payment method'];
    }

    // VAT context — resolve scenario before enriching cart so per-line VAT is captured.
    // $cfg must be loaded here: apiCheckout is a function, the file-scope $cfg is not
    // visible (it used to be silently undefined → seller always 'SK', OSS always off,
    // admin notification never sent). Seller identity lives per-store in stores.json.
    $cfg   = $runtimeConfig ?? require __DIR__ . '/config.php';
    StoreContext::current()->assertPermission('tax');
    $store = apiActiveStore();
    $vatId = strtoupper(preg_replace('/\s+/', '', (string)($input['vat_id'] ?? '')));
    $sellerCountry = strtoupper((string)(($store['legal']['country'] ?? null) ?: 'SK'));
    $sellerVatRegistered = array_key_exists('vat_registered', $store['legal'] ?? [])
        ? (bool)$store['legal']['vat_registered']
        : true;
    $sellerOss     = (bool)($store['oss_enabled'] ?? false);
    $vatIdValid    = false;
    $vatIdStatus   = null;
    if ($vatId !== '') {
        try {
            $vatRes = VatService::validateVatId($vatId, VatService::defaultValidator());
            $vatIdValid  = ($vatRes['status'] === 'valid');
            $vatIdStatus = $vatRes['status'];
        } catch (\Throwable $e) {
            error_log("VAT ID validation failed [{$vatId}]: " . $e->getMessage());
            $vatIdStatus = 'unverified';
        }
        // Persist VAT ID on logged-in customer so future orders remember it
        Security::sessionStart();
        if (!empty($_SESSION['customer_id'])) {
            Customers::update((int)$_SESSION['customer_id'], [
                'vat_id'           => $vatId,
                'vat_country'      => substr($vatId, 0, 2),
                'vat_validated_at' => $vatIdValid ? date('Y-m-d H:i:s') : null,
            ]);
        }
    }
    $vatContext = [
        'seller_country'        => $sellerCountry,
        'seller_vat_registered' => $sellerVatRegistered,
        'seller_oss_enabled'    => $sellerOss,
        'customer_country'      => $country,
        'customer_vat_id_valid' => $vatIdValid,
        'is_b2b'                => $vatId !== '',
    ];
    $vatScenario = VatService::resolveScenario($vatContext);

    // Cart preview and the immutable order snapshot use the same canonical
    // provider inputs. No downstream consumer resolves the scenario again.
    $cart = Cart::enriched($vatContext);
    if (empty($cart['items'])) {
        return ['error' => 'Cart is empty'];
    }
    $availableItems = array_values(array_filter($cart['items'], static fn(array $item): bool => $item['available'] ?? true));
    if (empty($availableItems)) {
        return ['success' => false, 'error' => 'Žiadne dostupné produkty v košíku.'];
    }
    $cart['items'] = $availableItems;
    $cart['subtotal'] = array_sum(array_column($availableItems, 'total'));
    $digitalOnly = cartIsDigitalOnly($availableItems);
    if ($digitalOnly) {
        $shippingMethod = 'digital';
    } elseif (trim((string)($input['street'] ?? '')) === '' || trim((string)($input['city'] ?? '')) === '' || trim((string)($input['zip'] ?? '')) === '') {
        return ['success' => false, 'error' => 'Doručovacia adresa je povinná pri fyzických produktoch.'];
    }

    $consentContext = [
        'country' => $country,
        'product_types' => array_values(array_unique(array_column($cart['items'], 'product_type'))),
    ];
    $submittedConsents = is_array($input['consents'] ?? null) ? $input['consents'] : [];
    $consentErrors = ConsentService::validate($submittedConsents, $consentContext);
    if ($consentErrors) {
        http_response_code(422);
        return ['success' => false, 'error' => 'Required consent missing', 'consent_errors' => $consentErrors];
    }

    // Apply coupon if provided
    $couponCode = trim($input['coupon_code'] ?? '');
    $couponDiscount = 0;
    if ($couponCode) {
        $couponResult = Pricing::applyCoupon($couponCode, $cart['subtotal'], $email);
        if ($couponResult['discount'] > 0) {
            $couponDiscount = $couponResult['discount'];
        } else {
            return ['error' => $couponResult['error'] ?? 'Invalid coupon'];
        }
    }

    $metrics = shippingCartMetrics($cart['items']);
    $totalWeight = $digitalOnly ? 0 : $metrics['weight_g'];
    $totalVolume = $digitalOnly ? 0 : $metrics['volume_cm3'];
    $selectedRate = $digitalOnly
        ? digitalDeliveryRate()
        : ShippingService::selectRate($shippingMethod, $country, $totalWeight, $cart['subtotal'], $paymentMethod === 'cod', $totalVolume);
    if (!$selectedRate) {
        return ['success' => false, 'error' => 'Doručenie nie je dostupné do zvolenej krajiny.'];
    }
    $shippingCost = (int)$selectedRate['price'];
    $autoRules = Pricing::autoCartRules($cart['subtotal']);
    foreach ($autoRules as $rule) {
        if ($rule['action'] === 'free_shipping') $shippingCost = 0;
    }

    $currency = $cart['items'][0]['currency'] ?? 'eur';
    $paymentBaseAmount = max(0, $cart['subtotal'] - $couponDiscount) + $shippingCost;
    $freeDigitalOrder = $digitalOnly && $paymentBaseAmount === 0;
    if ($freeDigitalOrder) {
        $paymentMethod = 'free';
        $selectedPayment = freeDigitalPayment();
    } else {
        $selectedPayment = PaymentService::selectMethod($paymentMethod, $country, $currency, $paymentBaseAmount, $selectedRate['method_code']);
    }
    if (!$selectedPayment || ($digitalOnly && ($selectedPayment['service_type'] ?? '') === 'cod')) {
        return ['success' => false, 'error' => 'Platba nie je dostupná pre digitálne doručenie.'];
    }
    $paymentFee = (int)$selectedPayment['fee'];
    $financialSnapshot = VatService::buildSnapshot($availableItems, $vatContext, [
        'discount' => $couponDiscount,
        'shipping' => $shippingCost,
        'payment_fee' => $paymentFee,
        'currency' => $currency,
        'effective_date' => date('Y-m-d'),
    ]);

    // Attach logged-in customer_id if a session is active (guest checkout keeps customer_id=null)
    Security::sessionStart();
    $loggedInCustomerId = !empty($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : null;

    $customer = [
        'customer_id'    => $loggedInCustomerId,
        'name'           => $name,
        'email'          => $email,
        'phone'          => $phone,
        'address'        => [
            'street'  => $digitalOnly ? '' : trim($input['street'] ?? ''),
            'city'    => $digitalOnly ? '' : trim($input['city'] ?? ''),
            'zip'     => $digitalOnly ? '' : trim($input['zip'] ?? ''),
            'country' => $country,
        ],
        'currency'       => $currency,
        'locale'         => I18n::locale(),
        'shipping_cost'  => $shippingCost,
        'payment_fee'    => $paymentFee,
        'payment_method_snapshot' => json_encode($selectedPayment, JSON_UNESCAPED_UNICODE),
        'shipping_method'=> $selectedRate['method_code'],
        'shipping_zone'  => $selectedRate['zone_code'] ?? '',
        'shipping_weight_g' => $totalWeight,
        'shipping_rate_snapshot' => json_encode($selectedRate, JSON_UNESCAPED_UNICODE),
        'tax_amount'     => $financialSnapshot['totals']['vat'],
        'coupon_discount'=> $couponDiscount,
        'financial_snapshot' => $financialSnapshot,
        'vat_id'         => $vatId,
        'vat_country'    => $vatId !== '' ? substr($vatId, 0, 2) : '',
        'vat_id_status'  => $vatIdStatus,
        'vat_scenario'   => $vatScenario['scenario'],
    ];

    // Pass coupon info for atomic recording inside the order transaction
    $coupon = ($couponCode && $couponDiscount > 0)
        ? ['code' => $couponCode, 'discount' => $couponDiscount]
        : null;

    try {
        $orderId = Orders::create($availableItems, $customer, $paymentMethod, $coupon);
        ConsentService::record(
            $orderId,
            $submittedConsents,
            $consentContext,
            Security::clientIp(),
            (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
        );
        if ($freeDigitalOrder) {
            PaymentService::updatePaymentStatus($orderId, 'paid');
            OrderWorkflow::transition($orderId, 'paid', 'Bezplatná digitálna objednávka');
        }

        Cart::clear();
        // Invalidate the submitted CSRF value after the irreversible create so
        // an exact browser/network replay cannot reserve stock a second time.
        Security::rotateCsrfToken();

        $order = Orders::byId($orderId);
        $orderItems = Orders::items($orderId);
        // For COD/bank_transfer: send confirmation immediately (no webhook will fire)
        // For Stripe: confirmation email is sent from the webhook after payment is confirmed
        if (!$freeDigitalOrder && empty($selectedPayment['requires_online'])) {
            JobQueue::push('email_send', ['template' => 'order_confirmation', 'to' => $email, 'order_id' => $orderId]);
        }

        $result = [
            'success'      => true,
            'order_id'     => $orderId,
            'order_number' => $order['order_number'],
            'total'        => (int)$order['total'],
        ];

        // For online payments, create a payment session
        if (!empty($selectedPayment['requires_online'])) {
            try {
                $payment = Adapters::payment();
                $baseUrl = Security::appUrl($cfg);
                $session = PaymentService::initiate([
                    'order_id'       => $orderId,
                    'order_number'   => $order['order_number'],
                    'total'          => (int)$order['total'],
                    'customer_email' => $email,
                    'currency'       => $order['currency'] ?? 'eur',
                    'items'          => $orderItems,
                    'shipping_cost'  => (int)$order['shipping_cost'],
                    'financial_snapshot_id' => $financialSnapshot['fingerprint'],
                    'success_url'    => $baseUrl . '/?page=order-done&order=' . rawurlencode($order['order_number']) . '&session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url'     => $baseUrl . '/checkout/cancel?order=' . rawurlencode($order['order_number']),
                ], $payment);
                $result['payment_url'] = $session['url'];
                $result['session_id'] = $session['session_id'];

                // Persist the provider session separately so payment_id may later
                // be replaced by the payment intent without losing ownership proof.
                if (!empty($session['session_id'])) {
                    CheckoutSession::bind($orderId, (string)$session['session_id'], 'stripe', session_id());
                    Db::get()->prepare("UPDATE orders SET payment_id = :pid WHERE id = :id")
                        ->execute([':pid' => $session['session_id'], ':id' => $orderId]);
                }
            } catch (\Throwable $e) {
                // Payment adapter error — order still created, log and return
                error_log("Payment session creation failed for order #{$orderId}: " . $e->getMessage());
                $result['payment_error'] = 'Payment processing unavailable. Please try again.';
            }
        }

        return $result;
    } catch (\RuntimeException $e) {
        Cart::removeUnavailable([]);
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function cartIsDigitalOnly(array $items): bool {
    if (empty($items)) return false;
    foreach ($items as $item) {
        if (!VariantAvailability::isDigitalType((string)($item['product_type'] ?? ''))) return false;
    }
    return true;
}

function digitalDeliveryRate(): array {
    return [
        'method_code' => 'digital',
        'carrier' => 'digital',
        'label' => 'Elektronicky — odkaz na stiahnutie e-mailom',
        'description' => 'Bez fyzickej dopravy',
        'service_type' => 'digital',
        'zone_code' => 'digital',
        'price' => 0,
        'cod_surcharge' => 0,
        'delivery_days' => 'ihneď po úhrade',
        'cod' => false,
    ];
}

function freeDigitalPayment(): array {
    return [
        'code' => 'free',
        'provider' => 'none',
        'label' => 'Bez platby — objednávka je zdarma',
        'description' => 'Bezplatná digitálna objednávka',
        'service_type' => 'free',
        'requires_online' => false,
        'fee' => 0,
    ];
}

function shippingCartMetrics(array $items): array {
    $productIds = array_unique(array_column($items, 'product_id'));
    $weights = [];
    $attrs = [];
    if (!empty($productIds)) {
        $ph = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = Db::get()->prepare("SELECT id, weight_g, attrs FROM products WHERE id IN ({$ph})");
        $stmt->execute(array_values($productIds));
        while ($row = $stmt->fetch()) {
            $pid = (int)$row['id'];
            $weights[$pid] = (int)($row['weight_g'] ?? 0);
            $attrs[$pid] = json_decode($row['attrs'] ?? '{}', true) ?: [];
        }
    }

    $weightG = 0;
    $volumeCm3 = 0;
    foreach ($items as $item) {
        $pid = (int)$item['product_id'];
        $qty = (int)$item['qty'];
        $weightG += ($weights[$pid] ?? 0) * $qty;
        $a = $attrs[$pid] ?? [];
        $volumeCm3 += (int)($a['volume_cm3'] ?? 0) * $qty;
    }

    return ['weight_g' => $weightG, 'volume_cm3' => $volumeCm3];
}

function apiWebhookStripe(): array {
    $payload = file_get_contents('php://input');
    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $cfg = require __DIR__ . '/config.php';
    $secret = $cfg['stripe_webhook_secret'] ?? '';

    if (strlen((string)$secret) < 16 || trim((string)$secret) !== (string)$secret) {
        http_response_code(503);
        return ['error' => 'Webhook not configured'];
    }

    if (!verifyStripeSignature($payload, $sigHeader, $secret)) {
        http_response_code(400);
        return ['error' => 'Invalid signature'];
    }

    $event = json_decode($payload, true);
    if (!$event || empty($event['id']) || empty($event['type'])) {
        http_response_code(400);
        return ['error' => 'Invalid payload'];
    }

    $eventKey = 'payment_stripe_' . $event['id'];
    if (Idempotency::webhookAlreadyProcessed($event['id']) || Idempotency::webhookProcessed($eventKey)) {
        return ['status' => 'already_processed'];
    }

    try {
        match($event['type']) {
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded' => onPaymentCompleted($event['data']['object'] ?? []),
            'charge.refunded'            => onChargeRefunded($event['data']['object'] ?? []),
            'charge.dispute.created'     => onDisputeCreated($event['data']['object'] ?? []),
            default                      => null,
        };
    } catch (\Throwable $e) {
        // Unmark so Stripe can retry the webhook
        Idempotency::unmark($eventKey);
        error_log("Webhook error [{$event['type']}]: {$e->getMessage()}\n" . $e->getTraceAsString());
        http_response_code(500);
        return ['error' => 'Processing failed'];
    }

    return ['status' => 'ok'];
}

function onPaymentCompleted(array $session): void {
    $sessionId = (string)($session['id'] ?? '');
    $orderId = (int)($session['metadata']['order_id'] ?? 0);
    if ($orderId <= 0 && $sessionId !== '') $orderId = CheckoutSession::resolveOrderId($sessionId, 'stripe') ?? 0;
    if ($orderId <= 0) return;

    $order = Orders::byId($orderId);
    if (!$order) return;
    if (isset($session['payment_status']) && $session['payment_status'] !== 'paid') return;
    if (isset($session['amount_total']) && (int)$session['amount_total'] !== (int)$order['total']) {
        throw new RuntimeException("Stripe amount mismatch for order #{$orderId}");
    }
    if (isset($session['currency']) && strtolower((string)$session['currency']) !== strtolower((string)$order['currency'])) {
        throw new RuntimeException("Stripe currency mismatch for order #{$orderId}");
    }

    $paymentRef = (string)($session['payment_intent'] ?? $sessionId);
    if ($paymentRef !== '') Orders::setPaymentId($orderId, $paymentRef);
    if ($sessionId !== '') CheckoutSession::markCompleted($sessionId, 'stripe');

    if (($order['payment_status'] ?? 'pending') !== 'paid') {
        PaymentService::transitionPayment(
            $orderId,
            'paid',
            'checkout.session.completed',
            (int)($session['amount_total'] ?? $order['total']),
            'stripe_checkout_' . ($sessionId ?: $paymentRef),
            ['session_id' => $sessionId, 'payment_id' => $paymentRef]
        );
    }
    $order = Orders::byId($orderId);
    if ($order && OrderWorkflow::canTransition((string)$order['status'], 'paid')) {
        OrderWorkflow::transition($orderId, 'paid', 'Stripe webhook: ' . $sessionId);
    }
}

function onChargeRefunded(array $charge): void {
    $paymentIntent = $charge['payment_intent'] ?? '';
    if (!$paymentIntent) return;

    $stmt = Db::get()->prepare("SELECT * FROM orders WHERE payment_id = :pid LIMIT 1");
    $stmt->execute([':pid' => $paymentIntent]);
    $order = $stmt->fetch();
    if (!$order) return;

    $refundedAmount = (int)($charge['amount_refunded'] ?? 0);
    $orderTotal = (int)$order['total'];

    AuditLog::log('order_refund', (int)$order['id'], '', (string)$refundedAmount,
        "Stripe refund: {$refundedAmount}/{$orderTotal} cents");

    $orderId = (int)$order['id'];
    $fullRefund = $refundedAmount >= $orderTotal;
    $targetPaymentStatus = $fullRefund ? 'refunded' : 'partially_refunded';
    $refundDelta = max(0, $refundedAmount - PaymentService::refundedAmount($orderId));
    if ($refundDelta > 0) {
        $eventKey = 'stripe_refund_' . (string)($charge['id'] ?? $paymentIntent) . '_' . $refundedAmount;
        $eventPayload = ['cumulative_amount' => $refundedAmount];
        if (($order['payment_status'] ?? '') !== $targetPaymentStatus
            && PaymentService::canPaymentTransition((string)($order['payment_status'] ?? 'pending'), $targetPaymentStatus)) {
            PaymentService::transitionPayment($orderId, $targetPaymentStatus, 'charge.refunded', $refundDelta, $eventKey, $eventPayload);
        } else {
            PaymentService::recordEvent(
                $orderId,
                'charge.refunded',
                $targetPaymentStatus,
                $refundDelta,
                (string)($order['currency'] ?? 'eur'),
                $eventKey,
                $eventPayload,
                'stripe',
                (string)$paymentIntent
            );
        }
    }

    if ($fullRefund) {
        if (OrderWorkflow::canTransition((string)$order['status'], 'refunded')) {
            OrderWorkflow::transition($orderId, 'refunded', "Stripe full refund: {$refundedAmount} cents");
        } else {
            FulfillmentDispatcher::queue('refunded', $order);
        }
    }
}

function onDisputeCreated(array $dispute): void {
    $paymentIntent = $dispute['payment_intent'] ?? '';
    if (!$paymentIntent) return;

    $stmt = Db::get()->prepare("SELECT * FROM orders WHERE payment_id = :pid LIMIT 1");
    $stmt->execute([':pid' => $paymentIntent]);
    $order = $stmt->fetch();
    if (!$order) return;

    $orderId = (int)$order['id'];
    AuditLog::log('order_dispute', $orderId, $order['status'], 'disputed', 'Stripe dispute: ' . ($dispute['id'] ?? ''));
    if (($order['payment_status'] ?? '') !== 'disputed'
        && PaymentService::canPaymentTransition((string)($order['payment_status'] ?? 'pending'), 'disputed')) {
        PaymentService::transitionPayment(
            $orderId,
            'disputed',
            'charge.dispute.created',
            (int)($dispute['amount'] ?? $order['total']),
            'stripe_dispute_' . (string)($dispute['id'] ?? $paymentIntent)
        );
    }
    FulfillmentDispatcher::queue('disputed', $order);
}

if (!function_exists('verifyStripeSignature')) {
function verifyStripeSignature(string $payload, string $sigHeader, string $secret): bool {
    if (strlen($secret) < 16 || trim($secret) !== $secret) return false;

    $timestamp = '';
    $signatures = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$key, $value] = explode('=', trim($part), 2) + [1 => ''];
        if ($key === 't') {
            if ($timestamp !== '' && $timestamp !== $value) return false;
            $timestamp = $value;
        } elseif ($key === 'v1' && $value !== '') {
            $signatures[] = strtolower($value);
        }
    }
    if ($timestamp === '' || !ctype_digit($timestamp) || !$signatures) return false;
    if (abs(time() - (int)$timestamp) > 300) return false;

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) return true;
    }
    return false;
}
}

// ============================================================
// Generic Payment Webhook Handler (Stripe, PayU, Mock)
// Usage: POST /api.php?action=webhook_payment&provider=stripe|payu|mock
// ============================================================

function apiWebhookPayment(): array {
    $provider = Security::clean($_GET['provider'] ?? '');
    if (!$provider || !preg_match('/^[a-z0-9_]+$/', $provider)) {
        http_response_code(400);
        return ['error' => 'Invalid provider'];
    }

    $rawPayload = file_get_contents('php://input');
    if (!$rawPayload) {
        http_response_code(400);
        return ['error' => 'Empty payload'];
    }

    $parsed = json_decode($rawPayload, true);
    if ($parsed === null) {
        http_response_code(400);
        return ['error' => 'Invalid JSON'];
    }

    // Resolve gateway and signature header per provider
    $cfg = require __DIR__ . '/config.php';
    [$gateway, $sigHeader] = match($provider) {
        'stripe' => [
            new StripePayment($cfg),
            $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '',
        ],
        'payu' => [
            new PayUPayment($cfg),
            $_SERVER['HTTP_OPENPAYU_SIGNATURE'] ?? '',
        ],
        // Mock gateway = payment bypass — only routable when config explicitly
        // opts in (adapters.payment == MockPayment or allow_mock_webhook flag).
        // No default signature header: missing header must fail verification.
        'mock' => (($cfg['adapters']['payment'] ?? '') === 'MockPayment' || !empty($cfg['allow_mock_webhook']))
            ? [
                new MockPayment($cfg + ['mock_result' => 'success']),
                $_SERVER['HTTP_X_MOCK_SIGNATURE'] ?? '',
            ]
            : [null, ''],
        default => [null, ''],
    };

    if (!$gateway) {
        http_response_code(400);
        return ['error' => "Unknown provider: {$provider}"];
    }

    // Load adapter classes
    $adapterDir = __DIR__ . '/adapters/contrib/';
    foreach (['StripePayment', 'PayUPayment', 'MockPayment'] as $cls) {
        if (!class_exists($cls)) {
            $f = $adapterDir . $cls . '.php';
            if (file_exists($f)) require_once $f;
        }
    }

    try {
        $result = PaymentService::handleWebhook(
            rawPayload:    $rawPayload,
            sigHeader:     $sigHeader,
            parsedPayload: $parsed,
            gateway:       $gateway,
            providerName:  $provider,
        );
    } catch (\RuntimeException $e) {
        if (str_contains($e->getMessage(), 'signature')) {
            http_response_code(400);
            return ['error' => 'Invalid signature'];
        }
        error_log("webhook_payment [{$provider}] error: " . $e->getMessage());
        http_response_code(500);
        return ['error' => 'Processing failed'];
    }

    return ['status' => $result['duplicate'] ?? false ? 'already_processed' : 'ok'];
}

function apiWebhookShipping(): array {
    $carrier = Security::clean($_GET['carrier'] ?? '');
    if (!$carrier || !preg_match('/^[a-z0-9_]+$/', $carrier)) {
        http_response_code(400);
        return ['error' => 'Invalid carrier'];
    }

    $rawPayload = file_get_contents('php://input');
    if (!$rawPayload) {
        http_response_code(400);
        return ['error' => 'Empty payload'];
    }

    $parsed = json_decode($rawPayload, true);
    if ($parsed === null) {
        http_response_code(400);
        return ['error' => 'Invalid JSON'];
    }

    // Signature header varies by carrier
    $sigHeader = match($carrier) {
        'packeta' => $_SERVER['HTTP_X_PACKETA_SIGNATURE'] ?? '',
        'gls'     => $_SERVER['HTTP_X_GLS_SIGNATURE'] ?? '',
        default   => $_SERVER['HTTP_X_SHIPPING_SIGNATURE'] ?? '',
    };

    try {
        $result = ShippingService::handleTrackingWebhook($carrier, $parsed, $sigHeader, $rawPayload);
    } catch (\RuntimeException $e) {
        if (str_contains($e->getMessage(), 'signature')) {
            http_response_code(400);
            return ['error' => 'Invalid signature'];
        }
        error_log("webhook_shipping [{$carrier}] error: " . $e->getMessage());
        http_response_code(500);
        return ['error' => 'Processing failed'];
    }

    return $result;
}

// ============================================================
// TecDoc / Auto Parts API Handlers
// ============================================================

function apiVehicleManufacturers(): array {
    return ['manufacturers' => TecDoc::manufacturers()];
}

function apiVehicleModels(): array {
    $mfr = Security::clean($_GET['manufacturer'] ?? '');
    if (!$mfr) return ['error' => 'manufacturer required'];
    return ['models' => TecDoc::models($mfr)];
}

function apiVehicleTypes(): array {
    $mfr = Security::clean($_GET['manufacturer'] ?? '');
    $mdl = Security::clean($_GET['model'] ?? '');
    if (!$mfr || !$mdl) return ['error' => 'manufacturer and model required'];
    return ['types' => TecDoc::types($mfr, $mdl)];
}

function apiVehicleParts(): array {
    $ktype = Security::int($_GET['ktype'] ?? 0);
    if (!$ktype) return ['error' => 'ktype required'];

    $page = max(1, Security::int($_GET['page'] ?? 1));
    $catId = !empty($_GET['category_id']) ? Security::int($_GET['category_id']) : null;

    $result = TecDoc::partsByKtype($ktype, $page, 24, $catId);
    $result['categories'] = TecDoc::categoriesForKtype($ktype);
    $result['products'] = array_map(fn($p) => Products::enrich($p), $result['products']);
    return $result;
}

function apiOESearch(): array {
    $q = Security::clean($_GET['q'] ?? '');
    if (strlen($q) < 3) return ['error' => 'Minimum 3 characters'];

    $page = max(1, Security::int($_GET['page'] ?? 1));
    $result = TecDoc::searchByOE($q, $page, 24);
    $result['products'] = array_map(fn($p) => Products::enrich($p), $result['products']);
    return $result;
}

function apiParametricSearch(): array {
    $catId = Security::int($_GET['category_id'] ?? 0);
    if (!$catId) return ['error' => 'category_id required'];

    $page = max(1, Security::int($_GET['page'] ?? 1));
    $sort = Security::clean($_GET['sort'] ?? 'price_asc');
    $lang = I18n::locale();

    $priceMin = !empty($_GET['price_min']) ? Security::int($_GET['price_min']) : null;
    $priceMax = !empty($_GET['price_max']) ? Security::int($_GET['price_max']) : null;

    // Parse spec filters from query params: spec[Strana montáže]=L&spec[Materiál]=bavlna
    $specFilters = [];
    if (!empty($_GET['spec']) && is_array($_GET['spec'])) {
        foreach ($_GET['spec'] as $name => $val) {
            $name = Security::clean($name);
            if (is_array($val)) {
                $specFilters[$name] = array_map([Security::class, 'clean'], $val);
            } else {
                $specFilters[$name] = [Security::clean($val)];
            }
        }
    }

    $result = TecDoc::parametricSearch($catId, $page, 24, $sort, $specFilters, $priceMin, $priceMax, $lang);
    $result['products'] = array_map(fn($p) => Products::enrich($p), $result['products']);
    return $result;
}

function apiProductSpecs(): array {
    $id = Security::int($_GET['id'] ?? 0);
    if (!$id) return ['error' => 'id required'];
    $lang = I18n::locale();
    return ['specifications' => TecDoc::specifications($id, $lang)];
}

function apiProductOE(): array {
    $id = Security::int($_GET['id'] ?? 0);
    if (!$id) return ['error' => 'id required'];
    return ['oe_numbers' => TecDoc::oeNumbers($id)];
}

function apiProductVehicles(): array {
    $id = Security::int($_GET['id'] ?? 0);
    if (!$id) return ['error' => 'id required'];
    $limit = min(200, max(1, Security::int($_GET['limit'] ?? 50)));
    return ['vehicles' => TecDoc::vehiclesForProduct($id, $limit)];
}

function apiCookieConsent(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['error' => 'POST required'];
    }
    if (!Security::csrfValidate()) {
        http_response_code(403);
        return ['error' => 'Invalid CSRF token'];
    }
    $cats = [
        'necessary' => true, // always
        'analytics' => !empty($_POST['analytics']),
        'marketing' => !empty($_POST['marketing']),
    ];
    $method = (string)($_POST['method'] ?? 'preferences');
    if (!in_array($method, ['accept_all', 'reject_all', 'preferences'], true)) {
        $method = 'preferences';
    }
    $cid = (int)($_SESSION['customer_id'] ?? 0);
    GdprService::logCookieConsent([
        'customer_id' => $cid > 0 ? $cid : null,
        'session_id'  => session_id() ?: null,
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent'  => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        'categories'  => $cats,
        'method'      => $method,
    ]);
    return ['ok' => true, 'categories' => $cats, 'method' => $method];
}

function apiPage(): array {
    $slug = Security::slug($_GET['slug'] ?? '');
    if (!$slug) return ['error' => 'slug required'];
    $stmt = Db::get()->prepare("SELECT * FROM pages WHERE slug = :slug AND status = 'active'");
    $stmt->execute([':slug' => $slug]);
    $page = $stmt->fetch();
    if (!$page) { http_response_code(404); return ['error' => 'Page not found']; }
    // Translate if non-default locale
    $tr = json_decode($page['translations'] ?? '{}', true);
    $locale = I18n::locale();
    if ($locale !== I18n::sysDefault() && isset($tr[$locale])) {
        if (!empty($tr[$locale]['title'])) $page['title'] = $tr[$locale]['title'];
        if (!empty($tr[$locale]['body'])) $page['body'] = $tr[$locale]['body'];
    }
    return ['page' => ['slug' => $page['slug'], 'title' => $page['title'], 'body' => Security::sanitizeRichHtml((string)($page['body'] ?? ''))]];
}

// ============================================================
// BBF Dynamic Options API
// ============================================================

function apiBbfCategories(): array {
    $db = Db::get();
    $rows = $db->query("SELECT id, name, path FROM categories ORDER BY path, name")->fetchAll(PDO::FETCH_ASSOC);
    $options = [];
    foreach ($rows as $r) {
        $label = $r['path'] ? $r['path'] : $r['name'];
        $options[] = ['value' => (string)$r['id'], 'label' => $label];
    }
    return $options;
}

function apiBbfProductTypes(): array {
    $catalogFile = __DIR__ . '/catalog.json';
    $ptypes = file_exists($catalogFile)
        ? array_keys(json_decode(file_get_contents($catalogFile), true)['product_types'] ?? [])
        : ['simple'];
    $ptypes = array_unique(array_merge(['simple'], $ptypes));
    $options = [];
    foreach ($ptypes as $pt) {
        $options[] = ['value' => $pt, 'label' => ucfirst($pt)];
    }
    return $options;
}

function apiBbfAttrTypes(): array {
    return [
        ['value' => 'enum', 'label' => 'Enum (zoznam hodnôt)'],
        ['value' => 'number', 'label' => 'Číslo'],
        ['value' => 'boolean', 'label' => 'Áno/Nie'],
    ];
}

function apiBbfStaticOptions(array $options): array {
    return $options;
}
