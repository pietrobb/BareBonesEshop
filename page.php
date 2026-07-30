<?php
/**
 * BareBones Shop — CMS Page (SSR)
 * Static content pages: about, contact, terms, etc.
 */
require_once __DIR__ . '/src/autoload.php';

Security::headers();
Security::sessionStart();
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

// Locale from URL
$lang = Bootstrap::initLocale();

$slug = Security::slug($_GET['slug'] ?? '');
if (!$slug) { http_response_code(404); exit; }

$db = Db::get();
$stmt = $db->prepare("SELECT * FROM pages WHERE slug = :slug AND status = 'active'");
$stmt->execute([':slug' => $slug]);
$page = $stmt->fetch();

if (!$page) {
    http_response_code(404);
    exit('Page not found');
}

// Translate
$tr = json_decode($page['translations'] ?? '{}', true);
$cfg = require __DIR__ . '/config.php';
if ($lang !== I18n::sysDefault() && isset($tr[$lang])) {
    if (!empty($tr[$lang]['title'])) $page['title'] = $tr[$lang]['title'];
    if (!empty($tr[$lang]['body'])) $page['body'] = $tr[$lang]['body'];
}

$t = I18n::all();
$pageTitle = Security::esc($page['title']) . ' — ' . ($cfg['shop_name'] ?? 'BareBones Shop');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="/shop.css">
</head>
<body>
<main class="container" style="max-width:800px;margin:2rem auto;padding:0 1rem">
    <h1><?= Security::esc($page['title']) ?></h1>
    <div class="cms-content">
        <?= Security::sanitizeRichHtml((string)$page['body']) ?>
    </div>
</main>
<script src="/shop.js"></script>
</body>
</html>
