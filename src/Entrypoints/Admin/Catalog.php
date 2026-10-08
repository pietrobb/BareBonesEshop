<?php
/** Catalog, content, attributes, assets and review administration. */

function pageProducts(): void {
    $db = Db::get();
    $page = max(1, Security::int($_GET['page'] ?? 1));
    $perPage = 25;
    $offset = ($page - 1) * $perPage;
    $search = trim($_GET['q'] ?? '');
    $statusFilter = $_GET['status'] ?? '';
    $stockFilter = ($_GET['stock'] ?? '') === 'low' ? 'low' : '';
    // Same thresholds and warehouse availability as the stock e-mails (worst level first).
    $stockLevels = [];
    foreach (StockAlerts::belowLow() as $row) $stockLevels[$row['product_id']] ??= $row['level'];

    $where = '1=1';
    $params = [];
    if ($search) {
        $where .= " AND (name LIKE :q OR sku LIKE :q2)";
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
        $params[':q'] = "%{$escaped}%";
        $params[':q2'] = "%{$escaped}%";
    }
    if ($statusFilter) {
        $where .= " AND status = :status";
        $params[':status'] = $statusFilter;
    }
    if ($stockFilter) $where .= $stockLevels ? ' AND id IN (' . implode(',', array_map('intval', array_keys($stockLevels))) . ')' : ' AND 1=0';

    $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE {$where}");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));

    $params[':limit'] = $perPage;
    $params[':offset'] = $offset;
    $stmt = $db->prepare("SELECT * FROM products WHERE {$where} ORDER BY updated_at DESC LIMIT :limit OFFSET :offset");
    $stmt->execute($params);
    $products = $stmt->fetchAll();
    ?>
    <h1><?= esc(I18n::t('admin.title.products')) ?> <span class="muted">(<?= $total ?>)</span></h1>
    <div class="card">
        <form method="get" style="display:flex;gap:.5rem;margin-bottom:1rem">
            <input type="hidden" name="p" value="products">
            <input name="q" value="<?= esc($search) ?>" placeholder="<?= esc(I18n::t('admin.products.search_placeholder')) ?>">
            <select name="status">
                <option value="">— <?= esc(I18n::t('admin.th.status')) ?> —</option>
                <option value="active" <?= $statusFilter==='active'?'selected':'' ?>>active</option>
                <option value="draft" <?= $statusFilter==='draft'?'selected':'' ?>>draft</option>
                <option value="archived" <?= $statusFilter==='archived'?'selected':'' ?>>archived</option>
            </select>
            <select name="stock"><option value="">— <?= esc(I18n::t('admin.th.stock')) ?> —</option><option value="low" <?= $stockFilter==='low'?'selected':'' ?>><?= esc(I18n::t('admin.products.filter_low_stock')) ?></option></select>
            <button class="btn" type="submit"><?= esc(I18n::t('admin.btn.apply')) ?></button>
        </form>
        <form method="post" action="admin.php?p=products&action=bulk" id="bulk-form" data-confirm="OK?">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div style="display:flex;gap:.5rem;margin-bottom:.5rem">
                <select name="bulk_action">
                    <option value="">— <?= esc(I18n::t('admin.products.bulk_action')) ?> —</option>
                    <option value="activate">activate</option>
                    <option value="archive">archive</option>
                    <option value="delete"><?= esc(I18n::t('admin.btn.delete')) ?></option>
                    <option value="price_up_5">Price +5%</option>
                    <option value="price_up_10">Price +10%</option>
                    <option value="price_up_20">Price +20%</option>
                    <option value="price_down_5">Price -5%</option>
                    <option value="price_down_10">Price -10%</option>
                    <option value="price_down_20">Price -20%</option>
                </select>
                <button type="submit" class="btn btn-sm"><?= esc(I18n::t('admin.btn.apply')) ?></button>
                <input type="hidden" name="q" value="<?= esc($search) ?>">
                <input type="hidden" name="status" value="<?= esc($statusFilter) ?>">
                <button type="submit" class="btn btn-sm" formaction="admin.php?p=products&amp;action=export" formmethod="post" data-skip-confirm><?= esc(I18n::t('admin.btn.export_csv')) ?></button>
            </div>
        <table>
            <tr><th><input type="checkbox" id="check-all"></th><th><?= esc(I18n::t('admin.th.sku')) ?></th><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.th.price')) ?></th><th><?= esc(I18n::t('admin.th.stock')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.th.actions')) ?></th></tr>
            <?php foreach ($products as $p): ?>
            <tr>
                <td><input type="checkbox" name="product_ids[]" value="<?= $p['id'] ?>" class="product-check"></td>
                <td><?= esc($p['sku']) ?></td>
                <td><a href="admin.php?p=product&id=<?= $p['id'] ?>"><?= esc($p['name']) ?></a></td>
                <td><?= I18n::price((int)$p['price'], $p['currency']) ?></td>
                <td class="<?= isset($stockLevels[(int)$p['id']]) ? 'stock-' . ($stockLevels[(int)$p['id']] === StockAlerts::LOW ? 'low' : 'critical') : '' ?>"><?= (int)$p['stock'] ?></td>
                <td><span class="badge badge-<?= esc($p['status']) ?>"><?= esc($p['status']) ?></span></td>
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
            'product_type'  => $row['product_type'] ?? 'simple',
            'price'         => (string)(int)($row['price'] ?? 0),
            'compare_price' => isset($row['compare_price']) ? (string)(int)$row['compare_price'] : '',
            'stock'         => (string)(int)($row['stock'] ?? 0),
            'status'        => $row['status'] ?? 'active',
            'summary'       => $row['summary'] ?? '',
            'description'   => $row['description'] ?? '',
            'images'        => implode("\n", json_decode($row['images'] ?? '[]', true) ?: []),
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
    $categories = $db->query("SELECT id, name, path FROM categories ORDER BY path")->fetchAll();
    $relatedProductIds = [];
    $relatedCandidates = [];
    $relationType = Security::slug((string)($_GET['relation_type'] ?? 'related')) ?: 'related';
    if ($id) {
        $relatedStmt = $db->prepare("SELECT related_product_id FROM product_related_products WHERE product_id = :id AND relation_type = :type ORDER BY sort_order");
        $relatedStmt->execute([':id' => $id, ':type' => $relationType]);
        $relatedProductIds = array_map('intval', $relatedStmt->fetchAll(PDO::FETCH_COLUMN));

        $candidateStmt = $db->prepare("SELECT id, sku, name, product_type FROM products WHERE status = 'active' AND id != :id ORDER BY product_type, name");
        $candidateStmt->execute([':id' => $id]);
        $relatedCandidates = $candidateStmt->fetchAll(PDO::FETCH_ASSOC);
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
    <h1><?= $product ? 'Upraviť: ' . esc($product['name']) : 'Nový produkt' ?></h1>
    <div class="card">
        <?php ob_start(); ?>
        <div id="attr-section" style="border-top:2px solid #e2e8f0;margin-top:1rem;padding-top:1rem">
            <h3 style="margin-bottom:.75rem">Atribúty</h3>
            <p class="muted" style="color:#64748b;font-size:.85rem">Atribúty sa načítajú podľa zvolenej kategórie po uložení produktu.</p>
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
                        <option value="">— nevybrané —</option>
                        <?php foreach ($attrOptions[$a['id']] ?? [] as $opt): ?>
                        <option value="<?= esc($opt) ?>" <?= $curVal === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php elseif ($a['type'] === 'number'): ?>
                    <input type="number" step="any" name="attr[<?= esc($a['name']) ?>]" value="<?= esc($curNum ?: $curVal) ?>" placeholder="<?= esc($a['unit'] ?: 'číslo') ?>">
                    <?php elseif ($a['type'] === 'boolean'): ?>
                    <select name="attr[<?= esc($a['name']) ?>]">
                        <option value="">— nevybrané —</option>
                        <option value="Áno" <?= $curVal === 'Áno' ? 'selected' : '' ?>>Áno</option>
                        <option value="Nie" <?= $curVal === 'Nie' ? 'selected' : '' ?>>Nie</option>
                    </select>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php if (empty($catAttrs)): ?>
                <p class="muted" style="color:#64748b;font-size:.85rem">Pre túto kategóriu nie sú definované atribúty. <a href="admin.php?p=attributes">Spravovať atribúty</a></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        $attrSection = (string)ob_get_clean();
        echo AdminForm::render('admin-product', 'admin.php?p=product&action=save' . ($product ? '&id=' . (int)$product['id'] : ''),
            adminFormValues('admin-product', $product ?: null), $attrSection);
        ?>

        <?php if ($product): ?>
        <div class="card" style="margin-top:1rem">
            <h3 style="margin-bottom:.75rem">Súvisiace produkty</h3>
            <form method="post" action="admin.php?p=product&id=<?= (int)$product['id'] ?>&action=related_save">
                <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                <div class="form-row">
                    <label>Typ vzťahu</label>
                    <input name="relation_type" value="<?= esc($relationType) ?>" pattern="[a-z][a-z0-9_-]{0,79}" required>
                </div>
                <div class="form-row">
                    <label>Ručne vybrané produkty pre tento typ</label>
                    <select name="related_product_ids[]" multiple size="8" style="width:100%">
                        <?php foreach ($relatedCandidates as $candidate): ?>
                        <option value="<?= (int)$candidate['id'] ?>" <?= in_array((int)$candidate['id'], $relatedProductIds, true) ? 'selected' : '' ?>>
                            <?= esc($candidate['name']) ?> · <?= esc($candidate['sku']) ?> · <?= esc($candidate['product_type']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="muted" style="margin-top:.35rem">Ak nič nevyberiete, produktová stránka použije automatický výber z rovnakej kategórie.</p>
                </div>
                <button class="btn btn-primary" type="submit">Uložiť súvisiace produkty</button>
            </form>
        </div>
        <div style="margin-top:1rem"><a href="admin.php?p=products" class="btn">Späť na zoznam</a></div>
        <?php endif; ?>
    </div>
    <script nonce="<?= Security::cspNonce() ?>">
    var catAttrMap = <?= json_encode($catAttrMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var existingSpecs = <?= json_encode($existingSpecs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    function updateAttrFields() {
        var catId = document.getElementById('field-category_id').value;
        var attrs = catAttrMap[catId] || [];
        var container = document.getElementById('attr-fields');
        if (!attrs.length) {
            container.innerHTML = '<p class="muted" style="color:#64748b;font-size:.85rem">Pre túto kategóriu nie sú definované atribúty. <a href="admin.php?p=attributes">Spravovať atribúty</a></p>';
            return;
        }
        var html = '';
        attrs.forEach(function(a) {
            var spec = existingSpecs[a.name] || {};
            var curVal = spec.criteria_value || '';
            var curNum = spec.numeric_val || '';
            html += '<div class="form-row"><label>' + escHtml(a.name) + (a.unit ? ' (' + escHtml(a.unit) + ')' : '') + '</label>';
            if (a.type === 'enum') {
                html += '<select name="attr[' + escHtml(a.name) + ']"><option value="">— nevybrané —</option>';
                (a.options || []).forEach(function(o) {
                    html += '<option value="' + escHtml(o) + '"' + (curVal === o ? ' selected' : '') + '>' + escHtml(o) + '</option>';
                });
                html += '</select>';
            } else if (a.type === 'number') {
                html += '<input type="number" step="any" name="attr[' + escHtml(a.name) + ']" value="' + escHtml(curNum || curVal) + '" placeholder="' + escHtml(a.unit || 'číslo') + '">';
            } else if (a.type === 'boolean') {
                html += '<select name="attr[' + escHtml(a.name) + ']"><option value="">— nevybrané —</option>';
                html += '<option value="Áno"' + (curVal === 'Áno' ? ' selected' : '') + '>Áno</option>';
                html += '<option value="Nie"' + (curVal === 'Nie' ? ' selected' : '') + '>Nie</option></select>';
            }
            html += '</div>';
        });
        container.innerHTML = html;
    }
    function escHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    document.getElementById('field-category_id').addEventListener('change', updateAttrFields);
    </script>
    <?php
}
function pageDigitalAssets(): void {
    $cfg = require dirname(__DIR__, 3) . '/config.php';
    $baseDir = (string)($cfg['digital_assets_base_dir'] ?? '');
    $assets = Db::get()->query("SELECT da.*,
            (SELECT COUNT(*) FROM digital_entitlements de WHERE de.asset_id = da.id) AS entitlement_count
        FROM digital_assets da ORDER BY da.sku, da.asset_key")->fetchAll();
    ?>
    <h1>Digitálne súbory</h1>
    <div class="card">
        <p>Súbory musia byť uložené mimo verejného webrootu pod:</p>
        <p><code><?= esc($baseDir ?: 'NIE JE NASTAVENÉ') ?></code></p>
        <p class="muted">Do poľa cesta zadajte iba relatívnu cestu, napríklad <code>ebooks/manual.pdf</code>.</p>
    </div>
    <div class="card">
        <h3>Pridať alebo upraviť súbor</h3>
        <form method="post" action="admin.php?p=digital_assets&action=save">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <input type="hidden" name="asset_id" value="0">
            <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(190px,1fr))">
                <div class="form-row"><label>SKU</label><input name="sku" required maxlength="191"></div>
                <div class="form-row"><label>Kľúč súboru</label><input name="asset_key" value="default" required maxlength="80"></div>
                <div class="form-row"><label>Názov</label><input name="label" maxlength="191"></div>
                <div class="form-row"><label>Relatívna cesta</label><input name="storage_path" placeholder="ebooks/manual.pdf" required maxlength="500"></div>
                <div class="form-row"><label>Názov pri stiahnutí</label><input name="download_name" placeholder="manual.pdf" required maxlength="180"></div>
                <div class="form-row"><label>Content-Type</label><input name="content_type" value="application/octet-stream" required maxlength="100"></div>
                <div class="form-row"><label>Limit stiahnutí (0 = bez limitu)</label><input name="download_limit" type="number" min="0" max="10000" value="5"></div>
                <div class="form-row"><label>Platnosť v dňoch</label><input name="expiry_days" type="number" min="0" max="3650" value="7"></div>
            </div>
            <label><input type="checkbox" name="active" value="1" checked> Aktívny</label>
            <button class="btn btn-primary" type="submit">Uložiť</button>
        </form>
    </div>
    <div class="card">
        <h3>Mapovania SKU</h3>
        <?php if (!$assets): ?><p class="muted">Zatiaľ nie je pridaný žiadny digitálny súbor.</p><?php else: ?>
        <table>
            <tr><th>SKU</th><th>Kľúč</th><th>Cesta</th><th>Súbor</th><th>Limit / dni</th><th>Stav</th><th>Oprávnenia</th><th></th></tr>
            <?php foreach ($assets as $asset): ?>
            <tr>
                <td><?= esc($asset['sku']) ?></td><td><?= esc($asset['asset_key']) ?></td>
                <td class="muted"><?= esc($asset['storage_path']) ?></td><td><?= esc($asset['download_name']) ?></td>
                <td><?= $asset['download_limit'] === null ? '∞' : (int)$asset['download_limit'] ?> / <?= $asset['expiry_seconds'] === null ? '∞' : round((int)$asset['expiry_seconds'] / 86400, 2) ?></td>
                <td><span class="badge badge-<?= (int)$asset['active'] ? 'active' : 'draft' ?>"><?= (int)$asset['active'] ? 'active' : 'disabled' ?></span></td>
                <td><?= (int)$asset['entitlement_count'] ?></td>
                <td>
                    <form method="post" action="admin.php?p=digital_assets&action=toggle" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="asset_id" value="<?= (int)$asset['id'] ?>">
                        <input type="hidden" name="active" value="<?= (int)$asset['active'] ? 0 : 1 ?>">
                        <button class="btn btn-sm" type="submit"><?= (int)$asset['active'] ? 'Deaktivovať' : 'Aktivovať' ?></button>
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
    <h1>Recenzie — moderácia <span class="muted">(<?= count($pending) ?> čaká)</span></h1>
    <div class="card">
        <?php if (!$pending): ?>
        <p class="muted">Žiadne recenzie nečakajú na schválenie.</p>
        <?php else: ?>
        <table>
            <tr><th>ID</th><th>Produkt</th><th>Autor</th><th>Hodnotenie</th><th>Text</th><th>Kedy</th><th>Akcie</th></tr>
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
                        <button class="btn btn-primary" type="submit">Schváliť</button>
                    </form>
                    <form method="post" action="admin.php?p=reviews&action=reject" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="review_id" value="<?= (int)$rv['id'] ?>">
                        <button class="btn" type="submit">Zamietnuť</button>
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
    <h1>Content &amp; Marketing <span class="muted"><?= esc(StoreContext::current()->id()) ?></span></h1>
    <p class="muted" style="margin-bottom:1rem">Theme: <code><?= esc((string)(StoreContext::current()->config()['theme'] ?? 'default')) ?></code> ·
        <a href="/feeds/google.xml" target="_blank" rel="noopener">Google Merchant XML</a> ·
        <a href="/feeds/meta.csv" target="_blank" rel="noopener">Meta CSV</a></p>
    <div class="grid grid-4">
        <section class="card">
            <h2>Menu item</h2>
            <form method="post" action="admin.php?p=content&amp;action=save">
                <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>"><input type="hidden" name="content_type" value="menu">
                <div class="form-row"><label>Menu code<input name="menu_code" value="primary" required pattern="[a-z0-9_-]+"></label></div>
                <div class="form-row"><label>Locale<input name="locale" value="*" required></label></div>
                <div class="form-row"><label>Label<input name="label" maxlength="191" required></label></div>
                <div class="form-row"><label>Local URL<input name="url" value="/" required></label></div>
                <div class="form-row"><label>Order<input name="sort_order" type="number" value="0"></label></div>
                <label><input name="active" type="checkbox" value="1" checked> Active</label><br><button class="btn btn-primary">Save menu item</button>
            </form>
        </section>
        <section class="card">
            <h2>Banner</h2>
            <form method="post" action="admin.php?p=content&amp;action=save">
                <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>"><input type="hidden" name="content_type" value="banner">
                <div class="form-row"><label>Slot<input name="slot" value="homepage" required pattern="[a-z0-9_-]+"></label></div>
                <div class="form-row"><label>Locale<input name="locale" value="*" required></label></div>
                <div class="form-row"><label>Title<input name="title" maxlength="191" required></label></div>
                <div class="form-row"><label>Body<textarea name="body" rows="2"></textarea></label></div>
                <div class="form-row"><label>Image URL<input name="image_url" placeholder="/assets/img/banner.jpg"></label></div>
                <div class="form-row"><label>Link URL<input name="link_url" placeholder="/category/sale"></label></div>
                <div class="form-row"><label>Link label<input name="link_label" maxlength="191"></label></div>
                <div class="form-row"><label>Starts<input name="starts_at" type="datetime-local"></label></div>
                <div class="form-row"><label>Ends<input name="ends_at" type="datetime-local"></label></div>
                <label><input name="active" type="checkbox" value="1" checked> Active</label><br><button class="btn btn-primary">Save banner</button>
            </form>
        </section>
        <section class="card">
            <h2>Landing page</h2>
            <form method="post" action="admin.php?p=content&amp;action=save">
                <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>"><input type="hidden" name="content_type" value="landing">
                <div class="form-row"><label>Slug<input name="slug" required pattern="[a-z0-9-]+"></label></div>
                <div class="form-row"><label>Title<input name="title" maxlength="191" required></label></div>
                <div class="form-row"><label>Body<textarea name="body" rows="4"></textarea></label></div>
                <div class="form-row"><label>SEO title<input name="seo_title" maxlength="191"></label></div>
                <div class="form-row"><label>Meta description<textarea name="meta_description" maxlength="320" rows="2"></textarea></label></div>
                <div class="form-row"><label>Status<select name="status"><option value="draft">Draft</option><option value="active">Active</option></select></label></div>
                <button class="btn btn-primary">Save landing page</button>
            </form>
        </section>
        <section class="card">
            <h2>Redirect</h2>
            <form method="post" action="admin.php?p=content&amp;action=save">
                <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>"><input type="hidden" name="content_type" value="redirect">
                <div class="form-row"><label>Source path<input name="source_path" placeholder="/old-path" required></label></div>
                <div class="form-row"><label>Target path<input name="target_url" placeholder="/new-path" required></label></div>
                <div class="form-row"><label>Status<select name="http_status"><option>301</option><option>302</option><option>307</option><option>308</option></select></label></div>
                <label><input name="active" type="checkbox" value="1" checked> Active</label><br><button class="btn btn-primary">Save redirect</button>
            </form>
        </section>
    </div>
    <?php foreach ([
        'menu' => ['Menu items', $menus, ['menu_code','locale','label','url']],
        'banner' => ['Banners', $banners, ['slot','locale','title','link_url']],
        'landing' => ['Landing pages', $landings, ['slug','title','status','seo_title']],
        'redirect' => ['Redirects', $redirects, ['source_path','target_url','http_status','active']],
    ] as $type => [$heading, $rows, $columns]): ?>
    <section class="card"><h2><?= esc($heading) ?></h2><div class="table-scroll"><table><tr>
        <?php foreach ($columns as $column): ?><th><?= esc($column) ?></th><?php endforeach; ?><th>Actions</th></tr>
        <?php foreach ($rows as $row): ?><tr><?php foreach ($columns as $column): ?><td><?= esc((string)($row[$column] ?? '')) ?></td><?php endforeach; ?><td>
            <form method="post" action="admin.php?p=content&amp;action=delete" data-confirm="Delete this content record?">
                <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>"><input type="hidden" name="content_type" value="<?= esc($type) ?>"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button class="btn btn-danger btn-sm">Delete</button>
            </form></td></tr><?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?= count($columns) + 1 ?>" class="muted">No records.</td></tr><?php endif; ?>
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
        setFlash('ok', 'Content saved.');
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
        setFlash('ok', 'Content deleted.');
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
    $pages = $db->query("SELECT * FROM pages ORDER BY created_at DESC")->fetchAll();
    ?>
    <h1>CMS Stránky</h1>
    <p style="margin-bottom:1rem"><a href="admin.php?p=page_edit" class="btn btn-primary">Nová stránka</a></p>
    <div class="card">
        <table>
            <tr><th>Slug</th><th>Názov</th><th>Stav</th><th>Vytvorené</th><th></th></tr>
            <?php foreach ($pages as $pg): ?>
            <tr>
                <td><code><?= esc($pg['slug']) ?></code></td>
                <td><?= esc($pg['title']) ?></td>
                <td><span class="badge badge-<?= $pg['status'] === 'active' ? 'active' : 'draft' ?>"><?= esc($pg['status']) ?></span></td>
                <td class="muted"><?= esc(substr($pg['created_at'], 0, 16)) ?></td>
                <td><a href="admin.php?p=page_edit&id=<?= (int)$pg['id'] ?>" class="btn btn-sm">Upraviť</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php
}
function pageCmsEdit(): void {
    $id = Security::int($_GET['id'] ?? 0);
    $pg = $id ? Db::get()->prepare("SELECT * FROM pages WHERE id = :id")->execute([':id' => $id]) ? Db::get()->prepare("SELECT * FROM pages WHERE id = :id") : null : null;
    if ($id) {
        $stmt = Db::get()->prepare("SELECT * FROM pages WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $pg = $stmt->fetch();
    }
    ?>
    <h1><?= $id ? 'Upraviť stránku' : 'Nová stránka' ?></h1>
    <div class="card">
        <?php
        echo AdminForm::render('admin-page', 'admin.php?p=page_edit&action=save' . ($id ? '&id=' . $id : ''), adminFormValues('admin-page', $pg ?: null));
        ?>
        <?php if ($id): ?>
        <div style="margin-top:1rem"><a href="admin.php?p=pages" class="btn">Späť</a></div>
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
        $stmt = $db->prepare("SELECT * FROM pages WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $old = $stmt->fetch() ?: null;
    }
    $form = AdminForm::validate('admin-page', $old ? adminFormValues('admin-page', $old) : []);
    if ($form === null) {
        setFlash('err', 'Stránka nebola uložená: ' . AdminForm::firstError());
        header("Location: admin.php?p=page_edit&id={$id}");
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
        setFlash('err', 'Sekcie musia byť platné bezpečné JSON pole.');
        header("Location: admin.php?p=page_edit&id={$id}");
        exit;
    }
    $status = $form['status'] !== '' ? $form['status'] : 'active';

    if (!$slug) {
        setFlash('err', 'Slug a názov sú povinné.');
        header("Location: admin.php?p=page_edit&id={$id}");
        exit;
    }

    if ($id) {
        $db->prepare("UPDATE pages SET slug = :slug, title = :title, body = :body, page_type = :page_type, template = :template, layout = :layout, sections = :sections, status = :status, updated_at = {$now} WHERE id = :id")
           ->execute([':slug' => $slug, ':title' => $title, ':body' => $body, ':page_type' => $pageType, ':template' => $template, ':layout' => $layout, ':sections' => $sections, ':status' => $status, ':id' => $id]);
        setFlash('ok', 'Stránka aktualizovaná.');
    } else {
        $db->prepare("INSERT INTO pages (slug, title, body, page_type, template, layout, sections, status) VALUES (:slug, :title, :body, :page_type, :template, :layout, :sections, :status)")
           ->execute([':slug' => $slug, ':title' => $title, ':body' => $body, ':page_type' => $pageType, ':template' => $template, ':layout' => $layout, ':sections' => $sections, ':status' => $status]);
        $id = (int)$db->lastInsertId();
        setFlash('ok', 'Stránka vytvorená.');
    }
    header("Location: admin.php?p=page_edit&id={$id}");
    exit;
}
function handleCmsPageDelete(): void {
    $id = Security::int($_POST['page_id'] ?? 0);
    if ($id) {
        Db::get()->prepare("DELETE FROM pages WHERE id = :id")->execute([':id' => $id]);
        setFlash('ok', 'Stránka zmazaná.');
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
    $path = str_replace('\\', '/', trim((string)($_POST['storage_path'] ?? '')));
    $downloadName = basename(str_replace('\\', '/', trim((string)($_POST['download_name'] ?? ''))));
    $contentType = trim((string)($_POST['content_type'] ?? 'application/octet-stream'));
    $submittedLimit = max(0, min(10000, Security::int($_POST['download_limit'] ?? 5)));
    $downloadLimit = $submittedLimit > 0 ? $submittedLimit : null;
    $expiryDays = max(0, min(3650, Security::int($_POST['expiry_days'] ?? 7)));
    $active = !empty($_POST['active']) ? 1 : 0;

    $invalidPath = $path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path)
        || in_array('..', explode('/', $path), true) || str_contains($path, "\0");
    if ($sku === '' || strlen($sku) > 191 || !preg_match('/^[A-Za-z0-9._-]{1,191}$/', $sku)
        || !preg_match('/^[A-Za-z0-9._-]{1,80}$/', $assetKey) || $invalidPath
        || $downloadName === '' || !preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $contentType)) {
        setFlash('err', 'Neplatné údaje digitálneho súboru.');
        header('Location: admin.php?p=digital_assets');
        exit;
    }

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
        setFlash('ok', 'Digitálny súbor bol uložený.');
    } catch (Throwable $e) {
        error_log('Digital asset save failed: ' . $e->getMessage());
        setFlash('err', 'Digitálny súbor sa nepodarilo uložiť.');
    }
    header('Location: admin.php?p=digital_assets');
    exit;
}
function handleDigitalAssetToggle(): void {
    Security::adminRequire('products.edit');
    $id = Security::int($_POST['asset_id'] ?? 0);
    $active = !empty($_POST['active']) ? 1 : 0;
    if ($id > 0) {
        Db::get()->prepare("UPDATE digital_assets SET active = :active, updated_at = " . Db::now() . " WHERE id = :id")
            ->execute([':active' => $active, ':id' => $id]);
        setFlash('ok', $active ? 'Digitálny súbor bol aktivovaný.' : 'Digitálny súbor bol deaktivovaný.');
    }
    header('Location: admin.php?p=digital_assets');
    exit;
}
function handleReviewModerate(string $status): void {
    Security::adminRequire('products.edit');
    $id = Security::int($_POST['review_id'] ?? 0);
    if ($id > 0 && Reviews::setStatus($id, $status)) {
        setFlash('ok', 'Recenzia #' . $id . ' → ' . $status);
    } else {
        setFlash('err', 'Recenziu sa nepodarilo upraviť.');
    }
    header('Location: admin.php?p=reviews');
    exit;
}
function handleProductRelatedSave(): void {
    $db = Db::get();
    $id = Security::int($_GET['id'] ?? 0);
    if ($id <= 0) {
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
    $allowed = array_map('intval', $db->query("SELECT id FROM products WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN));
    $selected = array_values(array_intersect($selected, $allowed));

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

    setFlash('ok', 'Produktové vzťahy boli uložené.');
    header("Location: admin.php?p=product&id={$id}&relation_type=" . rawurlencode($relationType));
    exit;
}
function handleProductSave(): void {
    $db = Db::get();
    $id = Security::int($_GET['id'] ?? 0);
    $now = Db::isSqlite() ? "datetime('now')" : 'NOW()';
    $old = $id ? Products::byId($id) : null;
    if ($id && !$old) {
        setFlash('err', 'Produkt neexistuje.');
        header('Location: admin.php?p=products');
        exit;
    }

    // BBF checks required fields, numbers, the select lists (a stored value outside today's lists survives an
    // unchanged save) and the uploaded image's size, extension and content.
    $form = AdminForm::validate('admin-product', $old ? adminFormValues('admin-product', $old) : []);
    if ($form === null) {
        setFlash('err', 'Produkt nebol uložený: ' . AdminForm::firstError());
        header("Location: admin.php?p=product&id={$id}");
        exit;
    }
    $nullableCount = static fn(string $v): ?int => $v === '' ? null : max(0, Security::int($v));

    $data = [
        ':sku'           => $form['sku'],
        ':name'          => $form['name'],
        ':slug'          => Security::slug($form['slug']),
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
    ];
    $productType = $data[':product_type'];

    $images = array_values(array_filter(array_map('trim', explode("\n", $form['images'])), 'strlen'));

    $upload = $form['image_upload']['action'] === 'replace' ? $form['image_upload']['files'][0] : null;
    if ($upload) {
        $tmpName = $upload['tmp_name'];
        $dimensions = @getimagesize($tmpName);
        $validDimensions = is_array($dimensions)
            && ($dimensions[0] ?? 0) > 0 && ($dimensions[1] ?? 0) > 0
            && ($dimensions[0] ?? 0) <= 12000 && ($dimensions[1] ?? 0) <= 12000
            && ($dimensions[0] * $dimensions[1]) <= 40000000;
        if (!$validDimensions) {
            setFlash('err', 'Obrázok je neplatný alebo má príliš veľké rozmery.');
            header("Location: admin.php?p=product&id={$id}");
            exit;
        }

        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$upload['type']];
        $safeSku = preg_replace('/[^a-zA-Z0-9_-]/', '_', $data[':sku'] ?: bin2hex(random_bytes(6)));
        $filename = 'products/' . $safeSku . '-' . time() . '.' . $extension;
        $imageBytes = file_get_contents($tmpName);
        if ($imageBytes === false || strlen($imageBytes) !== $upload['size']) {
            throw new RuntimeException('Uploaded image could not be read completely');
        }
        $storage = Adapters::storage();
        $url = $storage->store($filename, $imageBytes);
        $images[] = $url;
        JobQueue::push('image_resize', ['path' => $filename], 3);
    }
    $data[':images'] = json_encode($images);
    $data[':tags'] = json_encode(array_values(array_filter(array_map('trim', explode(',', $form['tags'])), 'strlen')));

    // Validate slug uniqueness
    $slug = $data[':slug'];
    $checkSlug = $db->prepare("SELECT id FROM products WHERE slug = :slug AND id != :id");
    $checkSlug->execute([':slug' => $slug, ':id' => $id ?: -1]);
    if ($checkSlug->fetch()) {
        setFlash('err', 'Slug "' . esc($slug) . '" už existuje pre iný produkt.');
        header("Location: admin.php?p=product&id={$id}");
        exit;
    }

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
                'price', 'stock', 'weight_g', 'category_id' => (int)$before === (int)$value,
                'compare_price' => ($nullableInt($before) ?: null) === $value,
                'stock_low_threshold', 'stock_critical_threshold' => $nullableInt($before) === $value,
                default => (string)$before === (string)$value,
            };
            if (!$same) $changed[] = $column;
        }
        if ($changed) {
            $stmt = $db->prepare("UPDATE products SET sku=:sku, name=:name, slug=:slug, product_type=:product_type,
                category_id=:category_id, price=:price, compare_price=:compare_price, stock=:stock,
                status=:status, summary=:summary, description=:description, images=:images, tags=:tags, weight_g=:weight_g,
                stock_low_threshold=:stock_low_threshold, stock_critical_threshold=:stock_critical_threshold, updated_at={$now} WHERE id=:id");
            $stmt->execute($data + [':id' => $id]);
            AuditLog::log('product', $id, $old, $data, 'Admin edit: ' . implode(', ', $changed));
            setFlash('ok', 'Produkt aktualizovaný.');
        } else {
            setFlash('ok', 'Bez zmien.');
        }
    } else {
        $stmt = $db->prepare("INSERT INTO products (sku, name, slug, product_type, category_id, price, compare_price, stock, status, summary, description, images, tags, weight_g, stock_low_threshold, stock_critical_threshold)
            VALUES (:sku, :name, :slug, :product_type, :category_id, :price, :compare_price, :stock, :status, :summary, :description, :images, :tags, :weight_g, :stock_low_threshold, :stock_critical_threshold)");
        $stmt->execute($data);
        $id = (int)$db->lastInsertId();
        AuditLog::log('product', $id, null, $data, 'Admin create');
        setFlash('ok', 'Produkt vytvorený.');
    }

    // The stock field is "available"; book the change in the warehouse ledger (the source of truth).
    try {
        if ((int)($old['stock'] ?? -1) !== $data[':stock']) Products::setAvailableStock($data[':sku'], $productType, $data[':stock'], 'manual_adjustment', 'admin_product');
    } catch (RuntimeException | InvalidArgumentException $e) {
        setFlash('err', 'Sklad nebol zmenený: ' . $e->getMessage());
    }

    // Save attribute values to product_specifications
    $attrValues = $_POST['attr'] ?? [];
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
            $val = trim($val);
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

    header("Location: admin.php?p=product&id={$id}");
    exit;
}
function handleBulkAction(): void {
    // CSRF already validated by handlePost()
    Security::adminRequire('products.edit');
    $ids = array_map('intval', $_POST['product_ids'] ?? []);
    $action = $_POST['bulk_action'] ?? '';
    if (empty($ids) || !$action) { header('Location: admin.php?p=products'); exit; }

    $db = Db::get();
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $now = Db::now();
    switch ($action) {
        case 'activate':
            $db->prepare("UPDATE products SET status = 'active', updated_at = {$now} WHERE id IN ({$ph})")->execute($ids);
            setFlash('ok', count($ids) . ' produktov aktivovaných.');
            break;
        case 'archive':
            $db->prepare("UPDATE products SET status = 'archived', updated_at = {$now} WHERE id IN ({$ph})")->execute($ids);
            setFlash('ok', count($ids) . ' produktov archivovaných.');
            break;
        case 'delete':
            $db->prepare("DELETE FROM products WHERE id IN ({$ph})")->execute($ids);
            setFlash('ok', count($ids) . ' produktov zmazaných.');
            break;
        case 'price_up_5':
        case 'price_up_10':
        case 'price_up_20':
        case 'price_down_5':
        case 'price_down_10':
        case 'price_down_20':
            $percent = (int)filter_var($action, FILTER_SANITIZE_NUMBER_INT);
            $direction = str_contains($action, 'up') ? 1 : -1;
            $multiplier = round(1 + ($direction * abs($percent) / 100), 4);
            $stmt = $db->prepare("UPDATE products SET price = MAX(0, ROUND(price * :mult)), updated_at = {$now} WHERE id IN ({$ph})");
            $stmt->bindValue(':mult', $multiplier);
            $stmt->execute($ids);
            $sign = $direction > 0 ? '+' : '-';
            setFlash('ok', count($ids) . " produktov: cena {$sign}" . abs($percent) . '%.');
            break;
    }
    header('Location: admin.php?p=products');
    exit;
}
function handleExport(): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="products-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    exportProductsCsv($out, (string)($_POST['q'] ?? ''), (string)($_POST['status'] ?? ''));
    fclose($out);
    exit;
}
/** Products CSV in the Import::csv() format (prices in euros), so an edited export can be imported back. */
function exportProductsCsv($out, string $search = '', string $statusFilter = ''): void {
    $where = "1=1";
    $params = [];
    if ($search) {
        $where .= " AND (name LIKE :q OR sku LIKE :q2)";
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
        $params[':q'] = "%{$escaped}%";
        $params[':q2'] = "%{$escaped}%";
    }
    if ($statusFilter) { $where .= " AND status = :status"; $params[':status'] = $statusFilter; }

    $stmt = Db::get()->prepare("SELECT sku, name, slug, product_type, price, compare_price, stock, status, summary, weight_g,
        stock_low_threshold, stock_critical_threshold FROM products WHERE {$where} ORDER BY id");
    $stmt->execute($params);

    fputcsv($out, ['sku', 'name', 'slug', 'product_type', 'price', 'compare_price', 'stock', 'status', 'summary', 'weight_g', 'stock_low_threshold', 'stock_critical_threshold'], ',', '"', '');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $row['price'] = Import::formatCents((int)$row['price']);
        if ($row['compare_price'] !== null) $row['compare_price'] = Import::formatCents((int)$row['compare_price']);
        fputcsv($out, array_map([Security::class, 'csvCell'], $row), ',', '"', '');
    }
}
function pageAttributes(): void {
    $db = Db::get();
    $attrs = $db->query("
        SELECT ad.*, GROUP_CONCAT(c.name, ', ') as category_names
        FROM attribute_definitions ad
        LEFT JOIN category_attributes ca ON ca.attribute_id = ad.id
        LEFT JOIN categories c ON c.id = ca.category_id
        GROUP BY ad.id
        ORDER BY ad.sort_order, ad.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <h1>Atribúty</h1>
    <p style="margin-bottom:1rem"><a href="admin.php?p=attribute" class="btn btn-primary">Nový atribút</a></p>
    <div class="card">
        <table>
            <tr><th>Názov</th><th>Slug</th><th>Typ</th><th>Jednotka</th><th>Kategórie</th><th>Poradie</th><th></th></tr>
            <?php foreach ($attrs as $a): ?>
            <tr>
                <td><a href="admin.php?p=attribute&id=<?= (int)$a['id'] ?>"><?= esc($a['name']) ?></a></td>
                <td><code><?= esc($a['slug']) ?></code></td>
                <td><?= esc($a['type']) ?></td>
                <td><?= esc($a['unit'] ?: '—') ?></td>
                <td class="muted"><?= esc($a['category_names'] ?: '—') ?></td>
                <td><?= (int)$a['sort_order'] ?></td>
                <td>
                    <a href="admin.php?p=attribute&id=<?= (int)$a['id'] ?>" class="btn btn-sm">Upraviť</a>
                    <form method="post" action="admin.php?p=attributes&action=delete" style="display:inline" data-confirm="Naozaj zmazať?">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="attr_id" value="<?= (int)$a['id'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit">Zmazať</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($attrs)): ?>
            <tr><td colspan="7" style="text-align:center;color:#64748b">Zatiaľ žiadne atribúty.</td></tr>
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

        $cats = $db->prepare("SELECT category_id FROM category_attributes WHERE attribute_id = :aid");
        $cats->execute([':aid' => $id]);
        $assignedCats = $cats->fetchAll(PDO::FETCH_COLUMN);
    }

    $categories = $db->query("SELECT id, name, path FROM categories ORDER BY path")->fetchAll(PDO::FETCH_ASSOC);
    $types = ['enum' => 'Enum (zoznam hodnôt)', 'number' => 'Číslo', 'boolean' => 'Áno/Nie'];
    ?>
    <h1><?= $attr ? 'Upraviť: ' . esc($attr['name']) : 'Nový atribút' ?></h1>
    <div class="card">
        <?php ob_start(); ?>
        <div id="options-section">
            <h3 style="margin:1rem 0 .5rem">Hodnoty (pre enum)</h3>
            <div id="options-list">
                <?php foreach ($options as $i => $opt): ?>
                <div class="form-row" style="display:flex;gap:.5rem;align-items:center">
                    <input name="options[]" value="<?= esc($opt['value']) ?>" style="flex:1" placeholder="Hodnota">
                    <input type="number" name="option_order[]" value="<?= (int)$opt['sort_order'] ?>" style="width:60px" placeholder="Poradie">
                    <button type="button" data-action="remove-parent" class="btn btn-sm btn-danger">x</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" data-action="add-option" class="btn btn-sm" style="margin-top:.5rem">+ Pridať hodnotu</button>
        </div>

        <h3 style="margin:1rem 0 .5rem">Priradiť ku kategóriám</h3>
        <input type="text" id="cat-filter" placeholder="Hľadať kategóriu..." style="width:100%;padding:.4rem .6rem;margin-bottom:.5rem;border:1px solid #d1d5db;border-radius:4px" data-action="filter-cats">
        <div style="margin-bottom:.5rem;font-size:.8rem">
            <a href="#" data-action="toggle-cats" data-value="1" style="margin-right:.5rem">Vybrať všetky viditeľné</a>
            <a href="#" data-action="toggle-cats" data-value="0">Odznačiť všetky</a>
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
            <a href="admin.php?p=attributes" class="btn">Späť</a>
        </div>
    </div>
    <script nonce="<?= Security::cspNonce() ?>">
    function toggleOptions() {
        document.getElementById('options-section').style.display = document.getElementById('field-type').value === 'enum' ? '' : 'none';
    }
    document.getElementById('field-type').addEventListener('change', toggleOptions);
    function addOption() {
        var div = document.createElement('div');
        div.className = 'form-row';
        div.style.cssText = 'display:flex;gap:.5rem;align-items:center';
        div.innerHTML = '<input name="options[]" style="flex:1" placeholder="Hodnota">' +
            '<input type="number" name="option_order[]" style="width:60px" placeholder="Poradie" value="0">' +
            '<button type="button" data-action="remove-parent" class="btn btn-sm btn-danger">×</button>';
        document.getElementById('options-list').appendChild(div);
    }
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
        document.getElementById('cat-count').textContent = checked + ' vybraných / ' + shown + ' zobrazených';
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
        setFlash('err', 'Atribút nebol uložený: ' . AdminForm::firstError());
        header("Location: admin.php?p=attribute" . ($id ? "&id={$id}" : ''));
        exit;
    }
    $name = $form['name'];
    $slug = Security::slug($form['slug']);
    $type = $form['type'] !== '' ? $form['type'] : 'enum';
    $unit = $form['unit'];
    $sortOrder = Security::int($form['sort_order']);

    if (!$slug) {
        setFlash('err', 'Názov a slug sú povinné.');
        header("Location: admin.php?p=attribute" . ($id ? "&id={$id}" : ''));
        exit;
    }

    // Check slug uniqueness
    $check = $db->prepare("SELECT id FROM attribute_definitions WHERE slug = :slug AND id != :id");
    $check->execute([':slug' => $slug, ':id' => $id ?: -1]);
    if ($check->fetch()) {
        setFlash('err', 'Slug "' . esc($slug) . '" už existuje.');
        header("Location: admin.php?p=attribute" . ($id ? "&id={$id}" : ''));
        exit;
    }

    if ($id) {
        $db->prepare("UPDATE attribute_definitions SET name = :name, slug = :slug, type = :type, unit = :unit, sort_order = :sort WHERE id = :id")
           ->execute([':name' => $name, ':slug' => $slug, ':type' => $type, ':unit' => $unit, ':sort' => $sortOrder, ':id' => $id]);
    } else {
        $db->prepare("INSERT INTO attribute_definitions (name, slug, type, unit, sort_order) VALUES (:name, :slug, :type, :unit, :sort)")
           ->execute([':name' => $name, ':slug' => $slug, ':type' => $type, ':unit' => $unit, ':sort' => $sortOrder]);
        $id = (int)$db->lastInsertId();
    }

    // Save enum options
    $db->prepare("DELETE FROM attribute_options WHERE attribute_id = :aid")->execute([':aid' => $id]);
    if ($type === 'enum') {
        $optValues = $_POST['options'] ?? [];
        $optOrders = $_POST['option_order'] ?? [];
        $ins = $db->prepare("INSERT INTO attribute_options (attribute_id, value, sort_order) VALUES (:aid, :val, :sort)");
        foreach ($optValues as $i => $val) {
            $val = trim($val);
            if ($val === '') continue;
            $ins->execute([':aid' => $id, ':val' => $val, ':sort' => (int)($optOrders[$i] ?? 0)]);
        }
    }

    // Save category assignments
    $db->prepare("DELETE FROM category_attributes WHERE attribute_id = :aid")->execute([':aid' => $id]);
    $catIds = array_map('intval', $_POST['category_ids'] ?? []);
    $catIns = $db->prepare("INSERT INTO category_attributes (category_id, attribute_id) VALUES (:cid, :aid)");
    foreach ($catIds as $cid) {
        if ($cid > 0) $catIns->execute([':cid' => $cid, ':aid' => $id]);
    }

    setFlash('ok', 'Atribút uložený.');
    header("Location: admin.php?p=attribute&id={$id}");
    exit;
}
function handleAttributeDelete(): void {
    $id = Security::int($_POST['attr_id'] ?? 0);
    if ($id) {
        $db = Db::get();
        $db->prepare("DELETE FROM attribute_options WHERE attribute_id = :id")->execute([':id' => $id]);
        $db->prepare("DELETE FROM category_attributes WHERE attribute_id = :id")->execute([':id' => $id]);
        $db->prepare("DELETE FROM attribute_definitions WHERE id = :id")->execute([':id' => $id]);
        setFlash('ok', 'Atribút zmazaný.');
    }
    header('Location: admin.php?p=attributes');
    exit;
}

// ============================================================
// Helpers
// ============================================================
