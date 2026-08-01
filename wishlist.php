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

$bodyEnd = '<script nonce="' . esc(Security::cspNonce()) . '">window.BBS='
    . json_encode(['csrfToken' => $csrfToken, 'lang' => $lang], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
    . ';</script>';

ob_start();
?>
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
<?php
$content = ob_get_clean();
echo Template::document(($t['wishlist.title'] ?? 'Wishlist') . ' | BareBones Shop', $content, [
    'seo_head' => '<meta name="robots" content="noindex,follow">',
    'body_end' => $bodyEnd,
    'cart' => ['count' => $cart['count']],
]);
?>
