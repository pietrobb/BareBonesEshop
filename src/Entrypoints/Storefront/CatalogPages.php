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
    // The date the text last changed: the store's legal.updated_at, else the template's own
    // <!-- legal-updated: YYYY-MM-DD --> marker, else the template file's mtime — never "today".
    $html = (string)file_get_contents($tpl);
    $updated = (string)($legal['updated_at'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $updated)) $updated = preg_match('/<!--\s*legal-updated:\s*(\d{4}-\d{2}-\d{2})\s*-->/', $html, $m) ? $m[1] : date('Y-m-d', (int)filemtime($tpl));
    $vars['{{updated_at}}'] = esc($updated);
    return Template::render('storefront/legal-page', ['content_html' => strtr($html, $vars)]);
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
    $ids = Categories::descendantIds($catId);
    // subcat[] may only narrow to real descendants of this category (bounded: one descendant lookup each).
    $selected = !empty($_GET['subcat']) && is_array($_GET['subcat'])
        ? array_slice(array_values(array_intersect(array_unique(array_map('intval', array_filter($_GET['subcat'], 'is_scalar'))), array_map('intval', $ids))), 0, 50)
        : [];
    if ($selected) {
        $narrowed = [];
        foreach ($selected as $id) $narrowed = array_merge($narrowed, Categories::descendantIds($id));
        $ids = array_values(array_unique($narrowed));
    }
    $page = max(1, min(Search::MAX_PAGE, Security::int($_GET['pg'] ?? 1)));
    $sort = Security::clean($_GET['sort'] ?? 'price_asc');
    // Only scalar values pass here; Products::parametricSearch() keeps the category's own facets, capped (max_input_vars bounds the loop).
    $spec = [];
    foreach (is_array($_GET['spec'] ?? null) ? $_GET['spec'] : [] as $name => $values) {
        $spec[Security::clean((string)$name)] = array_map([Security::class, 'clean'], array_slice(array_filter((array)$values, 'is_scalar'), 0, Products::MAX_FILTER_VALUES));
    }
    $money = static fn(string $key): ?int => !empty($_GET[$key]) && is_scalar($_GET[$key]) ? max(0, min(Search::MAX_PRICE_FILTER, Security::int($_GET[$key]))) * 100 : null;
    $pmin = $money('pmin');
    $pmax = $money('pmax');
    $ranges = [];
    foreach (['rmin' => 'min', 'rmax' => 'max'] as $input => $edge) {
        foreach (is_array($_GET[$input] ?? null) ? $_GET[$input] : [] as $name => $value) {
            if (is_scalar($value) && is_numeric($value)) $ranges[Security::clean((string)$name)][$edge] = (float)$value;
        }
    }
    $result = Products::parametricSearch($ids, $page, 24, $sort, $spec, $pmin, $pmax, $lang, $ranges);
    $spec = $result['applied_spec'];
    $ranges = $result['applied_ranges'];
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

/** $filters comes from Search::filtersFromQuery(). */
function renderSearch(string $query, array $result, array $filters): string {
    $base = '?page=search&q=' . rawurlencode($query) . ($filters['sort'] !== 'relevance' ? '&sort=' . rawurlencode($filters['sort']) : '')
        . ($filters['pmin'] !== null ? '&pmin=' . $filters['pmin'] : '') . ($filters['pmax'] !== null ? '&pmax=' . $filters['pmax'] : '') . ($filters['cat'] > 0 ? '&cat=' . $filters['cat'] : '');
    global $catName;
    $name = is_callable($catName ?? null) ? $catName : static fn(string $value): string => $value;
    return renderSearchPage($query, $result, I18n::t('search_results'), $base, [
        'show_filters' => true, 'sort' => $filters['sort'], 'price_min' => $filters['pmin'] ?? '', 'price_max' => $filters['pmax'] ?? '', 'cat' => $filters['cat'] ?: '',
        'sort_options' => array_map(static fn(string $value): array => ['value' => $value, 'label' => I18n::t($value), 'selected' => $filters['sort'] === $value], $filters['sorts']),
        'category_options' => array_map(static fn(array $row): array => ['id' => $row['id'], 'name' => $name($row['name']), 'count' => $row['count'], 'checked' => $filters['cat'] === $row['id']], (array)($result['categories'] ?? [])),
        'all_categories_checked' => $filters['cat'] === 0,
        'clear_url' => Template::storeUrl('/?page=search&q=' . rawurlencode($query)),
    ]);
}
function renderSearchPage(string $query, array $result, string $heading, string $base, array $filterView = []): string {
    return Template::render('storefront/search-results', [
        'breadcrumbs' => [['url'=>Template::storeUrl('/'),'label'=>I18n::t('home'),'current'=>false],['url'=>'','label'=>$heading . ': ' . $query,'current'=>true]],
        'heading'=>$heading, 'total'=>(int)$result['total'], 'query'=>$query, 'products'=>storefrontProductItems($result['products']),
        'pagination'=>storefrontPagination((int)$result['page'], (int)$result['pages'], $base), 't'=>I18n::all(),
    ] + $filterView + ['show_filters' => false]);
}

function storefrontProductItems(array $products): array { return array_values(StorefrontViewModel::productCards($products)); }
function storefrontPagination(int $page, int $pages, string $base): array {
    if ($pages <= 1) return [];
    $separator = str_contains($base, '?') ? '&' : '?'; $links = [];
    for ($number=max(1,$page-3),$end=min($pages,$page+3); $number<=$end; $number++) $links[]=['url'=>Template::storeUrl($base.$separator.'pg='.$number),'label'=>$number,'current'=>$number===$page];
    return $links;
}
function buildCategoryUrl(string $slug, string $sort, array $spec, ?int $pmin, ?int $pmax, array $subcats=[], array $ranges=[]): string {
    // The default order adds no sort= (robots.txt disallows sort URLs; pagination must stay crawlable).
    $url='?page=category&slug='.rawurlencode($slug).($sort!=='price_asc'?'&sort='.rawurlencode($sort):'');
    foreach($subcats as $id)$url.='&subcat[]='.(int)$id;
    foreach($spec as $name=>$values)foreach($values as $value)$url.='&spec['.rawurlencode($name).'][]='.rawurlencode($value);
    if($pmin)$url.='&pmin='.(int)($pmin/100); if($pmax)$url.='&pmax='.(int)($pmax/100);
    foreach($ranges as $name=>$range)foreach($range as $edge=>$value)$url.='&r'.$edge.'['.rawurlencode($name).']='.rawurlencode((string)$value);
    return $url;
}
