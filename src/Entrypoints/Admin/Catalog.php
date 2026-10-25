<?php
/** Catalog, content, attributes, assets and review administration. */
require_once __DIR__ . '/CatalogEditor.php';
require_once __DIR__ . '/Taxonomy.php';
require_once __DIR__ . '/PricingRules.php';
require_once __DIR__ . '/RichText.php';
/** The one escaping helper the catalog editors' nonce'd inline scripts (product and attribute forms) build markup with. */
const ADMIN_JS_ESC_HTML = "function escHtml(s) { return String(s ?? '').replace(/[&<>\"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '\"': '&quot;', \"'\": '&#39;'}[c]; }); }";
function pageProducts(): void {
    $db = Db::get();
    $page = max(1, Security::int($_GET['page'] ?? 1));
    $perPage = 25;
    $offset = ($page - 1) * $perPage;
    $search = trim(Security::str($_GET['q'] ?? ''));
    $statusFilter = in_array($_GET['status'] ?? '', ['active', 'draft', 'archived'], true) ? $_GET['status'] : '';
    $stockFilter = ($_GET['stock'] ?? '') === 'low' ? 'low' : '';
    // Only the active store's catalog (list and count).
    $where = 'store_id = :catalog';
    $params = [':catalog' => StoreContext::current()->catalogId()];
    if ($search) {
        $where .= " AND (name LIKE :q ESCAPE '!' OR sku LIKE :q2 ESCAPE '!')";
        $escaped = Db::likeLiteral($search);
        $params[':q'] = "%{$escaped}%";
        $params[':q2'] = "%{$escaped}%";
    }
    if ($statusFilter) {
        $where .= " AND status = :status";
        $params[':status'] = $statusFilter;
    }
    // Same thresholds and warehouse availability as the stock e-mails, filtered in SQL (no id list of the whole catalog).
    if ($stockFilter) {
        [$lowSql, $lowParams] = StockAlerts::belowLowProductIdsSql();
        $where .= " AND id IN ({$lowSql})";
        $params += $lowParams;
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE {$where}");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));

    $params[':limit'] = $perPage;
    $params[':offset'] = $offset;
    $stmt = $db->prepare("SELECT * FROM products WHERE {$where} ORDER BY updated_at DESC LIMIT :limit OFFSET :offset");
    $stmt->execute($params);
    $products = $stmt->fetchAll();
    $stockLevels = StockAlerts::levelsForProducts(array_column($products, 'id'));
    ?>
    <h1><?= esc(I18n::t('admin.title.products')) ?> <span class="muted">(<?= $total ?>)</span></h1>
    <?php if (Security::adminCan('products.edit')): ?><p style="margin-bottom:1rem"><a href="admin.php?p=product" class="btn btn-primary" data-new-product><?= esc(I18n::t('admin.title.product_new')) ?></a></p><?php endif; ?>
    <div class="card">
        <form method="get" style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem">
            <input type="hidden" name="p" value="products">
            <input name="q" aria-label="<?= esc(I18n::t('admin.products.search_placeholder')) ?>" value="<?= esc($search) ?>" placeholder="<?= esc(I18n::t('admin.products.search_placeholder')) ?>">
            <select name="status" aria-label="<?= esc(I18n::t('admin.th.status')) ?>">
                <option value="">— <?= esc(I18n::t('admin.th.status')) ?> —</option>
                <option value="active" <?= $statusFilter==='active'?'selected':'' ?>><?= esc(I18n::t('admin.product.status.active')) ?></option>
                <option value="draft" <?= $statusFilter==='draft'?'selected':'' ?>><?= esc(I18n::t('admin.product.status.draft')) ?></option>
                <option value="archived" <?= $statusFilter==='archived'?'selected':'' ?>><?= esc(I18n::t('admin.product.status.archived')) ?></option>
            </select>
            <select name="stock" aria-label="<?= esc(I18n::t('admin.th.stock')) ?>"><option value="">— <?= esc(I18n::t('admin.th.stock')) ?> —</option><option value="low" <?= $stockFilter==='low'?'selected':'' ?>><?= esc(I18n::t('admin.products.filter_low_stock')) ?></option></select>
            <button class="btn" type="submit"><?= esc(I18n::t('admin.btn.apply')) ?></button>
        </form>
        <form method="post" action="admin.php?p=products&action=bulk" id="bulk-form" data-confirm="<?= esc(I18n::t('admin.products.bulk.confirm')) ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:.5rem">
                <select name="bulk_action" aria-label="<?= esc(I18n::t('admin.products.bulk_action')) ?>">
                    <option value="">— <?= esc(I18n::t('admin.products.bulk_action')) ?> —</option>
                    <option value="activate"><?= esc(I18n::t('admin.common.activate')) ?></option>
                    <option value="archive"><?= esc(I18n::t('admin.products.bulk.archive')) ?></option>
                    <option value="delete"><?= esc(I18n::t('admin.btn.delete')) ?></option>
                    <option value="price_up_5"><?= esc(strtr(I18n::t('admin.products.bulk.price'), ['{change}' => '+5%'])) ?></option>
                    <option value="price_up_10"><?= esc(strtr(I18n::t('admin.products.bulk.price'), ['{change}' => '+10%'])) ?></option>
                    <option value="price_up_20"><?= esc(strtr(I18n::t('admin.products.bulk.price'), ['{change}' => '+20%'])) ?></option>
                    <option value="price_down_5"><?= esc(strtr(I18n::t('admin.products.bulk.price'), ['{change}' => '-5%'])) ?></option>
                    <option value="price_down_10"><?= esc(strtr(I18n::t('admin.products.bulk.price'), ['{change}' => '-10%'])) ?></option>
                    <option value="price_down_20"><?= esc(strtr(I18n::t('admin.products.bulk.price'), ['{change}' => '-20%'])) ?></option>
                </select>
                <button type="submit" class="btn btn-sm"><?= esc(I18n::t('admin.btn.apply')) ?></button>
                <input type="hidden" name="q" value="<?= esc($search) ?>">
                <input type="hidden" name="status" value="<?= esc($statusFilter) ?>">
                <input type="hidden" name="stock" value="<?= esc($stockFilter) ?>">
                <input type="hidden" name="page" value="<?= (int)$page ?>">
                <button type="submit" class="btn btn-sm" formaction="admin.php?p=products&amp;action=export" formmethod="post" data-skip-confirm><?= esc(I18n::t('admin.btn.export_csv')) ?></button>
            </div>
        <table>
            <tr><th><input type="checkbox" id="check-all" aria-label="<?= esc(I18n::t('admin.a11y.select_all')) ?>"></th><th><?= esc(I18n::t('admin.th.sku')) ?></th><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.th.price')) ?></th><th><?= esc(I18n::t('admin.th.stock')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.th.actions')) ?></th></tr>
            <?php foreach ($products as $p): ?>
            <tr>
                <td><input type="checkbox" name="product_ids[]" value="<?= $p['id'] ?>" aria-label="<?= esc(strtr(I18n::t('admin.a11y.select_product'), ['{name}' => (string)$p['name']])) ?>" class="product-check"></td>
                <td><?= esc($p['sku']) ?></td>
                <td><a href="admin.php?p=product&id=<?= $p['id'] ?>"><?= esc($p['name']) ?></a></td>
                <td><?= I18n::price((int)$p['price'], $p['currency']) ?></td>
                <td class="<?= isset($stockLevels[(int)$p['id']]) ? 'stock-' . ($stockLevels[(int)$p['id']] === StockAlerts::LOW ? 'low' : 'critical') : '' ?>"><?= (int)$p['stock'] ?></td>
                <td><span class="badge badge-<?= esc($p['status']) ?>"><?= esc(adminCodeLabel('admin.product.status.', (string)$p['status'])) ?></span></td>
                <td>
                    <a href="admin.php?p=product&id=<?= $p['id'] ?>" class="btn btn-sm"><?= esc(I18n::t('admin.btn.edit')) ?></a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </form>
        <script nonce="<?= Security::cspNonce() ?>">document.getElementById('check-all')?.addEventListener('change',function(){document.querySelectorAll('.product-check').forEach(c=>c.checked=this.checked)});</script>
        <?php pagination($page, $pages, "admin.php?p=products&q=" . urlencode($search) . "&status=" . urlencode($statusFilter) . "&stock=" . urlencode($stockFilter)); ?>
    </div>
    <?php
}
/**
 * Stored record (null = new) → values of the admin form's fields, shown on edit and given to the save's validation
 * (prices in cents, product lists one per line / comma-separated).
 */
function adminFormValues(string $formId, ?array $row): array {
    return match ($formId) {
        'admin-product' => [
            'sku'           => $row['sku'] ?? '',
            'name'          => $row['name'] ?? '',
            'slug'          => $row['slug'] ?? '',
            'category_id'   => (string)($row['category_id'] ?? ''),
            'brand_id'      => (string)($row['brand_id'] ?? ''),
            'gtin'          => (string)($row['gtin'] ?? ''),
            'mpn'           => (string)($row['mpn'] ?? ''),
            'seo_title'     => (string)($row['seo_title'] ?? ''),
            'meta_description' => (string)($row['meta_description'] ?? ''),
            'product_type'  => $row['product_type'] ?? 'simple',
            'price'         => (string)(int)($row['price'] ?? 0),
            'compare_price' => isset($row['compare_price']) ? (string)(int)$row['compare_price'] : '',
            'stock'         => (string)(int)($row['stock'] ?? 0),
            'status'        => $row['status'] ?? 'active',
            'summary'       => $row['summary'] ?? '',
            'description'   => $row['description'] ?? '',
            'images'        => ProductEditor::imagesText($row['images'] ?? '[]', $row['image_alts'] ?? null),
            'weight_g'      => (string)(int)($row['weight_g'] ?? 0),
            'stock_low_threshold'      => isset($row['stock_low_threshold']) ? (string)(int)$row['stock_low_threshold'] : '',
            'stock_critical_threshold' => isset($row['stock_critical_threshold']) ? (string)(int)$row['stock_critical_threshold'] : '',
            'tags'          => is_array($tags = json_decode((string)($row['tags'] ?? ''), true)) ? implode(', ', $tags) : (string)($row['tags'] ?? ''),
        ],
        'admin-page' => [
            'slug'      => $row['slug'] ?? '',
            'title'     => $row['title'] ?? '',
            'body'      => $row['body'] ?? '',
            'page_type' => $row['page_type'] ?? 'content',
            'template'  => $row['template'] ?? 'cms',
            'layout'    => $row['layout'] ?? 'storefront',
            'sections'  => $row['sections'] ?? '[]',
            'status'    => $row['status'] ?? 'active',
        ],
        'admin-attribute' => [
            'name'       => $row['name'] ?? '',
            'slug'       => $row['slug'] ?? '',
            'type'       => $row['type'] ?? 'enum',
            'unit'       => $row['unit'] ?? '',
            'sort_order' => (string)(int)($row['sort_order'] ?? 0),
        ],
    };
}
function pageProduct(): void {
    $db = Db::get();
    $id = Security::int($_GET['id'] ?? 0);
    $product = $id ? Products::byId($id) : null;
    if ($id && !$product) { echo '<h1>' . esc(I18n::t('admin.product.label')) . '</h1><div class="card"><p class="muted">' . esc(I18n::t('admin.product.not_found')) . ' <a href="admin.php?p=products">' . esc(I18n::t('admin.common.back_to_list')) . '</a></p></div>'; return; }
    $categories = CatalogScope::categories();
    $relatedProductIds = [];
    $relatedCandidates = [];
    $relationType = Security::slug((string)($_GET['relation_type'] ?? 'related')) ?: 'related';
    if ($id) {
        $relatedStmt = $db->prepare("SELECT related_product_id FROM product_related_products WHERE product_id = :id AND relation_type = :type ORDER BY sort_order");
        $relatedStmt->execute([':id' => $id, ':type' => $relationType]);
        $relatedProductIds = array_map('intval', $relatedStmt->fetchAll(PDO::FETCH_COLUMN));

        $relatedCandidates = CatalogScope::rows("SELECT id, sku, name, product_type FROM products WHERE store_id = :catalog AND status = 'active' AND id != :id ORDER BY product_type, name", [':id' => $id]);
    }

    // Load attributes for product's category (+ all parent categories)
    $catAttrs = [];
    $existingSpecs = [];
    $catId = (int)($product['category_id'] ?? 0);
    if ($catId) {
        $catAttrs = $db->prepare("
            SELECT ad.id, ad.name, ad.slug, ad.type, ad.unit, ad.sort_order
            FROM attribute_definitions ad
            JOIN category_attributes ca ON ca.attribute_id = ad.id
            WHERE ca.category_id = :cid
            ORDER BY ad.sort_order, ad.name
        ");
        $catAttrs->execute([':cid' => $catId]);
        $catAttrs = $catAttrs->fetchAll(PDO::FETCH_ASSOC);
    }
    // Load existing spec values for this product
    if ($id) {
        $specStmt = $db->prepare("SELECT criteria_name, criteria_value, numeric_val FROM product_specifications WHERE product_id = :pid AND lang = 'sk'");
        $specStmt->execute([':pid' => $id]);
        foreach ($specStmt->fetchAll(PDO::FETCH_ASSOC) as $sp) {
            $existingSpecs[$sp['criteria_name']] = $sp;
        }
    }
    // Load enum options for each attribute
    $attrOptions = [];
    foreach ($catAttrs as $a) {
        if ($a['type'] === 'enum') {
            $optStmt = $db->prepare("SELECT value FROM attribute_options WHERE attribute_id = :aid ORDER BY sort_order, value");
            $optStmt->execute([':aid' => $a['id']]);
            $attrOptions[$a['id']] = $optStmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }
    // Build JSON map: category_id => [{id, name, slug, type, unit, options}]
    $allCatAttrs = $db->query("
        SELECT ca.category_id, ad.id, ad.name, ad.slug, ad.type, ad.unit
        FROM category_attributes ca
        JOIN attribute_definitions ad ON ad.id = ca.attribute_id
        ORDER BY ad.sort_order, ad.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $catAttrMap = [];
    foreach ($allCatAttrs as $row) {
        $entry = ['id' => (int)$row['id'], 'name' => $row['name'], 'slug' => $row['slug'], 'type' => $row['type'], 'unit' => $row['unit']];
        if ($row['type'] === 'enum') {
            $entry['options'] = $attrOptions[$row['id']] ?? [];
            if (empty($entry['options'])) {
                $optStmt = $db->prepare("SELECT value FROM attribute_options WHERE attribute_id = :aid ORDER BY sort_order, value");
                $optStmt->execute([':aid' => $row['id']]);
                $entry['options'] = $optStmt->fetchAll(PDO::FETCH_COLUMN);
                $attrOptions[$row['id']] = $entry['options'];
            }
        }
        $catAttrMap[$row['category_id']][] = $entry;
    }
    ?>
    <h1><?= $product ? esc(strtr(I18n::t('admin.common.edit_named'), ['{name}' => $product['name']])) : esc(I18n::t('admin.title.product_new')) ?></h1>
    <?php if ($product): ?><form method="post" action="admin.php?p=product&amp;id=<?= (int)$product['id'] ?>&amp;action=duplicate"><input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>"><button class="btn btn-sm" type="submit"><?= esc(I18n::t('admin.product.duplicate')) ?></button></form><?php endif; ?>
    <div class="card">
        <?php ob_start(); ?>
        <?= productEditorExtrasHtml($categories, $product) ?>
        <?php if ($product): ?><input type="hidden" name="stock_loaded" value="<?= (int)$product['stock'] ?>"><?php endif; ?>
        <div id="attr-section" style="border-top:2px solid #e2e8f0;margin-top:1rem;padding-top:1rem">
            <h3 style="margin-bottom:.75rem"><?= esc(I18n::t('admin.title.attributes')) ?></h3>
            <p class="muted" style="color:#64748b;font-size:.85rem"><?= esc(I18n::t('admin.product.attrs_hint')) ?></p>
            <div id="attr-fields">
                <?php foreach ($catAttrs as $a):
                    $specKey = $a['name'];
                    $curVal = $existingSpecs[$specKey]['criteria_value'] ?? '';
                    $curNum = $existingSpecs[$specKey]['numeric_val'] ?? '';
                ?>
                <div class="form-row">
                    <label><?= esc($a['name']) ?><?= $a['unit'] ? ' (' . esc($a['unit']) . ')' : '' ?></label>
                    <?php if ($a['type'] === 'enum'): ?>
                    <select name="attr[<?= esc($a['name']) ?>]">
                        <option value="">— <?= esc(I18n::t('admin.common.not_selected')) ?> —</option>
                        <?php foreach ($attrOptions[$a['id']] ?? [] as $opt): ?>
                        <option value="<?= esc($opt) ?>" <?= $curVal === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php elseif ($a['type'] === 'number'): ?>
                    <input type="number" step="any" name="attr[<?= esc($a['name']) ?>]" value="<?= esc($curNum ?: $curVal) ?>" placeholder="<?= esc($a['unit'] ?: I18n::t('admin.product.number')) ?>">
                    <?php elseif ($a['type'] === 'boolean'): ?>
                    <select name="attr[<?= esc($a['name']) ?>]">
                        <option value="">— <?= esc(I18n::t('admin.common.not_selected')) ?> —</option>
                        <option value="Áno" <?= $curVal === 'Áno' ? 'selected' : '' ?>><?= esc(I18n::t('admin.common.yes')) ?></option>
                        <option value="Nie" <?= $curVal === 'Nie' ? 'selected' : '' ?>><?= esc(I18n::t('admin.common.no')) ?></option>
                    </select>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php if (empty($catAttrs)): ?>
                <p class="muted" style="color:#64748b;font-size:.85rem"><?= esc(I18n::t('admin.product.no_attrs')) ?> <a href="admin.php?p=attributes"><?= esc(I18n::t('admin.product.manage_attrs')) ?></a></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        $attrSection = (string)ob_get_clean();
        echo AdminForm::render('admin-product', 'admin.php?p=product&action=save' . ($product ? '&id=' . (int)$product['id'] : ''),
            adminFormValues('admin-product', $product ?: null), $attrSection);
        echo adminRichTextScript();
        ?>

        <?php if ($product): ?>
        <div class="card" style="margin-top:1rem">
            <h3 style="margin-bottom:.75rem"><?= esc(I18n::t('admin.product.related')) ?></h3>
            <form method="post" action="admin.php?p=product&id=<?= (int)$product['id'] ?>&action=related_save">
                <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                <div class="form-row">
                    <label for="related-relation-type"><?= esc(I18n::t('admin.product.relation_type')) ?></label>
                    <input id="related-relation-type" name="relation_type" value="<?= esc($relationType) ?>" pattern="[a-z][a-z0-9_-]{0,79}" required>
                </div>
                <div class="form-row">
                    <label for="related-related-product-ids"><?= esc(I18n::t('admin.product.related_manual')) ?></label>
                    <select id="related-related-product-ids" name="related_product_ids[]" multiple size="8" style="width:100%">
                        <?php foreach ($relatedCandidates as $candidate): ?>
                        <option value="<?= (int)$candidate['id'] ?>" <?= in_array((int)$candidate['id'], $relatedProductIds, true) ? 'selected' : '' ?>>
                            <?= esc($candidate['name']) ?> · <?= esc($candidate['sku']) ?> · <?= esc($candidate['product_type']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="muted" style="margin-top:.35rem"><?= esc(I18n::t('admin.product.related_hint')) ?></p>
                </div>
                <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.product.related_save')) ?></button>
            </form>
        </div>
        <?= productVariantsHtml($product) ?>
        <div style="margin-top:1rem"><a href="admin.php?p=products" class="btn"><?= esc(I18n::t('admin.common.back_to_list')) ?></a></div>
        <?php endif; ?>
    </div>
    <script nonce="<?= Security::cspNonce() ?>">
    var adminText = <?= json_encode(['notSelected' => I18n::t('admin.common.not_selected'), 'number' => I18n::t('admin.product.number'), 'yes' => I18n::t('admin.common.yes'), 'no' => I18n::t('admin.common.no'), 'noAttrs' => I18n::t('admin.product.no_attrs'), 'manageAttrs' => I18n::t('admin.product.manage_attrs')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var catAttrMap = <?= json_encode($catAttrMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var existingSpecs = <?= json_encode($existingSpecs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    function updateAttrFields() {
        var catId = document.getElementById('field-category_id').value;
        var attrs = catAttrMap[catId] || [];
        var container = document.getElementById('attr-fields');
        if (!attrs.length) {
            container.innerHTML = '<p class="muted" style="color:#64748b;font-size:.85rem">' + escHtml(adminText.noAttrs) + ' <a href="admin.php?p=attributes">' + escHtml(adminText.manageAttrs) + '</a></p>';
            return;
        }
        var html = '';
        attrs.forEach(function(a) {
            var spec = existingSpecs[a.name] || {};
            var curVal = spec.criteria_value || '';
            var curNum = spec.numeric_val || '';
            html += '<div class="form-row"><label>' + escHtml(a.name) + (a.unit ? ' (' + escHtml(a.unit) + ')' : '') + '</label>';
            if (a.type === 'enum') {
                html += '<select name="attr[' + escHtml(a.name) + ']"><option value="">— ' + escHtml(adminText.notSelected) + ' —</option>';
                (a.options || []).forEach(function(o) {
                    html += '<option value="' + escHtml(o) + '"' + (curVal === o ? ' selected' : '') + '>' + escHtml(o) + '</option>';
                });
                html += '</select>';
            } else if (a.type === 'number') {
                html += '<input type="number" step="any" name="attr[' + escHtml(a.name) + ']" value="' + escHtml(curNum || curVal) + '" placeholder="' + escHtml(a.unit || adminText.number) + '">';
            } else if (a.type === 'boolean') {
                html += '<select name="attr[' + escHtml(a.name) + ']"><option value="">— ' + escHtml(adminText.notSelected) + ' —</option>';
                html += '<option value="Áno"' + (curVal === 'Áno' ? ' selected' : '') + '>' + escHtml(adminText.yes) + '</option>';
                html += '<option value="Nie"' + (curVal === 'Nie' ? ' selected' : '') + '>' + escHtml(adminText.no) + '</option></select>';
            }
            html += '</div>';
        });
        container.innerHTML = html;
    }
    <?= ADMIN_JS_ESC_HTML ?>

    document.getElementById('field-category_id').addEventListener('change', updateAttrFields);
    </script>
    <?php
}
function pageDigitalAssets(): void {
    $cfg = require dirname(__DIR__, 3) . '/config.php';
    $baseDir = (string)($cfg['digital_assets_base_dir'] ?? '');
    $assets = CatalogScope::digitalAssets();
    ?>
    <h1><?= esc(I18n::t('admin.title.digital_assets')) ?></h1>
    <div class="card">
        <p><?= esc(I18n::t('admin.digital.location_hint')) ?></p>
        <p><code><?= esc($baseDir ? rtrim($baseDir, '/\\') . '/' . StoreContext::current()->catalogId() . '/' : I18n::t('admin.digital.not_configured')) ?></code></p>
        <p class="muted"><?= strtr(esc(I18n::t('admin.digital.path_hint')), ['{example}' => '<code>ebooks/manual.pdf</code>']) ?></p>
        <p class="muted"><?= esc(I18n::t('admin.digital.upload_hint')) ?></p>
    </div>
    <div class="card">
        <h3><?= esc(I18n::t('admin.digital.add_edit')) ?></h3>
        <form method="post" action="admin.php?p=digital_assets&action=save" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <input type="hidden" name="asset_id" value="0">
            <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(190px,1fr))">
                <div class="form-row"><label for="digital-sku"><?= esc(I18n::t('admin.th.sku')) ?></label><input id="digital-sku" name="sku" required maxlength="191"></div>
                <div class="form-row"><label for="digital-asset-key"><?= esc(I18n::t('admin.digital.asset_key')) ?></label><input id="digital-asset-key" name="asset_key" value="default" required maxlength="80"></div>
                <div class="form-row"><label for="digital-label"><?= esc(I18n::t('admin.th.name')) ?></label><input id="digital-label" name="label" maxlength="191"></div>
                <div class="form-row"><label for="digital-file"><?= esc(I18n::t('admin.digital.upload')) ?></label><input id="digital-file" name="file" type="file"></div>
                <div class="form-row"><label for="digital-storage-path"><?= esc(I18n::t('admin.digital.relative_path')) ?></label><input id="digital-storage-path" name="storage_path" placeholder="ebooks/manual.pdf" maxlength="500"></div>
                <div class="form-row"><label for="digital-download-name"><?= esc(I18n::t('admin.digital.download_name')) ?></label><input id="digital-download-name" name="download_name" placeholder="manual.pdf" maxlength="180"></div>
                <div class="form-row"><label for="digital-content-type"><?= esc(I18n::t('admin.digital.content_type')) ?></label><input id="digital-content-type" name="content_type" value="application/octet-stream" required maxlength="100"></div>
                <div class="form-row"><label for="digital-download-limit"><?= esc(I18n::t('admin.digital.download_limit')) ?></label><input id="digital-download-limit" name="download_limit" type="number" min="0" max="10000" value="5"></div>
                <div class="form-row"><label for="digital-expiry-days"><?= esc(I18n::t('admin.digital.expiry_days')) ?></label><input id="digital-expiry-days" name="expiry_days" type="number" min="0" max="3650" value="7"></div>
            </div>
            <label><input type="checkbox" name="active" value="1" checked> <?= esc(I18n::t('admin.common.active')) ?></label>
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.btn.save')) ?></button>
        </form>
    </div>
    <div class="card">
        <h3><?= esc(I18n::t('admin.digital.mappings')) ?></h3>
        <?php if (!$assets): ?><p class="muted"><?= esc(I18n::t('admin.digital.empty')) ?></p><?php else: ?>
        <table>
            <tr><th><?= esc(I18n::t('admin.th.sku')) ?></th><th><?= esc(I18n::t('admin.digital.key')) ?></th><th><?= esc(I18n::t('admin.common.path')) ?></th><th><?= esc(I18n::t('admin.digital.file')) ?></th><th><?= esc(I18n::t('admin.digital.limit_days')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.digital.entitlements')) ?></th><th></th></tr>
            <?php foreach ($assets as $asset): ?>
            <tr>
                <td><?= esc($asset['sku']) ?></td><td><?= esc($asset['asset_key']) ?></td>
                <td class="muted"><?= esc($asset['storage_path']) ?></td><td><?= esc($asset['download_name']) ?></td>
                <td><?= $asset['download_limit'] === null ? '∞' : (int)$asset['download_limit'] ?> / <?= $asset['expiry_seconds'] === null ? '∞' : round((int)$asset['expiry_seconds'] / 86400, 2) ?></td>
                <td><span class="badge badge-<?= (int)$asset['active'] ? 'active' : 'draft' ?>"><?= esc(I18n::t((int)$asset['active'] ? 'admin.common.enabled' : 'admin.common.disabled')) ?></span></td>
                <td><?= (int)$asset['entitlement_count'] ?></td>
                <td>
                    <form method="post" action="admin.php?p=digital_assets&action=toggle" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="asset_id" value="<?= (int)$asset['id'] ?>">
                        <input type="hidden" name="active" value="<?= (int)$asset['active'] ? 0 : 1 ?>">
                        <button class="btn btn-sm" type="submit"><?= esc(I18n::t((int)$asset['active'] ? 'admin.common.deactivate' : 'admin.common.activate')) ?></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
    <?php
}
function pageReviews(): void {
    $db = Db::get();
    $pending = Reviews::pending(200);
    // Map product ids → names for display
    $names = [];
    if ($pending) {
        $ids = implode(',', array_map(fn($r) => (int)$r['product_id'], $pending));
        foreach ($db->query("SELECT id, name FROM products WHERE id IN ({$ids})") as $p) {
            $names[(int)$p['id']] = $p['name'];
        }
    }
    ?>
    <h1><?= esc(I18n::t('admin.reviews.moderation')) ?> <span class="muted">(<?= esc(strtr(I18n::t('admin.reviews.pending_count'), ['{count}' => count($pending)])) ?>)</span></h1>
    <div class="card">
        <?php if (!$pending): ?>
        <p class="muted"><?= esc(I18n::t('admin.reviews.none')) ?></p>
        <?php else: ?>
        <table>
            <tr><th>ID</th><th><?= esc(I18n::t('admin.product.label')) ?></th><th><?= esc(I18n::t('admin.reviews.author')) ?></th><th><?= esc(I18n::t('admin.reviews.rating')) ?></th><th><?= esc(I18n::t('admin.reviews.text')) ?></th><th><?= esc(I18n::t('admin.common.when')) ?></th><th><?= esc(I18n::t('admin.th.actions')) ?></th></tr>
            <?php foreach ($pending as $rv): ?>
            <tr>
                <td><?= (int)$rv['id'] ?></td>
                <td><?= esc($names[(int)$rv['product_id']] ?? ('#' . $rv['product_id'])) ?></td>
                <td><?= esc($rv['author_name']) ?></td>
                <td><?= str_repeat('★', (int)$rv['rating']) ?></td>
                <td style="max-width:320px">
                    <?php if (!empty($rv['title'])): ?><strong><?= esc($rv['title']) ?></strong><br><?php endif; ?>
                    <?= esc($rv['body']) ?>
                </td>
                <td class="muted"><?= esc(substr((string)$rv['created_at'], 0, 16)) ?></td>
                <td style="white-space:nowrap">
                    <form method="post" action="admin.php?p=reviews&action=approve" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="review_id" value="<?= (int)$rv['id'] ?>">
                        <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.reviews.approve')) ?></button>
                    </form>
                    <form method="post" action="admin.php?p=reviews&action=reject" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="review_id" value="<?= (int)$rv['id'] ?>">
                        <button class="btn" type="submit"><?= esc(I18n::t('admin.reviews.reject')) ?></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
    <?php
}
function pageContentMarketing(): void {
    $menus = ContentService::all('menu');
    $banners = ContentService::all('banner');
    $redirects = ContentService::all('redirect');
    $stmt = Db::get()->prepare("SELECT * FROM pages WHERE store_id=:store AND page_type='landing' ORDER BY updated_at DESC");
    $stmt->execute([':store' => StoreContext::current()->id()]);
    $landings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $csrf = Security::csrfToken();
    ?>
    <h1><?= esc(I18n::t('admin.title.content')) ?> <span class="muted"><?= esc(StoreContext::current()->id()) ?></span></h1>
    <p class="muted" style="margin-bottom:1rem"><?= esc(I18n::t('admin.content.theme')) ?>: <code><?= esc((string)(StoreContext::current()->config()['theme'] ?? 'default')) ?></code> ·
        <a href="/feeds/google.xml" target="_blank" rel="noopener"><?= esc(I18n::t('admin.content.feed_google')) ?></a> ·
        <a href="/feeds/meta.csv" target="_blank" rel="noopener"><?= esc(I18n::t('admin.content.feed_meta')) ?></a></p>
    <div class="grid grid-4">
        <section class="card">
            <h2><?= esc(I18n::t('admin.content.menu_item')) ?></h2>
            <form method="post" action="admin.php?p=content&amp;action=save">
                <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>"><input type="hidden" name="content_type" value="menu">
                <div class="form-row"><label><?= esc(I18n::t('admin.content.menu_code')) ?><input name="menu_code" value="primary" required pattern="[a-z0-9_-]+"></label></div>
                <div class="form-row"><label><?= esc(I18n::t('admin.content.locale')) ?><input name="locale" value="*" required></label></div>
                <div class="form-row"><label><?= esc(I18n::t('admin.content.label')) ?><input name="label" maxlength="191" required></label></div>
                <div class="form-row"><label><?= esc(I18n::t('admin.content.local_url')) ?><input name="url" value="/" required></label></div>
                <div class="form-row"><label><?= esc(I18n::t('admin.common.sort_order')) ?><input name="sort_order" type="number" value="0"></label></div>
                <label><input name="active" type="checkbox" value="1" checked> <?= esc(I18n::t('admin.common.active')) ?></label><br><button class="btn btn-primary"><?= esc(I18n::t('admin.content.save_menu')) ?></button>
            </form>
        </section>
        <section class="card">
            <h2><?= esc(I18n::t('admin.content.banner')) ?></h2>
            <form method="post" action="admin.php?p=content&amp;action=save">
                <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>"><input type="hidden" name="content_type" value="banner">
                <div class="form-row"><label><?= esc(I18n::t('admin.content.slot')) ?><input name="slot" value="homepage" required pattern="[a-z0-9_-]+"></label></div>
                <div class="form-row"><label><?= esc(I18n::t('admin.content.locale')) ?><input name="locale" value="*" required></label></div>
                <div class="form-row"><label><?= esc(I18n::t('admin.content.title')) ?><input name="title" maxlength="191" required></label></div>
                <div class="form-row"><label><?= esc(I18n::t('admin.content.body')) ?><textarea name="body" rows="2"></textarea></label></div>
                <div class="form-row"><label><?= esc(I18n::t('admin.content.image_url')) ?><input name="image_url" placeholder="/assets/img/banner.jpg"></label></div>
                <div class="form-row"><label><?= esc(I18n::t('admin.content.link_url')) ?><input name="link_url" placeholder="/category/sale"></label></div>
                <div class="form-row"><label><?= esc(I18n::t('admin.content.link_label')) ?><input name="link_label" maxlength="191"></label></div>
                <div class="form-row"><label><?= esc(I18n::t('admin.content.starts')) ?><input name="starts_at" type="datetime-local"></label></div>
                <div class="form-row"><label><?= esc(I18n::t('admin.content.ends')) ?><input name="ends_at" type="datetime-local"></label></div>
                <label><input name="active" type="checkbox" value="1" checked> <?= esc(I18n::t('admin.common.active')) ?></label><br><button class="btn btn-primary"><?= esc(I18n::t('admin.content.save_banner')) ?></button>
            </form>
        </section>
        <section class="card">
            <h2><?= esc(I18n::t('admin.content.landing')) ?></h2>
            <form method="post" action="admin.php?p=content&amp;action=save">
                <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>"><input type="hidden" name="content_type" value="landing">
                <div class="form-row"><label><?= esc(I18n::t('admin.common.slug')) ?><input name="slug" required pattern="[a-z0-9-]+"></label></div>
                <div class="form-row"><label><?= esc(I18n::t('admin.content.title')) ?><input name="title" maxlength="191" required></label></div>
                <div class="form-row"><label><?= esc(I18n::t('admin.content.body')) ?><textarea name="body" rows="4"></textarea></label></div>
                <div class="form-row"><label><?= esc(I18n::t('admin.product.field.seo_title')) ?><input name="seo_title" maxlength="191"></label></div>
                <div class="form-row"><label><?= esc(I18n::t('admin.product.field.meta_description')) ?><textarea name="meta_description" maxlength="320" rows="2"></textarea></label></div>
                <div class="form-row"><label><?= esc(I18n::t('admin.th.status')) ?><select name="status"><option value="draft"><?= esc(I18n::t('admin.cms.status.draft')) ?></option><option value="active"><?= esc(I18n::t('admin.cms.status.active')) ?></option></select></label></div>
                <button class="btn btn-primary"><?= esc(I18n::t('admin.content.save_landing')) ?></button>
            </form>
        </section>
        <section class="card">
            <h2><?= esc(I18n::t('admin.content.redirect')) ?></h2>
            <form method="post" action="admin.php?p=content&amp;action=save">
                <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>"><input type="hidden" name="content_type" value="redirect">
                <div class="form-row"><label><?= esc(I18n::t('admin.content.source_path')) ?><input name="source_path" placeholder="/old-path" required></label></div>
                <div class="form-row"><label><?= esc(I18n::t('admin.content.target_path')) ?><input name="target_url" placeholder="/new-path" required></label></div>
                <div class="form-row"><label><?= esc(I18n::t('admin.content.http_status')) ?><select name="http_status"><option>301</option><option>302</option><option>307</option><option>308</option></select></label></div>
                <label><input name="active" type="checkbox" value="1" checked> <?= esc(I18n::t('admin.common.active')) ?></label><br><button class="btn btn-primary"><?= esc(I18n::t('admin.content.save_redirect')) ?></button>
            </form>
        </section>
    </div>
    <?php foreach ([
        'menu' => ['admin.content.menu_items', $menus, ['menu_code' => 'admin.content.menu_code', 'locale' => 'admin.content.locale', 'label' => 'admin.content.label', 'url' => 'admin.content.local_url']],
        'banner' => ['admin.content.banners', $banners, ['slot' => 'admin.content.slot', 'locale' => 'admin.content.locale', 'title' => 'admin.content.title', 'link_url' => 'admin.content.link_url']],
        'landing' => ['admin.content.landings', $landings, ['slug' => 'admin.common.slug', 'title' => 'admin.content.title', 'status' => 'admin.th.status', 'seo_title' => 'admin.product.field.seo_title']],
        'redirect' => ['admin.content.redirects', $redirects, ['source_path' => 'admin.content.source_path', 'target_url' => 'admin.content.target_path', 'http_status' => 'admin.content.http_status', 'active' => 'admin.common.active']],
    ] as $type => [$heading, $rows, $columns]): ?>
    <section class="card"><h2><?= esc(I18n::t($heading)) ?></h2><div class="table-scroll"><table><tr>
        <?php foreach ($columns as $columnLabel): ?><th><?= esc(I18n::t($columnLabel)) ?></th><?php endforeach; ?><th><?= esc(I18n::t('admin.th.actions')) ?></th></tr>
        <?php foreach ($rows as $row): ?><tr><?php foreach (array_keys($columns) as $column): ?><td><?= esc((string)($row[$column] ?? '')) ?></td><?php endforeach; ?><td>
            <form method="post" action="admin.php?p=content&amp;action=delete" data-confirm="<?= esc(I18n::t('admin.content.delete_confirm')) ?>">
                <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>"><input type="hidden" name="content_type" value="<?= esc($type) ?>"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button class="btn btn-danger btn-sm"><?= esc(I18n::t('admin.btn.delete')) ?></button>
            </form></td></tr><?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?= count($columns) + 1 ?>" class="muted"><?= esc(I18n::t('admin.common.no_records')) ?></td></tr><?php endif; ?>
    </table></div></section>
    <?php endforeach;
}
function handleContentSave(): void {
    Security::adminRequire('products.edit');
    try {
        $type = (string)($_POST['content_type'] ?? '');
        match($type) {
            'menu' => ContentService::saveMenu($_POST),
            'banner' => ContentService::saveBanner($_POST),
            'landing' => ContentService::saveLanding($_POST),
            'redirect' => ContentService::saveRedirect($_POST),
            default => throw new InvalidArgumentException('Unknown content type'),
        };
        setFlash('ok', I18n::t('admin.content.saved'));
    } catch (Throwable $e) {
        setFlash('err', $e->getMessage());
    }
    header('Location: admin.php?p=content');
    exit;
}
function handleContentDelete(): void {
    Security::adminRequire('products.edit');
    try {
        ContentService::delete((string)($_POST['content_type'] ?? ''), Security::int($_POST['id'] ?? 0));
        setFlash('ok', I18n::t('admin.content.deleted'));
    } catch (Throwable $e) {
        setFlash('err', $e->getMessage());
    }
    header('Location: admin.php?p=content');
    exit;
}

// ============================================================
// CMS Pages
// ============================================================
function pageCmsPages(): void {
    $db = Db::get();
    $stmt = $db->prepare("SELECT * FROM pages WHERE store_id = :store ORDER BY created_at DESC");
    $stmt->execute([':store' => StoreContext::current()->id()]);
    $pages = $stmt->fetchAll();
    ?>
    <h1><?= esc(I18n::t('admin.title.cms_pages')) ?></h1>
    <p style="margin-bottom:1rem"><a href="admin.php?p=page_edit" class="btn btn-primary"><?= esc(I18n::t('admin.cms.new')) ?></a></p>
    <div class="card">
        <table>
            <tr><th><?= esc(I18n::t('admin.common.slug')) ?></th><th><?= esc(I18n::t('admin.content.title')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.common.created')) ?></th><th></th></tr>
            <?php foreach ($pages as $pg): ?>
            <tr>
                <td><code><?= esc($pg['slug']) ?></code></td>
                <td><?= esc($pg['title']) ?></td>
                <td><span class="badge badge-<?= $pg['status'] === 'active' ? 'active' : 'draft' ?>"><?= esc(adminCodeLabel('admin.cms.status.', (string)$pg['status'])) ?></span></td>
                <td class="muted"><?= esc(substr($pg['created_at'], 0, 16)) ?></td>
                <td><a href="admin.php?p=page_edit&id=<?= (int)$pg['id'] ?>" class="btn btn-sm"><?= esc(I18n::t('admin.btn.edit')) ?></a></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php
}
function pageCmsEdit(): void {
    $id = Security::int($_GET['id'] ?? 0);
    $pg = $id ? ContentService::pageById($id) : null;
    if ($id && !$pg) { echo '<p>' . esc(I18n::t('admin.cms.not_found')) . '</p>'; return; }
    ?>
    <h1><?= esc(I18n::t($id ? 'admin.title.cms_page_edit' : 'admin.cms.new')) ?></h1>
    <div class="card">
        <?php
        echo AdminForm::render('admin-page', 'admin.php?p=page_edit&action=save' . ($id ? '&id=' . $id : ''), adminFormValues('admin-page', $pg ?: null));
        echo adminRichTextScript();
        ?>
        <?php if ($id): ?>
        <div style="margin-top:1rem"><a href="admin.php?p=pages" class="btn"><?= esc(I18n::t('admin.btn.back')) ?></a></div>
        <?php endif; ?>
    </div>
    <?php
}
function handleCmsPageSave(): void {
    $db = Db::get();
    $id = Security::int($_GET['id'] ?? 0);
    $now = Db::isSqlite() ? "datetime('now')" : 'NOW()';
    $old = null;
    if ($id) {
        $old = ContentService::pageById($id);
        if (!$old) {
            setFlash('err', I18n::t('admin.cms.not_found'));
            header('Location: admin.php?p=pages');
            exit;
        }
    }
    $form = AdminForm::validate('admin-page', $old ? adminFormValues('admin-page', $old) : []);
    $back = 'Location: admin.php?p=page_edit' . ($id ? "&id={$id}" : '');
    if ($form === null) {
        setFlash('err', I18n::t('admin.cms.not_saved') . ' ' . AdminForm::firstError());
        header($back);
        exit;
    }
    $slug = Security::slug($form['slug']);
    $title = $form['title'];
    $body = Security::sanitizeRichHtml($form['body']);
    // Theme codes keep underscores (e.g. a "full_width" layout), so they are validated, not slugified.
    $code = static function (string $field, string $default) use ($form): string {
        $value = strtolower($form[$field]);
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $value) ? $value : $default;
    };
    $pageType = $code('page_type', 'content');
    $template = $code('template', 'cms');
    $layout = $code('layout', 'storefront');
    try {
        $sections = ContentService::sanitizeSections($form['sections'] !== '' ? $form['sections'] : '[]');
    } catch (JsonException | InvalidArgumentException $e) {
        AdminForm::keepInput('admin-page', $form, ['sections' => I18n::t('admin.cms.sections_invalid')]);
        setFlash('err', I18n::t('admin.cms.sections_invalid'));
        header($back);
        exit;
    }
    $status = $form['status'] !== '' ? $form['status'] : 'active';

    $slugOwner = $slug ? ContentService::pageIdBySlug($slug) : null;
    if (!$slug || ($slugOwner !== null && $slugOwner !== $id)) {
        $message = I18n::t(!$slug ? 'admin.cms.slug_required' : 'admin.cms.slug_taken');
        AdminForm::keepInput('admin-page', $form, ['slug' => $message]);
        setFlash('err', $message);
        header($back);
        exit;
    }

    if ($id) {
        $db->prepare("UPDATE pages SET slug = :slug, title = :title, body = :body, page_type = :page_type, template = :template, layout = :layout, sections = :sections, status = :status, updated_at = {$now} WHERE id = :id AND store_id = :store")
           ->execute([':slug' => $slug, ':title' => $title, ':body' => $body, ':page_type' => $pageType, ':template' => $template, ':layout' => $layout, ':sections' => $sections, ':status' => $status, ':id' => $id, ':store' => StoreContext::current()->id()]);
        setFlash('ok', I18n::t('admin.cms.updated'));
    } else {
        $db->prepare("INSERT INTO pages (store_id, slug, title, body, page_type, template, layout, sections, status) VALUES (:store, :slug, :title, :body, :page_type, :template, :layout, :sections, :status)")
           ->execute([':store' => StoreContext::current()->id(), ':slug' => $slug, ':title' => $title, ':body' => $body, ':page_type' => $pageType, ':template' => $template, ':layout' => $layout, ':sections' => $sections, ':status' => $status]);
        $id = (int)$db->lastInsertId();
        setFlash('ok', I18n::t('admin.cms.created'));
    }
    AuditLog::log('page', $id, $old, ContentService::pageById($id), $old ? 'Admin page edit' : 'Admin page create');
    header("Location: admin.php?p=page_edit&id={$id}");
    exit;
}
function handleCmsPageDelete(): void {
    $id = Security::int($_POST['page_id'] ?? 0);
    if ($id) {
        $old = ContentService::pageById($id);
        $stmt = Db::get()->prepare("DELETE FROM pages WHERE id = :id AND store_id = :store");
        $stmt->execute([':id' => $id, ':store' => StoreContext::current()->id()]);
        if ($stmt->rowCount()) {
            AuditLog::log('page', $id, $old, null, 'Admin page delete');
            setFlash('ok', I18n::t('admin.cms.deleted'));
        } else {
            setFlash('err', I18n::t('admin.cms.not_found'));
        }
    }
    header('Location: admin.php?p=pages');
    exit;
}

// ============================================================
// POST handlers
// ============================================================
function handleDigitalAssetSave(): void {
    Security::adminRequire('products.edit');
    $sku = trim((string)($_POST['sku'] ?? ''));
    $assetKey = trim((string)($_POST['asset_key'] ?? 'default'));
    $label = trim((string)($_POST['label'] ?? ''));
    // Relative to the active catalog's own directory <digital_assets_base_dir>/<catalog_id>/ (stored with that prefix);
    // the file must exist there. An older asset re-saved with its unchanged base-relative path keeps it.
    $current = null;
    if (preg_match('/^[A-Za-z0-9._-]{1,191}$/', $sku) && preg_match('/^[A-Za-z0-9._-]{1,80}$/', $assetKey) && CatalogScope::ownsSku($sku)) {
        $currentStmt = Db::get()->prepare('SELECT storage_path FROM digital_assets WHERE sku = :sku AND asset_key = :asset_key');
        $currentStmt->execute([':sku' => $sku, ':asset_key' => $assetKey]);
        $current = $currentStmt->fetchColumn();
    }
    // An uploaded file is moved into the store directory once the rest of the form is valid; without one the file
    // must already be there (copied by FTP/SSH).
    $upload = DigitalDelivery::uploadedFile($_FILES['file'] ?? null);
    if ($upload === false) { setFlash('err', I18n::t('admin.digital.upload_failed')); header('Location: admin.php?p=digital_assets'); exit; }
    [$path, $pathError] = $upload ? ['', null] : DigitalDelivery::assetPathForSave(Security::str($_POST['storage_path'] ?? ''), is_string($current) ? $current : null);
    $downloadName = basename(str_replace('\\', '/', trim((string)($_POST['download_name'] ?? '')))) ?: (string)($upload['name'] ?? '');
    $contentType = trim((string)($_POST['content_type'] ?? '')) ?: 'application/octet-stream';
    if ($upload && $contentType === 'application/octet-stream') $contentType = $upload['type'];
    $submittedLimit = max(0, min(10000, Security::int($_POST['download_limit'] ?? 5)));
    $downloadLimit = $submittedLimit > 0 ? $submittedLimit : null;
    $expiryDays = max(0, min(3650, Security::int($_POST['expiry_days'] ?? 7)));
    $active = !empty($_POST['active']) ? 1 : 0;

    $invalidPath = $path === null || strlen($path) > 500;
    if ($sku === '' || strlen($sku) > 191 || !preg_match('/^[A-Za-z0-9._-]{1,191}$/', $sku)
        || !preg_match('/^[A-Za-z0-9._-]{1,80}$/', $assetKey) || $invalidPath
        || $downloadName === '' || !preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $contentType)
        || !CatalogScope::ownsSku($sku)) {
        $message = $pathError !== null && $pathError !== 'admin.digital.invalid' && CatalogScope::ownsSku($sku)
            ? I18n::format($pathError, ['dir' => StoreContext::current()->catalogId() . '/'])
            : I18n::t('admin.digital.invalid');
        setFlash('err', $message);
        header('Location: admin.php?p=digital_assets');
        exit;
    }
    if ($upload) [$path, $pathError] = DigitalDelivery::storeUpload($upload['tmp'], $upload['name'], Security::str($_POST['storage_path'] ?? ''), is_string($current) ? $current : null);
    if ($path === null) { setFlash('err', I18n::format((string)$pathError, ['dir' => StoreContext::current()->catalogId() . '/'])); header('Location: admin.php?p=digital_assets'); exit; }

    try {
        $sql = "INSERT INTO digital_assets
            (sku, asset_key, label, storage_path, download_name, content_type, download_limit, expiry_seconds, active)
            VALUES (:sku, :asset_key, :label, :storage_path, :download_name, :content_type, :download_limit, :expiry_seconds, :active) "
            . Db::upsertClause(
                'sku, asset_key',
                'label=excluded.label, storage_path=excluded.storage_path, download_name=excluded.download_name, content_type=excluded.content_type, download_limit=excluded.download_limit, expiry_seconds=excluded.expiry_seconds, active=excluded.active, updated_at=' . Db::now(),
                'label=VALUES(label), storage_path=VALUES(storage_path), download_name=VALUES(download_name), content_type=VALUES(content_type), download_limit=VALUES(download_limit), expiry_seconds=VALUES(expiry_seconds), active=VALUES(active), updated_at=' . Db::now()
            );
        Db::get()->prepare($sql)->execute([
            ':sku' => $sku,
            ':asset_key' => $assetKey,
            ':label' => $label,
            ':storage_path' => $path,
            ':download_name' => $downloadName,
            ':content_type' => $contentType,
            ':download_limit' => $downloadLimit,
            ':expiry_seconds' => $expiryDays > 0 ? $expiryDays * 86400 : null,
            ':active' => $active,
        ]);
        AuditLog::log('digital_asset', 0, null, ['sku' => $sku, 'asset_key' => $assetKey, 'storage_path' => $path, 'download_name' => $downloadName,
            'download_limit' => $downloadLimit, 'expiry_days' => $expiryDays, 'active' => $active], 'Admin digital asset save');
        setFlash('ok', I18n::t('admin.digital.saved'));
    } catch (Throwable $e) {
        Logger::phpLog('Digital asset save failed: ' . $e->getMessage());
        setFlash('err', I18n::t('admin.digital.save_failed'));
    }
    header('Location: admin.php?p=digital_assets');
    exit;
}
function handleDigitalAssetToggle(): void {
    Security::adminRequire('products.edit');
    $id = Security::int($_POST['asset_id'] ?? 0);
    $active = !empty($_POST['active']) ? 1 : 0;
    if ($id > 0 && CatalogScope::setDigitalAssetActive($id, $active)) {
        AuditLog::log('digital_asset', $id, ['active' => 1 - $active], ['active' => $active], 'Admin digital asset toggle');
        setFlash('ok', I18n::t($active ? 'admin.digital.activated' : 'admin.digital.deactivated'));
    } elseif ($id > 0) {
        setFlash('err', I18n::t('admin.common.record_missing'));
    }
    header('Location: admin.php?p=digital_assets');
    exit;
}
function handleReviewModerate(string $status): void {
    Security::adminRequire('products.edit');
    $id = Security::int($_POST['review_id'] ?? 0);
    if ($id > 0 && Reviews::setStatus($id, $status)) {
        AuditLog::log('review', $id, null, ['status' => $status], 'Admin review moderation');
        setFlash('ok', strtr(I18n::t('admin.reviews.moderated'), ['{id}' => $id, '{status}' => adminCodeLabel('admin.reviews.status.', $status)]));
    } else {
        setFlash('err', I18n::t('admin.reviews.failed'));
    }
    header('Location: admin.php?p=reviews');
    exit;
}
function handleProductRelatedSave(): void {
    $db = Db::get();
    $id = Security::int($_GET['id'] ?? 0);
    if ($id <= 0 || !Products::byId($id)) {
        header('Location: admin.php?p=products');
        exit;
    }

    $relationType = strtolower(trim((string)($_POST['relation_type'] ?? 'related')));
    if (!preg_match('/^[a-z][a-z0-9_-]{0,79}$/', $relationType)) throw new InvalidArgumentException('Invalid product relation type');
    $submitted = $_POST['related_product_ids'] ?? [];
    if (!is_array($submitted)) $submitted = [];
    $selected = array_values(array_unique(array_filter(
        array_map('intval', $submitted),
        static fn(int $candidateId): bool => $candidateId > 0 && $candidateId !== $id
    )));
    $selected = array_values(array_intersect($selected, CatalogScope::activeProductIds()));

    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM product_related_products WHERE product_id = :id AND relation_type = :type')->execute([':id' => $id, ':type' => $relationType]);
        $insert = $db->prepare('INSERT INTO product_related_products (product_id, related_product_id, relation_type, sort_order) VALUES (:id, :related, :type, :sort)');
        foreach ($selected as $sort => $relatedId) {
            $insert->execute([':id' => $id, ':related' => $relatedId, ':type' => $relationType, ':sort' => $sort]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    setFlash('ok', I18n::t('admin.product.related_saved'));
    header("Location: admin.php?p=product&id={$id}&relation_type=" . rawurlencode($relationType));
    exit;
}
function handleProductDuplicate(): void {
    $id = Security::int($_GET['id'] ?? 0);
    if ($id <= 0 || !Products::byId($id)) {
        header('Location: admin.php?p=products');
        exit;
    }
    $copyId = ProductDuplicator::duplicate($id);
    setFlash('ok', I18n::t('admin.product.duplicated'));
    header("Location: admin.php?p=product&id={$copyId}");
    exit;
}
function handleProductSave(): void {
    $db = Db::get();
    $id = Security::int($_GET['id'] ?? 0);
    $now = Db::isSqlite() ? "datetime('now')" : 'NOW()';
    $old = $id ? Products::byId($id) : null;
    if ($id && !$old) {
        setFlash('err', I18n::t('admin.product.not_found'));
        header('Location: admin.php?p=products');
        exit;
    }

    // BBF checks required fields, numbers, the select lists (a stored value outside today's lists survives an
    // unchanged save) and the uploaded image's size, extension and content.
    $form = AdminForm::validate('admin-product', $old ? adminFormValues('admin-product', $old) : []);
    // Brand, GTIN/MPN, SEO, extra categories and translations are checked against the active catalog first.
    $editor = $form === null ? AdminForm::firstError() : ProductEditor::validate($form, $_POST, $id, $old);
    // A new product goes back to the (prefilled) new-product form, not to an "id=0" record.
    $back = 'Location: admin.php?p=product' . ($id ? "&id={$id}" : '');
    if (is_string($editor)) {
        if ($form !== null) AdminForm::keepInput('admin-product', $form);
        setFlash('err', I18n::t('admin.product.not_saved') . ' ' . $editor);
        header($back);
        exit;
    }
    $nullableCount = static fn(string $v): ?int => $v === '' ? null : max(0, Security::int($v));

    $data = [
        ':sku'           => $form['sku'],
        ':name'          => $form['name'],
        ':product_type'  => $form['product_type'] !== '' ? $form['product_type'] : 'simple',
        ':category_id'   => Security::int($form['category_id']),
        ':price'         => Security::int($form['price']),
        ':compare_price' => Security::int($form['compare_price']) ?: null,
        ':stock'         => Security::int($form['stock']),
        ':status'        => $form['status'] !== '' ? $form['status'] : 'active',
        ':summary'       => $form['summary'],
        ':description'   => Security::sanitizeRichHtml($form['description']),
        ':weight_g'      => Security::int($form['weight_g']),
        // Empty per-product alert threshold = use the stock_alerts config default.
        ':stock_low_threshold'      => $nullableCount($form['stock_low_threshold']),
        ':stock_critical_threshold' => $nullableCount($form['stock_critical_threshold']),
    ] + $editor['columns'];
    // The form carries the stock it showed. Stock moved by orders or returns since then must survive the save: an
    // untouched field keeps today's stock, an edited one applies the admin's change on top of it (was: the stale
    // number overwrote it, e.g. a sale made while the form was open came back as available stock).
    if ($old && isset($_POST['stock_loaded']) && is_numeric($_POST['stock_loaded'])) {
        $data[':stock'] = max(0, (int)$old['stock'] + $data[':stock'] - (int)$_POST['stock_loaded']);
    }
    $productType = $data[':product_type'];

    // Image order, alts and removals come from the editor; an upload is stored last, after every check passed.
    $images = $editor['images'];
    $upload = $form['image_upload']['action'] === 'replace' ? ProductEditor::storeUpload($form['image_upload']['files'][0], $data[':sku']) : null;
    if (is_string($upload)) {
        AdminForm::keepInput('admin-product', $form);
        setFlash('err', $upload);
        header($back);
        exit;
    }
    if ($upload) $images[] = $upload[0];
    $data[':images'] = json_encode($images);
    $data[':tags'] = json_encode(array_values(array_filter(array_map('trim', explode(',', $form['tags'])), 'strlen')));

    try {
        if ($old) {
            // Saving the form unchanged must not rewrite the product (nor bump updated_at / the audit log).
            $jsonList = static fn($v): array => is_array($d = json_decode((string)$v, true)) ? array_values($d) : [];
            $nullableInt = static fn($v): ?int => $v === null || $v === '' ? null : (int)$v;
            $changed = [];
            foreach ($data as $param => $value) {
                $column = substr($param, 1);
                $before = $old[$column] ?? null;
                $same = match ($column) {
                    'images', 'tags' => $jsonList($before) === $jsonList($value),
                    'translations' => (json_decode((string)$before, true) ?: []) == (json_decode((string)$value, true) ?: []),
                    'price', 'stock', 'weight_g', 'category_id' => (int)$before === (int)$value,
                    'compare_price', 'brand_id' => ($nullableInt($before) ?: null) === $value,
                    'stock_low_threshold', 'stock_critical_threshold' => $nullableInt($before) === $value,
                    default => (string)$before === (string)$value,
                };
                if (!$same) $changed[] = $column;
            }
            if (ProductEditor::categoriesChanged($id, $editor)) $changed[] = 'categories';
            if ($changed) {
                // Stock moves by the admin's change only: a sale committed after $old was read stays sold.
                $stmt = $db->prepare("UPDATE products SET sku=:sku, name=:name, slug=:slug, product_type=:product_type, stock=CASE WHEN stock + :stock_delta < 0 THEN 0 ELSE stock + :stock_delta2 END,
                    category_id=:category_id, brand_id=:brand_id, gtin=:gtin, mpn=:mpn, seo_title=:seo_title, meta_description=:meta_description, translations=:translations, price=:price, compare_price=:compare_price,
                    status=:status, summary=:summary, description=:description, images=:images, image_alts=:image_alts, tags=:tags, weight_g=:weight_g,
                    stock_low_threshold=:stock_low_threshold, stock_critical_threshold=:stock_critical_threshold, updated_at={$now} WHERE id=:id AND store_id=:catalog");
                $db->beginTransaction(); // an exception ends the request; the uncommitted transaction is rolled back
                $stmt->execute(array_diff_key($data, [':stock' => 1]) + [':stock_delta' => $data[':stock'] - (int)$old['stock'], ':stock_delta2' => $data[':stock'] - (int)$old['stock'],
                    ':id' => $id, ':catalog' => StoreContext::current()->catalogId()]);
                ProductEditor::save($id, $editor, $old);
                AuditLog::log('product', $id, $old, $data, 'Admin edit: ' . implode(', ', $changed));
                Products::recordUpdated($id, $old);
                $db->commit();
                setFlash('ok', I18n::t('admin.product.updated'));
            } else {
                setFlash('ok', I18n::t('admin.product.unchanged'));
            }
        } else {
            $stmt = $db->prepare("INSERT INTO products (sku, name, slug, product_type, category_id, brand_id, gtin, mpn, seo_title, meta_description, translations, price, compare_price, stock, status, summary, description, images, image_alts, tags, weight_g, stock_low_threshold, stock_critical_threshold, store_id)
                VALUES (:sku, :name, :slug, :product_type, :category_id, :brand_id, :gtin, :mpn, :seo_title, :meta_description, :translations, :price, :compare_price, :stock, :status, :summary, :description, :images, :image_alts, :tags, :weight_g, :stock_low_threshold, :stock_critical_threshold, :catalog)");
            $db->beginTransaction();
            $stmt->execute($data + [':catalog' => StoreContext::current()->catalogId()]);
            $id = (int)$db->lastInsertId();
            ProductEditor::save($id, $editor);
            AuditLog::log('product', $id, null, $data, 'Admin create');
            $db->commit();
            setFlash('ok', I18n::t('admin.product.created'));
        }
    } catch (Throwable $e) {
        if ($upload) ProductEditor::discardUpload($upload[1]);
        throw $e;
    }
    ProductEditor::afterImagesSaved(json_decode((string)($old['images'] ?? '[]'), true) ?: [], $images, $upload[1] ?? null);

    // The stock field is "available"; book the change in the warehouse ledger (the source of truth).
    try {
        if ((int)($old['stock'] ?? -1) !== $data[':stock']) Products::setAvailableStock($data[':sku'], $productType, $data[':stock'], 'manual_adjustment', 'admin_product', $old ? (int)$old['stock'] : null);
    } catch (RuntimeException | InvalidArgumentException $e) {
        setFlash('err', I18n::t('admin.product.stock_not_changed') . ' ' . $e->getMessage());
    }

    // Save attribute values to product_specifications
    $attrValues = is_array($_POST['attr'] ?? null) ? array_filter($_POST['attr'], 'is_scalar') : [];
    if (!empty($attrValues) && $id) {
        // Get attribute definitions for mapping name → type/unit
        $catId = $data[':category_id'];
        $defStmt = $db->prepare("
            SELECT ad.name, ad.type, ad.unit
            FROM attribute_definitions ad
            JOIN category_attributes ca ON ca.attribute_id = ad.id
            WHERE ca.category_id = :cid
        ");
        $defStmt->execute([':cid' => $catId]);
        $attrDefs = [];
        foreach ($defStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $attrDefs[$d['name']] = $d;
        }

        // Delete old attribute-based specs for this product (only those matching defined attributes)
        foreach ($attrDefs as $aName => $aDef) {
            $db->prepare("DELETE FROM product_specifications WHERE product_id = :pid AND criteria_name = :name AND lang = 'sk'")
               ->execute([':pid' => $id, ':name' => $aName]);
        }

        // Insert new values
        $specIns = $db->prepare("INSERT INTO product_specifications (product_id, criteria_name, criteria_value, numeric_val, lang) VALUES (:pid, :name, :val, :num, 'sk')");
        foreach ($attrValues as $name => $val) {
            $val = trim((string)$val);
            if ($val === '' || !isset($attrDefs[$name])) continue;
            $def = $attrDefs[$name];
            $numericVal = null;
            if ($def['type'] === 'number') {
                $numericVal = (float)$val;
                $displayVal = $val . ($def['unit'] ? ' ' . $def['unit'] : '');
            } else {
                $displayVal = $val;
            }
            $specIns->execute([':pid' => $id, ':name' => $name, ':val' => $displayVal, ':num' => $numericVal]);
        }
    }
    Cache::catalogChanged();

    header("Location: admin.php?p=product&id={$id}");
    exit;
}
function handleBulkAction(): void {
    // CSRF already validated by handlePost()
    Security::adminRequire('products.edit');
    $ids = Security::intList($_POST['product_ids'] ?? []);
    $action = is_string($_POST['bulk_action'] ?? null) ? $_POST['bulk_action'] : '';
    // Back to the list the admin was looking at (same search, filters and page).
    $filters = array_filter(array_map(static fn($value): string => is_string($value) ? trim($value) : '', array_intersect_key($_POST, array_flip(['q', 'status', 'stock', 'page']))), static fn(string $value): bool => $value !== '');
    $back = 'Location: admin.php?' . http_build_query(['p' => 'products'] + $filters);
    if (empty($ids) || !$action) {
        setFlash('err', I18n::t(empty($ids) ? 'admin.products.bulk.none_selected' : 'admin.products.bulk.no_action'));
        header($back);
        exit;
    }

    $result = ProductBulk::apply($action, $ids);
    if (!$result['ok']) {
        setFlash('err', I18n::t('admin.products.bulk.failed'));
    } else {
        $percent = ProductBulk::PRICE_ACTIONS[$action] ?? 0;
        $key = ['activate' => 'activated', 'archive' => 'archived', 'delete' => 'deleted'][$action] ?? 'price_changed';
        $msg = I18n::format('admin.products.bulk.' . $key, ['count' => $result['count'], 'change' => sprintf('%+d%%', $percent)]);
        if ($result['skipped']) $msg .= ' ' . I18n::format('admin.products.bulk.delete_skipped', ['list' => implode(', ', $result['skipped'])]);
        if ($result['clamped']) $msg .= ' ' . I18n::format('admin.products.bulk.price_clamped', ['list' => implode(', ', $result['clamped'])]);
        setFlash($result['skipped'] ? 'err' : 'ok', $msg);
    }
    header($back);
    exit;
}
function handleExport(): void {
    Security::adminRequire('products.view');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="products-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    try {
        exportProductsCsv($out, is_string($_POST['q'] ?? null) ? $_POST['q'] : '', is_string($_POST['status'] ?? null) ? $_POST['status'] : '');
    } catch (CsvExportTruncated) {
        CsvExport::abortResponse(); // logged + incident already; an already started download stays incomplete
    }
    fclose($out);
    exit;
}
/** Products CSV in the Import::csv() format (prices in euros), so an edited export can be imported back. */
function exportProductsCsv($out, string $search = '', string $statusFilter = ''): void {
    $where = "store_id = :store";
    $params = [':store' => StoreContext::current()->catalogId()];
    if ($search) {
        $where .= " AND (name LIKE :q ESCAPE '!' OR sku LIKE :q2 ESCAPE '!')";
        $escaped = Db::likeLiteral($search);
        $params[':q'] = "%{$escaped}%";
        $params[':q2'] = "%{$escaped}%";
    }
    if ($statusFilter) { $where .= " AND status = :status"; $params[':status'] = $statusFilter; }

    // Unbuffered on MySQL: rows stream to the CSV instead of the whole catalog being held in memory.
    $stmt = Db::executeUnbuffered("SELECT sku, name, slug, product_type, price, compare_price, stock, status, summary, weight_g,
        stock_low_threshold, stock_critical_threshold FROM products WHERE {$where} ORDER BY id", $params);

    $header = ['sku', 'name', 'slug', 'product_type', 'price', 'compare_price', 'stock', 'status', 'summary', 'weight_g', 'stock_low_threshold', 'stock_critical_threshold'];
    // A failure mid-stream is logged, raised as an incident and thrown (CsvExportTruncated).
    CsvExport::stream($out, $stmt, $header, static function (array $row): array {
        $row['price'] = Import::formatCents((int)$row['price']);
        if ($row['compare_price'] !== null) $row['compare_price'] = Import::formatCents((int)$row['compare_price']);
        return array_values(array_map([Security::class, 'csvCell'], $row));
    }, 'products');
}
function pageAttributes(): void {
    $db = Db::get();
    $attrs = CatalogScope::attributesWithCategories();
    ?>
    <h1><?= esc(I18n::t('admin.title.attributes')) ?></h1>
    <p style="margin-bottom:1rem"><a href="admin.php?p=attribute" class="btn btn-primary"><?= esc(I18n::t('admin.title.attribute_new')) ?></a></p>
    <div class="card">
        <table>
            <tr><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.common.slug')) ?></th><th><?= esc(I18n::t('admin.common.type')) ?></th><th><?= esc(I18n::t('admin.attr.unit')) ?></th><th><?= esc(I18n::t('admin.title.categories')) ?></th><th><?= esc(I18n::t('admin.common.sort_order')) ?></th><th></th></tr>
            <?php foreach ($attrs as $a): ?>
            <tr>
                <td><a href="admin.php?p=attribute&id=<?= (int)$a['id'] ?>"><?= esc($a['name']) ?></a></td>
                <td><code><?= esc($a['slug']) ?></code></td>
                <td><?= esc(adminCodeLabel('admin.attr.type.', (string)$a['type'])) ?></td>
                <td><?= esc($a['unit'] ?: '—') ?></td>
                <td class="muted"><?= esc($a['category_names'] ?: '—') ?></td>
                <td><?= (int)$a['sort_order'] ?></td>
                <td>
                    <a href="admin.php?p=attribute&id=<?= (int)$a['id'] ?>" class="btn btn-sm"><?= esc(I18n::t('admin.btn.edit')) ?></a>
                    <form method="post" action="admin.php?p=attributes&action=delete" style="display:inline" data-confirm="<?= esc(I18n::t('admin.common.delete_confirm')) ?>">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="attr_id" value="<?= (int)$a['id'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit"><?= esc(I18n::t('admin.btn.delete')) ?></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($attrs)): ?>
            <tr><td colspan="7" style="text-align:center;color:#64748b"><?= esc(I18n::t('admin.attr.empty')) ?></td></tr>
            <?php endif; ?>
        </table>
    </div>
    <?php
}
function pageAttribute(): void {
    $db = Db::get();
    $id = Security::int($_GET['id'] ?? 0);
    $attr = null;
    $options = [];
    $assignedCats = [];

    if ($id) {
        $stmt = $db->prepare("SELECT * FROM attribute_definitions WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $attr = $stmt->fetch(PDO::FETCH_ASSOC);

        $opts = $db->prepare("SELECT * FROM attribute_options WHERE attribute_id = :aid ORDER BY sort_order, value");
        $opts->execute([':aid' => $id]);
        $options = $opts->fetchAll(PDO::FETCH_ASSOC);

        $assignedCats = CatalogScope::attributeCategoryIds($id);
    }

    $categories = CatalogScope::categories();
    ?>
    <h1><?= $attr ? esc(strtr(I18n::t('admin.common.edit_named'), ['{name}' => $attr['name']])) : esc(I18n::t('admin.title.attribute_new')) ?></h1>
    <div class="card">
        <?php ob_start(); ?>
        <div id="options-section">
            <h3 style="margin:1rem 0 .5rem"><?= esc(I18n::t('admin.attr.values')) ?></h3>
            <div id="options-list">
                <?php foreach ($options as $i => $opt): ?>
                <div class="form-row" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
                    <input name="options[]" aria-label="<?= esc(I18n::t('admin.attr.value')) ?>" value="<?= esc($opt['value']) ?>" style="flex:1" placeholder="<?= esc(I18n::t('admin.attr.value')) ?>">
                    <input type="number" name="option_order[]" aria-label="<?= esc(I18n::t('admin.common.sort_order')) ?>" value="<?= (int)$opt['sort_order'] ?>" style="width:60px" placeholder="<?= esc(I18n::t('admin.common.sort_order')) ?>">
                    <button type="button" data-action="remove-parent" class="btn btn-sm btn-danger">x</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" data-action="add-option" class="btn btn-sm" style="margin-top:.5rem"><?= esc(I18n::t('admin.attr.add_value')) ?></button>
        </div>

        <h3 style="margin:1rem 0 .5rem"><?= esc(I18n::t('admin.attr.assign_categories')) ?></h3>
        <input type="text" id="cat-filter" aria-label="<?= esc(I18n::t('admin.attr.filter_placeholder')) ?>" placeholder="<?= esc(I18n::t('admin.attr.filter_placeholder')) ?>" style="width:100%;padding:.4rem .6rem;margin-bottom:.5rem;border:1px solid #d1d5db;border-radius:4px" data-action="filter-cats">
        <div style="margin-bottom:.5rem;font-size:.8rem">
            <a href="#" data-action="toggle-cats" data-value="1" style="margin-right:.5rem"><?= esc(I18n::t('admin.attr.select_visible')) ?></a>
            <a href="#" data-action="toggle-cats" data-value="0"><?= esc(I18n::t('admin.attr.unselect_all')) ?></a>
            <span id="cat-count" style="float:right;color:#64748b"></span>
        </div>
        <div id="cat-list" style="max-height:350px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:6px;padding:.5rem">
            <?php foreach ($categories as $cat):
                $depth = substr_count($cat['path'] ?: '', '/');
                $indent = $depth * 1.2;
            ?>
            <label class="cat-item" data-search="<?= esc(mb_strtolower($cat['name'] . ' ' . $cat['path'])) ?>" style="display:block;padding:2px 4px 2px <?= $indent + 0.4 ?>rem;cursor:pointer<?= $depth ? ';font-size:.9em' : ';font-weight:600' ?>">
                <input type="checkbox" class="cat-checkbox" name="category_ids[]" value="<?= (int)$cat['id'] ?>" <?= in_array($cat['id'], $assignedCats) ? 'checked' : '' ?>>
                <?= esc($cat['name']) ?>
                <?php if ($cat['path'] && $depth): ?><span style="color:#94a3b8;font-size:.8em;margin-left:.3rem"><?= esc($cat['path']) ?></span><?php endif; ?>
            </label>
            <?php endforeach; ?>
        </div>

        <?php
        $extraSections = (string)ob_get_clean();
        echo AdminForm::render('admin-attribute', 'admin.php?p=attribute&action=save' . ($id ? '&id=' . $id : ''),
            adminFormValues('admin-attribute', $attr ?: null), $extraSections);
        ?>

        <div style="margin-top:1rem">
            <a href="admin.php?p=attributes" class="btn"><?= esc(I18n::t('admin.btn.back')) ?></a>
        </div>
    </div>
    <script nonce="<?= Security::cspNonce() ?>">
    var adminText = <?= json_encode(['value' => I18n::t('admin.attr.value'), 'order' => I18n::t('admin.common.sort_order'), 'count' => I18n::t('admin.attr.count')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    function toggleOptions() {
        document.getElementById('options-section').style.display = document.getElementById('field-type').value === 'enum' ? '' : 'none';
    }
    document.getElementById('field-type').addEventListener('change', toggleOptions);
    function addOption() {
        var div = document.createElement('div');
        div.className = 'form-row';
        div.style.cssText = 'display:flex;gap:.5rem;align-items:center';
        div.innerHTML = '<input name="options[]" aria-label="' + escHtml(adminText.value) + '" style="flex:1" placeholder="' + escHtml(adminText.value) + '">' +
            '<input type="number" name="option_order[]" aria-label="' + escHtml(adminText.order) + '" style="width:60px" placeholder="' + escHtml(adminText.order) + '" value="0">' +
            '<button type="button" data-action="remove-parent" class="btn btn-sm btn-danger">×</button>';
        document.getElementById('options-list').appendChild(div);
    }
    <?= ADMIN_JS_ESC_HTML ?>

    toggleOptions();
    function filterCats() {
        var q = document.getElementById('cat-filter').value.toLowerCase();
        var items = document.querySelectorAll('.cat-item');
        var shown = 0, checked = 0;
        items.forEach(function(el) {
            var match = !q || el.getAttribute('data-search').indexOf(q) !== -1;
            el.style.display = match ? '' : 'none';
            if (match) shown++;
            if (el.querySelector('input').checked) checked++;
        });
        document.getElementById('cat-count').textContent = adminText.count.replace('{checked}', checked).replace('{shown}', shown);
    }
    function toggleAllCats(state) {
        document.querySelectorAll('.cat-item').forEach(function(el) {
            if (el.style.display !== 'none') el.querySelector('input').checked = state;
        });
        filterCats();
    }
    filterCats();
    </script>
    <?php
}
function handleAttributeSave(): void {
    $db = Db::get();
    $id = Security::int($_GET['id'] ?? 0);
    $old = null;
    if ($id) {
        $stmt = $db->prepare("SELECT * FROM attribute_definitions WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $form = AdminForm::validate('admin-attribute', $old ? adminFormValues('admin-attribute', $old) : []);
    if ($form === null) {
        setFlash('err', I18n::t('admin.attr.not_saved') . ' ' . AdminForm::firstError());
        header("Location: admin.php?p=attribute" . ($id ? "&id={$id}" : ''));
        exit;
    }
    $name = $form['name'];
    $slug = Security::slug($form['slug']);
    $type = $form['type'] !== '' ? $form['type'] : 'enum';
    $unit = $form['unit'];
    $sortOrder = Security::int($form['sort_order']);

    // Enum values: options[] with option_order[] — scalars only (anything else is a forged post, refused, not a 500).
    $optValues = $_POST['options'] ?? [];
    $optOrders = $_POST['option_order'] ?? [];
    $scalars = static fn($list): bool => is_array($list) && !array_filter($list, static fn($v): bool => !is_scalar($v));
    $options = [];
    if ($scalars($optValues) && $scalars($optOrders)) {
        foreach ($optValues as $i => $val) if (($val = trim((string)$val)) !== '') $options[$val] ??= Security::int($optOrders[$i] ?? 0);
    }
    $shared = $id && $old && CatalogScope::attributeUsedByOtherCatalog($id);
    $error = $id && !$old ? 'admin.common.record_missing' : (!$slug ? 'admin.attr.required' : null);
    $error ??= (!$scalars($optValues) || !$scalars($optOrders) ? 'admin.attr.options_invalid'
        : ($shared && CatalogScope::sharedAttributeChangeRefused($id, $old, $name, $slug, $type, $unit) ? 'admin.attr.shared_locked' : null));
    if ($error !== null) {
        setFlash('err', I18n::t($error));
        header("Location: admin.php?p=attribute" . ($id ? "&id={$id}" : ''));
        exit;
    }

    // Check slug uniqueness
    $check = $db->prepare("SELECT id FROM attribute_definitions WHERE slug = :slug AND id != :id");
    $check->execute([':slug' => $slug, ':id' => $id ?: -1]);
    if ($check->fetch()) {
        setFlash('err', strtr(I18n::t('admin.attr.slug_taken'), ['{slug}' => $slug]));
        header("Location: admin.php?p=attribute" . ($id ? "&id={$id}" : ''));
        exit;
    }

    if ($id) {
        $db->prepare("UPDATE attribute_definitions SET name = :name, slug = :slug, type = :type, unit = :unit, sort_order = :sort WHERE id = :id")
           ->execute([':name' => $name, ':slug' => $slug, ':type' => $type, ':unit' => $unit, ':sort' => $sortOrder, ':id' => $id]);
        CatalogScope::renameAttributeValues($id, (string)$old['name'], $name);
    } else {
        $db->prepare("INSERT INTO attribute_definitions (name, slug, type, unit, sort_order) VALUES (:name, :slug, :type, :unit, :sort)")
           ->execute([':name' => $name, ':slug' => $slug, ':type' => $type, ':unit' => $unit, ':sort' => $sortOrder]);
        $id = (int)$db->lastInsertId();
    }

    // Enum options; values another catalog may use are never deleted (see CatalogScope::saveAttributeOptions).
    $oldOptions = CatalogScope::attributeOptionValues($id);
    CatalogScope::saveAttributeOptions($id, $type === 'enum' ? $options : [], $shared);

    // Category assignments of the active catalog; other catalogs keep their links to this shared attribute.
    CatalogScope::replaceAttributeCategories($id, $_POST['category_ids'] ?? []);
    AuditLog::log('attribute', $id, $old ? $old + ['options' => $oldOptions] : null,
        ['name' => $name, 'slug' => $slug, 'type' => $type, 'unit' => $unit, 'sort_order' => $sortOrder, 'options' => CatalogScope::attributeOptionValues($id)],
        $old ? 'Admin attribute edit' : 'Admin attribute create');

    setFlash('ok', I18n::t('admin.attr.saved'));
    header("Location: admin.php?p=attribute&id={$id}");
    exit;
}
function handleAttributeDelete(): void {
    $id = Security::int($_POST['attr_id'] ?? 0);
    $db = Db::get();
    if ($id && CatalogScope::attributeUsedByOtherCatalog($id)) {
        setFlash('err', I18n::t('admin.attr.used_elsewhere'));
    } elseif ($id) {
        $stmt = $db->prepare("SELECT * FROM attribute_definitions WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $options = CatalogScope::attributeOptionValues($id);
        $db->prepare("DELETE FROM attribute_options WHERE attribute_id = :id")->execute([':id' => $id]);
        $db->prepare("DELETE FROM category_attributes WHERE attribute_id = :id")->execute([':id' => $id]);
        $db->prepare("DELETE FROM attribute_definitions WHERE id = :id")->execute([':id' => $id]);
        if ($old) AuditLog::log('attribute', $id, $old + ['options' => $options], null, 'Admin attribute delete');
        setFlash('ok', I18n::t('admin.attr.deleted'));
    }
    header('Location: admin.php?p=attributes');
    exit;
}

// ============================================================
// Helpers
// ============================================================
