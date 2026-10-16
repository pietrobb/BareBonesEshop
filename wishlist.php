<?php
/**
 * BareBones Shop — Wishlist Page (SSR)
 *
 * Lists the current owner's saved products (logged-in customer or anonymous
 * session). Removal and the product-page toggle are CSRF-guarded POSTs to this page
 * (JSON clients use api wishlist_toggle), so the page works without scripting.
 */
require_once __DIR__ . '/src/autoload.php';

Security::headers();
Security::sessionStart();
Customers::isLoggedIn();
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

$lang = Bootstrap::initLocale();

// No-JS POST fallback (CSRF-guarded). op=toggle from the product page,
// op=remove (default) from the wishlist page itself.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = Security::str($_POST['op'] ?? 'remove', 'remove');
    $pid = Security::int($_POST['product_id'] ?? 0);
    // Only an existing product of this store can be toggled, and only an active one added (same rule as the JSON API).
    $product = $pid > 0 ? Products::byId($pid) : null;
    if (Security::csrfValidate() && $pid > 0) {
        if ($op !== 'toggle') Wishlist::remove($pid);
        elseif ($product && (($product['status'] ?? '') === 'active' || Wishlist::has($pid))) Wishlist::toggle($pid);
    }
    // return_to is the product URL the theme rendered (store prefix, base path and aliases included):
    // honour it only as a same-site absolute path, otherwise rebuild the product URL server-side.
    $back = Template::storeUrl('/wishlist.php');
    if ($op === 'toggle' && $product) {
        $return = Security::safeLocalPath($_POST['return_to'] ?? '', '');
        $back = str_starts_with($return, '/') ? $return : StorefrontViewModel::productUrl((string)$product['slug']);
    }
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
