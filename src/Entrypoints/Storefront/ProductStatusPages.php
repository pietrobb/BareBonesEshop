<?php
/** Missing and archived product status presenters, rendered inside the storefront shell (no second header/footer). */
function renderUnavailableProduct(string $slug): array {
    $product = Products::bySlugAnyStatus($slug);
    if (!$product || ($product['status'] ?? '') !== 'archived') {
        http_response_code(404);
        return [Template::render('storefront/status-message', ['message' => I18n::t('product_not_found')]), Template::pageTitle(I18n::t('product_not_found'))];
    }

    http_response_code(410);
    $related = Products::curatedRelated((int)$product['id'], 4) ?: Products::related((int)$product['id'], 4);
    $product = I18n::product($product);
    return [Template::render('storefront/product-gone', [
        'name' => (string)($product['name'] ?? ''),
        'products' => storefrontProductItems(array_map([I18n::class, 'product'], $related)),
        'home_url' => Template::storeUrl('/'),
        't' => I18n::all(),
    ]), Template::pageTitle(I18n::t('product_discontinued'))];
}
