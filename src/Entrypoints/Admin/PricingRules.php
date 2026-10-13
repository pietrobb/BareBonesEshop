<?php
/** Admin screens for the active store's promotions, coupon codes and price lists; validation and writes live in PricingAdmin. */

function pagePromotions(): void {
    $promotions = PricingAdmin::promotions();
    $id = Security::int($_GET['id'] ?? 0);
    $edit = $id ? PricingAdmin::promotion($id) : null;
    $f = PricingAdmin::promotionForm($edit);
    $categories = CatalogScope::categories();
    $brands = CatalogTaxonomy::brands();
    $groups = PricingAdmin::customerGroups();
    $multi = static function(string $name, string $label, array $options, array $selected): void { ?>
        <div class="form-row"><label for="promo-<?= esc($name) ?>"><?= esc($label) ?></label>
            <select id="promo-<?= esc($name) ?>" name="<?= esc($name) ?>[]" multiple size="<?= min(6, max(2, count($options))) ?>">
                <?php foreach ($options as $value => $text): ?>
                <option value="<?= (int)$value ?>" <?= in_array((int)$value, $selected, true) ? 'selected' : '' ?>><?= esc($text) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php };
    $input = static function(string $name, string $label, string $value, string $extra = '') : void { ?>
        <div class="form-row"><label for="promo-<?= esc($name) ?>"><?= esc($label) ?></label><input id="promo-<?= esc($name) ?>" name="<?= esc($name) ?>" value="<?= esc($value) ?>" <?= $extra ?>></div>
    <?php };
    ?>
    <h1><?= esc(I18n::t('admin.title.promotions')) ?></h1>
    <div class="card">
        <h3><?= esc($edit ? I18n::format('admin.common.edit_named', ['name' => (string)$edit['name']]) : I18n::t('admin.promo.new')) ?></h3>
        <p class="muted"><?= esc(I18n::t('admin.promo.hint')) ?></p>
        <form method="post" action="admin.php?p=promotions&action=save<?= $edit ? '&id=' . (int)$edit['id'] : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="grid grid-4">
                <?php $input('name', I18n::t('admin.th.name'), $f['name'], 'required maxlength="191"'); ?>
                <?php $input('code', I18n::t('admin.taxonomy.code_hint'), $f['code'], 'maxlength="191"'); ?>
                <div class="form-row"><label for="promo-scope"><?= esc(I18n::t('admin.promo.scope')) ?></label>
                    <select id="promo-scope" name="scope">
                        <option value="catalog" <?= $f['scope'] === 'catalog' ? 'selected' : '' ?>><?= esc(I18n::t('admin.promo.scope_catalog')) ?></option>
                        <option value="cart" <?= $f['scope'] === 'cart' ? 'selected' : '' ?>><?= esc(I18n::t('admin.promo.scope_cart')) ?></option>
                    </select>
                </div>
                <?php $input('coupon_code', I18n::t('admin.promo.coupon_code'), $f['coupon_code'], 'maxlength="64" pattern="[A-Za-z0-9_\-]{2,64}"'); ?>
                <?php $input('priority', I18n::t('admin.promo.priority_hint'), $f['priority'], 'type="number"'); ?>
                <div class="form-row"><label for="promo-stacking"><?= esc(I18n::t('admin.promo.stacking')) ?></label>
                    <select id="promo-stacking" name="stacking">
                        <option value="exclusive" <?= $f['stacking'] === 'exclusive' ? 'selected' : '' ?>><?= esc(I18n::t('admin.promo.stacking_exclusive')) ?></option>
                        <option value="stack" <?= $f['stacking'] === 'stack' ? 'selected' : '' ?>><?= esc(I18n::t('admin.promo.stacking_stack')) ?></option>
                    </select>
                </div>
                <?php $input('valid_from', I18n::t('admin.pricing.valid_from'), $f['valid_from'], 'type="datetime-local" step="1"'); ?>
                <?php $input('valid_to', I18n::t('admin.pricing.valid_to'), $f['valid_to'], 'type="datetime-local" step="1"'); ?>
            </div>
            <h4><?= esc(I18n::t('admin.common.action')) ?></h4>
            <div class="grid grid-4">
                <div class="form-row"><label for="promo-action_type"><?= esc(I18n::t('admin.common.type')) ?></label>
                    <select id="promo-action_type" name="action_type">
                        <?php foreach (array_keys(PricingAdmin::ACTION_LABELS) as $type): ?>
                        <option value="<?= esc($type) ?>" <?= $f['action_type'] === $type ? 'selected' : '' ?>><?= esc(I18n::t('admin.promo.action.' . $type)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php $input('action_value', I18n::t('admin.promo.action_value'), $f['action_value']); ?>
                <?php $input('action_sku', I18n::t('admin.promo.action_sku'), $f['action_sku'], 'maxlength="191"'); ?>
                <?php $input('action_qty', I18n::t('admin.promo.action_qty'), $f['action_qty'], 'type="number" min="1"'); ?>
            </div>
            <h4><?= esc(I18n::t('admin.promo.conditions')) ?></h4>
            <div class="grid grid-4">
                <?php $input('cond_min_qty', I18n::t('admin.promo.cond_min_qty'), $f['cond_min_qty'], 'type="number" min="1"'); ?>
                <?php $input('cond_product_skus', I18n::t('admin.promo.cond_product_skus'), $f['cond_product_skus']); ?>
                <?php $multi('cond_category_ids', I18n::t('admin.promo.cond_category_ids'), array_column($categories, 'path', 'id'), $f['cond_category_ids']); ?>
                <?php $multi('cond_brand_ids', I18n::t('admin.promo.cond_brand_ids'), array_column($brands, 'name', 'id'), $f['cond_brand_ids']); ?>
                <?php $input('cond_min_subtotal', I18n::t('admin.promo.cond_min_subtotal'), $f['cond_min_subtotal'], 'type="number" min="1"'); ?>
                <?php $input('cond_buy_sku', I18n::t('admin.promo.cond_buy_sku'), $f['cond_buy_sku'], 'maxlength="191"'); ?>
                <?php $input('cond_buy_qty', I18n::t('admin.promo.cond_buy_qty'), $f['cond_buy_qty'], 'type="number" min="1"'); ?>
                <?php $input('cond_max_uses', I18n::t('admin.promo.cond_max_uses'), $f['cond_max_uses'], 'type="number" min="1"'); ?>
                <?php $input('cond_per_customer', I18n::t('admin.promo.cond_per_customer'), $f['cond_per_customer'], 'type="number" min="1"'); ?>
                <?php $multi('cond_customer_group_ids', I18n::t('admin.promo.cond_customer_group_ids'), array_column($groups, 'name', 'id'), $f['cond_customer_group_ids']); ?>
            </div>
            <div class="form-row"><label><input type="checkbox" name="active" value="1" <?= $f['active'] === '1' ? 'checked' : '' ?>> <?= esc(I18n::t('admin.common.active_f')) ?></label></div>
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.btn.save')) ?></button>
            <?php if ($edit): ?><a href="admin.php?p=promotions" class="btn" style="margin-left:.5rem"><?= esc(I18n::t('admin.promo.new')) ?></a><?php endif; ?>
        </form>
    </div>
    <div class="card table-scroll">
        <table>
            <thead><tr><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.common.code')) ?></th><th><?= esc(I18n::t('admin.promo.scope')) ?></th><th><?= esc(I18n::t('admin.promo.coupon')) ?></th><th><?= esc(I18n::t('admin.common.action')) ?></th><th><?= esc(I18n::t('admin.common.priority')) ?></th><th><?= esc(I18n::t('admin.pricing.validity')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($promotions as $p): $action = json_decode((string)$p['actions'], true) ?: []; ?>
            <tr>
                <td><a href="admin.php?p=promotions&id=<?= (int)$p['id'] ?>"><?= esc($p['name']) ?></a></td>
                <td><code><?= esc($p['code']) ?></code></td>
                <td><?= esc(I18n::t($p['scope'] === 'cart' ? 'admin.promo.scope_cart_short' : 'admin.promo.scope_catalog_short')) ?></td>
                <td><?= $p['coupon_code'] !== null && $p['coupon_code'] !== '' ? '<code>' . esc($p['coupon_code']) . '</code>' : '' ?></td>
                <td><?= esc(isset(PricingAdmin::ACTION_LABELS[$action['type'] ?? '']) ? I18n::t('admin.promo.action.' . $action['type']) : (string)($action['type'] ?? '')) ?></td>
                <td><?= (int)$p['priority'] ?></td>
                <td><?= esc((string)($p['valid_from'] ?? '—')) ?> – <?= esc((string)($p['valid_to'] ?? '—')) ?></td>
                <td><span class="badge badge-<?= $p['active'] ? 'active' : 'draft' ?>"><?= esc(I18n::t($p['active'] ? 'admin.common.status_active_f' : 'admin.common.status_inactive_f')) ?></span></td>
                <td>
                    <form method="post" action="admin.php?p=promotions&action=delete" style="display:inline" data-confirm="<?= esc(I18n::t('admin.promo.delete_confirm')) ?>">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="promotion_id" value="<?= (int)$p['id'] ?>">
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

function pagePriceLists(): void {
    $lists = PricingAdmin::priceLists();
    $id = Security::int($_GET['id'] ?? 0);
    $edit = $id ? PricingAdmin::priceList($id) : null;
    $groups = PricingAdmin::customerGroups();
    $currencies = PricingAdmin::currencies();
    $date = static fn(?string $v): string => $v === null || $v === '' ? '' : str_replace(' ', 'T', substr($v, 0, 19));
    ?>
    <h1><?= esc(I18n::t('admin.title.price_lists')) ?></h1>
    <div class="card">
        <h3><?= esc($edit ? I18n::format('admin.common.edit_named', ['name' => (string)$edit['name']]) : I18n::t('admin.pricelist.new')) ?></h3>
        <p class="muted"><?= esc(I18n::t('admin.pricelist.hint')) ?></p>
        <form method="post" action="admin.php?p=price_lists&action=save<?= $edit ? '&id=' . (int)$edit['id'] : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="grid grid-4">
                <div class="form-row"><label for="pl-name"><?= esc(I18n::t('admin.th.name')) ?></label><input id="pl-name" name="name" value="<?= esc((string)($edit['name'] ?? '')) ?>" required maxlength="191"></div>
                <div class="form-row"><label for="pl-code"><?= esc(I18n::t('admin.taxonomy.code_hint')) ?></label><input id="pl-code" name="code" value="<?= esc((string)($edit['code'] ?? '')) ?>" maxlength="191"></div>
                <div class="form-row"><label for="pl-currency"><?= esc(I18n::t('admin.reports.currency')) ?></label>
                    <select id="pl-currency" name="currency">
                        <?php foreach ($currencies as $currency): ?>
                        <option value="<?= esc($currency) ?>" <?= strtoupper((string)($edit['currency'] ?? '')) === $currency ? 'selected' : '' ?>><?= esc($currency) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label for="pl-group"><?= esc(I18n::t('admin.b2b.customer_group')) ?></label>
                    <select id="pl-group" name="customer_group_id">
                        <option value=""><?= esc(I18n::t('admin.pricelist.all_customers')) ?></option>
                        <?php foreach ($groups as $g): ?>
                        <option value="<?= (int)$g['id'] ?>" <?= (int)($edit['customer_group_id'] ?? 0) === (int)$g['id'] ? 'selected' : '' ?>><?= esc($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label for="pl-priority"><?= esc(I18n::t('admin.pricelist.priority_hint')) ?></label><input id="pl-priority" type="number" name="priority" value="<?= (int)($edit['priority'] ?? 0) ?>"></div>
                <div class="form-row"><label for="pl-from"><?= esc(I18n::t('admin.pricing.valid_from')) ?></label><input id="pl-from" type="datetime-local" step="1" name="valid_from" value="<?= esc($date($edit['valid_from'] ?? null)) ?>"></div>
                <div class="form-row"><label for="pl-to"><?= esc(I18n::t('admin.pricing.valid_to')) ?></label><input id="pl-to" type="datetime-local" step="1" name="valid_to" value="<?= esc($date($edit['valid_to'] ?? null)) ?>"></div>
            </div>
            <div class="form-row"><label><input type="checkbox" name="active" value="1" <?= !$edit || !empty($edit['active']) ? 'checked' : '' ?>> <?= esc(I18n::t('admin.common.active_m')) ?></label></div>
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.btn.save')) ?></button>
            <?php if ($edit): ?><a href="admin.php?p=price_lists" class="btn" style="margin-left:.5rem"><?= esc(I18n::t('admin.pricelist.new')) ?></a><?php endif; ?>
        </form>
    </div>
    <?php if ($edit): ?>
    <div class="card">
        <h3><?= esc(I18n::format('admin.pricelist.prices_of', ['code' => (string)$edit['code']])) ?></h3>
        <form method="post" action="admin.php?p=price_lists&action=prices&id=<?= (int)$edit['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="form-row"><label for="pl-prices"><?= esc(I18n::t('admin.pricelist.prices_hint')) ?></label>
                <textarea id="pl-prices" name="prices" rows="10" style="width:100%;font-family:monospace"><?= esc(PricingAdmin::priceLines((int)$edit['id'])) ?></textarea>
            </div>
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.pricelist.save_prices')) ?></button>
        </form>
    </div>
    <?php endif; ?>
    <div class="card table-scroll">
        <table>
            <thead><tr><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.common.code')) ?></th><th><?= esc(I18n::t('admin.reports.currency')) ?></th><th><?= esc(I18n::t('admin.pricelist.group')) ?></th><th><?= esc(I18n::t('admin.common.priority')) ?></th><th><?= esc(I18n::t('admin.pricing.validity')) ?></th><th><?= esc(I18n::t('admin.pricelist.prices')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th></th></tr></thead>
            <tbody>
            <?php $groupNames = array_column($groups, 'name', 'id'); foreach ($lists as $l): ?>
            <tr>
                <td><a href="admin.php?p=price_lists&id=<?= (int)$l['id'] ?>"><?= esc($l['name']) ?></a></td>
                <td><code><?= esc($l['code']) ?></code></td>
                <td><?= esc(strtoupper((string)$l['currency'])) ?></td>
                <td><?= $l['customer_group_id'] === null ? esc(I18n::t('admin.pricelist.all')) : esc((string)($groupNames[(int)$l['customer_group_id']] ?? '#' . (int)$l['customer_group_id'])) ?></td>
                <td><?= (int)$l['priority'] ?></td>
                <td><?= esc((string)($l['valid_from'] ?? '—')) ?> – <?= esc((string)($l['valid_to'] ?? '—')) ?></td>
                <td><?= (int)$l['price_count'] ?></td>
                <td><span class="badge badge-<?= $l['active'] ? 'active' : 'draft' ?>"><?= esc(I18n::t($l['active'] ? 'admin.common.status_active_m' : 'admin.common.status_inactive_m')) ?></span></td>
                <td>
                    <form method="post" action="admin.php?p=price_lists&action=delete" style="display:inline" data-confirm="<?= esc(I18n::t('admin.pricelist.delete_confirm')) ?>">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="price_list_id" value="<?= (int)$l['id'] ?>">
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

function handlePromotionSave(): void {
    taxonomyRedirect('promotions', PricingAdmin::savePromotion(Security::int($_GET['id'] ?? 0), $_POST));
}

function handlePromotionDelete(): void {
    taxonomyRedirect('promotions', PricingAdmin::deletePromotion(Security::int($_POST['promotion_id'] ?? 0)));
}

function handlePriceListSave(): void {
    taxonomyRedirect('price_lists', PricingAdmin::savePriceList(Security::int($_GET['id'] ?? 0), $_POST));
}

function handlePriceListPrices(): void {
    taxonomyRedirect('price_lists', PricingAdmin::savePrices(Security::int($_GET['id'] ?? 0), $_POST));
}

function handlePriceListDelete(): void {
    taxonomyRedirect('price_lists', PricingAdmin::deletePriceList(Security::int($_POST['price_list_id'] ?? 0)));
}
