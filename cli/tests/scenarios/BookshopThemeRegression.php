<?php

// Bookshop demo (#238): the data the bookshop theme reads through the core. Tagged products (a "bestseller" shelf),
// the top values of one specification (an author list), the product attrs on a listing card, and the cart and
// checkout lines naming the chosen variant (two editions of one book are told apart).
echo "\n--- Bookshop theme: tagged shelf, specification values, card attrs, variant lines ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$btFail = static function (string $what, Throwable $e): void {
    assert_true(false, "Bookshop theme {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$btCategory = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
// Fixed ids: an auto-assigned id follows the highest fixed id of earlier scenarios and collides with later ones.
$pdo->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, tags, attrs, created_at) VALUES
    (98821, 'default', {$btCategory}, 'BT-A', 'bt-a', 'simple', 'BT Older pick', 900, 'eur', 3, 'active', 'deny', '[\"bt238-pick\"]', '{\"Author\":\"BT Writer One\"}', '2026-01-01 00:00:00'),
    (98822, 'default', {$btCategory}, 'BT-B', 'bt-b', 'simple', 'BT Newer pick', 900, 'eur', 3, 'active', 'deny', '[\"x\",\"bt238-pick\"]', '{}', '2026-02-01 00:00:00'),
    (98823, 'default', {$btCategory}, 'BT-C', 'bt-c', 'simple', 'BT Longer tag', 900, 'eur', 3, 'active', 'deny', '[\"bt238-pick-extra\"]', '{}', '2026-03-01 00:00:00'),
    (98824, 'default', {$btCategory}, 'BT-D', 'bt-d', 'simple', 'BT Hidden pick', 900, 'eur', 3, 'draft', 'deny', '[\"bt238-pick\"]', '{}', '2026-04-01 00:00:00'),
    (98825, 'bt-other', {$btCategory}, 'BT-E', 'bt-e', 'simple', 'BT Other store pick', 900, 'eur', 3, 'active', 'deny', '[\"bt238-pick\"]', '{}', '2026-05-01 00:00:00')");
$btSpec = $pdo->prepare('INSERT INTO product_specifications (product_id, lang, criteria_name, criteria_value) VALUES (?, ?, ?, ?)');
foreach ([[98821, 'en', 'BT Writer One'], [98822, 'en', 'BT Writer One'], [98823, 'en', 'BT Writer Two'], [98824, 'en', 'BT Writer Two'],
          [98825, 'en', 'BT Writer Two'], [98823, 'de', 'BT Writer Two']] as [$id, $lang, $value]) {
    $btSpec->execute([$id, $lang, 'BT238 Author', $value]);
}

try {
    assert_equals([98822, 98821], array_map(static fn(array $row): int => (int)$row['id'], Products::tagged('bt238-pick')),
        'Bookshop theme: a tag shelf lists active products of this store with exactly that tag, newest first (not a longer tag, a draft or another store)');
    assert_equals([98822], array_map(static fn(array $row): int => (int)$row['id'], Products::tagged('BT238 Pick', 1)),
        'Bookshop theme: the tag is slugged and the limit holds');
    assert_equals([], Products::tagged('%'), 'Bookshop theme: a wildcard tag matches nothing');

    assert_equals([['value' => 'BT Writer One', 'count' => 2], ['value' => 'BT Writer Two', 'count' => 1]],
        Products::specificationValues('BT238 Author', 'en'),
        'Bookshop theme: specification values count active products of this store in one language, most common first');

    StorefrontCoreSectionProviders::register();
    $btShelf = StorefrontSectionRegistry::resolve('core.tagged-products', ['tag' => 'bt238-pick', 'limit' => 4]);
    assert_equals(['BT Newer pick', 'BT Older pick'], array_map(static fn(array $card): string => (string)$card['name'], $btShelf['items'] ?? []),
        'Bookshop theme: the tagged-products section provider returns the shelf as product cards');
    assert_equals('BT Writer One', $btShelf['items'][1]['custom_fields']['Author'] ?? null,
        'Bookshop theme: a product card carries the product attrs (the author under the title)');
    $btAuthors = StorefrontSectionRegistry::resolve('core.specification-values', ['name' => 'BT238 Author']);
    assert_equals(['BT238 Author', 'BT Writer One', 2], [$btAuthors['name'] ?? '', $btAuthors['items'][0]['value'] ?? '', $btAuthors['items'][0]['count'] ?? 0],
        'Bookshop theme: the specification-values provider lists the authors with their book counts');
    assert_true(str_ends_with((string)($btAuthors['items'][0]['url'] ?? ''), '?page=search&q=BT%20Writer%20One'),
        'Bookshop theme: each author links to a storefront search for the author');
    assert_equals([], StorefrontSectionRegistry::resolve('core.specification-values', ['name' => ' '])['items'],
        'Bookshop theme: no specification name, no list');
} catch (Throwable $e) { $btFail('section data', $e); }

if (!function_exists('renderCart')) require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Storefront/CheckoutPages.php';
$btGlobals = ['t' => $GLOBALS['t'] ?? null, 'storefront_currency' => $GLOBALS['storefront_currency'] ?? null];
$GLOBALS['t'] = static fn(string $key): string => $key;
try {
    $pdo->exec("INSERT INTO variants (id, product_id, sku, name, attrs, price_absolute, stock, sort_order) VALUES
        (98831, 98821, 'BT-A-PB', 'Paperback', '{\"format\":\"Paperback\"}', 900, 3, 0)");
    Warehouse::setStock((int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn(), 'BT-A-PB', 3, 'count', 'test', 'bt-opening-pb');
    $_SESSION['cart'] = [];
    Cart::add(98821, 98831, 1);
    $btCartHtml = renderCart();
    assert_true(str_contains($btCartHtml, 'BT Older pick') && str_contains($btCartHtml, 'Paperback'),
        'Bookshop theme: the cart line names the chosen edition');
    $_SESSION['cart'] = [];
} catch (Throwable $e) { $btFail('cart line', $e); }
finally { foreach ($btGlobals as $btKey => $btValue) $GLOBALS[$btKey] = $btValue; }

$pdo->exec('DELETE FROM product_specifications WHERE product_id BETWEEN 98821 AND 98825');
$pdo->exec('DELETE FROM variants WHERE id = 98831');
$pdo->exec('DELETE FROM products WHERE id BETWEEN 98821 AND 98825');
