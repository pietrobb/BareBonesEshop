<?php
/** R11 H9: carrier labels of the parcels in the admin order detail (ShippingService::generateLabel per shipment). */

/**
 * Carriers able to create labels (carrier id => why unavailable, null = ready; only for an order that can ship) and the
 * active labels of the order by parcel.
 * @return array{0: array<string,?string>, 1: array<int, list<array>>}
 */
function adminShipmentLabelState(int $orderId, bool $open): array {
    $labels = [];
    foreach (ShippingService::labels($orderId) as $label) {
        if (($label['shipment_id'] ?? null) !== null && $label['status'] !== 'cancelled') $labels[(int)$label['shipment_id']][] = $label;
    }
    return [$open ? ShippingService::labelCarriers() : [], $labels];
}

/**
 * Cash on delivery of one parcel, shown whether or not a carrier API is connected: the amount to enter in the carrier's
 * portal on the one parcel that collects the order total, "prepaid" on every other parcel (ShippingService::parcelCodAmount).
 */
function renderShipmentCod(array $order, int $shipmentId): void {
    $cod = ShippingService::parcelCodAmount($order, $shipmentId);
    if ($cod === null) return; ?>
    <br><span class="muted" data-shipment-cod="<?= $cod ?>"><?= $cod > 0
        ? esc(I18n::format('admin.shipment.label_cod', ['amount' => I18n::price($cod, (string)$order['currency'])]))
        : esc(I18n::t('admin.shipment.label_prepaid')) ?></span>
    <?php
}

/**
 * Carrier label of a planned parcel: the labels created for it (tracking number, cash on delivery the carrier
 * collects, taken from the order) or a form creating one. A carrier whose API is not connected is listed as such and
 * cannot be picked: no tracking number is invented, the parcel is booked in the carrier's portal instead.
 * @param array<string,?string> $carriers carrier id => why labels are unavailable (null = ready)
 */
function renderShipmentLabelForm(array $order, array $shipment, array $labels, array $carriers, string $action): void {
    foreach ($labels as $label): ?>
    <p class="muted" data-shipment-label="<?= esc((string)$label['awb']) ?>"><?= esc(I18n::t('admin.shipment.label')) ?>: <?= esc((string)$label['carrier'] . ' ' . (string)$label['awb']) ?> —
        <?= (int)($label['cod_amount'] ?? 0) > 0
            ? esc(I18n::format('admin.shipment.label_cod', ['amount' => I18n::price((int)$label['cod_amount'], (string)$order['currency'])]))
            : esc(I18n::t('admin.shipment.label_prepaid')) ?></p>
    <?php endforeach;
    if ($labels || !$carriers) return;
    $ready = array_keys(array_filter($carriers, static fn(?string $reason): bool => $reason === null));
    ?>
    <?php foreach (array_diff_key($carriers, array_flip($ready)) as $carrier => $reason): ?>
    <p class="muted" data-label-unavailable="<?= esc((string)$carrier) ?>"><?= esc(I18n::format('admin.shipment.label_not_connected', ['carrier' => (string)$carrier])) ?></p>
    <?php endforeach; ?>
    <?php if ($ready): $cod = ShippingService::parcelCodAmount($order, (int)$shipment['id']) ?? 0; ?>
    <form method="post" action="<?= $action ?>" style="margin-top:.25rem" data-shipment-label-form>
        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
        <input type="hidden" name="shipment_id" value="<?= (int)$shipment['id'] ?>">
        <select name="label_carrier" aria-label="<?= esc(I18n::t('admin.shipment.carrier')) ?>">
            <?php foreach ($ready as $carrier): ?><option value="<?= esc((string)$carrier) ?>"<?= (string)$carrier === (string)($order['carrier'] ?? '') ? ' selected' : '' ?>><?= esc((string)$carrier) ?></option><?php endforeach; ?>
        </select>
        <span class="muted"><?= $cod > 0 ? esc(I18n::format('admin.shipment.label_cod', ['amount' => I18n::price($cod, (string)$order['currency'])])) : esc(I18n::t('admin.shipment.label_prepaid')) ?></span>
        <button class="btn btn-sm" type="submit"><?= esc(I18n::t('admin.shipment.label_create')) ?></button>
    </form>
    <?php endif;
}

/** Create the carrier label of a planned parcel (ShippingService::generateLabel; idempotent per parcel and carrier). */
function handleOrderShipmentLabel(): void {
    Security::adminRequire('orders.ship');
    $orderId = Security::int($_GET['id'] ?? 0);
    adminShipmentAction($orderId, static function() use ($orderId): string {
        $shipment = adminOrderShipment($orderId, 'planned');
        $carrier = (string)($_POST['label_carrier'] ?? '');
        $carriers = ShippingService::labelCarriers();
        if (!array_key_exists($carrier, $carriers)) throw new DomainException('admin.shipment.msg.invalid_form');
        if ($carriers[$carrier] !== null) throw new DomainException('admin.shipment.msg.label_unavailable');
        $label = ShippingService::generateLabel($orderId, $carrier, (int)$shipment['id']);
        AuditLog::log('shipment', (int)$shipment['id'], null, ['label_carrier' => $carrier, 'awb' => $label['awb'], 'cod_amount' => (int)($label['cod_amount'] ?? 0)], 'Admin shipment label');
        return 'admin.shipment.msg.label_created';
    });
}
