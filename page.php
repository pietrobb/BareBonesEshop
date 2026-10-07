<?php
/** Store-scoped CMS page rendered from a canonical model by the active theme. */
require_once __DIR__ . '/src/autoload.php';

UrlAliases::redirectInternal();
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

$model = StorefrontViewModel::cmsPage($page);
$template = (string)$model['page']['template'];
if (!isset(Template::loadLayouts()['pages'][$template])) $template = 'cms';
$content = Template::page($template, $model);
$seo = $model['page']['seo'];
$cartCount = 0;
foreach ((array)$_SESSION['cart'] as $quantity) $cartCount += max(0, (int)$quantity);
$shopName = (string)(StoreContext::current()->config()['name'] ?? 'BareBones Shop');

echo Template::document((string)$seo['title'] . ' — ' . $shopName, $content, [
    'seo_head' => Template::viewModelSeoHead($model, (string)$seo['title']),
    'cart' => ['count' => $cartCount],
]);
