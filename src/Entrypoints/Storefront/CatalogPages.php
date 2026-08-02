<?php
/** Catalog and discovery page renderers. */
function renderLegalPage(string $page): string {
    $allowed = ['privacy', 'terms', 'cookies'];
    if (!in_array($page, $allowed, true)) {
        return '<p>Not found.</p>';
    }

    $locale = I18n::locale();
    $tpl = dirname(__DIR__, 3) . '/templates/legal/' . $locale . '/' . $page . '.html';
    if (!is_file($tpl)) {
        $tpl = dirname(__DIR__, 3) . '/templates/legal/en/' . $page . '.html';
    }

    $stores = Bootstrap::stores();
    $legal = [];
    foreach ($stores['stores'] ?? [] as $store) {
        if (($store['default_locale'] ?? '') === $locale) {
            $legal = $store['legal'] ?? [];
            break;
        }
    }
    if (!$legal) {
        $defaultStore = $stores['default_store'] ?? 'sk';
        $legal = $stores['stores'][$defaultStore]['legal'] ?? ($stores['stores']['sk']['legal'] ?? []);
    }

    $vars = [];
    foreach ($legal as $k => $v) {
        $vars['{{' . $k . '}}'] = htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
    $vars['{{updated_at}}'] = date('Y-m-d');

    $html = (string)file_get_contents($tpl);
    return '<div class="card legal-page">' . strtr($html, $vars) . '</div>';
}

function renderHome(): string {
    global $e, $price, $catName, $t, $lang;

    $managedBanners = ContentService::banners('homepage', $lang);
    $banner = $managedBanners[0] ?? null;
    $bannerImage = !empty($banner['image_url']) ? $banner['image_url'] : 'assets/img/slider1.jpg';
    $bannerTitle = !empty($banner['title']) ? $banner['title'] : $t('hero_title');
    $bannerLink = !empty($banner['link_url']) ? $banner['link_url'] : ('?page=category&slug=' . ((Categories::roots()[0] ?? [])['slug'] ?? ''));
    $bannerLabel = !empty($banner['link_label']) ? $banner['link_label'] : $t('shop_now');
    ob_start(); ?>
    <div class="hero">
     <img src="<?= $e($bannerImage) ?>" alt="<?= $e($bannerTitle) ?>">
     <div class="hero-overlay">
      <h1><?= $e($bannerTitle) ?></h1>
      <?php if (!empty($banner['body'])): ?><p><?= $e($banner['body']) ?></p><?php endif; ?>
      <a href="<?= $e($bannerLink) ?>" class="btn-hero"><?= $e($bannerLabel) ?></a>
     </div>
    </div>

    <h2 class="section-title" style="margin-bottom:16px;font-size:1.4em"><?= $t('categories') ?></h2>
    <div class="grid grid-3">
    <?php
    $roots = Categories::roots();
    usort($roots, fn($a,$b) => (int)$b['product_count'] - (int)$a['product_count']);
    $roots = array_filter($roots, fn($c) => (int)$c['product_count'] >= 5 && $c['slug'] !== 'uncategorized');
    foreach ($roots as $rc):
    ?>
     <a href="?page=category&slug=<?= $e($rc['slug']) ?>" class="cat-card">
      <span style="flex:1;font-weight:600"><?= $e($catName($rc['name'])) ?></span>
      <span class="count"><?= number_format((int)$rc['product_count']) ?></span>
     </a>
    <?php endforeach; ?>
    </div>

    <?php
    $latest = Products::enrichMany(Products::latest(12));
    if ($latest): ?>
    <h2 class="section-title" style="margin:32px 0 16px;font-size:1.4em"><?= $t('latest') ?></h2>
    <div class="grid grid-4">
    <?php foreach ($latest as $p): ?>
     <div class="card" style="position:relative">
      <a href="?page=product&slug=<?= $e($p['slug']) ?>">
       <?php
       $imgs = is_string($p['images'] ?? null) ? json_decode($p['images'], true) : ($p['images'] ?? []);
       $firstImg = $imgs[0] ?? null;
       if ($firstImg): ?>
       <img class="card-img" src="<?= $e($firstImg) ?>" alt="<?= $e($p['name']) ?>" loading="lazy">
       <?php else: ?>
       <div class="card-img-placeholder">📷</div>
       <?php endif; ?>
       <?php if (!empty($p['compare_price']) && $p['compare_price'] > $p['price']): ?>
       <span class="sticker sticker-sale">SALE</span>
       <?php endif; ?>
       <h3><?= $e($p['name']) ?></h3>
       <div class="price"><?= $price($p['price']) ?>
        <?php if (!empty($p['compare_price'])): ?><span class="old-price"><?= $price($p['compare_price']) ?></span><?php endif; ?>
       </div>
      </a>
      <button class="btn-cart" type="button" data-storefront-action="cart-add" data-product-id="<?= (int)$p['id'] ?>" data-quantity="1"><?= $t('add_to_cart') ?></button>
     </div>
    <?php endforeach; ?>
    </div>
    <?php endif;

    return ob_get_clean();
}

function renderCategory(array $cat): string {
    global $e, $price, $catName, $t, $lang;
    $catId = (int)$cat['id'];

    // Breadcrumb
    $bc = Categories::breadcrumb($catId);
    ob_start();
    echo '<div class="breadcrumb"><a href="?">' . $t('home') . '</a>';
    foreach ($bc as $b) echo ' &rsaquo; <a href="?page=category&slug=' . $e($b['slug']) . '">' . $e($catName($b['name'])) . '</a>';
    echo '</div>';

    // Subcategories — used for sidebar filters on parent categories
    $children = Categories::children($catId);
    $hasChildren = !empty($children);

    // Get all descendant category IDs for product search
    $allCatIds = Categories::descendantIds($catId);

    // Parse subcategory filter from URL
    $selectedSubcats = [];
    if ($hasChildren && !empty($_GET['subcat']) && is_array($_GET['subcat'])) {
        $selectedSubcats = array_map('intval', $_GET['subcat']);
    }

    // If subcategories selected, narrow search to their descendants only
    if (!empty($selectedSubcats)) {
        $searchCatIds = [];
        foreach ($selectedSubcats as $scId) {
            $searchCatIds = array_merge($searchCatIds, Categories::descendantIds($scId));
        }
        $searchCatIds = array_unique($searchCatIds);
    } else {
        $searchCatIds = $allCatIds;
    }

    // Parametric search with filters (works for both parent and leaf categories)
    $pg = max(1, Security::int($_GET['pg'] ?? 1));
    $sort = Security::clean($_GET['sort'] ?? 'price_asc');
    $searchLang = $lang;

    // Parse spec filters from URL
    $specFilters = [];
    if (!empty($_GET['spec']) && is_array($_GET['spec'])) {
        foreach ($_GET['spec'] as $name => $val) {
            $name = Security::clean($name);
            if (is_array($val)) {
                $specFilters[$name] = array_map([Security::class, 'clean'], $val);
            } else {
                $specFilters[$name] = [Security::clean($val)];
            }
        }
    }

    $priceMin = !empty($_GET['pmin']) ? Security::int($_GET['pmin']) * 100 : null;
    $priceMax = !empty($_GET['pmax']) ? Security::int($_GET['pmax']) * 100 : null;

    // Parse numeric range filters: rmin[Výkon (kW)]=3&rmax[Výkon (kW)]=9
    $rangeFilters = [];
    if (!empty($_GET['rmin']) && is_array($_GET['rmin'])) {
        foreach ($_GET['rmin'] as $name => $val) {
            $name = Security::clean($name);
            if ($val !== '') $rangeFilters[$name]['min'] = (float)$val;
        }
    }
    if (!empty($_GET['rmax']) && is_array($_GET['rmax'])) {
        foreach ($_GET['rmax'] as $name => $val) {
            $name = Security::clean($name);
            if ($val !== '') $rangeFilters[$name]['max'] = (float)$val;
        }
    }

    $result = TecDoc::parametricSearch($searchCatIds, $pg, 24, $sort, $specFilters, $priceMin, $priceMax, $searchLang, $rangeFilters);
    $filters = $result['filters'];

    echo '<h1 style="margin-bottom:4px">' . $e($catName($cat['name'])) . '</h1>';
    echo '<div class="sort-bar"><span class="total">' . number_format($result['total']) . ' ' . $t('parts_count') . '</span>';
    echo '<form method="get" style="display:flex;gap:8px;align-items:center">';
    echo '<input type="hidden" name="page" value="category"><input type="hidden" name="slug" value="' . $e($cat['slug']) . '">';
    // Preserve subcategory + spec filters in sort form
    foreach ($selectedSubcats as $scId) echo '<input type="hidden" name="subcat[]" value="' . (int)$scId . '">';
    foreach ($specFilters as $fn => $fvs) {
        foreach ($fvs as $fv) echo '<input type="hidden" name="spec[' . $e($fn) . '][]" value="' . $e($fv) . '">';
    }
    if ($priceMin) echo '<input type="hidden" name="pmin" value="' . (int)($priceMin / 100) . '">';
    if ($priceMax) echo '<input type="hidden" name="pmax" value="' . (int)($priceMax / 100) . '">';
    foreach ($rangeFilters as $fn => $rv) {
        if (isset($rv['min'])) echo '<input type="hidden" name="rmin[' . $e($fn) . ']" value="' . $e((string)$rv['min']) . '">';
        if (isset($rv['max'])) echo '<input type="hidden" name="rmax[' . $e($fn) . ']" value="' . $e((string)$rv['max']) . '">';
    }
    echo '<label>' . $t('sort') . '</label><select name="sort" data-submit-on-change>';
    foreach (['price_asc'=>$t('price_asc'),'price_desc'=>$t('price_desc'),'name_asc'=>$t('name_asc'),'name_desc'=>$t('name_desc'),'newest'=>$t('newest')] as $k=>$v) {
        echo '<option value="' . $k . '"' . ($sort===$k?' selected':'') . '>' . $v . '</option>';
    }
    echo '</select></form></div>';

    echo '<div class="layout-sidebar">';

    // Sidebar filters
    echo '<form class="sidebar" method="get">';
    echo '<input type="hidden" name="page" value="category"><input type="hidden" name="slug" value="' . $e($cat['slug']) . '">';
    echo '<input type="hidden" name="sort" value="' . $e($sort) . '">';

    $hasActiveFilters = !empty($specFilters) || $priceMin || $priceMax || !empty($selectedSubcats) || !empty($rangeFilters);

    // Subcategory filter (only for parent categories)
    if ($hasChildren) {
        $childrenWithProducts = array_filter($children, fn($ch) => (int)$ch['product_count'] > 0);
        if (!empty($childrenWithProducts)) {
            usort($childrenWithProducts, fn($a,$b) => (int)$b['product_count'] - (int)$a['product_count']);
            echo '<h3>' . $t('subcategories');
            if (!empty($selectedSubcats)) echo ' <span style="color:#a82d5c">●</span>';
            echo '</h3>';
            foreach ($childrenWithProducts as $ch) {
                $checked = in_array((int)$ch['id'], $selectedSubcats) ? ' checked' : '';
                echo '<label><input type="checkbox" name="subcat[]" value="' . (int)$ch['id'] . '"' . $checked . '> ';
                echo $e($catName($ch['name'])) . ' <span class="cnt">(' . (int)$ch['product_count'] . ')</span></label>';
            }
        }
    }

    // Price range
    echo '<h3>' . $t('price') . '</h3>';
    echo '<div style="display:flex;gap:4px;align-items:center">';
    $pminDisplay = $priceMin ? (int)($priceMin / 100) : '';
    $pmaxDisplay = $priceMax ? (int)($priceMax / 100) : '';
    echo '<input type="number" name="pmin" placeholder="' . $t('ph_from') . '" value="' . $pminDisplay . '" style="width:80px;padding:4px;border:1px solid #ddd;border-radius:3px;font-size:.85em">';
    echo '<span>—</span>';
    echo '<input type="number" name="pmax" placeholder="' . $t('ph_to') . '" value="' . $pmaxDisplay . '" style="width:80px;padding:4px;border:1px solid #ddd;border-radius:3px;font-size:.85em">';
    echo '<span style="font-size:.85em;color:#888">€</span></div>';

    foreach ($filters as $critName => $values) {
        $activeVals = $specFilters[$critName] ?? [];
        // Detect numeric filter: all values have numeric_val and there are many distinct values
        $allNumeric = count($values) > 5 && !empty($values[0]['numeric']);
        if ($allNumeric) {
            foreach ($values as $v) { if ($v['numeric'] === null) { $allNumeric = false; break; } }
        }

        echo '<h3>' . $e($critName);
        $rangeKey = 'range_' . md5($critName);
        $activeMin = $specFilters[$critName . '__min'] ?? ($_GET['rmin'][$critName] ?? '');
        $activeMax = $specFilters[$critName . '__max'] ?? ($_GET['rmax'][$critName] ?? '');
        if ($allNumeric && ($activeMin !== '' || $activeMax !== '')) echo ' <span style="color:#a82d5c">●</span>';
        elseif (!$allNumeric && !empty($activeVals)) echo ' <span style="color:#a82d5c">●</span>';
        echo '</h3>';

        if ($allNumeric) {
            // Range filter (min-max inputs) for numeric specs like kW
            $numVals = array_map(fn($v) => (float)$v['numeric'], $values);
            $minVal = min($numVals);
            $maxVal = max($numVals);
            $unit = '';
            if (preg_match('/\(([^)]+)\)/', $critName, $um)) $unit = $um[1];
            echo '<div style="display:flex;gap:4px;align-items:center">';
            echo '<input type="number" name="rmin[' . $e($critName) . ']" placeholder="' . $minVal . '" value="' . $e((string)$activeMin) . '" step="0.1" style="width:70px;padding:4px;border:1px solid #ddd;border-radius:3px;font-size:.85em">';
            echo '<span>—</span>';
            echo '<input type="number" name="rmax[' . $e($critName) . ']" placeholder="' . $maxVal . '" value="' . $e((string)$activeMax) . '" step="0.1" style="width:70px;padding:4px;border:1px solid #ddd;border-radius:3px;font-size:.85em">';
            if ($unit) echo '<span style="font-size:.85em;color:#888">' . $e($unit) . '</span>';
            echo '</div>';
        } else {
            // Checkbox filter for discrete values
            foreach ($values as $v) {
                $checked = in_array($v['value'], $activeVals) ? ' checked' : '';
                echo '<label><input type="checkbox" name="spec[' . $e($critName) . '][]" value="' . $e($v['value']) . '"' . $checked . '> ';
                echo $e($v['value']) . ' <span class="cnt">(' . $v['count'] . ')</span></label>';
            }
        }
    }

    echo '<div class="filter-actions">';
    echo '<button type="submit" class="btn-apply">' . $t('filter') . '</button>';
    if ($hasActiveFilters) echo '<a href="?page=category&slug=' . $e($cat['slug']) . '" class="btn-clear">' . $t('clear_filter') . '</a>';
    echo '</div></form>';

    // Product listing
    echo '<div>';
    if (empty($result['products'])) {
        echo '<p style="padding:40px;text-align:center;color:#888">' . $t('no_parts_filter') . '</p>';
    } else {
        echo '<div class="grid grid-4">';
        foreach (Products::enrichMany($result['products']) as $p) {
            $imgs = is_string($p['images'] ?? null) ? json_decode($p['images'], true) : ($p['images'] ?? []);
            $firstImg = $imgs[0] ?? null;
            echo '<div class="card" style="position:relative">';
            echo '<a href="?page=product&slug=' . $e($p['slug']) . '">';
            if ($firstImg) {
                echo '<img class="card-img" src="' . $e($firstImg) . '" alt="' . $e($p['name']) . '" loading="lazy">';
            } else {
                echo '<div class="card-img-placeholder">📷</div>';
            }
            if (!empty($p['compare_price']) && $p['compare_price'] > $p['price']) {
                echo '<span class="sticker sticker-sale">SALE</span>';
            }
            echo '<h3>' . $e($p['name']) . '</h3>';
            echo '<div class="price">' . I18n::price($p['price'], 'eur');
            if (!empty($p['compare_price'])) echo '<span class="old-price">' . I18n::price($p['compare_price'], 'eur') . '</span>';
            echo '</div>';
            if ($p['stock'] > 0) echo '<div class="badge">' . $t('in_stock') . '</div>';
            echo '</a>';
            echo '<button class="btn-cart" type="button" data-storefront-action="cart-add" data-product-id="' . (int)$p['id'] . '" data-quantity="1">' . $t('add_to_cart') . '</button>';
            echo '</div>';
        }
        echo '</div>';
        echo renderPagination($result['page'], $result['pages'], buildCategoryUrl($cat['slug'], $sort, $specFilters, $priceMin, $priceMax, $selectedSubcats, $rangeFilters));
    }
    echo '</div></div>';
    return ob_get_clean();
}

function renderProduct(array $p): string {
    global $e, $price, $catName, $t, $lang;
    $catId = (int)$p['category_id'];
    $bc = Categories::breadcrumb($catId);

    ob_start();
    echo '<div class="breadcrumb"><a href="?">' . $t('home') . '</a>';
    foreach ($bc as $b) echo ' &rsaquo; <a href="?page=category&slug=' . $e($b['slug']) . '">' . $e($catName($b['name'])) . '</a>';
    echo ' &rsaquo; ' . $e($p['name']) . '</div>';

    $attrs = is_array($p['attrs']) ? $p['attrs'] : json_decode($p['attrs'] ?? '{}', true);
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:32px;margin-bottom:24px">';
    echo '<div>';
    if (!empty($p['images']) && !empty($p['images'][0]) && $p['images'][0] !== 'placeholder.jpg') {
        echo '<img src="' . $e($p['images'][0]) . '" alt="" style="max-width:100%;border-radius:8px">';
    } else {
        echo '<div style="background:#eee;height:300px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#aaa;font-size:3em">📦</div>';
    }
    echo '</div><div>';
    echo '<h1 style="margin-bottom:8px">' . $e($p['name']) . '</h1>';
    echo '<p style="color:#888;margin-bottom:4px">SKU: ' . $e($p['sku']) . '</p>';
    if (!empty($attrs['supplier'])) echo '<p style="color:#888;margin-bottom:12px">' . $t('supplier') . ': <strong>' . $e($attrs['supplier']) . '</strong></p>';
    echo '<div class="price" style="font-size:1.6em;margin-bottom:12px">' . $price($p['price']);
    if (!empty($p['compare_price'])) echo '<span class="old-price" style="font-size:.6em">' . $price($p['compare_price']) . '</span>';
    echo '</div>';
    echo $p['stock'] > 0 ? '<div class="badge" style="font-size:.9em;margin-bottom:12px">✓ ' . $t('in_stock') . ' (' . $p['stock'] . ' ks)</div>'
                         : '<div style="color:#c62828;font-size:.9em;margin-bottom:12px">✗ ' . $t('out_of_stock') . '</div>';
    if (!empty($p['summary'])) echo '<p style="margin-top:12px;color:#555">' . $e($p['summary']) . '</p>';
    echo '<p style="margin-top:8px;color:#888;font-size:.85em">' . $t('compatible_with') . ' ' . $p['vehicle_count'] . ' ' . $t('vehicles') . '</p>';
    // Add to cart
    echo '<div style="margin-top:20px;display:flex;gap:8px;align-items:center">';
    echo '<input type="number" value="1" min="1" max="99" class="qty-input" id="qty-' . (int)$p['id'] . '">';
    echo '<button class="btn-cart btn-cart-lg" type="button" data-storefront-action="cart-add" data-product-id="' . (int)$p['id'] . '" data-quantity-input="qty-' . (int)$p['id'] . '">' . $t('add_to_cart') . '</button>';
    echo '</div>';
    echo '</div></div>';

    // Tabs
    echo '<div class="tabs" role="tablist">';
    echo '<button type="button" class="tab active" role="tab" aria-selected="true" data-product-tab="specs">' . $t('specs') . ' (' . count($p['specifications']) . ')</button>';
    echo '<button type="button" class="tab" role="tab" aria-selected="false" data-product-tab="oe">' . $t('oe_numbers') . ' (' . count($p['oe_numbers']) . ')</button>';
    echo '<button type="button" class="tab" role="tab" aria-selected="false" data-product-tab="vehicles">' . $t('vehicles_tab') . ' (' . $p['vehicle_count'] . ')</button>';
    echo '</div>';

    // Specs tab
    echo '<div class="tab-content active" id="tab-specs"><table class="spec-table">';
    foreach ($p['specifications'] as $s) {
        echo '<tr><td>' . $e($s['criteria_name']) . '</td><td><strong>' . $e($s['criteria_value']) . '</strong></td></tr>';
    }
    if (empty($p['specifications'])) echo '<tr><td colspan="2" style="color:#888">' . $t('no_specs') . '</td></tr>';
    echo '</table></div>';

    // OE tab
    echo '<div class="tab-content" id="tab-oe"><table class="spec-table">';
    $lastBrand = '';
    foreach ($p['oe_numbers'] as $oe) {
        if ($oe['oem_brand'] !== $lastBrand) {
            $lastBrand = $oe['oem_brand'];
            echo '<tr><td colspan="2" style="font-weight:700;background:#f0f0f0;padding:8px 12px">' . $e($lastBrand) . '</td></tr>';
        }
        echo '<tr><td></td><td><a href="?page=oe&q=' . urlencode($oe['oem_number']) . '">' . $e($oe['oem_number']) . '</a></td></tr>';
    }
    if (empty($p['oe_numbers'])) echo '<tr><td colspan="2" style="color:#888">' . $t('no_oe') . '</td></tr>';
    echo '</table></div>';

    // Vehicles tab
    $vehicles = TecDoc::vehiclesForProduct((int)$p['id'], 100);
    echo '<div class="tab-content" id="tab-vehicles"><table class="spec-table">';
    $vehHeader = $lang === 'en' ? 'Manufacturer / Model' : ($lang === 'de' ? 'Hersteller / Modell' : 'Výrobca / Model');
    $engHeader = $lang === 'en' ? 'Engine Type' : ($lang === 'de' ? 'Motortyp' : 'Typ motora');
    echo '<tr style="background:#f0f0f0;font-weight:600"><td>' . $vehHeader . '</td><td>' . $engHeader . '</td></tr>';
    foreach ($vehicles as $v) {
        echo '<tr><td>' . $e($v['manufacturer']) . ' ' . $e($v['model']) . '</td><td>' . $e($v['type_name']) . '</td></tr>';
    }
    if (empty($vehicles)) echo '<tr><td colspan="2" style="color:#888">' . $t('no_vehicles') . '</td></tr>';
    echo '</table></div>';

    return ob_get_clean();
}

function renderVehicle(int $ktype, array $result, array $cats, ?int $activeCatId): string {
    global $e, $price, $catName, $t;
    ob_start();
    echo '<div class="breadcrumb"><a href="?">' . $t('home') . '</a> &rsaquo; ' . $t('vehicle_parts') . ' (ktype: ' . $ktype . ')</div>';
    echo '<h1 style="margin-bottom:16px">' . $t('vehicle_parts') . '</h1>';

    if (!empty($cats)) {
        echo '<div class="grid grid-3" style="margin-bottom:24px">';
        foreach ($cats as $c) {
            $active = $activeCatId === (int)$c['id'] ? 'border:2px solid #a82d5c;' : '';
            echo '<a href="?page=vehicle&ktype=' . $ktype . '&cat=' . (int)$c['id'] . '" class="cat-card" style="' . $active . '">';
            echo '<span style="flex:1">' . $e($catName($c['name'])) . '</span><span class="count">' . (int)$c['part_count'] . '</span></a>';
        }
        echo '</div>';
    }

    echo '<div class="sort-bar"><span class="total">' . number_format($result['total']) . ' ' . $t('parts_count') . '</span></div>';
    echo '<div class="grid grid-4">';
    foreach (Products::enrichMany($result['products']) as $p) {
        $imgs = is_string($p['images'] ?? null) ? json_decode($p['images'], true) : ($p['images'] ?? []);
        $firstImg = $imgs[0] ?? null;
        echo '<div class="card" style="position:relative">';
        echo '<a href="?page=product&slug=' . $e($p['slug']) . '">';
        if ($firstImg) {
            echo '<img class="card-img" src="' . $e($firstImg) . '" alt="' . $e($p['name']) . '" loading="lazy">';
        } else {
            echo '<div class="card-img-placeholder">📷</div>';
        }
        echo '<h3>' . $e($p['name']) . '</h3>';
        echo '<div class="price">' . $price($p['price']) . '</div>';
        echo '</a>';
        echo '<button class="btn-cart" type="button" data-storefront-action="cart-add" data-product-id="' . (int)$p['id'] . '" data-quantity="1">' . $t('add_to_cart') . '</button>';
        echo '</div>';
    }
    echo '</div>';
    $baseUrl = '?page=vehicle&ktype=' . $ktype . ($activeCatId ? '&cat=' . $activeCatId : '');
    echo renderPagination($result['page'], $result['pages'], $baseUrl);
    return ob_get_clean();
}

function renderSearch(string $q, array $result): string {
    global $e, $price, $t;
    ob_start();
    echo '<div class="breadcrumb"><a href="?">' . $t('home') . '</a> &rsaquo; ' . $t('search') . ': <strong>' . $e($q) . '</strong></div>';
    echo '<h1 style="margin-bottom:4px">' . $t('search_results') . '</h1>';
    echo '<div class="sort-bar"><span class="total">' . number_format($result['total']) . ' ' . $t('results_for') . ' "' . $e($q) . '"</span></div>';
    echo renderProductGrid($result['products']);
    echo renderPagination($result['page'], $result['pages'], '?page=search&q=' . urlencode($q));
    return ob_get_clean();
}

function renderOE(string $q, array $result): string {
    global $e, $price, $t;
    ob_start();
    echo '<div class="breadcrumb"><a href="?">' . $t('home') . '</a> &rsaquo; OE: <strong>' . $e($q) . '</strong></div>';
    echo '<h1 style="margin-bottom:4px">' . $t('oe_search') . '</h1>';
    echo '<div class="sort-bar"><span class="total">' . number_format($result['total']) . ' ' . $t('results_for') . ' OE "' . $e($q) . '"</span></div>';
    echo renderProductGrid($result['products']);
    echo renderPagination($result['page'], $result['pages'], '?page=oe&q=' . urlencode($q));
    return ob_get_clean();
}

function renderProductGrid(array $products): string {
    global $e, $price, $t;
    $products = Products::enrichMany($products);
    ob_start();
    echo '<div class="grid grid-4">';
    foreach ($products as $p) {
        $imgs = is_string($p['images'] ?? null) ? json_decode($p['images'], true) : ($p['images'] ?? []);
        $firstImg = $imgs[0] ?? null;
        echo '<div class="card" style="position:relative">';
        echo '<a href="?page=product&slug=' . $e($p['slug']) . '">';
        if ($firstImg) {
            echo '<img class="card-img" src="' . $e($firstImg) . '" alt="' . $e($p['name']) . '" loading="lazy">';
        } else {
            echo '<div class="card-img-placeholder">📷</div>';
        }
        if (!empty($p['compare_price']) && $p['compare_price'] > $p['price']) {
            echo '<span class="sticker sticker-sale">SALE</span>';
        }
        echo '<h3>' . $e($p['name']) . '</h3>';
        echo '<div class="price">' . $price($p['price']);
        if (!empty($p['compare_price'])) echo '<span class="old-price">' . $price($p['compare_price']) . '</span>';
        echo '</div>';
        echo '</a>';
        echo '<button class="btn-cart" type="button" data-storefront-action="cart-add" data-product-id="' . (int)$p['id'] . '" data-quantity="1">' . $t('add_to_cart') . '</button>';
        echo '</div>';
    }
    if (empty($products)) echo '<p style="padding:40px;text-align:center;color:#888;grid-column:1/-1">' . $t('no_results') . '</p>';
    echo '</div>';
    return ob_get_clean();
}

function renderPagination(int $page, int $pages, string $baseUrl): string {
    if ($pages <= 1) return '';
    $sep = str_contains($baseUrl, '?') ? '&' : '?';
    $html = '<div class="pagination">';
    if ($page > 1) $html .= '<a href="' . $baseUrl . $sep . 'pg=' . ($page-1) . '">&laquo;</a>';
    $start = max(1, $page - 3);
    $end = min($pages, $page + 3);
    for ($i = $start; $i <= $end; $i++) {
        $html .= $i === $page
            ? '<span class="current">' . $i . '</span>'
            : '<a href="' . $baseUrl . $sep . 'pg=' . $i . '">' . $i . '</a>';
    }
    if ($page < $pages) $html .= '<a href="' . $baseUrl . $sep . 'pg=' . ($page+1) . '">&raquo;</a>';
    $html .= '</div>';
    return $html;
}

function buildCategoryUrl(string $slug, string $sort, array $specFilters, ?int $pmin, ?int $pmax, array $subcats = [], array $rangeFilters = []): string {
    $url = '?page=category&slug=' . urlencode($slug) . '&sort=' . urlencode($sort);
    foreach ($subcats as $scId) $url .= '&subcat[]=' . (int)$scId;
    foreach ($specFilters as $fn => $fvs) {
        foreach ($fvs as $fv) $url .= '&spec[' . urlencode($fn) . '][]=' . urlencode($fv);
    }
    if ($pmin) $url .= '&pmin=' . (int)($pmin / 100);
    if ($pmax) $url .= '&pmax=' . (int)($pmax / 100);
    foreach ($rangeFilters as $fn => $rv) {
        if (isset($rv['min'])) $url .= '&rmin[' . urlencode($fn) . ']=' . urlencode((string)$rv['min']);
        if (isset($rv['max'])) $url .= '&rmax[' . urlencode($fn) . ']=' . urlencode((string)$rv['max']);
    }
    return $url;
}

