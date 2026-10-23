<?php
/** A12.8: shipments and tracking in the admin order detail (partial shipments through Fulfillment). */

/** The consumer's withdrawal statements (storefront withdrawal form): received when, for which lines, why. */
function renderOrderWithdrawals(array $order, array $withdrawals): void {
    if (!$withdrawals) return;
    $names = array_column(Orders::items((int)$order['id']), 'name', 'id');
    ?>
    <div class="card" id="withdrawals" data-withdrawals style="border-left:4px solid #c62828">
        <h3><?= esc(I18n::t('admin.withdrawal.heading')) ?></h3>
        <p><?= esc(I18n::t('admin.withdrawal.notice')) ?></p>
        <table>
            <tr><th><?= esc(I18n::t('admin.withdrawal.received_at')) ?></th><th><?= esc(I18n::t('admin.sales.items')) ?></th><th><?= esc(I18n::t('admin.withdrawal.reason')) ?></th><th><?= esc(I18n::t('admin.withdrawal.confirmation')) ?></th></tr>
            <?php foreach ($withdrawals as $withdrawal): ?>
            <tr>
                <td><?= esc((string)$withdrawal['received_at']) ?> UTC</td>
                <td><ul class="muted" style="margin:0;padding-left:16px">
                    <?php foreach ((array)(json_decode((string)$withdrawal['items'], true) ?: []) as $itemId => $qty): ?>
                    <li><?= esc((string)($names[(int)$itemId] ?? ('#' . (int)$itemId))) ?> (<?= (int)$qty ?>×)</li>
                    <?php endforeach; ?>
                </ul></td>
                <td><?= esc((string)($withdrawal['reason'] ?? '')) ?: '—' ?></td>
                <td class="muted"><?= esc((string)($withdrawal['confirmation_status'] ?? '')) ?: '—' ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php
}

/** Shipping goods the consumer withdrew from needs an explicit acknowledgement on the form. */
function adminShipmentWithdrawalCheck(int $orderId): void {
    if (($_POST['withdrawal_ack'] ?? '') !== '1' && Withdrawal::forOrder($orderId)) throw new DomainException('admin.shipment.msg.withdrawal_ack');
}

function renderOrderShipments(array $order): void {
    $orderId = (int)$order['id'];
    $withdrawals = Withdrawal::forOrder($orderId);
    $withdrawalAck = $withdrawals ? '<label style="display:block;margin:.25rem 0;color:#c62828"><input type="checkbox" name="withdrawal_ack" value="1" data-withdrawal-ack> ' . esc(I18n::t('admin.shipment.withdrawal_ack')) . '</label>' : '';
    renderOrderWithdrawals($order, $withdrawals);
    $shipments = Fulfillment::shipments($orderId);
    $shipmentItems = Fulfillment::shipmentItems(array_column($shipments, 'id'));
    $names = array_column(Orders::items($orderId), 'name', 'id');
    $canShip = Security::adminCan('orders.ship');
    $open = $canShip && Fulfillment::orderShippable($order);
    $groups = $open ? array_values(array_filter(Fulfillment::shippable($orderId),
        static fn(array $g): bool => array_sum(array_column($g['lines'], 'remaining')) > 0)) : [];
    $warehouses = adminShipmentWarehouseNames();
    $warehouseLabel = static fn(?int $id): string => $id === null ? I18n::t('admin.shipment.no_warehouse') : ($warehouses[$id] ?? ('#' . $id));
    $action = static fn(string $name): string => 'admin.php?p=order&amp;id=' . $orderId . '&amp;action=' . $name;
    $trackingFields = static function(array $shipment = []): void { ?>
        <input name="carrier" maxlength="100" value="<?= esc((string)($shipment['carrier'] ?? '')) ?>" placeholder="<?= esc(I18n::t('admin.shipment.carrier')) ?>" aria-label="<?= esc(I18n::t('admin.shipment.carrier')) ?>">
        <input name="tracking_number" maxlength="100" value="<?= esc((string)($shipment['tracking_number'] ?? '')) ?>" placeholder="<?= esc(I18n::t('admin.shipment.tracking_number')) ?>" aria-label="<?= esc(I18n::t('admin.shipment.tracking_number')) ?>">
        <input name="tracking_url" type="url" maxlength="500" value="<?= esc((string)($shipment['tracking_url'] ?? '')) ?>" aria-label="<?= esc(I18n::t('admin.shipment.tracking_url')) ?>">
    <?php };
    ?>
    <div class="card" id="shipments">
        <h3><?= esc(I18n::t('admin.shipment.heading')) ?></h3>
        <?php if ($shipments): ?>
        <table>
            <tr><th>#</th><th><?= esc(I18n::t('admin.shipment.warehouse')) ?></th><th><?= esc(I18n::t('admin.sales.items')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.shipment.tracking')) ?></th><th><?= esc(I18n::t('admin.shipment.shipped_at')) ?></th><?php if ($canShip): ?><th><?= esc(I18n::t('admin.th.actions')) ?></th><?php endif; ?></tr>
            <?php foreach ($shipments as $shipment): $shipmentId = (int)$shipment['id']; ?>
            <tr data-shipment="<?= $shipmentId ?>">
                <td><?= $shipmentId ?></td>
                <td><?= esc($warehouseLabel($shipment['warehouse_id'] === null ? null : (int)$shipment['warehouse_id'])) ?></td>
                <td><ul class="muted" style="margin:0;padding-left:16px">
                    <?php foreach ($shipmentItems[$shipmentId] ?? [] as $item): ?>
                    <li><?= esc((string)$item['sku']) ?> — <?= esc((string)($names[(int)$item['order_item_id']] ?? '')) ?> (<?= (int)$item['quantity'] ?>×)</li>
                    <?php endforeach; ?>
                </ul></td>
                <td><span class="badge badge-<?= esc((string)$shipment['status']) ?>"><?= esc(I18n::t('admin.shipment.status.' . $shipment['status'])) ?></span></td>
                <td><?= esc(trim((string)$shipment['carrier'] . ' ' . (string)$shipment['tracking_number'])) ?>
                    <?php if (!empty($shipment['tracking_url'])): ?><br><a href="<?= esc((string)$shipment['tracking_url']) ?>" target="_blank" rel="noopener noreferrer"><?= esc(I18n::t('admin.shipment.track')) ?></a><?php endif; ?></td>
                <td class="muted"><?= esc($shipment['shipped_at'] ? substr((string)$shipment['shipped_at'], 0, 16) : '—') ?></td>
                <?php if ($canShip): ?>
                <td>
                    <?php if ($shipment['status'] === 'planned' && $open): ?>
                    <form method="post" action="<?= $action('shipment_ship') ?>">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="shipment_id" value="<?= $shipmentId ?>">
                        <?php $trackingFields(); ?>
                        <?= $withdrawalAck ?>
                        <button class="btn btn-primary btn-sm" type="submit"><?= esc(I18n::t('admin.shipment.ship')) ?></button>
                    </form>
                    <?php endif; ?>
                    <?php if ($shipment['status'] === 'planned'): ?>
                    <form method="post" action="<?= $action('shipment_cancel') ?>" style="margin-top:.25rem">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="shipment_id" value="<?= $shipmentId ?>">
                        <button class="btn btn-danger btn-sm" type="submit"><?= esc(I18n::t('admin.shipment.cancel')) ?></button>
                    </form>
                    <?php elseif ($shipment['status'] === 'shipped'): ?>
                    <form method="post" action="<?= $action('shipment_tracking') ?>">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="shipment_id" value="<?= $shipmentId ?>">
                        <?php $trackingFields($shipment); ?>
                        <button class="btn btn-sm" type="submit"><?= esc(I18n::t('admin.shipment.save_tracking')) ?></button>
                    </form>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php else: ?>
        <p class="muted"><?= esc(I18n::t('admin.shipment.none')) ?></p>
        <?php endif; ?>

        <?php foreach ($groups as $group): ?>
        <form method="post" action="<?= $action('shipment_create') ?>" style="margin-top:.75rem" data-shipment-create>
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <input type="hidden" name="shipment_key" value="<?= bin2hex(random_bytes(16)) ?>">
            <input type="hidden" name="warehouse" value="<?= $group['warehouse_id'] === null ? 'none' : (int)$group['warehouse_id'] ?>">
            <h4><?= esc(I18n::format('admin.shipment.new_from', ['warehouse' => $warehouseLabel($group['warehouse_id'])])) ?></h4>
            <table>
                <tr><th><?= esc(I18n::t('admin.th.sku')) ?></th><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.shipment.remaining')) ?></th><th><?= esc(I18n::t('admin.shipment.quantity')) ?></th></tr>
                <?php foreach ($group['lines'] as $line): if ($line['remaining'] <= 0) continue; $field = 'qty[' . $line['order_item_id'] . ':' . $line['sku'] . ']'; ?>
                <tr>
                    <td><?= esc($line['sku']) ?></td>
                    <td><?= esc($line['name']) ?></td>
                    <td><?= (int)$line['remaining'] ?> / <?= (int)$line['allocated'] ?></td>
                    <td><input name="<?= esc($field) ?>" type="number" min="0" max="<?= (int)$line['remaining'] ?>" step="1" value="<?= (int)$line['remaining'] ?>" aria-label="<?= esc(I18n::t('admin.shipment.quantity') . ' ' . $line['sku']) ?>" style="width:5rem"></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <p style="margin-top:.5rem"><?php $trackingFields(); ?></p>
            <?= $withdrawalAck ?>
            <button class="btn btn-primary btn-sm" type="submit" name="ship_now" value="1"><?= esc(I18n::t('admin.shipment.create_and_ship')) ?></button>
            <button class="btn btn-sm" type="submit"><?= esc(I18n::t('admin.shipment.create_planned')) ?></button>
        </form>
        <?php endforeach; ?>
        <?php if ($canShip && !$open && $order['status'] !== 'shipped'): ?>
        <p class="muted"><?= esc(I18n::t('admin.shipment.not_shippable')) ?></p>
        <?php endif; ?>
    </div>
    <?php
}

/** @return array<int,string> warehouse id => name of the active store's catalog */
function adminShipmentWarehouseNames(): array {
    $stmt = Db::get()->prepare('SELECT id, name FROM warehouses WHERE store_id = :store');
    $stmt->execute([':store' => StoreContext::current()->catalogId()]);
    return array_map('strval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name', 'id'));
}

function handleOrderShipmentCreate(): void {
    Security::adminRequire('orders.ship');
    $orderId = Security::int($_GET['id'] ?? 0);
    adminShipmentAction($orderId, static function(array $order) use ($orderId): string {
        $key = (string)($_POST['shipment_key'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $key)) throw new DomainException('admin.shipment.msg.invalid_form');
        $coreKey = "admin:{$orderId}:{$key}";
        $stmt = Db::get()->prepare('SELECT id FROM shipments WHERE store_id = :store AND idempotency_key = :key');
        $stmt->execute([':store' => StoreContext::current()->id(), ':key' => $coreKey]);
        if ($stmt->fetchColumn() !== false) return 'admin.shipment.msg.already_done';

        $warehouseInput = (string)($_POST['warehouse'] ?? '');
        $group = null;
        foreach (Fulfillment::shippable($orderId) as $candidate) {
            if (($candidate['warehouse_id'] === null ? 'none' : (string)$candidate['warehouse_id']) === $warehouseInput) $group = $candidate;
        }
        $posted = $_POST['qty'] ?? null;
        if ($group === null || !is_array($posted)) throw new DomainException('admin.shipment.msg.invalid_form');
        $lines = [];
        foreach ($group['lines'] as $line) $lines[$line['order_item_id'] . ':' . $line['sku']] = $line;
        $items = [];
        foreach ($posted as $field => $value) {
            $line = $lines[(string)$field] ?? null;
            if ($line === null || !is_string($value) || !preg_match('/^\d{1,6}$/', trim($value))) throw new DomainException('admin.shipment.msg.invalid_form');
            $quantity = (int)trim($value);
            if ($quantity > $line['remaining']) throw new DomainException('admin.shipment.msg.exceeds');
            if ($quantity > 0) $items[] = ['order_item_id' => $line['order_item_id'], 'sku' => $line['sku'], 'quantity' => $quantity];
        }
        if (!$items) throw new DomainException('admin.shipment.msg.empty');

        $shipNow = ($_POST['ship_now'] ?? '') === '1';
        if ($shipNow) adminShipmentWithdrawalCheck($orderId);
        $tracking = adminShipmentTrackingInput();
        $shipmentId = Fulfillment::createShipment($orderId, $group['warehouse_id'], $items, $coreKey);
        if ($shipNow) Fulfillment::ship($shipmentId, ...$tracking);
        AuditLog::log('shipment', $shipmentId, null, [
            'order_id' => $orderId, 'warehouse_id' => $group['warehouse_id'], 'items' => $items,
            'status' => $shipNow ? 'shipped' : 'planned',
        ] + ($shipNow ? array_combine(['carrier', 'tracking_number', 'tracking_url'], $tracking) : []), 'Admin shipment');
        return $shipNow ? 'admin.shipment.msg.shipped' : 'admin.shipment.msg.created';
    });
}

function handleOrderShipmentShip(): void {
    Security::adminRequire('orders.ship');
    $orderId = Security::int($_GET['id'] ?? 0);
    adminShipmentAction($orderId, static function() use ($orderId): string {
        $shipment = adminOrderShipment($orderId, 'planned');
        adminShipmentWithdrawalCheck($orderId);
        $tracking = adminShipmentTrackingInput();
        if (!Fulfillment::ship((int)$shipment['id'], ...$tracking)) return 'admin.shipment.msg.already_done';
        AuditLog::log('shipment', (int)$shipment['id'], ['status' => 'planned'],
            ['status' => 'shipped'] + array_combine(['carrier', 'tracking_number', 'tracking_url'], $tracking), 'Admin shipment shipped');
        return 'admin.shipment.msg.shipped';
    });
}

function handleOrderShipmentTracking(): void {
    Security::adminRequire('orders.ship');
    $orderId = Security::int($_GET['id'] ?? 0);
    adminShipmentAction($orderId, static function() use ($orderId): string {
        $shipment = adminOrderShipment($orderId, 'shipped');
        $tracking = adminShipmentTrackingInput();
        $old = ['carrier' => $shipment['carrier'], 'tracking_number' => $shipment['tracking_number'], 'tracking_url' => $shipment['tracking_url'] ?? null];
        $new = array_combine(['carrier', 'tracking_number', 'tracking_url'], $tracking);
        if ($old === $new) return 'admin.shipment.msg.unchanged';
        Fulfillment::updateTracking((int)$shipment['id'], ...$tracking);
        AuditLog::log('shipment', (int)$shipment['id'], $old, $new, 'Admin shipment tracking');
        return 'admin.shipment.msg.tracking_saved';
    }, false);
}

function handleOrderShipmentCancel(): void {
    Security::adminRequire('orders.ship');
    $orderId = Security::int($_GET['id'] ?? 0);
    adminShipmentAction($orderId, static function() use ($orderId): string {
        $shipment = adminOrderShipment($orderId, 'planned');
        if (!Fulfillment::cancel((int)$shipment['id'])) return 'admin.shipment.msg.already_done';
        AuditLog::log('shipment', (int)$shipment['id'], ['status' => 'planned'], ['status' => 'cancelled'], 'Admin shipment cancelled');
        return 'admin.shipment.msg.cancelled';
    }, false);
}

/** Run one shipment change in a transaction; DomainException messages are i18n keys shown to the admin. */
function adminShipmentAction(int $orderId, Closure $change, bool $requireOpenOrder = true): void {
    $db = Db::get();
    $db->beginTransaction();
    try {
        // Lock before the first read: on MySQL that read fixes the transaction's snapshot.
        Orders::lockWithin($orderId);
        $order = Orders::byId($orderId);
        if (!$order) throw new DomainException('admin.order.msg.not_found');
        if ($requireOpenOrder && !Fulfillment::orderShippable($order)) throw new DomainException('admin.shipment.not_shippable');
        $message = $change($order);
        $db->commit();
        setFlash('ok', I18n::t($message));
    } catch (Throwable $e) {
        $db->rollBack();
        $key = match (true) {
            $e instanceof DomainException => $e->getMessage(),
            $e instanceof InvalidArgumentException => 'admin.shipment.msg.invalid_tracking',
            str_contains($e->getMessage(), 'exceeds reserved allocation') => 'admin.shipment.msg.exceeds',
            default => 'admin.shipment.msg.failed',
        };
        if ($key === 'admin.shipment.msg.failed') Logger::phpLog("Admin shipment [{$orderId}]: " . $e->getMessage());
        setFlash('err', I18n::t($key));
    }
    header("Location: admin.php?p=order&id={$orderId}#shipments");
    exit;
}

/** Shipment of the order in the active store with the expected status, from the posted shipment_id. */
function adminOrderShipment(int $orderId, string $status): array {
    $shipmentId = Security::int($_POST['shipment_id'] ?? 0);
    foreach (Fulfillment::shipments($orderId) as $shipment) {
        if ((int)$shipment['id'] !== $shipmentId) continue;
        if ($shipment['status'] !== $status) throw new DomainException('admin.shipment.msg.already_done');
        return $shipment;
    }
    throw new DomainException('admin.shipment.msg.not_found');
}

/** @return array{0:?string,1:?string,2:?string} */
function adminShipmentTrackingInput(): array {
    $values = [];
    foreach (['carrier', 'tracking_number', 'tracking_url'] as $field) {
        $value = $_POST[$field] ?? '';
        if (!is_string($value)) throw new InvalidArgumentException('Invalid tracking field');
        $values[] = trim($value) === '' ? null : trim($value);
    }
    return $values;
}
