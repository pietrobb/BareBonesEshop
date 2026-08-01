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
        ? $return
        : '/wishlist';
    header('Location: ' . $back);
    exit;
}

$items = Wishlist::list();
$cart = Cart::enriched();
$t = I18n::all();
$csrfToken = Security::csrfToken();

Security::headers();
?>
<!DOCTYPE html>
<html lang="<?= esc($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($t['wishlist.title'] ?? 'Wishlist') ?> | BareBones Shop</title>
    <meta name="robots" content="noindex,follow">
    <link rel="stylesheet" href="/shop.css">
    <?php foreach (Template::assets()['css'] as $themeCss): ?><link rel="stylesheet" href="<?= esc($themeCss) ?>"><?php endforeach; ?>
</head>
<body>
<a class="skip-link" href="#main-content"><?= esc(I18n::t('skip_to_content')) ?></a>
<?= Template::render('global/header', [
    't' => $t,
    'shop_name' => StoreContext::current()->config()['name'] ?? 'BareBones Shop',
    'navigation' => ContentService::menu('primary', $lang),
    'cart' => ['count' => $cart['count']],
    'csrf_token' => $csrfToken,
    'lang' => $lang,
]) ?>

<main id="main-content" class="container" tabindex="-1">
    <h1><?= esc($t['wishlist.title'] ?? 'My Wishlist') ?></h1>

    <?php if (empty($items)): ?>
    <p class="wishlist-empty"><?= esc($t['wishlist.empty'] ?? 'Your wishlist is empty.') ?></p>
    <?php else: ?>
    <ul class="wishlist-grid" data-wishlist>
        <?php foreach ($items as $p): ?>
        <li class="wishlist-item" data-product-id="<?= (int)$p['id'] ?>">
            <a href="/product/<?= esc($p['slug']) ?>" class="wishlist-link">
                <?php if (!empty($p['image'])): ?>
                <img src="/uploads/thumb/<?= esc($p['image']) ?>" alt="<?= esc($p['name']) ?>" loading="lazy">
                <?php endif; ?>
                <span class="wishlist-name"><?= esc($p['name']) ?></span>
            </a>
            <span class="wishlist-price"><?= esc(I18n::price((int)$p['price'], $p['currency'] ?? null)) ?></span>
            <form method="post" action="/wishlist" class="wishlist-remove-form" data-wishlist-remove data-product-id="<?= (int)$p['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
                <input type="hidden" name="op" value="remove">
                <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="btn btn-secondary btn-sm"><?= esc($t['wishlist.remove'] ?? 'Remove') ?></button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</main>

<?= Template::render('global/footer', ['t' => $t]) ?>

<script nonce="<?= Security::cspNonce() ?>">
    window.BBS = {
        csrfToken: <?= json_encode($csrfToken) ?>,
        lang: <?= json_encode($lang) ?>,
    };
</script>
<script src="/shop.js"></script>
<?php foreach (Template::assets()['js'] as $themeJs): ?><script src="<?= esc($themeJs) ?>" defer></script><?php endforeach; ?>
<?= MarketingConsent::hooks($_COOKIE, Security::cspNonce()) ?>
</body>
</html>
