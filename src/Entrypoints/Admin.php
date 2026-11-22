<?php
/** BareBones Shop admin panel: session/MFA auth (Security::adminAuth), permission map, POST dispatch and page layout. */
require_once dirname(__DIR__, 2) . '/src/autoload.php';
WebWorker::afterResponse();

// Shared helpers, POST dispatch and store scope load on every request; page modules load per route below.
require_once __DIR__ . '/Admin/Shared.php';
require_once __DIR__ . '/Admin/LayoutScript.php';
require_once __DIR__ . '/Admin/Request.php';
require_once __DIR__ . '/Admin/StoreScope.php';
// Auth
Security::adminAuth();

// Security headers — admin inherits the nonce-based CSP from Security::headers()
// (script-src 'self' 'nonce-…'); all admin inline <script> carry the request
// nonce and inline on*= handlers were delegated, so no 'unsafe-inline' is needed.
Security::headers();

// Store scope: one granted store per request; a foreign ?store= / POST store is refused (Admin/StoreScope.php).
$storesCfg = Bootstrap::stores();
$adminStores = adminActivateStore($storesCfg);
$pagePermissions = require __DIR__ . '/Admin/Permissions.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['admin_switch_store'])) handleStoreSwitch($pagePermissions);

// Admin locale: URL ?lang=xx (persisted in session) → session → system default.
$adminSupportedLocales = [];
foreach ($storesCfg['stores'] ?? [] as $s) {
    foreach ($s['languages'] ?? [] as $lc) $adminSupportedLocales[$lc] = true;
}
$adminSupportedLocales = array_keys($adminSupportedLocales) ?: [$storesCfg['default_locale'] ?? 'en'];
if (!empty($_GET['lang']) && in_array($_GET['lang'], $adminSupportedLocales, true)) {
    $_SESSION['admin_locale'] = $_GET['lang'];
}
$adminLocale = $_SESSION['admin_locale'] ?? ($storesCfg['default_locale'] ?? 'en');
I18n::setLocale($adminLocale);
$adminConfig = require AppConfig::path(dirname(__DIR__, 2));
$adminAppName = trim((string)($adminConfig['admin_name'] ?? '')) ?: I18n::t('admin.app_name');

// p[]=… / action[]=… arrive as arrays; treat them like unknown pages instead of an array-key TypeError.
$page = Security::str($_GET['p'] ?? 'dashboard', 'dashboard');
$action = Security::str($_GET['action'] ?? '');
// Only the modules behind this route (Admin/Modules.php); an unknown page renders the dashboard.
$adminModules = require __DIR__ . '/Admin/Modules.php';
foreach ($adminModules[$page] ?? $adminModules['dashboard'] as $adminModule) require_once __DIR__ . '/Admin/' . $adminModule . '.php';

// CSV export — POST only, so the CSRF token travels in the request body
// instead of the URL (URLs leak via Referer header, browser history and
// access/proxy logs). Security::csrfValidate() accepts $_POST or X-CSRF-Token.
if (in_array($page, ['products', 'orders'], true) && $action === 'export' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Security::csrfValidate()) {
        http_response_code(403);
        exit('Forbidden');
    }
    $page === 'orders' ? handleOrdersExport() : handleExport();
}

// Role-based permission map
$requiredPerm = $pagePermissions[$page] ?? 'reports.view';
// The plain admin.php (the landing page after login) sends a role without reports.view (editor,
// fulfillment) to the first menu page it may open instead of a 403.
if ($page === 'dashboard' && !isset($_GET['p']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && !Security::adminCan($requiredPerm) && ($adminLanding = Security::adminLandingPage()) !== null) {
    header('Location: admin.php?p=' . $adminLanding);
    exit;
}
// '' (my_account): any signed-in admin; the page acts only on the signed-in account.
if ($requiredPerm !== '') Security::adminRequire($requiredPerm);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handlePost($page, $action);
}

// Binary downloads leave before the HTML layout starts, otherwise the PDF is wrapped in admin markup.
if ($page === 'invoice_pdf' || $page === 'delivery_note') {
    $page === 'invoice_pdf' ? pageInvoicePdf() : pageDeliveryNotes();
    exit;
}
if ($page === 'import' && $action === 'template') {
    handleImportTemplate();
    exit;
}

// Render page — buffered: a fatal error mid-render (e.g. memory limit) becomes a 500, never HTTP 200 with half a page.
ob_start();
register_shutdown_function('adminFatalPageGuard');
$title = match($page) {
    'dashboard'  => I18n::t('admin.title.dashboard'),
    'sales'      => I18n::t('admin.title.sales'),
    'reports'    => I18n::t('admin.title.reports'),
    'products'   => I18n::t('admin.title.products'),
    'product'    => isset($_GET['id']) ? I18n::t('admin.title.product_edit') : I18n::t('admin.title.product_new'),
    'orders'     => I18n::t('admin.title.orders'),
    'order'      => I18n::t('admin.title.order_detail'),
    'order_new'  => I18n::t('admin.title.order_new'),
    'awaiting_payment' => I18n::t('admin.title.awaiting_payment'),
    'abandoned_carts' => I18n::t('admin.title.abandoned_carts'),
    'import'     => I18n::t('admin.title.import'),
    'cache'      => I18n::t('admin.title.cache'),
    'audit'      => I18n::t('admin.title.audit_log'),
    'gdpr'       => I18n::t('admin.title.gdpr'),
    'pages'      => I18n::t('admin.title.cms_pages'),
    'page_edit'  => I18n::t('admin.title.cms_page_edit'),
    'content'    => I18n::t('admin.title.content'),
    'attributes' => I18n::t('admin.title.attributes'),
    'attribute'  => isset($_GET['id']) ? I18n::t('admin.title.attribute_edit') : I18n::t('admin.title.attribute_new'),
    'categories' => I18n::t('admin.title.categories'),
    'brands'     => I18n::t('admin.title.brands'),
    'promotions' => I18n::t('admin.title.promotions'),
    'price_lists' => I18n::t('admin.title.price_lists'),
    'companies'  => I18n::t('admin.title.companies'),
    'customer_groups' => I18n::t('admin.title.customer_groups'),
    'suppliers'  => I18n::t('admin.title.suppliers'),
    'purchase_orders' => I18n::t('admin.title.purchase_orders'),
    'warehouses' => I18n::t('admin.title.warehouses'),
    'warehouse'  => isset($_GET['id']) ? I18n::t('admin.title.warehouse_edit') : I18n::t('admin.title.warehouse_new'),
    'feeds'      => I18n::t('admin.title.feeds'),
    'feed'       => isset($_GET['id']) ? I18n::t('admin.title.feed_edit') : I18n::t('admin.title.feed_new'),
    'shipping'   => I18n::t('admin.title.shipping'),
    'payments'   => I18n::t('admin.title.payments'),
    'digital_assets' => I18n::t('admin.title.digital_assets'),
    'customers'  => I18n::t('admin.title.customers'),
    'customer'   => I18n::t('admin.title.customer'),
    'admin_users' => I18n::t('admin.title.admin_users'),
    'my_account' => I18n::t('admin.title.my_account'),
    'email_templates' => I18n::t('admin.title.email_templates'),
    'mail' => I18n::t('admin.title.mail'),
    'stores' => I18n::t('admin.nav.stores'),
    'reviews'    => I18n::t('admin.title.reviews'),
    'rma'        => I18n::t('admin.title.rma'),
    'complaints' => I18n::t('admin.title.complaints'),
    default      => I18n::t('admin.title.default'),
};

?><!DOCTYPE html>
<html lang="<?= esc($adminLocale) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($title) ?> — <?= esc($adminAppName) ?></title>
<style>
:root{--bg:#f4f5f7;--card:#fff;--text:#1d2330;--muted:#556070;--line:#e3e6eb;--dark:#172033;--dark2:#1f2a40;--dark3:#121a2b;--accent:#ea580c;--accent-d:#c2410c;--accent-dd:#9a3412;--accent-bg:#fff1e6;--radius:10px;--mono:"IBM Plex Mono",ui-monospace,"Cascadia Mono",Consolas,monospace}
*{box-sizing:border-box;margin:0;padding:0}
body{font:14px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--text)}
a{color:var(--accent-d);text-decoration:none}a:hover,p a:not(.btn),li a:not(.btn),.muted a:not(.btn){text-decoration:underline}
.wrap{display:flex;min-height:100vh}
nav{width:248px;background:var(--dark);color:#fff;padding:0 0 1.5rem;flex-shrink:0;position:sticky;top:0;height:100vh;overflow-y:auto;scrollbar-width:thin;scrollbar-color:#2c3a55 transparent}
nav h2{display:flex;align-items:center;gap:.4rem;padding:1.1rem 1.1rem 1rem;font-size:1.05rem;font-weight:800;letter-spacing:.2px;background:var(--dark3);border-bottom:1px solid #24304a}
nav h2 .nav-badge{background:var(--accent-d);color:#fff;border-radius:4px;padding:1px 6px;font-size:.68rem;letter-spacing:.6px;text-transform:uppercase}.skip-link{position:absolute;left:-9999px;top:.5rem;z-index:100;padding:.5rem .9rem;border-radius:6px;background:#fff;color:var(--accent-dd);font-weight:600}.skip-link:focus{left:.5rem}
nav .nav-user{display:flex;align-items:center;gap:.6rem;margin:.9rem .8rem .5rem;padding:.6rem .7rem;background:var(--dark2);border:1px solid #2c3a55;border-radius:8px;font-size:.8rem;color:#c3cbd9;line-height:1.3}
.nav-avatar{flex:none;width:2rem;height:2rem;border-radius:50%;background:var(--accent-d);color:#fff;display:grid;place-items:center;font-weight:700;text-transform:uppercase}
.nav-who{flex:1;min-width:0}.nav-who b{display:block;color:#fff;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
nav .nav-user a{color:#c3cbd9;padding:0;margin:0;display:inline;background:none}nav .nav-user a:hover{color:#fff;background:none}
nav .nav-out{padding:0 .8rem .4rem}nav .nav-out .btn{width:100%;background:transparent;color:#c3cbd9;border-color:#2c3a55}nav .nav-out .btn:hover{background:var(--dark2);color:#fff}
nav .nav-group{display:block;padding:1rem 1.2rem .3rem;font-size:.7rem;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:#8b97ad}
nav a{display:block;margin:1px .6rem;padding:.42rem .7rem;border-radius:6px;color:#c3cbd9;font-size:.9rem}nav a:hover{background:var(--dark2);color:#fff;text-decoration:none}
nav a.active{background:var(--dark2);color:#fff;font-weight:600;box-shadow:inset 3px 0 0 var(--accent)}
nav .nav-langs{display:flex;flex-wrap:wrap;gap:4px;padding:1.2rem 1.2rem 0;font-size:.72rem;color:#8b97ad;align-items:center}
nav .nav-langs a{margin:0;padding:1px 7px;border:1px solid #2c3a55;border-radius:4px;font-size:.72rem;color:#c3cbd9}nav .nav-langs a[aria-current]{background:#fff;color:var(--dark);font-weight:700}
main{flex:1;min-width:0;padding:1.6rem 2rem 3rem;max-width:1440px}
h1{margin-bottom:1rem;font-size:1.45rem;font-weight:700;letter-spacing:-.2px}h2{font-size:1.1rem}h3{font-size:1rem}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:1.1rem 1.25rem;margin-bottom:1rem;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.card>h2,.card>h3{margin-bottom:.75rem}
.grid{display:grid;gap:1rem}.grid>*{min-width:0}.grid-2{grid-template-columns:1fr 1fr}.grid-4{grid-template-columns:repeat(auto-fit,minmax(200px,1fr))}
.stat{position:relative}.card.stat{border-top:3px solid var(--accent)}.stat .num{font-size:1.75rem;font-weight:700;color:var(--text);font-variant-numeric:tabular-nums;line-height:1.2}.stat .label{font-size:.72rem;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--muted)}
table{width:100%;border-collapse:collapse}th,td{padding:.55rem .75rem;text-align:left;border-bottom:1px solid #eef0f3}
th{background:#f9fafb;font-weight:700;font-size:.72rem;letter-spacing:.5px;text-transform:uppercase;color:var(--muted)}
tr:hover{background:#fafbfc}td a:not(.btn){color:var(--accent-d);font-weight:500;text-decoration:underline;text-underline-offset:2px}td a:not(.btn):hover{color:var(--accent-dd)}th.num,td.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.mono{font-family:var(--mono);font-size:.85em}
.badge{display:inline-block;max-width:100%;padding:2px 9px;border-radius:999px;font-size:.72rem;font-weight:600;overflow-wrap:anywhere}td>.badge{white-space:nowrap}
.badge-pending{background:#fef3c7;color:#92400e}.badge-paid{background:#d1fae5;color:#065f46}.badge-shipped{background:#dbeafe;color:#1e40af}.badge-delivered{background:#d1fae5;color:#065f46}
.badge-cancelled,.badge-failed{background:#fee2e2;color:#991b1b}.badge-returned{background:#fce7f3;color:#9d174d}.badge-refunded{background:#e2e8f0;color:#475569}.badge-processing{background:#e0e7ff;color:#3730a3}
.badge-refund_pending{background:#fef3c7;color:#92400e}.badge-authorized{background:#dbeafe;color:#1e40af}
.badge-active{background:#d1fae5;color:#065f46}.badge-draft{background:#e2e8f0;color:#475569}
.btn{display:inline-block;padding:.42rem .85rem;border-radius:6px;font:inherit;font-size:.85rem;cursor:pointer;border:1px solid #d6dae0;background:#fff;color:var(--text);white-space:nowrap}
.btn:hover{border-color:#b8bec8;background:#f9fafb;text-decoration:none}.btn-primary,.bbf-submit{background:var(--accent-d);color:#fff;border-color:var(--accent-d);font-weight:600}
.btn-primary:hover,.bbf-submit:hover{background:var(--accent-dd);border-color:var(--accent-dd)}.btn-danger{background:#dc2626;color:#fff;border-color:#dc2626}.btn-danger:hover{background:#b91c1c}
.btn-sm{padding:.18rem .55rem;font-size:.75rem}
.flash{padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;overflow-wrap:anywhere;border:1px solid transparent}code{overflow-wrap:anywhere;font-family:var(--mono);font-size:.85em}
.flash-ok{background:#ecfdf3;color:#065f46;border-color:#a7f3d0}.flash-err{background:#fef2f2;color:#991b1b;border-color:#fecaca}.flash-warn{background:#fffbeb;color:#92400e;border-color:#fde68a}.flash-info{background:#eff6ff;color:#1e40af;border-color:#bfdbfe}
input,select,textarea{padding:.45rem .65rem;border:1px solid #d6dae0;border-radius:6px;font:inherit;font-size:.9rem;max-width:100%;background:#fff;color:var(--text)}
input:focus,select:focus,textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(234,88,12,.15)}:focus-visible{outline:2px solid var(--accent-d);outline-offset:2px}nav :focus-visible{outline-color:#fff}#main:focus{outline:0}
input[type=file]{padding:.3rem}input[type=checkbox],input[type=radio]{width:1.5rem;height:1.5rem;margin:0 .25rem 0 0;vertical-align:middle;accent-color:var(--accent-d)}textarea{resize:vertical}
.form-row{margin-bottom:.75rem}.form-row label{display:block;font-weight:600;font-size:.85rem;margin-bottom:2px}
.bbf-field{margin-bottom:.75rem}.bbf-label{display:block;font-weight:600;font-size:.85rem;margin-bottom:2px}.bbf-required{color:#b91c1c}
.bbf-field-text .bbf-input,.bbf-field-textarea .bbf-input{width:100%}.bbf-description,.bbf-field-desc{display:block;color:var(--muted);font-size:.85rem}
.bbf-field-error{color:#b91c1c;font-size:.8rem}.bbf-field-error:empty,.bbf-file-list:empty,.bbf-file-status:empty{display:none}
.bbf-submit{display:inline-block;padding:.42rem .85rem;border-radius:6px;font-size:.85rem;cursor:pointer;border:1px solid var(--accent-d)}
.pagination{display:flex;gap:.5rem;margin-top:1rem}
.stock-low{color:#b45309;font-weight:600}.stock-critical{color:#dc2626;font-weight:700}
.muted{color:var(--muted);font-size:.85rem}.small{font-size:.78rem}.strong{font-weight:600}
.sales-toolbar{display:flex;gap:.5rem;align-items:end;flex-wrap:wrap}.sales-toolbar .form-row{margin:0}.sales-toolbar input{min-width:220px}
.report-bar{margin-left:.5rem;width:8rem;vertical-align:middle}details>summary{cursor:pointer;font-weight:600;margin:.5rem 0}
.store-switcher{display:flex;flex-wrap:wrap;gap:.35rem;align-items:center;padding:.2rem .8rem .5rem;font-size:.75rem;color:#c3cbd9}.store-switcher select{flex:1;min-width:0;padding:.3rem .45rem;font-size:.8rem}
.table-scroll{overflow-x:auto;max-width:100%}.status-ok{color:#047857}.status-warn{color:#b45309}.status-error{color:#b91c1c;font-weight:600}
.dash-head{display:flex;justify-content:space-between;align-items:flex-end;gap:.75rem 1.5rem;flex-wrap:wrap;margin-bottom:1.1rem}.dash-head h1{margin:0}.dash-actions{display:flex;gap:.5rem;flex-wrap:wrap}
.kpis{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));margin-bottom:1rem}
.kpi{display:grid;grid-template-columns:auto 1fr;grid-template-areas:"icon label" "icon value";gap:.1rem .9rem;align-items:center;background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:1rem 1.1rem;color:var(--text);box-shadow:0 1px 2px rgba(16,24,40,.04);transition:border-color .15s,box-shadow .15s}
a.kpi:hover{text-decoration:none;border-color:#f3b38a;box-shadow:0 4px 14px rgba(16,24,40,.08)}
.kpi-icon{grid-area:icon;width:2.9rem;height:2.9rem;border-radius:10px;display:grid;place-items:center;background:var(--accent-bg);color:var(--accent-d)}
.kpi-label{grid-area:label;font-size:.72rem;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--muted);align-self:end}
.kpi-value{grid-area:value;font-size:1.6rem;font-weight:700;line-height:1.15;font-variant-numeric:tabular-nums;align-self:start}
.kpi-revenue .kpi-icon{background:#ecfdf3;color:#166534}.kpi-warn .kpi-icon{background:#fffbeb;color:#92400e}.kpi-bad .kpi-icon{background:#fef2f2;color:#b42318}.kpi-calm .kpi-icon{background:#f1f3f6;color:#475569}
.kpi-warn{box-shadow:inset 0 -3px 0 #f59e0b}.kpi-bad{box-shadow:inset 0 -3px 0 #ef4444}
.dash-grid{display:grid;gap:1rem;grid-template-columns:minmax(0,1fr);align-items:start}.dash-grid>*{min-width:0}
.panel-head{display:flex;justify-content:space-between;align-items:baseline;gap:.4rem 1rem;flex-wrap:wrap;margin-bottom:.75rem}.panel-head h2{font-size:1.02rem}.panel-head a{font-size:.85rem;font-weight:600}
.card h3{margin:1rem 0 .4rem;font-size:.9rem}.report-chart svg rect{fill:var(--accent)}
.dot{display:inline-block;width:.55rem;height:.55rem;border-radius:50%;background:#cbd5e1;margin-right:.45rem;vertical-align:middle;flex:none}.dot-ok{background:#16a34a}.dot-bad{background:#ef4444}.stock-low .dot{background:#f59e0b}.stock-critical .dot{background:#ef4444}
.health{list-style:none;margin-bottom:.6rem}.health li{display:flex;align-items:center;gap:.5rem;padding:.5rem 0;border-bottom:1px solid #eef0f3}.health li span:not(.dot){flex:1;min-width:0}.health li strong{font-variant-numeric:tabular-nums;text-align:right}
.setup-checklist{list-style:none;display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,400px),1fr));column-gap:2rem}.setup-checklist li{padding:.6rem 0;border-top:1px solid #eef0f3;display:flex;flex-wrap:wrap;gap:.3rem .5rem;align-items:baseline}.setup-checklist li strong{flex:1;min-width:10rem;font-weight:600}.setup-checklist details{flex-basis:100%;font-size:.8rem}.setup-checklist details summary{margin:0;font-weight:500}.check-text{flex:1;min-width:10rem}.setup-checklist .check-text.muted{flex-basis:100%}
.dash-monitor>summary{margin:0}.dash-monitor .table-scroll{margin-top:.75rem}
@media(min-width:1180px){.dash-grid{grid-template-columns:minmax(0,1fr) 380px}}
@media(max-width:760px){.wrap{display:block}nav{width:100%;height:auto;position:static;display:flex;overflow-x:auto;padding:.5rem}nav h2,nav div,nav .nav-user,nav .nav-langs,nav .nav-group{display:none}nav a{white-space:nowrap;margin:0 1px}nav .nav-out{padding:0 .5rem 0 0;flex:0 0 auto}nav .store-switcher{order:-1;flex:0 0 auto;flex-wrap:nowrap;padding:0 .5rem 0 0}nav .store-switcher select{flex:0 0 auto;min-width:9rem}main{padding:1rem;min-width:0}.grid-2{grid-template-columns:minmax(0,1fr)}.sales-toolbar{align-items:stretch}.sales-toolbar>*{max-width:100%}.sales-toolbar input{min-width:0;width:100%}.sales-toolbar .form-row{width:100%}.kpis{grid-template-columns:repeat(2,minmax(0,1fr));gap:.6rem}.kpi{grid-template-columns:minmax(0,1fr);grid-template-areas:"label" "value";padding:.75rem .85rem}.kpi-icon{display:none}.kpi-value{font-size:1.25rem}.report-chart text{font-size:26px}.report-chart .chart-axis,.report-chart .chart-day:not(.chart-day-key){display:none}.report-chart .chart-day-last{text-anchor:end}}
</style>
</head>
<body><a class="skip-link" href="#main"><?= esc(I18n::t('skip_to_content')) ?></a>
<div class="wrap">
<nav>
    <h2><?= esc($adminAppName) ?> <span class="nav-badge"><?= esc(I18n::t('admin.nav.badge')) ?></span></h2>
    <div class="nav-user"><span class="nav-avatar" aria-hidden="true"><?= esc(mb_substr(Security::adminUser(), 0, 1)) ?></span><span class="nav-who"><b><?= esc(Security::adminUser()) ?></b><?= esc(Security::adminRole()) ?> · <a href="admin.php?p=my_account"><?= esc(I18n::t('admin.title.my_account')) ?></a></span></div>
    <form method="post" action="admin.php" class="nav-out">
        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
        <button class="btn btn-sm" type="submit" name="admin_logout" value="1"><?= esc(I18n::t('admin.sign_out')) ?></button>
    </form>
    <?php renderStoreSwitcher($adminStores, $storesCfg, $page); ?>
    <?php $navGroup = null; foreach (require __DIR__ . '/Admin/Navigation.php' as [$navPage, $navPerm, $navKey, $navAlso, $navGroupKey]): if (!Security::adminCan($navPerm)) continue; ?>
    <?php if ($navGroupKey !== $navGroup): $navGroup = $navGroupKey; ?><span class="nav-group"><?= esc(I18n::t($navGroupKey)) ?></span><?php endif; ?>
    <a href="admin.php?p=<?= esc($navPage) ?>" class="<?= $page === $navPage || in_array($page, $navAlso, true) ? 'active' : '' ?>"<?= $page === $navPage || in_array($page, $navAlso, true) ? ' aria-current="page"' : '' ?>><?= esc(I18n::t($navKey)) ?></a>
    <?php endforeach; ?>
    <div class="nav-langs"><?= esc(I18n::t('admin.language')) ?>:
        <?php foreach ($adminSupportedLocales as $lc): ?>
            <?php $qs = http_build_query(array_merge($_GET, ['lang' => $lc])); ?>
            <a href="admin.php?<?= esc($qs) ?>" lang="<?= esc($lc) ?>"<?= $lc === $adminLocale ? ' aria-current="true"' : '' ?>><?= strtoupper(esc($lc)) ?></a>
        <?php endforeach; ?>
    </div>
</nav>
<main id="main" tabindex="-1">
<?php flash(); ?>
<?php
match($page) {
    'dashboard' => pageDashboard(),
    'sales'     => pageSalesOverview(),
    'reports'   => pageReports(),
    'products'  => pageProducts(),
    'product'   => pageProduct(),
    'orders'    => pageOrders(),
    'order'     => pageOrder(),
    'order_new' => pageOrderNew(),
    'awaiting_payment' => pageAwaitingPayment(),
    'abandoned_carts' => pageAbandonedCarts(),
    'import'    => pageImport(),
    'cache'     => pageCache(),
    'audit'     => pageAudit(),
    'gdpr'      => pageGdpr(),
    'pages'      => pageCmsPages(),
    'page_edit'  => pageCmsEdit(),
    'content'    => pageContentMarketing(),
    'attributes' => pageAttributes(),
    'attribute'  => pageAttribute(),
    'categories' => pageCategories(),
    'brands'     => pageBrands(),
    'promotions' => pagePromotions(), 'price_lists' => pagePriceLists(),
    'companies'  => pageCompanies(), 'customer_groups' => pageCustomerGroups(),
    'suppliers'  => pageSuppliers(), 'purchase_orders' => pagePurchaseOrders(),
    'warehouses' => pageWarehouses(),
    'warehouse'  => pageWarehouse(),
    'feeds'      => pageFeeds(),
    'feed'       => pageFeed(),
    'shipping'   => pageShipping(),
    'payments'   => pagePayments(),
    'digital_assets' => pageDigitalAssets(),
    'reviews'    => pageReviews(),
    'rma'        => pageRma(),
    'complaints' => pageComplaints(),
    'customers'  => pageCustomers(),
    'customer'   => pageCustomerDetail(),
    'admin_users' => pageAdminUsers(),
    'my_account' => pageMyAccount(),
    'email_templates' => pageEmailTemplates(),
    'mail' => pageMailDelivery(),
    'stores' => pageStoreSettings(),
    default      => pageDashboard(),
};
?>
</main>
</div>
<?= adminLayoutScript() ?>
</body>
</html>
<?php
