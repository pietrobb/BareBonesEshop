<?php
/** Missing and archived product status presenters. */
function renderUnavailableProduct(string $slug): array {
    $product = Products::bySlugAnyStatus($slug);
    if (!$product || ($product['status'] ?? '') !== 'archived') {
        http_response_code(404);
        return [Template::page('404', ['t' => I18n::all()]), I18n::t('product_not_found') . ' — BareBones Shop'];
    }

    http_response_code(410);
    $related = Products::curatedRelated((int)$product['id'], 4) ?: Products::related((int)$product['id'], 4);
    return [Template::page('product-gone', [
        'product' => I18n::product($product),
        'related' => array_map(static fn(array $item): array => Products::enrich(I18n::product($item)), $related),
        't' => I18n::all(),
    ]), I18n::t('product_discontinued') . ' — BareBones Shop'];
}
