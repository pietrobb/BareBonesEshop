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
    <h1>Promo akcie a kupóny</h1>
    <div class="card">
        <h3><?= $edit ? 'Upraviť: ' . esc($edit['name']) : 'Nová promo akcia' ?></h3>
        <p class="muted">Sumy v centoch, percentá v % (napr. 12,5), platnosť v UTC. Promo akcia katalógu mení cenu produktu (výpis, detail, košík); promo akcia košíka zľavňuje objednávku.
            Kupónový kód má len promo akcia košíka — uplatní sa iba po zadaní kódu v pokladni.</p>
        <form method="post" action="admin.php?p=promotions&action=save<?= $edit ? '&id=' . (int)$edit['id'] : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="grid grid-4">
                <?php $input('name', 'Názov', $f['name'], 'required maxlength="191"'); ?>
                <?php $input('code', 'Kód (prázdny = z názvu)', $f['code'], 'maxlength="191"'); ?>
                <div class="form-row"><label for="promo-scope">Rozsah</label>
                    <select id="promo-scope" name="scope">
                        <option value="catalog" <?= $f['scope'] === 'catalog' ? 'selected' : '' ?>>Katalóg (cena produktu)</option>
                        <option value="cart" <?= $f['scope'] === 'cart' ? 'selected' : '' ?>>Košík (zľava objednávky)</option>
                    </select>
                </div>
                <?php $input('coupon_code', 'Kupónový kód (len košík)', $f['coupon_code'], 'maxlength="64" pattern="[A-Za-z0-9_\-]{2,64}"'); ?>
                <?php $input('priority', 'Priorita (nižšia skôr)', $f['priority'], 'type="number"'); ?>
                <div class="form-row"><label for="promo-stacking">Kombinovanie</label>
                    <select id="promo-stacking" name="stacking">
                        <option value="exclusive" <?= $f['stacking'] === 'exclusive' ? 'selected' : '' ?>>Výhradná (ďalšie sa nepoužijú)</option>
                        <option value="stack" <?= $f['stacking'] === 'stack' ? 'selected' : '' ?>>Kombinovateľná</option>
                    </select>
                </div>
                <?php $input('valid_from', 'Platí od (UTC)', $f['valid_from'], 'type="datetime-local" step="1"'); ?>
                <?php $input('valid_to', 'Platí do (UTC)', $f['valid_to'], 'type="datetime-local" step="1"'); ?>
            </div>
            <h4>Akcia</h4>
            <div class="grid grid-4">
                <div class="form-row"><label for="promo-action_type">Typ</label>
                    <select id="promo-action_type" name="action_type">
                        <?php foreach (PricingAdmin::ACTION_LABELS as $type => $label): ?>
                        <option value="<?= esc($type) ?>" <?= $f['action_type'] === $type ? 'selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php $input('action_value', 'Hodnota (% alebo centy)', $f['action_value']); ?>
                <?php $input('action_sku', 'SKU produktu zadarmo / darčeka', $f['action_sku'], 'maxlength="191"'); ?>
                <?php $input('action_qty', 'Počet kusov zadarmo / darčekov', $f['action_qty'], 'type="number" min="1"'); ?>
            </div>
            <h4>Podmienky</h4>
            <div class="grid grid-4">
                <?php $input('cond_min_qty', 'Katalóg: min. množstvo', $f['cond_min_qty'], 'type="number" min="1"'); ?>
                <?php $input('cond_product_skus', 'Katalóg: SKU produktov (čiarkou)', $f['cond_product_skus']); ?>
                <?php $multi('cond_category_ids', 'Katalóg: kategórie', array_column($categories, 'path', 'id'), $f['cond_category_ids']); ?>
                <?php $multi('cond_brand_ids', 'Katalóg: značky', array_column($brands, 'name', 'id'), $f['cond_brand_ids']); ?>
                <?php $input('cond_min_subtotal', 'Košík: min. suma (centy)', $f['cond_min_subtotal'], 'type="number" min="1"'); ?>
                <?php $input('cond_buy_sku', 'Kúp X: SKU kúpeného produktu', $f['cond_buy_sku'], 'maxlength="191"'); ?>
                <?php $input('cond_buy_qty', 'Kúp X: počet kúpených kusov', $f['cond_buy_qty'], 'type="number" min="1"'); ?>
                <?php $input('cond_max_uses', 'Kupón: max. použití spolu', $f['cond_max_uses'], 'type="number" min="1"'); ?>
                <?php $input('cond_per_customer', 'Kupón: max. použití na zákazníka', $f['cond_per_customer'], 'type="number" min="1"'); ?>
                <?php $multi('cond_customer_group_ids', 'Len pre zákaznícke skupiny', array_column($groups, 'name', 'id'), $f['cond_customer_group_ids']); ?>
            </div>
            <div class="form-row"><label><input type="checkbox" name="active" value="1" <?= $f['active'] === '1' ? 'checked' : '' ?>> Aktívna</label></div>
            <button class="btn btn-primary" type="submit">Uložiť</button>
            <?php if ($edit): ?><a href="admin.php?p=promotions" class="btn" style="margin-left:.5rem">Nová promo akcia</a><?php endif; ?>
        </form>
    </div>
    <div class="card table-scroll">
        <table>
            <thead><tr><th>Názov</th><th>Kód</th><th>Rozsah</th><th>Kupón</th><th>Akcia</th><th>Priorita</th><th>Platnosť (UTC)</th><th>Stav</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($promotions as $p): $action = json_decode((string)$p['actions'], true) ?: []; ?>
            <tr>
                <td><a href="admin.php?p=promotions&id=<?= (int)$p['id'] ?>"><?= esc($p['name']) ?></a></td>
                <td><code><?= esc($p['code']) ?></code></td>
                <td><?= $p['scope'] === 'cart' ? 'košík' : 'katalóg' ?></td>
                <td><?= $p['coupon_code'] !== null && $p['coupon_code'] !== '' ? '<code>' . esc($p['coupon_code']) . '</code>' : '' ?></td>
                <td><?= esc(PricingAdmin::ACTION_LABELS[$action['type'] ?? ''] ?? (string)($action['type'] ?? '')) ?></td>
                <td><?= (int)$p['priority'] ?></td>
                <td><?= esc((string)($p['valid_from'] ?? '—')) ?> – <?= esc((string)($p['valid_to'] ?? '—')) ?></td>
                <td><span class="badge badge-<?= $p['active'] ? 'active' : 'draft' ?>"><?= $p['active'] ? 'aktívna' : 'neaktívna' ?></span></td>
                <td>
                    <form method="post" action="admin.php?p=promotions&action=delete" style="display:inline" data-confirm="Zmazať promo akciu?">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="promotion_id" value="<?= (int)$p['id'] ?>">
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

function pagePriceLists(): void {
    $lists = PricingAdmin::priceLists();
    $id = Security::int($_GET['id'] ?? 0);
    $edit = $id ? PricingAdmin::priceList($id) : null;
    $groups = PricingAdmin::customerGroups();
    $currencies = PricingAdmin::currencies();
    $date = static fn(?string $v): string => $v === null || $v === '' ? '' : str_replace(' ', 'T', substr($v, 0, 19));
    ?>
    <h1>Cenníky</h1>
    <div class="card">
        <h3><?= $edit ? 'Upraviť: ' . esc($edit['name']) : 'Nový cenník' ?></h3>
        <p class="muted">Cena z cenníka nahrádza základnú cenu produktu v jeho mene. Prednosť má cenník zákazníckej skupiny, potom cena variantu, vyššie minimálne množstvo a vyššia priorita.</p>
        <form method="post" action="admin.php?p=price_lists&action=save<?= $edit ? '&id=' . (int)$edit['id'] : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="grid grid-4">
                <div class="form-row"><label for="pl-name">Názov</label><input id="pl-name" name="name" value="<?= esc((string)($edit['name'] ?? '')) ?>" required maxlength="191"></div>
                <div class="form-row"><label for="pl-code">Kód (prázdny = z názvu)</label><input id="pl-code" name="code" value="<?= esc((string)($edit['code'] ?? '')) ?>" maxlength="191"></div>
                <div class="form-row"><label for="pl-currency">Mena</label>
                    <select id="pl-currency" name="currency">
                        <?php foreach ($currencies as $currency): ?>
                        <option value="<?= esc($currency) ?>" <?= strtoupper((string)($edit['currency'] ?? '')) === $currency ? 'selected' : '' ?>><?= esc($currency) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label for="pl-group">Zákaznícka skupina</label>
                    <select id="pl-group" name="customer_group_id">
                        <option value="">— všetci zákazníci —</option>
                        <?php foreach ($groups as $g): ?>
                        <option value="<?= (int)$g['id'] ?>" <?= (int)($edit['customer_group_id'] ?? 0) === (int)$g['id'] ? 'selected' : '' ?>><?= esc($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label for="pl-priority">Priorita (vyššia vyhráva)</label><input id="pl-priority" type="number" name="priority" value="<?= (int)($edit['priority'] ?? 0) ?>"></div>
                <div class="form-row"><label for="pl-from">Platí od (UTC)</label><input id="pl-from" type="datetime-local" step="1" name="valid_from" value="<?= esc($date($edit['valid_from'] ?? null)) ?>"></div>
                <div class="form-row"><label for="pl-to">Platí do (UTC)</label><input id="pl-to" type="datetime-local" step="1" name="valid_to" value="<?= esc($date($edit['valid_to'] ?? null)) ?>"></div>
            </div>
            <div class="form-row"><label><input type="checkbox" name="active" value="1" <?= !$edit || !empty($edit['active']) ? 'checked' : '' ?>> Aktívny</label></div>
            <button class="btn btn-primary" type="submit">Uložiť</button>
            <?php if ($edit): ?><a href="admin.php?p=price_lists" class="btn" style="margin-left:.5rem">Nový cenník</a><?php endif; ?>
        </form>
    </div>
    <?php if ($edit): ?>
    <div class="card">
        <h3>Ceny cenníka <?= esc($edit['code']) ?></h3>
        <form method="post" action="admin.php?p=price_lists&action=prices&id=<?= (int)$edit['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="form-row"><label for="pl-prices">Jeden riadok na cenu: SKU produktu alebo variantu | min. množstvo | cena v centoch [| pôvodná cena v centoch]</label>
                <textarea id="pl-prices" name="prices" rows="10" style="width:100%;font-family:monospace"><?= esc(PricingAdmin::priceLines((int)$edit['id'])) ?></textarea>
            </div>
            <button class="btn btn-primary" type="submit">Uložiť ceny</button>
        </form>
    </div>
    <?php endif; ?>
    <div class="card table-scroll">
        <table>
            <thead><tr><th>Názov</th><th>Kód</th><th>Mena</th><th>Skupina</th><th>Priorita</th><th>Platnosť (UTC)</th><th>Ceny</th><th>Stav</th><th></th></tr></thead>
            <tbody>
            <?php $groupNames = array_column($groups, 'name', 'id'); foreach ($lists as $l): ?>
            <tr>
                <td><a href="admin.php?p=price_lists&id=<?= (int)$l['id'] ?>"><?= esc($l['name']) ?></a></td>
                <td><code><?= esc($l['code']) ?></code></td>
                <td><?= esc(strtoupper((string)$l['currency'])) ?></td>
                <td><?= $l['customer_group_id'] === null ? 'všetci' : esc((string)($groupNames[(int)$l['customer_group_id']] ?? '#' . (int)$l['customer_group_id'])) ?></td>
                <td><?= (int)$l['priority'] ?></td>
                <td><?= esc((string)($l['valid_from'] ?? '—')) ?> – <?= esc((string)($l['valid_to'] ?? '—')) ?></td>
                <td><?= (int)$l['price_count'] ?></td>
                <td><span class="badge badge-<?= $l['active'] ? 'active' : 'draft' ?>"><?= $l['active'] ? 'aktívny' : 'neaktívny' ?></span></td>
                <td>
                    <form method="post" action="admin.php?p=price_lists&action=delete" style="display:inline" data-confirm="Zmazať cenník aj s cenami?">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="price_list_id" value="<?= (int)$l['id'] ?>">
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
