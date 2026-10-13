<?php
/** Admin screens for the active store's B2B companies, customer groups and a customer's assignment; validation and writes live in B2bAdmin. */

function pageCustomerGroups(): void {
    $groups = B2bAdmin::groups();
    $id = Security::int($_GET['id'] ?? 0);
    $edit = $id ? B2bAdmin::group($id) : null;
    ?>
    <h1><?= esc(I18n::t('admin.title.customer_groups')) ?></h1>
    <div class="card">
        <h3><?= esc($edit ? I18n::format('admin.common.edit_named', ['name' => (string)$edit['name']]) : I18n::t('admin.b2b.group_new')) ?></h3>
        <p class="muted"><?= esc(I18n::t('admin.b2b.group_hint')) ?></p>
        <form method="post" action="admin.php?p=customer_groups&action=save<?= $edit ? '&id=' . (int)$edit['id'] : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="grid grid-4">
                <div class="form-row"><label for="cg-name"><?= esc(I18n::t('admin.th.name')) ?></label><input id="cg-name" name="name" value="<?= esc((string)($edit['name'] ?? '')) ?>" required maxlength="191"></div>
                <div class="form-row"><label for="cg-code"><?= esc(I18n::t('admin.taxonomy.code_hint')) ?></label><input id="cg-code" name="code" value="<?= esc((string)($edit['code'] ?? '')) ?>" maxlength="191"></div>
                <div class="form-row"><label for="cg-discount"><?= esc(I18n::t('admin.b2b.discount_hint')) ?></label><input id="cg-discount" name="discount" value="<?= esc(B2bAdmin::bpsToPercent((int)($edit['discount_bps'] ?? 0))) ?>" inputmode="decimal"></div>
                <div class="form-row"><label><input type="checkbox" name="active" value="1" <?= !$edit || !empty($edit['active']) ? 'checked' : '' ?>> <?= esc(I18n::t('admin.common.active_f')) ?></label></div>
            </div>
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.btn.save')) ?></button>
            <?php if ($edit): ?><a href="admin.php?p=customer_groups" class="btn" style="margin-left:.5rem"><?= esc(I18n::t('admin.b2b.group_new')) ?></a><?php endif; ?>
        </form>
    </div>
    <div class="card table-scroll">
        <table>
            <thead><tr><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.common.code')) ?></th><th><?= esc(I18n::t('admin.b2b.discount')) ?></th><th><?= esc(I18n::t('admin.title.customers')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($groups as $g): ?>
            <tr>
                <td><a href="admin.php?p=customer_groups&id=<?= (int)$g['id'] ?>"><?= esc($g['name']) ?></a></td>
                <td><code><?= esc($g['code']) ?></code></td>
                <td><?= esc(B2bAdmin::bpsToPercent((int)$g['discount_bps'])) ?> %</td>
                <td><?= (int)$g['customer_count'] ?></td>
                <td><span class="badge badge-<?= $g['active'] ? 'active' : 'draft' ?>"><?= esc(I18n::t($g['active'] ? 'admin.common.status_active_f' : 'admin.common.status_inactive_f')) ?></span></td>
                <td><?php b2bDeleteButton('customer_groups', 'customer_group_id', (int)$g['id'], I18n::t('admin.b2b.group_delete_confirm')); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function pageCompanies(): void {
    $companies = B2bAdmin::companies();
    $id = Security::int($_GET['id'] ?? 0);
    $edit = $id ? B2bAdmin::company($id) : null;
    $value = static fn(string $key, string $default = ''): string => esc((string)($edit[$key] ?? $default));
    ?>
    <h1><?= esc(I18n::t('admin.title.companies')) ?></h1>
    <div class="card">
        <h3><?= esc($edit ? I18n::format('admin.common.edit_named', ['name' => (string)$edit['legal_name']]) : I18n::t('admin.b2b.company_new')) ?></h3>
        <p class="muted"><?= esc(I18n::t('admin.b2b.company_hint')) ?></p>
        <form method="post" action="admin.php?p=companies&action=save<?= $edit ? '&id=' . (int)$edit['id'] : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="grid grid-4">
                <div class="form-row"><label for="co-name"><?= esc(I18n::t('admin.b2b.legal_name')) ?></label><input id="co-name" name="legal_name" value="<?= $value('legal_name') ?>" required maxlength="191"></div>
                <div class="form-row"><label for="co-code"><?= esc(I18n::t('admin.b2b.code_hint_legal')) ?></label><input id="co-code" name="code" value="<?= $value('code') ?>" maxlength="191"></div>
                <div class="form-row"><label for="co-reg"><?= esc(I18n::t('checkout_ico')) ?></label><input id="co-reg" name="registration_number" value="<?= $value('registration_number') ?>" maxlength="32"></div>
                <div class="form-row"><label for="co-vat"><?= esc(I18n::t('checkout_vat_id')) ?><?= !empty($edit['vat_validated_at']) ? ' ' . esc(I18n::format('admin.b2b.vat_verified', ['date' => substr((string)$edit['vat_validated_at'], 0, 10)])) : '' ?></label><input id="co-vat" name="vat_id" value="<?= $value('vat_id') ?>" maxlength="20"></div>
                <div class="form-row"><label for="co-approval"><?= esc(I18n::t('admin.b2b.approval')) ?></label>
                    <select id="co-approval" name="approval_status">
                        <?php foreach (array_keys(B2bAdmin::APPROVAL) as $status): ?>
                        <option value="<?= esc($status) ?>" <?= ($edit['approval_status'] ?? 'pending') === $status ? 'selected' : '' ?>><?= esc(I18n::t('admin.b2b.approval.' . $status)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label for="co-credit"><?= esc(I18n::t('admin.b2b.credit_limit_hint')) ?></label><input id="co-credit" type="number" min="0" name="credit_limit" value="<?= $value('credit_limit', '0') ?>"></div>
                <div class="form-row"><label for="co-terms"><?= esc(I18n::t('admin.b2b.payment_terms')) ?></label><input id="co-terms" type="number" min="0" max="365" name="payment_terms_days" value="<?= $value('payment_terms_days', '0') ?>"></div>
                <div class="form-row"><label><input type="checkbox" name="invoice_purchase" value="1" <?= !empty($edit['invoice_purchase']) ? 'checked' : '' ?>> <?= esc(I18n::t('admin.b2b.invoice_purchase')) ?></label>
                    <label><input type="checkbox" name="active" value="1" <?= !$edit || !empty($edit['active']) ? 'checked' : '' ?>> <?= esc(I18n::t('admin.common.active_f')) ?></label></div>
            </div>
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.btn.save')) ?></button>
            <?php if ($edit): ?><a href="admin.php?p=companies" class="btn" style="margin-left:.5rem"><?= esc(I18n::t('admin.b2b.company_new')) ?></a><?php endif; ?>
        </form>
    </div>
    <div class="card table-scroll">
        <table>
            <thead><tr><th><?= esc(I18n::t('admin.b2b.company')) ?></th><th><?= esc(I18n::t('admin.common.code')) ?></th><th><?= esc(I18n::t('checkout_ico')) ?> / <?= esc(I18n::t('checkout_vat_id')) ?></th><th><?= esc(I18n::t('admin.b2b.approval')) ?></th><th><?= esc(I18n::t('admin.b2b.invoice')) ?></th><th><?= esc(I18n::t('admin.b2b.limit_open')) ?></th><th><?= esc(I18n::t('admin.title.customers')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($companies as $c): $currency = StoreContext::current()->currency(); ?>
            <tr>
                <td><a href="admin.php?p=companies&id=<?= (int)$c['id'] ?>"><?= esc($c['legal_name']) ?></a></td>
                <td><code><?= esc($c['code']) ?></code></td>
                <td><?= esc((string)($c['registration_number'] ?? '')) ?> <?= esc((string)($c['vat_id'] ?? '')) ?></td>
                <td><?= esc(isset(B2bAdmin::APPROVAL[$c['approval_status']]) ? I18n::t('admin.b2b.approval.' . $c['approval_status']) : (string)$c['approval_status']) ?></td>
                <td><?= esc($c['invoice_purchase'] ? I18n::format('admin.b2b.invoice_terms', ['days' => (int)$c['payment_terms_days']]) : I18n::t('admin.b2b.invoice_no')) ?></td>
                <td><?= (int)$c['credit_limit'] > 0 ? esc(I18n::price((int)$c['credit_limit'], $currency)) : esc(I18n::t('admin.b2b.no_limit')) ?> / <?= esc(I18n::price((int)$c['open_total'], $currency)) ?></td>
                <td><?= (int)$c['customer_count'] ?></td>
                <td><span class="badge badge-<?= $c['active'] ? 'active' : 'draft' ?>"><?= esc(I18n::t($c['active'] ? 'admin.common.status_active_f' : 'admin.common.status_inactive_f')) ?></span></td>
                <td><?php b2bDeleteButton('companies', 'company_id', (int)$c['id'], I18n::t('admin.b2b.company_delete_confirm')); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/** B2B card on the customer detail: group and company of the same store. */
function b2bCustomerCard(int $customerId): void {
    $assignment = B2bAdmin::customerAssignment($customerId);
    if ($assignment === null || !Security::adminCan('customers.edit')) return;
    $select = static function(string $name, string $label, array $rows, string $text, ?int $selected): void { ?>
        <div class="form-row"><label for="b2b-<?= esc($name) ?>"><?= esc($label) ?></label>
            <select id="b2b-<?= esc($name) ?>" name="<?= esc($name) ?>">
                <option value=""><?= esc(I18n::t('admin.b2b.none')) ?></option>
                <?php foreach ($rows as $row): ?>
                <option value="<?= (int)$row['id'] ?>" <?= $selected === (int)$row['id'] ? 'selected' : '' ?>><?= esc((string)$row[$text]) ?><?= empty($row['active']) ? ' ' . esc(I18n::t('admin.b2b.inactive_suffix')) : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php };
    ?>
    <div class="card">
        <h3>B2B</h3>
        <form method="post" action="admin.php?p=customer&action=b2b&id=<?= $customerId ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="grid grid-4">
                <?php $select('customer_group_id', I18n::t('admin.b2b.customer_group'), B2bAdmin::groups(), 'name', $assignment['customer_group_id']); ?>
                <?php $select('company_id', I18n::t('admin.b2b.company'), B2bAdmin::companies(), 'legal_name', $assignment['company_id']); ?>
            </div>
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.b2b.save_assignment')) ?></button>
        </form>
    </div>
    <?php
}

function b2bDeleteButton(string $page, string $field, int $id, string $confirm): void { ?>
    <form method="post" action="admin.php?p=<?= esc($page) ?>&action=delete" style="display:inline" data-confirm="<?= esc($confirm) ?>">
        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
        <input type="hidden" name="<?= esc($field) ?>" value="<?= $id ?>">
        <button class="btn btn-sm btn-danger"><?= esc(I18n::t('admin.btn.delete')) ?></button>
    </form>
<?php }

function handleCustomerGroupSave(): void {
    taxonomyRedirect('customer_groups', B2bAdmin::saveGroup(Security::int($_GET['id'] ?? 0), $_POST));
}

function handleCustomerGroupDelete(): void {
    taxonomyRedirect('customer_groups', B2bAdmin::deleteGroup(Security::int($_POST['customer_group_id'] ?? 0)));
}

function handleCompanySave(): void {
    taxonomyRedirect('companies', B2bAdmin::saveCompany(Security::int($_GET['id'] ?? 0), $_POST));
}

function handleCompanyDelete(): void {
    taxonomyRedirect('companies', B2bAdmin::deleteCompany(Security::int($_POST['company_id'] ?? 0)));
}

function handleCustomerB2b(): void {
    Security::adminRequire('customers.edit');
    $id = Security::int($_GET['id'] ?? 0);
    $result = B2bAdmin::assignCustomer($id, $_POST);
    setFlash($result['ok'] ? 'ok' : 'err', $result['message']);
    header('Location: admin.php?p=customer&id=' . $id);
    exit;
}
