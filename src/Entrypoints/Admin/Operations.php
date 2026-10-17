<?php
/** Dashboard, import, cache, warehouse, feed, shipping and payment operations. */

function pageDashboard(): void {
    $db = Db::get();

    // Today's orders since the shop's local midnight (created_at is UTC).
    $storeId = StoreContext::current()->id();
    $stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE store_id=:store AND created_at >= :today AND is_synthetic = 0");
    $stmt->execute([':store' => $storeId, ':today' => SalesReport::sinceUtc(1)]);
    $ordersToday = (int)$stmt->fetchColumn();

    // Pending orders
    $stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE store_id=:store AND status='pending'");
    $stmt->execute([':store' => $storeId]);
    $pendingOrders = (int)$stmt->fetchColumn();

    // Same thresholds and warehouse availability as the merchant stock e-mails; physical products only.
    $lowStockSummary = StockAlerts::summary();
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
        <div class="card stat"><div class="num"><?= adminRevenueToday() ?></div><div class="label"><?= esc(I18n::t('admin.stat.revenue_today')) ?></div></div>
        <div class="card stat"><div class="num"><?= $pendingOrders ?></div><div class="label"><?= esc(I18n::t('admin.stat.pending')) ?></div></div>
        <div class="card stat"><div class="num"><?= $lowStock ?></div><div class="label"><?= esc(I18n::t('admin.stat.low_stock')) ?></div></div>
    </div>

    <div class="card">
        <h3><?= esc(I18n::t('admin.dashboard.statistics')) ?></h3>
        <p><?= esc(I18n::t('admin.dashboard.total_products')) ?>: <strong><?= $totalProducts ?></strong></p>
        <p><?= esc(I18n::t('admin.dashboard.jobs_queue')) ?> (<?= esc($operations['queue_backend']) ?>): <strong><?= $jobStats['pending'] ?></strong> <?= esc(I18n::t('admin.dashboard.jobs_pending')) ?>, <strong><?= $jobStats['failed'] ?></strong> <?= esc(I18n::t('admin.dashboard.jobs_failed')) ?></p>
        <p class="muted"><?= esc(I18n::t('admin.common.correlation')) ?>: <?= esc($operations['correlation_id']) ?> · <?= esc(I18n::t('admin.common.store')) ?>: <?= esc($operations['store_id']) ?></p>
    </div>

    <div class="card">
        <h3><?= esc(I18n::t('admin.ops.operations')) ?></h3>
        <?php $monitor = $operations['incident_monitor']; ?>
        <p><?= esc(I18n::t($monitor['enabled'] ? 'admin.ops.monitoring_enabled' : 'admin.ops.monitoring_disabled')) ?> · <?= esc(I18n::t($monitor['available'] ? 'admin.ops.evidence_available' : 'admin.ops.evidence_unavailable')) ?></p>
        <p><?= esc(I18n::t('admin.ops.worker_cron')) ?>: <?= esc($monitor['worker_cron']) ?> · <?= esc(I18n::t('admin.ops.monitor_cron')) ?>: <?= esc($monitor['monitor_cron']) ?></p>
        <div class="table-scroll"><table><tr><th><?= esc(I18n::t('admin.ops.probe')) ?></th><th><?= esc(I18n::t('admin.ops.observed_status')) ?></th><th><?= esc(I18n::t('admin.ops.observed_at')) ?></th></tr>
        <?php foreach ($monitor['probes'] as $probe): ?><tr><td><?= esc($probe['probe']) ?></td><td><?= esc($probe['status']) ?><?= $probe['stale'] ? ' ' . esc(I18n::t('admin.ops.stale')) : '' ?></td><td><?= esc(gmdate('c', (int)$probe['checked_at'])) ?></td></tr><?php endforeach; ?>
        </table></div>
        <div class="table-scroll"><table><tr><th><?= esc(I18n::t('admin.common.code')) ?></th><th><?= esc(I18n::t('admin.ops.phase')) ?></th><th><?= esc(I18n::t('admin.ops.severity')) ?></th><th><?= esc(I18n::t('admin.common.correlation')) ?></th><th><?= esc(I18n::t('admin.ops.time_utc')) ?></th></tr>
        <?php foreach ($monitor['incidents'] as $incident): ?><tr><td><?= esc($incident['code']) ?></td><td><?= esc($incident['phase']) ?></td><td><?= esc($incident['severity']) ?></td><td><code><?= esc($incident['correlation_id']) ?></code></td><td><?= esc(gmdate('c', (int)$incident['occurred_at'])) ?></td></tr><?php endforeach; ?>
        </table></div>
        <div class="table-scroll"><table><tr><th><?= esc(I18n::t('admin.common.code')) ?></th><th><?= esc(I18n::t('admin.ops.count')) ?></th><th><?= esc(I18n::t('admin.ops.notification')) ?></th><th><?= esc(I18n::t('admin.ops.last_acceptance')) ?></th></tr>
        <?php foreach ($monitor['notifications'] as $notification): ?><tr><td><?= esc($notification['code']) ?></td><td><?= (int)$notification['occurrences'] ?></td><td><?= esc($notification['notification_status']) ?></td><td><?= $notification['last_notification_at'] ? esc(gmdate('c', (int)$notification['last_notification_at'])) : esc(I18n::t('admin.ops.not_observed')) ?></td></tr><?php endforeach; ?>
        </table></div>
        <div class="grid grid-4">
            <div class="stat"><div class="num"><?= (int)$operations['counts']['failed_jobs'] ?></div><div class="label"><?= esc(I18n::t('admin.ops.failed_jobs')) ?></div></div>
            <div class="stat"><div class="num"><?= (int)$operations['counts']['failed_mail'] ?></div><div class="label"><?= esc(I18n::t('admin.ops.failed_mail')) ?></div></div>
            <div class="stat"><div class="num"><?= (int)$operations['counts']['payment_incidents'] ?></div><div class="label"><?= esc(I18n::t('admin.ops.payment_incidents')) ?></div></div>
            <div class="stat"><div class="num"><?= (int)$operations['counts']['webhook_incidents'] ?></div><div class="label"><?= esc(I18n::t('admin.ops.webhook_incidents')) ?></div></div>
        </div>
        <?php if ($operations['failed_jobs']): ?>
        <h4><?= esc(I18n::t('admin.ops.failed_jobs')) ?></h4><div class="table-scroll"><table><tr><th><?= esc(I18n::t('admin.common.type')) ?></th><th><?= esc(I18n::t('admin.common.attempts')) ?></th><th><?= esc(I18n::t('admin.common.error')) ?></th><th><?= esc(I18n::t('admin.common.correlation')) ?></th><th><?= esc(I18n::t('admin.common.updated')) ?></th></tr>
            <?php foreach ($operations['failed_jobs'] as $incident): ?><tr><td><?= esc($incident['type']) ?></td><td><?= (int)$incident['attempts'] ?></td><td><?= esc($incident['last_error']) ?></td><td><code><?= esc($incident['correlation_id']) ?></code></td><td><?= esc($incident['updated_at']) ?></td></tr><?php endforeach; ?>
        </table></div><?php endif; ?>
        <?php if ($operations['failed_mail']): ?>
        <h4><?= esc(I18n::t('admin.ops.failed_mail')) ?></h4><div class="table-scroll"><table><tr><th><?= esc(I18n::t('admin.common.template')) ?></th><th><?= esc(I18n::t('admin.common.recipient')) ?></th><th><?= esc(I18n::t('admin.common.error')) ?></th><th><?= esc(I18n::t('admin.common.correlation')) ?></th><th><?= esc(I18n::t('admin.common.created')) ?></th></tr>
            <?php foreach ($operations['failed_mail'] as $incident): ?><tr><td><?= esc($incident['template']) ?></td><td><?= esc($incident['recipient']) ?></td><td><?= esc($incident['error']) ?></td><td><code><?= esc($incident['correlation_id'] ?? '') ?></code></td><td><?= esc($incident['created_at']) ?></td></tr><?php endforeach; ?>
        </table></div><?php endif; ?>
        <?php if ($operations['payment_incidents']): ?>
        <h4><?= esc(I18n::t('admin.ops.payment_incidents')) ?></h4><div class="table-scroll"><table><tr><th><?= esc(I18n::t('admin.order.label')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.common.provider')) ?></th><th><?= esc(I18n::t('admin.common.correlation')) ?></th><th><?= esc(I18n::t('admin.common.created')) ?></th></tr>
            <?php foreach ($operations['payment_incidents'] as $incident): ?><tr><td><a href="admin.php?p=order&id=<?= (int)$incident['id'] ?>"><?= esc($incident['order_number']) ?></a></td><td><?= esc($incident['payment_status']) ?></td><td><?= esc($incident['payment_method']) ?></td><td><code><?= esc($incident['correlation_id'] ?? '') ?></code></td><td><?= esc($incident['created_at']) ?></td></tr><?php endforeach; ?>
        </table></div><?php endif; ?>
        <?php if ($operations['webhook_incidents']): ?>
        <h4><?= esc(I18n::t('admin.ops.webhook_incidents')) ?></h4><div class="table-scroll"><table><tr><th><?= esc(I18n::t('admin.common.event')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.common.attempts')) ?></th><th><?= esc(I18n::t('admin.common.error')) ?></th><th><?= esc(I18n::t('admin.common.correlation')) ?></th></tr>
            <?php foreach ($operations['webhook_incidents'] as $incident): ?><tr><td><?= esc($incident['event_type']) ?></td><td><?= esc($incident['status']) ?></td><td><?= (int)$incident['attempts'] ?></td><td><?= esc($incident['last_error'] ?? '') ?></td><td><code><?= esc($incident['correlation_id'] ?? '') ?></code></td></tr><?php endforeach; ?>
        </table></div><?php endif; ?>
    </div>

    <?php if ($lowStock > 0): ?>
    <div class="card">
        <h3><?= esc(I18n::t('admin.dashboard.low_stock_alert')) ?> <a class="btn btn-sm" href="admin.php?p=products&amp;stock=low"><?= esc(I18n::t('admin.products.filter_low_stock')) ?></a></h3>
        <table>
            <tr><th><?= esc(I18n::t('admin.th.sku')) ?></th><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.th.stock')) ?></th><th><?= esc(I18n::t('admin.dashboard.stock_threshold')) ?></th></tr>
            <?php foreach ($lowStockSummary['products'] as $row): ?>
            <tr>
                <td><?= esc($row['sku']) ?></td>
                <td><a href="admin.php?p=product&amp;id=<?= (int)$row['product_id'] ?>"><?= esc($row['name']) ?></a></td>
                <td class="stock-<?= $row['level'] === StockAlerts::LOW ? 'low' : 'critical' ?>"><?= (int)$row['available'] ?> <span class="muted">(<?= esc(I18n::t('admin.stock_level.' . $row['level'])) ?>)</span></td>
                <td><?= (int)$row['low'] ?> / <?= (int)$row['critical'] ?></td>
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
            $stmt = $db->prepare("SELECT * FROM orders WHERE store_id = :store ORDER BY created_at DESC, id DESC LIMIT 10");
            $stmt->execute([':store' => StoreContext::current()->id()]);
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
    <h1><?= esc(I18n::t('admin.nav.import_csv')) ?></h1>
    <div class="card">
        <?= AdminForm::render('admin-import', 'admin.php?p=import&action=upload') ?>
    </div>
    <div class="card">
        <h3><?= esc(I18n::t('admin.import.templates')) ?></h3>
        <p class="muted"><?= esc(I18n::t('admin.import.sample_hint')) ?> <code>import/test-products.csv</code></p>
    </div>
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
    <h1><?= esc(I18n::t('admin.cache.title')) ?></h1>
    <div class="grid grid-4">
        <div class="card stat"><div class="num"><?= $stats['files'] ?></div><div class="label"><?= esc(I18n::t('admin.cache.files')) ?></div></div>
        <div class="card stat"><div class="num"><?= round($stats['size']/1024, 1) ?>K</div><div class="label"><?= esc(I18n::t('admin.cache.size')) ?></div></div>
        <div class="card stat"><div class="num"><?= $jobStats['pending'] ?></div><div class="label"><?= esc(I18n::t('admin.cache.jobs_pending')) ?></div></div>
        <div class="card stat"><div class="num"><?= $jobStats['failed'] ?></div><div class="label"><?= esc(I18n::t('admin.ops.failed_jobs')) ?></div></div>
    </div>
    <div class="card">
        <h3><?= esc(I18n::t('admin.th.actions')) ?></h3>
        <form method="post" action="admin.php?p=cache&action=flush" style="display:inline" data-confirm="<?= esc(I18n::t('admin.cache.flush_confirm')) ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <button class="btn btn-danger" type="submit"><?= esc(I18n::t('admin.cache.flush')) ?></button>
        </form>
        <form method="post" action="admin.php?p=cache&action=rebuild" style="display:inline;margin-left:.5rem">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.cache.rebuild')) ?></button>
        </form>
    </div>
    <?php
}
function pageAudit(): void {
    $db = Db::get();
    $page = max(1, Security::int($_GET['page'] ?? 1));
    $perPage = 50;
    $offset = ($page - 1) * $perPage;

    $store = StoreContext::current()->id();
    $count = $db->prepare("SELECT COUNT(*) FROM audit_log WHERE store_id = :store");
    $count->execute([':store' => $store]);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));

    $stmt = $db->prepare("SELECT * FROM audit_log WHERE store_id = :store ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset");
    $stmt->execute([':store' => $store, ':limit' => $perPage, ':offset' => $offset]);
    $logs = $stmt->fetchAll();
    ?>
    <h1><?= esc(I18n::t('admin.title.audit_log')) ?> <span class="muted">(<?= $total ?>)</span></h1>
    <div class="card">
        <table>
            <tr><th><?= esc(I18n::t('admin.audit.entity')) ?></th><th>ID</th><th><?= esc(I18n::t('admin.common.action')) ?></th><th><?= esc(I18n::t('admin.audit.old')) ?></th><th><?= esc(I18n::t('admin.audit.new')) ?></th><th><?= esc(I18n::t('admin.common.note')) ?></th><th><?= esc(I18n::t('admin.common.who')) ?></th><th><?= esc(I18n::t('admin.common.when')) ?></th></tr>
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
    // BBF checks that a file came, its .csv extension, size and content type.
    $form = AdminForm::validate('admin-import', [], ['allowed_extensions' => ['csv'], 'max_file_size' => Import::MAX_FILE_BYTES]);
    if ($form === null) {
        setFlash('err', I18n::t('admin.import.rejected') . ' ' . AdminForm::firstError());
        header("Location: admin.php?p=import");
        exit;
    }

    $result = Import::csv($form['csv_file']['files'][0]['tmp_name']);

    $errCount = count($result['errors']);
    if (($result['imported'] ?? 0) > 0) {
        AuditLog::log('import', 0, null, [
            'imported' => $result['imported'],
            'errors' => $errCount,
            'complete' => (bool)$result['success'],
        ], 'CSV import');
    }
    if ($result['success'] && $errCount === 0) {
        setFlash('ok', strtr(I18n::t('admin.import.done'), ['{count}' => (string)$result['imported']]));
    } else {
        setFlash('err', strtr(I18n::t('admin.import.partial'), ['{imported}' => (string)$result['imported'], '{errors}' => (string)$errCount]));
    }

    header("Location: admin.php?p=import");
    exit;
}
function handleCacheFlush(): void {
    Cache::flush();
    AuditLog::log('cache', 0, null, 'flush', 'Cache flushed');
    setFlash('ok', I18n::t('admin.cache.flushed'));
    header("Location: admin.php?p=cache");
    exit;
}
function handleCacheRebuild(): void {
    JobQueue::push('cache_rebuild', ['scope' => 'full'], 1);
    setFlash('ok', I18n::t('admin.cache.rebuild_queued'));
    header("Location: admin.php?p=cache");
    exit;
}

// ============================================================
// Attributes CRUD
// ============================================================
function pageWarehouses(): void {
    $warehouses = Warehouse::all();
    ?>
    <h1><?= esc(I18n::t('admin.title.warehouses')) ?></h1>
    <p style="margin-bottom:1rem"><a href="admin.php?p=warehouse" class="btn btn-primary"><?= esc(I18n::t('admin.title.warehouse_new')) ?></a></p>
    <div class="card">
        <table>
            <thead><tr><th><?= esc(I18n::t('admin.common.code')) ?></th><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.common.priority')) ?></th><th><?= esc(I18n::t('admin.warehouse.dropship')) ?></th><th><?= esc(I18n::t('admin.warehouse.default')) ?></th><th><?= esc(I18n::t('admin.nav.feeds')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($warehouses as $w):
                $feedCount = count(Warehouse::feedsByWarehouse((int)$w['id']));
            ?>
            <tr>
                <td><code><?= esc($w['code']) ?></code></td>
                <td><a href="admin.php?p=warehouse&id=<?= $w['id'] ?>"><?= esc($w['name']) ?></a></td>
                <td><?= (int)$w['priority'] ?></td>
                <td><?= $w['is_dropship'] ? esc(I18n::t('admin.common.yes')) : '—' ?></td>
                <td><?= $w['is_default'] ? esc(I18n::t('admin.common.yes')) : '—' ?></td>
                <td><?= $feedCount ?></td>
                <td>
                    <form method="post" action="admin.php?p=warehouses&action=delete" style="display:inline" data-confirm="<?= esc(I18n::t('admin.warehouse.delete_confirm')) ?>">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="warehouse_id" value="<?= $w['id'] ?>">
                        <button class="btn btn-sm btn-danger"><?= esc(I18n::t('admin.btn.delete')) ?></button>
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
    <h1><?= $w ? esc(strtr(I18n::t('admin.common.edit_named'), ['{name}' => $w['name']])) : esc(I18n::t('admin.title.warehouse_new')) ?></h1>
    <div class="card">
        <form method="post" action="admin.php?p=warehouse&action=save<?= $w ? '&id='.$w['id'] : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="form-row"><label><?= esc(I18n::t('admin.common.code_unique')) ?></label><input name="code" value="<?= esc($w['code'] ?? '') ?>" required placeholder="main"></div>
            <div class="form-row"><label><?= esc(I18n::t('admin.th.name')) ?></label><input name="name" value="<?= esc($w['name'] ?? '') ?>" required style="width:100%"></div>
            <div class="form-row"><label><?= esc(I18n::t('admin.warehouse.priority_hint')) ?></label><input type="number" name="priority" value="<?= (int)($w['priority'] ?? 0) ?>"></div>
            <div class="form-row"><label><?= esc(I18n::t('admin.common.type')) ?></label>
                <select name="is_dropship">
                    <option value="0" <?= empty($w['is_dropship']) ? 'selected' : '' ?>><?= esc(I18n::t('admin.warehouse.own')) ?></option>
                    <option value="1" <?= !empty($w['is_dropship']) ? 'selected' : '' ?>><?= esc(I18n::t('admin.warehouse.dropship_external')) ?></option>
                </select>
            </div>
            <div class="form-row"><label><input type="checkbox" name="is_default" value="1" <?= !empty($w['is_default']) ? 'checked' : '' ?>> <?= esc(I18n::t('admin.warehouse.is_default')) ?></label></div>
            <div class="form-row"><label><?= esc(I18n::t('admin.warehouse.contact')) ?></label><input name="contact" value="<?= esc($w['contact'] ?? '') ?>" style="width:100%"></div>
            <div class="form-row"><label><?= esc(I18n::t('admin.warehouse.notes')) ?></label><textarea name="notes" rows="3" style="width:100%"><?= esc($w['notes'] ?? '') ?></textarea></div>
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.btn.save')) ?></button>
            <a href="admin.php?p=warehouses" class="btn" style="margin-left:.5rem"><?= esc(I18n::t('admin.btn.back')) ?></a>
        </form>
    </div>
    <?php if ($w): ?>
    <div class="card" style="margin-top:1rem">
        <h3><?= esc(I18n::t('admin.warehouse.feeds_for')) ?></h3>
        <?php $feeds = Warehouse::feedsByWarehouse($id); ?>
        <?php if (empty($feeds)): ?>
            <p class="muted"><?= esc(I18n::t('admin.warehouse.no_feeds')) ?> <a href="admin.php?p=feed&warehouse_id=<?= $id ?>"><?= esc(I18n::t('admin.warehouse.add_feed')) ?></a></p>
        <?php else: ?>
            <table>
                <thead><tr><th><?= esc(I18n::t('admin.common.code')) ?></th><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.common.format')) ?></th><th><?= esc(I18n::t('admin.feed.last_sync')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($feeds as $f): ?>
                <tr>
                    <td><a href="admin.php?p=feed&id=<?= $f['id'] ?>"><?= esc($f['code']) ?></a></td>
                    <td><?= esc($f['name']) ?></td>
                    <td><?= esc($f['format']) ?></td>
                    <td><?= $f['last_sync'] ? esc(substr($f['last_sync'], 0, 16)) : '<span class="muted">' . esc(I18n::t('admin.common.never')) . '</span>' ?></td>
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
        'name' => trim(Security::str($_POST['name'] ?? '')),
        'priority' => Security::int($_POST['priority'] ?? 0),
        'is_dropship' => (int)!empty($_POST['is_dropship']),
        'is_default' => (int)!empty($_POST['is_default']),
        'contact' => trim(Security::str($_POST['contact'] ?? '')),
        'notes' => trim(Security::str($_POST['notes'] ?? '')),
    ];
    $old = $id ? Warehouse::byId($id) : null;
    if (!$data['code'] || !$data['name'] || ($id && !$old)) {
        setFlash('err', I18n::t($id && !$old ? 'admin.common.record_missing' : 'admin.common.code_name_required'));
        header("Location: admin.php?p=warehouse" . ($id ? "&id={$id}" : ''));
        exit;
    }
    $id = Warehouse::save($data);
    AuditLog::log('warehouse', $id, $old, Warehouse::byId($id), $old ? 'Admin warehouse edit' : 'Admin warehouse create');
    setFlash('ok', I18n::t('admin.warehouse.saved'));
    header("Location: admin.php?p=warehouse&id={$id}");
    exit;
}
function handleWarehouseDelete(): void {
    $id = Security::int($_POST['warehouse_id'] ?? 0);
    $old = $id ? Warehouse::byId($id) : null;
    if ($old) {
        $old['feeds'] = Warehouse::feedsByWarehouse($id);
        Warehouse::delete($id);
        AuditLog::log('warehouse', $id, $old, null, 'Admin warehouse delete');
        setFlash('ok', I18n::t('admin.warehouse.deleted'));
    } elseif ($id) {
        setFlash('err', I18n::t('admin.common.record_missing'));
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
    <h1><?= esc(I18n::t('admin.title.feeds')) ?></h1>
    <p style="margin-bottom:1rem"><a href="admin.php?p=feed" class="btn btn-primary"><?= esc(I18n::t('admin.title.feed_new')) ?></a></p>
    <div class="card">
        <table>
            <thead><tr><th><?= esc(I18n::t('admin.common.code')) ?></th><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.feed.warehouse')) ?></th><th><?= esc(I18n::t('admin.common.format')) ?></th><th><?= esc(I18n::t('admin.feed.last_sync')) ?></th><th><?= esc(I18n::t('admin.nav.products')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($feeds as $f): ?>
            <tr>
                <td><a href="admin.php?p=feed&id=<?= $f['id'] ?>"><?= esc($f['code']) ?></a></td>
                <td><?= esc($f['name']) ?></td>
                <td><?= esc($f['warehouse_name']) ?></td>
                <td><?= esc($f['format']) ?></td>
                <td><?= $f['last_sync'] ? esc(substr($f['last_sync'], 0, 16)) : '<span class="muted">' . esc(I18n::t('admin.common.never')) . '</span>' ?></td>
                <td><?= (int)$f['last_count'] ?></td>
                <td><?= $f['enabled'] ? esc($f['last_status'] ?? '—') : '<span class="muted">' . esc(I18n::t('admin.feed.disabled')) . '</span>' ?></td>
                <td>
                    <form method="post" action="admin.php?p=feeds&action=sync" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="feed_id" value="<?= $f['id'] ?>">
                        <button class="btn btn-sm"><?= esc(I18n::t('admin.feed.sync_now')) ?></button>
                    </form>
                    <form method="post" action="admin.php?p=feeds&action=delete" style="display:inline" data-confirm="<?= esc(I18n::t('admin.feed.delete_confirm')) ?>">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="feed_id" value="<?= $f['id'] ?>">
                        <button class="btn btn-sm btn-danger"><?= esc(I18n::t('admin.btn.delete')) ?></button>
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
    <h1><?= $f ? esc(strtr(I18n::t('admin.common.edit_named'), ['{name}' => $f['name']])) : esc(I18n::t('admin.title.feed_new')) ?></h1>
    <div class="card">
        <form method="post" action="admin.php?p=feed&action=save<?= $f ? '&id='.$f['id'] : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="form-row"><label><?= esc(I18n::t('admin.common.code_unique')) ?></label><input name="code" value="<?= esc($f['code'] ?? '') ?>" required placeholder="supplier-xml"></div>
            <div class="form-row"><label><?= esc(I18n::t('admin.th.name')) ?></label><input name="name" value="<?= esc($f['name'] ?? '') ?>" required style="width:100%"></div>
            <div class="form-row"><label><?= esc(I18n::t('admin.feed.warehouse')) ?></label>
                <select name="warehouse_id" required>
                    <option value=""><?= esc(I18n::t('admin.feed.choose_warehouse')) ?></option>
                    <?php foreach ($warehouses as $w): ?>
                    <option value="<?= $w['id'] ?>" <?= $preselect == $w['id'] ? 'selected' : '' ?>><?= esc($w['name']) ?> (<?= esc($w['code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label><?= esc(I18n::t('admin.feed.url')) ?></label><input name="url" value="<?= esc($f['url'] ?? '') ?>" required style="width:100%" placeholder="https://..."></div>
            <div class="form-row"><label><?= esc(I18n::t('admin.common.format')) ?></label>
                <select name="format">
                    <option value="xml" <?= ($f['format'] ?? 'xml') === 'xml' ? 'selected' : '' ?>>XML (SHOP/SHOPITEM)</option>
                    <option value="csv" <?= ($f['format'] ?? '') === 'csv' ? 'selected' : '' ?>>CSV</option>
                </select>
            </div>
            <div class="form-row"><label><?= esc(I18n::t('admin.feed.import_mode')) ?></label>
                <select name="import_mode">
                    <?php foreach ([FeedImport::MODE_UPSERT => 'admin.feed.mode_upsert', FeedImport::MODE_PRICE_STOCK => 'admin.feed.mode_price_stock', FeedImport::MODE_STOCK_ONLY => 'admin.feed.mode_stock'] as $mode => $label): ?>
                    <option value="<?= esc($mode) ?>" <?= ($f['import_mode'] ?? FeedImport::MODE_UPSERT) === $mode ? 'selected' : '' ?>><?= esc(I18n::t($label)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label><?= esc(I18n::t('admin.feed.field_map')) ?></label>
                <textarea name="field_map" rows="12" style="width:100%;font-family:monospace;font-size:.8rem"><?= esc($f['field_map'] ?? "{\n    \"ITEM_ID\": \"sku\",\n    \"PRODUCTNAME\": \"name\",\n    \"CATEGORY_TEXT\": {\"field\": \"category\", \"transform\": \"category_path\", \"separator\": \" | \"},\n    \"PRICE_VAT\": {\"field\": \"price\", \"transform\": \"price_to_cents\"},\n    \"STORE\": {\"field\": \"stock\", \"transform\": \"boolean_stock\", \"default_qty\": 10},\n    \"IMGURL\": \"image\",\n    \"IMGURL_ALTERNATIVE\": {\"field\": \"image\", \"append\": true},\n    \"DESCRIPTION\": \"description\",\n    \"DESCRIPTION_SHORT\": \"summary\",\n    \"WEIGHT\": {\"field\": \"weight_g\", \"transform\": \"weight_to_grams\"},\n    \"MANUFACTURER\": \"attr:manufacturer\",\n    \"EAN\": \"attr:ean\"\n}") ?></textarea>
            </div>
            <div class="form-row"><label><?= esc(I18n::t('admin.feed.schedule')) ?></label><input name="schedule" value="<?= esc($f['schedule'] ?? '0 */6 * * *') ?>" placeholder="0 */6 * * *"></div>
            <div class="form-row"><label><input type="checkbox" name="enabled" value="1" <?= ($f['enabled'] ?? 1) ? 'checked' : '' ?>> <?= esc(I18n::t('admin.common.active')) ?></label></div>
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.btn.save')) ?></button>
            <a href="admin.php?p=feeds" class="btn" style="margin-left:.5rem"><?= esc(I18n::t('admin.btn.back')) ?></a>
        </form>
    </div>
    <?php if ($f): ?>
    <div class="card" style="margin-top:1rem">
        <h3><?= esc(I18n::t('admin.feed.last_sync')) ?></h3>
        <p><?= esc(I18n::t('admin.feed.time')) ?>: <?= $f['last_sync'] ? esc($f['last_sync']) : esc(I18n::t('admin.common.never')) ?></p>
        <p><?= esc(I18n::t('admin.th.status')) ?>: <?= esc($f['last_status'] ?? '—') ?></p>
        <p><?= esc(I18n::t('admin.nav.products')) ?>: <?= (int)$f['last_count'] ?></p>
        <form method="post" action="admin.php?p=feeds&action=sync" style="margin-top:.5rem">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <input type="hidden" name="feed_id" value="<?= $f['id'] ?>">
            <button class="btn btn-primary"><?= esc(I18n::t('admin.feed.sync_now')) ?></button>
        </form>
    </div>
    <?php endif; ?>
    <?php
}
function handleFeedSave(): void {
    $id = Security::int($_GET['id'] ?? 0);

    // Validate JSON field_map
    $fieldMapRaw = trim(Security::str($_POST['field_map'] ?? '{}'));
    $fieldMap = json_decode($fieldMapRaw, true);
    if ($fieldMap === null && $fieldMapRaw !== '{}') {
        setFlash('err', I18n::t('admin.feed.invalid_json') . ' ' . json_last_error_msg());
        header("Location: admin.php?p=feed" . ($id ? "&id={$id}" : ''));
        exit;
    }

    $data = [
        'id' => $id,
        'code' => Security::slug($_POST['code'] ?? ''),
        'name' => trim(Security::str($_POST['name'] ?? '')),
        'warehouse_id' => Security::int($_POST['warehouse_id'] ?? 0),
        'url' => trim(Security::str($_POST['url'] ?? '')),
        'format' => in_array($_POST['format'] ?? '', ['xml', 'csv']) ? $_POST['format'] : 'xml',
        'field_map' => json_encode($fieldMap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        'import_mode' => in_array($_POST['import_mode'] ?? '', FeedImport::MODES, true) ? $_POST['import_mode'] : FeedImport::MODE_UPSERT,
        'schedule' => trim(Security::str($_POST['schedule'] ?? '0 */6 * * *')),
        'enabled' => (int)!empty($_POST['enabled']),
    ];

    $old = $id ? Warehouse::feedById($id) : null;
    $error = !$data['code'] || !$data['name'] || !$data['warehouse_id'] || !$data['url'] ? 'admin.feed.required'
        : ($id && !$old ? 'admin.common.record_missing' : (!Warehouse::byId($data['warehouse_id']) ? 'admin.feed.warehouse_foreign' : null));
    if ($error !== null) {
        setFlash('err', I18n::t($error));
        header("Location: admin.php?p=feed" . ($id ? "&id={$id}" : ''));
        exit;
    }

    try {
        $id = Warehouse::saveFeed($data);
    } catch (PDOException $e) {
        // feeds.code is unique across stores.
        if ((string)$e->getCode() !== '23000') throw $e;
        setFlash('err', I18n::t('admin.feed.code_taken'));
        header("Location: admin.php?p=feed" . ($id ? "&id={$id}" : ''));
        exit;
    }
    AuditLog::log('feed', $id, $old, Warehouse::feedById($id), $old ? 'Admin feed edit' : 'Admin feed create');
    setFlash('ok', I18n::t('admin.feed.saved'));
    header("Location: admin.php?p=feed&id={$id}");
    exit;
}
function handleFeedDelete(): void {
    $id = Security::int($_POST['feed_id'] ?? 0);
    $old = $id ? Warehouse::feedById($id) : null;
    if ($old && Warehouse::deleteFeed($id)) {
        AuditLog::log('feed', $id, $old, null, 'Admin feed delete');
        setFlash('ok', I18n::t('admin.feed.deleted'));
    } elseif ($id) {
        setFlash('err', I18n::t('admin.common.record_missing'));
    }
    header('Location: admin.php?p=feeds');
    exit;
}
function pageShipping(): void {
    $methods = StoreConfig::rows("SELECT * FROM shipping_methods WHERE store_id = :store ORDER BY sort_order, code");
    $zones = StoreConfig::rows("SELECT * FROM shipping_zones WHERE store_id = :store ORDER BY sort_order, code");
    $countries = StoreConfig::rows("SELECT * FROM shipping_zone_countries WHERE store_id = :store ORDER BY zone_code, country, postal_min");
    $rates = StoreConfig::rows("SELECT r.*, m.name AS method_name, z.name AS zone_name FROM shipping_rates r LEFT JOIN shipping_methods m ON m.code = r.method_code AND m.store_id = r.store_id LEFT JOIN shipping_zones z ON z.code = r.zone_code AND z.store_id = r.store_id WHERE r.store_id = :store ORDER BY COALESCE(r.sort_order, 100), r.method_code, r.country, r.weight_to_g LIMIT 200");
    ?>
    <h1><?= esc(I18n::t('admin.title.shipping')) ?></h1>
    <div class="card">
        <h3><?= esc(I18n::t('admin.shipping.methods')) ?></h3>
        <table>
            <thead><tr><th><?= esc(I18n::t('admin.common.code')) ?></th><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.shipping.carrier')) ?></th><th><?= esc(I18n::t('admin.common.type')) ?></th><th><?= esc(I18n::t('admin.common.limits')) ?></th><th><?= esc(I18n::t('admin.common.active')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($methods as $m): ?>
            <tr>
                <form method="post" action="admin.php?p=shipping&action=method_save">
                    <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                    <td><input name="code" value="<?= esc($m['code']) ?>" required style="width:7rem"></td>
                    <td><input name="name" value="<?= esc($m['name']) ?>" required style="width:12rem"><br><input name="description" value="<?= esc($m['description'] ?? '') ?>" placeholder="<?= esc(I18n::t('admin.common.description')) ?>" style="width:12rem"></td>
                    <td><input name="carrier" value="<?= esc($m['carrier']) ?>" required style="width:7rem"><br><input name="adapter_class" value="<?= esc($m['adapter_class'] ?? '') ?>" placeholder="adapter" style="width:7rem"></td>
                    <td><select name="service_type"><option value="parcel" <?= ($m['service_type'] ?? '')==='parcel'?'selected':'' ?>><?= esc(I18n::t('admin.shipping.parcel')) ?></option><option value="pickup" <?= ($m['service_type'] ?? '')==='pickup'?'selected':'' ?>><?= esc(I18n::t('admin.shipping.pickup')) ?></option></select><br><label><input type="checkbox" name="supports_cod" value="1" <?= !empty($m['supports_cod'])?'checked':'' ?>> <?= esc(I18n::t('admin.shipping.cod')) ?></label> <label><input type="checkbox" name="supports_pickup" value="1" <?= !empty($m['supports_pickup'])?'checked':'' ?>> <?= esc(I18n::t('admin.shipping.pickup')) ?></label></td>
                    <td><input type="number" name="free_above" value="<?= esc($m['free_above'] ?? '') ?>" placeholder="<?= esc(I18n::t('admin.shipping.free_above')) ?>" style="width:6rem"><input type="number" name="free_from_qty" value="<?= esc($m['free_from_qty'] ?? '') ?>" placeholder="<?= esc(I18n::t('admin.shipping.free_from_qty')) ?>" title="<?= esc(I18n::t('admin.shipping.free_from_qty_hint')) ?>" style="width:6rem"><br><input type="number" name="max_weight_g" value="<?= esc($m['max_weight_g'] ?? '') ?>" placeholder="max g" style="width:6rem"><input type="number" name="max_volume_cm3" value="<?= esc($m['max_volume_cm3'] ?? '') ?>" placeholder="max cm3" style="width:6rem"></td>
                    <td><input type="number" name="sort_order" value="<?= (int)$m['sort_order'] ?>" style="width:4rem"><br><label><input type="checkbox" name="active" value="1" <?= !empty($m['active'])?'checked':'' ?>> <?= esc(I18n::t('admin.common.active')) ?></label></td>
                    <td><button class="btn btn-sm btn-primary"><?= esc(I18n::t('admin.btn.save')) ?></button>
                </form>
                <form method="post" action="admin.php?p=shipping&action=method_delete" style="display:inline" data-confirm="<?= esc(I18n::t('admin.shipping.method_delete_confirm')) ?>"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-sm btn-danger"><?= esc(I18n::t('admin.btn.delete')) ?></button></form></td>
            </tr>
            <?php endforeach; ?>
            <tr><form method="post" action="admin.php?p=shipping&action=method_save"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><td><input name="code" required placeholder="packeta"></td><td><input name="name" required placeholder="<?= esc(I18n::t('admin.th.name')) ?>"><br><input name="description" placeholder="<?= esc(I18n::t('admin.common.description')) ?>"></td><td><input name="carrier" required placeholder="packeta"><br><input name="adapter_class" placeholder="PacketaShipping"></td><td><select name="service_type"><option value="parcel"><?= esc(I18n::t('admin.shipping.parcel')) ?></option><option value="pickup"><?= esc(I18n::t('admin.shipping.pickup')) ?></option></select><br><label><input type="checkbox" name="supports_cod" value="1"> <?= esc(I18n::t('admin.shipping.cod')) ?></label> <label><input type="checkbox" name="supports_pickup" value="1"> <?= esc(I18n::t('admin.shipping.pickup')) ?></label></td><td><input type="number" name="free_above" placeholder="<?= esc(I18n::t('admin.shipping.free_above')) ?>" style="width:6rem"><input type="number" name="free_from_qty" placeholder="<?= esc(I18n::t('admin.shipping.free_from_qty')) ?>" title="<?= esc(I18n::t('admin.shipping.free_from_qty_hint')) ?>" style="width:6rem"><br><input type="number" name="max_weight_g" placeholder="max g" style="width:6rem"><input type="number" name="max_volume_cm3" placeholder="max cm3" style="width:6rem"></td><td><input type="number" name="sort_order" value="100" style="width:4rem"><br><label><input type="checkbox" name="active" value="1" checked> <?= esc(I18n::t('admin.common.active')) ?></label></td><td><button class="btn btn-sm btn-primary"><?= esc(I18n::t('admin.common.add')) ?></button></td></form></tr>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3><?= esc(I18n::t('admin.shipping.zones')) ?></h3>
        <table><thead><tr><th><?= esc(I18n::t('admin.common.code')) ?></th><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.common.description')) ?></th><th><?= esc(I18n::t('admin.common.active')) ?></th><th></th></tr></thead><tbody>
        <?php foreach ($zones as $z): ?>
        <tr><form method="post" action="admin.php?p=shipping&action=zone_save"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><input type="hidden" name="id" value="<?= (int)$z['id'] ?>"><td><input name="code" value="<?= esc($z['code']) ?>" required></td><td><input name="name" value="<?= esc($z['name']) ?>" required></td><td><input name="description" value="<?= esc($z['description'] ?? '') ?>" style="width:100%"></td><td><input type="number" name="sort_order" value="<?= (int)$z['sort_order'] ?>" style="width:4rem"><br><label><input type="checkbox" name="active" value="1" <?= !empty($z['active'])?'checked':'' ?>> <?= esc(I18n::t('admin.common.active')) ?></label></td><td><button class="btn btn-sm btn-primary"><?= esc(I18n::t('admin.btn.save')) ?></button></form> <form method="post" action="admin.php?p=shipping&action=zone_delete" style="display:inline" data-confirm="<?= esc(I18n::t('admin.shipping.zone_delete_confirm')) ?>"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><input type="hidden" name="id" value="<?= (int)$z['id'] ?>"><button class="btn btn-sm btn-danger"><?= esc(I18n::t('admin.btn.delete')) ?></button></form></td></tr>
        <?php endforeach; ?>
        <tr><form method="post" action="admin.php?p=shipping&action=zone_save"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><td><input name="code" required placeholder="sk"></td><td><input name="name" required placeholder="<?= esc(I18n::t('admin.th.name')) ?>"></td><td><input name="description" style="width:100%"></td><td><input type="number" name="sort_order" value="100" style="width:4rem"><br><label><input type="checkbox" name="active" value="1" checked> <?= esc(I18n::t('admin.common.active')) ?></label></td><td><button class="btn btn-sm btn-primary"><?= esc(I18n::t('admin.common.add')) ?></button></td></form></tr>
        </tbody></table>
        <h4 style="margin-top:1rem"><?= esc(I18n::t('admin.shipping.countries_in_zones')) ?></h4>
        <table><thead><tr><th><?= esc(I18n::t('admin.shipping.zone')) ?></th><th><?= esc(I18n::t('admin.common.country')) ?></th><th><?= esc(I18n::t('admin.shipping.postal_from')) ?></th><th><?= esc(I18n::t('admin.shipping.postal_to')) ?></th><th></th></tr></thead><tbody>
        <?php foreach ($countries as $c): ?>
        <tr><td><?= esc($c['zone_code']) ?></td><td><?= esc($c['country']) ?></td><td><?= esc($c['postal_min'] ?? '') ?></td><td><?= esc($c['postal_max'] ?? '') ?></td><td><form method="post" action="admin.php?p=shipping&action=country_delete" style="display:inline"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sm btn-danger"><?= esc(I18n::t('admin.btn.delete')) ?></button></form></td></tr>
        <?php endforeach; ?>
        <tr><form method="post" action="admin.php?p=shipping&action=country_save"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><td><select name="zone_code"><?php foreach ($zones as $z): ?><option value="<?= esc($z['code']) ?>"><?= esc($z['code']) ?></option><?php endforeach; ?></select></td><td><input name="country" maxlength="2" required placeholder="SK" style="width:4rem"></td><td><input name="postal_min" style="width:6rem"></td><td><input name="postal_max" style="width:6rem"></td><td><button class="btn btn-sm btn-primary"><?= esc(I18n::t('admin.common.add')) ?></button></td></form></tr>
        </tbody></table>
    </div>

    <div class="card">
        <h3><?= esc(I18n::t('admin.shipping.rates')) ?></h3>
        <table><thead><tr><th><?= esc(I18n::t('admin.shipping.method_zone')) ?></th><th><?= esc(I18n::t('admin.common.country')) ?></th><th><?= esc(I18n::t('admin.shipping.weight')) ?></th><th><?= esc(I18n::t('admin.shipping.volume')) ?></th><th><?= esc(I18n::t('admin.th.price')) ?></th><th><?= esc(I18n::t('admin.shipping.cod')) ?></th><th><?= esc(I18n::t('admin.shipping.days')) ?></th><th><?= esc(I18n::t('admin.common.active')) ?></th><th></th></tr></thead><tbody>
        <?php foreach ($rates as $r): ?>
        <tr><form method="post" action="admin.php?p=shipping&action=rate_save"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><td><select name="method_code"><?php foreach ($methods as $m): ?><option value="<?= esc($m['code']) ?>" <?= ($r['method_code'] ?? '')===$m['code']?'selected':'' ?>><?= esc($m['code']) ?></option><?php endforeach; ?></select><br><select name="zone_code"><?php foreach ($zones as $z): ?><option value="<?= esc($z['code']) ?>" <?= ($r['zone_code'] ?? '')===$z['code']?'selected':'' ?>><?= esc($z['code']) ?></option><?php endforeach; ?></select></td><td><input name="country" value="<?= esc($r['country']) ?>" maxlength="2" style="width:4rem"></td><td><input type="number" name="weight_from_g" value="<?= (int)$r['weight_from_g'] ?>" style="width:6rem"><br><input type="number" name="weight_to_g" value="<?= (int)$r['weight_to_g'] ?>" style="width:6rem"></td><td><input type="number" name="volume_to_cm3" value="<?= esc($r['volume_to_cm3'] ?? '') ?>" style="width:7rem"></td><td><input type="number" name="price" value="<?= (int)$r['price'] ?>" required style="width:6rem"></td><td><input type="number" name="cod_surcharge" value="<?= (int)$r['cod_surcharge'] ?>" style="width:5rem"></td><td><input name="delivery_days" value="<?= esc($r['delivery_days'] ?? '') ?>" style="width:4rem"></td><td><input type="number" name="sort_order" value="<?= (int)($r['sort_order'] ?? 100) ?>" style="width:4rem"><br><label><input type="checkbox" name="active" value="1" <?= !isset($r['active']) || $r['active']?'checked':'' ?>> <?= esc(I18n::t('admin.common.active')) ?></label></td><td><button class="btn btn-sm btn-primary"><?= esc(I18n::t('admin.btn.save')) ?></button></form> <form method="post" action="admin.php?p=shipping&action=rate_delete" style="display:inline" data-confirm="<?= esc(I18n::t('admin.shipping.rate_delete_confirm')) ?>"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-danger"><?= esc(I18n::t('admin.btn.delete')) ?></button></form></td></tr>
        <?php endforeach; ?>
        <tr><form method="post" action="admin.php?p=shipping&action=rate_save"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><td><select name="method_code"><?php foreach ($methods as $m): ?><option value="<?= esc($m['code']) ?>"><?= esc($m['code']) ?></option><?php endforeach; ?></select><br><select name="zone_code"><?php foreach ($zones as $z): ?><option value="<?= esc($z['code']) ?>"><?= esc($z['code']) ?></option><?php endforeach; ?></select></td><td><input name="country" maxlength="2" placeholder="SK" style="width:4rem"></td><td><input type="number" name="weight_from_g" value="0" style="width:6rem"><br><input type="number" name="weight_to_g" value="5000" style="width:6rem"></td><td><input type="number" name="volume_to_cm3" style="width:7rem"></td><td><input type="number" name="price" value="0" required style="width:6rem"></td><td><input type="number" name="cod_surcharge" value="0" style="width:5rem"></td><td><input name="delivery_days" style="width:4rem"></td><td><input type="number" name="sort_order" value="100" style="width:4rem"><br><label><input type="checkbox" name="active" value="1" checked> <?= esc(I18n::t('admin.common.active')) ?></label></td><td><button class="btn btn-sm btn-primary"><?= esc(I18n::t('admin.common.add')) ?></button></td></form></tr>
        </tbody></table>
    </div>
    <?php
}
function handleShippingMethodSave(): void {
    Security::adminRequire('shipping.manage');
    $db = Db::get();
    $id = Security::int($_POST['id'] ?? 0);
    $code = Security::slug($_POST['code'] ?? '');
    $carrier = Security::slug($_POST['carrier'] ?? '');
    $name = trim(Security::str($_POST['name'] ?? ''));
    $serviceType = in_array($_POST['service_type'] ?? '', ['parcel', 'pickup'], true) ? $_POST['service_type'] : 'parcel';
    if (!$code || !$carrier || !$name) { setFlash('err', I18n::t('admin.shipping.method_required')); header('Location: admin.php?p=shipping'); exit; }
    $data = [':code'=>$code, ':carrier'=>$carrier, ':name'=>$name, ':description'=>trim(Security::str($_POST['description'] ?? '')), ':adapter_class'=>trim(Security::str($_POST['adapter_class'] ?? '')), ':service_type'=>$serviceType, ':supports_cod'=>(int)!empty($_POST['supports_cod']), ':supports_pickup'=>(int)!empty($_POST['supports_pickup']), ':free_above'=>shippingNullableInt('free_above'), ':free_from_qty'=>shippingNullableInt('free_from_qty'), ':max_weight_g'=>shippingNullableInt('max_weight_g'), ':max_volume_cm3'=>shippingNullableInt('max_volume_cm3'), ':active'=>(int)!empty($_POST['active']), ':sort_order'=>Security::int($_POST['sort_order'] ?? 100)];
    $data[':store'] = StoreContext::current()->providerId();
    $old = $id ? StoreConfig::row('shipping_methods', $id) : null;
    if ($id && $old === null) { setFlash('err', I18n::t('admin.common.record_missing')); header('Location: admin.php?p=shipping'); exit; }
    if (!in_array(StoreConfig::value('shipping_methods', 'code', $code), [null, (string)$id], true)) { setFlash('err', I18n::t('admin.shipping.method_exists')); header('Location: admin.php?p=shipping'); exit; }
    if ($id) { $data[':id'] = $id; $stmt = $db->prepare("UPDATE shipping_methods SET code=:code, carrier=:carrier, name=:name, description=:description, adapter_class=:adapter_class, service_type=:service_type, supports_cod=:supports_cod, supports_pickup=:supports_pickup, free_above=:free_above, free_from_qty=:free_from_qty, max_weight_g=:max_weight_g, max_volume_cm3=:max_volume_cm3, active=:active, sort_order=:sort_order, updated_at=" . Db::now() . " WHERE id=:id AND store_id=:store"); $stmt->execute($data); setFlash('ok', I18n::t('admin.shipping.method_saved')); }
    else { $db->prepare("INSERT INTO shipping_methods (store_id, code, carrier, name, description, adapter_class, service_type, supports_cod, supports_pickup, free_above, free_from_qty, max_weight_g, max_volume_cm3, active, sort_order) VALUES (:store, :code, :carrier, :name, :description, :adapter_class, :service_type, :supports_cod, :supports_pickup, :free_above, :free_from_qty, :max_weight_g, :max_volume_cm3, :active, :sort_order)")->execute($data); setFlash('ok', I18n::t('admin.shipping.method_saved')); }
    StoreConfig::audit('shipping_methods', $id, $old);
    header('Location: admin.php?p=shipping'); exit;
}
function handleShippingMethodDelete(): void { Security::adminRequire('shipping.manage'); storeConfigDelete('shipping_methods', I18n::t('admin.shipping.method_deleted')); header('Location: admin.php?p=shipping'); exit; }
function handleShippingZoneSave(): void {
    Security::adminRequire('shipping.manage');
    $db = Db::get(); $id = Security::int($_POST['id'] ?? 0); $code = Security::slug($_POST['code'] ?? ''); $name = trim(Security::str($_POST['name'] ?? ''));
    if (!$code || !$name) { setFlash('err', I18n::t('admin.common.code_name_required')); header('Location: admin.php?p=shipping'); exit; }
    $data = [':code'=>$code, ':name'=>$name, ':description'=>trim(Security::str($_POST['description'] ?? '')), ':active'=>(int)!empty($_POST['active']), ':sort_order'=>Security::int($_POST['sort_order'] ?? 100)];
    $data[':store'] = StoreContext::current()->providerId();
    $old = $id ? StoreConfig::row('shipping_zones', $id) : null;
    if ($id && $old === null) { setFlash('err', I18n::t('admin.common.record_missing')); header('Location: admin.php?p=shipping'); exit; }
    if (!in_array(StoreConfig::value('shipping_zones', 'code', $code), [null, (string)$id], true)) { setFlash('err', I18n::t('admin.shipping.zone_exists')); header('Location: admin.php?p=shipping'); exit; }
    if ($id) { $data[':id']=$id; $stmt = $db->prepare("UPDATE shipping_zones SET code=:code, name=:name, description=:description, active=:active, sort_order=:sort_order, updated_at=" . Db::now() . " WHERE id=:id AND store_id=:store"); $stmt->execute($data); setFlash('ok', I18n::t('admin.shipping.zone_saved')); }
    else { $db->prepare("INSERT INTO shipping_zones (store_id, code, name, description, active, sort_order) VALUES (:store, :code, :name, :description, :active, :sort_order)")->execute($data); setFlash('ok', I18n::t('admin.shipping.zone_saved')); }
    StoreConfig::audit('shipping_zones', $id, $old);
    header('Location: admin.php?p=shipping'); exit;
}
function handleShippingZoneDelete(): void { Security::adminRequire('shipping.manage'); storeConfigDelete('shipping_zones', I18n::t('admin.shipping.zone_deleted')); header('Location: admin.php?p=shipping'); exit; }
function handleShippingCountrySave(): void {
    Security::adminRequire('shipping.manage');
    $db = Db::get(); $zoneCode = Security::slug($_POST['zone_code'] ?? ''); $country = strtoupper(trim(Security::str($_POST['country'] ?? '')));
    if (!$zoneCode || !preg_match('/^[A-Z]{2}$/', $country) || StoreConfig::value('shipping_zones', 'code', $zoneCode) === null) { setFlash('err', I18n::t('admin.shipping.country_required')); header('Location: admin.php?p=shipping'); exit; }
    $stmt = $db->prepare(Db::insertIgnore() . " INTO shipping_zone_countries (store_id, zone_code, country, postal_min, postal_max) VALUES (:store, :zone, :country, :min, :max)");
    $stmt->execute([':store'=>StoreContext::current()->providerId(), ':zone'=>$zoneCode, ':country'=>$country, ':min'=>trim(Security::str($_POST['postal_min'] ?? '')), ':max'=>trim(Security::str($_POST['postal_max'] ?? ''))]);
    if ($stmt->rowCount() > 0) StoreConfig::audit('shipping_zone_countries', 0, null);
    setFlash('ok', I18n::t('admin.shipping.country_added')); header('Location: admin.php?p=shipping'); exit;
}
function handleShippingCountryDelete(): void { Security::adminRequire('shipping.manage'); storeConfigDelete('shipping_zone_countries', I18n::t('admin.common.country_deleted')); header('Location: admin.php?p=shipping'); exit; }
function handleShippingRateSave(): void {
    Security::adminRequire('shipping.manage');
    $db = Db::get(); $id = Security::int($_POST['id'] ?? 0); $methodCode = Security::slug($_POST['method_code'] ?? ''); $zoneCode = Security::slug($_POST['zone_code'] ?? ''); $country = strtoupper(trim(Security::str($_POST['country'] ?? '')));
    $from = max(0, Security::int($_POST['weight_from_g'] ?? 0)); $to = max(0, Security::int($_POST['weight_to_g'] ?? 0));
    $carrier = $methodCode ? StoreConfig::value('shipping_methods', 'code', $methodCode, 'carrier') : null;
    if ($carrier === null || !$zoneCode || StoreConfig::value('shipping_zones', 'code', $zoneCode) === null || ($country !== '' && !preg_match('/^[A-Z]{2}$/', $country)) || $to < $from) { setFlash('err', I18n::t('admin.shipping.rate_invalid')); header('Location: admin.php?p=shipping'); exit; }
    $data = [':method_code'=>$methodCode, ':carrier'=>$carrier, ':country'=>$country, ':zone_code'=>$zoneCode, ':weight_from_g'=>$from, ':weight_to_g'=>$to, ':volume_to_cm3'=>shippingNullableInt('volume_to_cm3'), ':price'=>max(0, Security::int($_POST['price'] ?? 0)), ':cod_surcharge'=>max(0, Security::int($_POST['cod_surcharge'] ?? 0)), ':delivery_days'=>trim(Security::str($_POST['delivery_days'] ?? '')), ':active'=>(int)!empty($_POST['active']), ':sort_order'=>Security::int($_POST['sort_order'] ?? 100)];
    $data[':store'] = StoreContext::current()->providerId();
    $old = $id ? StoreConfig::row('shipping_rates', $id) : null;
    if ($id && $old === null) { setFlash('err', I18n::t('admin.common.record_missing')); header('Location: admin.php?p=shipping'); exit; }
    if ($id) { $data[':id']=$id; $stmt = $db->prepare("UPDATE shipping_rates SET method_code=:method_code, carrier=:carrier, country=:country, zone_code=:zone_code, weight_from_g=:weight_from_g, weight_to_g=:weight_to_g, volume_to_cm3=:volume_to_cm3, price=:price, cod_surcharge=:cod_surcharge, delivery_days=:delivery_days, active=:active, sort_order=:sort_order, updated_at=" . Db::now() . " WHERE id=:id AND store_id=:store"); $stmt->execute($data); setFlash('ok', I18n::t('admin.shipping.rate_saved')); }
    else { $db->prepare("INSERT INTO shipping_rates (store_id, method_code, carrier, country, zone_code, weight_from_g, weight_to_g, volume_to_cm3, price, cod_surcharge, delivery_days, active, sort_order) VALUES (:store, :method_code, :carrier, :country, :zone_code, :weight_from_g, :weight_to_g, :volume_to_cm3, :price, :cod_surcharge, :delivery_days, :active, :sort_order)")->execute($data); setFlash('ok', I18n::t('admin.shipping.rate_saved')); }
    StoreConfig::audit('shipping_rates', $id, $old);
    header('Location: admin.php?p=shipping'); exit;
}
function handleShippingRateDelete(): void { Security::adminRequire('shipping.manage'); storeConfigDelete('shipping_rates', I18n::t('admin.shipping.rate_deleted')); header('Location: admin.php?p=shipping'); exit; }
function shippingNullableInt(string $key): ?int { $v = trim((string)($_POST[$key] ?? '')); return $v === '' ? null : max(0, Security::int($v)); }
function pagePayments(): void {
    $methods = StoreConfig::rows("SELECT * FROM payment_methods WHERE store_id = :store ORDER BY sort_order, code");
    $countries = StoreConfig::rows("SELECT * FROM payment_method_countries WHERE store_id = :store ORDER BY method_code, country");
    $shippingLinks = StoreConfig::rows("SELECT ps.*, sm.name AS shipping_name FROM payment_method_shipping ps LEFT JOIN shipping_methods sm ON sm.code = ps.shipping_method_code AND sm.store_id = ps.store_id WHERE ps.store_id = :store ORDER BY ps.method_code, ps.shipping_method_code");
    $shippingMethods = StoreConfig::rows("SELECT code, name FROM shipping_methods WHERE store_id = :store ORDER BY sort_order, code");
    ?>
    <h1><?= esc(I18n::t('admin.title.payments')) ?></h1>
    <div class="card">
        <h3><?= esc(I18n::t('admin.payments.methods')) ?></h3>
        <table>
            <thead><tr><th><?= esc(I18n::t('admin.common.code')) ?></th><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.payments.provider_type')) ?></th><th><?= esc(I18n::t('admin.common.limits')) ?></th><th><?= esc(I18n::t('admin.payments.fee')) ?></th><th><?= esc(I18n::t('admin.common.active')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($methods as $m): ?>
            <tr><form method="post" action="admin.php?p=payments&action=method_save"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><td><input name="code" value="<?= esc($m['code']) ?>" required style="width:8rem"></td><td><input name="name" value="<?= esc($m['name']) ?>" required style="width:12rem"><br><input name="description" value="<?= esc($m['description'] ?? '') ?>" placeholder="<?= esc(I18n::t('admin.common.description')) ?>" style="width:12rem"></td><td><input name="provider" value="<?= esc($m['provider']) ?>" required style="width:8rem"><br><select name="service_type"><option value="online_card" <?= ($m['service_type'] ?? '')==='online_card'?'selected':'' ?>><?= esc(I18n::t('admin.payments.type_online_card')) ?></option><option value="bank_transfer" <?= ($m['service_type'] ?? '')==='bank_transfer'?'selected':'' ?>><?= esc(I18n::t('admin.payments.type_bank_transfer')) ?></option><option value="cod" <?= ($m['service_type'] ?? '')==='cod'?'selected':'' ?>><?= esc(I18n::t('admin.shipping.cod')) ?></option><option value="offline" <?= ($m['service_type'] ?? '')==='offline'?'selected':'' ?>><?= esc(I18n::t('admin.payments.type_offline')) ?></option></select></td><td><input type="number" name="min_amount" value="<?= (int)$m['min_amount'] ?>" style="width:6rem"><br><input type="number" name="max_amount" value="<?= esc($m['max_amount'] ?? '') ?>" placeholder="max" style="width:6rem"></td><td><input type="number" name="fixed_fee" value="<?= (int)$m['fixed_fee'] ?>" style="width:5rem"><br><input type="number" name="percent_fee_bps" value="<?= (int)$m['percent_fee_bps'] ?>" style="width:5rem"> bps<br><input type="number" name="free_above" value="<?= esc($m['free_above'] ?? '') ?>" placeholder="<?= esc(I18n::t('admin.shipping.free_above')) ?>" style="width:6rem"></td><td><input type="number" name="sort_order" value="<?= (int)$m['sort_order'] ?>" style="width:4rem"><br><label><input type="checkbox" name="requires_online" value="1" <?= !empty($m['requires_online'])?'checked':'' ?>> <?= esc(I18n::t('admin.payments.online')) ?></label><br><label><input type="checkbox" name="active" value="1" <?= !empty($m['active'])?'checked':'' ?>> <?= esc(I18n::t('admin.common.active')) ?></label></td><td><button class="btn btn-sm btn-primary"><?= esc(I18n::t('admin.btn.save')) ?></button></form> <form method="post" action="admin.php?p=payments&action=method_delete" style="display:inline" data-confirm="<?= esc(I18n::t('admin.payments.delete_confirm')) ?>"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-sm btn-danger"><?= esc(I18n::t('admin.btn.delete')) ?></button></form></td></tr>
            <?php endforeach; ?>
            <tr><form method="post" action="admin.php?p=payments&action=method_save"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><td><input name="code" required placeholder="card"></td><td><input name="name" required placeholder="<?= esc(I18n::t('admin.th.name')) ?>"><br><input name="description" placeholder="<?= esc(I18n::t('admin.common.description')) ?>"></td><td><input name="provider" required placeholder="stripe"><br><select name="service_type"><option value="online_card"><?= esc(I18n::t('admin.payments.type_online_card')) ?></option><option value="bank_transfer"><?= esc(I18n::t('admin.payments.type_bank_transfer')) ?></option><option value="cod"><?= esc(I18n::t('admin.shipping.cod')) ?></option><option value="offline"><?= esc(I18n::t('admin.payments.type_offline')) ?></option></select></td><td><input type="number" name="min_amount" value="0" style="width:6rem"><br><input type="number" name="max_amount" placeholder="max" style="width:6rem"></td><td><input type="number" name="fixed_fee" value="0" style="width:5rem"><br><input type="number" name="percent_fee_bps" value="0" style="width:5rem"> bps<br><input type="number" name="free_above" placeholder="<?= esc(I18n::t('admin.shipping.free_above')) ?>" style="width:6rem"></td><td><input type="number" name="sort_order" value="100" style="width:4rem"><br><label><input type="checkbox" name="requires_online" value="1"> <?= esc(I18n::t('admin.payments.online')) ?></label><br><label><input type="checkbox" name="active" value="1" checked> <?= esc(I18n::t('admin.common.active')) ?></label></td><td><button class="btn btn-sm btn-primary"><?= esc(I18n::t('admin.common.add')) ?></button></td></form></tr>
            </tbody>
        </table>
    </div>
    <div class="card">
        <h3><?= esc(I18n::t('admin.payments.countries_shipping')) ?></h3>
        <div class="grid" style="grid-template-columns:1fr 1fr">
            <div><h4><?= esc(I18n::t('admin.payments.countries')) ?></h4><table><thead><tr><th><?= esc(I18n::t('admin.payments.method')) ?></th><th><?= esc(I18n::t('admin.common.country')) ?></th><th></th></tr></thead><tbody>
            <?php foreach ($countries as $c): ?><tr><td><?= esc($c['method_code']) ?></td><td><?= esc($c['country']) ?></td><td><form method="post" action="admin.php?p=payments&action=country_delete" style="display:inline"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sm btn-danger"><?= esc(I18n::t('admin.btn.delete')) ?></button></form></td></tr><?php endforeach; ?>
            <tr><form method="post" action="admin.php?p=payments&action=country_save"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><td><select name="method_code"><?php foreach ($methods as $m): ?><option value="<?= esc($m['code']) ?>"><?= esc($m['code']) ?></option><?php endforeach; ?></select></td><td><input name="country" maxlength="2" required placeholder="SK" style="width:4rem"></td><td><button class="btn btn-sm btn-primary"><?= esc(I18n::t('admin.common.add')) ?></button></td></form></tr>
            </tbody></table></div>
            <div><h4><?= esc(I18n::t('admin.payments.shipping_limits')) ?></h4><p class="muted"><?= esc(I18n::t('admin.payments.shipping_hint')) ?></p><table><thead><tr><th><?= esc(I18n::t('admin.payments.method')) ?></th><th><?= esc(I18n::t('admin.nav.shipping')) ?></th><th></th></tr></thead><tbody>
            <?php foreach ($shippingLinks as $s): ?><tr><td><?= esc($s['method_code']) ?></td><td><?= esc($s['shipping_method_code']) ?> <?= $s['shipping_name'] ? '(' . esc($s['shipping_name']) . ')' : '' ?></td><td><form method="post" action="admin.php?p=payments&action=shipping_delete" style="display:inline"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm btn-danger"><?= esc(I18n::t('admin.btn.delete')) ?></button></form></td></tr><?php endforeach; ?>
            <tr><form method="post" action="admin.php?p=payments&action=shipping_save"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><td><select name="method_code"><?php foreach ($methods as $m): ?><option value="<?= esc($m['code']) ?>"><?= esc($m['code']) ?></option><?php endforeach; ?></select></td><td><select name="shipping_method_code"><?php foreach ($shippingMethods as $sm): ?><option value="<?= esc($sm['code']) ?>"><?= esc($sm['code']) ?> — <?= esc($sm['name']) ?></option><?php endforeach; ?></select></td><td><button class="btn btn-sm btn-primary"><?= esc(I18n::t('admin.common.add')) ?></button></td></form></tr>
            </tbody></table></div>
        </div>
    </div>
    <?php
}
function handlePaymentMethodSave(): void {
    Security::adminRequire('payments.manage');
    $db = Db::get(); $id = Security::int($_POST['id'] ?? 0); $code = Security::slug($_POST['code'] ?? ''); $provider = Security::slug($_POST['provider'] ?? ''); $name = trim(Security::str($_POST['name'] ?? ''));
    $serviceType = in_array($_POST['service_type'] ?? '', ['online_card', 'bank_transfer', 'cod', 'offline'], true) ? $_POST['service_type'] : 'offline';
    $min = max(0, Security::int($_POST['min_amount'] ?? 0)); $max = paymentNullableInt('max_amount');
    if (!$code || !$provider || !$name || ($max !== null && $max < $min)) { setFlash('err', I18n::t('admin.payments.required')); header('Location: admin.php?p=payments'); exit; }
    $data = [':code'=>$code, ':provider'=>$provider, ':name'=>$name, ':description'=>trim(Security::str($_POST['description'] ?? '')), ':service_type'=>$serviceType, ':min_amount'=>$min, ':max_amount'=>$max, ':fixed_fee'=>max(0, Security::int($_POST['fixed_fee'] ?? 0)), ':percent_fee_bps'=>max(0, Security::int($_POST['percent_fee_bps'] ?? 0)), ':free_above'=>paymentNullableInt('free_above'), ':requires_online'=>(int)!empty($_POST['requires_online']), ':active'=>(int)!empty($_POST['active']), ':sort_order'=>Security::int($_POST['sort_order'] ?? 100)];
    $data[':store'] = StoreContext::current()->providerId();
    if (!in_array(StoreConfig::value('payment_methods', 'code', $code), [null, (string)$id], true)) { setFlash('err', I18n::t('admin.payments.exists')); header('Location: admin.php?p=payments'); exit; }
    $old = $id ? StoreConfig::row('payment_methods', $id) : null;
    if ($id) {
        if ($old === null) { setFlash('err', I18n::t('admin.common.record_missing')); header('Location: admin.php?p=payments'); exit; }
        $data[':id']=$id; $db->prepare("UPDATE payment_methods SET code=:code, provider=:provider, name=:name, description=:description, service_type=:service_type, min_amount=:min_amount, max_amount=:max_amount, fixed_fee=:fixed_fee, percent_fee_bps=:percent_fee_bps, free_above=:free_above, requires_online=:requires_online, active=:active, sort_order=:sort_order, updated_at=" . Db::now() . " WHERE id=:id AND store_id=:store")->execute($data);
        PaymentService::renameMethodCodeLinks((string)$old['code'], $code);
    }
    else { $db->prepare("INSERT INTO payment_methods (store_id, code, provider, name, description, service_type, min_amount, max_amount, fixed_fee, percent_fee_bps, free_above, requires_online, active, sort_order) VALUES (:store, :code, :provider, :name, :description, :service_type, :min_amount, :max_amount, :fixed_fee, :percent_fee_bps, :free_above, :requires_online, :active, :sort_order)")->execute($data); }
    StoreConfig::audit('payment_methods', $id, $old);
    setFlash('ok', I18n::t('admin.payments.saved')); header('Location: admin.php?p=payments'); exit;
}
function handlePaymentMethodDelete(): void { Security::adminRequire('payments.manage'); storeConfigDelete('payment_methods', I18n::t('admin.payments.deleted')); header('Location: admin.php?p=payments'); exit; }
function handlePaymentCountrySave(): void {
    Security::adminRequire('payments.manage');
    $methodCode = Security::slug($_POST['method_code'] ?? ''); $country = strtoupper(trim(Security::str($_POST['country'] ?? '')));
    if (!$methodCode || !preg_match('/^[A-Z]{2}$/', $country) || StoreConfig::value('payment_methods', 'code', $methodCode) === null) { setFlash('err', I18n::t('admin.payments.country_required')); header('Location: admin.php?p=payments'); exit; }
    $stmt = Db::get()->prepare(Db::insertIgnore() . " INTO payment_method_countries (store_id, method_code, country) VALUES (:store, :method, :country)");
    $stmt->execute([':store'=>StoreContext::current()->providerId(), ':method'=>$methodCode, ':country'=>$country]);
    if ($stmt->rowCount() > 0) StoreConfig::audit('payment_method_countries', 0, null);
    setFlash('ok', I18n::t('admin.payments.country_added')); header('Location: admin.php?p=payments'); exit;
}
function handlePaymentCountryDelete(): void { Security::adminRequire('payments.manage'); storeConfigDelete('payment_method_countries', I18n::t('admin.common.country_deleted')); header('Location: admin.php?p=payments'); exit; }
function handlePaymentShippingSave(): void {
    Security::adminRequire('payments.manage');
    $methodCode = Security::slug($_POST['method_code'] ?? ''); $shippingCode = Security::slug($_POST['shipping_method_code'] ?? '');
    if (!$methodCode || !$shippingCode || StoreConfig::value('payment_methods', 'code', $methodCode) === null || StoreConfig::value('shipping_methods', 'code', $shippingCode) === null) { setFlash('err', I18n::t('admin.payments.shipping_required')); header('Location: admin.php?p=payments'); exit; }
    $stmt = Db::get()->prepare(Db::insertIgnore() . " INTO payment_method_shipping (store_id, method_code, shipping_method_code) VALUES (:store, :method, :shipping)");
    $stmt->execute([':store'=>StoreContext::current()->providerId(), ':method'=>$methodCode, ':shipping'=>$shippingCode]);
    if ($stmt->rowCount() > 0) StoreConfig::audit('payment_method_shipping', 0, null);
    setFlash('ok', I18n::t('admin.payments.shipping_added')); header('Location: admin.php?p=payments'); exit;
}
function handlePaymentShippingDelete(): void { Security::adminRequire('payments.manage'); storeConfigDelete('payment_method_shipping', I18n::t('admin.payments.shipping_deleted')); header('Location: admin.php?p=payments'); exit; }
function paymentNullableInt(string $key): ?int { $v = trim((string)($_POST[$key] ?? '')); return $v === '' ? null : max(0, Security::int($v)); }

function storeConfigDelete(string $table, string $message): void {
    $id = Security::int($_POST['id'] ?? 0);
    if (!$id) return;
    if (StoreConfig::delete($table, $id)) setFlash('ok', $message);
    else setFlash('err', I18n::t('admin.common.record_missing'));
}
function handleFeedSync(): void {
    $id = Security::int($_POST['feed_id'] ?? 0);
    // Only a feed of the active store (its warehouse's store) can be synced from here.
    if (!$id || !Warehouse::feedById($id)) {
        setFlash('err', I18n::t('admin.feed.invalid'));
        header('Location: admin.php?p=feeds');
        exit;
    }

    $result = FeedImport::sync($id);
    AuditLog::log('feed', $id, null, array_diff_key($result, ['errors' => 1]) + ['error_count' => count($result['errors'] ?? [])], 'Admin feed sync');

    if (!empty($result['error'])) {
        setFlash('err', I18n::t('admin.feed.sync_error') . ' ' . $result['error']);
    } else {
        $msg = strtr(I18n::t('admin.feed.sync_done'), ['{imported}' => (string)$result['imported'], '{stock}' => (string)$result['stock_updated']]);
        if (!empty($result['errors'])) {
            $msg .= strtr(I18n::t('admin.feed.sync_errors'), ['{count}' => (string)count($result['errors'])]);
        }
        setFlash('ok', $msg);
    }

    header("Location: admin.php?p=feed&id={$id}");
    exit;
}
