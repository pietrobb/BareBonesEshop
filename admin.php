<?php
/**
 * BareBones Shop — Admin Panel
 * Minimal admin: dashboard, products CRUD, orders, import, cache.
 * HTTP Basic Auth with brute-force protection.
 */
require_once __DIR__ . '/src/autoload.php';

// Auth
Security::adminAuth();

// Security headers (relaxed CSP for admin inline scripts)
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");

Security::sessionStart();

I18n::setLocale('sk');

$page = $_GET['p'] ?? 'dashboard';
$action = $_GET['action'] ?? '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handlePost($page, $action);
}

// Render page
$title = match($page) {
    'dashboard' => 'Dashboard',
    'products'  => 'Produkty',
    'product'   => isset($_GET['id']) ? 'Upraviť produkt' : 'Nový produkt',
    'orders'    => 'Objednávky',
    'order'     => 'Detail objednávky',
    'import'    => 'Import',
    'cache'     => 'Cache',
    'audit'     => 'Audit Log',
    default     => 'Admin',
};

?><!DOCTYPE html>
<html lang="sk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($title) ?> — BareBonesShop Admin</title>
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
    <h2>BareBonesShop</h2>
    <a href="admin.php?p=dashboard" class="<?= $page==='dashboard'?'active':'' ?>">Dashboard</a>
    <a href="admin.php?p=products" class="<?= $page==='products'?'active':'' ?>">Produkty</a>
    <a href="admin.php?p=orders" class="<?= $page==='orders'?'active':'' ?>">Objednávky</a>
    <a href="admin.php?p=import" class="<?= $page==='import'?'active':'' ?>">Import CSV</a>
    <a href="admin.php?p=cache" class="<?= $page==='cache'?'active':'' ?>">Cache</a>
    <a href="admin.php?p=audit" class="<?= $page==='audit'?'active':'' ?>">Audit Log</a>
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
    default     => pageDashboard(),
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
    <h1>Dashboard</h1>
    <div class="grid grid-4">
        <div class="card stat"><div class="num"><?= $ordersToday ?></div><div class="label">Objednávky dnes</div></div>
        <div class="card stat"><div class="num"><?= I18n::price($revenueToday) ?></div><div class="label">Tržby dnes</div></div>
        <div class="card stat"><div class="num"><?= $pendingOrders ?></div><div class="label">Čakajúce</div></div>
        <div class="card stat"><div class="num"><?= $lowStock ?></div><div class="label">Low stock</div></div>
    </div>

    <div class="card">
        <h3>Štatistiky</h3>
        <p>Celkom produktov: <strong><?= $totalProducts ?></strong></p>
        <p>Joby v rade: <strong><?= $jobStats['pending'] ?></strong> čakajúcich, <strong><?= $jobStats['failed'] ?></strong> zlyhaných</p>
    </div>

    <?php if ($lowStock > 0): ?>
    <div class="card">
        <h3>Low Stock Alert (< 10 ks)</h3>
        <table>
            <tr><th>SKU</th><th>Názov</th><th>Stock</th></tr>
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
        <h3>Posledné objednávky</h3>
        <table>
            <tr><th>#</th><th>Zákazník</th><th>Suma</th><th>Stav</th><th>Dátum</th></tr>
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
    <h1>Produkty <span class="muted">(<?= $total ?>)</span></h1>
    <div class="card">
        <form method="get" style="display:flex;gap:.5rem;margin-bottom:1rem">
            <input type="hidden" name="p" value="products">
            <input name="q" value="<?= esc($search) ?>" placeholder="Hľadať (názov, SKU)...">
            <select name="status">
                <option value="">Všetky stavy</option>
                <option value="active" <?= $statusFilter==='active'?'selected':'' ?>>Active</option>
                <option value="draft" <?= $statusFilter==='draft'?'selected':'' ?>>Draft</option>
                <option value="archived" <?= $statusFilter==='archived'?'selected':'' ?>>Archived</option>
            </select>
            <button class="btn" type="submit">Filtrovať</button>
        </form>
        <table>
            <tr><th>SKU</th><th>Názov</th><th>Cena</th><th>Stock</th><th>Stav</th><th>Akcie</th></tr>
            <?php foreach ($products as $p): ?>
            <tr>
                <td><?= esc($p['sku']) ?></td>
                <td><a href="admin.php?p=product&id=<?= $p['id'] ?>"><?= esc($p['name']) ?></a></td>
                <td><?= I18n::price((int)$p['price'], $p['currency']) ?></td>
                <td class="<?= $p['stock'] < 10 ? 'stock-low' : '' ?>"><?= (int)$p['stock'] ?></td>
                <td><span class="badge badge-<?= esc($p['status']) ?>"><?= esc($p['status']) ?></span></td>
                <td>
                    <a href="admin.php?p=product&id=<?= $p['id'] ?>" class="btn btn-sm">Upraviť</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php pagination($page, $pages, "admin.php?p=products&q=" . urlencode($search) . "&status=" . urlencode($statusFilter)); ?>
    </div>
    <?php
}

function pageProduct(): void {
    $id = Security::int($_GET['id'] ?? 0);
    $product = $id ? Products::byId($id) : null;
    $categories = Db::get()->query("SELECT id, name, path FROM categories ORDER BY path")->fetchAll();
    ?>
    <h1><?= $product ? 'Upraviť: ' . esc($product['name']) : 'Nový produkt' ?></h1>
    <div class="card">
        <form method="post" action="admin.php?p=product&action=save<?= $product ? '&id='.$product['id'] : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="form-row"><label>SKU</label><input name="sku" value="<?= esc($product['sku'] ?? '') ?>" required></div>
            <div class="form-row"><label>Názov</label><input name="name" value="<?= esc($product['name'] ?? '') ?>" required style="width:100%"></div>
            <div class="form-row"><label>Slug</label><input name="slug" value="<?= esc($product['slug'] ?? '') ?>" required></div>
            <div class="form-row"><label>Kategória</label>
                <select name="category_id" required>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= ($product['category_id'] ?? 0) == $cat['id'] ? 'selected' : '' ?>><?= esc($cat['path'] ?: $cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Cena (centy)</label><input type="number" name="price" value="<?= (int)($product['price'] ?? 0) ?>" required></div>
            <div class="form-row"><label>Pôvodná cena (centy)</label><input type="number" name="compare_price" value="<?= (int)($product['compare_price'] ?? 0) ?>"></div>
            <div class="form-row"><label>Stock</label><input type="number" name="stock" value="<?= (int)($product['stock'] ?? 0) ?>"></div>
            <div class="form-row"><label>Typ</label>
                <select name="product_type">
                    <option value="simple" <?= ($product['product_type'] ?? '') === 'simple' ? 'selected' : '' ?>>Simple</option>
                    <option value="clothing" <?= ($product['product_type'] ?? '') === 'clothing' ? 'selected' : '' ?>>Clothing</option>
                    <option value="electronics" <?= ($product['product_type'] ?? '') === 'electronics' ? 'selected' : '' ?>>Electronics</option>
                    <option value="downloadable" <?= ($product['product_type'] ?? '') === 'downloadable' ? 'selected' : '' ?>>Downloadable</option>
                </select>
            </div>
            <div class="form-row"><label>Stav</label>
                <select name="status">
                    <option value="active" <?= ($product['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="draft" <?= ($product['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="archived" <?= ($product['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Archived</option>
                </select>
            </div>
            <div class="form-row"><label>Popis</label><textarea name="summary" rows="3" style="width:100%"><?= esc($product['summary'] ?? '') ?></textarea></div>
            <div class="form-row"><label>Hmotnosť (g)</label><input type="number" name="weight_g" value="<?= (int)($product['weight_g'] ?? 0) ?>"></div>
            <button type="submit" class="btn btn-primary">Uložiť</button>
            <?php if ($product): ?>
                <a href="admin.php?p=products" class="btn">Späť</a>
            <?php endif; ?>
        </form>
    </div>
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
    <h1>Objednávky <span class="muted">(<?= $total ?>)</span></h1>
    <div class="card">
        <form method="get" style="display:flex;gap:.5rem;margin-bottom:1rem">
            <input type="hidden" name="p" value="orders">
            <select name="status">
                <option value="">Všetky stavy</option>
                <?php foreach (OrderWorkflow::statuses() as $s): ?>
                <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn" type="submit">Filtrovať</button>
        </form>
        <table>
            <tr><th>#</th><th>Zákazník</th><th>Email</th><th>Suma</th><th>Stav</th><th>Dátum</th><th></th></tr>
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
    if (!$order) { echo '<p>Objednávka nenájdená.</p>'; return; }

    $items = Orders::items($id);
    $allowed = OrderWorkflow::allowedTransitions($order['status']);
    $logs = AuditLog::forEntity('order_status', $id, 20);
    $addr = json_decode($order['shipping_addr'] ?? '{}', true);
    ?>
    <h1>Objednávka <?= esc($order['order_number']) ?></h1>

    <div class="grid" style="grid-template-columns:1fr 1fr;gap:1rem">
        <div class="card">
            <h3>Zákazník</h3>
            <p><strong><?= esc($order['customer_name']) ?></strong></p>
            <p><?= esc($order['customer_email']) ?></p>
            <p><?= esc($order['customer_phone']) ?></p>
            <?php if ($addr): ?>
            <p class="muted"><?= esc(($addr['street'] ?? '') . ', ' . ($addr['zip'] ?? '') . ' ' . ($addr['city'] ?? '') . ', ' . ($addr['country'] ?? '')) ?></p>
            <?php endif; ?>
        </div>
        <div class="card">
            <h3>Stav: <span class="badge badge-<?= esc($order['status']) ?>"><?= esc($order['status']) ?></span></h3>
            <?php if (!empty($allowed)): ?>
            <form method="post" action="admin.php?p=order&id=<?= $id ?>&action=status" style="margin-top:.5rem">
                <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                <select name="new_status">
                    <?php foreach ($allowed as $s): ?>
                    <option value="<?= $s ?>"><?= $s ?></option>
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
            <tr><th>SKU</th><th>Názov</th><th>Ks</th><th>Cena/ks</th><th>Spolu</th></tr>
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

    <a href="admin.php?p=orders" class="btn">← Späť na zoznam</a>
    <?php
}

function pageImport(): void {
    ?>
    <h1>Import CSV</h1>
    <div class="card">
        <form method="post" enctype="multipart/form-data" action="admin.php?p=import&action=upload">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="form-row">
                <label>CSV súbor</label>
                <input type="file" name="csv_file" accept=".csv" required>
            </div>
            <p class="muted" style="margin-bottom:1rem">Formát: sku, name, slug, product_type, category_path, price, compare_price, stock, images, summary, tags, weight_g, attr:*, name_en, name_de...</p>
            <button class="btn btn-primary" type="submit">Importovať</button>
        </form>
    </div>
    <div class="card">
        <h3>Šablóny</h3>
        <p class="muted">Stiahnite si vzorový CSV z <code>import/test-products.csv</code></p>
    </div>
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
// POST handlers
// ============================================================

function handlePost(string $page, string $action): void {
    if (!Security::csrfValidate()) {
        setFlash('err', 'Neplatný CSRF token.');
        return;
    }

    match("{$page}:{$action}") {
        'product:save'   => handleProductSave(),
        'order:status'   => handleOrderStatus(),
        'import:upload'  => handleImportUpload(),
        'cache:flush'    => handleCacheFlush(),
        'cache:rebuild'  => handleCacheRebuild(),
        default          => null,
    };
}

function handleProductSave(): void {
    $db = Db::get();
    $id = Security::int($_GET['id'] ?? 0);
    $now = Db::isSqlite() ? "datetime('now')" : 'NOW()';

    $allowedStatuses = ['active', 'draft', 'archived'];
    $allowedTypes = ['simple', 'clothing', 'electronics', 'downloadable'];
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
        ':weight_g'      => Security::int($_POST['weight_g'] ?? 0),
    ];

    if ($id) {
        $old = Products::byId($id);
        $stmt = $db->prepare("UPDATE products SET sku=:sku, name=:name, slug=:slug, product_type=:product_type,
            category_id=:category_id, price=:price, compare_price=:compare_price, stock=:stock,
            status=:status, summary=:summary, weight_g=:weight_g, updated_at={$now} WHERE id=:id");
        $data[':id'] = $id;
        $stmt->execute($data);
        AuditLog::log('product', $id, $old, $data, 'Admin edit');
        setFlash('ok', 'Produkt aktualizovaný.');
    } else {
        $stmt = $db->prepare("INSERT INTO products (sku, name, slug, product_type, category_id, price, compare_price, stock, status, summary, weight_g)
            VALUES (:sku, :name, :slug, :product_type, :category_id, :price, :compare_price, :stock, :status, :summary, :weight_g)");
        $stmt->execute($data);
        $id = (int)$db->lastInsertId();
        AuditLog::log('product', $id, null, $data, 'Admin create');
        setFlash('ok', 'Produkt vytvorený.');
    }

    header("Location: admin.php?p=product&id={$id}");
    exit;
}

function handleOrderStatus(): void {
    $orderId = Security::int($_GET['id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    $note = trim($_POST['note'] ?? '');

    try {
        OrderWorkflow::transition($orderId, $newStatus, $note ?: 'Admin status change');
        setFlash('ok', "Stav objednávky zmenený na '{$newStatus}'.");
    } catch (\Throwable $e) {
        setFlash('err', 'Chyba: ' . $e->getMessage());
    }

    header("Location: admin.php?p=order&id={$orderId}");
    exit;
}

function handleImportUpload(): void {
    if (empty($_FILES['csv_file']['tmp_name'])) {
        setFlash('err', 'Žiadny súbor.');
        return;
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
