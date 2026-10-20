<?php
/**
 * BareBones Shop — Storefront
 * Generic catalog; extensions can add their own pages.
 */
require_once dirname(__DIR__, 2) . '/src/autoload.php';

require_once __DIR__ . '/Storefront/CatalogPages.php';
require_once __DIR__ . '/Storefront/ProductStatusPages.php';
require_once __DIR__ . '/Storefront/CheckoutPages.php';
Security::headers();
Security::sessionStart();
Bootstrap::validateCustomerSession();
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

// Locale from URL — canonical, no session override
$lang = Bootstrap::initLocale();
$catName = static fn(string $name): string => Bootstrap::categoryName($name, $lang);

// One catalog powers PHP templates, theme blocks, and browser runtime strings.
$t = static fn(string $key): string => I18n::t($key);

// Cart actions (POST) — handle before any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['cart_action'])) {
    if (!Security::csrfValidate()) {
        http_response_code(403);
        exit('CSRF token mismatch');
    }
    $result = StorefrontCartAction::execute($_POST);
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        if (!$result['ok']) http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
    // No-JS form post: redirect back (a 422 status would drop the redirect) with a one-shot notice.
    if (($_POST['cart_action'] ?? '') === 'add') StorefrontCartAction::flash($result);
    $redirect = Security::safeLocalPath($_POST['redirect'] ?? null, Template::storeUrl('/'));
    header('Location: ' . $redirect);
    exit;
}

if (isset($_GET['cart_recover'])) StorefrontCartAction::recover(Security::str($_GET['cart_recover']));
$cartCount = StorefrontCartAction::count();

// Route: support both ?page=X and URL-rewritten ?category=slug
$page = Security::clean($_GET['page'] ?? '');
if (!$page && !empty($_GET['category'])) $page = 'category';
if (!$page && isset($_GET['cart_recover'])) $page = 'cart';
if (!$page && isset($_GET['cart_unsubscribe'])) $page = 'cart-unsubscribe';
if (!$page) $page = 'home';

$e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
// ============================================================
// Route & fetch data
// ============================================================
$title = Template::pageTitle($t('home'));
$content = '';

switch ($page) {
    case 'category':
        $slug = Security::clean($_GET['slug'] ?? $_GET['category'] ?? '');
        redirectCategoryTrailingSlash($slug);
        $cat = Categories::bySlug($slug);
        if ($cat) StorefrontSeo::redirectToCanonical($slug, (string)$cat['slug'], 'category');
        if (!$cat) {
            http_response_code(404);
            $title = Template::pageTitle($t('cat_not_found'));
            $content = Template::render('storefront/status-message', ['message' => $t('cat_not_found')]);
            break;
        }
        $title = Template::pageTitle($catName($cat['name']));
        $content = renderCategory($cat);
        break;

    case 'product':
        $slug = Security::clean($_GET['slug'] ?? '');
        $product = Products::bySlug($slug);
        if ($product) StorefrontSeo::redirectToCanonical($slug, (string)I18n::product($product)['slug'], 'product');
        if (!$product) {
            [$content, $title] = renderUnavailableProduct($slug);
            break;
        }
        $content = renderProduct($product);
        $title = Template::pageTitle((string)($GLOBALS['storefront_view_model']['page']['title'] ?? $product['name']));
        break;

    case 'search':
        $q = Security::clean($_GET['q'] ?? '');
        $pg = max(1, min(Search::MAX_PAGE, Security::int($_GET['pg'] ?? 1)));
        $searchFilters = Search::filtersFromQuery($_GET);
        $result = Adapters::search()->search($q, ['page' => $pg, 'per_page' => 24] + $searchFilters['opts']);
        $title = Template::pageTitle($t('search') . ': ' . $q);
        $content = renderSearch($q, $result, $searchFilters);
        break;

    case 'privacy':
    case 'terms':
    case 'cookies':
        $legalTitles = ['privacy' => $t('privacy_policy'), 'terms' => $t('terms_conditions'), 'cookies' => $t('cookie_policy')];
        $title = Template::pageTitle($legalTitles[$page]);
        $content = renderLegalPage($page);
        break;

    case 'cart':
        $title = Template::pageTitle($t('cart'));
        $content = renderCart();
        break;

    case 'cart-unsubscribe':
        $unsubscribed = StorefrontCartAction::unsubscribeReminder(Security::str($_GET['cart_unsubscribe'] ?? ''));
        $title = Template::pageTitle($t('cart'));
        $content = Template::render('storefront/status-message', ['message' => $t($unsubscribed ? 'cart_reminder_unsubscribed' : 'cart_reminder_unsubscribe_invalid')]);
        break;

    case 'checkout':
        $title = Template::pageTitle($t('checkout'));
        $content = renderCheckout();
        break;

    case 'checkout-cancel':
        $title = Template::pageTitle($t('checkout'));
        $content = renderCheckoutCancel();
        break;

    case 'order-done':
        $ordNum = Security::clean($_GET['order'] ?? '');
        $title = Template::pageTitle($t('order_placed'));
        $content = renderOrderDone($ordNum);
        break;

    case 'home':
        $content = renderHome();
        break;

    default: // a page registered by an extension ("<code>" or "<code>.<name>"), otherwise a real 404 (not the homepage with 200)
        $extensionPage = Extensions::page($page);
        if ($extensionPage === null) {
            http_response_code(404);
            $title = Template::pageTitle($t('account.page_not_found'));
            $content = Template::render('storefront/status-message', ['message' => $t('account.page_not_found')]);
            break;
        }
        $title = Template::pageTitle($extensionPage['title']);
        $content = $extensionPage['content'];
        break;
}


require __DIR__ . '/Storefront/Layout.php';
