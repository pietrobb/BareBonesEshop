<?php
/** Store-scoped CMS page rendered from a canonical model inside the theme's storefront shell. */
require_once __DIR__ . '/src/autoload.php';

UrlAliases::redirectInternal();
Security::headers();
Security::sessionStart();
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

$lang = Bootstrap::initLocale();
$slug = Security::slug($_GET['slug'] ?? '');
$cmsPage = $slug !== '' ? ContentService::page($slug, $lang) : null;
$t = static fn(string $key): string => I18n::t($key);
$e = static fn(string $value): string => esc($value);
$catName = static fn(string $name): string => Bootstrap::categoryName($name, $lang);
$cartCount = StorefrontCartAction::count();
$page = 'cms';

if (!$cmsPage) {
    http_response_code(404);
    $title = Template::pageTitle($t('account.page_not_found'));
    // A status block, not the full-page 404 layout: the storefront shell already provides header and footer.
    $content = Template::render('storefront/status-message', ['message' => $t('account.page_not_found')]);
} else {
    $model = StorefrontViewModel::cmsPage($cmsPage);
    $template = (string)$model['page']['template'];
    if (!isset(Template::loadLayouts()['pages'][$template])) $template = 'cms';
    $content = Template::page($template, $model);
    $title = Template::pageTitle((string)$model['page']['seo']['title']);
    // Layout.php renders the canonical SEO head (canonical URL, description, JSON-LD) from this model.
    $GLOBALS['storefront_view_model'] = $model;
}

require __DIR__ . '/src/Entrypoints/Storefront/Layout.php';
