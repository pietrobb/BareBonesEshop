<?php
/**
 * BareBones Shop — Product Detail Page (SSR)
 * SEO: meta tags, JSON-LD, og:*, canonical URL.
 * Archived products return 410 Gone.
 */
require_once __DIR__ . '/src/autoload.php';

Security::sessionStart();
Customers::isLoggedIn();
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

// Locale from URL — canonical, no session override
$lang = Bootstrap::initLocale();

$slug = preg_replace('/[^a-z0-9-]/', '', $_GET['slug'] ?? '');
if (!$slug) { http_response_code(404); exit; }

$product = Products::bySlug($slug);
if (!$product) {
    http_response_code(404);
    echo Template::page('404', ['t' => I18n::all()]);
    exit;
}

// Archived products → 410 Gone with related alternatives
if ($product['status'] === 'archived') {
    http_response_code(410);
    $related = Products::curatedRelated((int)$product['id'], 4) ?: Products::related((int)$product['id'], 4);
    $related = array_map(fn($p) => Products::enrich(I18n::product($p)), $related);
    echo Template::page('product-gone', [
        'product' => $product,
        'related' => $related,
        't'       => I18n::all(),
    ]);
    exit;
}

// Enrich product
$product = Products::enrich(I18n::product($product));
$product['description'] = Security::sanitizeRichHtml((string)($product['description'] ?? ''));
$product['attrs_list'] = Products::attrs((int)$product['id']);
$product['variants'] = Products::variants((int)$product['id']);

// Related products
$related = Products::curatedRelated((int)$product['id'], 4) ?: Products::related((int)$product['id'], 4);
$related = array_map(fn($p) => Products::enrich(I18n::product($p)), $related);

// Approved reviews + aggregate rating (storefront display + JSON-LD AggregateRating)
$reviews = Reviews::approved((int)$product['id']);
$reviewAgg = Reviews::aggregate((int)$product['id']);

// Wishlist state for the toggle button (customer- or session-scoped)
$inWishlist = Wishlist::has((int)$product['id']);

// Breadcrumb
$breadcrumb = [];
if (!empty($product['category_id'])) {
    $breadcrumb = Categories::breadcrumb((int)$product['category_id']);
    $breadcrumb = array_map(fn($c) => I18n::category($c), $breadcrumb);
}

// Cart count for header badge
$cart = Cart::enriched();

// Currency for JSON-LD
$stores = Bootstrap::stores();
$currencyCode = strtoupper($product['currency'] ?? $stores['default_currency'] ?? 'eur');

// Build canonical URL
$canonical = '/product/' . $product['slug'];

$t = I18n::all();

// CSRF token for cart actions
$csrfToken = Security::csrfToken();

// Availability for JSON-LD
$availability = $product['stock'] > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
$priceDecimal = number_format($product['price'] / 100, 2, '.', '');
$image = $product['image'] ? '/uploads/full/' . $product['image'] : '';

Security::headers();

$productLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Product',
    'name' => $product['name'],
    'description' => $product['summary'] ?? '',
    'image' => $image,
    'sku' => $product['sku'],
    'offers' => [
        '@type' => 'Offer',
        'price' => $priceDecimal,
        'priceCurrency' => $currencyCode,
        'availability' => $availability,
    ],
];
if ($reviewAgg['count'] > 0) {
    $productLd['aggregateRating'] = [
        '@type' => 'AggregateRating',
        'ratingValue' => $reviewAgg['avg'],
        'reviewCount' => $reviewAgg['count'],
        'bestRating' => 5,
        'worstRating' => 1,
    ];
}

$crumbItems = [['name' => $t['home'] ?? 'Home', 'url' => '/']];
foreach ($breadcrumb as $bc) {
    $crumbItems[] = ['name' => $bc['name'], 'url' => '/category/' . $bc['slug']];
}
$crumbItems[] = ['name' => $product['name']];

$attribute = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$seoHead = '<meta name="description" content="' . $attribute((string)($product['summary'] ?? '')) . '">' . "\n"
    . '<link rel="canonical" href="' . $attribute($canonical) . '">' . "\n"
    . '<meta property="og:type" content="product">' . "\n"
    . '<meta property="og:title" content="' . $attribute((string)$product['name']) . '">' . "\n"
    . '<meta property="og:description" content="' . $attribute((string)($product['summary'] ?? '')) . '">' . "\n"
    . ($image !== '' ? '<meta property="og:image" content="' . $attribute($image) . '">' . "\n" : '')
    . '<meta property="og:url" content="' . $attribute($canonical) . '">' . "\n"
    . '<meta property="product:price:amount" content="' . $attribute($priceDecimal) . '">' . "\n"
    . '<meta property="product:price:currency" content="' . $attribute($currencyCode) . '">' . "\n"
    . JsonLd::render($productLd, Security::cspNonce()) . "\n"
    . JsonLd::render(JsonLd::breadcrumbList($crumbItems), Security::cspNonce());

$bodyEnd = '<script nonce="' . $attribute(Security::cspNonce()) . '">window.BBS='
    . json_encode(['csrfToken' => $csrfToken, 'lang' => $lang], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
    . ';</script>';

ob_start();
?>
    <?php if (!empty($breadcrumb)): ?>
    <?= Template::render('global/breadcrumb', [
        'breadcrumb' => $breadcrumb,
        'current' => $product['name'],
        't' => $t,
    ]) ?>
    <?php endif; ?>

    <?= Template::render('product/product-detail', [
        'product' => $product,
        't' => $t,
        'csrf_token' => $csrfToken,
    ]) ?>

    <form class="wishlist-toggle-form" method="post" action="/wishlist"
          data-wishlist-toggle data-product-id="<?= (int)$product['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
        <input type="hidden" name="op" value="toggle">
        <input type="hidden" name="return_to" value="/product/<?= esc($product['slug']) ?>">
        <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
        <button type="submit" class="btn btn-secondary"
                data-wishlist-btn aria-pressed="<?= $inWishlist ? 'true' : 'false' ?>">
            <?= $inWishlist
                ? esc($t['wishlist.in'] ?? 'In wishlist ♥')
                : esc($t['wishlist.add'] ?? 'Add to wishlist ♡') ?>
        </button>
    </form>

    <?php if (!empty($product['attrs_list'])): ?>
    <?= Template::render('product/product-attributes', [
        'attrs' => $product['attrs_list'],
        't' => $t,
    ]) ?>
    <?php endif; ?>

    <?php
        // ---- Product reviews ----
        $loggedIn = !empty($_SESSION['customer_id']);
        $stars = static function (float $value): string {
            $full = (int)floor($value);
            $out = '';
            for ($i = 1; $i <= 5; $i++) { $out .= $i <= $full ? '★' : '☆'; }
            return $out;
        };
    ?>
    <section class="reviews" id="reviews">
        <h2><?= esc($t['reviews.title'] ?? 'Reviews') ?></h2>
        <?php if ($reviewAgg['count'] > 0): ?>
        <p class="reviews-summary">
            <span class="stars" aria-hidden="true"><?= $stars((float)$reviewAgg['avg']) ?></span>
            <strong><?= esc(number_format((float)$reviewAgg['avg'], 1)) ?></strong>
            / 5 (<?= (int)$reviewAgg['count'] ?>)
        </p>
        <?php else: ?>
        <p class="reviews-empty"><?= esc($t['reviews.none'] ?? 'No reviews yet. Be the first!') ?></p>
        <?php endif; ?>

        <?php foreach ($reviews as $rv): ?>
        <article class="review">
            <header>
                <span class="stars" aria-hidden="true"><?= $stars((float)$rv['rating']) ?></span>
                <strong><?= esc($rv['author_name']) ?></strong>
                <time class="muted"><?= esc(substr((string)$rv['created_at'], 0, 10)) ?></time>
            </header>
            <?php if (!empty($rv['title'])): ?><h3><?= esc($rv['title']) ?></h3><?php endif; ?>
            <p><?= nl2br(esc($rv['body'])) ?></p>
        </article>
        <?php endforeach; ?>

        <form class="review-form" data-review-form data-product-id="<?= (int)$product['id'] ?>">
            <h3><?= esc($t['reviews.write'] ?? 'Write a review') ?></h3>
            <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
            <label>
                <?= esc($t['reviews.rating'] ?? 'Rating') ?>
                <select name="rating" required>
                    <?php for ($i = 5; $i >= 1; $i--): ?>
                    <option value="<?= $i ?>"><?= str_repeat('★', $i) ?> (<?= $i ?>)</option>
                    <?php endfor; ?>
                </select>
            </label>
            <?php if (!$loggedIn): ?>
            <label>
                <?= esc($t['reviews.author'] ?? 'Your name') ?>
                <input type="text" name="author_name" maxlength="80" required>
            </label>
            <?php endif; ?>
            <label>
                <?= esc($t['reviews.review_title'] ?? 'Title') ?>
                <input type="text" name="title" maxlength="150">
            </label>
            <label>
                <?= esc($t['reviews.body'] ?? 'Your review') ?>
                <textarea name="body" rows="4" minlength="3" maxlength="2000" required></textarea>
            </label>
            <button type="submit" class="btn btn-primary"><?= esc($t['reviews.submit'] ?? 'Submit review') ?></button>
            <p class="review-form-msg" data-review-msg hidden></p>
        </form>
    </section>

    <?php if (!empty($related)): ?>
    <?= Template::render('product/related-products', [
        'products' => $related,
        't' => $t,
    ]) ?>
    <?php endif; ?>
<?php
$content = ob_get_clean();
echo Template::document($product['name'] . ' | BareBones Shop', $content, [
    'seo_head' => $seoHead,
    'body_end' => $bodyEnd,
    'cart' => ['count' => $cart['count']],
]);
?>
