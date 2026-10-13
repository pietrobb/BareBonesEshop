<?php
/** BareBones Shop admin panel: session/MFA auth (Security::adminAuth), permission map, POST dispatch and page layout. */
require_once dirname(__DIR__, 2) . '/src/autoload.php';

require_once __DIR__ . '/Admin/Shared.php';
require_once __DIR__ . '/Admin/Catalog.php';
require_once __DIR__ . '/Admin/Orders.php';
require_once __DIR__ . '/Admin/Customers.php';
require_once __DIR__ . '/Admin/B2b.php';
require_once __DIR__ . '/Admin/Suppliers.php';
require_once __DIR__ . '/Admin/Operations.php';
require_once __DIR__ . '/Admin/Reports.php';
require_once __DIR__ . '/Admin/Recovery.php';
require_once __DIR__ . '/Admin/AdminUsers.php';
require_once __DIR__ . '/Admin/Request.php';
// Auth
Security::adminAuth();

// Security headers — admin inherits the nonce-based CSP from Security::headers()
// (script-src 'self' 'nonce-…'); all admin inline <script> carry the request
// nonce and inline on*= handlers were delegated, so no 'unsafe-inline' is needed.
Security::headers();

// Admin locale: URL ?lang=xx (persisted in session) → session → system default.
Bootstrap::initStoreContext();
$storesCfg = Bootstrap::stores();
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
$adminConfig = require dirname(__DIR__, 2) . '/config.php';
$adminAppName = trim((string)($adminConfig['admin_name'] ?? '')) ?: I18n::t('admin.app_name');

// p[]=… / action[]=… arrive as arrays; treat them like unknown pages instead of an array-key TypeError.
$page = Security::str($_GET['p'] ?? 'dashboard', 'dashboard');
$action = Security::str($_GET['action'] ?? '');

// CSV export — POST only, so the CSRF token travels in the request body
// instead of the URL (URLs leak via Referer header, browser history and
// access/proxy logs). Security::csrfValidate() accepts $_POST or X-CSRF-Token.
if ($page === 'products' && $action === 'export' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Security::csrfValidate()) {
        http_response_code(403);
        exit('Forbidden');
    }
    handleExport();
}

// Role-based permission map
$pagePermissions = require __DIR__ . '/Admin/Permissions.php';
$requiredPerm = $pagePermissions[$page] ?? 'reports.view';
Security::adminRequire($requiredPerm);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handlePost($page, $action);
}

// Binary downloads leave before the HTML layout starts, otherwise the PDF is wrapped in admin markup.
if ($page === 'invoice_pdf') {
    pageInvoicePdf();
    exit;
}

// Render page
$title = match($page) {
    'dashboard'  => I18n::t('admin.title.dashboard'),
    'sales'      => I18n::t('admin.title.sales'),
    'reports'    => I18n::t('admin.title.reports'),
    'products'   => I18n::t('admin.title.products'),
    'product'    => isset($_GET['id']) ? I18n::t('admin.title.product_edit') : I18n::t('admin.title.product_new'),
    'orders'     => I18n::t('admin.title.orders'),
    'order'      => I18n::t('admin.title.order_detail'),
    'awaiting_payment' => 'Čaká na platbu',
    'abandoned_carts' => 'Opustené košíky',
    'import'     => I18n::t('admin.title.import'),
    'cache'      => I18n::t('admin.title.cache'),
    'audit'      => I18n::t('admin.title.audit_log'),
    'gdpr'       => I18n::t('admin.title.gdpr'),
    'pages'      => I18n::t('admin.title.cms_pages'),
    'page_edit'  => I18n::t('admin.title.cms_page_edit'),
    'content'    => 'Content & Marketing',
    'attributes' => I18n::t('admin.title.attributes'),
    'attribute'  => isset($_GET['id']) ? I18n::t('admin.title.attribute_edit') : I18n::t('admin.title.attribute_new'),
    'categories' => 'Kategórie',
    'brands'     => 'Značky',
    'promotions' => 'Promo akcie a kupóny',
    'price_lists' => 'Cenníky',
    'companies'  => 'B2B firmy',
    'customer_groups' => 'Zákaznícke skupiny',
    'suppliers'  => 'Dodávatelia',
    'purchase_orders' => 'Nákupné objednávky',
    'warehouses' => I18n::t('admin.title.warehouses'),
    'warehouse'  => isset($_GET['id']) ? I18n::t('admin.title.warehouse_edit') : I18n::t('admin.title.warehouse_new'),
    'feeds'      => I18n::t('admin.title.feeds'),
    'feed'       => isset($_GET['id']) ? I18n::t('admin.title.feed_edit') : I18n::t('admin.title.feed_new'),
    'shipping'   => 'Doprava',
    'payments'   => 'Platby',
    'digital_assets' => 'Digitálne súbory',
    'customers'  => 'Zákazníci',
    'customer'   => 'Zákazník',
    'admin_users' => I18n::t('admin.title.admin_users'),
    default      => I18n::t('admin.title.default'),
};

?><!DOCTYPE html>
<html lang="<?= esc($adminLocale) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($title) ?> — <?= esc($adminAppName) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,sans-serif;background:#f5f5f5;color:#333;line-height:1.5}
a{color:#2563eb;text-decoration:none}a:hover{text-decoration:underline}
.wrap{display:flex;min-height:100vh}
nav{width:220px;background:#1e293b;color:#fff;padding:1rem 0;flex-shrink:0}
nav h2{padding:0 1rem 1rem;font-size:1.1rem;border-bottom:1px solid #334155}
nav a{display:block;padding:.5rem 1rem;color:#cbd5e1}nav a:hover,nav a.active{background:#334155;color:#fff;text-decoration:none}
main{flex:1;padding:1.5rem;max-width:1200px}
h1{margin-bottom:1rem;font-size:1.5rem}
.card{background:#fff;border-radius:8px;padding:1.25rem;margin-bottom:1rem;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.grid{display:grid;gap:1rem}.grid-4{grid-template-columns:repeat(auto-fit,minmax(200px,1fr))}
.stat{text-align:center}.stat .num{font-size:2rem;font-weight:700;color:#2563eb}.stat .label{font-size:.85rem;color:#64748b}
table{width:100%;border-collapse:collapse}th,td{padding:.5rem .75rem;text-align:left;border-bottom:1px solid #e2e8f0}
th{background:#f8fafc;font-weight:600;font-size:.85rem;color:#475569}
tr:hover{background:#f8fafc}
.badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:.75rem;font-weight:600}
.badge-pending{background:#fef3c7;color:#92400e}.badge-paid{background:#d1fae5;color:#065f46}
.badge-shipped{background:#dbeafe;color:#1e40af}.badge-delivered{background:#d1fae5;color:#065f46}
.badge-cancelled{background:#fee2e2;color:#991b1b}.badge-returned{background:#fce7f3;color:#9d174d}
.badge-refunded{background:#e2e8f0;color:#475569}.badge-processing{background:#e0e7ff;color:#3730a3}
.badge-refund_pending{background:#fef3c7;color:#92400e}.badge-authorized{background:#dbeafe;color:#1e40af}
.badge-active{background:#d1fae5;color:#065f46}.badge-draft{background:#e2e8f0;color:#475569}
.btn{display:inline-block;padding:.4rem .8rem;border-radius:4px;font-size:.85rem;cursor:pointer;border:1px solid #d1d5db;background:#fff}
.btn:hover{background:#f8fafc;text-decoration:none}.btn-primary{background:#2563eb;color:#fff;border-color:#2563eb}
.btn-primary:hover,.bbf-submit:hover{background:#1d4ed8}.btn-danger{background:#dc2626;color:#fff;border-color:#dc2626}
.btn-sm{padding:.2rem .5rem;font-size:.75rem}
.flash{padding:.75rem 1rem;border-radius:6px;margin-bottom:1rem}
.flash-ok{background:#d1fae5;color:#065f46}.flash-err{background:#fee2e2;color:#991b1b}
input,select,textarea{padding:.4rem .6rem;border:1px solid #d1d5db;border-radius:4px;font-size:.9rem}
input[type=file]{padding:.3rem}textarea{resize:vertical}
.form-row{margin-bottom:.75rem}.form-row label{display:block;font-weight:600;font-size:.85rem;margin-bottom:2px}
.bbf-field{margin-bottom:.75rem}.bbf-label{display:block;font-weight:600;font-size:.85rem;margin-bottom:2px}.bbf-required{color:#b91c1c}
.bbf-field-text .bbf-input,.bbf-field-textarea .bbf-input{width:100%}.bbf-description,.bbf-field-desc{display:block;color:#64748b;font-size:.85rem}
.bbf-field-error{color:#b91c1c;font-size:.8rem}.bbf-field-error:empty,.bbf-file-list:empty,.bbf-file-status:empty{display:none}
.bbf-submit{display:inline-block;padding:.4rem .8rem;border-radius:4px;font-size:.85rem;cursor:pointer;background:#2563eb;color:#fff;border:1px solid #2563eb}
.pagination{display:flex;gap:.5rem;margin-top:1rem}
.stock-low{color:#b45309;font-weight:600}.stock-critical{color:#dc2626;font-weight:700}
.muted{color:#64748b;font-size:.85rem}
.sales-toolbar{display:flex;gap:.5rem;align-items:end;flex-wrap:wrap}.sales-toolbar .form-row{margin:0}.sales-toolbar input{min-width:220px}
.report-bar{margin-left:.5rem;width:8rem;vertical-align:middle}details>summary{cursor:pointer;font-weight:600;margin:.5rem 0}
.table-scroll{overflow-x:auto}.status-ok{color:#047857}.status-warn{color:#b45309}.status-error{color:#b91c1c;font-weight:600}
@media(max-width:760px){.wrap{display:block}nav{width:100%;display:flex;overflow-x:auto;padding:.5rem}nav h2,nav div{display:none}nav a{white-space:nowrap}main{padding:1rem}.sales-toolbar input{min-width:0;width:100%}.sales-toolbar .form-row{width:100%}}
</style>
</head>
<body>
<div class="wrap">
<nav>
    <h2><?= esc($adminAppName) ?></h2>
    <div style="padding:0.3rem 1rem;font-size:0.75rem;color:#94a3b8;margin-bottom:0.5rem"><?= esc(Security::adminUser()) ?> (<?= esc(Security::adminRole()) ?>)</div>
    <form method="post" action="admin.php" style="padding:0 1rem .5rem">
        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
        <button class="btn btn-sm" type="submit" name="admin_logout" value="1">Sign out</button>
    </form>
    <div style="padding:0.3rem 1rem;font-size:0.75rem;color:#94a3b8;margin-bottom:0.5rem">
        <?= esc(I18n::t('admin.language')) ?>:
        <?php foreach ($adminSupportedLocales as $lc): ?>
            <?php $qs = http_build_query(array_merge($_GET, ['lang' => $lc])); ?>
            <a href="admin.php?<?= esc($qs) ?>" style="<?= $lc===$adminLocale?'color:#fff;font-weight:600':'color:#94a3b8' ?>"><?= strtoupper(esc($lc)) ?></a>
        <?php endforeach; ?>
    </div>
    <?php if (Security::adminCan('reports.view')): ?><a href="admin.php?p=dashboard" class="<?= $page==='dashboard'?'active':'' ?>"><?= esc(I18n::t('admin.nav.dashboard')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('orders.view')): ?><a href="admin.php?p=sales" class="<?= $page==='sales'?'active':'' ?>"><?= esc(I18n::t('admin.nav.sales')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('reports.view')): ?><a href="admin.php?p=reports" class="<?= $page==='reports'?'active':'' ?>"><?= esc(I18n::t('admin.nav.reports')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('products.view')): ?><a href="admin.php?p=products" class="<?= $page==='products'?'active':'' ?>"><?= esc(I18n::t('admin.nav.products')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('orders.view')): ?><a href="admin.php?p=orders" class="<?= $page==='orders'?'active':'' ?>"><?= esc(I18n::t('admin.nav.orders')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('orders.view')): ?><a href="admin.php?p=awaiting_payment" class="<?= $page==='awaiting_payment'?'active':'' ?>">Čaká na platbu</a><?php endif; ?>
    <?php if (Security::adminCan('orders.view')): ?><a href="admin.php?p=abandoned_carts" class="<?= $page==='abandoned_carts'?'active':'' ?>">Opustené košíky</a><?php endif; ?>
    <?php if (Security::adminCan('import')): ?><a href="admin.php?p=import" class="<?= $page==='import'?'active':'' ?>"><?= esc(I18n::t('admin.nav.import_csv')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('cache')): ?><a href="admin.php?p=cache" class="<?= $page==='cache'?'active':'' ?>"><?= esc(I18n::t('admin.nav.cache')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('products.edit')): ?><a href="admin.php?p=attributes" class="<?= $page==='attributes'||$page==='attribute'?'active':'' ?>"><?= esc(I18n::t('admin.nav.attributes')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('categories.edit')): ?><a href="admin.php?p=categories" class="<?= $page==='categories'?'active':'' ?>">Kategórie</a><a href="admin.php?p=brands" class="<?= $page==='brands'?'active':'' ?>">Značky</a><?php endif; ?>
    <?php if (Security::adminCan('pricing.manage')): ?><a href="admin.php?p=promotions" class="<?= $page==='promotions'?'active':'' ?>">Promo akcie</a><a href="admin.php?p=price_lists" class="<?= $page==='price_lists'?'active':'' ?>">Cenníky</a><?php endif; ?>
    <?php if (Security::adminCan('customers.edit')): ?><a href="admin.php?p=companies" class="<?= $page==='companies'?'active':'' ?>">B2B firmy</a><a href="admin.php?p=customer_groups" class="<?= $page==='customer_groups'?'active':'' ?>">Zákaznícke skupiny</a><?php endif; ?>
    <?php if (Security::adminCan('import')): ?><a href="admin.php?p=suppliers" class="<?= $page==='suppliers'?'active':'' ?>">Dodávatelia</a><a href="admin.php?p=purchase_orders" class="<?= $page==='purchase_orders'?'active':'' ?>">Nákupné objednávky</a><?php endif; ?>
    <?php if (Security::adminCan('import')): ?><a href="admin.php?p=warehouses" class="<?= $page==='warehouses'||$page==='warehouse'?'active':'' ?>"><?= esc(I18n::t('admin.nav.warehouses')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('import')): ?><a href="admin.php?p=feeds" class="<?= $page==='feeds'||$page==='feed'?'active':'' ?>"><?= esc(I18n::t('admin.nav.feeds')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('products.edit')): ?><a href="admin.php?p=pages" class="<?= $page==='pages'?'active':'' ?>"><?= esc(I18n::t('admin.nav.cms_pages')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('products.edit')): ?><a href="admin.php?p=content" class="<?= $page==='content'?'active':'' ?>">Content & Marketing</a><?php endif; ?>
    <?php if (Security::adminCan('shipping.manage')): ?><a href="admin.php?p=shipping" class="<?= $page==='shipping'?'active':'' ?>">Doprava</a><?php endif; ?>
    <?php if (Security::adminCan('payments.manage')): ?><a href="admin.php?p=payments" class="<?= $page==='payments'?'active':'' ?>">Platby</a><?php endif; ?>
    <?php if (Security::adminCan('products.edit')): ?><a href="admin.php?p=digital_assets" class="<?= $page==='digital_assets'?'active':'' ?>">Digitálne súbory</a><?php endif; ?>
    <?php if (Security::adminCan('reports.view')): ?><a href="admin.php?p=audit" class="<?= $page==='audit'?'active':'' ?>"><?= esc(I18n::t('admin.nav.audit')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('gdpr.view')): ?><a href="admin.php?p=gdpr" class="<?= $page==='gdpr'?'active':'' ?>"><?= esc(I18n::t('admin.nav.gdpr')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('products.edit')): ?><a href="admin.php?p=reviews" class="<?= $page==='reviews'?'active':'' ?>">Recenzie</a><?php endif; ?>
    <?php if (Security::adminCan('rma.manage')): ?><a href="admin.php?p=rma" class="<?= $page==='rma'?'active':'' ?>">Reklamácie</a><?php endif; ?>
    <?php if (Security::adminCan('customers.view')): ?><a href="admin.php?p=customers" class="<?= $page==='customers'||$page==='customer'?'active':'' ?>">Zákazníci</a><?php endif; ?>
    <?php if (Security::adminCan('admins.manage')): ?><a href="admin.php?p=admin_users" class="<?= $page==='admin_users'?'active':'' ?>"><?= esc(I18n::t('admin.title.admin_users')) ?></a><?php endif; ?>
</nav>
<main>
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
    'customers'  => pageCustomers(),
    'customer'   => pageCustomerDetail(),
    'admin_users' => pageAdminUsers(),
    default      => pageDashboard(),
};
?>
</main>
</div>
<script nonce="<?= Security::cspNonce() ?>">
// CSP-safe delegated handlers — replaces inline on*= attributes so the admin
// CSP can drop 'unsafe-inline' from script-src (nonce-only). See data-confirm
// and data-action hooks in the templates above.
(function(){
    document.addEventListener('submit', function(e){
        var f = e.target;
        var submitter = e.submitter;
        var skipConfirm = submitter && submitter.hasAttribute && submitter.hasAttribute('data-skip-confirm');
        var message = submitter && submitter.getAttribute ? submitter.getAttribute('data-confirm') : null;
        if (!message && !skipConfirm && f && f.matches && f.matches('form[data-confirm]')) message = f.getAttribute('data-confirm');
        if (message && !confirm(message)) e.preventDefault();
    });
    document.addEventListener('click', function(e){
        var t = e.target.closest ? e.target.closest('[data-action]') : null;
        if (!t) return;
        var a = t.getAttribute('data-action');
        if (a === 'remove-parent') { e.preventDefault(); if (t.parentElement) t.parentElement.remove(); }
        else if (a === 'add-option') { e.preventDefault(); if (window.addOption) window.addOption(); }
        else if (a === 'toggle-cats') { e.preventDefault(); if (window.toggleAllCats) window.toggleAllCats(t.getAttribute('data-value') === '1'); }
    });
    document.addEventListener('input', function(e){
        var t = e.target;
        if (t && t.matches && t.matches('[data-action="filter-cats"]') && window.filterCats) window.filterCats();
    });
})();
</script>
</body>
</html>
<?php
