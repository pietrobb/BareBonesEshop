<?php
/** Admin screens for the active store's suppliers and purchase orders; validation, ledger receipts and audit live in SupplierAdmin. */

function pageSuppliers(): void {
    $suppliers = SupplierAdmin::suppliers();
    $id = Security::int($_GET['id'] ?? 0);
    $edit = $id ? SupplierAdmin::supplier($id) : null;
    $contact = $edit['contact'] ?? [];
    ?>
    <h1><?= esc(I18n::t('admin.title.suppliers')) ?></h1>
    <div class="card">
        <h3><?= esc($edit ? I18n::format('admin.common.edit_named', ['name' => (string)$edit['name']]) : I18n::t('admin.supplier.new')) ?></h3>
        <form method="post" action="admin.php?p=suppliers&action=save<?= $edit ? '&id=' . (int)$edit['id'] : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="grid grid-4">
                <div class="form-row"><label for="sup-name"><?= esc(I18n::t('admin.th.name')) ?></label><input id="sup-name" name="name" value="<?= esc((string)($edit['name'] ?? '')) ?>" required maxlength="191"></div>
                <div class="form-row"><label for="sup-code"><?= esc(I18n::t('admin.taxonomy.code_hint')) ?></label><input id="sup-code" name="code" value="<?= esc((string)($edit['code'] ?? '')) ?>" maxlength="191"></div>
                <div class="form-row"><label for="sup-email"><?= esc(I18n::t('admin.common.email')) ?></label><input id="sup-email" type="email" name="email" value="<?= esc((string)($contact['email'] ?? '')) ?>" maxlength="191"></div>
                <div class="form-row"><label for="sup-phone"><?= esc(I18n::t('admin.customers.phone')) ?></label><input id="sup-phone" name="phone" value="<?= esc((string)($contact['phone'] ?? '')) ?>" maxlength="191"></div>
                <div class="form-row"><label for="sup-note"><?= esc(I18n::t('admin.common.note')) ?></label><input id="sup-note" name="note" value="<?= esc((string)($contact['note'] ?? '')) ?>" maxlength="191"></div>
                <div class="form-row"><label><input type="checkbox" name="active" value="1" <?= !$edit || !empty($edit['active']) ? 'checked' : '' ?>> <?= esc(I18n::t('admin.common.active_m')) ?></label></div>
            </div>
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.btn.save')) ?></button>
            <?php if ($edit): ?><a href="admin.php?p=suppliers" class="btn" style="margin-left:.5rem"><?= esc(I18n::t('admin.supplier.new')) ?></a><?php endif; ?>
        </form>
    </div>
    <?php if ($edit): ?>
    <div class="card">
        <h3><?= esc(I18n::format('admin.supplier.offer_of', ['code' => (string)$edit['code']])) ?></h3>
        <form method="post" action="admin.php?p=suppliers&action=products&id=<?= (int)$edit['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="form-row"><label for="sup-products"><?= esc(I18n::t('admin.supplier.offer_hint')) ?></label>
                <textarea id="sup-products" name="products" rows="8" style="width:100%;font-family:monospace"><?= esc(SupplierAdmin::offerLines((int)$edit['id'])) ?></textarea>
            </div>
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.supplier.save_offer')) ?></button>
        </form>
    </div>
    <?php endif; ?>
    <div class="card table-scroll">
        <table>
            <thead><tr><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.common.code')) ?></th><th><?= esc(I18n::t('admin.supplier.contact')) ?></th><th><?= esc(I18n::t('admin.sales.items')) ?></th><th><?= esc(I18n::t('admin.title.orders')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($suppliers as $s): $c = json_decode((string)$s['contact'], true) ?: []; ?>
            <tr>
                <td><a href="admin.php?p=suppliers&id=<?= (int)$s['id'] ?>"><?= esc($s['name']) ?></a></td>
                <td><code><?= esc($s['code']) ?></code></td>
                <td><?= esc(trim(($c['email'] ?? '') . ' ' . ($c['phone'] ?? ''))) ?></td>
                <td><?= (int)$s['product_count'] ?></td>
                <td><?= (int)$s['order_count'] ?></td>
                <td><span class="badge badge-<?= $s['active'] ? 'active' : 'draft' ?>"><?= esc(I18n::t($s['active'] ? 'admin.common.status_active_m' : 'admin.common.status_inactive_m')) ?></span></td>
                <td>
                    <form method="post" action="admin.php?p=suppliers&action=delete" style="display:inline" data-confirm="<?= esc(I18n::t('admin.supplier.delete_confirm')) ?>">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="supplier_id" value="<?= (int)$s['id'] ?>">
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

function pagePurchaseOrders(): void {
    $id = Security::int($_GET['id'] ?? 0);
    $order = $id ? SupplierAdmin::purchaseOrder($id) : null;
    $currency = StoreContext::current()->currency();
    if ($order): ?>
    <h1><?= esc(I18n::format('admin.po.heading', ['number' => (string)$order['number']])) ?></h1>
    <p style="margin-bottom:1rem"><a class="btn btn-sm" href="admin.php?p=purchase_orders">← <?= esc(I18n::t('admin.customers.list')) ?></a></p>
    <div class="card">
        <p><?= esc((string)$order['supplier_name']) ?> → <?= esc(I18n::format('admin.po.warehouse_named', ['name' => (string)$order['warehouse_name']])) ?> · <?= esc(supplierOrderStatus((string)$order['status'])) ?>
            <?= $order['expected_at'] ? ' · ' . esc(I18n::format('admin.po.expected_named', ['date' => substr((string)$order['expected_at'], 0, 10)])) : '' ?></p>
        <form method="post" action="admin.php?p=purchase_orders&action=receive&id=<?= (int)$order['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <input type="hidden" name="receipt_key" value="<?= bin2hex(random_bytes(16)) ?>">
            <table>
                <thead><tr><th><?= esc(I18n::t('admin.th.sku')) ?></th><th><?= esc(I18n::t('admin.po.ordered')) ?></th><th><?= esc(I18n::t('admin.po.received')) ?></th><th><?= esc(I18n::t('admin.po.unit_cost')) ?></th><th><?= esc(I18n::t('admin.po.receive_now')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($order['items'] as $item): $open = (int)$item['quantity'] - (int)$item['received_qty']; ?>
                <tr>
                    <td><code><?= esc($item['sku']) ?></code></td>
                    <td><?= (int)$item['quantity'] ?></td>
                    <td><?= (int)$item['received_qty'] ?></td>
                    <td><?= esc(I18n::price((int)$item['unit_cost'], $currency)) ?></td>
                    <td><?php if ($open > 0): ?><label class="sr-only" for="rcv-<?= (int)$item['id'] ?>"><?= esc(I18n::format('admin.po.receive_sku', ['sku' => (string)$item['sku']])) ?></label><input id="rcv-<?= (int)$item['id'] ?>" type="number" min="0" max="<?= $open ?>" name="receive[<?= (int)$item['id'] ?>]" value="<?= $open ?>"><?php else: ?>—<?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($order['status'] !== 'received'): ?><button class="btn btn-primary" type="submit" style="margin-top:.5rem"><?= esc(I18n::t('admin.po.post_receipt')) ?></button><?php endif; ?>
        </form>
    </div>
    <?php return; endif;
    $suppliers = array_filter(SupplierAdmin::suppliers(), static fn(array $s): bool => (bool)$s['active']);
    ?>
    <h1><?= esc(I18n::t('admin.title.purchase_orders')) ?></h1>
    <div class="card">
        <h3><?= esc(I18n::t('admin.po.new')) ?></h3>
        <form method="post" action="admin.php?p=purchase_orders&action=create">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="grid grid-4">
                <div class="form-row"><label for="po-supplier"><?= esc(I18n::t('admin.po.supplier')) ?></label><select id="po-supplier" name="supplier_id"><?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"><?= esc($s['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-row"><label for="po-warehouse"><?= esc(I18n::t('admin.feed.warehouse')) ?></label><select id="po-warehouse" name="warehouse_id"><?php foreach (Warehouse::all() as $w): ?><option value="<?= (int)$w['id'] ?>"><?= esc($w['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-row"><label for="po-number"><?= esc(I18n::t('admin.po.number_hint')) ?></label><input id="po-number" name="number" maxlength="64"></div>
                <div class="form-row"><label for="po-expected"><?= esc(I18n::t('admin.po.expected')) ?></label><input id="po-expected" type="date" name="expected_at"></div>
            </div>
            <div class="form-row"><label for="po-items"><?= esc(I18n::t('admin.po.items_hint')) ?></label>
                <textarea id="po-items" name="items" rows="6" style="width:100%;font-family:monospace"></textarea></div>
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.po.create')) ?></button>
        </form>
    </div>
    <div class="card table-scroll">
        <table>
            <thead><tr><th><?= esc(I18n::t('admin.common.number')) ?></th><th><?= esc(I18n::t('admin.po.supplier')) ?></th><th><?= esc(I18n::t('admin.feed.warehouse')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.po.received_ordered')) ?></th><th><?= esc(I18n::t('admin.recovery.value')) ?></th><th><?= esc(I18n::t('admin.recovery.created')) ?></th></tr></thead>
            <tbody>
            <?php foreach (SupplierAdmin::purchaseOrders() as $po): ?>
            <tr>
                <td><a href="admin.php?p=purchase_orders&id=<?= (int)$po['id'] ?>"><?= esc($po['number']) ?></a></td>
                <td><?= esc((string)$po['supplier_name']) ?></td>
                <td><?= esc((string)$po['warehouse_name']) ?></td>
                <td><?= esc(supplierOrderStatus((string)$po['status'])) ?></td>
                <td><?= (int)$po['received_qty'] ?> / <?= (int)$po['ordered_qty'] ?></td>
                <td><?= esc(I18n::price((int)$po['total_cost'], (string)$po['currency'])) ?></td>
                <td class="muted"><?= esc(substr((string)$po['created_at'], 0, 16)) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/** Purchase order status in the admin language; an unknown status is shown as stored. */
function supplierOrderStatus(string $status): string {
    return in_array($status, SupplierAdmin::STATUSES, true) ? I18n::t('admin.po.status.' . $status) : $status;
}

function handleSupplierSave(): void {
    taxonomyRedirect('suppliers', SupplierAdmin::saveSupplier(Security::int($_GET['id'] ?? 0), $_POST));
}

function handleSupplierDelete(): void {
    taxonomyRedirect('suppliers', SupplierAdmin::deleteSupplier(Security::int($_POST['supplier_id'] ?? 0)));
}

function handleSupplierProducts(): void {
    taxonomyRedirect('suppliers', SupplierAdmin::saveOffers(Security::int($_GET['id'] ?? 0), $_POST));
}

function handlePurchaseOrderCreate(): void {
    taxonomyRedirect('purchase_orders', SupplierAdmin::createOrder($_POST));
}

function handlePurchaseOrderReceive(): void {
    taxonomyRedirect('purchase_orders', SupplierAdmin::receive(Security::int($_GET['id'] ?? 0), $_POST));
}
