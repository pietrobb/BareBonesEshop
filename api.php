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
        'cart_restore' => apiCartRestore(),
        default      => ['error' => 'Unknown action'],
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
