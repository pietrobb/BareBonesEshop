<?php
/** Sales, orders, invoices, refunds and RMA administration. */
require_once __DIR__ . '/Shipments.php';

function pageSalesOverview(): void {
    Security::adminRequire('orders.view');
    $db = Db::get();
    $storeId = StoreContext::current()->id();
    // Shop-local midnight as UTC, the zone created_at is stored in.
    $today = SalesReport::sinceUtc(1);

    $stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE created_at >= :today AND store_id = :store AND is_synthetic = 0");
    $stmt->execute([':today' => $today, ':store' => $storeId]);
    $ordersToday = (int)$stmt->fetchColumn();

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

    $filters = OrderExport::filters($_GET);
    [$search, $period, $statusFilter, $paymentStatusFilter] = [$filters['q'], $filters['period'], $filters['status'], $filters['payment_status']];
    [$whereSql, $params] = [$filters['where'], $filters['params']];

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
        <div class="card stat"><div class="num"><?= adminRevenueToday() ?></div><div class="label"><?= esc(I18n::t('admin.sales.order_value_today')) ?></div></div>
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
            <select name="status" aria-label="<?= esc(I18n::t('admin.sales.order_status')) ?>">
                <option value="">— <?= esc(I18n::t('admin.th.status')) ?> —</option>
                <?php foreach (OrderWorkflow::statuses() as $s): ?>
                <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
            <select name="payment_status" aria-label="<?= esc(I18n::t('admin.sales.payment_status')) ?>">
                <option value="">— <?= esc(I18n::t('admin.order.payment')) ?> —</option>
                <?php foreach (PaymentService::paymentStatuses() as $s): ?>
                <option value="<?= $s ?>" <?= $paymentStatusFilter===$s?'selected':'' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn" type="submit"><?= esc(I18n::t('admin.btn.apply')) ?></button>
        </form>
        <?php $filterFields = '<input type="hidden" name="csrf_token" value="' . esc(Security::csrfToken()) . '"><input type="hidden" name="status" value="' . esc(Security::str($statusFilter)) . '"><input type="hidden" name="payment_status" value="' . esc(Security::str($paymentStatusFilter)) . '">'; ?>
        <form method="post" action="admin.php?p=orders&amp;action=export" style="margin-bottom:1rem"><?= $filterFields ?><button class="btn btn-sm" type="submit"><?= esc(I18n::t('admin.orders.export_csv')) ?></button></form>
        <form method="post" action="admin.php?p=orders&amp;action=bulk_status" id="orders-bulk">
        <?= $filterFields ?>
        <div class="sales-toolbar" style="margin-bottom:1rem">
            <div class="form-row"><label for="bulk-status"><?= esc(I18n::t('admin.orders.bulk_status')) ?></label><select id="bulk-status" name="new_status"><?php foreach (OrderWorkflow::statuses() as $s): ?><option value="<?= esc($s) ?>"><?= esc($s) ?></option><?php endforeach; ?></select></div>
            <div class="form-row"><label for="bulk-note"><?= esc(I18n::t('admin.order.note')) ?></label><input id="bulk-note" name="note"></div>
            <button class="btn btn-primary btn-sm" type="submit"><?= esc(I18n::t('admin.orders.bulk_apply')) ?></button>
            <button class="btn btn-sm" type="submit" formaction="admin.php?p=delivery_note" formtarget="_blank" name="format" value="html"><?= esc(I18n::t('admin.delivery_note.html')) ?></button>
            <button class="btn btn-sm" type="submit" formaction="admin.php?p=delivery_note" formtarget="_blank" name="format" value="pdf"><?= esc(I18n::t('admin.delivery_note.pdf')) ?></button>
        </div>
        <table>
            <tr><th><span class="sr-only"><?= esc(I18n::t('admin.orders.select')) ?></span></th><th><?= esc(I18n::t('admin.th.number')) ?></th><th><?= esc(I18n::t('admin.th.customer')) ?></th><th><?= esc(I18n::t('admin.th.email')) ?></th><th><?= esc(I18n::t('admin.th.amount')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.order.payment')) ?></th><th><?= esc(I18n::t('admin.th.date')) ?></th><th></th></tr>
            <?php foreach ($orders as $o): ?>
            <tr>
                <td><input type="checkbox" name="order_ids[]" value="<?= (int)$o['id'] ?>" aria-label="<?= esc(I18n::t('admin.orders.select') . ' ' . $o['order_number']) ?>"></td>
                <td><a href="admin.php?p=order&id=<?= $o['id'] ?>"><?= esc($o['order_number']) ?></a></td>
                <td><?= esc($o['customer_name']) ?></td>
                <td class="muted"><?= esc($o['customer_email']) ?></td>
                <td><?= I18n::price((int)$o['total'], $o['currency']) ?></td>
                <td><span class="badge badge-<?= esc($o['status']) ?>"><?= esc($o['status']) ?></span></td>
                <td><span class="badge badge-<?= esc($o['payment_status'] ?? 'pending') ?>"><?= esc($o['payment_status'] ?? 'pending') ?></span><br><span class="muted"><?= esc($o['payment_method'] ?? '') ?></span></td>
                <td class="muted"><?= esc(substr($o['created_at'], 0, 16)) ?></td>
                <td><a href="admin.php?p=order&id=<?= $o['id'] ?>" class="btn btn-sm"><?= esc(I18n::t('admin.sales.detail')) ?></a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </form>
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
    $canRefund = Security::adminCan('orders.refund');
    if (!$canRefund) $allowed = array_values(array_diff($allowed, ['refunded']));
    $paymentTransitions = Security::adminCan('orders.payments') ? adminManualPaymentTransitions($order) : [];
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
    <?php if (Security::adminCan('orders.print')): ?><p><a class="btn btn-sm" href="admin.php?p=delivery_note&amp;id=<?= $id ?>" target="_blank"><?= esc(I18n::t('admin.delivery_note.html')) ?></a> <a class="btn btn-sm" href="admin.php?p=delivery_note&amp;id=<?= $id ?>&amp;format=pdf" target="_blank"><?= esc(I18n::t('admin.delivery_note.pdf')) ?></a></p><?php endif; ?>

    <div class="grid" style="grid-template-columns:1fr 1fr;gap:1rem">
        <div class="card">
            <h3><?= esc(I18n::t('admin.th.customer')) ?></h3>
            <p><strong><?= esc($order['customer_name']) ?></strong></p>
            <p><?= esc($order['customer_email']) ?></p>
            <p><?= esc($order['customer_phone']) ?></p>
            <?php if ($addr): ?>
            <p class="muted"><?= esc(($addr['street'] ?? '') . ', ' . ($addr['zip'] ?? '') . ' ' . ($addr['city'] ?? '') . ', ' . ($addr['country'] ?? '')) ?></p>
            <?php foreach (CheckoutService::companyLines($addr) as $companyLine): ?><p class="order-company"><?= esc($companyLine) ?></p><?php endforeach; ?>
            <?php endif; ?>
            <?php if (trim((string)($order['notes'] ?? '')) !== ''): ?>
            <h4 style="margin-top:.75rem"><?= esc(I18n::t('admin.order.note')) ?></h4>
            <p data-order-note style="white-space:pre-wrap"><?= esc((string)$order['notes']) ?></p>
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
                <input name="note" placeholder="<?= esc(I18n::t('admin.order.note_placeholder')) ?>">
                <button class="btn btn-primary btn-sm" type="submit"><?= esc(I18n::t('admin.order.change_status')) ?></button>
            </form>
            <?php else: ?>
            <p class="muted"><?= esc(I18n::t('admin.order.final_status')) ?></p>
            <?php endif; ?>
            <p class="muted" style="margin-top:.5rem"><?= esc(I18n::t('admin.order.payment_method')) ?>: <?= esc((string)($paymentSnapshot['label'] ?? $order['payment_method'])) ?> <span class="muted"><?= esc((string)($paymentSnapshot['code'] ?? $order['payment_method'])) ?><?= !empty($paymentSnapshot['provider']) ? ' · ' . esc((string)$paymentSnapshot['provider']) : '' ?></span></p>
            <?php if ((string)($paymentSnapshot['service_type'] ?? $order['payment_method']) === 'bank_transfer'): ?>
            <p class="muted"><?= esc(I18n::t('admin.order.variable_symbol')) ?>: <strong data-variable-symbol><?= esc(BankTransferInstructions::variableSymbol($order)) ?></strong></p>
            <?php endif; ?>
            <p class="muted"><?= esc(I18n::t('admin.order.payment_reference')) ?>: <?= $order['payment_id'] ? esc($order['payment_id']) : '—' ?></p>
            <p class="muted"><?= esc(I18n::t('admin.sales.payment_status')) ?>: <span class="badge badge-<?= esc($order['payment_status'] ?? 'pending') ?>"><?= esc($order['payment_status'] ?? 'pending') ?></span><?= $latestPaymentTx ? ' · ' . esc(I18n::t('admin.order.last_event')) . ': ' . esc((string)$latestPaymentTx['status']) . ' @ ' . esc(substr((string)$latestPaymentTx['created_at'], 0, 16)) : '' ?></p>
            <?php if (!empty($paymentTransitions)): ?>
            <form method="post" action="admin.php?p=order&id=<?= $id ?>&action=payment_transition" style="margin-top:.5rem">
                <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                <select name="new_payment_status">
                    <?php foreach ($paymentTransitions as $s): ?>
                    <option value="<?= esc($s) ?>"><?= esc($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <input name="amount" type="number" min="0" step="1" placeholder="<?= esc(I18n::t('admin.order.amount_cents')) ?>">
                <input name="note" placeholder="<?= esc(I18n::t('admin.order.note_placeholder')) ?>">
                <button class="btn btn-primary btn-sm" type="submit"><?= esc(I18n::t('admin.order.change_payment')) ?></button>
            </form>
            <?php endif; ?>
            <?php if ($canRefund && $refundableBalance > 0 && PaymentService::canPaymentTransition((string)($order['payment_status'] ?? 'pending'), 'refund_pending')): ?>
            <form method="post" action="admin.php?p=order&id=<?= $id ?>&action=refund_request" style="margin-top:.5rem">
                <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                <input name="amount" type="number" min="1" max="<?= $refundableBalance ?>" step="1" value="<?= $refundableBalance ?>">
                <input name="note" placeholder="<?= esc(I18n::t('admin.order.refund_reason')) ?>" required>
                <button class="btn btn-danger btn-sm" type="submit"><?= esc(I18n::t('admin.order.request_refund')) ?></button>
            </form>
            <?php else: ?>
            <p class="muted" style="margin-top:.5rem"><?= esc(I18n::t('admin.order.refund_unavailable')) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h3><?= esc(I18n::t('admin.sales.items')) ?></h3>
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
            <p><?= esc(I18n::t('admin.order.subtotal')) ?>: <strong><?= I18n::price((int)$order['subtotal'], $order['currency']) ?></strong></p>
            <p><?= esc(I18n::t('admin.order.shipping')) ?>: <?= I18n::price((int)$order['shipping_cost'], $order['currency']) ?></p>
            <?php $pickupPoint = json_decode((string)($order['shipping_rate_snapshot'] ?? ''), true)['pickup_point'] ?? null; if (is_array($pickupPoint)): ?>
            <p><?= esc(I18n::t('pickup_point')) ?>: <?= esc((string)($pickupPoint['name'] ?? '')) ?> (ID <?= esc((string)($pickupPoint['id'] ?? '')) ?>) — <?= esc((string)($pickupPoint['address'] ?? '')) ?></p>
            <?php endif; ?>
            <?php if ((int)($order['payment_fee'] ?? 0) > 0): ?>
            <p><?= esc(I18n::t('admin.order.payment_fee')) ?>: <?= I18n::price((int)$order['payment_fee'], $order['currency']) ?></p>
            <?php endif; ?>
            <?php if ((int)$order['tax_amount'] > 0): ?>
            <p><?= esc(I18n::t('admin.order.vat')) ?>: <?= I18n::price((int)$order['tax_amount'], $order['currency']) ?></p>
            <?php endif; ?>
            <p style="font-size:1.2rem"><strong><?= esc(I18n::t('admin.order.grand_total')) ?>: <?= I18n::price((int)$order['total'], $order['currency']) ?></strong></p>
        </div>
    </div>

    <?php renderOrderShipments($order); ?>

    <?php if ($digitalEntitlements): ?>
    <div class="card">
        <h3><?= esc(I18n::t('admin.order.entitlements')) ?></h3>
        <table>
            <tr><th><?= esc(I18n::t('admin.th.sku')) ?></th><th><?= esc(I18n::t('admin.order.file')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.order.downloads')) ?></th><th><?= esc(I18n::t('admin.order.expires')) ?></th><th><?= esc(I18n::t('admin.order.delivered')) ?></th></tr>
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
        <h3><?= esc(I18n::t('admin.order.consents')) ?></h3>
        <table>
            <tr><th><?= esc(I18n::t('admin.order.consent_key')) ?></th><th><?= esc(I18n::t('admin.order.version')) ?></th><th><?= esc(I18n::t('admin.order.wording')) ?></th><th><?= esc(I18n::t('admin.order.time')) ?></th></tr>
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
        <h3><?= esc(I18n::t('admin.order.payment_timeline')) ?></h3>
        <?php if ($paymentTxs): ?>
        <table>
            <tr><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.common.provider')) ?></th><th>Ref</th><th><?= esc(I18n::t('admin.th.amount')) ?></th><th><?= esc(I18n::t('admin.common.event')) ?></th><th><?= esc(I18n::t('admin.order.details')) ?></th><th><?= esc(I18n::t('admin.common.when')) ?></th></tr>
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
        <p class="muted"><?= esc(I18n::t('admin.order.no_payment_events')) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($logs): ?>
    <div class="card">
        <h3><?= esc(I18n::t('admin.order.history')) ?></h3>
        <table>
            <tr><th><?= esc(I18n::t('admin.common.action')) ?></th><th><?= esc(I18n::t('admin.order.from')) ?></th><th><?= esc(I18n::t('admin.order.to')) ?></th><th><?= esc(I18n::t('admin.common.note')) ?></th><th><?= esc(I18n::t('admin.common.who')) ?></th><th><?= esc(I18n::t('admin.common.when')) ?></th></tr>
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
        <h3><?= esc(I18n::t('admin.order.emails')) ?></h3>
        <table>
            <tr><th><?= esc(I18n::t('admin.common.template')) ?></th><th><?= esc(I18n::t('admin.order.locale')) ?></th><th><?= esc(I18n::t('admin.order.subject')) ?></th><th><?= esc(I18n::t('admin.order.adapter')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.order.sent')) ?></th><th></th></tr>
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
                        <button class="btn btn-sm" type="submit"><?= esc(I18n::t('admin.order.resend')) ?></button>
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
        <h3><?= esc(I18n::t('admin.order.invoices')) ?></h3>
        <?php if ($invoices): ?>
        <table>
            <tr><th><?= esc(I18n::t('admin.common.number')) ?></th><th><?= esc(I18n::t('admin.common.type')) ?></th><th><?= esc(I18n::t('admin.order.vat_scenario')) ?></th><th><?= esc(I18n::t('admin.order.issued')) ?></th><th><?= esc(I18n::t('admin.order.due')) ?></th><th><?= esc(I18n::t('admin.order.grand_total')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.th.actions')) ?></th></tr>
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
                        <button class="btn btn-sm" type="submit"><?= esc(I18n::t('admin.order.resend')) ?></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php else: ?>
        <p class="muted"><?= esc(I18n::t('admin.order.no_invoices')) ?></p>
        <?php endif; ?>
        <?php if (InvoiceService::canIssue($order)): ?>
        <form method="post" action="admin.php?p=order&id=<?= $id ?>&action=invoice_issue" style="display:inline;margin-top:.5rem">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <button class="btn btn-primary btn-sm" type="submit"><?= esc(I18n::t('admin.order.issue_invoice')) ?></button>
        </form>
        <?php endif; ?>
    </div>

    <a href="admin.php?p=orders" class="btn">← <?= esc(I18n::t('admin.common.back_to_list')) ?></a>
    <?php
}
function pageInvoicePdf(): void {
    Security::adminRequire('orders.view');
    $id = Security::int($_GET['id'] ?? 0);
    $inv = InvoiceService::byId($id);
    if (!$inv) { http_response_code(404); echo esc(I18n::t('admin.order.msg.invoice_missing')); return; }
    $abs = InvoiceService::pdfAbsolutePath($inv);
    if (!is_file($abs)) { http_response_code(404); echo esc(I18n::t('admin.order.msg.pdf_missing')); return; }
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
        'requested' => ['approved' => I18n::t('admin.rma.approve'), 'rejected' => I18n::t('admin.rma.reject')],
        'approved'  => ['received' => I18n::t('admin.rma.receive'), 'rejected' => I18n::t('admin.rma.reject')],
        'received'  => Security::adminCan('orders.refund') ? ['refunded' => I18n::t('admin.rma.refund')] : [],
    ];
    ?>
    <h1><?= esc(I18n::t('admin.rma.heading')) ?> <span class="muted">(<?= count($rmas) ?>)</span></h1>
    <div class="card">
        <p>
            <a class="btn btn-sm<?= $filter===null?' btn-primary':'' ?>" href="admin.php?p=rma"><?= esc(I18n::t('admin.common.all')) ?></a>
            <?php foreach (Rma::STATUSES as $s): ?>
            <a class="btn btn-sm<?= $filter===$s?' btn-primary':'' ?>" href="admin.php?p=rma&status=<?= esc($s) ?>"><?= esc($s) ?></a>
            <?php endforeach; ?>
        </p>
        <?php if (!$rmas): ?>
        <p class="muted"><?= esc(I18n::t('admin.rma.none')) ?></p>
        <?php else: ?>
        <table>
            <tr><th>RMA</th><th><?= esc(I18n::t('admin.order.label')) ?></th><th><?= esc(I18n::t('admin.sales.items')) ?></th><th><?= esc(I18n::t('admin.rma.reason')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.common.action')) ?></th></tr>
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
                        <select name="disposition" aria-label="<?= esc(I18n::t('admin.rma.disposition')) ?>">
                            <option value="restock"><?= esc(I18n::t('admin.rma.restock')) ?></option>
                            <option value="repair"><?= esc(I18n::t('admin.rma.repair')) ?></option>
                            <option value="discard"><?= esc(I18n::t('admin.rma.discard')) ?></option>
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
    Security::adminRequire('rma.manage');
    $id = Security::int($_POST['rma_id'] ?? 0);
    $status = Security::clean($_POST['status'] ?? '');
    // Refunding an RMA issues a credit note and a refund — a money decision, not catalogue or warehouse work.
    if ($status === 'refunded') Security::adminRequire('orders.refund');
    try {
        if ($id <= 0) throw new InvalidArgumentException(I18n::t('admin.rma.invalid'));
        if ($status === 'received') {
            $disposition = Security::clean($_POST['disposition'] ?? '');
            if (!in_array($disposition, ['restock', 'repair', 'discard'], true)) throw new InvalidArgumentException(I18n::t('admin.rma.invalid_disposition'));
            $decisions = [];
            foreach (Rma::items($id) as $item) {
                $remaining = (int)$item['quantity'] - (int)$item['received_quantity'];
                if ($remaining > 0) $decisions[(int)$item['id']] = ['quantity' => $remaining, 'disposition' => $disposition];
            }
            if (!$decisions || !Rma::receive($id, $decisions, 'admin-receive-' . $id, 'Admin receipt')) {
                throw new RuntimeException(I18n::t('admin.rma.already_received'));
            }
        } elseif ($status === 'refunded') {
            $rma = Rma::byId($id);
            if (!$rma) throw new RuntimeException(I18n::t('admin.rma.not_found'));
            $source = InvoiceService::byOrderId((int)$rma['order_id']);
            if (!$source) throw new RuntimeException(I18n::t('admin.rma.no_source_invoice'));
            $quantities = InvoiceService::creditableQuantities($id);
            if (!$quantities) throw new LogicException(I18n::t('admin.rma.nothing_to_credit'));
            // One key per attempt: a concurrent double submit shares it, a retry after a failed refund gets a new credit note.
            $credit = InvoiceService::credit((int)$source['id'], $id, $quantities, 'admin-credit-' . $id . '-' . InvoiceService::creditAttempts($id));
            $order = Orders::byId((int)$rma['order_id']);
            if (trim((string)($order['payment_id'] ?? '')) === '') {
                Rma::completeRefund((int)$credit['refund_id'], 'manual-admin');
            }
        } elseif (!Rma::setStatus($id, $status)) {
            throw new LogicException(I18n::t('admin.rma.transition_refused'));
        }
        setFlash('ok', I18n::format('admin.rma.status_changed', ['id' => $id, 'status' => $status]));
    } catch (Throwable $e) {
        setFlash('err', I18n::format('admin.rma.status_failed', ['error' => $e->getMessage()]));
    }
    header('Location: admin.php?p=rma');
    exit;
}
function handleOrderStatus(): void {
    Security::adminRequire('orders.edit');
    $orderId = Security::int($_GET['id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    $note = trim(Security::str($_POST['note'] ?? ''));

    if (!is_string($newStatus) || !in_array($newStatus, OrderWorkflow::statuses(), true)) {
        setFlash('err', I18n::t('admin.order.msg.invalid_status'));
        header("Location: admin.php?p=order&id={$orderId}");
        exit;
    }
    if ($newStatus === 'refunded') Security::adminRequire('orders.refund');
    $rejection = OrderWorkflow::adminRejection(Orders::byId($orderId), $newStatus, true);
    if (in_array($rejection, ['not_found', 'payment_not_confirmed'], true)) {
        setFlash('err', I18n::t($rejection === 'payment_not_confirmed' ? 'admin.order.msg.confirm_payment_first' : 'admin.order.msg.not_found'));
        header("Location: admin.php?p=order&id={$orderId}");
        exit;
    }

    try {
        OrderWorkflow::transition($orderId, $newStatus, $note ?: 'Admin status change');
        setFlash('ok', I18n::format('admin.order.msg.status_changed', ['status' => $newStatus]));
    } catch (\LogicException $e) {
        setFlash('err', I18n::t('admin.order.msg.invalid_transition'));
    } catch (\Throwable $e) {
        error_log("Order status change error [{$orderId}]: " . $e->getMessage());
        setFlash('err', I18n::t('admin.order.msg.status_error'));
    }

    header("Location: admin.php?p=order&id={$orderId}");
    exit;
}
/**
 * Payment states an admin may set by hand. Refunds go through the refund ledger. An open order's
 * payment is cancelled only by cancelling the order: a cancelled payment on a pending order would
 * leave the expiry sweep and keep its stock reserved for ever.
 */
function adminManualPaymentTransitions(array $order): array {
    $orderCancelled = (string)($order['status'] ?? '') === 'cancelled';
    return array_values(array_filter(
        PaymentService::allowedPaymentTransitions((string)($order['payment_status'] ?? 'pending')),
        static fn(string $to): bool => !in_array($to, ['refund_pending', 'partially_refunded', 'refunded'], true)
            && ($to !== 'cancelled' || $orderCancelled)
    ));
}
function handleOrderPaymentTransition(): void {
    Security::adminRequire('orders.payments');
    $orderId = Security::int($_GET['id'] ?? 0);
    $order = Orders::byId($orderId);
    if (!$order) {
        setFlash('err', I18n::t('admin.order.msg.not_found'));
        header('Location: admin.php?p=orders');
        exit;
    }

    $newStatus = trim((string)($_POST['new_payment_status'] ?? ''));
    $amount = max(0, Security::int($_POST['amount'] ?? 0));
    $note = trim((string)($_POST['note'] ?? ''));

    if (!in_array($newStatus, adminManualPaymentTransitions($order), true)) {
        setFlash('err', I18n::t('admin.order.msg.invalid_payment_transition'));
        header("Location: admin.php?p=order&id={$orderId}");
        exit;
    }

    try {
        $key = 'admin_payment_' . $orderId . '_' . $newStatus . '_' . time();
        PaymentService::transitionPayment($orderId, $newStatus, 'admin.' . $newStatus, $amount, $key, [
            'actor' => Security::adminUser(),
            'note' => $note,
        ]);
        if ($newStatus === 'paid' && (string)$order['status'] === 'cancelled') {
            // Money recorded by hand for a cancelled order: no gateway session captured it, so the recorded
            // amount (the order total when none was entered) is flagged for a manual refund with an incident.
            PaymentService::settleAdminPaymentOnCancelled($orderId, $amount > 0 ? $amount : (int)$order['total'], 'Admin payment: ' . $note);
            setFlash('err', I18n::t('admin.order.msg.paid_on_cancelled'));
        } else {
            setFlash('ok', I18n::format('admin.order.msg.payment_changed', ['status' => $newStatus]));
        }
    } catch (\Throwable $e) {
        error_log("Payment status change error [{$orderId}]: " . $e->getMessage());
        setFlash('err', I18n::t('admin.order.msg.payment_error'));
    }

    header("Location: admin.php?p=order&id={$orderId}");
    exit;
}
function handleOrderRefundRequest(): void {
    Security::adminRequire('orders.refund');
    $orderId = Security::int($_GET['id'] ?? 0);
    $order = Orders::byId($orderId);
    if (!$order) {
        setFlash('err', I18n::t('admin.order.msg.not_found'));
        header('Location: admin.php?p=orders');
        exit;
    }

    $amount = Security::int($_POST['amount'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? $_POST['note'] ?? ''));
    $refundableBalance = PaymentService::refundableBalance($orderId);
    if ($amount <= 0 || $amount > $refundableBalance || $reason === '') {
        setFlash('err', I18n::t('admin.order.msg.refund_amount'));
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
        setFlash('ok', I18n::t('admin.order.msg.refund_requested'));
    } catch (\Throwable $e) {
        error_log("Refund request error [{$orderId}]: " . $e->getMessage());
        setFlash('err', I18n::t('admin.order.msg.refund_error'));
    }

    header("Location: admin.php?p=order&id={$orderId}");
    exit;
}
function handleOrderEmailResend(): void {
    Security::adminRequire('orders.edit');
    $orderId = Security::int($_GET['id'] ?? 0);
    $logId   = Security::int($_POST['log_id'] ?? 0);
    if ($logId <= 0 || !Orders::byId($orderId) || !Security::rateLimit('admin_email_resend_' . $logId, 3, 3600, false)) {
        setFlash('err', I18n::t('admin.order.msg.email_resend_refused'));
        header("Location: admin.php?p=order&id={$orderId}");
        exit;
    }
    try {
        $res = MailService::resend($logId, $orderId);
        if (($res['status'] ?? '') === 'sent') {
            setFlash('ok', I18n::format('admin.order.msg.email_resent', ['adapter' => $res['adapter'] ?? '']));
        } else {
            setFlash('err', I18n::format('admin.order.msg.email_failed', ['error' => $res['error'] ?? $res['status'] ?? I18n::t('admin.order.msg.unknown_error')]));
        }
    } catch (\Throwable $e) {
        error_log("Resend email #{$logId} failed: " . $e->getMessage());
        setFlash('err', I18n::t('admin.order.msg.email_resend_error'));
    }
    header("Location: admin.php?p=order&id={$orderId}");
    exit;
}
function handleOrderInvoiceIssue(): void {
    Security::adminRequire('orders.edit');
    $orderId = Security::int($_GET['id'] ?? 0);
    // Same rule as the button: no invoice for an order that is not paid (InvoiceService::issue() refuses it too).
    $unpaid = $orderId > 0 && ($order = Orders::byId($orderId)) && !InvoiceService::byOrderId($orderId) && !InvoiceService::canIssue($order);
    if ($orderId <= 0 || $unpaid || !Security::rateLimit('admin_invoice_issue_' . $orderId, 3, 3600, false)) {
        setFlash('err', I18n::t($unpaid ? 'admin.order.msg.invoice_unpaid' : 'admin.order.msg.invoice_issue_refused'));
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
        setFlash('ok', I18n::format('admin.order.msg.invoice_issued', ['number' => $inv['number']]));
    } catch (\Throwable $e) {
        error_log("Invoice issue [{$orderId}]: " . $e->getMessage());
        setFlash('err', I18n::format('admin.order.msg.invoice_issue_failed', ['error' => $e->getMessage()]));
    }
    header("Location: admin.php?p=order&id={$orderId}");
    exit;
}
function handleOrderInvoiceResend(): void {
    Security::adminRequire('orders.edit');
    $orderId   = Security::int($_GET['id'] ?? 0);
    $invoiceId = Security::int($_POST['invoice_id'] ?? 0);
    if ($invoiceId <= 0 || !Security::rateLimit('admin_invoice_resend_' . $invoiceId, 3, 3600, false)) {
        setFlash('err', I18n::t('admin.order.msg.invoice_resend_refused'));
        header("Location: admin.php?p=order&id={$orderId}");
        exit;
    }
    $stmt = Db::get()->prepare("SELECT * FROM invoices WHERE id = :id AND order_id = :order_id AND store_id = :store");
    $stmt->execute([':id' => $invoiceId, ':order_id' => $orderId, ':store' => StoreContext::current()->id()]);
    $inv = $stmt->fetch();
    if (!$inv || !Orders::byId($orderId)) {
        setFlash('err', I18n::t('admin.order.msg.invoice_missing'));
        header("Location: admin.php?p=order&id={$orderId}");
        exit;
    }
    try {
        $order = Orders::byId((int)$inv['order_id']);
        $abs = InvoiceService::pdfAbsolutePath($inv);
        if (!is_file($abs)) {
            setFlash('err', I18n::t('admin.order.msg.pdf_missing'));
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
            setFlash('ok', I18n::t('admin.order.msg.invoice_resent'));
        } else {
            setFlash('err', I18n::format('admin.order.msg.email_failed', ['error' => $res['error'] ?? $res['status'] ?? I18n::t('admin.order.msg.unknown_error')]));
        }
    } catch (\Throwable $e) {
        error_log("Invoice resend [{$invoiceId}]: " . $e->getMessage());
        setFlash('err', I18n::format('admin.order.msg.send_error', ['error' => $e->getMessage()]));
    }
    header("Location: admin.php?p=order&id={$orderId}");
    exit;
}
