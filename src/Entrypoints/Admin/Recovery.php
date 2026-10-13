<?php
/** Revenue recovery admin: bank transfers awaiting payment and abandoned carts. */

function pageAwaitingPayment(): void {
    $orders = OfflinePaymentQueue::awaiting();
    $canEdit = Security::adminCan('orders.payments');
    ?>
    <h1>Čaká na platbu <span class="muted">(<?= count($orders) ?>)</span></h1>
    <div class="card table-scroll">
        <?php if (!$orders): ?>
        <p class="muted">Žiadne nezaplatené prevody.</p>
        <?php else: ?>
        <table>
            <tr><th>Objednávka</th><th>Zákazník</th><th>Suma</th><th>Platba</th><th>Vytvorená</th><th>Vek</th><th></th></tr>
            <?php foreach ($orders as $o): ?>
            <?php $age = (int)$o['age_hours']; ?>
            <tr>
                <td><a href="admin.php?p=order&id=<?= (int)$o['id'] ?>"><?= esc($o['order_number']) ?></a></td>
                <td><?= esc($o['customer_name']) ?><br><span class="muted"><?= esc($o['customer_email']) ?></span></td>
                <td><?= I18n::price((int)$o['total'], $o['currency']) ?></td>
                <td class="muted"><?= esc($o['payment_label']) ?></td>
                <td class="muted"><?= esc(substr((string)$o['created_at'], 0, 16)) ?></td>
                <td class="<?= $age >= 72 ? 'status-error' : ($age >= 24 ? 'status-warn' : '') ?>"><?= $age < 48 ? $age . ' h' : intdiv($age, 24) . ' d' ?></td>
                <td>
                    <?php if ($canEdit): ?>
                    <form method="post" action="admin.php?p=awaiting_payment&action=confirm" data-confirm="Označiť objednávku <?= esc($o['order_number']) ?> ako zaplatenú?">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                        <input name="note" placeholder="poznámka (napr. výpis)" style="width:10rem">
                        <button class="btn btn-sm btn-primary" type="submit">Označiť ako zaplatené</button>
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
        AbandonedCarts::STATUS_SENT => 'pripomienka odoslaná',
        AbandonedCarts::STATUS_ORDERED => 'objednal',
        AbandonedCarts::STATUS_SUPPRESSED => 'odhlásený',
    ];
    ?>
    <h1>Opustené košíky <span class="muted">(<?= count($carts) ?>)</span></h1>
    <p style="margin-bottom:1rem">
        <?php foreach (['abandoned' => 'Opustené', 'converted' => 'Konvertované', 'all' => 'Všetky'] as $key => $label): ?>
        <a href="admin.php?p=abandoned_carts&filter=<?= $key ?>" class="btn btn-sm<?= $filter === $key ? ' btn-primary' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </p>
    <div class="card table-scroll">
        <?php if (!$carts): ?>
        <p class="muted">Žiadne košíky.</p>
        <?php else: ?>
        <table>
            <tr><th>E-mail</th><th>Položky</th><th>Hodnota</th><th>Vytvorený</th><th>Posledná zmena</th><th>Pripomienka</th><th>Objednávka</th></tr>
            <?php foreach ($carts as $c): ?>
            <tr>
                <td><?= esc((string)$c['customer_email']) ?></td>
                <td><?php foreach ($c['items'] as $item): ?><?= (int)($item['qty'] ?? 1) ?> × <?= esc((string)$item['name']) ?><br><?php endforeach; ?></td>
                <td><?= I18n::price((int)($c['cart_value'] ?? 0), (string)($c['currency'] ?? '') ?: null) ?></td>
                <td class="muted"><?= esc(substr((string)$c['created_at'], 0, 16)) ?></td>
                <td class="muted"><?= esc(substr((string)($c['updated_at'] ?? $c['created_at']), 0, 16)) ?></td>
                <td><?= esc($statusLabels[(string)($c['reminder_status'] ?? '')] ?? 'čaká') ?><?php if (!empty($c['reminded_at'])): ?><br><span class="muted"><?= esc(substr((string)$c['reminded_at'], 0, 16)) ?></span><?php endif; ?></td>
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
        setFlash($recorded ? 'ok' : 'err', $recorded
            ? 'Platba zaznamenaná, objednávka je zaplatená a doručenie bolo spustené.'
            : 'Objednávka už bola označená ako zaplatená.');
    } catch (\Throwable $e) {
        error_log("Offline payment confirm error [{$orderId}]: " . $e->getMessage());
        setFlash('err', 'Platbu sa nepodarilo označiť.');
    }
    header('Location: admin.php?p=awaiting_payment');
    exit;
}
