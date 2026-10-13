<?php
/** Admin screens for the active store's suppliers and purchase orders; validation, ledger receipts and audit live in SupplierAdmin. */

function pageSuppliers(): void {
    $suppliers = SupplierAdmin::suppliers();
    $id = Security::int($_GET['id'] ?? 0);
    $edit = $id ? SupplierAdmin::supplier($id) : null;
    $contact = $edit['contact'] ?? [];
    ?>
    <h1>Dodávatelia</h1>
    <div class="card">
        <h3><?= $edit ? 'Upraviť: ' . esc($edit['name']) : 'Nový dodávateľ' ?></h3>
        <form method="post" action="admin.php?p=suppliers&action=save<?= $edit ? '&id=' . (int)$edit['id'] : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="grid grid-4">
                <div class="form-row"><label for="sup-name">Názov</label><input id="sup-name" name="name" value="<?= esc((string)($edit['name'] ?? '')) ?>" required maxlength="191"></div>
                <div class="form-row"><label for="sup-code">Kód (prázdny = z názvu)</label><input id="sup-code" name="code" value="<?= esc((string)($edit['code'] ?? '')) ?>" maxlength="191"></div>
                <div class="form-row"><label for="sup-email">E-mail</label><input id="sup-email" type="email" name="email" value="<?= esc((string)($contact['email'] ?? '')) ?>" maxlength="191"></div>
                <div class="form-row"><label for="sup-phone">Telefón</label><input id="sup-phone" name="phone" value="<?= esc((string)($contact['phone'] ?? '')) ?>" maxlength="191"></div>
                <div class="form-row"><label for="sup-note">Poznámka</label><input id="sup-note" name="note" value="<?= esc((string)($contact['note'] ?? '')) ?>" maxlength="191"></div>
                <div class="form-row"><label><input type="checkbox" name="active" value="1" <?= !$edit || !empty($edit['active']) ? 'checked' : '' ?>> Aktívny</label></div>
            </div>
            <button class="btn btn-primary" type="submit">Uložiť</button>
            <?php if ($edit): ?><a href="admin.php?p=suppliers" class="btn" style="margin-left:.5rem">Nový dodávateľ</a><?php endif; ?>
        </form>
    </div>
    <?php if ($edit): ?>
    <div class="card">
        <h3>Ponuka dodávateľa <?= esc($edit['code']) ?></h3>
        <form method="post" action="admin.php?p=suppliers&action=products&id=<?= (int)$edit['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="form-row"><label for="sup-products">Riadok: SKU | kód dodávateľa | nákupná cena (centy) | min. množstvo | balenie | dodacia lehota (dni)</label>
                <textarea id="sup-products" name="products" rows="8" style="width:100%;font-family:monospace"><?= esc(SupplierAdmin::offerLines((int)$edit['id'])) ?></textarea>
            </div>
            <button class="btn btn-primary" type="submit">Uložiť ponuku</button>
        </form>
    </div>
    <?php endif; ?>
    <div class="card table-scroll">
        <table>
            <thead><tr><th>Názov</th><th>Kód</th><th>Kontakt</th><th>Položky</th><th>Objednávky</th><th>Stav</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($suppliers as $s): $c = json_decode((string)$s['contact'], true) ?: []; ?>
            <tr>
                <td><a href="admin.php?p=suppliers&id=<?= (int)$s['id'] ?>"><?= esc($s['name']) ?></a></td>
                <td><code><?= esc($s['code']) ?></code></td>
                <td><?= esc(trim(($c['email'] ?? '') . ' ' . ($c['phone'] ?? ''))) ?></td>
                <td><?= (int)$s['product_count'] ?></td>
                <td><?= (int)$s['order_count'] ?></td>
                <td><span class="badge badge-<?= $s['active'] ? 'active' : 'draft' ?>"><?= $s['active'] ? 'aktívny' : 'neaktívny' ?></span></td>
                <td>
                    <form method="post" action="admin.php?p=suppliers&action=delete" style="display:inline" data-confirm="Zmazať dodávateľa aj s jeho ponukou?">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="supplier_id" value="<?= (int)$s['id'] ?>">
                        <button class="btn btn-sm btn-danger">Zmazať</button>
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
    <h1>Nákupná objednávka <?= esc($order['number']) ?></h1>
    <p style="margin-bottom:1rem"><a class="btn btn-sm" href="admin.php?p=purchase_orders">← Zoznam</a></p>
    <div class="card">
        <p><?= esc((string)$order['supplier_name']) ?> → sklad <?= esc((string)$order['warehouse_name']) ?> · <?= esc(SupplierAdmin::STATUSES[$order['status']] ?? (string)$order['status']) ?>
            <?= $order['expected_at'] ? ' · očakávané ' . esc(substr((string)$order['expected_at'], 0, 10)) : '' ?></p>
        <form method="post" action="admin.php?p=purchase_orders&action=receive&id=<?= (int)$order['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <input type="hidden" name="receipt_key" value="<?= bin2hex(random_bytes(16)) ?>">
            <table>
                <thead><tr><th>SKU</th><th>Objednané</th><th>Prijaté</th><th>Nákupná cena</th><th>Prijať teraz</th></tr></thead>
                <tbody>
                <?php foreach ($order['items'] as $item): $open = (int)$item['quantity'] - (int)$item['received_qty']; ?>
                <tr>
                    <td><code><?= esc($item['sku']) ?></code></td>
                    <td><?= (int)$item['quantity'] ?></td>
                    <td><?= (int)$item['received_qty'] ?></td>
                    <td><?= esc(I18n::price((int)$item['unit_cost'], $currency)) ?></td>
                    <td><?php if ($open > 0): ?><label class="sr-only" for="rcv-<?= (int)$item['id'] ?>">Prijať <?= esc($item['sku']) ?></label><input id="rcv-<?= (int)$item['id'] ?>" type="number" min="0" max="<?= $open ?>" name="receive[<?= (int)$item['id'] ?>]" value="<?= $open ?>"><?php else: ?>—<?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($order['status'] !== 'received'): ?><button class="btn btn-primary" type="submit" style="margin-top:.5rem">Zaúčtovať príjem na sklad</button><?php endif; ?>
        </form>
    </div>
    <?php return; endif;
    $suppliers = array_filter(SupplierAdmin::suppliers(), static fn(array $s): bool => (bool)$s['active']);
    ?>
    <h1>Nákupné objednávky</h1>
    <div class="card">
        <h3>Nová objednávka</h3>
        <form method="post" action="admin.php?p=purchase_orders&action=create">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="grid grid-4">
                <div class="form-row"><label for="po-supplier">Dodávateľ</label><select id="po-supplier" name="supplier_id"><?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"><?= esc($s['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-row"><label for="po-warehouse">Sklad</label><select id="po-warehouse" name="warehouse_id"><?php foreach (Warehouse::all() as $w): ?><option value="<?= (int)$w['id'] ?>"><?= esc($w['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-row"><label for="po-number">Číslo (prázdne = automaticky)</label><input id="po-number" name="number" maxlength="64"></div>
                <div class="form-row"><label for="po-expected">Očakávané dodanie</label><input id="po-expected" type="date" name="expected_at"></div>
            </div>
            <div class="form-row"><label for="po-items">Riadok: SKU | množstvo [| nákupná cena v centoch; prázdna = z ponuky dodávateľa]</label>
                <textarea id="po-items" name="items" rows="6" style="width:100%;font-family:monospace"></textarea></div>
            <button class="btn btn-primary" type="submit">Vytvoriť objednávku</button>
        </form>
    </div>
    <div class="card table-scroll">
        <table>
            <thead><tr><th>Číslo</th><th>Dodávateľ</th><th>Sklad</th><th>Stav</th><th>Prijaté / objednané</th><th>Hodnota</th><th>Vytvorená</th></tr></thead>
            <tbody>
            <?php foreach (SupplierAdmin::purchaseOrders() as $po): ?>
            <tr>
                <td><a href="admin.php?p=purchase_orders&id=<?= (int)$po['id'] ?>"><?= esc($po['number']) ?></a></td>
                <td><?= esc((string)$po['supplier_name']) ?></td>
                <td><?= esc((string)$po['warehouse_name']) ?></td>
                <td><?= esc(SupplierAdmin::STATUSES[$po['status']] ?? (string)$po['status']) ?></td>
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
