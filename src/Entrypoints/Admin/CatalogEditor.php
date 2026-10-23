<?php
/** Admin product editor sections rendered inside the BBF product form (extra categories, translations). */

/** Extra category picker and per-locale translation fields of the product editor; $categories are the active catalog's. */
function productEditorExtrasHtml(array $categories, ?array $product): string {
    $extraCategoryIds = $product ? ProductEditor::extraCategoryIds((int)$product['id']) : [];
    $translations = json_decode((string)($product['translations'] ?? '{}'), true) ?: [];
    ob_start();
    ?>
        <div class="form-row">
            <label for="extra-categories"><?= esc(I18n::t('admin.product.extra_categories')) ?></label>
            <select id="extra-categories" name="extra_category_ids[]" multiple size="6" style="width:100%">
                <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>"<?= in_array((int)$c['id'], $extraCategoryIds, true) ? ' selected' : '' ?>><?= esc($c['name']) ?> (<?= esc($c['path']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <p class="muted"><?= esc(I18n::t('admin.product.extra_categories_hint')) ?></p>
        </div>
        <fieldset style="border-top:2px solid #e2e8f0;margin-top:1rem;padding-top:1rem">
            <legend><strong><?= esc(I18n::t('admin.product.translations')) ?></strong> <span class="muted"><?= esc(I18n::t('admin.product.translations_hint')) ?></span></legend>
            <?php foreach (ProductEditor::locales() as $locale): $tr = (array)($translations[$locale] ?? []); ?>
            <details<?= $tr ? ' open' : '' ?>>
                <summary><?= esc(strtoupper($locale)) ?></summary>
                <?php foreach (ProductEditor::TRANSLATION_FIELDS as $field): $name = "tr[{$locale}][{$field}]"; $fieldId = "tr-{$locale}-{$field}"; ?>
                <div class="form-row">
                    <label for="<?= esc($fieldId) ?>"><?= esc(I18n::t('admin.product.field.' . $field)) ?> (<?= esc($locale) ?>)</label>
                    <?php if (in_array($field, ['summary', 'description', 'meta_description'], true)): ?>
                    <textarea id="<?= esc($fieldId) ?>" name="<?= esc($name) ?>" rows="<?= $field === 'description' ? 4 : 2 ?>"><?= esc((string)($tr[$field] ?? '')) ?></textarea>
                    <?php else: ?>
                    <input id="<?= esc($fieldId) ?>" name="<?= esc($name) ?>" value="<?= esc((string)($tr[$field] ?? '')) ?>">
                    <?php endif; ?>
                </div>
                <?php endforeach; $fieldId = "tr-{$locale}-image_alts"; ?>
                <div class="form-row">
                    <label for="<?= esc($fieldId) ?>"><?= esc(I18n::t('admin.product.image_alts')) ?> (<?= esc($locale) ?>)</label>
                    <textarea id="<?= esc($fieldId) ?>" name="<?= esc("tr[{$locale}][image_alts]") ?>" rows="2" placeholder="<?= esc(I18n::t('admin.product.image_alts_placeholder')) ?>"><?= esc(ProductEditor::imagesText(array_keys((array)($tr['image_alts'] ?? [])), (array)($tr['image_alts'] ?? []))) ?></textarea>
                </div>
            </details>
            <?php endforeach; ?>
        </fieldset>
    <?php
    return (string)ob_get_clean();
}

/** Variant table of an existing product: edit rows, add one row, delete unused variants. */
function productVariantsHtml(array $product): string {
    $variants = ProductVariants::forEditor((int)$product['id']);
    $variants[] = ProductVariants::blank(count($variants));
    $select = static function(string $name, array $options, string $value, string $label): string {
        $html = '<select name="' . esc($name) . '" aria-label="' . esc($label) . '">';
        foreach ($options as $option) $html .= '<option value="' . esc($option) . '"' . ($option === $value ? ' selected' : '') . '>' . esc($option) . '</option>';
        return $html . '</select>';
    };
    ob_start();
    ?>
        <div class="card" style="margin-top:1rem">
            <h3 style="margin-bottom:.75rem"><?= esc(I18n::t('admin.variant.title')) ?></h3>
            <form method="post" action="admin.php?p=product&id=<?= (int)$product['id'] ?>&action=variants_save">
                <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                <div style="overflow-x:auto">
                <table>
                    <thead><tr><th><?= esc(I18n::t('admin.th.sku')) ?></th><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.variant.attrs')) ?></th><th><?= esc(I18n::t('admin.variant.price')) ?></th><th><?= esc(I18n::t('admin.variant.delta')) ?></th><th><?= esc(I18n::t('admin.th.stock')) ?></th><th><?= esc(I18n::t('admin.variant.availability')) ?></th><th><?= esc(I18n::t('admin.variant.policy')) ?></th><th><?= esc(I18n::t('admin.common.sort_order')) ?></th><th><?= esc(I18n::t('admin.btn.delete')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($variants as $v): $key = (string)$v['id']; $p = "variants[{$key}]"; $label = $key === 'new' ? I18n::t('admin.variant.new') : (string)$v['sku']; ?>
                    <tr>
                        <td><input name="<?= esc("{$p}[sku]") ?>" value="<?= esc((string)$v['sku']) ?>" maxlength="100" aria-label="SKU <?= esc($label) ?>"<?= $key === 'new' ? ' placeholder="' . esc($label) . '"' : ' required' ?>></td>
                        <td><input name="<?= esc("{$p}[name]") ?>" value="<?= esc((string)$v['name']) ?>" maxlength="255" aria-label="<?= esc(I18n::t('admin.th.name') . ' ' . $label) ?>"></td>
                        <td><textarea name="<?= esc("{$p}[attrs]") ?>" rows="2" aria-label="<?= esc(I18n::t('admin.title.attributes') . ' ' . $label) ?>"><?= esc((string)$v['attrs_text']) ?></textarea></td>
                        <td><input name="<?= esc("{$p}[price_absolute]") ?>" value="<?= esc($v['price_absolute'] === null ? '' : (string)$v['price_absolute']) ?>" inputmode="numeric" aria-label="<?= esc(I18n::t('admin.th.price') . ' ' . $label) ?>"></td>
                        <td><input name="<?= esc("{$p}[price_delta]") ?>" value="<?= (int)$v['price_delta'] ?>" inputmode="numeric" aria-label="<?= esc(I18n::t('admin.variant.delta') . ' ' . $label) ?>"></td>
                        <td><input name="<?= esc("{$p}[stock]") ?>" value="<?= (int)$v['stock'] ?>" inputmode="numeric" aria-label="<?= esc(I18n::t('admin.th.stock') . ' ' . $label) ?>"><?php if ($key !== 'new'): ?><input type="hidden" name="<?= esc("{$p}[stock_loaded]") ?>" value="<?= (int)($v['stock_loaded'] ?? $v['stock']) ?>"><?php endif; ?></td>
                        <td><?= $select("{$p}[availability]", ProductVariants::availabilities(), (string)$v['availability'], I18n::t('admin.variant.availability') . ' ' . $label) ?></td>
                        <td><?= $select("{$p}[inventory_policy]", ProductVariants::POLICIES, (string)$v['inventory_policy'], I18n::t('admin.variant.policy') . ' ' . $label) ?></td>
                        <td><input name="<?= esc("{$p}[sort_order]") ?>" value="<?= (int)$v['sort_order'] ?>" inputmode="numeric" size="3" aria-label="<?= esc(I18n::t('admin.common.sort_order') . ' ' . $label) ?>"></td>
                        <td><?php if ($key !== 'new'): ?><input type="checkbox" name="<?= esc("{$p}[delete]") ?>" value="1" aria-label="<?= esc(I18n::t('admin.btn.delete') . ' ' . $label) ?>"><?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p class="muted"><?= esc(I18n::t('admin.variant.hint')) ?></p>
                <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.variant.save')) ?></button>
            </form>
        </div>
    <?php
    return (string)ob_get_clean();
}

function handleProductVariantsSave(): void {
    $id = Security::int($_GET['id'] ?? 0);
    $result = ProductVariants::submit($id, $_POST['variants'] ?? []);
    setFlash($result['ok'] ? 'ok' : 'err', $result['message']);
    header('Location: ' . ($result['found'] ? "admin.php?p=product&id={$id}" : 'admin.php?p=products'));
    exit;
}
