<?php
/** Missing and archived product status presenters; blocks render inside the storefront shell (Layout.php owns header/footer). */
function renderUnavailableProduct(string $slug): array {
    $product = Products::bySlugAnyStatus($slug);
    if (!$product || ($product['status'] ?? '') !== 'archived') {
        http_response_code(404);
        $message = I18n::t('product_not_found');
        return [Template::render('storefront/status-message', ['message' => $message]), Template::pageTitle($message)];
    }

    http_response_code(410);
    $related = Products::curatedRelated((int)$product['id'], 4) ?: Products::related((int)$product['id'], 4);
    return [Template::render('storefront/product-gone', [
        'product' => I18n::product($product),
        'products' => storefrontProductItems($related),
        'home_url' => Template::storeUrl('/'),
        't' => I18n::all(),
    ]), Template::pageTitle(I18n::t('product_discontinued'))];
}
