<?php
/** Catalog data presenters. Storefront markup is owned by the active theme. */
function renderLegalPage(string $page): string {
    $allowed = ['privacy', 'terms', 'cookies'];
    if (!in_array($page, $allowed, true)) return Template::render('storefront/legal-page', ['content_html' => '<p>Not found.</p>']);

    $locale = I18n::locale();
    $tpl = dirname(__DIR__, 3) . '/templates/legal/' . $locale . '/' . $page . '.html';
    if (!is_file($tpl)) $tpl = dirname(__DIR__, 3) . '/templates/legal/en/' . $page . '.html';

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
    foreach ($legal as $key => $value) $vars['{{' . $key . '}}'] = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $vars['{{updated_at}}'] = date('Y-m-d');
    return Template::render('storefront/legal-page', ['content_html' => strtr((string)file_get_contents($tpl), $vars)]);
}

function storefrontBreadcrumb(array $items): string {
    return Template::render('storefront/breadcrumb', ['items' => $items]);
}

function storefrontProductCard(array $product, bool $showStock = false): string {
    global $price;
    $images = is_string($product['images'] ?? null) ? json_decode($product['images'], true) : ($product['images'] ?? []);
    $image = is_array($images) ? (string)($images[0] ?? '') : '';
    return Template::render('storefront/product-card', [
        'id' => (int)$product['id'],
        'url' => '?page=product&slug=' . rawurlencode((string)$product['slug']),
        'name' => (string)$product['name'],
        'image' => $image,
        'on_sale' => !empty($product['compare_price']) && (int)$product['compare_price'] > (int)$product['price'],
        'price' => $price((int)$product['price']),
        'compare_price' => !empty($product['compare_price']) ? $price((int)$product['compare_price']) : '',
        'in_stock' => $showStock && (int)($product['stock'] ?? 0) > 0,
        'in_stock_label' => I18n::t('in_stock'),
        'add_to_cart_label' => I18n::t('add_to_cart'),
    ]);
}

function renderHome(): string {
    global $catName, $t, $lang;
    $managedBanners = ContentService::banners('homepage', $lang);
    $banner = $managedBanners[0] ?? null;
    $roots = Categories::roots();
    $bannerSlug = (string)(($roots[0] ?? [])['slug'] ?? '');
    usort($roots, static fn(array $a, array $b): int => (int)$b['product_count'] <=> (int)$a['product_count']);
    $roots = array_values(array_filter($roots, static fn(array $category): bool => (int)$category['product_count'] >= 5 && $category['slug'] !== 'uncategorized'));
    $categories = array_map(static fn(array $category): array => [
        'url' => '?page=category&slug=' . rawurlencode((string)$category['slug']),
        'name' => $catName((string)$category['name']),
        'count' => number_format((int)$category['product_count']),
    ], $roots);
    $latest = Products::latest(12);

    return Template::render('storefront/home', [
        'hero_image' => (string)($banner['image_url'] ?? '') ?: 'assets/img/slider1.jpg',
        'hero_title' => (string)($banner['title'] ?? '') ?: $t('hero_title'),
        'hero_body' => (string)($banner['body'] ?? ''),
        'hero_url' => (string)($banner['link_url'] ?? '') ?: '?page=category&slug=' . rawurlencode($bannerSlug),
        'hero_label' => (string)($banner['link_label'] ?? '') ?: $t('shop_now'),
        'categories_label' => $t('categories'),
        'categories' => $categories,
        'latest_label' => $t('latest'),
        'latest_html' => $latest ? renderProductGrid($latest, '') : '',
    ]);
}

function renderCategory(array $cat): string {
    global $catName, $t, $lang;
    $catId = (int)$cat['id'];
    $children = Categories::children($catId);
    $selectedSubcats = [];
    if ($children && !empty($_GET['subcat']) && is_array($_GET['subcat'])) $selectedSubcats = array_map('intval', $_GET['subcat']);

    $searchCatIds = Categories::descendantIds($catId);
    if ($selectedSubcats) {
        $searchCatIds = [];
        foreach ($selectedSubcats as $subcatId) $searchCatIds = array_merge($searchCatIds, Categories::descendantIds($subcatId));
        $searchCatIds = array_values(array_unique($searchCatIds));
    }

    $page = max(1, Security::int($_GET['pg'] ?? 1));
    $sort = Security::clean($_GET['sort'] ?? 'price_asc');
    $specFilters = [];
    if (!empty($_GET['spec']) && is_array($_GET['spec'])) {
        foreach ($_GET['spec'] as $name => $value) {
            $name = Security::clean($name);
            $specFilters[$name] = is_array($value) ? array_map([Security::class, 'clean'], $value) : [Security::clean($value)];
        }
    }
    $priceMin = !empty($_GET['pmin']) ? Security::int($_GET['pmin']) * 100 : null;
    $priceMax = !empty($_GET['pmax']) ? Security::int($_GET['pmax']) * 100 : null;
    $rangeFilters = [];
    if (!empty($_GET['rmin']) && is_array($_GET['rmin'])) {
        foreach ($_GET['rmin'] as $name => $value) if ($value !== '') $rangeFilters[Security::clean($name)]['min'] = (float)$value;
    }
    if (!empty($_GET['rmax']) && is_array($_GET['rmax'])) {
        foreach ($_GET['rmax'] as $name => $value) if ($value !== '') $rangeFilters[Security::clean($name)]['max'] = (float)$value;
    }

    $result = TecDoc::parametricSearch($searchCatIds, $page, 24, $sort, $specFilters, $priceMin, $priceMax, $lang, $rangeFilters);
    $sortHidden = '';
    foreach ($selectedSubcats as $id) $sortHidden .= Template::render('storefront/hidden-input', ['name' => 'subcat[]', 'value' => $id]);
    foreach ($specFilters as $name => $values) {
        foreach ($values as $value) $sortHidden .= Template::render('storefront/hidden-input', ['name' => 'spec[' . $name . '][]', 'value' => $value]);
    }
    if ($priceMin) $sortHidden .= Template::render('storefront/hidden-input', ['name' => 'pmin', 'value' => (int)($priceMin / 100)]);
    if ($priceMax) $sortHidden .= Template::render('storefront/hidden-input', ['name' => 'pmax', 'value' => (int)($priceMax / 100)]);
    foreach ($rangeFilters as $name => $range) {
        if (isset($range['min'])) $sortHidden .= Template::render('storefront/hidden-input', ['name' => 'rmin[' . $name . ']', 'value' => $range['min']]);
        if (isset($range['max'])) $sortHidden .= Template::render('storefront/hidden-input', ['name' => 'rmax[' . $name . ']', 'value' => $range['max']]);
    }

    $children = array_values(array_filter($children, static fn(array $child): bool => (int)$child['product_count'] > 0));
    usort($children, static fn(array $a, array $b): int => (int)$b['product_count'] <=> (int)$a['product_count']);
    $subcategories = $children ? Template::render('storefront/category-subcategories', [
        'label' => $t('subcategories'),
        'active' => $selectedSubcats !== [],
        'items' => array_map(static fn(array $child): array => [
            'id' => (int)$child['id'], 'name' => $catName((string)$child['name']), 'count' => (int)$child['product_count'],
            'checked' => in_array((int)$child['id'], $selectedSubcats, true) ? ' checked' : '',
        ], $children),
    ]) : '';

    $filtersHtml = '';
    foreach ($result['filters'] as $name => $values) {
        $allNumeric = count($values) > 5 && !empty($values[0]['numeric']);
        if ($allNumeric) foreach ($values as $value) if ($value['numeric'] === null) { $allNumeric = false; break; }
        $activeValues = $specFilters[$name] ?? [];
        $activeMin = $rangeFilters[$name]['min'] ?? '';
        $activeMax = $rangeFilters[$name]['max'] ?? '';
        $numericValues = $allNumeric ? array_map(static fn(array $value): float => (float)$value['numeric'], $values) : [];
        $unit = '';
        if ($allNumeric && preg_match('/\(([^)]+)\)/', $name, $unitMatch)) $unit = $unitMatch[1];
        $filtersHtml .= Template::render('storefront/category-filter', [
            'name' => $name,
            'active' => $allNumeric ? ($activeMin !== '' || $activeMax !== '') : $activeValues !== [],
            'range' => $allNumeric,
            'min_placeholder' => $numericValues ? min($numericValues) : '',
            'max_placeholder' => $numericValues ? max($numericValues) : '',
            'active_min' => $activeMin,
            'active_max' => $activeMax,
            'unit' => $unit,
            'values' => array_map(static fn(array $value): array => [
                'filter_name' => $name, 'value' => $value['value'], 'count' => $value['count'],
                'checked' => in_array($value['value'], $activeValues, true) ? ' checked' : '',
            ], $values),
        ]);
    }

    $breadcrumb = [['url' => '?', 'label' => $t('home'), 'current' => false]];
    foreach (Categories::breadcrumb($catId) as $item) $breadcrumb[] = ['url' => '?page=category&slug=' . rawurlencode((string)$item['slug']), 'label' => $catName((string)$item['name']), 'current' => false];
    $sortLabels = ['price_asc' => 'price_asc', 'price_desc' => 'price_desc', 'name_asc' => 'name_asc', 'name_desc' => 'name_desc', 'newest' => 'newest'];
    $sortOptions = [];
    foreach ($sortLabels as $value => $labelKey) $sortOptions[] = ['value' => $value, 'label' => $t($labelKey), 'selected' => $sort === $value ? ' selected' : ''];
    $hasActiveFilters = $specFilters !== [] || $priceMin || $priceMax || $selectedSubcats !== [] || $rangeFilters !== [];

    return Template::render('storefront/category-page', [
        'breadcrumb_html' => storefrontBreadcrumb($breadcrumb),
        'name' => $catName((string)$cat['name']), 'slug' => (string)$cat['slug'], 'total' => number_format((int)$result['total']),
        'parts_label' => $t('parts_count'), 'sort_label' => $t('sort'), 'sort' => $sort, 'sort_options' => $sortOptions, 'sort_hidden_html' => $sortHidden,
        'subcategories_html' => $subcategories, 'price_label' => $t('price'), 'from_label' => $t('ph_from'), 'to_label' => $t('ph_to'),
        'price_min' => $priceMin ? (int)($priceMin / 100) : '', 'price_max' => $priceMax ? (int)($priceMax / 100) : '',
        'filters_html' => $filtersHtml, 'filter_label' => $t('filter'), 'has_active_filters' => (bool)$hasActiveFilters,
        'clear_url' => '?page=category&slug=' . rawurlencode((string)$cat['slug']), 'clear_label' => $t('clear_filter'),
        'product_grid_html' => renderProductGrid($result['products'], $t('no_parts_filter'), true),
        'pagination_html' => renderPagination((int)$result['page'], (int)$result['pages'], buildCategoryUrl((string)$cat['slug'], $sort, $specFilters, $priceMin, $priceMax, $selectedSubcats, $rangeFilters)),
    ]);
}

function renderProduct(array $product): string {
    global $catName, $t, $hasTecDoc, $price;
    $specifications = is_array($product['specifications'] ?? null) ? $product['specifications'] : [];
    $oeNumbers = is_array($product['oe_numbers'] ?? null) ? $product['oe_numbers'] : [];
    $vehicleCount = (int)($product['vehicle_count'] ?? 0);
    $showAutomotive = ($hasTecDoc && ($product['product_type'] ?? '') === 'auto_part') || $vehicleCount > 0 || $oeNumbers !== [];
    $breadcrumb = [['url' => '?', 'label' => $t('home'), 'current' => false]];
    foreach (Categories::breadcrumb((int)$product['category_id']) as $item) $breadcrumb[] = ['url' => '?page=category&slug=' . rawurlencode((string)$item['slug']), 'label' => $catName((string)$item['name']), 'current' => false];
    $breadcrumb[] = ['url' => '', 'label' => (string)$product['name'], 'current' => true];

    $attributes = is_array($product['attrs'] ?? null) ? $product['attrs'] : (json_decode((string)($product['attrs'] ?? '{}'), true) ?: []);
    $images = is_array($product['images'] ?? null) ? $product['images'] : (json_decode((string)($product['images'] ?? '[]'), true) ?: []);
    $image = !empty($images[0]) && $images[0] !== 'placeholder.jpg' ? (string)$images[0] : '';
    $oeRows = '';
    $lastBrand = null;
    foreach ($oeNumbers as $oe) {
        $brand = (string)$oe['oem_brand'];
        $oeRows .= Template::render('storefront/product-oe-row', [
            'brand_heading' => $brand !== $lastBrand, 'brand' => $brand, 'number' => (string)$oe['oem_number'],
            'url' => '?page=oe&q=' . rawurlencode((string)$oe['oem_number']),
        ]);
        $lastBrand = $brand;
    }
    $vehicles = $showAutomotive ? TecDoc::vehiclesForProduct((int)$product['id'], 100) : [];
    $vehicleRows = array_map(static fn(array $vehicle): array => [
        'model' => $vehicle['manufacturer'] . ' ' . $vehicle['model'],
        'type' => (string)$vehicle['type_name'],
    ], $vehicles);
    $automotiveHtml = $showAutomotive ? Template::render('storefront/product-automotive', [
        'oe_rows_html' => $oeRows, 'no_oe' => $oeNumbers === [], 'no_oe_label' => $t('no_oe'),
        'vehicle_model_label' => $t('vehicle_manufacturer_model'), 'vehicle_engine_label' => $t('vehicle_engine_type'),
        'vehicles' => $vehicleRows, 'no_vehicles' => $vehicles === [], 'no_vehicles_label' => $t('no_vehicles'),
    ]) : '';

    return Template::render('storefront/product-detail', [
        'breadcrumb_html' => storefrontBreadcrumb($breadcrumb), 'id' => (int)$product['id'], 'image' => $image,
        'name' => (string)$product['name'], 'sku' => (string)$product['sku'], 'supplier' => (string)($attributes['supplier'] ?? ''),
        'supplier_label' => $t('supplier'), 'price' => $price((int)$product['price']),
        'compare_price' => !empty($product['compare_price']) ? $price((int)$product['compare_price']) : '',
        'in_stock' => (int)$product['stock'] > 0, 'stock' => (int)$product['stock'], 'in_stock_label' => $t('in_stock'), 'out_of_stock_label' => $t('out_of_stock'),
        'summary' => (string)($product['summary'] ?? ''), 'show_automotive' => $showAutomotive, 'compatible_with_label' => $t('compatible_with'),
        'vehicle_count' => $vehicleCount, 'vehicles_label' => $t('vehicles'), 'add_to_cart_label' => $t('add_to_cart'),
        'specs_label' => $t('specs'), 'spec_count' => count($specifications), 'oe_label' => $t('oe_numbers'), 'oe_count' => count($oeNumbers), 'vehicles_tab_label' => $t('vehicles_tab'),
        'specifications' => array_map(static fn(array $spec): array => ['name' => (string)$spec['criteria_name'], 'value' => (string)$spec['criteria_value']], $specifications),
        'no_specs' => $specifications === [], 'no_specs_label' => $t('no_specs'), 'automotive_html' => $automotiveHtml,
    ]);
}

function renderVehicle(int $ktype, array $result, array $categories, ?int $activeCatId): string {
    global $catName, $t;
    $categoryHtml = '';
    foreach ($categories as $category) {
        $categoryHtml .= Template::render('storefront/vehicle-category', [
            'url' => '?page=vehicle&ktype=' . $ktype . '&cat=' . (int)$category['id'], 'name' => $catName((string)$category['name']),
            'count' => (int)$category['part_count'], 'active' => $activeCatId === (int)$category['id'],
        ]);
    }
    $baseUrl = '?page=vehicle&ktype=' . $ktype . ($activeCatId ? '&cat=' . $activeCatId : '');
    return Template::render('storefront/vehicle-page', [
        'breadcrumb_html' => storefrontBreadcrumb([['url' => '?', 'label' => $t('home'), 'current' => false], ['url' => '', 'label' => $t('vehicle_parts') . ' (ktype: ' . $ktype . ')', 'current' => true]]),
        'heading' => $t('vehicle_parts'), 'categories_html' => $categoryHtml, 'total' => number_format((int)$result['total']), 'parts_label' => $t('parts_count'),
        'product_grid_html' => renderProductGrid($result['products']), 'pagination_html' => renderPagination((int)$result['page'], (int)$result['pages'], $baseUrl),
    ]);
}

function renderSearch(string $query, array $result): string {
    global $t;
    return Template::render('storefront/search-results', [
        'breadcrumb_html' => storefrontBreadcrumb([['url' => '?', 'label' => $t('home'), 'current' => false], ['url' => '', 'label' => $t('search') . ': ' . $query, 'current' => true]]),
        'heading' => $t('search_results'), 'total' => number_format((int)$result['total']), 'results_label' => $t('results_for'), 'query' => $query,
        'product_grid_html' => renderProductGrid($result['products']), 'pagination_html' => renderPagination((int)$result['page'], (int)$result['pages'], '?page=search&q=' . rawurlencode($query)),
    ]);
}

function renderOE(string $query, array $result): string {
    global $t;
    return Template::render('storefront/search-results', [
        'breadcrumb_html' => storefrontBreadcrumb([['url' => '?', 'label' => $t('home'), 'current' => false], ['url' => '', 'label' => 'OE: ' . $query, 'current' => true]]),
        'heading' => $t('oe_search'), 'total' => number_format((int)$result['total']), 'results_label' => $t('results_for') . ' OE', 'query' => $query,
        'product_grid_html' => renderProductGrid($result['products']), 'pagination_html' => renderPagination((int)$result['page'], (int)$result['pages'], '?page=oe&q=' . rawurlencode($query)),
    ]);
}

function renderProductGrid(array $products, ?string $emptyText = null, bool $showStock = false): string {
    $products = Products::enrichMany($products);
    $cards = '';
    foreach ($products as $product) $cards .= storefrontProductCard($product, $showStock);
    return Template::render('storefront/product-grid', ['cards_html' => $cards, 'empty_text' => $products ? '' : ($emptyText ?? I18n::t('no_results'))]);
}

function renderPagination(int $page, int $pages, string $baseUrl): string {
    if ($pages <= 1) return '';
    $separator = str_contains($baseUrl, '?') ? '&' : '?';
    $links = [];
    if ($page > 1) $links[] = ['url' => $baseUrl . $separator . 'pg=' . ($page - 1), 'label' => '«', 'current' => false];
    for ($number = max(1, $page - 3), $end = min($pages, $page + 3); $number <= $end; $number++) {
        $links[] = ['url' => $baseUrl . $separator . 'pg=' . $number, 'label' => $number, 'current' => $number === $page];
    }
    if ($page < $pages) $links[] = ['url' => $baseUrl . $separator . 'pg=' . ($page + 1), 'label' => '»', 'current' => false];
    return Template::render('storefront/pagination', ['visible' => true, 'links' => $links]);
}

function buildCategoryUrl(string $slug, string $sort, array $specFilters, ?int $pmin, ?int $pmax, array $subcats = [], array $rangeFilters = []): string {
    $url = '?page=category&slug=' . rawurlencode($slug) . '&sort=' . rawurlencode($sort);
    foreach ($subcats as $id) $url .= '&subcat[]=' . (int)$id;
    foreach ($specFilters as $name => $values) foreach ($values as $value) $url .= '&spec[' . rawurlencode($name) . '][]=' . rawurlencode($value);
    if ($pmin) $url .= '&pmin=' . (int)($pmin / 100);
    if ($pmax) $url .= '&pmax=' . (int)($pmax / 100);
    foreach ($rangeFilters as $name => $range) {
        if (isset($range['min'])) $url .= '&rmin[' . rawurlencode($name) . ']=' . rawurlencode((string)$range['min']);
        if (isset($range['max'])) $url .= '&rmax[' . rawurlencode($name) . ']=' . rawurlencode((string)$range['max']);
    }
    return $url;
}
