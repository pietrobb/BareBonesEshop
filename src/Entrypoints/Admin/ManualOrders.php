<?php
/** R10 UX7: orders entered in the admin and edits of a pending order's lines; pricing and stock rules live in ManualOrder. */

const MANUAL_ORDER_FIELDS = ['name', 'email', 'phone', 'country', 'street', 'city', 'zip', 'company', 'ico', 'dic', 'vat_id',
    'pickup_point', 'shipping_method', 'payment_method', 'lines', 'notes', 'locale'];

function manualOrderMessage(array $result): string {
    $message = I18n::t((string)$result['error']);
    return ($result['detail'] ?? '') !== '' ? $message . ' (' . $result['detail'] . ')' : $message;
}

/** GET ?p=order_new — the form; a refused submission comes back filled in. */
function pageOrderNew(): void {
    $posted = is_array($_SESSION['manual_order_form'] ?? null) ? $_SESSION['manual_order_form'] : [];
    unset($_SESSION['manual_order_form']);
    $value = static fn(string $field, string $default = ''): string => (string)($posted[$field] ?? $default);
    $country = strtoupper($value('country', (string)(StoreContext::current()->legal()['country'] ?? 'SK')));
    $options = ManualOrder::formOptions(preg_match('/^[A-Z]{2}$/', $country) ? $country : 'SK');
    $locales = array_map(static fn(string $file): string => basename($file, '.php'), glob(dirname(__DIR__, 3) . '/lang/*.php') ?: []);
    $field = static function (string $name, string $label, string $type = 'text', bool $required = false) use ($value): void { ?>
        <div class="form-row"><label for="mo-<?= esc($name) ?>"><?= esc($label) ?></label><input id="mo-<?= esc($name) ?>" name="<?= esc($name) ?>" type="<?= esc($type) ?>" value="<?= esc($value($name)) ?>"<?= $required ? ' required' : '' ?>></div>
    <?php };
    ?>
    <h1><?= esc(I18n::t('admin.title.order_new')) ?></h1>
    <form method="post" action="admin.php?p=order_new&amp;action=create" class="card" data-manual-order>
        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
        <div class="grid grid-4">
            <?php $field('name', I18n::t('admin.th.customer'), 'text', true); $field('email', I18n::t('admin.th.email'), 'email', true); $field('phone', I18n::t('phone')); ?>
            <div class="form-row"><label for="mo-locale"><?= esc(I18n::t('admin.order.locale')) ?></label><select id="mo-locale" name="locale"><?php foreach ($locales as $lc): ?><option value="<?= esc($lc) ?>" <?= $value('locale', StoreContext::current()->defaultLocale()) === $lc ? 'selected' : '' ?>><?= esc(strtoupper($lc)) ?></option><?php endforeach; ?></select></div>
            <?php $field('street', I18n::t('street')); $field('city', I18n::t('city')); $field('zip', I18n::t('zip')); $field('country', I18n::t('country'), 'text', true); ?>
            <?php $field('company', I18n::t('checkout_company')); $field('ico', I18n::t('checkout_ico')); $field('dic', I18n::t('checkout_dic')); $field('vat_id', I18n::t('checkout_vat_id')); ?>
            <div class="form-row"><label for="mo-shipping"><?= esc(I18n::t('admin.order.shipping')) ?></label><select id="mo-shipping" name="shipping_method"><?php foreach ($options['shipping'] as $o): ?><option value="<?= esc($o['code']) ?>" <?= $value('shipping_method') === $o['code'] ? 'selected' : '' ?>><?= esc($o['label']) ?></option><?php endforeach; ?></select></div>
            <?php $field('pickup_point', I18n::t('pickup_point')); ?>
            <div class="form-row"><label for="mo-payment"><?= esc(I18n::t('admin.order.payment_method')) ?></label><select id="mo-payment" name="payment_method"><?php foreach ($options['payment'] as $o): ?><option value="<?= esc($o['code']) ?>" <?= $value('payment_method') === $o['code'] ? 'selected' : '' ?>><?= esc($o['label']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row"><label for="mo-lines"><?= esc(I18n::t('admin.order.manual.lines')) ?></label><textarea id="mo-lines" name="lines" rows="6" style="width:100%" required aria-describedby="mo-lines-hint"><?= esc($value('lines')) ?></textarea><span id="mo-lines-hint" class="muted"><?= esc(I18n::t('admin.order.manual.lines_hint')) ?></span></div>
        <div class="form-row"><label for="mo-notes"><?= esc(I18n::t('admin.order.note')) ?></label><textarea id="mo-notes" name="notes" rows="2" style="width:100%"><?= esc($value('notes')) ?></textarea></div>
        <div class="form-row"><label><input type="checkbox" name="notify" value="1" <?= ($posted === [] || !empty($posted['notify'])) ? 'checked' : '' ?>> <?= esc(I18n::t('admin.order.manual.notify')) ?></label></div>
        <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.order.manual.create')) ?></button>
        <a class="btn" href="admin.php?p=orders"><?= esc(I18n::t('admin.common.back_to_list')) ?></a>
    </form>
    <?php
}

/** POST ?p=order_new&action=create */
function handleOrderNew(): void {
    Security::adminRequire('orders.edit');
    $posted = [];
    foreach (MANUAL_ORDER_FIELDS as $name) $posted[$name] = is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
    $posted['notify'] = !empty($_POST['notify']);
    $parsed = ManualOrder::parseLines($posted['lines']);
    $result = $parsed['errors'] !== []
        ? ['ok' => false, 'error' => 'admin.order.manual.unknown_sku', 'detail' => implode(', ', $parsed['errors'])]
        : ManualOrder::create($parsed['lines'], $posted, $posted['notify']);
    if (!$result['ok']) {
        $_SESSION['manual_order_form'] = $posted;
        setFlash('err', manualOrderMessage($result));
        header('Location: admin.php?p=order_new');
        exit;
    }
    setFlash('ok', I18n::t('admin.order.manual.created'));
    header('Location: admin.php?p=order&id=' . (int)$result['order_id']);
    exit;
}

/** Order detail: the lines of a pending, unpaid order can be changed (the reason is shown when they cannot). */
function renderOrderItemsEditor(array $order, array $items): void {
    $refusal = Orders::editRefusal($order);
    ?>
    <div class="card" data-order-items-editor>
        <h3><?= esc(I18n::t('admin.order.edit.title')) ?></h3>
        <?php if ($refusal !== null): ?>
        <p class="muted"><?= esc(I18n::t($refusal)) ?></p>
        <?php else: ?>
        <form method="post" action="admin.php?p=order&amp;id=<?= (int)$order['id'] ?>&amp;action=items_edit">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <label for="order-lines"><?= esc(I18n::t('admin.order.manual.lines')) ?></label>
            <textarea id="order-lines" name="lines" rows="<?= max(3, count($items) + 1) ?>" style="width:100%" required aria-describedby="order-lines-hint"><?= esc(ManualOrder::linesText($items)) ?></textarea>
            <p id="order-lines-hint" class="muted"><?= esc(I18n::t('admin.order.edit.hint')) ?></p>
            <button class="btn btn-sm" type="submit" data-confirm="<?= esc(I18n::t('admin.order.edit.confirm')) ?>"><?= esc(I18n::t('admin.order.edit.save')) ?></button>
        </form>
        <?php endif; ?>
    </div>
    <?php
}

/** POST ?p=order&id=N&action=items_edit */
function handleOrderItemsEdit(): void {
    Security::adminRequire('orders.edit');
    $orderId = Security::int($_GET['id'] ?? 0);
    $parsed = ManualOrder::parseLines(is_string($_POST['lines'] ?? null) ? $_POST['lines'] : '');
    $result = $parsed['errors'] !== []
        ? ['ok' => false, 'error' => 'admin.order.manual.unknown_sku', 'detail' => implode(', ', $parsed['errors'])]
        : ManualOrder::editItems($orderId, $parsed['lines']);
    setFlash($result['ok'] ? 'ok' : 'err', $result['ok'] ? I18n::t('admin.order.edit.saved') : manualOrderMessage($result));
    header('Location: admin.php?p=order&id=' . $orderId);
    exit;
}
