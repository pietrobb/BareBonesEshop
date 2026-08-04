<?php
/**
 * BareBones Shop — Wishlist Page (SSR)
 *
 * Lists the current owner's saved products (logged-in customer or anonymous
 * session). Removal is progressive-enhancement via shop.js → api wishlist_toggle;
 * a no-JS POST fallback keeps the page functional without scripting.
 */
require_once __DIR__ . '/src/autoload.php';

Security::sessionStart();
Customers::isLoggedIn();
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

$lang = Bootstrap::initLocale();

// No-JS POST fallback (CSRF-guarded). op=toggle from the product page,
// op=remove (default) from the wishlist page itself.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Security::csrfValidate()) {
        $pid = Security::int($_POST['product_id'] ?? 0);
        $op  = (string)($_POST['op'] ?? 'remove');
        if ($pid > 0) {
            $op === 'toggle' ? Wishlist::toggle($pid) : Wishlist::remove($pid);
        }
    }
    // Only honour a local /product/... return target to avoid open redirects.
    $return = (string)($_POST['return_to'] ?? '');
    $back = (($_POST['op'] ?? '') === 'toggle' && preg_match('~^/product/[a-z0-9-]+/?$~', $return))
        ? Template::storeUrl($return)
        : Template::storeUrl('/wishlist.php');
    header('Location: ' . $back);
    exit;
}

$translations = I18n::all();
$csrfToken = Security::csrfToken();
$cards = array_map([StorefrontViewModel::class, 'productCard'], Wishlist::list());
$content = Template::page('wishlist', [
    'wishlist' => [
        'items' => $cards,
        'action_url' => Template::storeUrl('/wishlist.php'),
        'csrf_token' => $csrfToken,
    ],
    't' => $translations,
]);
$title = Template::pageTitle((string)($translations['wishlist.title'] ?? 'Wishlist'));
$cartCount = StorefrontCartAction::count();
$t = static fn(string $key): string => I18n::t($key);
$e = static fn(string $value): string => esc($value);
$catName = static fn(string $name): string => Bootstrap::categoryName($name, $lang);
$page = 'wishlist';
require __DIR__ . '/src/Entrypoints/Storefront/Layout.php';
