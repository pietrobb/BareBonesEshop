<?php
/** Catalog data presenters. Storefront markup is owned by the active theme. */
function renderLegalPage(string $page): string {
    if (!in_array($page, ['privacy', 'terms', 'cookies'], true)) return Template::render('storefront/status-message', ['message' => I18n::t('product_not_found')]);
    $locale = I18n::locale();
    $tpl = dirname(__DIR__, 3) . '/templates/legal/' . $locale . '/' . $page . '.html';
    if (!is_file($tpl)) $tpl = dirname(__DIR__, 3) . '/templates/legal/en/' . $page . '.html';
    $legal = StoreContext::current()->legal();
    $vars = [];
    foreach ($legal as $key => $value) $vars['{{' . $key . '}}'] = esc((string)$value);
    $vars['{{updated_at}}'] = date('Y-m-d');
    return Template::render('storefront/legal-page', ['content_html' => strtr((string)file_get_contents($tpl), $vars)]);
}

function renderHome(): string {
    $model = StorefrontViewModel::home();
    $GLOBALS['storefront_view_model'] = $model;
    return Template::page((string)$model['page']['template'], $model);
}

function renderProduct(array $product): string {
    $model = StorefrontViewModel::product($product);
    $GLOBALS['storefront_view_model'] = $model;
    return Template::page((string)$model['page']['template'], $model);
}

function renderCategory(array $cat): string {
    global $catName, $t, $lang;
    $catId = (int)$cat['id'];
    $selected = !empty($_GET['subcat']) && is_array($_GET['subcat']) ? array_map('intval', $_GET['subcat']) : [];
    $ids = Categories::descendantIds($catId);
    if ($selected) {
        $ids = [];
        foreach ($selected as $id) $ids = array_merge($ids, Categories::descendantIds($id));
        $ids = array_values(array_unique($ids));
    }
    $page = max(1, Security::int($_GET['pg'] ?? 1));
    $sort = Security::clean($_GET['sort'] ?? 'price_asc');
    $spec = [];
    foreach ((array)($_GET['spec'] ?? []) as $name => $values) $spec[Security::clean($name)] = array_map([Security::class, 'clean'], (array)$values);
    $pmin = !empty($_GET['pmin']) ? Security::int($_GET['pmin']) * 100 : null;
    $pmax = !empty($_GET['pmax']) ? Security::int($_GET['pmax']) * 100 : null;
    $ranges = [];
    foreach (['rmin' => 'min', 'rmax' => 'max'] as $input => $edge) foreach ((array)($_GET[$input] ?? []) as $name => $value) if ($value !== '') $ranges[Security::clean($name)][$edge] = (float)$value;
    $result = TecDoc::parametricSearch($ids, $page, 24, $sort, $spec, $pmin, $pmax, $lang, $ranges);
    $children = array_values(array_filter(Categories::children($catId), static fn(array $row): bool => (int)$row['product_count'] > 0));
    $filters = [];
    foreach ($result['filters'] as $name => $values) {
        $numeric = count($values) > 5 && !in_array(null, array_column($values, 'numeric'), true);
        $numbers = $numeric ? array_map('floatval', array_column($values, 'numeric')) : [];
        $filters[] = [
            'name' => $name, 'range' => $numeric, 'min' => $ranges[$name]['min'] ?? '', 'max' => $ranges[$name]['max'] ?? '',
            'min_placeholder' => $numbers ? min($numbers) : '', 'max_placeholder' => $numbers ? max($numbers) : '',
            'values' => array_map(static fn(array $value): array => $value + ['checked' => in_array($value['value'], $spec[$name] ?? [], true)], $values),
        ];
    }
    $breadcrumbs = [['url' => Template::storeUrl('/'), 'label' => $t('home'), 'current' => false]];
    foreach (Categories::breadcrumb($catId) as $item) $breadcrumbs[] = ['url' => Template::storeUrl('/?page=category&slug=' . rawurlencode((string)$item['slug'])), 'label' => $catName((string)$item['name']), 'current' => false];
    return Template::render('storefront/category-page', [
        'breadcrumbs' => $breadcrumbs, 'name' => $catName((string)$cat['name']), 'slug' => (string)$cat['slug'], 'total' => (int)$result['total'],
        'sort' => $sort, 'sort_options' => array_map(static fn(string $value): array => ['value' => $value, 'label' => $t($value), 'selected' => $sort === $value], ['price_asc','price_desc','name_asc','name_desc','newest']),
        'subcategories' => array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'name' => $catName((string)$row['name']), 'count' => (int)$row['product_count'], 'checked' => in_array((int)$row['id'], $selected, true)], $children),
        'filters' => $filters, 'price_min' => $pmin ? $pmin / 100 : '', 'price_max' => $pmax ? $pmax / 100 : '',
        'clear_url' => Template::storeUrl('/?page=category&slug=' . rawurlencode((string)$cat['slug'])),
        'products' => storefrontProductItems($result['products']), 'pagination' => storefrontPagination((int)$result['page'], (int)$result['pages'], buildCategoryUrl((string)$cat['slug'], $sort, $spec, $pmin, $pmax, $selected, $ranges)),
        't' => I18n::all(),
    ]);
}

function renderSearch(string $query, array $result): string { return renderSearchPage($query, $result, I18n::t('search_results'), '?page=search&q=' . rawurlencode($query)); }
function renderOE(string $query, array $result): string { return renderSearchPage($query, $result, I18n::t('oe_search'), '?page=oe&q=' . rawurlencode($query)); }
function renderSearchPage(string $query, array $result, string $heading, string $base): string {
    return Template::render('storefront/search-results', [
        'breadcrumbs' => [['url'=>Template::storeUrl('/'),'label'=>I18n::t('home'),'current'=>false],['url'=>'','label'=>$heading . ': ' . $query,'current'=>true]],
        'heading'=>$heading, 'total'=>(int)$result['total'], 'query'=>$query, 'products'=>storefrontProductItems($result['products']),
        'pagination'=>storefrontPagination((int)$result['page'], (int)$result['pages'], $base), 't'=>I18n::all(),
    ]);
}

function renderVehicle(int $ktype, array $result, array $categories, ?int $activeCatId): string {
    return Template::render('storefront/vehicle-page', [
        'heading'=>I18n::t('vehicle_parts'), 'ktype'=>$ktype, 'total'=>(int)$result['total'],
        'categories'=>array_map(static fn(array $row): array => ['url'=>Template::storeUrl('/?page=vehicle&ktype='.$ktype.'&cat='.(int)$row['id']),'name'=>(string)$row['name'],'count'=>(int)$row['part_count'],'active'=>$activeCatId===(int)$row['id']], $categories),
        'products'=>storefrontProductItems($result['products']), 'pagination'=>storefrontPagination((int)$result['page'], (int)$result['pages'], '?page=vehicle&ktype='.$ktype), 't'=>I18n::all(),
    ]);
}

function storefrontProductItems(array $products): array { return array_map([StorefrontViewModel::class, 'productCard'], Products::enrichMany($products)); }
function storefrontPagination(int $page, int $pages, string $base): array {
    if ($pages <= 1) return [];
    $separator = str_contains($base, '?') ? '&' : '?'; $links = [];
    for ($number=max(1,$page-3),$end=min($pages,$page+3); $number<=$end; $number++) $links[]=['url'=>Template::storeUrl($base.$separator.'pg='.$number),'label'=>$number,'current'=>$number===$page];
    return $links;
}
function buildCategoryUrl(string $slug, string $sort, array $spec, ?int $pmin, ?int $pmax, array $subcats=[], array $ranges=[]): string {
    $url='?page=category&slug='.rawurlencode($slug).'&sort='.rawurlencode($sort);
    foreach($subcats as $id)$url.='&subcat[]='.(int)$id;
    foreach($spec as $name=>$values)foreach($values as $value)$url.='&spec['.rawurlencode($name).'][]='.rawurlencode($value);
    if($pmin)$url.='&pmin='.(int)($pmin/100); if($pmax)$url.='&pmax='.(int)($pmax/100);
    foreach($ranges as $name=>$range)foreach($range as $edge=>$value)$url.='&r'.$edge.'['.rawurlencode($name).']='.rawurlencode((string)$value);
    return $url;
}
