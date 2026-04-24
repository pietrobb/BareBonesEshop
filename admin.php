<?php
/**
 * BareBones Shop — Admin Panel
 * Minimal admin: dashboard, products CRUD, orders, import, cache.
 * HTTP Basic Auth with brute-force protection.
 */
require_once __DIR__ . '/src/autoload.php';

// Auth
Security::adminAuth();

// Security headers
Security::headers();
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'");

// Admin locale: URL ?lang=xx (persisted in session) → session → system default.
$storesCfg = json_decode(file_get_contents(__DIR__ . '/stores.json'), true) ?: [];
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

$page = $_GET['p'] ?? 'dashboard';
$action = $_GET['action'] ?? '';

// Handle GET actions (with CSRF token in URL)
if ($page === 'products' && $action === 'export') {
    $exportToken = $_GET['csrf_token'] ?? '';
    if ($exportToken === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $exportToken)) {
        http_response_code(403);
        exit('Forbidden');
    }
    handleExport();
}

// Role-based permission map
$pagePermissions = [
    'dashboard'  => 'reports.view',
    'products'   => 'products.view',
    'product'    => 'products.edit',
    'orders'     => 'orders.view',
    'order'      => 'orders.view',
    'import'     => 'import',
    'cache'      => 'cache',
    'audit'      => 'reports.view',
    'pages'      => 'products.edit',
    'page_edit'  => 'products.edit',
    'attributes' => 'products.edit',
    'attribute'  => 'products.edit',
    'warehouses' => 'import',
    'warehouse'  => 'import',
    'feeds'      => 'import',
    'feed'       => 'import',
];
$requiredPerm = $pagePermissions[$page] ?? 'reports.view';
Security::adminRequire($requiredPerm);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handlePost($page, $action);
}

// Render page
$title = match($page) {
    'dashboard'  => I18n::t('admin.title.dashboard'),
    'products'   => I18n::t('admin.title.products'),
    'product'    => isset($_GET['id']) ? I18n::t('admin.title.product_edit') : I18n::t('admin.title.product_new'),
    'orders'     => I18n::t('admin.title.orders'),
    'order'      => I18n::t('admin.title.order_detail'),
    'import'     => I18n::t('admin.title.import'),
    'cache'      => I18n::t('admin.title.cache'),
    'audit'      => I18n::t('admin.title.audit_log'),
    'pages'      => I18n::t('admin.title.cms_pages'),
    'page_edit'  => I18n::t('admin.title.cms_page_edit'),
    'attributes' => I18n::t('admin.title.attributes'),
    'attribute'  => isset($_GET['id']) ? I18n::t('admin.title.attribute_edit') : I18n::t('admin.title.attribute_new'),
    'warehouses' => I18n::t('admin.title.warehouses'),
    'warehouse'  => isset($_GET['id']) ? I18n::t('admin.title.warehouse_edit') : I18n::t('admin.title.warehouse_new'),
    'feeds'      => I18n::t('admin.title.feeds'),
    'feed'       => isset($_GET['id']) ? I18n::t('admin.title.feed_edit') : I18n::t('admin.title.feed_new'),
    default      => I18n::t('admin.title.default'),
};

?><!DOCTYPE html>
<html lang="<?= esc($adminLocale) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($title) ?> — <?= esc(I18n::t('admin.app_name')) ?></title>
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
.badge-active{background:#d1fae5;color:#065f46}.badge-draft{background:#e2e8f0;color:#475569}
.btn{display:inline-block;padding:.4rem .8rem;border-radius:4px;font-size:.85rem;cursor:pointer;border:1px solid #d1d5db;background:#fff}
.btn:hover{background:#f8fafc;text-decoration:none}.btn-primary{background:#2563eb;color:#fff;border-color:#2563eb}
.btn-primary:hover{background:#1d4ed8}.btn-danger{background:#dc2626;color:#fff;border-color:#dc2626}
.btn-sm{padding:.2rem .5rem;font-size:.75rem}
.flash{padding:.75rem 1rem;border-radius:6px;margin-bottom:1rem}
.flash-ok{background:#d1fae5;color:#065f46}.flash-err{background:#fee2e2;color:#991b1b}
input,select,textarea{padding:.4rem .6rem;border:1px solid #d1d5db;border-radius:4px;font-size:.9rem}
input[type=file]{padding:.3rem}textarea{resize:vertical}
.form-row{margin-bottom:.75rem}.form-row label{display:block;font-weight:600;font-size:.85rem;margin-bottom:2px}
.pagination{display:flex;gap:.5rem;margin-top:1rem}
.stock-low{color:#dc2626;font-weight:600}
.muted{color:#64748b;font-size:.85rem}
</style>
</head>
<body>
<div class="wrap">
<nav>
    <h2><?= esc(I18n::t('admin.app_name')) ?></h2>
    <div style="padding:0.3rem 1rem;font-size:0.75rem;color:#94a3b8;margin-bottom:0.5rem"><?= esc(Security::adminUser()) ?> (<?= esc(Security::adminRole()) ?>)</div>
    <div style="padding:0.3rem 1rem;font-size:0.75rem;color:#94a3b8;margin-bottom:0.5rem">
        <?= esc(I18n::t('admin.language')) ?>:
        <?php foreach ($adminSupportedLocales as $lc): ?>
            <?php $qs = http_build_query(array_merge($_GET, ['lang' => $lc])); ?>
            <a href="admin.php?<?= esc($qs) ?>" style="<?= $lc===$adminLocale?'color:#fff;font-weight:600':'color:#94a3b8' ?>"><?= strtoupper(esc($lc)) ?></a>
        <?php endforeach; ?>
    </div>
    <?php if (Security::adminCan('reports.view')): ?><a href="admin.php?p=dashboard" class="<?= $page==='dashboard'?'active':'' ?>"><?= esc(I18n::t('admin.nav.dashboard')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('products.view')): ?><a href="admin.php?p=products" class="<?= $page==='products'?'active':'' ?>"><?= esc(I18n::t('admin.nav.products')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('orders.view')): ?><a href="admin.php?p=orders" class="<?= $page==='orders'?'active':'' ?>"><?= esc(I18n::t('admin.nav.orders')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('import')): ?><a href="admin.php?p=import" class="<?= $page==='import'?'active':'' ?>"><?= esc(I18n::t('admin.nav.import_csv')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('cache')): ?><a href="admin.php?p=cache" class="<?= $page==='cache'?'active':'' ?>"><?= esc(I18n::t('admin.nav.cache')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('products.edit')): ?><a href="admin.php?p=attributes" class="<?= $page==='attributes'||$page==='attribute'?'active':'' ?>"><?= esc(I18n::t('admin.nav.attributes')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('import')): ?><a href="admin.php?p=warehouses" class="<?= $page==='warehouses'||$page==='warehouse'?'active':'' ?>"><?= esc(I18n::t('admin.nav.warehouses')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('import')): ?><a href="admin.php?p=feeds" class="<?= $page==='feeds'||$page==='feed'?'active':'' ?>"><?= esc(I18n::t('admin.nav.feeds')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('products.edit')): ?><a href="admin.php?p=pages" class="<?= $page==='pages'?'active':'' ?>"><?= esc(I18n::t('admin.nav.cms_pages')) ?></a><?php endif; ?>
    <?php if (Security::adminCan('reports.view')): ?><a href="admin.php?p=audit" class="<?= $page==='audit'?'active':'' ?>"><?= esc(I18n::t('admin.nav.audit')) ?></a><?php endif; ?>
</nav>
<main>
<?php flash(); ?>
<?php
match($page) {
    'dashboard' => pageDashboard(),
    'products'  => pageProducts(),
    'product'   => pageProduct(),
    'orders'    => pageOrders(),
    'order'     => pageOrder(),
    'import'    => pageImport(),
    'cache'     => pageCache(),
    'audit'     => pageAudit(),
    'pages'      => pageCmsPages(),
    'page_edit'  => pageCmsEdit(),
    'attributes' => pageAttributes(),
    'attribute'  => pageAttribute(),
    'warehouses' => pageWarehouses(),
    'warehouse'  => pageWarehouse(),
    'feeds'      => pageFeeds(),
    'feed'       => pageFeed(),
    default      => pageDashboard(),
};
?>
</main>
</div>
</body>
</html>
<?php

// ============================================================
// Pages
// ============================================================

function pageDashboard(): void {
    $db = Db::get();

    // Today's orders
    $today = date('Y-m-d');
    $stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE created_at >= :today");
    $stmt->execute([':today' => $today]);
    $ordersToday = (int)$stmt->fetchColumn();

    // Today's revenue
    $stmt = $db->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE created_at >= :today AND status NOT IN ('cancelled','refunded')");
    $stmt->execute([':today' => $today]);
    $revenueToday = (int)$stmt->fetchColumn();

    // Pending orders
    $stmt = $db->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'");
    $pendingOrders = (int)$stmt->fetchColumn();

    // Low stock products (< 10)
    $stmt = $db->query("SELECT COUNT(*) FROM products WHERE stock < 10 AND status = 'active'");
    $lowStock = (int)$stmt->fetchColumn();

    // Product count
    $totalProducts = (int)$db->query("SELECT COUNT(*) FROM products")->fetchColumn();

    // Job queue stats
    $jobStats = JobQueue::stats();
    ?>
    <h1><?= esc(I18n::t('admin.title.dashboard')) ?></h1>
    <div class="grid grid-4">
        <div class="card stat"><div class="num"><?= $ordersToday ?></div><div class="label"><?= esc(I18n::t('admin.stat.orders_today')) ?></div></div>
        <div class="card stat"><div class="num"><?= I18n::price($revenueToday) ?></div><div class="label"><?= esc(I18n::t('admin.stat.revenue_today')) ?></div></div>
        <div class="card stat"><div class="num"><?= $pendingOrders ?></div><div class="label"><?= esc(I18n::t('admin.stat.pending')) ?></div></div>
        <div class="card stat"><div class="num"><?= $lowStock ?></div><div class="label"><?= esc(I18n::t('admin.stat.low_stock')) ?></div></div>
    </div>

    <div class="card">
        <h3><?= esc(I18n::t('admin.dashboard.statistics')) ?></h3>
        <p><?= esc(I18n::t('admin.dashboard.total_products')) ?>: <strong><?= $totalProducts ?></strong></p>
        <p><?= esc(I18n::t('admin.dashboard.jobs_queue')) ?>: <strong><?= $jobStats['pending'] ?></strong> <?= esc(I18n::t('admin.dashboard.jobs_pending')) ?>, <strong><?= $jobStats['failed'] ?></strong> <?= esc(I18n::t('admin.dashboard.jobs_failed')) ?></p>
    </div>

    <?php if ($lowStock > 0): ?>
    <div class="card">
        <h3><?= esc(I18n::t('admin.dashboard.low_stock_alert')) ?></h3>
        <table>
            <tr><th><?= esc(I18n::t('admin.th.sku')) ?></th><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.th.stock')) ?></th></tr>
            <?php
            $stmt = $db->query("SELECT sku, name, stock FROM products WHERE stock < 10 AND status = 'active' ORDER BY stock ASC LIMIT 20");
            while ($row = $stmt->fetch()):
            ?>
            <tr>
                <td><?= esc($row['sku']) ?></td>
                <td><?= esc($row['name']) ?></td>
                <td class="stock-low"><?= (int)$row['stock'] ?></td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>
    <?php endif; ?>

    <div class="card">
        <h3><?= esc(I18n::t('admin.dashboard.recent_orders')) ?></h3>
        <table>
            <tr><th><?= esc(I18n::t('admin.th.number')) ?></th><th><?= esc(I18n::t('admin.th.customer')) ?></th><th><?= esc(I18n::t('admin.th.amount')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.th.date')) ?></th></tr>
            <?php
            $stmt = $db->query("SELECT * FROM orders ORDER BY created_at DESC LIMIT 10");
            while ($row = $stmt->fetch()):
            ?>
            <tr>
                <td><a href="admin.php?p=order&id=<?= $row['id'] ?>"><?= esc($row['order_number']) ?></a></td>
                <td><?= esc($row['customer_name']) ?></td>
                <td><?= I18n::price((int)$row['total'], $row['currency']) ?></td>
                <td><span class="badge badge-<?= esc($row['status']) ?>"><?= esc($row['status']) ?></span></td>
                <td class="muted"><?= esc(substr($row['created_at'], 0, 16)) ?></td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>
    <?php
}

function pageProducts(): void {
    $db = Db::get();
    $page = max(1, Security::int($_GET['page'] ?? 1));
    $perPage = 25;
    $offset = ($page - 1) * $perPage;
    $search = trim($_GET['q'] ?? '');
    $statusFilter = $_GET['status'] ?? '';

    $where = '1=1';
    $params = [];
    if ($search) {
        $where .= " AND (name LIKE :q OR sku LIKE :q2)";
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
        $params[':q'] = "%{$escaped}%";
        $params[':q2'] = "%{$escaped}%";
    }
    if ($statusFilter) {
        $where .= " AND status = :status";
        $params[':status'] = $statusFilter;
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE {$where}");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));

    $params[':limit'] = $perPage;
    $params[':offset'] = $offset;
    $stmt = $db->prepare("SELECT * FROM products WHERE {$where} ORDER BY updated_at DESC LIMIT :limit OFFSET :offset");
    $stmt->execute($params);
    $products = $stmt->fetchAll();
    ?>
    <h1><?= esc(I18n::t('admin.title.products')) ?> <span class="muted">(<?= $total ?>)</span></h1>
    <div class="card">
        <form method="get" style="display:flex;gap:.5rem;margin-bottom:1rem">
            <input type="hidden" name="p" value="products">
            <input name="q" value="<?= esc($search) ?>" placeholder="<?= esc(I18n::t('admin.products.search_placeholder')) ?>">
            <select name="status">
                <option value="">— <?= esc(I18n::t('admin.th.status')) ?> —</option>
                <option value="active" <?= $statusFilter==='active'?'selected':'' ?>>active</option>
                <option value="draft" <?= $statusFilter==='draft'?'selected':'' ?>>draft</option>
                <option value="archived" <?= $statusFilter==='archived'?'selected':'' ?>>archived</option>
            </select>
            <button class="btn" type="submit"><?= esc(I18n::t('admin.btn.apply')) ?></button>
        </form>
        <form method="post" action="admin.php?p=products&action=bulk" id="bulk-form">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div style="display:flex;gap:.5rem;margin-bottom:.5rem">
                <select name="bulk_action">
                    <option value="">— <?= esc(I18n::t('admin.products.bulk_action')) ?> —</option>
                    <option value="activate">activate</option>
                    <option value="archive">archive</option>
                    <option value="delete"><?= esc(I18n::t('admin.btn.delete')) ?></option>
                    <option value="price_up_5">Price +5%</option>
                    <option value="price_up_10">Price +10%</option>
                    <option value="price_up_20">Price +20%</option>
                    <option value="price_down_5">Price -5%</option>
                    <option value="price_down_10">Price -10%</option>
                    <option value="price_down_20">Price -20%</option>
                </select>
                <button type="submit" class="btn btn-sm" onclick="return confirm('OK?')"><?= esc(I18n::t('admin.btn.apply')) ?></button>
                <a href="admin.php?p=products&action=export&csrf_token=<?= Security::csrfToken() ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>" class="btn btn-sm"><?= esc(I18n::t('admin.btn.export_csv')) ?></a>
            </div>
        <table>
            <tr><th><input type="checkbox" id="check-all"></th><th><?= esc(I18n::t('admin.th.sku')) ?></th><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.th.price')) ?></th><th><?= esc(I18n::t('admin.th.stock')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.th.actions')) ?></th></tr>
            <?php foreach ($products as $p): ?>
            <tr>
                <td><input type="checkbox" name="product_ids[]" value="<?= $p['id'] ?>" class="product-check"></td>
                <td><?= esc($p['sku']) ?></td>
                <td><a href="admin.php?p=product&id=<?= $p['id'] ?>"><?= esc($p['name']) ?></a></td>
                <td><?= I18n::price((int)$p['price'], $p['currency']) ?></td>
                <td class="<?= $p['stock'] < 10 ? 'stock-low' : '' ?>"><?= (int)$p['stock'] ?></td>
                <td><span class="badge badge-<?= esc($p['status']) ?>"><?= esc($p['status']) ?></span></td>
                <td>
                    <a href="admin.php?p=product&id=<?= $p['id'] ?>" class="btn btn-sm"><?= esc(I18n::t('admin.btn.edit')) ?></a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </form>
        <script>document.getElementById('check-all')?.addEventListener('change',function(){document.querySelectorAll('.product-check').forEach(c=>c.checked=this.checked)});</script>
        <?php pagination($page, $pages, "admin.php?p=products&q=" . urlencode($search) . "&status=" . urlencode($statusFilter)); ?>
    </div>
    <?php
}

function pageProduct(): void {
    $db = Db::get();
    $id = Security::int($_GET['id'] ?? 0);
    $product = $id ? Products::byId($id) : null;
    $categories = $db->query("SELECT id, name, path FROM categories ORDER BY path")->fetchAll();

    // Load attributes for product's category (+ all parent categories)
    $catAttrs = [];
    $existingSpecs = [];
    $catId = (int)($product['category_id'] ?? 0);
    if ($catId) {
        $catAttrs = $db->prepare("
            SELECT ad.id, ad.name, ad.slug, ad.type, ad.unit, ad.sort_order
            FROM attribute_definitions ad
            JOIN category_attributes ca ON ca.attribute_id = ad.id
            WHERE ca.category_id = :cid
            ORDER BY ad.sort_order, ad.name
        ");
        $catAttrs->execute([':cid' => $catId]);
        $catAttrs = $catAttrs->fetchAll(PDO::FETCH_ASSOC);
    }
    // Load existing spec values for this product
    if ($id) {
        $specStmt = $db->prepare("SELECT criteria_name, criteria_value, numeric_val FROM product_specifications WHERE product_id = :pid AND lang = 'sk'");
        $specStmt->execute([':pid' => $id]);
        foreach ($specStmt->fetchAll(PDO::FETCH_ASSOC) as $sp) {
            $existingSpecs[$sp['criteria_name']] = $sp;
        }
    }
    // Load enum options for each attribute
    $attrOptions = [];
    foreach ($catAttrs as $a) {
        if ($a['type'] === 'enum') {
            $optStmt = $db->prepare("SELECT value FROM attribute_options WHERE attribute_id = :aid ORDER BY sort_order, value");
            $optStmt->execute([':aid' => $a['id']]);
            $attrOptions[$a['id']] = $optStmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }
    // Build JSON map: category_id => [{id, name, slug, type, unit, options}]
    $allCatAttrs = $db->query("
        SELECT ca.category_id, ad.id, ad.name, ad.slug, ad.type, ad.unit
        FROM category_attributes ca
        JOIN attribute_definitions ad ON ad.id = ca.attribute_id
        ORDER BY ad.sort_order, ad.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $catAttrMap = [];
    foreach ($allCatAttrs as $row) {
        $entry = ['id' => (int)$row['id'], 'name' => $row['name'], 'slug' => $row['slug'], 'type' => $row['type'], 'unit' => $row['unit']];
        if ($row['type'] === 'enum') {
            $entry['options'] = $attrOptions[$row['id']] ?? [];
            if (empty($entry['options'])) {
                $optStmt = $db->prepare("SELECT value FROM attribute_options WHERE attribute_id = :aid ORDER BY sort_order, value");
                $optStmt->execute([':aid' => $row['id']]);
                $entry['options'] = $optStmt->fetchAll(PDO::FETCH_COLUMN);
                $attrOptions[$row['id']] = $entry['options'];
            }
        }
        $catAttrMap[$row['category_id']][] = $entry;
    }
    ?>
    <h1><?= $product ? 'Upraviť: ' . esc($product['name']) : 'Nový produkt' ?></h1>
    <div class="card">
        <?php
        // Pre-fill data for BBF form
        $bbfProductData = json_encode([
            '_product_id'   => $product['id'] ?? 0,
            'sku'           => $product['sku'] ?? '',
            'name'          => $product['name'] ?? '',
            'slug'          => $product['slug'] ?? '',
            'category_id'   => (string)($product['category_id'] ?? ''),
            'product_type'  => $product['product_type'] ?? 'simple',
            'price'         => (string)(int)($product['price'] ?? 0),
            'compare_price' => (string)(int)($product['compare_price'] ?? 0),
            'stock'         => (string)(int)($product['stock'] ?? 0),
            'status'        => $product['status'] ?? 'active',
            'summary'       => $product['summary'] ?? '',
            'description'   => $product['description'] ?? '',
            'images'        => implode("\n", json_decode($product['images'] ?? '[]', true) ?: []),
            'weight_g'      => (string)(int)($product['weight_g'] ?? 0),
            'tags'          => $product['tags'] ?? '',
        ], JSON_UNESCAPED_UNICODE);
        ?>
        <div id="bbf-product" data-form="admin-product" data-lang="sk"></div>

        <div id="attr-section" style="border-top:2px solid #e2e8f0;margin-top:1rem;padding-top:1rem">
            <h3 style="margin-bottom:.75rem">Atribúty</h3>
            <p class="muted" style="color:#64748b;font-size:.85rem">Atribúty sa načítajú podľa zvolenej kategórie po uložení produktu.</p>
            <div id="attr-fields">
                <?php foreach ($catAttrs as $a):
                    $specKey = $a['name'];
                    $curVal = $existingSpecs[$specKey]['criteria_value'] ?? '';
                    $curNum = $existingSpecs[$specKey]['numeric_val'] ?? '';
                ?>
                <div class="form-row">
                    <label><?= esc($a['name']) ?><?= $a['unit'] ? ' (' . esc($a['unit']) . ')' : '' ?></label>
                    <?php if ($a['type'] === 'enum'): ?>
                    <select name="attr_<?= esc($a['name']) ?>" data-bbf-inject="true">
                        <option value="">— nevybrané —</option>
                        <?php foreach ($attrOptions[$a['id']] ?? [] as $opt): ?>
                        <option value="<?= esc($opt) ?>" <?= $curVal === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php elseif ($a['type'] === 'number'): ?>
                    <input type="number" step="any" name="attr_<?= esc($a['name']) ?>" value="<?= esc($curNum ?: $curVal) ?>" placeholder="<?= esc($a['unit'] ?: 'číslo') ?>" data-bbf-inject="true">
                    <?php elseif ($a['type'] === 'boolean'): ?>
                    <select name="attr_<?= esc($a['name']) ?>" data-bbf-inject="true">
                        <option value="">— nevybrané —</option>
                        <option value="Áno" <?= $curVal === 'Áno' ? 'selected' : '' ?>>Áno</option>
                        <option value="Nie" <?= $curVal === 'Nie' ? 'selected' : '' ?>>Nie</option>
                    </select>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php if (empty($catAttrs)): ?>
                <p class="muted" style="color:#64748b;font-size:.85rem">Pre túto kategóriu nie sú definované atribúty. <a href="admin.php?p=attributes">Spravovať atribúty</a></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($product): ?>
        <div style="margin-top:1rem"><a href="admin.php?p=products" class="btn">Späť na zoznam</a></div>
        <?php endif; ?>
    </div>
    <link rel="stylesheet" href="vendor/bbf/bbf.css">
    <script src="vendor/bbf/bbf.js"></script>
    <script>
    (function() {
        var prefill = <?= $bbfProductData ?>;
        BBF.baseUrl = 'vendor/bbf/';
        BBF.render('admin-product', '#bbf-product', {
            prefill: prefill,
            onSuccess: function(resp) {
                if (resp.redirect) { window.location.href = resp.redirect; return false; }
            },
            onBeforeSubmit: function(data) {
                // Inject attribute fields into submission data
                document.querySelectorAll('[data-bbf-inject]').forEach(function(el) {
                    if (el.name && el.value) data[el.name] = el.value;
                });
                return data;
            }
        });
    })();
    </script>
    <script>
    var catAttrMap = <?= json_encode($catAttrMap, JSON_UNESCAPED_UNICODE) ?>;
    var existingSpecs = <?= json_encode($existingSpecs, JSON_UNESCAPED_UNICODE) ?>;
    function updateAttrFields() {
        var catId = document.getElementById('category-select').value;
        var attrs = catAttrMap[catId] || [];
        var container = document.getElementById('attr-fields');
        if (!attrs.length) {
            container.innerHTML = '<p class="muted" style="color:#64748b;font-size:.85rem">Pre túto kategóriu nie sú definované atribúty. <a href="admin.php?p=attributes">Spravovať atribúty</a></p>';
            return;
        }
        var html = '';
        attrs.forEach(function(a) {
            var spec = existingSpecs[a.name] || {};
            var curVal = spec.criteria_value || '';
            var curNum = spec.numeric_val || '';
            html += '<div class="form-row"><label>' + escHtml(a.name) + (a.unit ? ' (' + escHtml(a.unit) + ')' : '') + '</label>';
            if (a.type === 'enum') {
                html += '<select name="attr[' + escHtml(a.name) + ']"><option value="">— nevybrané —</option>';
                (a.options || []).forEach(function(o) {
                    html += '<option value="' + escHtml(o) + '"' + (curVal === o ? ' selected' : '') + '>' + escHtml(o) + '</option>';
                });
                html += '</select>';
            } else if (a.type === 'number') {
                html += '<input type="number" step="any" name="attr[' + escHtml(a.name) + ']" value="' + escHtml(curNum || curVal) + '" placeholder="' + escHtml(a.unit || 'číslo') + '">';
            } else if (a.type === 'boolean') {
                html += '<select name="attr[' + escHtml(a.name) + ']"><option value="">— nevybrané —</option>';
                html += '<option value="Áno"' + (curVal === 'Áno' ? ' selected' : '') + '>Áno</option>';
                html += '<option value="Nie"' + (curVal === 'Nie' ? ' selected' : '') + '>Nie</option></select>';
            }
            html += '</div>';
        });
        container.innerHTML = html;
    }
    function escHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    </script>
    <?php
}

function pageOrders(): void {
    $db = Db::get();
    $page = max(1, Security::int($_GET['page'] ?? 1));
    $perPage = 25;
    $offset = ($page - 1) * $perPage;
    $statusFilter = $_GET['status'] ?? '';

    $where = '1=1';
    $params = [];
    if ($statusFilter) {
        $where .= " AND status = :status";
        $params[':status'] = $statusFilter;
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE {$where}");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));

    $params[':limit'] = $perPage;
    $params[':offset'] = $offset;
    $stmt = $db->prepare("SELECT * FROM orders WHERE {$where} ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
    ?>
    <h1><?= esc(I18n::t('admin.title.orders')) ?> <span class="muted">(<?= $total ?>)</span></h1>
    <div class="card">
        <form method="get" style="display:flex;gap:.5rem;margin-bottom:1rem">
            <input type="hidden" name="p" value="orders">
            <select name="status">
                <option value="">— <?= esc(I18n::t('admin.th.status')) ?> —</option>
                <?php foreach (OrderWorkflow::statuses() as $s): ?>
                <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn" type="submit"><?= esc(I18n::t('admin.btn.apply')) ?></button>
        </form>
        <table>
            <tr><th><?= esc(I18n::t('admin.th.number')) ?></th><th><?= esc(I18n::t('admin.th.customer')) ?></th><th><?= esc(I18n::t('admin.th.email')) ?></th><th><?= esc(I18n::t('admin.th.amount')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.th.date')) ?></th><th></th></tr>
            <?php foreach ($orders as $o): ?>
            <tr>
                <td><a href="admin.php?p=order&id=<?= $o['id'] ?>"><?= esc($o['order_number']) ?></a></td>
                <td><?= esc($o['customer_name']) ?></td>
                <td class="muted"><?= esc($o['customer_email']) ?></td>
                <td><?= I18n::price((int)$o['total'], $o['currency']) ?></td>
                <td><span class="badge badge-<?= esc($o['status']) ?>"><?= esc($o['status']) ?></span></td>
                <td class="muted"><?= esc(substr($o['created_at'], 0, 16)) ?></td>
                <td><a href="admin.php?p=order&id=<?= $o['id'] ?>" class="btn btn-sm">Detail</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php pagination($page, $pages, "admin.php?p=orders&status=" . urlencode($statusFilter)); ?>
    </div>
    <?php
}

function pageOrder(): void {
    $id = Security::int($_GET['id'] ?? 0);
    $order = Orders::byId($id);
    if (!$order) { echo '<p>' . esc(I18n::t('admin.order.not_found')) . '</p>'; return; }

    $items = Orders::items($id);
    $allowed = OrderWorkflow::allowedTransitions($order['status']);
    $logs = AuditLog::forEntity('order_status', $id, 20);
    $addr = json_decode($order['shipping_addr'] ?? '{}', true);
    ?>
    <h1><?= esc(I18n::t('admin.order.label')) ?> <?= esc($order['order_number']) ?></h1>

    <div class="grid" style="grid-template-columns:1fr 1fr;gap:1rem">
        <div class="card">
            <h3><?= esc(I18n::t('admin.th.customer')) ?></h3>
            <p><strong><?= esc($order['customer_name']) ?></strong></p>
            <p><?= esc($order['customer_email']) ?></p>
            <p><?= esc($order['customer_phone']) ?></p>
            <?php if ($addr): ?>
            <p class="muted"><?= esc(($addr['street'] ?? '') . ', ' . ($addr['zip'] ?? '') . ' ' . ($addr['city'] ?? '') . ', ' . ($addr['country'] ?? '')) ?></p>
            <?php endif; ?>
        </div>
        <div class="card">
            <h3><?= esc(I18n::t('admin.order.status_prefix')) ?>: <span class="badge badge-<?= esc($order['status']) ?>"><?= esc($order['status']) ?></span></h3>
            <?php if (!empty($allowed)): ?>
            <form method="post" action="admin.php?p=order&id=<?= $id ?>&action=status" style="margin-top:.5rem">
                <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                <select name="new_status">
                    <?php foreach ($allowed as $s): ?>
                    <option value="<?= esc($s) ?>"><?= esc($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <input name="note" placeholder="Poznámka...">
                <button class="btn btn-primary btn-sm" type="submit">Zmeniť stav</button>
            </form>
            <?php else: ?>
            <p class="muted">Konečný stav — žiadne ďalšie prechody.</p>
            <?php endif; ?>
            <p class="muted" style="margin-top:.5rem">Platba: <?= esc($order['payment_method']) ?> <?= $order['payment_id'] ? '(' . esc($order['payment_id']) . ')' : '' ?></p>
        </div>
    </div>

    <div class="card">
        <h3>Položky</h3>
        <table>
            <tr><th><?= esc(I18n::t('admin.th.sku')) ?></th><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.th.qty')) ?></th><th><?= esc(I18n::t('admin.th.price_per_unit')) ?></th><th><?= esc(I18n::t('admin.th.total')) ?></th></tr>
            <?php foreach ($items as $item): ?>
            <tr>
                <td><?= esc($item['sku']) ?></td>
                <td><?= esc($item['name']) ?></td>
                <td><?= (int)$item['quantity'] ?></td>
                <td><?= I18n::price((int)$item['unit_price'], $order['currency']) ?></td>
                <td><?= I18n::price((int)$item['total'], $order['currency']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <div style="text-align:right;margin-top:.5rem">
            <p>Medzisúčet: <strong><?= I18n::price((int)$order['subtotal'], $order['currency']) ?></strong></p>
            <p>Doprava: <?= I18n::price((int)$order['shipping_cost'], $order['currency']) ?></p>
            <?php if ((int)$order['tax_amount'] > 0): ?>
            <p>DPH: <?= I18n::price((int)$order['tax_amount'], $order['currency']) ?></p>
            <?php endif; ?>
            <p style="font-size:1.2rem"><strong>Celkom: <?= I18n::price((int)$order['total'], $order['currency']) ?></strong></p>
        </div>
    </div>

    <?php if ($logs): ?>
    <div class="card">
        <h3>História</h3>
        <table>
            <tr><th>Akcia</th><th>Z</th><th>Na</th><th>Poznámka</th><th>Kto</th><th>Kedy</th></tr>
            <?php foreach ($logs as $log): ?>
            <tr>
                <td><?= esc($log['action']) ?></td>
                <td><?= esc($log['old_value'] ?? '') ?></td>
                <td><?= esc($log['new_value'] ?? '') ?></td>
                <td class="muted"><?= esc($log['note'] ?? '') ?></td>
                <td><?= esc($log['actor']) ?></td>
                <td class="muted"><?= esc(substr($log['created_at'], 0, 16)) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <?php
    $emailStmt = Db::get()->prepare("SELECT id, template, locale, subject, adapter, status, error, sent_at, created_at
                                     FROM email_log WHERE order_id = :oid ORDER BY id DESC LIMIT 20");
    $emailStmt->execute([':oid' => $id]);
    $emails = $emailStmt->fetchAll();
    ?>
    <?php if ($emails): ?>
    <div class="card">
        <h3>Emaily</h3>
        <table>
            <tr><th>Template</th><th>Locale</th><th>Subject</th><th>Adapter</th><th>Status</th><th>Odoslané</th><th></th></tr>
            <?php foreach ($emails as $em): ?>
            <tr>
                <td><?= esc($em['template']) ?></td>
                <td><?= esc($em['locale']) ?></td>
                <td class="muted"><?= esc($em['subject']) ?></td>
                <td><?= esc($em['adapter']) ?></td>
                <td><span class="badge badge-<?= esc($em['status']) ?>"><?= esc($em['status']) ?></span>
                    <?php if (!empty($em['error'])): ?><br><small class="muted"><?= esc($em['error']) ?></small><?php endif; ?>
                </td>
                <td class="muted"><?= esc($em['sent_at'] ? substr($em['sent_at'], 0, 16) : substr($em['created_at'], 0, 16)) ?></td>
                <td>
                    <form method="post" action="admin.php?p=order&id=<?= $id ?>&action=email_resend" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="log_id" value="<?= (int)$em['id'] ?>">
                        <button class="btn btn-sm" type="submit">Poslať znova</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <a href="admin.php?p=orders" class="btn">← Späť na zoznam</a>
    <?php
}

function pageImport(): void {
    ?>
    <h1>Import CSV</h1>
    <div class="card">
        <div id="bbf-import" data-form="admin-import" data-lang="sk"></div>
    </div>
    <div class="card">
        <h3>Šablóny</h3>
        <p class="muted">Stiahnite si vzorový CSV z <code>import/test-products.csv</code></p>
    </div>
    <link rel="stylesheet" href="vendor/bbf/bbf.css">
    <script src="vendor/bbf/bbf.js"></script>
    <script>
    (function() {
        BBF.baseUrl = 'vendor/bbf/';
        BBF.render('admin-import', '#bbf-import', {
            onSuccess: function(resp) {
                if (resp.message) alert(resp.message);
                if (resp.redirect) { window.location.href = resp.redirect; return false; }
            }
        });
    })();
    </script>
    <?php
}

function pageCache(): void {
    $stats = [
        'files' => 0,
        'size'  => 0,
    ];
    $cacheDir = __DIR__ . '/cache';
    if (is_dir($cacheDir)) {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $file) {
            if ($file->isFile() && $file->getExtension() === 'json') {
                $stats['files']++;
                $stats['size'] += $file->getSize();
            }
        }
    }
    $jobStats = JobQueue::stats();
    ?>
    <h1>Cache Management</h1>
    <div class="grid grid-4">
        <div class="card stat"><div class="num"><?= $stats['files'] ?></div><div class="label">Cache súborov</div></div>
        <div class="card stat"><div class="num"><?= round($stats['size']/1024, 1) ?>K</div><div class="label">Veľkosť</div></div>
        <div class="card stat"><div class="num"><?= $jobStats['pending'] ?></div><div class="label">Joby čakajúce</div></div>
        <div class="card stat"><div class="num"><?= $jobStats['failed'] ?></div><div class="label">Joby zlyhané</div></div>
    </div>
    <div class="card">
        <h3>Akcie</h3>
        <form method="post" action="admin.php?p=cache&action=flush" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <button class="btn btn-danger" type="submit" onclick="return confirm('Flush celú cache?')">Flush Cache</button>
        </form>
        <form method="post" action="admin.php?p=cache&action=rebuild" style="display:inline;margin-left:.5rem">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <button class="btn btn-primary" type="submit">Rebuild Cache</button>
        </form>
    </div>
    <?php
}

function pageAudit(): void {
    $db = Db::get();
    $page = max(1, Security::int($_GET['page'] ?? 1));
    $perPage = 50;
    $offset = ($page - 1) * $perPage;

    $total = (int)$db->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));

    $stmt = $db->prepare("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
    $stmt->execute([':limit' => $perPage, ':offset' => $offset]);
    $logs = $stmt->fetchAll();
    ?>
    <h1>Audit Log <span class="muted">(<?= $total ?>)</span></h1>
    <div class="card">
        <table>
            <tr><th>Entita</th><th>ID</th><th>Akcia</th><th>Staré</th><th>Nové</th><th>Poznámka</th><th>Kto</th><th>Kedy</th></tr>
            <?php foreach ($logs as $log): ?>
            <tr>
                <td><?= esc($log['entity']) ?></td>
                <td><?= (int)$log['entity_id'] ?></td>
                <td><?= esc($log['action']) ?></td>
                <td class="muted" style="max-width:150px;overflow:hidden;text-overflow:ellipsis"><?= esc(substr($log['old_value'] ?? '', 0, 80)) ?></td>
                <td class="muted" style="max-width:150px;overflow:hidden;text-overflow:ellipsis"><?= esc(substr($log['new_value'] ?? '', 0, 80)) ?></td>
                <td><?= esc($log['note'] ?? '') ?></td>
                <td><?= esc($log['actor']) ?></td>
                <td class="muted"><?= esc(substr($log['created_at'], 0, 16)) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php pagination($page, $pages, "admin.php?p=audit"); ?>
    </div>
    <?php
}

// ============================================================
// CMS Pages
// ============================================================

function pageCmsPages(): void {
    $db = Db::get();
    $pages = $db->query("SELECT * FROM pages ORDER BY created_at DESC")->fetchAll();
    ?>
    <h1>CMS Stránky</h1>
    <p style="margin-bottom:1rem"><a href="admin.php?p=page_edit" class="btn btn-primary">Nová stránka</a></p>
    <div class="card">
        <table>
            <tr><th>Slug</th><th>Názov</th><th>Stav</th><th>Vytvorené</th><th></th></tr>
            <?php foreach ($pages as $pg): ?>
            <tr>
                <td><code><?= esc($pg['slug']) ?></code></td>
                <td><?= esc($pg['title']) ?></td>
                <td><span class="badge badge-<?= $pg['status'] === 'active' ? 'active' : 'draft' ?>"><?= esc($pg['status']) ?></span></td>
                <td class="muted"><?= esc(substr($pg['created_at'], 0, 16)) ?></td>
                <td><a href="admin.php?p=page_edit&id=<?= (int)$pg['id'] ?>" class="btn btn-sm">Upraviť</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php
}

function pageCmsEdit(): void {
    $id = Security::int($_GET['id'] ?? 0);
    $pg = $id ? Db::get()->prepare("SELECT * FROM pages WHERE id = :id")->execute([':id' => $id]) ? Db::get()->prepare("SELECT * FROM pages WHERE id = :id") : null : null;
    if ($id) {
        $stmt = Db::get()->prepare("SELECT * FROM pages WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $pg = $stmt->fetch();
    }
    ?>
    <h1><?= $id ? 'Upraviť stránku' : 'Nová stránka' ?></h1>
    <div class="card">
        <?php
        $bbfPageData = json_encode([
            '_page_id' => $id,
            'slug'     => $pg['slug'] ?? '',
            'title'    => $pg['title'] ?? '',
            'body'     => $pg['body'] ?? '',
            'status'   => $pg['status'] ?? 'active',
        ], JSON_UNESCAPED_UNICODE);
        ?>
        <div id="bbf-page" data-form="admin-page" data-lang="sk"></div>
        <?php if ($id): ?>
        <div style="margin-top:1rem"><a href="admin.php?p=pages" class="btn">Späť</a></div>
        <?php endif; ?>
    </div>
    <link rel="stylesheet" href="vendor/bbf/bbf.css">
    <script src="vendor/bbf/bbf.js"></script>
    <script>
    (function() {
        BBF.baseUrl = 'vendor/bbf/';
        BBF.render('admin-page', '#bbf-page', {
            prefill: <?= $bbfPageData ?>,
            onSuccess: function(resp) {
                if (resp.redirect) { window.location.href = resp.redirect; return false; }
            }
        });
    })();
    </script>
    <?php
}

function handleCmsPageSave(): void {
    $db = Db::get();
    $id = Security::int($_GET['id'] ?? 0);
    $now = Db::isSqlite() ? "datetime('now')" : 'NOW()';
    $slug = Security::slug($_POST['slug'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $body = $_POST['body'] ?? '';
    $status = in_array($_POST['status'] ?? '', ['active', 'draft']) ? $_POST['status'] : 'active';

    if (!$slug || !$title) {
        setFlash('err', 'Slug a názov sú povinné.');
        header("Location: admin.php?p=page_edit&id={$id}");
        exit;
    }

    if ($id) {
        $db->prepare("UPDATE pages SET slug = :slug, title = :title, body = :body, status = :status, updated_at = {$now} WHERE id = :id")
           ->execute([':slug' => $slug, ':title' => $title, ':body' => $body, ':status' => $status, ':id' => $id]);
        setFlash('ok', 'Stránka aktualizovaná.');
    } else {
        $db->prepare("INSERT INTO pages (slug, title, body, status) VALUES (:slug, :title, :body, :status)")
           ->execute([':slug' => $slug, ':title' => $title, ':body' => $body, ':status' => $status]);
        $id = (int)$db->lastInsertId();
        setFlash('ok', 'Stránka vytvorená.');
    }
    header("Location: admin.php?p=page_edit&id={$id}");
    exit;
}

function handleCmsPageDelete(): void {
    $id = Security::int($_POST['page_id'] ?? 0);
    if ($id) {
        Db::get()->prepare("DELETE FROM pages WHERE id = :id")->execute([':id' => $id]);
        setFlash('ok', 'Stránka zmazaná.');
    }
    header('Location: admin.php?p=pages');
    exit;
}

// ============================================================
// POST handlers
// ============================================================

function handlePost(string $page, string $action): void {
    if (!Security::csrfValidate()) {
        setFlash('err', 'Neplatný CSRF token.');
        return;
    }

    match("{$page}:{$action}") {
        'product:save'   => handleProductSave(),
        'products:bulk'  => handleBulkAction(),
        'order:status'   => handleOrderStatus(),
        'order:email_resend' => handleOrderEmailResend(),
        'import:upload'  => handleImportUpload(),
        'cache:flush'    => handleCacheFlush(),
        'cache:rebuild'  => handleCacheRebuild(),
        'page_edit:save' => handleCmsPageSave(),
        'pages:delete'   => handleCmsPageDelete(),
        'attribute:save' => handleAttributeSave(),
        'attributes:delete' => handleAttributeDelete(),
        'warehouse:save'    => handleWarehouseSave(),
        'warehouses:delete' => handleWarehouseDelete(),
        'feed:save'         => handleFeedSave(),
        'feeds:delete'      => handleFeedDelete(),
        'feeds:sync'        => handleFeedSync(),
        default          => null,
    };
}

function handleProductSave(): void {
    $db = Db::get();
    $id = Security::int($_GET['id'] ?? 0);
    $now = Db::isSqlite() ? "datetime('now')" : 'NOW()';

    $allowedStatuses = ['active', 'draft', 'archived'];
    $catalogFile = __DIR__ . '/catalog.json';
    $catalogTypes = file_exists($catalogFile) ? array_keys(json_decode(file_get_contents($catalogFile), true)['product_types'] ?? []) : [];
    $allowedTypes = array_unique(array_merge(['simple'], $catalogTypes));
    $status = in_array($_POST['status'] ?? '', $allowedStatuses) ? $_POST['status'] : 'active';
    $productType = in_array($_POST['product_type'] ?? '', $allowedTypes) ? $_POST['product_type'] : 'simple';

    $data = [
        ':sku'           => trim($_POST['sku'] ?? ''),
        ':name'          => trim($_POST['name'] ?? ''),
        ':slug'          => Security::slug($_POST['slug'] ?? $_POST['name'] ?? ''),
        ':product_type'  => $productType,
        ':category_id'   => Security::int($_POST['category_id'] ?? 0),
        ':price'         => Security::int($_POST['price'] ?? 0),
        ':compare_price' => Security::int($_POST['compare_price'] ?? 0) ?: null,
        ':stock'         => Security::int($_POST['stock'] ?? 0),
        ':status'        => $status,
        ':summary'       => trim($_POST['summary'] ?? ''),
        ':description'   => trim($_POST['description'] ?? ''),
        ':weight_g'      => Security::int($_POST['weight_g'] ?? 0),
    ];

    // Handle images
    $images = [];
    $imagesText = trim($_POST['images'] ?? '');
    if ($imagesText) {
        $images = array_filter(array_map('trim', explode("\n", $imagesText)));
    }

    // Handle file upload
    if (!empty($_FILES['image_upload']['tmp_name']) && $_FILES['image_upload']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['image_upload']['name'], PATHINFO_EXTENSION));
        // Verify actual MIME type via finfo (not just extension)
        $allowedMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['image_upload']['tmp_name']);
        if (isset($allowedMime[$mime])) {
            $ext = $allowedMime[$mime]; // use detected extension, not user-supplied
            $safeSku = preg_replace('/[^a-zA-Z0-9_-]/', '_', $data[':sku'] ?: uniqid());
            $filename = 'products/' . $safeSku . '-' . time() . '.' . $ext;
            try {
                $storage = Adapters::storage();
                $url = $storage->store($filename, file_get_contents($_FILES['image_upload']['tmp_name']));
                $images[] = $url;
                // Queue resize job
                JobQueue::push('image_resize', ['path' => $filename], 3);
            } catch (\Throwable $e) {
                // Storage not configured, save with URL only
                $images[] = $filename;
            }
        } // else: unsupported MIME type, silently skip
    }
    $data[':images'] = json_encode($images);

    // Validate slug uniqueness
    $slug = $data[':slug'];
    $checkSlug = $db->prepare("SELECT id FROM products WHERE slug = :slug AND id != :id");
    $checkSlug->execute([':slug' => $slug, ':id' => $id ?: -1]);
    if ($checkSlug->fetch()) {
        setFlash('err', 'Slug "' . esc($slug) . '" už existuje pre iný produkt.');
        header("Location: admin.php?p=product&id={$id}");
        exit;
    }

    if ($id) {
        $old = Products::byId($id);
        $stmt = $db->prepare("UPDATE products SET sku=:sku, name=:name, slug=:slug, product_type=:product_type,
            category_id=:category_id, price=:price, compare_price=:compare_price, stock=:stock,
            status=:status, summary=:summary, description=:description, images=:images, weight_g=:weight_g, updated_at={$now} WHERE id=:id");
        $data[':id'] = $id;
        $stmt->execute($data);
        AuditLog::log('product', $id, $old, $data, 'Admin edit');
        setFlash('ok', 'Produkt aktualizovaný.');
    } else {
        $stmt = $db->prepare("INSERT INTO products (sku, name, slug, product_type, category_id, price, compare_price, stock, status, summary, description, images, weight_g)
            VALUES (:sku, :name, :slug, :product_type, :category_id, :price, :compare_price, :stock, :status, :summary, :description, :images, :weight_g)");
        $stmt->execute($data);
        $id = (int)$db->lastInsertId();
        AuditLog::log('product', $id, null, $data, 'Admin create');
        setFlash('ok', 'Produkt vytvorený.');
    }

    // Save attribute values to product_specifications
    $attrValues = $_POST['attr'] ?? [];
    if (!empty($attrValues) && $id) {
        // Get attribute definitions for mapping name → type/unit
        $catId = Security::int($_POST['category_id'] ?? 0);
        $defStmt = $db->prepare("
            SELECT ad.name, ad.type, ad.unit
            FROM attribute_definitions ad
            JOIN category_attributes ca ON ca.attribute_id = ad.id
            WHERE ca.category_id = :cid
        ");
        $defStmt->execute([':cid' => $catId]);
        $attrDefs = [];
        foreach ($defStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $attrDefs[$d['name']] = $d;
        }

        // Delete old attribute-based specs for this product (only those matching defined attributes)
        foreach ($attrDefs as $aName => $aDef) {
            $db->prepare("DELETE FROM product_specifications WHERE product_id = :pid AND criteria_name = :name AND lang = 'sk'")
               ->execute([':pid' => $id, ':name' => $aName]);
        }

        // Insert new values
        $specIns = $db->prepare("INSERT INTO product_specifications (product_id, criteria_name, criteria_value, numeric_val, lang) VALUES (:pid, :name, :val, :num, 'sk')");
        foreach ($attrValues as $name => $val) {
            $val = trim($val);
            if ($val === '' || !isset($attrDefs[$name])) continue;
            $def = $attrDefs[$name];
            $numericVal = null;
            if ($def['type'] === 'number') {
                $numericVal = (float)$val;
                $displayVal = $val . ($def['unit'] ? ' ' . $def['unit'] : '');
            } else {
                $displayVal = $val;
            }
            $specIns->execute([':pid' => $id, ':name' => $name, ':val' => $displayVal, ':num' => $numericVal]);
        }
    }

    header("Location: admin.php?p=product&id={$id}");
    exit;
}

function handleBulkAction(): void {
    // CSRF already validated by handlePost()
    Security::adminRequire('products.edit');
    $ids = array_map('intval', $_POST['product_ids'] ?? []);
    $action = $_POST['bulk_action'] ?? '';
    if (empty($ids) || !$action) { header('Location: admin.php?p=products'); exit; }

    $db = Db::get();
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $now = Db::now();
    switch ($action) {
        case 'activate':
            $db->prepare("UPDATE products SET status = 'active', updated_at = {$now} WHERE id IN ({$ph})")->execute($ids);
            setFlash('ok', count($ids) . ' produktov aktivovaných.');
            break;
        case 'archive':
            $db->prepare("UPDATE products SET status = 'archived', updated_at = {$now} WHERE id IN ({$ph})")->execute($ids);
            setFlash('ok', count($ids) . ' produktov archivovaných.');
            break;
        case 'delete':
            $db->prepare("DELETE FROM products WHERE id IN ({$ph})")->execute($ids);
            setFlash('ok', count($ids) . ' produktov zmazaných.');
            break;
        case 'price_up_5':
        case 'price_up_10':
        case 'price_up_20':
        case 'price_down_5':
        case 'price_down_10':
        case 'price_down_20':
            $percent = (int)filter_var($action, FILTER_SANITIZE_NUMBER_INT);
            $direction = str_contains($action, 'up') ? 1 : -1;
            $multiplier = round(1 + ($direction * abs($percent) / 100), 4);
            $stmt = $db->prepare("UPDATE products SET price = MAX(0, ROUND(price * :mult)), updated_at = {$now} WHERE id IN ({$ph})");
            $stmt->bindValue(':mult', $multiplier);
            $stmt->execute($ids);
            $sign = $direction > 0 ? '+' : '-';
            setFlash('ok', count($ids) . " produktov: cena {$sign}" . abs($percent) . '%.');
            break;
    }
    header('Location: admin.php?p=products');
    exit;
}

function handleExport(): void {
    $db = Db::get();
    $search = $_GET['q'] ?? '';
    $statusFilter = $_GET['status'] ?? '';

    $where = "1=1";
    $params = [];
    if ($search) {
        $where .= " AND (name LIKE :q OR sku LIKE :q2)";
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
        $params[':q'] = "%{$escaped}%";
        $params[':q2'] = "%{$escaped}%";
    }
    if ($statusFilter) { $where .= " AND status = :status"; $params[':status'] = $statusFilter; }

    $stmt = $db->prepare("SELECT sku, name, slug, product_type, price, compare_price, stock, status, summary, weight_g FROM products WHERE {$where} ORDER BY id");
    $stmt->execute($params);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="products-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['sku', 'name', 'slug', 'product_type', 'price', 'compare_price', 'stock', 'status', 'summary', 'weight_g']);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

function handleOrderStatus(): void {
    Security::adminRequire('orders.edit');
    $orderId = Security::int($_GET['id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    $note = trim($_POST['note'] ?? '');

    if (!in_array($newStatus, OrderWorkflow::statuses(), true)) {
        setFlash('err', 'Neplatný stav objednávky.');
        header("Location: admin.php?p=order&id={$orderId}");
        exit;
    }

    try {
        OrderWorkflow::transition($orderId, $newStatus, $note ?: 'Admin status change');
        setFlash('ok', "Stav objednávky zmenený na '" . esc($newStatus) . "'.");
    } catch (\LogicException $e) {
        setFlash('err', 'Neplatný prechod stavu.');
    } catch (\Throwable $e) {
        error_log("Order status change error [{$orderId}]: " . $e->getMessage());
        setFlash('err', 'Chyba pri zmene stavu objednávky.');
    }

    header("Location: admin.php?p=order&id={$orderId}");
    exit;
}

function handleOrderEmailResend(): void {
    Security::adminRequire('orders.edit');
    $orderId = Security::int($_GET['id'] ?? 0);
    $logId   = Security::int($_POST['log_id'] ?? 0);
    if ($logId <= 0) {
        setFlash('err', 'Chýbajúci záznam emailu.');
        header("Location: admin.php?p=order&id={$orderId}");
        exit;
    }
    try {
        $res = MailService::resend($logId);
        if (($res['status'] ?? '') === 'sent') {
            setFlash('ok', 'Email opätovne odoslaný (' . esc($res['adapter'] ?? '') . ').');
        } else {
            setFlash('err', 'Email sa nepodarilo odoslať: ' . esc($res['error'] ?? $res['status'] ?? 'neznáma chyba'));
        }
    } catch (\Throwable $e) {
        error_log("Resend email #{$logId} failed: " . $e->getMessage());
        setFlash('err', 'Chyba pri opätovnom odosielaní emailu.');
    }
    header("Location: admin.php?p=order&id={$orderId}");
    exit;
}

function handleImportUpload(): void {
    if (empty($_FILES['csv_file']['tmp_name'])) {
        setFlash('err', 'Žiadny súbor.');
        return;
    }

    $maxBytes = 50 * 1024 * 1024; // 50 MB
    if (($_FILES['csv_file']['size'] ?? 0) > $maxBytes) {
        setFlash('err', 'Súbor je príliš veľký (max 50 MB).');
        header("Location: admin.php?p=import");
        exit;
    }

    $tmpFile = $_FILES['csv_file']['tmp_name'];
    $result = Import::csv($tmpFile);

    if ($result['success']) {
        AuditLog::log('import', 0, null, ['imported' => $result['imported']], 'CSV import');
        setFlash('ok', "Import úspešný: {$result['imported']} produktov.");
    } else {
        $errCount = count($result['errors']);
        setFlash('err', "Import: {$result['imported']} OK, {$errCount} chýb.");
    }

    header("Location: admin.php?p=import");
    exit;
}

function handleCacheFlush(): void {
    Cache::flush();
    AuditLog::log('cache', 0, null, 'flush', 'Cache flushed');
    setFlash('ok', 'Cache flushed.');
    header("Location: admin.php?p=cache");
    exit;
}

function handleCacheRebuild(): void {
    JobQueue::push('cache_rebuild', ['scope' => 'full'], 1);
    setFlash('ok', 'Cache rebuild job naplánovaný.');
    header("Location: admin.php?p=cache");
    exit;
}

// ============================================================
// Attributes CRUD
// ============================================================

function pageAttributes(): void {
    $db = Db::get();
    $attrs = $db->query("
        SELECT ad.*, GROUP_CONCAT(c.name, ', ') as category_names
        FROM attribute_definitions ad
        LEFT JOIN category_attributes ca ON ca.attribute_id = ad.id
        LEFT JOIN categories c ON c.id = ca.category_id
        GROUP BY ad.id
        ORDER BY ad.sort_order, ad.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <h1>Atribúty</h1>
    <p style="margin-bottom:1rem"><a href="admin.php?p=attribute" class="btn btn-primary">Nový atribút</a></p>
    <div class="card">
        <table>
            <tr><th>Názov</th><th>Slug</th><th>Typ</th><th>Jednotka</th><th>Kategórie</th><th>Poradie</th><th></th></tr>
            <?php foreach ($attrs as $a): ?>
            <tr>
                <td><a href="admin.php?p=attribute&id=<?= (int)$a['id'] ?>"><?= esc($a['name']) ?></a></td>
                <td><code><?= esc($a['slug']) ?></code></td>
                <td><?= esc($a['type']) ?></td>
                <td><?= esc($a['unit'] ?: '—') ?></td>
                <td class="muted"><?= esc($a['category_names'] ?: '—') ?></td>
                <td><?= (int)$a['sort_order'] ?></td>
                <td>
                    <a href="admin.php?p=attribute&id=<?= (int)$a['id'] ?>" class="btn btn-sm">Upraviť</a>
                    <form method="post" action="admin.php?p=attributes&action=delete" style="display:inline" onsubmit="return confirm('Naozaj zmazať?')">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="attr_id" value="<?= (int)$a['id'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit">Zmazať</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($attrs)): ?>
            <tr><td colspan="7" style="text-align:center;color:#64748b">Zatiaľ žiadne atribúty.</td></tr>
            <?php endif; ?>
        </table>
    </div>
    <?php
}

function pageAttribute(): void {
    $db = Db::get();
    $id = Security::int($_GET['id'] ?? 0);
    $attr = null;
    $options = [];
    $assignedCats = [];

    if ($id) {
        $stmt = $db->prepare("SELECT * FROM attribute_definitions WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $attr = $stmt->fetch(PDO::FETCH_ASSOC);

        $opts = $db->prepare("SELECT * FROM attribute_options WHERE attribute_id = :aid ORDER BY sort_order, value");
        $opts->execute([':aid' => $id]);
        $options = $opts->fetchAll(PDO::FETCH_ASSOC);

        $cats = $db->prepare("SELECT category_id FROM category_attributes WHERE attribute_id = :aid");
        $cats->execute([':aid' => $id]);
        $assignedCats = $cats->fetchAll(PDO::FETCH_COLUMN);
    }

    $categories = $db->query("SELECT id, name, path FROM categories ORDER BY path")->fetchAll(PDO::FETCH_ASSOC);
    $types = ['enum' => 'Enum (zoznam hodnôt)', 'number' => 'Číslo', 'boolean' => 'Áno/Nie'];
    ?>
    <h1><?= $attr ? 'Upraviť: ' . esc($attr['name']) : 'Nový atribút' ?></h1>
    <div class="card">
        <?php
        $bbfAttrData = json_encode([
            '_attribute_id' => $id,
            'name'          => $attr['name'] ?? '',
            'slug'          => $attr['slug'] ?? '',
            'type'          => $attr['type'] ?? 'enum',
            'unit'          => $attr['unit'] ?? '',
            'sort_order'    => (string)(int)($attr['sort_order'] ?? 0),
        ], JSON_UNESCAPED_UNICODE);
        ?>
        <div id="bbf-attribute" data-form="admin-attribute" data-lang="sk"></div>

        <div id="options-section">
            <h3 style="margin:1rem 0 .5rem">Hodnoty (pre enum)</h3>
            <div id="options-list">
                <?php foreach ($options as $i => $opt): ?>
                <div class="form-row" style="display:flex;gap:.5rem;align-items:center">
                    <input name="options[]" value="<?= esc($opt['value']) ?>" style="flex:1" placeholder="Hodnota" data-bbf-option>
                    <input type="number" name="option_order[]" value="<?= (int)$opt['sort_order'] ?>" style="width:60px" placeholder="Poradie" data-bbf-option-order>
                    <button type="button" onclick="this.parentElement.remove()" class="btn btn-sm btn-danger">x</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" onclick="addOption()" class="btn btn-sm" style="margin-top:.5rem">+ Pridať hodnotu</button>
        </div>

        <h3 style="margin:1rem 0 .5rem">Priradiť ku kategóriám</h3>
        <input type="text" id="cat-filter" placeholder="Hľadať kategóriu..." style="width:100%;padding:.4rem .6rem;margin-bottom:.5rem;border:1px solid #d1d5db;border-radius:4px" oninput="filterCats()">
        <div style="margin-bottom:.5rem;font-size:.8rem">
            <a href="#" onclick="toggleAllCats(true);return false" style="margin-right:.5rem">Vybrať všetky viditeľné</a>
            <a href="#" onclick="toggleAllCats(false);return false">Odznačiť všetky</a>
            <span id="cat-count" style="float:right;color:#64748b"></span>
        </div>
        <div id="cat-list" style="max-height:350px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:6px;padding:.5rem">
            <?php foreach ($categories as $cat):
                $depth = substr_count($cat['path'] ?: '', '/');
                $indent = $depth * 1.2;
            ?>
            <label class="cat-item" data-search="<?= esc(mb_strtolower($cat['name'] . ' ' . $cat['path'])) ?>" style="display:block;padding:2px 4px 2px <?= $indent + 0.4 ?>rem;cursor:pointer<?= $depth ? ';font-size:.9em' : ';font-weight:600' ?>">
                <input type="checkbox" class="cat-checkbox" value="<?= (int)$cat['id'] ?>" <?= in_array($cat['id'], $assignedCats) ? 'checked' : '' ?>>
                <?= esc($cat['name']) ?>
                <?php if ($cat['path'] && $depth): ?><span style="color:#94a3b8;font-size:.8em;margin-left:.3rem"><?= esc($cat['path']) ?></span><?php endif; ?>
            </label>
            <?php endforeach; ?>
        </div>

        <div style="margin-top:1rem">
            <a href="admin.php?p=attributes" class="btn">Späť</a>
        </div>
    </div>
    <link rel="stylesheet" href="vendor/bbf/bbf.css">
    <script src="vendor/bbf/bbf.js"></script>
    <script>
    (function() {
        BBF.baseUrl = 'vendor/bbf/';
        BBF.render('admin-attribute', '#bbf-attribute', {
            prefill: <?= $bbfAttrData ?>,
            onSuccess: function(resp) {
                if (resp.redirect) { window.location.href = resp.redirect; return false; }
            },
            onBeforeSubmit: function(data) {
                // Inject enum options
                var optEls = document.querySelectorAll('[data-bbf-option]');
                var ordEls = document.querySelectorAll('[data-bbf-option-order]');
                optEls.forEach(function(el, i) {
                    data['option_val_' + i] = el.value;
                });
                ordEls.forEach(function(el, i) {
                    data['option_ord_' + i] = el.value;
                });
                // Inject category checkboxes
                document.querySelectorAll('.cat-checkbox:checked').forEach(function(el) {
                    data['cat_id_' + el.value] = el.value;
                });
                return data;
            }
        });
    })();
    </script>
    <script>
    function toggleOptions() {
        document.getElementById('options-section').style.display = document.getElementById('attr-type').value === 'enum' ? '' : 'none';
    }
    function addOption() {
        var div = document.createElement('div');
        div.className = 'form-row';
        div.style.cssText = 'display:flex;gap:.5rem;align-items:center';
        div.innerHTML = '<input name="options[]" style="flex:1" placeholder="Hodnota">' +
            '<input type="number" name="option_order[]" style="width:60px" placeholder="Poradie" value="0">' +
            '<button type="button" onclick="this.parentElement.remove()" class="btn btn-sm btn-danger">×</button>';
        document.getElementById('options-list').appendChild(div);
    }
    toggleOptions();
    function filterCats() {
        var q = document.getElementById('cat-filter').value.toLowerCase();
        var items = document.querySelectorAll('.cat-item');
        var shown = 0, checked = 0;
        items.forEach(function(el) {
            var match = !q || el.getAttribute('data-search').indexOf(q) !== -1;
            el.style.display = match ? '' : 'none';
            if (match) shown++;
            if (el.querySelector('input').checked) checked++;
        });
        document.getElementById('cat-count').textContent = checked + ' vybraných / ' + shown + ' zobrazených';
    }
    function toggleAllCats(state) {
        document.querySelectorAll('.cat-item').forEach(function(el) {
            if (el.style.display !== 'none') el.querySelector('input').checked = state;
        });
        filterCats();
    }
    filterCats();
    </script>
    <?php
}

function handleAttributeSave(): void {
    $db = Db::get();
    $id = Security::int($_GET['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $slug = Security::slug($_POST['slug'] ?? $name);
    $type = in_array($_POST['type'] ?? '', ['enum', 'number', 'boolean']) ? $_POST['type'] : 'enum';
    $unit = trim($_POST['unit'] ?? '');
    $sortOrder = Security::int($_POST['sort_order'] ?? 0);

    if (!$name || !$slug) {
        setFlash('err', 'Názov a slug sú povinné.');
        header("Location: admin.php?p=attribute" . ($id ? "&id={$id}" : ''));
        exit;
    }

    // Check slug uniqueness
    $check = $db->prepare("SELECT id FROM attribute_definitions WHERE slug = :slug AND id != :id");
    $check->execute([':slug' => $slug, ':id' => $id ?: -1]);
    if ($check->fetch()) {
        setFlash('err', 'Slug "' . esc($slug) . '" už existuje.');
        header("Location: admin.php?p=attribute" . ($id ? "&id={$id}" : ''));
        exit;
    }

    if ($id) {
        $db->prepare("UPDATE attribute_definitions SET name = :name, slug = :slug, type = :type, unit = :unit, sort_order = :sort WHERE id = :id")
           ->execute([':name' => $name, ':slug' => $slug, ':type' => $type, ':unit' => $unit, ':sort' => $sortOrder, ':id' => $id]);
    } else {
        $db->prepare("INSERT INTO attribute_definitions (name, slug, type, unit, sort_order) VALUES (:name, :slug, :type, :unit, :sort)")
           ->execute([':name' => $name, ':slug' => $slug, ':type' => $type, ':unit' => $unit, ':sort' => $sortOrder]);
        $id = (int)$db->lastInsertId();
    }

    // Save enum options
    $db->prepare("DELETE FROM attribute_options WHERE attribute_id = :aid")->execute([':aid' => $id]);
    if ($type === 'enum') {
        $optValues = $_POST['options'] ?? [];
        $optOrders = $_POST['option_order'] ?? [];
        $ins = $db->prepare("INSERT INTO attribute_options (attribute_id, value, sort_order) VALUES (:aid, :val, :sort)");
        foreach ($optValues as $i => $val) {
            $val = trim($val);
            if ($val === '') continue;
            $ins->execute([':aid' => $id, ':val' => $val, ':sort' => (int)($optOrders[$i] ?? 0)]);
        }
    }

    // Save category assignments
    $db->prepare("DELETE FROM category_attributes WHERE attribute_id = :aid")->execute([':aid' => $id]);
    $catIds = array_map('intval', $_POST['category_ids'] ?? []);
    $catIns = $db->prepare("INSERT INTO category_attributes (category_id, attribute_id) VALUES (:cid, :aid)");
    foreach ($catIds as $cid) {
        if ($cid > 0) $catIns->execute([':cid' => $cid, ':aid' => $id]);
    }

    setFlash('ok', 'Atribút uložený.');
    header("Location: admin.php?p=attribute&id={$id}");
    exit;
}

function handleAttributeDelete(): void {
    $id = Security::int($_POST['attr_id'] ?? 0);
    if ($id) {
        $db = Db::get();
        $db->prepare("DELETE FROM attribute_options WHERE attribute_id = :id")->execute([':id' => $id]);
        $db->prepare("DELETE FROM category_attributes WHERE attribute_id = :id")->execute([':id' => $id]);
        $db->prepare("DELETE FROM attribute_definitions WHERE id = :id")->execute([':id' => $id]);
        setFlash('ok', 'Atribút zmazaný.');
    }
    header('Location: admin.php?p=attributes');
    exit;
}

// ============================================================
// Helpers
// ============================================================

function setFlash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function flash(): void {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        echo '<div class="flash flash-' . esc($f['type']) . '">' . esc($f['msg']) . '</div>';
        unset($_SESSION['flash']);
    }
}

function pagination(int $current, int $total, string $baseUrl): void {
    if ($total <= 1) return;
    echo '<div class="pagination">';
    for ($i = 1; $i <= $total; $i++) {
        $sep = str_contains($baseUrl, '?') ? '&' : '?';
        $active = $i === $current ? ' btn-primary' : '';
        echo '<a href="' . esc($baseUrl . $sep . 'page=' . $i) . '" class="btn btn-sm' . $active . '">' . $i . '</a>';
    }
    echo '</div>';
}

// ============================================================
// Warehouses
// ============================================================

function pageWarehouses(): void {
    $warehouses = Warehouse::all();
    ?>
    <h1>Sklady</h1>
    <p style="margin-bottom:1rem"><a href="admin.php?p=warehouse" class="btn btn-primary">Nový sklad</a></p>
    <div class="card">
        <table>
            <thead><tr><th>Kód</th><th>Názov</th><th>Priorita</th><th>Dropship</th><th>Default</th><th>Feedov</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($warehouses as $w):
                $feedCount = count(Warehouse::feedsByWarehouse((int)$w['id']));
            ?>
            <tr>
                <td><code><?= esc($w['code']) ?></code></td>
                <td><a href="admin.php?p=warehouse&id=<?= $w['id'] ?>"><?= esc($w['name']) ?></a></td>
                <td><?= (int)$w['priority'] ?></td>
                <td><?= $w['is_dropship'] ? 'Ano' : '—' ?></td>
                <td><?= $w['is_default'] ? 'Ano' : '—' ?></td>
                <td><?= $feedCount ?></td>
                <td>
                    <form method="post" action="admin.php?p=warehouses&action=delete" style="display:inline" onsubmit="return confirm('Zmazat sklad?')">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="warehouse_id" value="<?= $w['id'] ?>">
                        <button class="btn btn-sm btn-danger">Zmazat</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function pageWarehouse(): void {
    $id = Security::int($_GET['id'] ?? 0);
    $w = $id ? Warehouse::byId($id) : null;
    ?>
    <h1><?= $w ? 'Upravit: ' . esc($w['name']) : 'Novy sklad' ?></h1>
    <div class="card">
        <form method="post" action="admin.php?p=warehouse&action=save<?= $w ? '&id='.$w['id'] : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="form-row"><label>Kod (unikatny)</label><input name="code" value="<?= esc($w['code'] ?? '') ?>" required placeholder="napr. main, neosolar"></div>
            <div class="form-row"><label>Nazov</label><input name="name" value="<?= esc($w['name'] ?? '') ?>" required style="width:100%"></div>
            <div class="form-row"><label>Priorita (vyssia = prednost)</label><input type="number" name="priority" value="<?= (int)($w['priority'] ?? 0) ?>"></div>
            <div class="form-row"><label>Typ</label>
                <select name="is_dropship">
                    <option value="0" <?= empty($w['is_dropship']) ? 'selected' : '' ?>>Vlastny sklad</option>
                    <option value="1" <?= !empty($w['is_dropship']) ? 'selected' : '' ?>>Dropship (externy dodavatel)</option>
                </select>
            </div>
            <div class="form-row"><label><input type="checkbox" name="is_default" value="1" <?= !empty($w['is_default']) ? 'checked' : '' ?>> Predvoleny sklad</label></div>
            <div class="form-row"><label>Kontakt</label><input name="contact" value="<?= esc($w['contact'] ?? '') ?>" style="width:100%"></div>
            <div class="form-row"><label>Poznamky</label><textarea name="notes" rows="3" style="width:100%"><?= esc($w['notes'] ?? '') ?></textarea></div>
            <button class="btn btn-primary" type="submit">Ulozit</button>
            <a href="admin.php?p=warehouses" class="btn" style="margin-left:.5rem">Spat</a>
        </form>
    </div>
    <?php if ($w): ?>
    <div class="card" style="margin-top:1rem">
        <h3>Feedy pre tento sklad</h3>
        <?php $feeds = Warehouse::feedsByWarehouse($id); ?>
        <?php if (empty($feeds)): ?>
            <p class="muted">Ziadne feedy. <a href="admin.php?p=feed&warehouse_id=<?= $id ?>">Pridat feed</a></p>
        <?php else: ?>
            <table>
                <thead><tr><th>Kod</th><th>Nazov</th><th>Format</th><th>Posledny sync</th><th>Stav</th></tr></thead>
                <tbody>
                <?php foreach ($feeds as $f): ?>
                <tr>
                    <td><a href="admin.php?p=feed&id=<?= $f['id'] ?>"><?= esc($f['code']) ?></a></td>
                    <td><?= esc($f['name']) ?></td>
                    <td><?= esc($f['format']) ?></td>
                    <td><?= $f['last_sync'] ? esc(substr($f['last_sync'], 0, 16)) : '<span class="muted">nikdy</span>' ?></td>
                    <td><?= esc($f['last_status'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php
}

function handleWarehouseSave(): void {
    $id = Security::int($_GET['id'] ?? 0);
    $data = [
        'id' => $id,
        'code' => Security::slug($_POST['code'] ?? ''),
        'name' => trim($_POST['name'] ?? ''),
        'priority' => Security::int($_POST['priority'] ?? 0),
        'is_dropship' => (int)!empty($_POST['is_dropship']),
        'is_default' => (int)!empty($_POST['is_default']),
        'contact' => trim($_POST['contact'] ?? ''),
        'notes' => trim($_POST['notes'] ?? ''),
    ];
    if (!$data['code'] || !$data['name']) {
        setFlash('err', 'Kod a nazov su povinne.');
        header("Location: admin.php?p=warehouse" . ($id ? "&id={$id}" : ''));
        exit;
    }
    $id = Warehouse::save($data);
    setFlash('ok', 'Sklad ulozeny.');
    header("Location: admin.php?p=warehouse&id={$id}");
    exit;
}

function handleWarehouseDelete(): void {
    $id = Security::int($_POST['warehouse_id'] ?? 0);
    if ($id) {
        Warehouse::delete($id);
        setFlash('ok', 'Sklad zmazany.');
    }
    header('Location: admin.php?p=warehouses');
    exit;
}

// ============================================================
// Feeds
// ============================================================

function pageFeeds(): void {
    $feeds = Warehouse::allFeeds();
    ?>
    <h1>Feedy</h1>
    <p style="margin-bottom:1rem"><a href="admin.php?p=feed" class="btn btn-primary">Novy feed</a></p>
    <div class="card">
        <table>
            <thead><tr><th>Kod</th><th>Nazov</th><th>Sklad</th><th>Format</th><th>Posledny sync</th><th>Produktov</th><th>Stav</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($feeds as $f): ?>
            <tr>
                <td><a href="admin.php?p=feed&id=<?= $f['id'] ?>"><?= esc($f['code']) ?></a></td>
                <td><?= esc($f['name']) ?></td>
                <td><?= esc($f['warehouse_name']) ?></td>
                <td><?= esc($f['format']) ?></td>
                <td><?= $f['last_sync'] ? esc(substr($f['last_sync'], 0, 16)) : '<span class="muted">nikdy</span>' ?></td>
                <td><?= (int)$f['last_count'] ?></td>
                <td><?= $f['enabled'] ? esc($f['last_status'] ?? '—') : '<span class="muted">Vypnuty</span>' ?></td>
                <td>
                    <form method="post" action="admin.php?p=feeds&action=sync" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="feed_id" value="<?= $f['id'] ?>">
                        <button class="btn btn-sm">Sync teraz</button>
                    </form>
                    <form method="post" action="admin.php?p=feeds&action=delete" style="display:inline" onsubmit="return confirm('Zmazat feed?')">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="feed_id" value="<?= $f['id'] ?>">
                        <button class="btn btn-sm btn-danger">Zmazat</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function pageFeed(): void {
    $id = Security::int($_GET['id'] ?? 0);
    $f = $id ? Warehouse::feedById($id) : null;
    $warehouses = Warehouse::all();
    $preselect = Security::int($_GET['warehouse_id'] ?? ($f['warehouse_id'] ?? 0));
    ?>
    <h1><?= $f ? 'Upravit: ' . esc($f['name']) : 'Novy feed' ?></h1>
    <div class="card">
        <form method="post" action="admin.php?p=feed&action=save<?= $f ? '&id='.$f['id'] : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="form-row"><label>Kod (unikatny)</label><input name="code" value="<?= esc($f['code'] ?? '') ?>" required placeholder="napr. neosolar-victron"></div>
            <div class="form-row"><label>Nazov</label><input name="name" value="<?= esc($f['name'] ?? '') ?>" required style="width:100%"></div>
            <div class="form-row"><label>Sklad</label>
                <select name="warehouse_id" required>
                    <option value="">— Vyberte sklad —</option>
                    <?php foreach ($warehouses as $w): ?>
                    <option value="<?= $w['id'] ?>" <?= $preselect == $w['id'] ? 'selected' : '' ?>><?= esc($w['name']) ?> (<?= esc($w['code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>URL feedu</label><input name="url" value="<?= esc($f['url'] ?? '') ?>" required style="width:100%" placeholder="https://..."></div>
            <div class="form-row"><label>Format</label>
                <select name="format">
                    <option value="xml" <?= ($f['format'] ?? 'xml') === 'xml' ? 'selected' : '' ?>>XML (SHOP/SHOPITEM)</option>
                    <option value="csv" <?= ($f['format'] ?? '') === 'csv' ? 'selected' : '' ?>>CSV</option>
                </select>
            </div>
            <div class="form-row"><label>Import mod</label>
                <select name="import_mode">
                    <option value="upsert" <?= ($f['import_mode'] ?? 'upsert') === 'upsert' ? 'selected' : '' ?>>Upsert (aktualizuj existujuce, pridaj nove)</option>
                    <option value="stock_only" <?= ($f['import_mode'] ?? '') === 'stock_only' ? 'selected' : '' ?>>Len stock (neaktualizuj produkty)</option>
                </select>
            </div>
            <div class="form-row"><label>Mapovanie poli (JSON)</label>
                <textarea name="field_map" rows="12" style="width:100%;font-family:monospace;font-size:.8rem"><?= esc($f['field_map'] ?? "{\n    \"ITEM_ID\": \"sku\",\n    \"PRODUCTNAME\": \"name\",\n    \"CATEGORY_TEXT\": {\"field\": \"category\", \"transform\": \"category_path\", \"separator\": \" | \"},\n    \"PRICE_VAT\": {\"field\": \"price\", \"transform\": \"price_to_cents\"},\n    \"STORE\": {\"field\": \"stock\", \"transform\": \"boolean_stock\", \"default_qty\": 10},\n    \"IMGURL\": \"image\",\n    \"IMGURL_ALTERNATIVE\": {\"field\": \"image\", \"append\": true},\n    \"DESCRIPTION\": \"description\",\n    \"DESCRIPTION_SHORT\": \"summary\",\n    \"WEIGHT\": {\"field\": \"weight_g\", \"transform\": \"weight_to_grams\"},\n    \"MANUFACTURER\": \"attr:manufacturer\",\n    \"EAN\": \"attr:ean\"\n}") ?></textarea>
            </div>
            <div class="form-row"><label>Opakovanie (cron vyraz)</label><input name="schedule" value="<?= esc($f['schedule'] ?? '0 */6 * * *') ?>" placeholder="0 */6 * * *"></div>
            <div class="form-row"><label><input type="checkbox" name="enabled" value="1" <?= ($f['enabled'] ?? 1) ? 'checked' : '' ?>> Aktivny</label></div>
            <button class="btn btn-primary" type="submit">Ulozit</button>
            <a href="admin.php?p=feeds" class="btn" style="margin-left:.5rem">Spat</a>
        </form>
    </div>
    <?php if ($f): ?>
    <div class="card" style="margin-top:1rem">
        <h3>Posledny sync</h3>
        <p>Cas: <?= $f['last_sync'] ? esc($f['last_sync']) : 'nikdy' ?></p>
        <p>Stav: <?= esc($f['last_status'] ?? '—') ?></p>
        <p>Produktov: <?= (int)$f['last_count'] ?></p>
        <form method="post" action="admin.php?p=feeds&action=sync" style="margin-top:.5rem">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <input type="hidden" name="feed_id" value="<?= $f['id'] ?>">
            <button class="btn btn-primary">Spustit sync teraz</button>
        </form>
    </div>
    <?php endif; ?>
    <?php
}

function handleFeedSave(): void {
    $id = Security::int($_GET['id'] ?? 0);

    // Validate JSON field_map
    $fieldMapRaw = trim($_POST['field_map'] ?? '{}');
    $fieldMap = json_decode($fieldMapRaw, true);
    if ($fieldMap === null && $fieldMapRaw !== '{}') {
        setFlash('err', 'Neplatny JSON v mapovani poli: ' . json_last_error_msg());
        header("Location: admin.php?p=feed" . ($id ? "&id={$id}" : ''));
        exit;
    }

    $data = [
        'id' => $id,
        'code' => Security::slug($_POST['code'] ?? ''),
        'name' => trim($_POST['name'] ?? ''),
        'warehouse_id' => Security::int($_POST['warehouse_id'] ?? 0),
        'url' => trim($_POST['url'] ?? ''),
        'format' => in_array($_POST['format'] ?? '', ['xml', 'csv']) ? $_POST['format'] : 'xml',
        'field_map' => json_encode($fieldMap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        'import_mode' => in_array($_POST['import_mode'] ?? '', ['upsert', 'stock_only']) ? $_POST['import_mode'] : 'upsert',
        'schedule' => trim($_POST['schedule'] ?? '0 */6 * * *'),
        'enabled' => (int)!empty($_POST['enabled']),
    ];

    if (!$data['code'] || !$data['name'] || !$data['warehouse_id'] || !$data['url']) {
        setFlash('err', 'Kod, nazov, sklad a URL su povinne.');
        header("Location: admin.php?p=feed" . ($id ? "&id={$id}" : ''));
        exit;
    }

    $id = Warehouse::saveFeed($data);
    setFlash('ok', 'Feed ulozeny.');
    header("Location: admin.php?p=feed&id={$id}");
    exit;
}

function handleFeedDelete(): void {
    $id = Security::int($_POST['feed_id'] ?? 0);
    if ($id) {
        Warehouse::deleteFeed($id);
        setFlash('ok', 'Feed zmazany.');
    }
    header('Location: admin.php?p=feeds');
    exit;
}

function handleFeedSync(): void {
    $id = Security::int($_POST['feed_id'] ?? 0);
    if (!$id) {
        setFlash('err', 'Neplatny feed.');
        header('Location: admin.php?p=feeds');
        exit;
    }

    $result = FeedImport::sync($id);

    if (!empty($result['error'])) {
        setFlash('err', 'Feed sync chyba: ' . $result['error']);
    } else {
        $msg = "Sync dokonceny: {$result['imported']} produktov, {$result['stock_updated']} stock updates";
        if (!empty($result['errors'])) {
            $msg .= ', ' . count($result['errors']) . ' chyb';
        }
        setFlash('ok', $msg);
    }

    header("Location: admin.php?p=feed&id={$id}");
    exit;
}
