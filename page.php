<?php
/** Store-scoped CMS/landing page rendered through the active theme package. */
require_once __DIR__ . '/src/autoload.php';

Security::headers();
Security::sessionStart();
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

$lang = Bootstrap::initLocale();
$slug = Security::slug($_GET['slug'] ?? '');
$page = $slug !== '' ? ContentService::page($slug, $lang) : null;
if (!$page) {
    http_response_code(404);
    echo Template::page('404', ['t' => I18n::all()]);
    exit;
}

$cfg = require __DIR__ . '/config.php';
$store = StoreContext::current();
$title = trim((string)($page['seo_title'] ?? '')) ?: (string)$page['title'];
$documentTitle = $title . ' — ' . ($cfg['shop_name'] ?? $store->config()['name'] ?? 'BareBones Shop');
$body = Security::sanitizeRichHtml((string)($page['body'] ?? ''));
$description = trim((string)($page['meta_description'] ?? '')) ?: mb_substr(trim(strip_tags($body)), 0, 320);
$prefix = rtrim((string)($store->config()['url_prefix'] ?? ''), '/');
$path = $prefix . '/page/' . rawurlencode((string)$page['slug']);
$cartCount = 0;
foreach ((array)$_SESSION['cart'] as $quantity) $cartCount += max(0, (int)$quantity);

$content = '<article class="cms-content cms-content--' . esc((string)($page['page_type'] ?? 'content')) . '">'
    . '<h1>' . esc((string)$page['title']) . '</h1>' . $body . '</article>';
echo Template::document($documentTitle, $content, [
    'seo_head' => Seo::head($title, $description, $path, ($page['status'] ?? '') === 'active'),
    'cart' => ['count' => $cartCount],
]);
