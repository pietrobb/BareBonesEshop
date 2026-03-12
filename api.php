<?php
/**
 * BareBones Shop — API Router
 * All dynamic operations: products, categories, search, cart.
 * Cache-first, JSON responses, CSRF for POST.
 */
require_once __DIR__ . '/src/Db.php';
require_once __DIR__ . '/src/Security.php';
require_once __DIR__ . '/src/I18n.php';
require_once __DIR__ . '/src/Products.php';
require_once __DIR__ . '/src/Categories.php';
require_once __DIR__ . '/src/Search.php';
require_once __DIR__ . '/src/Cache.php';
require_once __DIR__ . '/src/Orders.php';
require_once __DIR__ . '/src/OrderWorkflow.php';
require_once __DIR__ . '/src/AuditLog.php';
require_once __DIR__ . '/src/Idempotency.php';
require_once __DIR__ . '/src/JobQueue.php';
require_once __DIR__ . '/src/Cart.php';
require_once __DIR__ . '/src/Pricing.php';
require_once __DIR__ . '/src/Shipping.php';
require_once __DIR__ . '/src/Tax.php';
require_once __DIR__ . '/src/TecDoc.php';

// Security headers
Security::headers();

// JSON response
header('Content-Type: application/json; charset=utf-8');

// Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Detect locale
$uri = $_SERVER['REQUEST_URI'] ?? '/';
I18n::setLocale(I18n::detectLocale($uri));

$cfg = require __DIR__ . '/config.php';
$action = $_GET['action'] ?? '';

// Rate limiting for all API calls
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
        'shipping_methods'=> apiShippingMethods(),
        'webhook_stripe'  => apiWebhookStripe(),
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
        default                 => ['error' => 'Unknown action'],
    };

    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal error']);
    error_log("API error [{$action}]: " . $e->getMessage());
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

    // Translate & enrich products
    $data['products'] = array_map(function($p) {
        return Products::enrich(I18n::product($p));
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

    $data = Search::query($q, $page);
    $data['products'] = array_map(function($p) {
        return Products::enrich(I18n::product($p));
    }, $data['products']);

    return $data;
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
    return [
        'items'    => $_SESSION['cart'] ?? [],
        'count'    => array_sum(array_column($_SESSION['cart'] ?? [], 'qty')),
        'subtotal' => 0, // Will be enriched in Phase 3
    ];
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

    $key = $variantId ? "v_{$variantId}" : "p_{$productId}";
    if (!isset($_SESSION['cart'][$key])) {
        $_SESSION['cart'][$key] = [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'qty'        => 0,
        ];
    }
    $_SESSION['cart'][$key]['qty'] += $qty;

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
    $qty = Security::int($input['qty'] ?? 0);

    if ($qty <= 0) {
        unset($_SESSION['cart'][$key]);
    } else {
        if (isset($_SESSION['cart'][$key])) {
            $_SESSION['cart'][$key]['qty'] = min(99, $qty);
        }
    }

    return ['success' => true, 'cart' => apiCart()];
}

function apiCartRestore(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!is_array($input)) return ['error' => 'Invalid data'];

    foreach ($input as $item) {
        $productId = Security::int($item['product_id'] ?? 0);
        $variantId = isset($item['variant_id']) ? Security::int($item['variant_id']) : null;
        $qty = max(1, Security::int($item['qty'] ?? 1));

        if ($productId > 0) {
            $key = $variantId ? "v_{$variantId}" : "p_{$productId}";
            $_SESSION['cart'][$key] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'qty'        => $qty,
            ];
        }
    }

    return ['success' => true, 'cart' => apiCart()];
}

function apiCartEnriched(): array {
    return Cart::enriched();
}

function apiShippingMethods(): array {
    $country = strtoupper(trim($_GET['country'] ?? 'SK'));
    $subtotal = Security::int($_GET['subtotal'] ?? 0);
    $methods = Shipping::availableMethods($country, $subtotal);
    return ['methods' => $methods];
}

function apiCheckout(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['error' => 'Method not allowed'];
    }

    if (!Security::csrfValidate()) {
        http_response_code(403);
        return ['error' => 'Invalid CSRF token'];
    }

    if (!Security::rateLimit('checkout', 10)) {
        return ['error' => 'Too many checkout attempts'];
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $name = trim($input['name'] ?? '');
    $email = Security::email($input['email'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $country = strtoupper(trim($input['country'] ?? 'SK'));
    $shippingMethod = $input['shipping_method'] ?? 'standard';
    $paymentMethod = $input['payment_method'] ?? 'stripe';

    if (!$name || !$email) {
        return ['error' => 'Name and email are required'];
    }

    $cart = Cart::enriched();
    if (empty($cart['items'])) {
        return ['error' => 'Cart is empty'];
    }

    $totalWeight = 0;
    foreach ($cart['items'] as $item) {
        $product = Products::byId($item['product_id']);
        $totalWeight += ($product['weight_g'] ?? 0) * $item['qty'];
    }
    $shippingCost = Shipping::calculate($shippingMethod, $country, $totalWeight, $cart['subtotal']);
    if ($shippingCost < 0) $shippingCost = 0;

    $autoRules = Pricing::autoCartRules($cart['subtotal']);
    foreach ($autoRules as $rule) {
        if ($rule['action'] === 'free_shipping') $shippingCost = 0;
    }

    $taxBreakdown = Tax::orderBreakdown($cart['items'], $country);

    $customer = [
        'name'          => $name,
        'email'         => $email,
        'phone'         => $phone,
        'address'       => [
            'street'  => trim($input['street'] ?? ''),
            'city'    => trim($input['city'] ?? ''),
            'zip'     => trim($input['zip'] ?? ''),
            'country' => $country,
        ],
        'currency'      => $cart['items'][0]['currency'] ?? 'eur',
        'shipping_cost' => $shippingCost,
        'tax_amount'    => $taxBreakdown['total_tax'],
    ];

    try {
        $orderId = Orders::create($cart['items'], $customer, $paymentMethod);
        Cart::clear();

        $order = Orders::byId($orderId);
        JobQueue::push('email_send', ['template' => 'order-confirmation', 'to' => $email, 'order_id' => $orderId]);
        JobQueue::push('email_send', ['template' => 'admin-notification', 'to' => 'admin', 'order_id' => $orderId]);

        return [
            'success'      => true,
            'order_id'     => $orderId,
            'order_number' => $order['order_number'],
            'total'        => (int)$order['total'],
        ];
    } catch (\RuntimeException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function apiWebhookStripe(): array {
    $payload = file_get_contents('php://input');
    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $cfg = require __DIR__ . '/config.php';
    $secret = $cfg['stripe_webhook_secret'] ?? '';

    if ($secret && $sigHeader) {
        if (!verifyStripeSignature($payload, $sigHeader, $secret)) {
            http_response_code(400);
            return ['error' => 'Invalid signature'];
        }
    }

    $event = json_decode($payload, true);
    if (!$event || empty($event['id']) || empty($event['type'])) {
        http_response_code(400);
        return ['error' => 'Invalid payload'];
    }

    if (Idempotency::webhookProcessed($event['id'])) {
        return ['status' => 'already_processed'];
    }

    try {
        match($event['type']) {
            'checkout.session.completed' => onPaymentCompleted($event['data']['object'] ?? []),
            'charge.refunded'            => onChargeRefunded($event['data']['object'] ?? []),
            'charge.dispute.created'     => onDisputeCreated($event['data']['object'] ?? []),
            default                      => null,
        };
    } catch (\Throwable $e) {
        error_log("Webhook error [{$event['type']}]: {$e->getMessage()}");
    }

    return ['status' => 'ok'];
}

function onPaymentCompleted(array $session): void {
    $orderId = (int)($session['metadata']['order_id'] ?? 0);
    if (!$orderId) return;

    $order = Orders::byId($orderId);
    if (!$order || $order['status'] !== 'pending') return;

    Orders::setPaymentId($orderId, $session['payment_intent'] ?? $session['id'] ?? '');
    OrderWorkflow::transition($orderId, 'paid', 'Stripe webhook: ' . ($session['id'] ?? ''));

    JobQueue::push('email_send', ['template' => 'order-confirmation', 'to' => $order['customer_email'], 'order_id' => $orderId]);
}

function onChargeRefunded(array $charge): void {
    $paymentIntent = $charge['payment_intent'] ?? '';
    if (!$paymentIntent) return;

    $stmt = Db::get()->prepare("SELECT * FROM orders WHERE payment_id = :pid LIMIT 1");
    $stmt->execute([':pid' => $paymentIntent]);
    $order = $stmt->fetch();
    if (!$order) return;

    if (OrderWorkflow::canTransition($order['status'], 'refunded')) {
        OrderWorkflow::transition((int)$order['id'], 'refunded', 'Stripe refund webhook');
    }
}

function onDisputeCreated(array $dispute): void {
    $paymentIntent = $dispute['payment_intent'] ?? '';
    if (!$paymentIntent) return;

    $stmt = Db::get()->prepare("SELECT * FROM orders WHERE payment_id = :pid LIMIT 1");
    $stmt->execute([':pid' => $paymentIntent]);
    $order = $stmt->fetch();
    if (!$order) return;

    AuditLog::log('order_dispute', (int)$order['id'], $order['status'], 'disputed', 'Stripe dispute: ' . ($dispute['id'] ?? ''));
}

function verifyStripeSignature(string $payload, string $sigHeader, string $secret): bool {
    $parts = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$key, $val] = explode('=', trim($part), 2) + [1 => ''];
        $parts[$key] = $val;
    }

    $timestamp = $parts['t'] ?? '';
    $signature = $parts['v1'] ?? '';
    if (!$timestamp || !$signature) return false;

    if (abs(time() - (int)$timestamp) > 300) return false;

    $signedPayload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $secret);

    return hash_equals($expected, $signature);
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
