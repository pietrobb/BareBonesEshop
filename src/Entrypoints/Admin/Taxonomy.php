<?php
/** Admin screens for the categories and brands of the active catalog; validation and writes live in CatalogTaxonomy. */

function pageCategories(): void {
    $categories = CatalogTaxonomy::categories();
    $id = Security::int($_GET['id'] ?? 0);
    $edit = $id ? (array_column($categories, null, 'id')[$id] ?? null) : null;
    $tr = json_decode((string)($edit['translations'] ?? '{}'), true) ?: [];
    // The category itself and its subtree cannot become its parent.
    $blocked = $edit ? array_map('intval', array_column(array_filter($categories, static fn(array $c): bool => str_starts_with($c['path'] . '/', $edit['path'] . '/')), 'id')) : [];
    ?>
    <h1><?= esc(I18n::t('admin.title.categories')) ?></h1>
    <div class="card">
        <h3><?= $edit ? esc(strtr(I18n::t('admin.common.edit_named'), ['{name}' => $edit['name']])) : esc(I18n::t('admin.taxonomy.category_new')) ?></h3>
        <form method="post" action="admin.php?p=categories&action=save<?= $edit ? '&id=' . (int)$edit['id'] : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="form-row"><label for="cat-name"><?= esc(I18n::t('admin.th.name')) ?></label><input id="cat-name" name="name" value="<?= esc($edit['name'] ?? '') ?>" required maxlength="191" style="width:100%"></div>
            <div class="form-row"><label for="cat-slug"><?= esc(I18n::t('admin.taxonomy.slug_hint')) ?></label><input id="cat-slug" name="slug" value="<?= esc($edit['slug'] ?? '') ?>" maxlength="191"></div>
            <div class="form-row"><label for="cat-parent"><?= esc(I18n::t('admin.taxonomy.parent')) ?></label>
                <select id="cat-parent" name="parent_id">
                    <option value="">— <?= esc(I18n::t('admin.taxonomy.root')) ?> —</option>
                    <?php foreach ($categories as $c): if (in_array((int)$c['id'], $blocked, true)) continue; ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $edit && (int)$edit['parent_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= str_repeat('— ', (int)$c['depth']) . esc($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label for="cat-sort"><?= esc(I18n::t('admin.common.sort_order')) ?></label><input id="cat-sort" type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? 0) ?>"></div>
            <?php foreach (ProductEditor::locales() as $locale): ?>
            <div class="form-row"><label for="cat-tr-<?= esc($locale) ?>"><?= esc(I18n::t('admin.th.name')) ?> (<?= esc(strtoupper($locale)) ?>)</label><input id="cat-tr-<?= esc($locale) ?>" name="tr[<?= esc($locale) ?>][name]" value="<?= esc((string)($tr[$locale]['name'] ?? '')) ?>" maxlength="191" style="width:100%"></div>
            <?php endforeach; ?>
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.btn.save')) ?></button>
            <?php if ($edit): ?><a href="admin.php?p=categories" class="btn" style="margin-left:.5rem"><?= esc(I18n::t('admin.taxonomy.category_new')) ?></a><?php endif; ?>
        </form>
    </div>
    <div class="card table-scroll">
        <table>
            <thead><tr><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.common.path')) ?></th><th><?= esc(I18n::t('admin.common.sort_order')) ?></th><th><?= esc(I18n::t('admin.taxonomy.subcategories')) ?></th><th><?= esc(I18n::t('admin.common.products')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($categories as $c): ?>
            <tr>
                <td><?= str_repeat('— ', (int)$c['depth']) ?><a href="admin.php?p=categories&id=<?= (int)$c['id'] ?>"><?= esc($c['name']) ?></a></td>
                <td><code><?= esc($c['path']) ?></code></td>
                <td><?= (int)$c['sort_order'] ?></td>
                <td><?= (int)$c['child_count'] ?></td>
                <td><?= (int)$c['product_total'] ?></td>
                <td><?php if (!$c['child_count'] && !$c['product_total']): ?>
                    <form method="post" action="admin.php?p=categories&action=delete" style="display:inline" data-confirm="<?= esc(I18n::t('admin.taxonomy.category_delete_confirm')) ?>">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="category_id" value="<?= (int)$c['id'] ?>">
                        <button class="btn btn-sm btn-danger"><?= esc(I18n::t('admin.btn.delete')) ?></button>
                    </form>
                <?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function pageBrands(): void {
    $brands = CatalogTaxonomy::brands();
    $id = Security::int($_GET['id'] ?? 0);
    $edit = $id ? CatalogTaxonomy::brand($id) : null;
    ?>
    <h1><?= esc(I18n::t('admin.title.brands')) ?></h1>
    <div class="card">
        <h3><?= $edit ? esc(strtr(I18n::t('admin.common.edit_named'), ['{name}' => $edit['name']])) : esc(I18n::t('admin.taxonomy.brand_new')) ?></h3>
        <form method="post" action="admin.php?p=brands&action=save<?= $edit ? '&id=' . (int)$edit['id'] : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="form-row"><label for="brand-name"><?= esc(I18n::t('admin.th.name')) ?></label><input id="brand-name" name="name" value="<?= esc($edit['name'] ?? '') ?>" required maxlength="191" style="width:100%"></div>
            <div class="form-row"><label for="brand-code"><?= esc(I18n::t('admin.taxonomy.code_hint')) ?></label><input id="brand-code" name="code" value="<?= esc($edit['code'] ?? '') ?>" maxlength="191"></div>
            <div class="form-row"><label for="brand-manufacturer"><?= esc(I18n::t('admin.taxonomy.manufacturer')) ?></label><input id="brand-manufacturer" name="manufacturer_name" value="<?= esc((string)($edit['manufacturer_name'] ?? '')) ?>" maxlength="191" style="width:100%"></div>
            <div class="form-row"><label><input type="checkbox" name="active" value="1" <?= !$edit || !empty($edit['active']) ? 'checked' : '' ?>> <?= esc(I18n::t('admin.taxonomy.brand_active_hint')) ?></label></div>
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.btn.save')) ?></button>
            <?php if ($edit): ?><a href="admin.php?p=brands" class="btn" style="margin-left:.5rem"><?= esc(I18n::t('admin.taxonomy.brand_new')) ?></a><?php endif; ?>
        </form>
    </div>
    <div class="card table-scroll">
        <table>
            <thead><tr><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.common.code')) ?></th><th><?= esc(I18n::t('admin.taxonomy.manufacturer')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.common.products')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($brands as $b): ?>
            <tr>
                <td><a href="admin.php?p=brands&id=<?= (int)$b['id'] ?>"><?= esc($b['name']) ?></a></td>
                <td><code><?= esc($b['code']) ?></code></td>
                <td><?= esc((string)($b['manufacturer_name'] ?? '')) ?></td>
                <td><span class="badge badge-<?= $b['active'] ? 'active' : 'draft' ?>"><?= esc(I18n::t($b['active'] ? 'admin.common.enabled' : 'admin.common.disabled')) ?></span></td>
                <td><?= (int)$b['product_total'] ?></td>
                <td><?php if (!$b['product_total']): ?>
                    <form method="post" action="admin.php?p=brands&action=delete" style="display:inline" data-confirm="<?= esc(I18n::t('admin.taxonomy.brand_delete_confirm')) ?>">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="brand_id" value="<?= (int)$b['id'] ?>">
                        <button class="btn btn-sm btn-danger"><?= esc(I18n::t('admin.btn.delete')) ?></button>
                    </form>
                <?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/** Flash the result and redirect back to the list (to the edited row when the save failed or succeeded). */
function taxonomyRedirect(string $page, array $result): never {
    setFlash($result['ok'] ? 'ok' : 'err', $result['message']);
    header("Location: admin.php?p={$page}" . ($result['id'] ? '&id=' . (int)$result['id'] : ''));
    exit;
}

function handleCategorySave(): void {
    taxonomyRedirect('categories', CatalogTaxonomy::saveCategory(Security::int($_GET['id'] ?? 0), $_POST));
}

function handleCategoryDelete(): void {
    taxonomyRedirect('categories', CatalogTaxonomy::deleteCategory(Security::int($_POST['category_id'] ?? 0)));
}

function handleBrandSave(): void {
    taxonomyRedirect('brands', CatalogTaxonomy::saveBrand(Security::int($_GET['id'] ?? 0), $_POST));
}

function handleBrandDelete(): void {
    taxonomyRedirect('brands', CatalogTaxonomy::deleteBrand(Security::int($_POST['brand_id'] ?? 0)));
}
