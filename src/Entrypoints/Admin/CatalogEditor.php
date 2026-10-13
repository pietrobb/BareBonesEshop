<?php
/** Admin product editor sections rendered inside the BBF product form (extra categories, translations). */

/** Extra category picker and per-locale translation fields of the product editor; $categories are the active catalog's. */
function productEditorExtrasHtml(array $categories, ?array $product): string {
    $extraCategoryIds = $product ? ProductEditor::extraCategoryIds((int)$product['id']) : [];
    $translations = json_decode((string)($product['translations'] ?? '{}'), true) ?: [];
    ob_start();
    ?>
        <div class="form-row">
            <label for="extra-categories">Ďalšie kategórie</label>
            <select id="extra-categories" name="extra_category_ids[]" multiple size="6" style="width:100%">
                <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>"<?= in_array((int)$c['id'], $extraCategoryIds, true) ? ' selected' : '' ?>><?= esc($c['name']) ?> (<?= esc($c['path']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <p class="muted">Produkt sa zobrazí aj v týchto kategóriách; hlavná kategória určuje atribúty a drobčekovú navigáciu.</p>
        </div>
        <fieldset style="border-top:2px solid #e2e8f0;margin-top:1rem;padding-top:1rem">
            <legend><strong>Preklady</strong> <span class="muted">(prázdne pole = základný text; prázdny slug sa vytvorí z preloženého názvu)</span></legend>
            <?php foreach (ProductEditor::locales() as $locale): $tr = (array)($translations[$locale] ?? []); ?>
            <details<?= $tr ? ' open' : '' ?>>
                <summary><?= esc(strtoupper($locale)) ?></summary>
                <?php foreach (ProductEditor::TRANSLATION_FIELDS as $field => $label): $name = "tr[{$locale}][{$field}]"; $fieldId = "tr-{$locale}-{$field}"; ?>
                <div class="form-row">
                    <label for="<?= esc($fieldId) ?>"><?= esc($label) ?> (<?= esc($locale) ?>)</label>
                    <?php if (in_array($field, ['summary', 'description', 'meta_description'], true)): ?>
                    <textarea id="<?= esc($fieldId) ?>" name="<?= esc($name) ?>" rows="<?= $field === 'description' ? 4 : 2 ?>"><?= esc((string)($tr[$field] ?? '')) ?></textarea>
                    <?php else: ?>
                    <input id="<?= esc($fieldId) ?>" name="<?= esc($name) ?>" value="<?= esc((string)($tr[$field] ?? '')) ?>">
                    <?php endif; ?>
                </div>
                <?php endforeach; $fieldId = "tr-{$locale}-image_alts"; ?>
                <div class="form-row">
                    <label for="<?= esc($fieldId) ?>">Alt texty obrázkov (<?= esc($locale) ?>)</label>
                    <textarea id="<?= esc($fieldId) ?>" name="<?= esc("tr[{$locale}][image_alts]") ?>" rows="2" placeholder="URL obrázka | alt text"><?= esc(ProductEditor::imagesText(array_keys((array)($tr['image_alts'] ?? [])), (array)($tr['image_alts'] ?? []))) ?></textarea>
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
            <h3 style="margin-bottom:.75rem">Varianty</h3>
            <form method="post" action="admin.php?p=product&id=<?= (int)$product['id'] ?>&action=variants_save">
                <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>SKU</th><th>Názov</th><th>Atribúty (kľúč=hodnota)</th><th>Cena (centy)</th><th>Príplatok (centy)</th><th>Sklad</th><th>Dostupnosť</th><th>Politika</th><th>Poradie</th><th>Zmazať</th></tr></thead>
                    <tbody>
                    <?php foreach ($variants as $v): $key = (string)$v['id']; $p = "variants[{$key}]"; $label = $key === 'new' ? 'nový variant' : (string)$v['sku']; ?>
                    <tr>
                        <td><input name="<?= esc("{$p}[sku]") ?>" value="<?= esc((string)$v['sku']) ?>" maxlength="100" aria-label="SKU <?= esc($label) ?>"<?= $key === 'new' ? ' placeholder="nový variant"' : ' required' ?>></td>
                        <td><input name="<?= esc("{$p}[name]") ?>" value="<?= esc((string)$v['name']) ?>" maxlength="255" aria-label="Názov <?= esc($label) ?>"></td>
                        <td><textarea name="<?= esc("{$p}[attrs]") ?>" rows="2" aria-label="Atribúty <?= esc($label) ?>"><?= esc((string)$v['attrs_text']) ?></textarea></td>
                        <td><input name="<?= esc("{$p}[price_absolute]") ?>" value="<?= esc($v['price_absolute'] === null ? '' : (string)$v['price_absolute']) ?>" inputmode="numeric" aria-label="Cena <?= esc($label) ?>"></td>
                        <td><input name="<?= esc("{$p}[price_delta]") ?>" value="<?= (int)$v['price_delta'] ?>" inputmode="numeric" aria-label="Príplatok <?= esc($label) ?>"></td>
                        <td><input name="<?= esc("{$p}[stock]") ?>" value="<?= (int)$v['stock'] ?>" inputmode="numeric" aria-label="Sklad <?= esc($label) ?>"></td>
                        <td><?= $select("{$p}[availability]", ProductVariants::availabilities(), (string)$v['availability'], "Dostupnosť {$label}") ?></td>
                        <td><?= $select("{$p}[inventory_policy]", ProductVariants::POLICIES, (string)$v['inventory_policy'], "Politika {$label}") ?></td>
                        <td><input name="<?= esc("{$p}[sort_order]") ?>" value="<?= (int)$v['sort_order'] ?>" inputmode="numeric" size="3" aria-label="Poradie <?= esc($label) ?>"></td>
                        <td><?php if ($key !== 'new'): ?><input type="checkbox" name="<?= esc("{$p}[delete]") ?>" value="1" aria-label="Zmazať <?= esc($label) ?>"><?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p class="muted">Prázdna cena = cena produktu + príplatok. Sklad sa účtuje cez skladovú evidenciu. Variant z objednávok sa nedá zmazať — nastavte dostupnosť discontinued.</p>
                <button class="btn btn-primary" type="submit">Uložiť varianty</button>
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
