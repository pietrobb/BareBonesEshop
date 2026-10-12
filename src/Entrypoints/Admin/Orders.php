<?php
/** Sales, orders, invoices, refunds and RMA administration. */

function pageSalesOverview(): void {
    Security::adminRequire('orders.view');
    $db = Db::get();
    $storeId = StoreContext::current()->id();
    $today = date('Y-m-d 00:00:00');

    $stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE created_at >= :today AND store_id = :store");
    $stmt->execute([':today' => $today, ':store' => $storeId]);
    $ordersToday = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(total), 0) FROM orders WHERE created_at >= :today AND store_id = :store AND status NOT IN ('cancelled', 'refunded')");
    $stmt->execute([':today' => $today, ':store' => $storeId]);
    $revenueToday = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE store_id = :store AND payment_status IN ('pending', 'authorized')");
    $stmt->execute([':store' => $storeId]);
    $pendingPayments = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COALESCE(SUM(de.download_count), 0) FROM digital_entitlements de JOIN orders o ON o.id = de.order_id WHERE o.store_id = :store");
    $stmt->execute([':store' => $storeId]);
    $downloadCount = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) FROM email_log el JOIN orders o ON o.id = el.order_id WHERE o.store_id = :store AND el.status = 'failed'");
    $stmt->execute([':store' => $storeId]);
    $failedEmails = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) FROM merchant_notifications mn JOIN orders o ON o.id = mn.order_id WHERE o.store_id = :store AND mn.status = 'failed'");
    $stmt->execute([':store' => $storeId]);
    $failedNotifications = (int)$stmt->fetchColumn();

    $search = trim((string)($_GET['q'] ?? ''));
    $period = (string)($_GET['period'] ?? '30');
    $statusFilter = (string)($_GET['status'] ?? '');
    $paymentStatusFilter = (string)($_GET['payment_status'] ?? '');
    $allowedPeriods = ['today', '7', '30', 'all'];
    if (!in_array($period, $allowedPeriods, true)) $period = '30';
    if ($statusFilter !== '' && !in_array($statusFilter, OrderWorkflow::statuses(), true)) $statusFilter = '';
    if ($paymentStatusFilter !== '' && !in_array($paymentStatusFilter, PaymentService::paymentStatuses(), true)) $paymentStatusFilter = '';

    $where = ['o.store_id = :store'];
    $params = [':store' => $storeId];
    if ($search !== '') {
        $like = '%' . mb_strtolower($search) . '%';
        $where[] = '(LOWER(o.order_number) LIKE :q_number OR LOWER(o.customer_name) LIKE :q_name OR LOWER(o.customer_email) LIKE :q_email)';
        $params[':q_number'] = $like;
        $params[':q_name'] = $like;
        $params[':q_email'] = $like;
    }
    if ($period !== 'all') {
        $since = $period === 'today' ? $today : date('Y-m-d H:i:s', strtotime('-' . (int)$period . ' days'));
        $where[] = 'o.created_at >= :since';
        $params[':since'] = $since;
    }
    if ($statusFilter !== '') {
        $where[] = 'o.status = :status';
        $params[':status'] = $statusFilter;
    }
    if ($paymentStatusFilter !== '') {
        $where[] = 'o.payment_status = :payment_status';
        $params[':payment_status'] = $paymentStatusFilter;
    }
    $whereSql = implode(' AND ', $where);

    $pageNumber = max(1, Security::int($_GET['page'] ?? 1));
    $perPage = 30;
    $offset = ($pageNumber - 1) * $perPage;
    $stmt = $db->prepare("SELECT COUNT(*) FROM orders o WHERE {$whereSql}");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));

    $stmt = $db->prepare("SELECT o.*,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count,
            (SELECT COUNT(*) FROM digital_entitlements de WHERE de.order_id = o.id) AS entitlement_count,
            (SELECT COALESCE(SUM(de.download_count), 0) FROM digital_entitlements de WHERE de.order_id = o.id) AS order_download_count,
            (SELECT el.status FROM email_log el WHERE el.order_id = o.id ORDER BY el.id DESC LIMIT 1) AS email_status
        FROM orders o
        WHERE {$whereSql}
        ORDER BY o.created_at DESC
        LIMIT :limit OFFSET :offset");
    foreach ($params as $key => $value) $stmt->bindValue($key, $value, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll();

    $periodLabels = [
        'today' => I18n::t('admin.sales.period.today'),
        '7' => I18n::t('admin.sales.period.7_days'),
        '30' => I18n::t('admin.sales.period.30_days'),
        'all' => I18n::t('admin.sales.period.all'),
    ];
    ?>
    <div style="display:flex;justify-content:space-between;align-items:start;gap:1rem;flex-wrap:wrap">
        <div>
            <h1 style="margin-bottom:.25rem"><?= esc(I18n::t('admin.title.sales')) ?></h1>
            <p class="muted"><?= esc(I18n::t('admin.sales.live_data')) ?> · <?= esc(I18n::t('admin.sales.updated')) ?> <?= esc(date('Y-m-d H:i')) ?></p>
        </div>
        <a class="btn" href="admin.php?p=sales"><?= esc(I18n::t('admin.sales.refresh')) ?></a>
    </div>

    <div class="grid grid-4" style="margin-top:1rem">
        <div class="card stat"><div class="num"><?= $ordersToday ?></div><div class="label"><?= esc(I18n::t('admin.stat.orders_today')) ?></div></div>
        <div class="card stat"><div class="num"><?= I18n::price($revenueToday) ?></div><div class="label"><?= esc(I18n::t('admin.sales.order_value_today')) ?></div></div>
        <div class="card stat"><div class="num"><?= $pendingPayments ?></div><div class="label"><?= esc(I18n::t('admin.sales.pending_payments')) ?></div></div>
        <div class="card stat"><div class="num"><?= $downloadCount ?></div><div class="label"><?= esc(I18n::t('admin.sales.total_downloads')) ?></div></div>
    </div>

    <?php if ($failedEmails || $failedNotifications): ?>
    <div class="flash flash-err">
        <?= esc(I18n::t('admin.sales.attention')) ?>: <?= $failedEmails ?> <?= esc(I18n::t('admin.sales.failed_emails')) ?>, <?= $failedNotifications ?> <?= esc(I18n::t('admin.sales.failed_notifications')) ?>.
    </div>
    <?php endif; ?>

    <div class="card">
        <form method="get" class="sales-toolbar">
            <input type="hidden" name="p" value="sales">
            <div class="form-row"><label for="sales-q"><?= esc(I18n::t('admin.sales.order_or_customer')) ?></label><input id="sales-q" name="q" value="<?= esc($search) ?>" placeholder="<?= esc(I18n::t('admin.sales.search_placeholder')) ?>"></div>
            <div class="form-row"><label for="sales-period"><?= esc(I18n::t('admin.sales.period')) ?></label><select id="sales-period" name="period">
                <?php foreach ($periodLabels as $value => $label): ?><option value="<?= esc($value) ?>" <?= $period === $value ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-row"><label for="sales-status"><?= esc(I18n::t('admin.sales.order_status')) ?></label><select id="sales-status" name="status">
                <option value=""><?= esc(I18n::t('admin.sales.all_statuses')) ?></option>
                <?php foreach (OrderWorkflow::statuses() as $value): ?><option value="<?= esc($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= esc($value) ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-row"><label for="sales-payment"><?= esc(I18n::t('admin.sales.payment_status')) ?></label><select id="sales-payment" name="payment_status">
                <option value=""><?= esc(I18n::t('admin.sales.all_statuses')) ?></option>
                <?php foreach (PaymentService::paymentStatuses() as $value): ?><option value="<?= esc($value) ?>" <?= $paymentStatusFilter === $value ? 'selected' : '' ?>><?= esc($value) ?></option><?php endforeach; ?>
            </select></div>
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.sales.show')) ?></button>
        </form>
    </div>

    <div class="card">
        <h3 style="margin-bottom:.75rem"><?= esc(I18n::t('admin.title.orders')) ?> <span class="muted">(<?= $total ?>)</span></h3>
        <div class="table-scroll"><table>
            <tr><th><?= esc(I18n::t('admin.order.label')) ?></th><th><?= esc(I18n::t('admin.th.customer')) ?></th><th><?= esc(I18n::t('admin.sales.items')) ?></th><th><?= esc(I18n::t('admin.th.amount')) ?></th><th><?= esc(I18n::t('admin.sales.order_status')) ?></th><th><?= esc(I18n::t('admin.sales.payment_status')) ?></th><th><?= esc(I18n::t('admin.sales.digital')) ?></th><th><?= esc(I18n::t('admin.th.email')) ?></th><th></th></tr>
            <?php foreach ($orders as $order): ?>
            <tr>
                <td><strong><?= esc($order['order_number']) ?></strong><br><span class="muted"><?= esc(substr((string)$order['created_at'], 0, 16)) ?></span></td>
                <td><?= esc($order['customer_name']) ?><br><span class="muted"><?= esc($order['customer_email']) ?></span></td>
                <td><?= (int)$order['item_count'] ?> <?= esc(I18n::t('admin.sales.items_short')) ?></td>
                <td><strong><?= I18n::price((int)$order['total'], $order['currency']) ?></strong></td>
                <td><span class="badge badge-<?= esc($order['status']) ?>"><?= esc($order['status']) ?></span></td>
                <td><span class="badge badge-<?= esc($order['payment_status'] ?? 'pending') ?>"><?= esc($order['payment_status'] ?? 'pending') ?></span><br><span class="muted"><?= esc($order['payment_method'] ?? '') ?></span></td>
                <td><?php if ((int)$order['entitlement_count'] > 0): ?><?= (int)$order['order_download_count'] ?> <?= esc(I18n::t('admin.sales.downloads')) ?><br><span class="muted"><?= (int)$order['entitlement_count'] ?> <?= esc(I18n::t('admin.sales.entitlements')) ?></span><?php else: ?>—<?php endif; ?></td>
                <td><?php if ($order['email_status']): ?><span class="<?= $order['email_status'] === 'failed' ? 'status-error' : ($order['email_status'] === 'sent' ? 'status-ok' : 'status-warn') ?>"><?= esc($order['email_status']) ?></span><?php else: ?>—<?php endif; ?></td>
                <td><a class="btn btn-sm" href="admin.php?p=order&id=<?= (int)$order['id'] ?>"><?= esc(I18n::t('admin.sales.detail')) ?></a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$orders): ?><tr><td colspan="9" class="muted" style="text-align:center;padding:2rem"><?= esc(I18n::t('admin.sales.no_orders')) ?></td></tr><?php endif; ?>
        </table></div>
        <?php
        $baseQuery = http_build_query(['p' => 'sales', 'q' => $search, 'period' => $period, 'status' => $statusFilter, 'payment_status' => $paymentStatusFilter]);
        pagination($pageNumber, $pages, 'admin.php?' . $baseQuery);
        ?>
    </div>
    <?php
}
function pageOrders(): void {
    Security::adminRequire('orders.view');
    $db = Db::get();
    $page = max(1, Security::int($_GET['page'] ?? 1));
    $perPage = 25;
    $offset = ($page - 1) * $perPage;
    $statusFilter = $_GET['status'] ?? '';
    $paymentStatusFilter = $_GET['payment_status'] ?? '';

    $where = 'store_id = :store';
    $params = [':store' => StoreContext::current()->id()];
    if ($statusFilter) {
        $where .= " AND status = :status";
        $params[':status'] = $statusFilter;
    }
    if ($paymentStatusFilter) {
        $where .= " AND payment_status = :payment_status";
        $params[':payment_status'] = $paymentStatusFilter;
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE {$where}");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));

    $stmt = $db->prepare("SELECT * FROM orders WHERE {$where} ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
    foreach ($params as $key => $value) $stmt->bindValue($key, $value, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
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
            <select name="payment_status">
                <option value="">— Payment —</option>
                <?php foreach (PaymentService::paymentStatuses() as $s): ?>
                <option value="<?= $s ?>" <?= $paymentStatusFilter===$s?'selected':'' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn" type="submit"><?= esc(I18n::t('admin.btn.apply')) ?></button>
        </form>
        <table>
            <tr><th><?= esc(I18n::t('admin.th.number')) ?></th><th><?= esc(I18n::t('admin.th.customer')) ?></th><th><?= esc(I18n::t('admin.th.email')) ?></th><th><?= esc(I18n::t('admin.th.amount')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th>Payment</th><th><?= esc(I18n::t('admin.th.date')) ?></th><th></th></tr>
            <?php foreach ($orders as $o): ?>
            <tr>
                <td><a href="admin.php?p=order&id=<?= $o['id'] ?>"><?= esc($o['order_number']) ?></a></td>
                <td><?= esc($o['customer_name']) ?></td>
                <td class="muted"><?= esc($o['customer_email']) ?></td>
                <td><?= I18n::price((int)$o['total'], $o['currency']) ?></td>
                <td><span class="badge badge-<?= esc($o['status']) ?>"><?= esc($o['status']) ?></span></td>
                <td><span class="badge badge-<?= esc($o['payment_status'] ?? 'pending') ?>"><?= esc($o['payment_status'] ?? 'pending') ?></span><br><span class="muted"><?= esc($o['payment_method'] ?? '') ?></span></td>
                <td class="muted"><?= esc(substr($o['created_at'], 0, 16)) ?></td>
                <td><a href="admin.php?p=order&id=<?= $o['id'] ?>" class="btn btn-sm">Detail</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php pagination($page, $pages, "admin.php?p=orders&status=" . urlencode($statusFilter) . "&payment_status=" . urlencode($paymentStatusFilter)); ?>
    </div>
    <?php
}
function pageOrder(): void {
    $id = Security::int($_GET['id'] ?? 0);
    $order = Orders::byId($id);
    if (!$order) { echo '<p>' . esc(I18n::t('admin.order.not_found')) . '</p>'; return; }

    $items = Orders::items($id);
    $allowed = OrderWorkflow::allowedTransitions($order['status']);
    $paymentTransitions = array_values(array_diff(
        PaymentService::allowedPaymentTransitions((string)($order['payment_status'] ?? 'pending')),
        ['refund_pending', 'partially_refunded', 'refunded']
    ));
    $paymentTxs = PaymentService::transactionsForOrder($id);
    $refundableBalance = PaymentService::refundableBalance($id);
    $paymentSnapshot = json_decode((string)($order['payment_method_snapshot'] ?? '{}'), true) ?: [];
    $latestPaymentTx = $paymentTxs ? $paymentTxs[count($paymentTxs) - 1] : null;
    $logs = AuditLog::forEntity('order_status', $id, 20);
    $addr = json_decode($order['shipping_addr'] ?? '{}', true);
    $digitalEntitlements = DigitalDelivery::forOrder($id);
    $orderConsents = ConsentService::forOrder($id);
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
            <p class="muted" style="margin-top:.5rem">Payment method: <?= esc((string)($paymentSnapshot['label'] ?? $order['payment_method'])) ?> <span class="muted"><?= esc((string)($paymentSnapshot['code'] ?? $order['payment_method'])) ?><?= !empty($paymentSnapshot['provider']) ? ' · ' . esc((string)$paymentSnapshot['provider']) : '' ?></span></p>
            <p class="muted">Payment reference: <?= $order['payment_id'] ? esc($order['payment_id']) : '—' ?></p>
            <p class="muted">Payment status: <span class="badge badge-<?= esc($order['payment_status'] ?? 'pending') ?>"><?= esc($order['payment_status'] ?? 'pending') ?></span><?= $latestPaymentTx ? ' · Last event: ' . esc((string)$latestPaymentTx['status']) . ' @ ' . esc(substr((string)$latestPaymentTx['created_at'], 0, 16)) : '' ?></p>
            <?php if (!empty($paymentTransitions)): ?>
            <form method="post" action="admin.php?p=order&id=<?= $id ?>&action=payment_transition" style="margin-top:.5rem">
                <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                <select name="new_payment_status">
                    <?php foreach ($paymentTransitions as $s): ?>
                    <option value="<?= esc($s) ?>"><?= esc($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <input name="amount" type="number" min="0" step="1" placeholder="Suma centy">
                <input name="note" placeholder="Poznámka...">
                <button class="btn btn-primary btn-sm" type="submit">Zmeniť platbu</button>
            </form>
            <?php endif; ?>
            <?php if ($refundableBalance > 0 && PaymentService::canPaymentTransition((string)($order['payment_status'] ?? 'pending'), 'refund_pending')): ?>
            <form method="post" action="admin.php?p=order&id=<?= $id ?>&action=refund_request" style="margin-top:.5rem">
                <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                <input name="amount" type="number" min="1" max="<?= $refundableBalance ?>" step="1" value="<?= $refundableBalance ?>">
                <input name="note" placeholder="Dôvod refundu..." required>
                <button class="btn btn-danger btn-sm" type="submit">Požiadať refund</button>
            </form>
            <?php else: ?>
            <p class="muted" style="margin-top:.5rem">Refund unavailable for current payment status.</p>
            <?php endif; ?>
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
            <?php if ((int)($order['payment_fee'] ?? 0) > 0): ?>
            <p>Poplatok za platbu: <?= I18n::price((int)$order['payment_fee'], $order['currency']) ?></p>
            <?php endif; ?>
            <?php if ((int)$order['tax_amount'] > 0): ?>
            <p>DPH: <?= I18n::price((int)$order['tax_amount'], $order['currency']) ?></p>
            <?php endif; ?>
            <p style="font-size:1.2rem"><strong>Celkom: <?= I18n::price((int)$order['total'], $order['currency']) ?></strong></p>
        </div>
    </div>

    <?php if ($digitalEntitlements): ?>
    <div class="card">
        <h3>Digitálne oprávnenia</h3>
        <table>
            <tr><th>SKU</th><th>Súbor</th><th>Stav</th><th>Stiahnutia</th><th>Expirácia</th><th>Doručené</th></tr>
            <?php foreach ($digitalEntitlements as $entitlement): ?>
            <tr>
                <td><?= esc($entitlement['sku']) ?></td>
                <td><?= esc($entitlement['label'] ?: $entitlement['download_name']) ?></td>
                <td><span class="badge badge-<?= esc($entitlement['status']) ?>"><?= esc($entitlement['status']) ?></span></td>
                <td><?= (int)$entitlement['download_count'] ?> / <?= $entitlement['download_limit'] === null ? '∞' : (int)$entitlement['download_limit'] ?></td>
                <td><?= $entitlement['expires_at'] === null ? '—' : esc(date('Y-m-d H:i', (int)$entitlement['expires_at'])) ?></td>
                <td><?= esc($entitlement['delivered_at'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($orderConsents): ?>
    <div class="card">
        <h3>Súhlasy pri objednávke</h3>
        <table>
            <tr><th>Kľúč</th><th>Verzia</th><th>Znenie</th><th>Čas</th></tr>
            <?php foreach ($orderConsents as $consent): ?>
            <tr>
                <td><?= esc($consent['consent_key']) ?></td>
                <td><?= esc($consent['version']) ?></td>
                <td><?= esc($consent['consent_text']) ?></td>
                <td><?= esc($consent['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <div class="card">
        <h3>Payment timeline</h3>
        <?php if ($paymentTxs): ?>
        <table>
            <tr><th>Status</th><th>Provider</th><th>Ref</th><th>Suma</th><th>Event</th><th>Details</th><th>Kedy</th></tr>
            <?php foreach ($paymentTxs as $tx): ?>
            <?php
                $raw = json_decode((string)($tx['raw_response'] ?? '{}'), true) ?: [];
                $details = [];
                foreach (['reason', 'actor', 'refund_id', 'session_id', 'payment_id'] as $key) {
                    if (!empty($raw[$key])) $details[] = $key . ': ' . (string)$raw[$key];
                }
            ?>
            <tr>
                <td><span class="badge badge-<?= esc($tx['status']) ?>"><?= esc($tx['status']) ?></span></td>
                <td><?= esc($tx['provider']) ?></td>
                <td class="muted"><?= esc($tx['provider_ref']) ?></td>
                <td><?= I18n::price((int)$tx['amount'], $tx['currency']) ?></td>
                <td class="muted"><?= esc($raw['event_type'] ?? '') ?></td>
                <td class="muted"><?= esc(implode(' · ', $details)) ?></td>
                <td class="muted"><?= esc(substr($tx['created_at'], 0, 16)) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php else: ?>
        <p class="muted">Žiadne payment eventy zatiaľ nie sú zaznamenané.</p>
        <?php endif; ?>
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

    <?php
    $invStmt = Db::get()->prepare("SELECT id, number, issued_at, due_date, total_gross, currency, status, pdf_path, download_token, locale, vat_scenario, reverse_charge_note_key, type, created_at
                                    FROM invoices WHERE order_id = :oid ORDER BY id DESC");
    $invStmt->execute([':oid' => $id]);
    $invoices = $invStmt->fetchAll();
    ?>
    <div class="card">
        <h3>Faktúry</h3>
        <?php if ($invoices): ?>
        <table>
            <tr><th>Číslo</th><th>Typ</th><th>VAT scenár</th><th>Vystavená</th><th>Splatná</th><th>Celkom</th><th>Stav</th><th>Akcie</th></tr>
            <?php foreach ($invoices as $inv): ?>
            <tr>
                <td><?= esc($inv['number']) ?></td>
                <td><?= esc($inv['type'] ?? 'invoice') ?></td>
                <td><span class="badge badge-vat-<?= esc($inv['vat_scenario'] ?? '') ?>"><?= esc($inv['vat_scenario'] ?? '—') ?></span></td>
                <td><?= esc($inv['issued_at']) ?></td>
                <td><?= esc($inv['due_date']) ?></td>
                <td><?= I18n::price((int)$inv['total_gross'], $inv['currency']) ?></td>
                <td><span class="badge badge-<?= esc($inv['status']) ?>"><?= esc($inv['status']) ?></span></td>
                <td>
                    <a class="btn btn-sm" href="admin.php?p=invoice_pdf&id=<?= (int)$inv['id'] ?>" target="_blank">PDF</a>
                    <form method="post" action="admin.php?p=order&id=<?= $id ?>&action=invoice_resend" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                        <button class="btn btn-sm" type="submit">Poslať znova</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php else: ?>
        <p class="muted">Žiadna faktúra zatiaľ nebola vystavená.</p>
        <?php endif; ?>
        <?php if ($order['status'] === 'paid' || $order['status'] === 'shipped' || $order['status'] === 'delivered'): ?>
        <form method="post" action="admin.php?p=order&id=<?= $id ?>&action=invoice_issue" style="display:inline;margin-top:.5rem">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <button class="btn btn-primary btn-sm" type="submit">Vystaviť faktúru</button>
        </form>
        <?php endif; ?>
    </div>

    <a href="admin.php?p=orders" class="btn">← Späť na zoznam</a>
    <?php
}
function pageInvoicePdf(): void {
    Security::adminRequire('orders.view');
    $id = Security::int($_GET['id'] ?? 0);
    $inv = InvoiceService::byId($id);
    if (!$inv) { http_response_code(404); echo 'Faktúra neexistuje.'; return; }
    $abs = InvoiceService::pdfAbsolutePath($inv);
    if (!is_file($abs)) { http_response_code(404); echo 'PDF súbor chýba.'; return; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $inv['number'] . '.pdf"');
    header('Content-Length: ' . filesize($abs));
    readfile($abs);
    exit;
}
function pageRma(): void {
    $filter = Security::clean($_GET['status'] ?? '');
    $filter = in_array($filter, Rma::STATUSES, true) ? $filter : null;
    $rmas = Rma::all(200, $filter);
    // Map order ids → order numbers for display
    $orderNos = [];
    $orderIds = array_values(array_unique(array_map(static fn($r) => (int)$r['order_id'], $rmas)));
    if ($orderIds) {
        $ph = implode(',', array_fill(0, count($orderIds), '?'));
        $st = Db::get()->prepare("SELECT id, order_number FROM orders WHERE id IN ({$ph})");
        $st->execute($orderIds);
        foreach ($st->fetchAll() as $r) { $orderNos[(int)$r['id']] = (string)$r['order_number']; }
    }
    // Allowed next-status buttons per current status.
    $next = [
        'requested' => ['approved' => 'Schváliť', 'rejected' => 'Zamietnuť'],
        'approved'  => ['received' => 'Prijaté', 'rejected' => 'Zamietnuť'],
        'received'  => ['refunded' => 'Refundované'],
    ];
    ?>
    <h1>Reklamácie / vrátenia <span class="muted">(<?= count($rmas) ?>)</span></h1>
    <div class="card">
        <p>
            <a class="btn btn-sm<?= $filter===null?' btn-primary':'' ?>" href="admin.php?p=rma">Všetky</a>
            <?php foreach (Rma::STATUSES as $s): ?>
            <a class="btn btn-sm<?= $filter===$s?' btn-primary':'' ?>" href="admin.php?p=rma&status=<?= esc($s) ?>"><?= esc($s) ?></a>
            <?php endforeach; ?>
        </p>
        <?php if (!$rmas): ?>
        <p class="muted">Žiadne reklamácie.</p>
        <?php else: ?>
        <table>
            <tr><th>RMA</th><th>Objednávka</th><th>Položky</th><th>Dôvod</th><th>Stav</th><th>Akcia</th></tr>
            <?php foreach ($rmas as $r): ?>
            <?php $items = Rma::items((int)$r['id']); ?>
            <tr>
                <td><?= esc($r['rma_number']) ?><br><span class="muted"><?= esc(substr((string)$r['created_at'], 0, 16)) ?></span></td>
                <td><?= esc($orderNos[(int)$r['order_id']] ?? ('#' . $r['order_id'])) ?></td>
                <td><ul class="muted" style="margin:0;padding-left:16px">
                    <?php foreach ($items as $it): ?>
                    <li><?= esc($it['name']) ?> (<?= (int)$it['quantity'] ?>×)</li>
                    <?php endforeach; ?>
                </ul></td>
                <td><?= esc($r['reason']) ?></td>
                <td><span class="badge badge-<?= esc($r['status']) ?>"><?= esc($r['status']) ?></span></td>
                <td style="white-space:nowrap">
                    <?php foreach ($next[$r['status']] ?? [] as $to => $label): ?>
                    <form method="post" action="admin.php?p=rma&action=status" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="rma_id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="status" value="<?= esc($to) ?>">
                        <?php if ($to === 'received'): ?>
                        <select name="disposition" aria-label="Disposition">
                            <option value="restock">Naskladniť</option>
                            <option value="repair">Oprava</option>
                            <option value="discard">Likvidácia</option>
                        </select>
                        <?php endif; ?>
                        <button class="btn btn-sm" type="submit"><?= esc($label) ?></button>
                    </form>
                    <?php endforeach; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
    <?php
}
function handleRmaStatus(): void {
    Security::adminRequire('products.edit');
    $id = Security::int($_POST['rma_id'] ?? 0);
    $status = Security::clean($_POST['status'] ?? '');
    try {
        if ($id <= 0) throw new InvalidArgumentException('Neplatné RMA.');
        if ($status === 'received') {
            $disposition = Security::clean($_POST['disposition'] ?? '');
            if (!in_array($disposition, ['restock', 'repair', 'discard'], true)) throw new InvalidArgumentException('Neplatná disposition.');
            $decisions = [];
            foreach (Rma::items($id) as $item) {
                $remaining = (int)$item['quantity'] - (int)$item['received_quantity'];
                if ($remaining > 0) $decisions[(int)$item['id']] = ['quantity' => $remaining, 'disposition' => $disposition];
            }
            if (!$decisions || !Rma::receive($id, $decisions, 'admin-receive-' . $id, 'Admin receipt')) {
                throw new RuntimeException('Príjem už bol spracovaný.');
            }
        } elseif ($status === 'refunded') {
            $rma = Rma::byId($id);
            if (!$rma) throw new RuntimeException('RMA sa nenašlo.');
            $source = InvoiceService::byOrderId((int)$rma['order_id']);
            if (!$source) throw new RuntimeException('Objednávka nemá zdrojovú faktúru.');
            $quantities = [];
            foreach (Rma::items($id) as $item) {
                $remaining = (int)$item['received_quantity'] - (int)$item['refunded_quantity'];
                if ($remaining > 0) $quantities[(int)$item['id']] = $remaining;
            }
            $credit = InvoiceService::credit((int)$source['id'], $id, $quantities, 'admin-credit-' . $id);
            $order = Orders::byId((int)$rma['order_id']);
            if (trim((string)($order['payment_id'] ?? '')) === '') {
                Rma::completeRefund((int)$credit['refund_id'], 'manual-admin');
            }
        } elseif (!Rma::setStatus($id, $status)) {
            throw new LogicException('Nepovolená zmena stavu.');
        }
        setFlash('ok', 'Reklamácia #' . $id . ' → ' . $status);
    } catch (Throwable $e) {
        setFlash('err', 'Stav reklamácie sa nepodarilo zmeniť: ' . $e->getMessage());
    }
    header('Location: admin.php?p=rma');
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
    $order = Orders::byId($orderId);
    if (!$order || ($newStatus === 'paid' && ($order['payment_status'] ?? 'pending') !== 'paid')) {
        setFlash('err', $order ? 'Najprv potvrďte prijatie platby.' : 'Objednávka neexistuje.');
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
function handleOrderPaymentTransition(): void {
    Security::adminRequire('orders.edit');
    $orderId = Security::int($_GET['id'] ?? 0);
    $order = Orders::byId($orderId);
    if (!$order) {
        setFlash('err', 'Objednávka neexistuje.');
        header('Location: admin.php?p=orders');
        exit;
    }

    $newStatus = trim((string)($_POST['new_payment_status'] ?? ''));
    $amount = max(0, Security::int($_POST['amount'] ?? 0));
    $note = trim((string)($_POST['note'] ?? ''));
    $from = (string)($order['payment_status'] ?? 'pending');

    if (in_array($newStatus, ['refund_pending', 'partially_refunded', 'refunded'], true)
        || !PaymentService::canPaymentTransition($from, $newStatus)) {
        setFlash('err', 'Neplatný prechod platby.');
        header("Location: admin.php?p=order&id={$orderId}");
        exit;
    }

    try {
        $key = 'admin_payment_' . $orderId . '_' . $newStatus . '_' . time();
        PaymentService::transitionPayment($orderId, $newStatus, 'admin.' . $newStatus, $amount, $key, [
            'actor' => Security::adminUser(),
            'note' => $note,
        ]);
        setFlash('ok', "Stav platby zmenený na '{$newStatus}'.");
    } catch (\Throwable $e) {
        error_log("Payment status change error [{$orderId}]: " . $e->getMessage());
        setFlash('err', 'Chyba pri zmene stavu platby.');
    }

    header("Location: admin.php?p=order&id={$orderId}");
    exit;
}
function handleOrderRefundRequest(): void {
    Security::adminRequire('orders.edit');
    $orderId = Security::int($_GET['id'] ?? 0);
    $order = Orders::byId($orderId);
    if (!$order) {
        setFlash('err', 'Objednávka neexistuje.');
        header('Location: admin.php?p=orders');
        exit;
    }

    $amount = Security::int($_POST['amount'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? $_POST['note'] ?? ''));
    $refundableBalance = PaymentService::refundableBalance($orderId);
    if ($amount <= 0 || $amount > $refundableBalance || $reason === '') {
        setFlash('err', 'Suma refundu musí byť v rozsahu dostupného zostatku.');
        header("Location: admin.php?p=order&id={$orderId}");
        exit;
    }

    try {
        $key = 'admin_refund_request_' . $orderId . '_' . bin2hex(random_bytes(12));
        PaymentService::requestRefund($orderId, $amount, $key, [
            'actor' => Security::adminUser(),
            'amount' => $amount,
            'reason' => $reason,
        ]);
        setFlash('ok', 'Refund požiadavka bola vytvorená.');
    } catch (\Throwable $e) {
        error_log("Refund request error [{$orderId}]: " . $e->getMessage());
        setFlash('err', 'Chyba pri vytváraní refund požiadavky.');
    }

    header("Location: admin.php?p=order&id={$orderId}");
    exit;
}
function handleOrderEmailResend(): void {
    Security::adminRequire('orders.edit');
    $orderId = Security::int($_GET['id'] ?? 0);
    $logId   = Security::int($_POST['log_id'] ?? 0);
    if ($logId <= 0 || !Security::rateLimit('admin_email_resend_' . $logId, 3, 3600, false)) {
        setFlash('err', 'Email nemožno opätovne odoslať.');
        header("Location: admin.php?p=order&id={$orderId}");
        exit;
    }
    try {
        $res = MailService::resend($logId, $orderId);
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
function handleOrderInvoiceIssue(): void {
    Security::adminRequire('orders.edit');
    $orderId = Security::int($_GET['id'] ?? 0);
    if ($orderId <= 0 || !Security::rateLimit('admin_invoice_issue_' . $orderId, 3, 3600, false)) {
        setFlash('err', 'Faktúru teraz nemožno vystaviť alebo znovu odoslať.');
        header("Location: admin.php?p=order&id={$orderId}");
        exit;
    }
    try {
        $inv = InvoiceService::issue($orderId);
        // Re-issue: send invoice email immediately (sync)
        $order = Orders::byId($orderId);
        if ($order && filter_var($order['customer_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $abs = InvoiceService::pdfAbsolutePath($inv);
            $vars = [
                'order_number'  => $order['order_number'],
                'invoice_number'=> $inv['number'],
                'total_gross'   => I18n::price((int)$inv['total_gross'], $inv['currency']),
                'due_date'      => $inv['due_date'],
            ];
            MailService::send('invoice_issued', $order['customer_email'], (string)($order['locale'] ?? ''), $vars, $orderId,
                [['path' => $abs, 'filename' => $inv['number'] . '.pdf', 'content_type' => 'application/pdf']]);
        }
        setFlash('ok', 'Faktúra ' . esc($inv['number']) . ' vystavená.');
    } catch (\Throwable $e) {
        error_log("Invoice issue [{$orderId}]: " . $e->getMessage());
        setFlash('err', 'Nepodarilo sa vystaviť faktúru: ' . esc($e->getMessage()));
    }
    header("Location: admin.php?p=order&id={$orderId}");
    exit;
}
function handleOrderInvoiceResend(): void {
    Security::adminRequire('orders.edit');
    $orderId   = Security::int($_GET['id'] ?? 0);
    $invoiceId = Security::int($_POST['invoice_id'] ?? 0);
    if ($invoiceId <= 0 || !Security::rateLimit('admin_invoice_resend_' . $invoiceId, 3, 3600, false)) {
        setFlash('err', 'Faktúru teraz nemožno opätovne odoslať.');
        header("Location: admin.php?p=order&id={$orderId}");
        exit;
    }
    $stmt = Db::get()->prepare("SELECT * FROM invoices WHERE id = :id AND order_id = :order_id");
    $stmt->execute([':id' => $invoiceId, ':order_id' => $orderId]);
    $inv = $stmt->fetch();
    if (!$inv) {
        setFlash('err', 'Faktúra neexistuje.');
        header("Location: admin.php?p=order&id={$orderId}");
        exit;
    }
    try {
        $order = Orders::byId((int)$inv['order_id']);
        $abs = InvoiceService::pdfAbsolutePath($inv);
        if (!is_file($abs)) {
            setFlash('err', 'PDF súbor chýba.');
            header("Location: admin.php?p=order&id={$orderId}");
            exit;
        }
        $vars = [
            'order_number'  => $order['order_number'] ?? '',
            'invoice_number'=> $inv['number'],
            'total_gross'   => I18n::price((int)$inv['total_gross'], $inv['currency']),
            'due_date'      => $inv['due_date'],
        ];
        $res = MailService::send('invoice_issued', $order['customer_email'] ?? '', (string)($inv['locale'] ?? ''), $vars, (int)$inv['order_id'],
            [['path' => $abs, 'filename' => $inv['number'] . '.pdf', 'content_type' => 'application/pdf']]);
        if (($res['status'] ?? '') === 'sent') {
            setFlash('ok', 'Faktúra opätovne odoslaná.');
        } else {
            setFlash('err', 'Email sa nepodarilo odoslať: ' . esc($res['error'] ?? $res['status'] ?? ''));
        }
    } catch (\Throwable $e) {
        error_log("Invoice resend [{$invoiceId}]: " . $e->getMessage());
        setFlash('err', 'Chyba pri odosielaní: ' . esc($e->getMessage()));
    }
    header("Location: admin.php?p=order&id={$orderId}");
    exit;
}
