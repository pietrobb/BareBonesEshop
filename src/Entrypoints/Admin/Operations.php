<?php
/** Dashboard, import, cache, warehouse, feed, shipping and payment operations. */

function pageDashboard(): void {
    $db = Db::get();

    // Today's orders
    $today = date('Y-m-d');
    $storeId = StoreContext::current()->id();
    $stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE store_id=:store AND created_at >= :today");
    $stmt->execute([':store' => $storeId, ':today' => $today]);
    $ordersToday = (int)$stmt->fetchColumn();

    // Today's revenue
    $stmt = $db->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE store_id=:store AND created_at >= :today AND status NOT IN ('cancelled','refunded')");
    $stmt->execute([':store' => $storeId, ':today' => $today]);
    $revenueToday = (int)$stmt->fetchColumn();

    // Pending orders
    $stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE store_id=:store AND status='pending'");
    $stmt->execute([':store' => $storeId]);
    $pendingOrders = (int)$stmt->fetchColumn();

    // Low stock applies only to physical products; digital products are not inventory-tracked.
    $lowStockSummary = Products::lowStockSummary();
    $lowStock = $lowStockSummary['count'];

    // Product count
    $stmt = $db->prepare('SELECT COUNT(*) FROM products WHERE store_id=:store');
    $stmt->execute([':store' => $storeId]);
    $totalProducts = (int)$stmt->fetchColumn();

    // Queue and store-scoped operational incidents.
    $jobStats = JobQueue::stats();
    $operations = OperationalDashboard::summary();
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
        <p><?= esc(I18n::t('admin.dashboard.jobs_queue')) ?> (<?= esc($operations['queue_backend']) ?>): <strong><?= $jobStats['pending'] ?></strong> <?= esc(I18n::t('admin.dashboard.jobs_pending')) ?>, <strong><?= $jobStats['failed'] ?></strong> <?= esc(I18n::t('admin.dashboard.jobs_failed')) ?></p>
        <p class="muted">Correlation: <?= esc($operations['correlation_id']) ?> · Store: <?= esc($operations['store_id']) ?></p>
    </div>

    <div class="card">
        <h3>Operations</h3>
        <div class="grid grid-4">
            <div class="stat"><div class="num"><?= (int)$operations['counts']['failed_jobs'] ?></div><div class="label">Failed jobs</div></div>
            <div class="stat"><div class="num"><?= (int)$operations['counts']['failed_mail'] ?></div><div class="label">Failed mail</div></div>
            <div class="stat"><div class="num"><?= (int)$operations['counts']['payment_incidents'] ?></div><div class="label">Payment incidents</div></div>
            <div class="stat"><div class="num"><?= (int)$operations['counts']['webhook_incidents'] ?></div><div class="label">Webhook incidents</div></div>
        </div>
        <?php if ($operations['failed_jobs']): ?>
        <h4>Failed jobs</h4><div class="table-scroll"><table><tr><th>Type</th><th>Attempts</th><th>Error</th><th>Correlation</th><th>Updated</th></tr>
            <?php foreach ($operations['failed_jobs'] as $incident): ?><tr><td><?= esc($incident['type']) ?></td><td><?= (int)$incident['attempts'] ?></td><td><?= esc($incident['last_error']) ?></td><td><code><?= esc($incident['correlation_id']) ?></code></td><td><?= esc($incident['updated_at']) ?></td></tr><?php endforeach; ?>
        </table></div><?php endif; ?>
        <?php if ($operations['failed_mail']): ?>
        <h4>Failed mail</h4><div class="table-scroll"><table><tr><th>Template</th><th>Recipient</th><th>Error</th><th>Correlation</th><th>Created</th></tr>
            <?php foreach ($operations['failed_mail'] as $incident): ?><tr><td><?= esc($incident['template']) ?></td><td><?= esc($incident['recipient']) ?></td><td><?= esc($incident['error']) ?></td><td><code><?= esc($incident['correlation_id'] ?? '') ?></code></td><td><?= esc($incident['created_at']) ?></td></tr><?php endforeach; ?>
        </table></div><?php endif; ?>
        <?php if ($operations['payment_incidents']): ?>
        <h4>Payment incidents</h4><div class="table-scroll"><table><tr><th>Order</th><th>Status</th><th>Provider</th><th>Correlation</th><th>Created</th></tr>
            <?php foreach ($operations['payment_incidents'] as $incident): ?><tr><td><a href="admin.php?p=order&id=<?= (int)$incident['id'] ?>"><?= esc($incident['order_number']) ?></a></td><td><?= esc($incident['payment_status']) ?></td><td><?= esc($incident['payment_method']) ?></td><td><code><?= esc($incident['correlation_id'] ?? '') ?></code></td><td><?= esc($incident['created_at']) ?></td></tr><?php endforeach; ?>
        </table></div><?php endif; ?>
        <?php if ($operations['webhook_incidents']): ?>
        <h4>Webhook incidents</h4><div class="table-scroll"><table><tr><th>Event</th><th>Status</th><th>Attempts</th><th>Error</th><th>Correlation</th></tr>
            <?php foreach ($operations['webhook_incidents'] as $incident): ?><tr><td><?= esc($incident['event_type']) ?></td><td><?= esc($incident['status']) ?></td><td><?= (int)$incident['attempts'] ?></td><td><?= esc($incident['last_error'] ?? '') ?></td><td><code><?= esc($incident['correlation_id'] ?? '') ?></code></td></tr><?php endforeach; ?>
        </table></div><?php endif; ?>
    </div>

    <?php if ($lowStock > 0): ?>
    <div class="card">
        <h3><?= esc(I18n::t('admin.dashboard.low_stock_alert')) ?></h3>
        <table>
            <tr><th><?= esc(I18n::t('admin.th.sku')) ?></th><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.th.stock')) ?></th></tr>
            <?php foreach ($lowStockSummary['products'] as $row): ?>
            <tr>
                <td><?= esc($row['sku']) ?></td>
                <td><?= esc($row['name']) ?></td>
                <td class="stock-low"><?= (int)$row['stock'] ?></td>
            </tr>
            <?php endforeach; ?>
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
    <script nonce="<?= Security::cspNonce() ?>">
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
    $cacheDir = dirname(__DIR__, 3) . '/cache';
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
        <form method="post" action="admin.php?p=cache&action=flush" style="display:inline" data-confirm="Flush celú cache?">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <button class="btn btn-danger" type="submit">Flush Cache</button>
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
function handleImportUpload(): void {
    $upload = $_FILES['csv_file'] ?? null;
    $tmpFile = is_array($upload) ? (string)($upload['tmp_name'] ?? '') : '';
    $actualSize = $tmpFile !== '' && is_file($tmpFile) ? filesize($tmpFile) : false;
    $basicValid = is_array($upload)
        && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
        && is_uploaded_file($tmpFile)
        && strtolower(pathinfo((string)($upload['name'] ?? ''), PATHINFO_EXTENSION)) === 'csv'
        && $actualSize !== false && $actualSize > 0 && $actualSize <= Import::MAX_FILE_BYTES;
    $mime = $basicValid ? (new finfo(FILEINFO_MIME_TYPE))->file($tmpFile) : false;
    $allowedMime = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'];
    if (!$basicValid || !in_array($mime, $allowedMime, true)) {
        setFlash('err', 'CSV upload je neplatný, príliš veľký alebo má nepodporovaný formát.');
        header("Location: admin.php?p=import");
        exit;
    }

    $result = Import::csv($tmpFile);

    $errCount = count($result['errors']);
    if (($result['imported'] ?? 0) > 0) {
        AuditLog::log('import', 0, null, [
            'imported' => $result['imported'],
            'errors' => $errCount,
            'complete' => (bool)$result['success'],
        ], 'CSV import');
    }
    if ($result['success'] && $errCount === 0) {
        setFlash('ok', "Import úspešný: {$result['imported']} produktov.");
    } else {
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
                    <form method="post" action="admin.php?p=warehouses&action=delete" style="display:inline" data-confirm="Zmazat sklad?">
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
                    <form method="post" action="admin.php?p=feeds&action=delete" style="display:inline" data-confirm="Zmazat feed?">
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
function pageShipping(): void {
    $db = Db::get();
    $methods = $db->query("SELECT * FROM shipping_methods ORDER BY sort_order, code")->fetchAll(PDO::FETCH_ASSOC);
    $zones = $db->query("SELECT * FROM shipping_zones ORDER BY sort_order, code")->fetchAll(PDO::FETCH_ASSOC);
    $countries = $db->query("SELECT * FROM shipping_zone_countries ORDER BY zone_code, country, postal_min")->fetchAll(PDO::FETCH_ASSOC);
    $rates = $db->query("SELECT r.*, m.name AS method_name, z.name AS zone_name FROM shipping_rates r LEFT JOIN shipping_methods m ON m.code = r.method_code LEFT JOIN shipping_zones z ON z.code = r.zone_code ORDER BY COALESCE(r.sort_order, 100), r.method_code, r.country, r.weight_to_g LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <h1>Doprava</h1>
    <div class="card">
        <h3>Metody dopravy</h3>
        <table>
            <thead><tr><th>Kod</th><th>Nazov</th><th>Carrier</th><th>Typ</th><th>Limity</th><th>Aktivna</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($methods as $m): ?>
            <tr>
                <form method="post" action="admin.php?p=shipping&action=method_save">
                    <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                    <td><input name="code" value="<?= esc($m['code']) ?>" required style="width:7rem"></td>
                    <td><input name="name" value="<?= esc($m['name']) ?>" required style="width:12rem"><br><input name="description" value="<?= esc($m['description'] ?? '') ?>" placeholder="popis" style="width:12rem"></td>
                    <td><input name="carrier" value="<?= esc($m['carrier']) ?>" required style="width:7rem"><br><input name="adapter_class" value="<?= esc($m['adapter_class'] ?? '') ?>" placeholder="adapter" style="width:7rem"></td>
                    <td><select name="service_type"><option value="parcel" <?= ($m['service_type'] ?? '')==='parcel'?'selected':'' ?>>parcel</option><option value="pickup" <?= ($m['service_type'] ?? '')==='pickup'?'selected':'' ?>>pickup</option></select><br><label><input type="checkbox" name="supports_cod" value="1" <?= !empty($m['supports_cod'])?'checked':'' ?>> COD</label> <label><input type="checkbox" name="supports_pickup" value="1" <?= !empty($m['supports_pickup'])?'checked':'' ?>> pickup</label></td>
                    <td><input type="number" name="free_above" value="<?= esc($m['free_above'] ?? '') ?>" placeholder="free above" style="width:6rem"><input type="number" name="free_from_qty" value="<?= esc($m['free_from_qty'] ?? '') ?>" placeholder="free from qty" title="Zdarma od N fyzických kusov" style="width:6rem"><br><input type="number" name="max_weight_g" value="<?= esc($m['max_weight_g'] ?? '') ?>" placeholder="max g" style="width:6rem"><input type="number" name="max_volume_cm3" value="<?= esc($m['max_volume_cm3'] ?? '') ?>" placeholder="max cm3" style="width:6rem"></td>
                    <td><input type="number" name="sort_order" value="<?= (int)$m['sort_order'] ?>" style="width:4rem"><br><label><input type="checkbox" name="active" value="1" <?= !empty($m['active'])?'checked':'' ?>> aktivna</label></td>
                    <td><button class="btn btn-sm btn-primary">Ulozit</button>
                </form>
                <form method="post" action="admin.php?p=shipping&action=method_delete" style="display:inline" data-confirm="Zmazat metodu?"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-sm btn-danger">Zmazat</button></form></td>
            </tr>
            <?php endforeach; ?>
            <tr><form method="post" action="admin.php?p=shipping&action=method_save"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><td><input name="code" required placeholder="packeta"></td><td><input name="name" required placeholder="Nazov"><br><input name="description" placeholder="popis"></td><td><input name="carrier" required placeholder="packeta"><br><input name="adapter_class" placeholder="PacketaShipping"></td><td><select name="service_type"><option value="parcel">parcel</option><option value="pickup">pickup</option></select><br><label><input type="checkbox" name="supports_cod" value="1"> COD</label> <label><input type="checkbox" name="supports_pickup" value="1"> pickup</label></td><td><input type="number" name="free_above" placeholder="free above" style="width:6rem"><input type="number" name="free_from_qty" placeholder="free from qty" title="Zdarma od N fyzických kusov" style="width:6rem"><br><input type="number" name="max_weight_g" placeholder="max g" style="width:6rem"><input type="number" name="max_volume_cm3" placeholder="max cm3" style="width:6rem"></td><td><input type="number" name="sort_order" value="100" style="width:4rem"><br><label><input type="checkbox" name="active" value="1" checked> aktivna</label></td><td><button class="btn btn-sm btn-primary">Pridat</button></td></form></tr>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3>Zony a krajiny</h3>
        <table><thead><tr><th>Kod</th><th>Nazov</th><th>Popis</th><th>Aktivna</th><th></th></tr></thead><tbody>
        <?php foreach ($zones as $z): ?>
        <tr><form method="post" action="admin.php?p=shipping&action=zone_save"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><input type="hidden" name="id" value="<?= (int)$z['id'] ?>"><td><input name="code" value="<?= esc($z['code']) ?>" required></td><td><input name="name" value="<?= esc($z['name']) ?>" required></td><td><input name="description" value="<?= esc($z['description'] ?? '') ?>" style="width:100%"></td><td><input type="number" name="sort_order" value="<?= (int)$z['sort_order'] ?>" style="width:4rem"><br><label><input type="checkbox" name="active" value="1" <?= !empty($z['active'])?'checked':'' ?>> aktivna</label></td><td><button class="btn btn-sm btn-primary">Ulozit</button></form> <form method="post" action="admin.php?p=shipping&action=zone_delete" style="display:inline" data-confirm="Zmazat zonu?"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><input type="hidden" name="id" value="<?= (int)$z['id'] ?>"><button class="btn btn-sm btn-danger">Zmazat</button></form></td></tr>
        <?php endforeach; ?>
        <tr><form method="post" action="admin.php?p=shipping&action=zone_save"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><td><input name="code" required placeholder="sk"></td><td><input name="name" required placeholder="Slovensko"></td><td><input name="description" style="width:100%"></td><td><input type="number" name="sort_order" value="100" style="width:4rem"><br><label><input type="checkbox" name="active" value="1" checked> aktivna</label></td><td><button class="btn btn-sm btn-primary">Pridat</button></td></form></tr>
        </tbody></table>
        <h4 style="margin-top:1rem">Krajiny v zonach</h4>
        <table><thead><tr><th>Zona</th><th>Krajina</th><th>PSC od</th><th>PSC do</th><th></th></tr></thead><tbody>
        <?php foreach ($countries as $c): ?>
        <tr><td><?= esc($c['zone_code']) ?></td><td><?= esc($c['country']) ?></td><td><?= esc($c['postal_min'] ?? '') ?></td><td><?= esc($c['postal_max'] ?? '') ?></td><td><form method="post" action="admin.php?p=shipping&action=country_delete" style="display:inline"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sm btn-danger">Zmazat</button></form></td></tr>
        <?php endforeach; ?>
        <tr><form method="post" action="admin.php?p=shipping&action=country_save"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><td><select name="zone_code"><?php foreach ($zones as $z): ?><option value="<?= esc($z['code']) ?>"><?= esc($z['code']) ?></option><?php endforeach; ?></select></td><td><input name="country" maxlength="2" required placeholder="SK" style="width:4rem"></td><td><input name="postal_min" style="width:6rem"></td><td><input name="postal_max" style="width:6rem"></td><td><button class="btn btn-sm btn-primary">Pridat</button></td></form></tr>
        </tbody></table>
    </div>

    <div class="card">
        <h3>Sadzby</h3>
        <table><thead><tr><th>Metoda/zona</th><th>Krajina</th><th>Vaha g</th><th>Objem cm3</th><th>Cena</th><th>COD</th><th>Dni</th><th>Aktivna</th><th></th></tr></thead><tbody>
        <?php foreach ($rates as $r): ?>
        <tr><form method="post" action="admin.php?p=shipping&action=rate_save"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><td><select name="method_code"><?php foreach ($methods as $m): ?><option value="<?= esc($m['code']) ?>" <?= ($r['method_code'] ?? '')===$m['code']?'selected':'' ?>><?= esc($m['code']) ?></option><?php endforeach; ?></select><br><select name="zone_code"><?php foreach ($zones as $z): ?><option value="<?= esc($z['code']) ?>" <?= ($r['zone_code'] ?? '')===$z['code']?'selected':'' ?>><?= esc($z['code']) ?></option><?php endforeach; ?></select></td><td><input name="country" value="<?= esc($r['country']) ?>" maxlength="2" style="width:4rem"></td><td><input type="number" name="weight_from_g" value="<?= (int)$r['weight_from_g'] ?>" style="width:6rem"><br><input type="number" name="weight_to_g" value="<?= (int)$r['weight_to_g'] ?>" style="width:6rem"></td><td><input type="number" name="volume_to_cm3" value="<?= esc($r['volume_to_cm3'] ?? '') ?>" style="width:7rem"></td><td><input type="number" name="price" value="<?= (int)$r['price'] ?>" required style="width:6rem"></td><td><input type="number" name="cod_surcharge" value="<?= (int)$r['cod_surcharge'] ?>" style="width:5rem"></td><td><input name="delivery_days" value="<?= esc($r['delivery_days'] ?? '') ?>" style="width:4rem"></td><td><input type="number" name="sort_order" value="<?= (int)($r['sort_order'] ?? 100) ?>" style="width:4rem"><br><label><input type="checkbox" name="active" value="1" <?= !isset($r['active']) || $r['active']?'checked':'' ?>> aktivna</label></td><td><button class="btn btn-sm btn-primary">Ulozit</button></form> <form method="post" action="admin.php?p=shipping&action=rate_delete" style="display:inline" data-confirm="Zmazat sadzbu?"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-danger">Zmazat</button></form></td></tr>
        <?php endforeach; ?>
        <tr><form method="post" action="admin.php?p=shipping&action=rate_save"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><td><select name="method_code"><?php foreach ($methods as $m): ?><option value="<?= esc($m['code']) ?>"><?= esc($m['code']) ?></option><?php endforeach; ?></select><br><select name="zone_code"><?php foreach ($zones as $z): ?><option value="<?= esc($z['code']) ?>"><?= esc($z['code']) ?></option><?php endforeach; ?></select></td><td><input name="country" maxlength="2" placeholder="SK" style="width:4rem"></td><td><input type="number" name="weight_from_g" value="0" style="width:6rem"><br><input type="number" name="weight_to_g" value="5000" style="width:6rem"></td><td><input type="number" name="volume_to_cm3" style="width:7rem"></td><td><input type="number" name="price" value="0" required style="width:6rem"></td><td><input type="number" name="cod_surcharge" value="0" style="width:5rem"></td><td><input name="delivery_days" style="width:4rem"></td><td><input type="number" name="sort_order" value="100" style="width:4rem"><br><label><input type="checkbox" name="active" value="1" checked> aktivna</label></td><td><button class="btn btn-sm btn-primary">Pridat</button></td></form></tr>
        </tbody></table>
    </div>
    <?php
}
function handleShippingMethodSave(): void {
    $db = Db::get();
    $id = Security::int($_POST['id'] ?? 0);
    $code = Security::slug($_POST['code'] ?? '');
    $carrier = Security::slug($_POST['carrier'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $serviceType = in_array($_POST['service_type'] ?? '', ['parcel', 'pickup'], true) ? $_POST['service_type'] : 'parcel';
    if (!$code || !$carrier || !$name) { setFlash('err', 'Kod, carrier a nazov metody su povinne.'); header('Location: admin.php?p=shipping'); exit; }
    $data = [':code'=>$code, ':carrier'=>$carrier, ':name'=>$name, ':description'=>trim($_POST['description'] ?? ''), ':adapter_class'=>trim($_POST['adapter_class'] ?? ''), ':service_type'=>$serviceType, ':supports_cod'=>(int)!empty($_POST['supports_cod']), ':supports_pickup'=>(int)!empty($_POST['supports_pickup']), ':free_above'=>shippingNullableInt('free_above'), ':free_from_qty'=>shippingNullableInt('free_from_qty'), ':max_weight_g'=>shippingNullableInt('max_weight_g'), ':max_volume_cm3'=>shippingNullableInt('max_volume_cm3'), ':active'=>(int)!empty($_POST['active']), ':sort_order'=>Security::int($_POST['sort_order'] ?? 100)];
    if ($id) { $data[':id'] = $id; $db->prepare("UPDATE shipping_methods SET code=:code, carrier=:carrier, name=:name, description=:description, adapter_class=:adapter_class, service_type=:service_type, supports_cod=:supports_cod, supports_pickup=:supports_pickup, free_above=:free_above, free_from_qty=:free_from_qty, max_weight_g=:max_weight_g, max_volume_cm3=:max_volume_cm3, active=:active, sort_order=:sort_order, updated_at=" . Db::now() . " WHERE id=:id")->execute($data); }
    else { $db->prepare("INSERT INTO shipping_methods (code, carrier, name, description, adapter_class, service_type, supports_cod, supports_pickup, free_above, free_from_qty, max_weight_g, max_volume_cm3, active, sort_order) VALUES (:code, :carrier, :name, :description, :adapter_class, :service_type, :supports_cod, :supports_pickup, :free_above, :free_from_qty, :max_weight_g, :max_volume_cm3, :active, :sort_order)")->execute($data); }
    setFlash('ok', 'Metoda dopravy ulozena.'); header('Location: admin.php?p=shipping'); exit;
}
function handleShippingMethodDelete(): void { $id = Security::int($_POST['id'] ?? 0); if ($id) { Db::get()->prepare("DELETE FROM shipping_methods WHERE id = :id")->execute([':id'=>$id]); setFlash('ok', 'Metoda zmazana.'); } header('Location: admin.php?p=shipping'); exit; }
function handleShippingZoneSave(): void {
    $db = Db::get(); $id = Security::int($_POST['id'] ?? 0); $code = Security::slug($_POST['code'] ?? ''); $name = trim($_POST['name'] ?? '');
    if (!$code || !$name) { setFlash('err', 'Kod a nazov zony su povinne.'); header('Location: admin.php?p=shipping'); exit; }
    $data = [':code'=>$code, ':name'=>$name, ':description'=>trim($_POST['description'] ?? ''), ':active'=>(int)!empty($_POST['active']), ':sort_order'=>Security::int($_POST['sort_order'] ?? 100)];
    if ($id) { $data[':id']=$id; $db->prepare("UPDATE shipping_zones SET code=:code, name=:name, description=:description, active=:active, sort_order=:sort_order, updated_at=" . Db::now() . " WHERE id=:id")->execute($data); }
    else { $db->prepare("INSERT INTO shipping_zones (code, name, description, active, sort_order) VALUES (:code, :name, :description, :active, :sort_order)")->execute($data); }
    setFlash('ok', 'Zona ulozena.'); header('Location: admin.php?p=shipping'); exit;
}
function handleShippingZoneDelete(): void { $id = Security::int($_POST['id'] ?? 0); if ($id) { Db::get()->prepare("DELETE FROM shipping_zones WHERE id = :id")->execute([':id'=>$id]); setFlash('ok', 'Zona zmazana.'); } header('Location: admin.php?p=shipping'); exit; }
function handleShippingCountrySave(): void {
    $db = Db::get(); $zoneCode = Security::slug($_POST['zone_code'] ?? ''); $country = strtoupper(trim($_POST['country'] ?? ''));
    if (!$zoneCode || !preg_match('/^[A-Z]{2}$/', $country)) { setFlash('err', 'Zona a ISO krajina su povinne.'); header('Location: admin.php?p=shipping'); exit; }
    $db->prepare(Db::insertIgnore() . " INTO shipping_zone_countries (zone_code, country, postal_min, postal_max) VALUES (:zone, :country, :min, :max)")->execute([':zone'=>$zoneCode, ':country'=>$country, ':min'=>trim($_POST['postal_min'] ?? ''), ':max'=>trim($_POST['postal_max'] ?? '')]);
    setFlash('ok', 'Krajina pridana do zony.'); header('Location: admin.php?p=shipping'); exit;
}
function handleShippingCountryDelete(): void { $id = Security::int($_POST['id'] ?? 0); if ($id) { Db::get()->prepare("DELETE FROM shipping_zone_countries WHERE id = :id")->execute([':id'=>$id]); setFlash('ok', 'Krajina zmazana.'); } header('Location: admin.php?p=shipping'); exit; }
function handleShippingRateSave(): void {
    $db = Db::get(); $id = Security::int($_POST['id'] ?? 0); $methodCode = Security::slug($_POST['method_code'] ?? ''); $zoneCode = Security::slug($_POST['zone_code'] ?? ''); $country = strtoupper(trim($_POST['country'] ?? ''));
    $from = max(0, Security::int($_POST['weight_from_g'] ?? 0)); $to = max(0, Security::int($_POST['weight_to_g'] ?? 0));
    if (!$methodCode || !$zoneCode || ($country !== '' && !preg_match('/^[A-Z]{2}$/', $country)) || $to < $from) { setFlash('err', 'Neplatna sadzba dopravy.'); header('Location: admin.php?p=shipping'); exit; }
    $stmt = $db->prepare("SELECT carrier FROM shipping_methods WHERE code = :code"); $stmt->execute([':code'=>$methodCode]); $carrier = (string)($stmt->fetchColumn() ?: $methodCode);
    $data = [':method_code'=>$methodCode, ':carrier'=>$carrier, ':country'=>$country, ':zone_code'=>$zoneCode, ':weight_from_g'=>$from, ':weight_to_g'=>$to, ':volume_to_cm3'=>shippingNullableInt('volume_to_cm3'), ':price'=>max(0, Security::int($_POST['price'] ?? 0)), ':cod_surcharge'=>max(0, Security::int($_POST['cod_surcharge'] ?? 0)), ':delivery_days'=>trim($_POST['delivery_days'] ?? ''), ':active'=>(int)!empty($_POST['active']), ':sort_order'=>Security::int($_POST['sort_order'] ?? 100)];
    if ($id) { $data[':id']=$id; $db->prepare("UPDATE shipping_rates SET method_code=:method_code, carrier=:carrier, country=:country, zone_code=:zone_code, weight_from_g=:weight_from_g, weight_to_g=:weight_to_g, volume_to_cm3=:volume_to_cm3, price=:price, cod_surcharge=:cod_surcharge, delivery_days=:delivery_days, active=:active, sort_order=:sort_order, updated_at=" . Db::now() . " WHERE id=:id")->execute($data); }
    else { $db->prepare("INSERT INTO shipping_rates (method_code, carrier, country, zone_code, weight_from_g, weight_to_g, volume_to_cm3, price, cod_surcharge, delivery_days, active, sort_order) VALUES (:method_code, :carrier, :country, :zone_code, :weight_from_g, :weight_to_g, :volume_to_cm3, :price, :cod_surcharge, :delivery_days, :active, :sort_order)")->execute($data); }
    setFlash('ok', 'Sadzba ulozena.'); header('Location: admin.php?p=shipping'); exit;
}
function handleShippingRateDelete(): void { $id = Security::int($_POST['id'] ?? 0); if ($id) { Db::get()->prepare("DELETE FROM shipping_rates WHERE id = :id")->execute([':id'=>$id]); setFlash('ok', 'Sadzba zmazana.'); } header('Location: admin.php?p=shipping'); exit; }
function shippingNullableInt(string $key): ?int { $v = trim((string)($_POST[$key] ?? '')); return $v === '' ? null : max(0, Security::int($v)); }
function pagePayments(): void {
    $db = Db::get();
    $methods = $db->query("SELECT * FROM payment_methods ORDER BY sort_order, code")->fetchAll(PDO::FETCH_ASSOC);
    $countries = $db->query("SELECT * FROM payment_method_countries ORDER BY method_code, country")->fetchAll(PDO::FETCH_ASSOC);
    $shippingLinks = $db->query("SELECT ps.*, sm.name AS shipping_name FROM payment_method_shipping ps LEFT JOIN shipping_methods sm ON sm.code = ps.shipping_method_code ORDER BY ps.method_code, ps.shipping_method_code")->fetchAll(PDO::FETCH_ASSOC);
    $shippingMethods = $db->query("SELECT code, name FROM shipping_methods ORDER BY sort_order, code")->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <h1>Platby</h1>
    <div class="card">
        <h3>Platobne metody</h3>
        <table>
            <thead><tr><th>Kod</th><th>Nazov</th><th>Provider/typ</th><th>Limity</th><th>Poplatok</th><th>Aktivna</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($methods as $m): ?>
            <tr><form method="post" action="admin.php?p=payments&action=method_save"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><td><input name="code" value="<?= esc($m['code']) ?>" required style="width:8rem"></td><td><input name="name" value="<?= esc($m['name']) ?>" required style="width:12rem"><br><input name="description" value="<?= esc($m['description'] ?? '') ?>" placeholder="popis" style="width:12rem"></td><td><input name="provider" value="<?= esc($m['provider']) ?>" required style="width:8rem"><br><select name="service_type"><option value="online_card" <?= ($m['service_type'] ?? '')==='online_card'?'selected':'' ?>>online_card</option><option value="bank_transfer" <?= ($m['service_type'] ?? '')==='bank_transfer'?'selected':'' ?>>bank_transfer</option><option value="cod" <?= ($m['service_type'] ?? '')==='cod'?'selected':'' ?>>cod</option><option value="offline" <?= ($m['service_type'] ?? '')==='offline'?'selected':'' ?>>offline</option></select></td><td><input type="number" name="min_amount" value="<?= (int)$m['min_amount'] ?>" style="width:6rem"><br><input type="number" name="max_amount" value="<?= esc($m['max_amount'] ?? '') ?>" placeholder="max" style="width:6rem"></td><td><input type="number" name="fixed_fee" value="<?= (int)$m['fixed_fee'] ?>" style="width:5rem"><br><input type="number" name="percent_fee_bps" value="<?= (int)$m['percent_fee_bps'] ?>" style="width:5rem"> bps<br><input type="number" name="free_above" value="<?= esc($m['free_above'] ?? '') ?>" placeholder="free above" style="width:6rem"></td><td><input type="number" name="sort_order" value="<?= (int)$m['sort_order'] ?>" style="width:4rem"><br><label><input type="checkbox" name="requires_online" value="1" <?= !empty($m['requires_online'])?'checked':'' ?>> online</label><br><label><input type="checkbox" name="active" value="1" <?= !empty($m['active'])?'checked':'' ?>> aktivna</label></td><td><button class="btn btn-sm btn-primary">Ulozit</button></form> <form method="post" action="admin.php?p=payments&action=method_delete" style="display:inline" data-confirm="Zmazat platobnu metodu?"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-sm btn-danger">Zmazat</button></form></td></tr>
            <?php endforeach; ?>
            <tr><form method="post" action="admin.php?p=payments&action=method_save"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><td><input name="code" required placeholder="card"></td><td><input name="name" required placeholder="Nazov"><br><input name="description" placeholder="popis"></td><td><input name="provider" required placeholder="stripe"><br><select name="service_type"><option value="online_card">online_card</option><option value="bank_transfer">bank_transfer</option><option value="cod">cod</option><option value="offline">offline</option></select></td><td><input type="number" name="min_amount" value="0" style="width:6rem"><br><input type="number" name="max_amount" placeholder="max" style="width:6rem"></td><td><input type="number" name="fixed_fee" value="0" style="width:5rem"><br><input type="number" name="percent_fee_bps" value="0" style="width:5rem"> bps<br><input type="number" name="free_above" placeholder="free above" style="width:6rem"></td><td><input type="number" name="sort_order" value="100" style="width:4rem"><br><label><input type="checkbox" name="requires_online" value="1"> online</label><br><label><input type="checkbox" name="active" value="1" checked> aktivna</label></td><td><button class="btn btn-sm btn-primary">Pridat</button></td></form></tr>
            </tbody>
        </table>
    </div>
    <div class="card">
        <h3>Krajiny a doprava</h3>
        <div class="grid" style="grid-template-columns:1fr 1fr">
            <div><h4>Krajiny</h4><table><thead><tr><th>Metoda</th><th>Krajina</th><th></th></tr></thead><tbody>
            <?php foreach ($countries as $c): ?><tr><td><?= esc($c['method_code']) ?></td><td><?= esc($c['country']) ?></td><td><form method="post" action="admin.php?p=payments&action=country_delete" style="display:inline"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sm btn-danger">Zmazat</button></form></td></tr><?php endforeach; ?>
            <tr><form method="post" action="admin.php?p=payments&action=country_save"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><td><select name="method_code"><?php foreach ($methods as $m): ?><option value="<?= esc($m['code']) ?>"><?= esc($m['code']) ?></option><?php endforeach; ?></select></td><td><input name="country" maxlength="2" required placeholder="SK" style="width:4rem"></td><td><button class="btn btn-sm btn-primary">Pridat</button></td></form></tr>
            </tbody></table></div>
            <div><h4>Obmedzenia na dopravu</h4><p class="muted">Ak metoda nema ziadne obmedzenie, je dostupna pre kazdu dopravu.</p><table><thead><tr><th>Metoda</th><th>Doprava</th><th></th></tr></thead><tbody>
            <?php foreach ($shippingLinks as $s): ?><tr><td><?= esc($s['method_code']) ?></td><td><?= esc($s['shipping_method_code']) ?> <?= $s['shipping_name'] ? '(' . esc($s['shipping_name']) . ')' : '' ?></td><td><form method="post" action="admin.php?p=payments&action=shipping_delete" style="display:inline"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm btn-danger">Zmazat</button></form></td></tr><?php endforeach; ?>
            <tr><form method="post" action="admin.php?p=payments&action=shipping_save"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><td><select name="method_code"><?php foreach ($methods as $m): ?><option value="<?= esc($m['code']) ?>"><?= esc($m['code']) ?></option><?php endforeach; ?></select></td><td><select name="shipping_method_code"><?php foreach ($shippingMethods as $sm): ?><option value="<?= esc($sm['code']) ?>"><?= esc($sm['code']) ?> — <?= esc($sm['name']) ?></option><?php endforeach; ?></select></td><td><button class="btn btn-sm btn-primary">Pridat</button></td></form></tr>
            </tbody></table></div>
        </div>
    </div>
    <?php
}
function handlePaymentMethodSave(): void {
    $db = Db::get(); $id = Security::int($_POST['id'] ?? 0); $code = Security::slug($_POST['code'] ?? ''); $provider = Security::slug($_POST['provider'] ?? ''); $name = trim($_POST['name'] ?? '');
    $serviceType = in_array($_POST['service_type'] ?? '', ['online_card', 'bank_transfer', 'cod', 'offline'], true) ? $_POST['service_type'] : 'offline';
    $min = max(0, Security::int($_POST['min_amount'] ?? 0)); $max = paymentNullableInt('max_amount');
    if (!$code || !$provider || !$name || ($max !== null && $max < $min)) { setFlash('err', 'Kod, provider, nazov a platne limity su povinne.'); header('Location: admin.php?p=payments'); exit; }
    $data = [':code'=>$code, ':provider'=>$provider, ':name'=>$name, ':description'=>trim($_POST['description'] ?? ''), ':service_type'=>$serviceType, ':min_amount'=>$min, ':max_amount'=>$max, ':fixed_fee'=>max(0, Security::int($_POST['fixed_fee'] ?? 0)), ':percent_fee_bps'=>max(0, Security::int($_POST['percent_fee_bps'] ?? 0)), ':free_above'=>paymentNullableInt('free_above'), ':requires_online'=>(int)!empty($_POST['requires_online']), ':active'=>(int)!empty($_POST['active']), ':sort_order'=>Security::int($_POST['sort_order'] ?? 100)];
    if ($id) {
        $stmt = $db->prepare("SELECT code FROM payment_methods WHERE id = :id");
        $stmt->execute([':id'=>$id]);
        $oldCode = (string)$stmt->fetchColumn();
        $data[':id']=$id; $db->prepare("UPDATE payment_methods SET code=:code, provider=:provider, name=:name, description=:description, service_type=:service_type, min_amount=:min_amount, max_amount=:max_amount, fixed_fee=:fixed_fee, percent_fee_bps=:percent_fee_bps, free_above=:free_above, requires_online=:requires_online, active=:active, sort_order=:sort_order, updated_at=" . Db::now() . " WHERE id=:id")->execute($data);
        PaymentService::renameMethodCodeLinks($oldCode, $code);
    }
    else { $db->prepare("INSERT INTO payment_methods (code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order) VALUES (:code, :provider, :name, :description, :service_type, :min_amount, :max_amount, :fixed_fee, :percent_fee_bps, :free_above, :requires_online, :active, :sort_order)")->execute($data); }
    setFlash('ok', 'Platobna metoda ulozena.'); header('Location: admin.php?p=payments'); exit;
}
function handlePaymentMethodDelete(): void { $id = Security::int($_POST['id'] ?? 0); if ($id) { Db::get()->prepare("DELETE FROM payment_methods WHERE id = :id")->execute([':id'=>$id]); setFlash('ok', 'Platobna metoda zmazana.'); } header('Location: admin.php?p=payments'); exit; }
function handlePaymentCountrySave(): void {
    $methodCode = Security::slug($_POST['method_code'] ?? ''); $country = strtoupper(trim($_POST['country'] ?? ''));
    if (!$methodCode || !preg_match('/^[A-Z]{2}$/', $country)) { setFlash('err', 'Metoda a ISO krajina su povinne.'); header('Location: admin.php?p=payments'); exit; }
    Db::get()->prepare(Db::insertIgnore() . " INTO payment_method_countries (method_code, country) VALUES (:method, :country)")->execute([':method'=>$methodCode, ':country'=>$country]);
    setFlash('ok', 'Krajina pridana k platbe.'); header('Location: admin.php?p=payments'); exit;
}
function handlePaymentCountryDelete(): void { $id = Security::int($_POST['id'] ?? 0); if ($id) { Db::get()->prepare("DELETE FROM payment_method_countries WHERE id = :id")->execute([':id'=>$id]); setFlash('ok', 'Krajina zmazana.'); } header('Location: admin.php?p=payments'); exit; }
function handlePaymentShippingSave(): void {
    $methodCode = Security::slug($_POST['method_code'] ?? ''); $shippingCode = Security::slug($_POST['shipping_method_code'] ?? '');
    if (!$methodCode || !$shippingCode) { setFlash('err', 'Metoda a doprava su povinne.'); header('Location: admin.php?p=payments'); exit; }
    Db::get()->prepare(Db::insertIgnore() . " INTO payment_method_shipping (method_code, shipping_method_code) VALUES (:method, :shipping)")->execute([':method'=>$methodCode, ':shipping'=>$shippingCode]);
    setFlash('ok', 'Obmedzenie dopravy pridane.'); header('Location: admin.php?p=payments'); exit;
}
function handlePaymentShippingDelete(): void { $id = Security::int($_POST['id'] ?? 0); if ($id) { Db::get()->prepare("DELETE FROM payment_method_shipping WHERE id = :id")->execute([':id'=>$id]); setFlash('ok', 'Obmedzenie dopravy zmazane.'); } header('Location: admin.php?p=payments'); exit; }
function paymentNullableInt(string $key): ?int { $v = trim((string)($_POST[$key] ?? '')); return $v === '' ? null : max(0, Security::int($v)); }
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
