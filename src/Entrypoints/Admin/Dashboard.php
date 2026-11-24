<?php
/**
 * Admin dashboard: today's figures as tiles, the revenue of the last two weeks, recent orders and low stock in the
 * main column and operations health beside them; the setup checklist, incident tables and monitoring evidence below.
 */

const DASHBOARD_TREND_DAYS = 14;

function pageDashboard(): void {
    $db = Db::get();
    $storeId = StoreContext::current()->id();
    // R58 (review round 23, low L2): this page's reports refresh the daily sales totals at most once.
    $salesOnce = SalesReport::refreshOnce();
    $count = static function (string $sql, array $params) use ($db): int {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    };
    // Today's orders since the shop's local midnight (created_at is UTC).
    $ordersToday = $count("SELECT COUNT(*) FROM orders WHERE store_id=:store AND created_at >= :today AND is_synthetic = 0", [':store' => $storeId, ':today' => SalesReport::sinceUtc(1)]);
    $pendingOrders = $count("SELECT COUNT(*) FROM orders WHERE store_id=:store AND status='pending'", [':store' => $storeId]);
    // Same thresholds and warehouse availability as the merchant stock e-mails; physical products only.
    $lowStock = StockAlerts::summary();
    $operations = OperationalDashboard::summary();
    $storeName = (string)(Bootstrap::stores()['stores'][$storeId]['name'] ?? $storeId);
    $permissions = require __DIR__ . '/Permissions.php';
    // R47: dashboard access is reports.view; order and stock detail and every link follow the target page's permission.
    $canOrders = Security::adminCan($permissions['orders']);
    $canProducts = Security::adminCan($permissions['products']);
    ?>
    <div class="dash-head">
        <div><h1><?= esc(I18n::t('admin.title.dashboard')) ?></h1><p class="muted"><?= esc($storeName) ?> · <?= esc(I18n::date(date('Y-m-d'))) ?></p></div>
        <div class="dash-actions">
            <?php if (Security::adminCan($permissions['order_new'])): ?><a class="btn" href="admin.php?p=order_new">+ <?= esc(I18n::t('admin.title.order_new')) ?></a><?php endif; ?>
            <?php if (Security::adminCan($permissions['product'])): ?><a class="btn btn-primary" href="admin.php?p=product">+ <?= esc(I18n::t('admin.title.product_new')) ?></a><?php endif; ?>
        </div>
    </div>
    <div class="kpis">
        <?= dashboardKpi('orders', $canOrders ? 'admin.php?p=orders' : null, 'admin.stat.orders_today', (string)$ordersToday, '<path d="M3 4h2l2.5 11h11L21 7H6.5"/><circle cx="9" cy="19" r="1.5"/><circle cx="17" cy="19" r="1.5"/>') ?>
        <?= dashboardKpi('revenue', 'admin.php?p=reports', 'admin.stat.revenue_today', adminRevenueToday(), '<path d="M4 19V9M10 19V5M16 19v-7M22 19H2"/>') ?>
        <?= dashboardKpi($pendingOrders > 0 ? 'warn' : 'calm', $canOrders ? 'admin.php?p=orders&amp;status=pending' : null, 'admin.stat.pending', (string)$pendingOrders, '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>') ?>
        <?= dashboardKpi($lowStock['count'] > 0 ? 'bad' : 'calm', $canProducts ? 'admin.php?p=products&amp;stock=low' : null, 'admin.stat.low_stock', (string)$lowStock['count'], '<path d="M3 7l9-4 9 4v10l-9 4-9-4z"/><path d="M3 7l9 4 9-4M12 11v10"/>') ?>
    </div>
    <div class="dash-grid">
        <div class="dash-main">
            <?php dashboardTrend($storeId); ?>
            <?php if ($canOrders) dashboardRecentOrders($db, $storeId); ?>
            <?php if ($canProducts && $lowStock['count'] > 0) dashboardLowStock($lowStock['products'], Security::adminCan($permissions['product'])); ?>
        </div>
        <div class="dash-side">
            <?php dashboardHealth($operations, $count('SELECT COUNT(*) FROM products WHERE store_id=:store', [':store' => StoreContext::current()->catalogId()])); ?>
        </div>
    </div>
    <?php if (Security::adminRole() === 'owner') dashboardChecklist(); // doctor output names server paths: owner only ?>
    <?php dashboardIncidents($operations, $canOrders); ?>
    <?php dashboardMonitor($operations, $canOrders); ?>
    <?php
}

/** One figure tile, a link when $href is given; $value is markup the caller already escaped, $icon the inner SVG paths. */
function dashboardKpi(string $tone, ?string $href, string $labelKey, string $value, string $icon): string {
    $body = sprintf('<span class="kpi-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
        . '%s</svg></span><span class="kpi-label">%s</span><span class="kpi-value">%s</span>', $icon, esc(I18n::t($labelKey)), $value);
    return $href !== null ? sprintf('<a class="kpi kpi-%s" href="%s">%s</a>', $tone, $href, $body) : sprintf('<div class="kpi kpi-%s">%s</div>', $tone, $body);
}

/** Panel heading with an optional "view all" link on the right. */
function dashboardPanelHead(string $title, ?string $href = null, ?string $linkText = null): string {
    return '<div class="panel-head"><h2>' . esc($title) . '</h2>'
        . ($href !== null ? '<a href="' . $href . '">' . esc($linkText ?? I18n::t('admin.dashboard.show_all')) . ' →</a>' : '') . '</div>';
}

function dashboardTrend(string $storeId): void {
    $trend = SalesReport::trend($storeId, DASHBOARD_TREND_DAYS);
    // R47: every currency the shop was paid in gets its own chart (amounts are never added up), the shop's own first.
    $own = strtolower(StoreContext::current()->currency());
    if (isset($trend[$own])) $trend = [$own => $trend[$own]] + $trend;
    ?>
    <section class="card">
        <?= dashboardPanelHead(I18n::format('admin.dashboard.trend', ['days' => DASHBOARD_TREND_DAYS]), 'admin.php?p=reports', I18n::t('admin.nav.reports')) ?>
        <?php if ($trend === []): ?><p class="muted"><?= esc(I18n::t('admin.reports.no_data')) ?></p><?php endif; ?>
        <?php foreach ($trend as $currency => $days): ?>
        <?php if (count($trend) > 1): ?><h3><?= esc(strtoupper((string)$currency)) ?></h3><?php endif; ?>
        <?= SalesChart::dailyRevenue($days, (string)$currency) ?>
        <?php // R49 (review round 19, low): a bar's amount was only in its hover tooltip; by keyboard and touch the days read here. ?>
        <details class="chart-values"><summary><?= esc(I18n::t('admin.dashboard.chart_values')) ?></summary><div class="table-scroll"><table>
            <tr><th><?= esc(I18n::t('admin.reports.day')) ?></th><th><?= esc(I18n::t('admin.reports.orders')) ?></th><th><?= esc(I18n::t('admin.reports.revenue')) ?></th></tr>
            <?php foreach (array_reverse($days, true) as $day => $row): ?><tr><td><?= esc(I18n::date((string)$day)) ?></td><td><?= (int)$row['orders'] ?></td><td><?= esc(I18n::price((int)$row['revenue'], (string)$currency)) ?></td></tr><?php endforeach; ?>
        </table></div></details>
        <?php endforeach; ?>
    </section>
    <?php
}

function dashboardRecentOrders(PDO $db, string $storeId): void {
    $stmt = $db->prepare("SELECT * FROM orders WHERE store_id = :store ORDER BY created_at DESC, id DESC LIMIT 10");
    $stmt->execute([':store' => $storeId]);
    $rows = $stmt->fetchAll();
    ?>
    <section class="card">
        <?= dashboardPanelHead(I18n::t('admin.dashboard.recent_orders'), 'admin.php?p=orders') ?>
        <?php if (!$rows): ?><p class="muted"><?= esc(I18n::t('admin.dashboard.no_orders')) ?></p><?php else: ?>
        <table>
            <tr><th><?= esc(I18n::t('admin.th.number')) ?></th><th><?= esc(I18n::t('admin.th.customer')) ?></th><th class="num"><?= esc(I18n::t('admin.th.amount')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.th.date')) ?></th></tr>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td><a class="strong" href="admin.php?p=order&id=<?= (int)$row['id'] ?>"><?= esc($row['order_number']) ?></a></td>
                <td><?= esc($row['customer_name']) ?></td>
                <td class="num"><?= I18n::price((int)$row['total'], $row['currency']) ?></td>
                <td><span class="badge badge-<?= esc($row['status']) ?>"><?= esc(OrderStatusLabels::order((string)$row['status'])) ?></span></td>
                <td class="muted"><?= esc(I18n::dateTime((string)$row['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </section>
    <?php
}

function dashboardLowStock(array $products, bool $canEdit): void {
    ?>
    <section class="card">
        <?= dashboardPanelHead(I18n::t('admin.dashboard.low_stock_alert'), 'admin.php?p=products&amp;stock=low', I18n::t('admin.products.filter_low_stock')) ?>
        <table>
            <tr><th><?= esc(I18n::t('admin.th.sku')) ?></th><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.th.stock')) ?></th><th><?= esc(I18n::t('admin.dashboard.stock_threshold')) ?></th></tr>
            <?php foreach ($products as $row): ?>
            <tr>
                <td class="mono"><?= esc($row['sku']) ?></td>
                <td><?php if ($canEdit): ?><a href="admin.php?p=product&amp;id=<?= (int)$row['product_id'] ?>"><?= esc($row['name']) ?></a><?php else: ?><?= esc($row['name']) ?><?php endif; ?></td>
                <td class="stock-<?= $row['level'] === StockAlerts::LOW ? 'low' : 'critical' ?>"><span class="dot" aria-hidden="true"></span><?= (int)$row['available'] ?> <span class="muted">(<?= esc(I18n::t('admin.stock_level.' . $row['level'])) ?>)</span></td>
                <td><?= (int)$row['low'] ?> / <?= (int)$row['critical'] ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </section>
    <?php
}

/** Operations at a glance: a green/red light per incident kind, the job queue, monitoring and the catalog size. */
function dashboardHealth(array $operations, int $totalProducts): void {
    // R48 (review round 18, low 7): this store's jobs, like every other figure of the panel (not the whole installation's).
    $jobStats = JobQueue::storeCounts() ?? $operations['queue'];
    $monitor = $operations['incident_monitor'];
    $light = static fn(bool $ok): string => '<span class="dot dot-' . ($ok ? 'ok' : 'bad') . '" aria-hidden="true"></span>';
    ?>
    <section class="card">
        <?= dashboardPanelHead(I18n::t('admin.dashboard.health')) ?>
        <ul class="health">
            <?php foreach (['failed_jobs', 'failed_mail', 'payment_incidents', 'webhook_incidents'] as $kind): $n = (int)$operations['counts'][$kind]; ?>
            <li><?= $light($n === 0) ?><span><?= esc(I18n::t('admin.ops.' . $kind)) ?></span><strong class="<?= $n > 0 ? 'status-error' : '' ?>"><?= $n ?></strong></li>
            <?php endforeach; ?>
            <li><?= $light((int)$jobStats['failed'] === 0) ?><span><?= esc(I18n::t('admin.dashboard.jobs_queue')) ?> (<?= esc(adminCodeLabel('admin.dashboard.queue_backend.', (string)$operations['queue_backend'])) ?>)</span><strong><?= (int)$jobStats['pending'] ?> <?= esc(I18n::t('admin.dashboard.jobs_pending')) ?> · <?= (int)$jobStats['failed'] ?> <?= esc(I18n::t('admin.dashboard.jobs_failed')) ?></strong></li>
            <li><?= $monitor['enabled'] ? $light(true) : '<span class="dot" aria-hidden="true"></span>' ?><span><?= esc(I18n::t($monitor['enabled'] ? 'admin.ops.monitoring_enabled' : 'admin.ops.monitoring_disabled')) ?></span></li>
            <li><?= $light($monitor['available']) ?><span><?= esc(I18n::t($monitor['available'] ? 'admin.ops.evidence_available' : 'admin.ops.evidence_unavailable')) ?></span></li>
            <li><span class="dot" aria-hidden="true"></span><span><?= esc(I18n::t('admin.dashboard.total_products')) ?></span><strong><?= $totalProducts ?></strong></li>
        </ul>
        <p class="muted small"><?= esc(I18n::t('admin.ops.worker_cron')) ?>: <?= esc($monitor['worker_cron']) ?> · <?= esc(I18n::t('admin.ops.monitor_cron')) ?>: <?= esc($monitor['monitor_cron']) ?><br>
            <?= esc(I18n::t('admin.common.correlation')) ?>: <?= esc($operations['correlation_id']) ?> · <?= esc(I18n::t('admin.common.store')) ?>: <?= esc($operations['store_id']) ?></p>
    </section>
    <?php
}

function dashboardChecklist(): void {
    $checklist = SetupChecklist::summary();
    ?>
    <div class="card" id="setup-checklist">
        <h2><?= esc(I18n::t('admin.dashboard.checklist')) ?></h2>
        <p class="<?= $checklist['ok'] ? 'status-ok' : 'muted' ?>"><?= esc($checklist['ok'] ? I18n::t('admin.dashboard.checklist_ok') : I18n::format('admin.dashboard.checklist_counts', [
            'problems' => I18n::plural('admin.dashboard.checklist_problems', $checklist['counts']['fail']),
            'warnings' => I18n::plural('admin.dashboard.checklist_warnings', $checklist['counts']['warn'])])) ?></p>
        <?php if ($checklist['items']): ?><ul class="setup-checklist"><?php foreach ($checklist['items'] as $item): ?>
            <li data-check="<?= esc($item['name']) ?>"><span class="badge badge-<?= ['fail' => 'failed', 'warn' => 'pending'][$item['status']] ?? 'draft' ?>"><?= esc(I18n::t('admin.dashboard.checklist_' . $item['status'])) ?></span>
            <?php if ($item['title'] !== null): ?><strong><?= esc($item['title']) ?></strong>
            <?php if ($item['page'] !== null): ?><a class="btn btn-sm" href="admin.php?p=<?= esc($item['page']) ?>"><?= esc(I18n::t('admin.dashboard.checklist_fix')) ?></a><?php endif; ?>
            <details><summary class="muted"><?= esc(I18n::t('admin.dashboard.checklist_detail')) ?></summary><span lang="en"><?= esc($item['message']) ?><?php if ($item['action'] !== ''): ?><br><span class="muted"><?= esc($item['action']) ?></span><?php endif; ?></span></details>
            <?php else: ?><span class="check-text"><?= esc($item['message']) ?></span>
            <?php if ($item['page'] !== null): ?><a class="btn btn-sm" href="admin.php?p=<?= esc($item['page']) ?>"><?= esc(I18n::t('admin.dashboard.checklist_fix')) ?></a>
            <?php elseif ($item['action'] !== ''): ?><span class="muted check-text"><?= esc($item['action']) ?></span><?php endif; ?>
            <?php endif; ?></li>
        <?php endforeach; ?></ul><?php endif; ?>
    </div>
    <?php
}

/** The open incidents of the store, one table per kind; nothing when everything is clean. */
function dashboardIncidents(array $operations, bool $canOrders): void {
    // R47 (capability review): failed mail names the customer and a payment incident is an order; without orders.view only their counts (dashboardHealth) show.
    if (!$canOrders) $operations['failed_mail'] = $operations['payment_incidents'] = [];
    if (!$operations['failed_jobs'] && !$operations['failed_mail'] && !$operations['payment_incidents'] && !$operations['webhook_incidents']) return;
    // R48 (review round 18, low 4): a job's or webhook's error names the order ("…paid order #9") or the customer's address.
    $error = static fn(?string $text): string => $canOrders ? esc((string)$text) : '<span class="muted">' . esc(I18n::t('admin.common.details_hidden')) . '</span>';
    ?>
    <section class="card">
        <?= dashboardPanelHead(I18n::t('admin.dashboard.incidents')) ?>
        <?php if ($operations['failed_jobs']): ?>
        <h3><?= esc(I18n::t('admin.ops.failed_jobs')) ?></h3><div class="table-scroll"><table><tr><th><?= esc(I18n::t('admin.common.type')) ?></th><th><?= esc(I18n::t('admin.common.attempts')) ?></th><th><?= esc(I18n::t('admin.common.error')) ?></th><th><?= esc(I18n::t('admin.common.correlation')) ?></th><th><?= esc(I18n::t('admin.common.updated')) ?></th></tr>
            <?php foreach ($operations['failed_jobs'] as $incident): ?><tr><td><?= esc($incident['type']) ?></td><td><?= (int)$incident['attempts'] ?></td><td><?= $error($incident['last_error']) ?></td><td><code><?= esc($incident['correlation_id']) ?></code></td><td><?= esc(I18n::dateTime((string)$incident['updated_at'])) ?></td></tr><?php endforeach; ?>
        </table></div><?php endif; ?>
        <?php if ($operations['failed_mail']): ?>
        <h3><?= esc(I18n::t('admin.ops.failed_mail')) ?></h3><div class="table-scroll"><table><tr><th><?= esc(I18n::t('admin.common.template')) ?></th><th><?= esc(I18n::t('admin.common.recipient')) ?></th><th><?= esc(I18n::t('admin.common.error')) ?></th><th><?= esc(I18n::t('admin.common.correlation')) ?></th><th><?= esc(I18n::t('admin.common.created')) ?></th></tr>
            <?php foreach ($operations['failed_mail'] as $incident): ?><tr><td><?= esc($incident['template']) ?></td><td><?= esc($incident['recipient']) ?></td><td><?= esc($incident['error']) ?></td><td><code><?= esc($incident['correlation_id'] ?? '') ?></code></td><td><?= esc(I18n::dateTime((string)$incident['created_at'])) ?></td></tr><?php endforeach; ?>
        </table></div><?php endif; ?>
        <?php if ($operations['payment_incidents']): ?>
        <h3><?= esc(I18n::t('admin.ops.payment_incidents')) ?></h3><div class="table-scroll"><table><tr><th><?= esc(I18n::t('admin.order.label')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.common.provider')) ?></th><th><?= esc(I18n::t('admin.common.correlation')) ?></th><th><?= esc(I18n::t('admin.common.created')) ?></th></tr>
            <?php foreach ($operations['payment_incidents'] as $incident): ?><tr><td><a href="admin.php?p=order&id=<?= (int)$incident['id'] ?>"><?= esc($incident['order_number']) ?></a></td><td><?= esc(OrderStatusLabels::payment((string)$incident['payment_status'])) ?></td><td title="<?= esc((string)$incident['payment_method']) ?>"><?= esc(PaymentService::adminLabel($incident)) ?></td><td><code><?= esc($incident['correlation_id'] ?? '') ?></code></td><td><?= esc(I18n::dateTime((string)$incident['created_at'])) ?></td></tr><?php endforeach; ?>
        </table></div><?php endif; ?>
        <?php if ($operations['webhook_incidents']): ?>
        <h3><?= esc(I18n::t('admin.ops.webhook_incidents')) ?></h3><div class="table-scroll"><table><tr><th><?= esc(I18n::t('admin.common.event')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.common.attempts')) ?></th><th><?= esc(I18n::t('admin.common.error')) ?></th><th><?= esc(I18n::t('admin.common.correlation')) ?></th></tr>
            <?php foreach ($operations['webhook_incidents'] as $incident): ?><tr><td title="<?= esc((string)$incident['event_type']) ?>"><?= esc(AdminEventLabels::event((string)$incident['event_type'])) ?></td><td title="<?= esc((string)$incident['status']) ?>"><?= esc(adminCodeLabel('admin.ops.webhook_status.', (string)$incident['status'])) ?></td><td><?= (int)$incident['attempts'] ?></td><td><?= $error($incident['last_error'] ?? '') ?></td><td><code><?= esc($incident['correlation_id'] ?? '') ?></code></td></tr><?php endforeach; ?>
        </table></div><?php endif; ?>
    </section>
    <?php
}

/** Monitoring evidence (probes, logged incidents, notifications) folded away: detail for whoever investigates. */
function dashboardMonitor(array $operations, bool $canOrders): void {
    $monitor = $operations['incident_monitor'];
    ?>
    <details class="card dash-monitor">
        <summary><?= esc(I18n::t('admin.dashboard.monitoring')) ?></summary>
        <div class="table-scroll"><table><tr><th><?= esc(I18n::t('admin.ops.probe')) ?></th><th><?= esc(I18n::t('admin.ops.observed_status')) ?></th><th><?= esc(I18n::t('admin.ops.observed_at')) ?></th></tr>
        <?php foreach ($monitor['probes'] as $probe): ?><tr><td><?= esc($probe['probe']) ?></td><td><?= esc($probe['status']) ?><?= $probe['stale'] ? ' ' . esc(I18n::t('admin.ops.stale')) : '' ?></td><td><?= esc(I18n::dateTime(gmdate('Y-m-d H:i:s', (int)$probe['checked_at']))) ?></td></tr><?php endforeach; ?>
        </table></div>
        <div class="table-scroll"><table><tr><th><?= esc(I18n::t('admin.common.code')) ?></th><th><?= esc(I18n::t('admin.ops.order')) ?></th><th><?= esc(I18n::t('admin.ops.phase')) ?></th><th><?= esc(I18n::t('admin.ops.severity')) ?></th><th><?= esc(I18n::t('admin.common.correlation')) ?></th><th><?= esc(I18n::t('admin.ops.time_utc')) ?></th></tr>
        <?php foreach ($monitor['incidents'] as $incident): $incidentOrder = (int)(json_decode((string)($incident['context_json'] ?? ''), true)['order_id'] ?? 0); // R36: an incident of an order links to it ?><tr><td><?= esc($incident['code']) ?></td><td><?php if ($incidentOrder > 0 && $canOrders): ?><a href="admin.php?p=order&id=<?= $incidentOrder ?>">#<?= $incidentOrder ?></a><?php endif; ?></td><td><?= esc($incident['phase']) ?></td><td><?= esc($incident['severity']) ?></td><td><code><?= esc($incident['correlation_id']) ?></code></td><td><?= esc(I18n::dateTime(gmdate('Y-m-d H:i:s', (int)$incident['occurred_at']))) ?></td></tr><?php endforeach; ?>
        </table></div>
        <div class="table-scroll"><table><tr><th><?= esc(I18n::t('admin.common.code')) ?></th><th><?= esc(I18n::t('admin.ops.count')) ?></th><th><?= esc(I18n::t('admin.ops.notification')) ?></th><th><?= esc(I18n::t('admin.ops.last_acceptance')) ?></th></tr>
        <?php foreach ($monitor['notifications'] as $notification): ?><tr><td><?= esc($notification['code']) ?></td><td><?= (int)$notification['occurrences'] ?></td><td><?= esc($notification['notification_status']) ?></td><td><?= $notification['last_notification_at'] ? esc(I18n::dateTime(gmdate('Y-m-d H:i:s', (int)$notification['last_notification_at']))) : esc(I18n::t('admin.ops.not_observed')) ?></td></tr><?php endforeach; ?>
        </table></div>
    </details>
    <?php
}
