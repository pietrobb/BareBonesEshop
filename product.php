<?php
/**
 * BareBones Shop — Product Detail Page (SSR)
 * SEO: meta tags, JSON-LD, og:*, canonical URL.
 * Archived products return 410 Gone.
 */
require_once __DIR__ . '/src/autoload.php';

Security::sessionStart();
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
    $related = Products::related((int)$product['id'], 4);
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
$product['attrs_list'] = Products::attrs((int)$product['id']);
$product['variants'] = Products::variants((int)$product['id']);

// Related products
$related = Products::related((int)$product['id'], 4);
$related = array_map(fn($p) => Products::enrich(I18n::product($p)), $related);

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
?>
<!DOCTYPE html>
<html lang="<?= esc($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($product['name']) ?> | BareBones Shop</title>
    <meta name="description" content="<?= esc($product['summary'] ?? '') ?>">
    <link rel="canonical" href="<?= esc($canonical) ?>">

    <!-- Open Graph -->
    <meta property="og:type" content="product">
    <meta property="og:title" content="<?= esc($product['name']) ?>">
    <meta property="og:description" content="<?= esc($product['summary'] ?? '') ?>">
    <?php if ($image): ?>
    <meta property="og:image" content="<?= esc($image) ?>">
    <?php endif; ?>
    <meta property="og:url" content="<?= esc($canonical) ?>">
    <meta property="product:price:amount" content="<?= esc($priceDecimal) ?>">
    <meta property="product:price:currency" content="<?= esc($currencyCode) ?>">

    <!-- JSON-LD -->
    <script type="application/ld+json" nonce="<?= Security::cspNonce() ?>">
    <?= json_encode([
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
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
    </script>

    <link rel="stylesheet" href="/shop.css">
</head>
<body>
<?= Template::render('global/header', [
    't' => $t,
    'cart_count' => $cart['count'],
    'csrf_token' => $csrfToken,
    'lang' => $lang,
]) ?>

<main class="container">
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

    <?php if (!empty($product['attrs_list'])): ?>
    <?= Template::render('product/product-attributes', [
        'attrs' => $product['attrs_list'],
        't' => $t,
    ]) ?>
    <?php endif; ?>

    <?php if (!empty($related)): ?>
    <?= Template::render('product/related-products', [
        'products' => $related,
        't' => $t,
    ]) ?>
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
</body>
</html>
