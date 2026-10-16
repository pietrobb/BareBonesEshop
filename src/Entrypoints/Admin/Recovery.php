<?php
/** Revenue recovery admin: bank transfers awaiting payment and abandoned carts. */

function pageAwaitingPayment(): void {
    $orders = OfflinePaymentQueue::awaiting();
    $canEdit = Security::adminCan('orders.payments');
    ?>
    <h1><?= esc(I18n::t('admin.title.awaiting_payment')) ?> <span class="muted">(<?= count($orders) ?>)</span></h1>
    <div class="card table-scroll">
        <?php if (!$orders): ?>
        <p class="muted"><?= esc(I18n::t('admin.recovery.no_transfers')) ?></p>
        <?php else: ?>
        <table>
            <tr><th><?= esc(I18n::t('admin.order.label')) ?></th><th><?= esc(I18n::t('admin.recovery.variable_symbol')) ?></th><th><?= esc(I18n::t('admin.th.customer')) ?></th><th><?= esc(I18n::t('admin.th.amount')) ?></th><th><?= esc(I18n::t('admin.order.payment')) ?></th><th><?= esc(I18n::t('admin.recovery.created')) ?></th><th><?= esc(I18n::t('admin.recovery.age')) ?></th><th></th></tr>
            <?php foreach ($orders as $o): ?>
            <?php $age = (int)$o['age_hours']; ?>
            <tr>
                <td><a href="admin.php?p=order&id=<?= (int)$o['id'] ?>"><?= esc($o['order_number']) ?></a></td>
                <td data-variable-symbol><?= esc(BankTransferInstructions::variableSymbol($o)) ?></td>
                <td><?= esc($o['customer_name']) ?><br><span class="muted"><?= esc($o['customer_email']) ?></span></td>
                <td><?= I18n::price((int)$o['total'], $o['currency']) ?></td>
                <td class="muted"><?= esc($o['payment_label']) ?></td>
                <td class="muted"><?= esc(substr((string)$o['created_at'], 0, 16)) ?></td>
                <td class="<?= $age >= 72 ? 'status-error' : ($age >= 24 ? 'status-warn' : '') ?>"><?= $age < 48 ? $age . ' h' : intdiv($age, 24) . ' d' ?></td>
                <td>
                    <?php if ($canEdit): ?>
                    <form method="post" action="admin.php?p=awaiting_payment&action=confirm" data-confirm="<?= esc(I18n::format('admin.recovery.confirm_paid', ['number' => $o['order_number']])) ?>">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                        <input name="note" placeholder="<?= esc(I18n::t('admin.recovery.note_placeholder')) ?>" style="width:10rem">
                        <button class="btn btn-sm btn-primary" type="submit"><?= esc(I18n::t('admin.recovery.mark_paid')) ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
    <?php
}

function pageAbandonedCarts(): void {
    $filter = in_array($_GET['filter'] ?? '', ['converted', 'all'], true) ? $_GET['filter'] : 'abandoned';
    $carts = AbandonedCarts::list($filter);
    $statusLabels = [
        AbandonedCarts::STATUS_SENT => I18n::t('admin.recovery.reminder_sent'),
        AbandonedCarts::STATUS_ORDERED => I18n::t('admin.recovery.ordered'),
        AbandonedCarts::STATUS_SUPPRESSED => I18n::t('admin.recovery.suppressed'),
    ];
    ?>
    <h1><?= esc(I18n::t('admin.title.abandoned_carts')) ?> <span class="muted">(<?= count($carts) ?>)</span></h1>
    <p style="margin-bottom:1rem">
        <?php foreach (['abandoned' => I18n::t('admin.recovery.filter_abandoned'), 'converted' => I18n::t('admin.recovery.filter_converted'), 'all' => I18n::t('admin.common.all')] as $key => $label): ?>
        <a href="admin.php?p=abandoned_carts&filter=<?= $key ?>" class="btn btn-sm<?= $filter === $key ? ' btn-primary' : '' ?>"><?= esc($label) ?></a>
        <?php endforeach; ?>
    </p>
    <div class="card table-scroll">
        <?php if (!$carts): ?>
        <p class="muted"><?= esc(I18n::t('admin.recovery.no_carts')) ?></p>
        <?php else: ?>
        <table>
            <tr><th><?= esc(I18n::t('admin.common.email')) ?></th><th><?= esc(I18n::t('admin.sales.items')) ?></th><th><?= esc(I18n::t('admin.recovery.value')) ?></th><th><?= esc(I18n::t('admin.common.created')) ?></th><th><?= esc(I18n::t('admin.recovery.updated')) ?></th><th><?= esc(I18n::t('admin.recovery.reminder')) ?></th><th><?= esc(I18n::t('admin.order.label')) ?></th></tr>
            <?php foreach ($carts as $c): ?>
            <tr>
                <td><?= esc((string)$c['customer_email']) ?></td>
                <td><?php foreach ($c['items'] as $item): ?><?= (int)($item['qty'] ?? 1) ?> × <?= esc((string)$item['name']) ?><br><?php endforeach; ?></td>
                <td><?= I18n::price((int)($c['cart_value'] ?? 0), (string)($c['currency'] ?? '') ?: null) ?></td>
                <td class="muted"><?= esc(substr((string)$c['created_at'], 0, 16)) ?></td>
                <td class="muted"><?= esc(substr((string)($c['updated_at'] ?? $c['created_at']), 0, 16)) ?></td>
                <td><?= esc($statusLabels[(string)($c['reminder_status'] ?? '')] ?? I18n::t('admin.recovery.waiting')) ?><?php if (!empty($c['reminded_at'])): ?><br><span class="muted"><?= esc(substr((string)$c['reminded_at'], 0, 16)) ?></span><?php endif; ?></td>
                <td><?php if (!empty($c['order_id'])): ?><a href="admin.php?p=order&id=<?= (int)$c['order_id'] ?>">#<?= (int)$c['order_id'] ?></a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
    <?php
}

function handleAwaitingPaymentConfirm(): void {
    Security::adminRequire('orders.payments');
    $orderId = Security::int($_POST['id'] ?? 0);
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200);
    try {
        $recorded = OfflinePaymentQueue::confirm($orderId, Security::adminUser(), $note);
        if ((string)(Orders::byId($orderId)['status'] ?? '') === 'cancelled') {
            // The order expired or was cancelled meanwhile: it stays cancelled and the money must go back.
            setFlash('err', I18n::t('admin.order.msg.paid_on_cancelled'));
        } else {
            setFlash($recorded ? 'ok' : 'err', $recorded
                ? I18n::t('admin.recovery.confirmed')
                : I18n::t('admin.recovery.already_paid'));
        }
    } catch (\Throwable $e) {
        error_log("Offline payment confirm error [{$orderId}]: " . $e->getMessage());
        setFlash('err', I18n::t('admin.recovery.confirm_failed'));
    }
    header('Location: admin.php?p=awaiting_payment');
    exit;
}
