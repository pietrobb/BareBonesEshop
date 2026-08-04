<?php
/**
 * BareBones Shop — Storefront
 * Generic catalog with conditional TecDoc auto-parts features.
 */
require_once dirname(__DIR__, 2) . '/src/autoload.php';

require_once __DIR__ . '/Storefront/CatalogPages.php';
require_once __DIR__ . '/Storefront/CheckoutPages.php';
Security::headers();
Security::sessionStart();
Customers::isLoggedIn();
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

// Locale from URL — canonical, no session override
$lang = Bootstrap::initLocale();

// Legacy category-name fallback for categories not yet migrated to
// categories.translations JSON (see data/category-translations.php).
$catTranslations = Bootstrap::catTranslations();

// Build DB-backed name→locale→translated lookup from categories.translations JSON
// (populated via `php cli/migrate-cat-translations.php`). Falls back to hardcoded
// $catTranslations array for categories not yet migrated.
$catTranslationsDb = [];
try {
    $catRows = Db::get()->query("SELECT name, translations FROM categories WHERE translations IS NOT NULL AND translations != '' AND translations != '{}'")->fetchAll();
    foreach ($catRows as $cr) {
        $tr = json_decode($cr['translations'] ?? '{}', true) ?: [];
        foreach ($tr as $lc => $data) {
            if (!empty($data['name'])) {
                $catTranslationsDb[$lc][$cr['name']] = $data['name'];
            }
        }
    }
} catch (\Throwable $ignore) { /* table missing during install */ }

$catName = function(string $name) use ($catTranslations, $catTranslationsDb, $lang): string {
    return $catTranslationsDb[$lang][$name] ?? $catTranslations[$lang][$name] ?? $name;
};

// One catalog powers PHP templates, theme blocks, and browser runtime strings.
$t = static fn(string $key): string => I18n::t($key);

// Cart actions (POST) — handle before any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['cart_action'])) {
    if (!Security::csrfValidate()) {
        http_response_code(403);
        exit('CSRF token mismatch');
    }
    $pid = Security::int($_POST['product_id'] ?? 0);
    $qty = max(0, Security::int($_POST['quantity'] ?? 1));
    switch ($_POST['cart_action']) {
        case 'add':
            if ($pid > 0 && $qty > 0) {
                $_SESSION['cart'][$pid] = ($_SESSION['cart'][$pid] ?? 0) + $qty;
            }
            break;
        case 'update':
            if ($pid > 0) {
                if ($qty > 0) $_SESSION['cart'][$pid] = $qty;
                else unset($_SESSION['cart'][$pid]);
            }
            break;
        case 'remove':
            unset($_SESSION['cart'][$pid]);
            break;
        case 'clear':
            $_SESSION['cart'] = [];
            break;
    }
    // AJAX request → return JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['count' => array_sum($_SESSION['cart']), 'cart' => $_SESSION['cart']]);
        exit;
    }
    // Regular POST → redirect back (validate: only relative URLs)
    $redirect = $_POST['redirect'] ?? '?';
    if (!str_starts_with($redirect, '?') && !str_starts_with($redirect, '/')) {
        $redirect = '?';
    }
    header('Location: ' . $redirect);
    exit;
}

$cartCount = array_sum($_SESSION['cart']);

// Detect catalog type — conditionally enable TecDoc features
$_catalog = json_decode(file_get_contents(dirname(__DIR__, 2) . '/catalog.json'), true);
$hasTecDoc = isset($_catalog['product_types']['auto_part']);

// Route: support both ?page=X and URL-rewritten ?category=slug
$page = Security::clean($_GET['page'] ?? '');
if (!$page && !empty($_GET['category'])) $page = 'category';
if (!$page) $page = 'home';

$e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$storeCurrency = StoreContext::current()->currency();
$price = fn(int $cents) => I18n::price($cents, $storeCurrency);
$csrfField = '<input type="hidden" name="csrf_token" value="' . Security::csrfToken() . '">';

// ============================================================
// Route & fetch data
// ============================================================
$title = 'BareBones Shop';
$content = '';

switch ($page) {
    case 'category':
        $slug = Security::clean($_GET['slug'] ?? $_GET['category'] ?? '');
        $cat = Categories::bySlug($slug);
        if (!$cat) { $content = '<p>' . $t('cat_not_found') . '</p>'; break; }
        $title = $e($catName($cat['name'])) . ' — BareBones Shop';
        $content = renderCategory($cat);
        break;

    case 'product':
        $slug = Security::clean($_GET['slug'] ?? '');
        $product = Products::bySlug($slug);
        if (!$product) { $content = '<p>' . $t('product_not_found') . '</p>'; break; }
        if ($hasTecDoc) $product = TecDoc::enrichProduct($product, $lang);
        $product = Products::enrich($product);
        $title = $e($product['name']) . ' — BareBones Shop';
        $content = renderProduct($product);
        break;

    case 'vehicle':
        if (!$hasTecDoc) { $content = '<p>Not available</p>'; break; }
        $ktype = Security::int($_GET['ktype'] ?? 0);
        $catId = !empty($_GET['cat']) ? Security::int($_GET['cat']) : null;
        $pg = max(1, Security::int($_GET['pg'] ?? 1));
        $result = TecDoc::partsByKtype($ktype, $pg, 24, $catId);
        $cats = TecDoc::categoriesForKtype($ktype);
        $title = $t('vehicle_parts') . ' — BareBones Shop';
        $content = renderVehicle($ktype, $result, $cats, $catId);
        break;

    case 'search':
        $q = Security::clean($_GET['q'] ?? '');
        $pg = max(1, Security::int($_GET['pg'] ?? 1));
        $result = Adapters::search()->search($q, ['page' => $pg, 'per_page' => 24]);
        $title = "{$t('search')}: {$e($q)} — BareBones Shop";
        $content = renderSearch($q, $result);
        break;

    case 'oe':
        if (!$hasTecDoc) { $content = '<p>Not available</p>'; break; }
        $q = Security::clean($_GET['q'] ?? '');
        $pg = max(1, Security::int($_GET['pg'] ?? 1));
        $result = TecDoc::searchByOE($q, $pg, 24);
        $title = "OE: {$e($q)} — BareBones Shop";
        $content = renderOE($q, $result);
        break;

    case 'privacy':
    case 'terms':
    case 'cookies':
        $legalTitles = ['privacy' => 'Privacy Policy', 'terms' => 'Terms & Conditions', 'cookies' => 'Cookie Policy'];
        $title = $legalTitles[$page] . ' — BareBones Shop';
        $content = renderLegalPage($page);
        break;

    case 'cart':
        $title = $t('cart') . ' — BareBones Shop';
        $content = renderCart();
        break;

    case 'checkout':
        $title = $t('checkout') . ' — BareBones Shop';
        $content = renderCheckout();
        break;

    case 'order-done':
        $ordNum = Security::clean($_GET['order'] ?? '');
        $title = $t('order_placed') . ' — BareBones Shop';
        $content = renderOrderDone($ordNum);
        break;

    default: // home
        $content = renderHome();
        break;
}


require __DIR__ . '/Storefront/Layout.php';
