<?php
/**
 * BareBones Shop — "Buy later" (save for later, as Amazon's "Saved for later").
 *
 * Cart lines the shopper set aside, with their variant and quantity, kept per store. Unlike the wishlist (favourite
 * products saved from the product page) this is a parked part of the cart. Saved lines are not in the cart: no
 * total, item count, shipping, coupon or stock reservation sees them. Moving a line back goes through
 * Cart::addValidated, so today's catalog decides price, stock and variants; the unit price seen when the line was
 * saved only tells the shopper that it has changed.
 *
 * A guest's list lives in the session; a signed-in customer's in saved_for_later_items. The guest list of the store
 * is folded into the account on login (Customers::login), the same line once.
 */
final class SavedForLater
{
    /** Most lines one list holds, as Cart::MAX_LINES for the cart. */
    public const MAX_LINES = 100;

    private static bool $tableReady = false;
    private static bool $skuColumn = false;

    public static function isKey(string $key): bool
    {
        return preg_match('/^(p|v)_\d{1,10}$/', $key) === 1;
    }

    /**
     * The list of the current shopper in the current store, newest first.
     * @return array<string, array{product_id:int, variant_id:?int, qty:int, saved_price:?int, saved_currency:?string, variant_sku:?string}>
     */
    public static function lines(): array
    {
        $customerId = self::customerId();
        if ($customerId === 0) {
            $stored = $_SESSION['saved_for_later'][self::store()] ?? [];
            $lines = [];
            foreach (is_array($stored) ? array_reverse($stored, true) : [] as $key => $line) {
                if (is_string($key) && self::isKey($key) && is_array($line)) $lines[$key] = self::line($line);
            }
            return $lines;
        }
        // R29: code deployed before its migration showed a signed-in customer's cart as a 500; the list is empty until then.
        if (!self::tableReady()) return [];
        // R30: a guest list kept at a sign-in before the migration (mergeGuest()) joins the account once the table is there.
        if (!empty($_SESSION['saved_for_later'][self::store()])) self::mergeGuest($customerId);
        $stmt = Db::get()->prepare("SELECT * FROM saved_for_later_items WHERE customer_id = ? AND store_id = ? ORDER BY id DESC");
        $stmt->execute([$customerId, self::store()]);
        $lines = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $lines[(string)$row['item_key']] = self::line($row);
        return $lines;
    }

    public static function count(): int
    {
        return count(self::lines());
    }

    /** Move a cart line (product, variant, quantity) into the list. A line saved before gets the quantities added. */
    public static function saveFromCart(string $cartKey): array
    {
        $cart = Cart::get();
        // R29: a refusal says why (the no-JS cart showed an empty notice).
        $refused = static fn(string $error): array => ['ok' => false, 'error' => $error, 'notice' => I18n::t($error)];
        if (!self::isKey($cartKey) || !isset($cart[$cartKey])) return $refused('saved_for_later_error');
        if (self::customerId() > 0 && !self::tableReady()) return $refused('saved_for_later_error');
        $lines = self::lines();
        $previous = $lines[$cartKey] ?? null;
        if ($previous === null && count($lines) >= self::MAX_LINES) return $refused('saved_for_later_full');
        $item = $cart[$cartKey];
        $price = null;
        $currency = null;
        $variantSku = null;
        foreach (Cart::enriched()['items'] as $priced) {
            if ($priced['key'] !== $cartKey) continue;
            if (!empty($item['variant_id'])) $variantSku = (string)$priced['sku'];
            if (empty($priced['available'])) continue;
            $price = (int)$priced['price'];
            $currency = (string)$priced['currency'];
        }
        self::put($cartKey, [
            'product_id' => (int)$item['product_id'],
            'variant_id' => !empty($item['variant_id']) ? (int)$item['variant_id'] : null,
            'qty' => min(Cart::MAX_QTY, max(1, (int)$item['qty']) + (int)($previous['qty'] ?? 0)),
            'saved_price' => $price ?? $previous['saved_price'] ?? null,
            'saved_currency' => $currency ?? $previous['saved_currency'] ?? null,
            'variant_sku' => $variantSku ?? $previous['variant_sku'] ?? null,
        ]);
        Cart::update($cartKey, 0);
        return ['ok' => true, 'error' => null, 'notice' => I18n::t('saved_for_later_saved')];
    }

    /**
     * Move a saved line back into the cart at today's price and stock. A line that cannot be bought now
     * (sold out, variant gone, full cart) stays saved and the shopper is told why.
     */
    public static function moveToCart(string $key): array
    {
        $line = self::isKey($key) ? (self::lines()[$key] ?? null) : null;
        // R30: a failed move said the item "could not be saved for later".
        if ($line === null) return ['ok' => false, 'error' => 'saved_for_later_move_error', 'notice' => I18n::t('saved_for_later_move_error')];
        $check = self::catalogCheck([$key => $line]);
        if ($check['gone'] !== []) {
            self::delete($key);
            return ['ok' => false, 'error' => 'saved_for_later_gone', 'notice' => I18n::t('saved_for_later_gone')];
        }
        if ($check['rekey'] !== []) self::toProductLine($key, $line);
        // R29: a product saved without a variant that has variants now: Cart::addValidated() refused it ("variant
        // required") and the line could never go back. The shopper picks one on the product page (view() links it).
        if ($check['rekey'] !== [] || ($line['variant_id'] === null && !empty($check['has_variants'][$line['product_id']]))) {
            return ['ok' => false, 'error' => 'saved_for_later_choose_variant', 'notice' => I18n::t('saved_for_later_choose_variant')];
        }
        $before = (int)(Cart::get()[$key]['qty'] ?? 0);
        $result = Cart::addValidated($line['product_id'], $line['variant_id'], $line['qty']);
        if (!$result['ok']) return $result + ['notice' => I18n::t((string)$result['error'])];
        // R29: with less in stock than saved, the rest stays saved (was: deleted with the line).
        $moved = max(0, (int)$result['quantity'] - $before);
        $left = $line['qty'] - $moved;
        if (!empty($result['clamped']) && $left > 0) {
            // The cart line holds what it shows, so the part kept here is not in both once the stock is back.
            Cart::update($key, (int)$result['quantity']);
            self::put($key, ['qty' => $left] + $line);
            // R30: with the cart already holding all there is in stock, nothing moved (was: "0 moved to your cart").
            if ($moved === 0) {
                return ['ok' => false, 'error' => 'saved_for_later_not_moved'] + $result
                    + ['notice' => I18n::format('saved_for_later_not_moved', ['count' => (int)$result['quantity']])];
            }
            return $result + ['notice' => I18n::format('saved_for_later_partly_moved', ['moved' => $moved, 'left' => $left])];
        }
        self::delete($key);
        $notices = [I18n::t('saved_for_later_moved')];
        $item = $result['item'] ?? null;
        if ($item !== null && $line['saved_price'] !== null && $line['saved_currency'] === (string)$item['currency']
            && $line['saved_price'] !== (int)$item['price']) {
            $notices[] = I18n::format('saved_for_later_price_changed', [
                'old' => I18n::price($line['saved_price'], $line['saved_currency']),
                'new' => I18n::price((int)$item['price'], (string)$item['currency']),
            ]);
        }
        if (!empty($result['clamped'])) $notices[] = I18n::format('cart_quantity_clamped', ['count' => (int)$result['quantity']]);
        return $result + ['notice' => implode(' ', $notices)];
    }

    public static function remove(string $key): array
    {
        if (self::isKey($key)) self::delete($key);
        return ['ok' => true, 'error' => null];
    }

    public static function clear(): array
    {
        $customerId = self::customerId();
        if ($customerId === 0) {
            unset($_SESSION['saved_for_later'][self::store()]);
        } elseif (self::tableReady()) {
            Db::get()->prepare("DELETE FROM saved_for_later_items WHERE customer_id = ? AND store_id = ?")->execute([$customerId, self::store()]);
        }
        return ['ok' => true, 'error' => null];
    }

    /**
     * The list priced like the cart (today's price, availability; $vatContext as checkout prices the cart).
     * Lines whose product or variant is gone from the store's catalog are dropped, as the cart drops them.
     * @return list<array<string, mixed>> enriched cart lines + saved_price / price_changed
     */
    public static function priced(?array $vatContext = null): array
    {
        $lines = self::lines();
        if ($lines === []) return [];
        // R29: only a line that is gone is deleted; one of a product hidden for now (a draft) is not listed and stays saved.
        $check = self::catalogCheck($lines);
        foreach ($check['gone'] as $key) {
            self::delete($key);
            unset($lines[$key]);
        }
        if ($check['rekey'] !== []) {
            foreach ($check['rekey'] as $key) self::toProductLine($key, $lines[$key]);
            return self::priced($vatContext);
        }
        if ($lines === []) return [];
        $base = Cart::enrichLines($lines);
        $shown = $vatContext !== null ? Cart::applyVat($base, $vatContext) : $base;
        $items = [];
        foreach ($shown['items'] as $index => $item) {
            $line = $lines[$item['key']];
            $catalogPrice = (int)$base['items'][$index]['price'];
            $item['qty'] = $line['qty'];
            $item['saved_price'] = $line['saved_price'];
            $item['price_changed'] = $line['saved_price'] !== null && !empty($item['available'])
                && $line['saved_currency'] === (string)$item['currency'] && $line['saved_price'] !== $catalogPrice;
            $item['choose_variant'] = $line['variant_id'] === null && !empty($check['has_variants'][$line['product_id']]);
            $items[] = $item;
        }
        return $items;
    }

    /** Template data of the "Buy later (N)" section under the cart. */
    public static function view(?array $vatContext, string $cartUrl, string $csrfToken): array
    {
        $items = [];
        $priced = self::priced($vatContext);
        $lines = self::lines();
        $shown = array_column($priced, 'key');
        // R30: a line of a product hidden for now (a draft, archived) is listed as unavailable and can be removed. It
        // counts towards MAX_LINES, so the shopper saw 90 lines of a "full" list and could only clear it all.
        $hidden = self::describe(array_diff_key($lines, array_flip($shown)));
        foreach ($hidden as $key => $item) {
            $items[$key] = [
                'key' => $key, 'url' => '', 'name' => $item['name'], 'variant_name' => $item['variant_name'], 'sku' => $item['sku'],
                'quantity' => $lines[$key]['qty'], 'price' => '', 'unavailable' => I18n::t('saved_for_later_unavailable'),
                'can_move' => false, 'choose_variant' => '', 'price_changed' => '',
                'cart_url' => $cartUrl, 'csrf_token' => $csrfToken,
                'quantity_label' => I18n::t('quantity'), 'price_label' => I18n::t('saved_for_later_unit_price'),
                'to_cart_label' => I18n::t('saved_for_later_to_cart'), 'remove_label' => I18n::t('remove'),
            ];
        }
        foreach ($priced as $item) {
            $items[(string)$item['key']] = [
                'key' => (string)$item['key'],
                'url' => Template::storeUrl('/product/' . rawurlencode((string)$item['slug'])),
                'name' => (string)$item['name'], 'variant_name' => (string)($item['variant_name'] ?? ''), 'sku' => (string)$item['sku'],
                'quantity' => (int)$item['qty'], 'price' => (string)$item['price_formatted'],
                'unavailable' => empty($item['available']) ? I18n::t('saved_for_later_unavailable') : '',
                // R29: a sold-out line's button is disabled; a product that has variants now links to its page instead.
                'can_move' => !empty($item['available']) && !$item['choose_variant'],
                'choose_variant' => $item['choose_variant'] ? I18n::t('saved_for_later_choose_variant_link') : '',
                'price_changed' => $item['price_changed']
                    ? I18n::format('saved_for_later_was', ['price' => I18n::price((int)$item['saved_price'], (string)$item['currency'])]) : '',
                'cart_url' => $cartUrl, 'csrf_token' => $csrfToken,
                'quantity_label' => I18n::t('quantity'), 'price_label' => I18n::t('saved_for_later_unit_price'),
                'to_cart_label' => I18n::t('saved_for_later_to_cart'), 'remove_label' => I18n::t('remove'),
            ];
        }
        $items = array_values(array_intersect_key(array_replace($lines, $items), $items));
        return [
            'items' => $items,
            'title' => I18n::format('saved_for_later_title', ['count' => count($items)]),
            'note' => I18n::t('saved_for_later_note'),
            'clear_label' => I18n::t('saved_for_later_clear'),
            'cart_url' => $cartUrl, 'csrf_token' => $csrfToken,
        ];
    }

    /** On login: the guest list of this store joins the account's list (a line saved in both keeps the larger quantity). */
    public static function mergeGuest(int $customerId): int
    {
        $store = self::store();
        // R30: code deployed before its migration dropped the guest list at sign-in; it stays in the session until then (lines()).
        if ($customerId > 0 && !self::tableReady()) return 0;
        $guest = $_SESSION['saved_for_later'][$store] ?? [];
        unset($_SESSION['saved_for_later'][$store]);
        if ($customerId <= 0 || !is_array($guest) || $guest === []) return 0;
        $account = self::accountLines($customerId);
        $merged = 0;
        $left = [];
        foreach ($guest as $key => $line) {
            if (!is_string($key) || !self::isKey($key) || !is_array($line)) continue;
            $line = self::line($line);
            $existing = $account[$key] ?? null;
            if ($existing === null && count($account) >= self::MAX_LINES) {
                $left[$key] = $line;
                continue;
            }
            if ($existing !== null) {
                $line['qty'] = max($line['qty'], $existing['qty']);
                $line['variant_sku'] ??= $existing['variant_sku'];
            }
            self::write($customerId, $key, $line);
            $account[$key] = $line;
            $merged++;
        }
        // R29: the guest lines a full account list cannot take were dropped without a word.
        // R30: ... and which ones they are.
        if ($left !== []) {
            $names = array_map(static fn(array $item): string => $item['name'] . ($item['variant_name'] !== '' ? ' (' . $item['variant_name'] . ')' : ''),
                self::describe($left));
            StorefrontFlash::set(I18n::format('saved_for_later_merge_full', ['count' => count($left), 'max' => self::MAX_LINES])
                . ($names !== [] ? ' ' . I18n::format('saved_for_later_merge_left', ['items' => implode(', ', $names)]) : ''));
        }
        return $merged;
    }

    /**
     * R30: an admin changed a variant's SKU: the saved lines of the variant keep it (catalogCheck() compares the SKU,
     * the line was deleted from every account's list). A guest's line in a session becomes its product's (toProductLine()).
     */
    public static function variantSkuChanged(int $variantId, string $oldSku, string $newSku): void
    {
        if ($oldSku === $newSku || !self::tableReady() || !self::skuColumn()) return;
        Db::get()->prepare("UPDATE saved_for_later_items SET variant_sku = ? WHERE variant_id = ? AND variant_sku = ?")
            ->execute([$newSku, $variantId, $oldSku]);
    }

    /**
     * Name, variant name and SKU of lines by key, whatever the product's status (a draft is not in Cart::enrichLines()).
     * @param array<string, array> $lines
     * @return array<string, array{name:string, variant_name:string, sku:string}>
     */
    private static function describe(array $lines): array
    {
        if ($lines === []) return [];
        $db = Db::get();
        $productIds = array_values(array_unique(array_column($lines, 'product_id')));
        $stmt = $db->prepare('SELECT id, name, slug, sku, translations FROM products WHERE store_id = ? AND id IN ('
            . implode(',', array_fill(0, count($productIds), '?')) . ')');
        $stmt->execute([StoreContext::current()->catalogId(), ...$productIds]);
        $products = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $products[(int)$row['id']] = I18n::product($row);
        $variantIds = array_values(array_filter(array_column($lines, 'variant_id')));
        $variants = [];
        if ($variantIds !== []) {
            $stmt = $db->prepare('SELECT id, name, sku FROM variants WHERE id IN (' . implode(',', array_fill(0, count($variantIds), '?')) . ')');
            $stmt->execute($variantIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $variants[(int)$row['id']] = $row;
        }
        $described = [];
        foreach ($lines as $key => $line) {
            $product = $products[$line['product_id']] ?? null;
            if ($product === null) continue;
            $variant = $line['variant_id'] !== null ? ($variants[$line['variant_id']] ?? null) : null;
            $described[(string)$key] = [
                'name' => (string)$product['name'],
                'variant_name' => (string)($variant['name'] ?? ''),
                'sku' => (string)($variant['sku'] ?? $product['sku'] ?? ''),
            ];
        }
        return $described;
    }

    /**
     * R30: a variant line whose variant now has another SKU (an admin renamed it, or SQLite gave a deleted variant's id to
     * a new one) becomes a line of its product: the shopper picks the variant on the product page. Was: deleted for good.
     */
    private static function toProductLine(string $key, array $line): void
    {
        $productKey = 'p_' . $line['product_id'];
        $existing = self::lines()[$productKey] ?? null;
        self::delete($key);
        self::put($productKey, [
            'product_id' => $line['product_id'], 'variant_id' => null,
            'qty' => max($line['qty'], (int)($existing['qty'] ?? 0)),
            'saved_price' => null, 'saved_currency' => null, 'variant_sku' => null,
        ]);
    }

    private static function customerId(): int
    {
        return max(0, (int)($_SESSION['customer_id'] ?? 0));
    }

    private static function store(): string
    {
        return StoreContext::current()->id();
    }

    /** @return array{product_id:int, variant_id:?int, qty:int, saved_price:?int, saved_currency:?string, variant_sku:?string} */
    private static function line(array $row): array
    {
        $variantId = (int)($row['variant_id'] ?? 0);
        return [
            'product_id' => (int)($row['product_id'] ?? 0),
            'variant_id' => $variantId > 0 ? $variantId : null,
            'qty' => max(1, min(Cart::MAX_QTY, (int)($row['qty'] ?? 1))),
            'saved_price' => isset($row['saved_price']) && $row['saved_price'] !== '' ? (int)$row['saved_price'] : null,
            'saved_currency' => isset($row['saved_currency']) && $row['saved_currency'] !== '' ? strtolower((string)$row['saved_currency']) : null,
            'variant_sku' => $variantId > 0 && isset($row['variant_sku']) && $row['variant_sku'] !== '' ? (string)$row['variant_sku'] : null,
        ];
    }

    /** Whether saved_for_later_items exists (migration 20261110190000 ran); only a yes is remembered. */
    private static function tableReady(): bool
    {
        return self::$tableReady = self::$tableReady || Db::tableExists('saved_for_later_items');
    }

    /** Whether saved_for_later_items.variant_sku exists (migration 20261111100000 ran); only a yes is remembered. */
    private static function skuColumn(): bool
    {
        return self::$skuColumn = self::$skuColumn || Db::hasColumn('saved_for_later_items', 'variant_sku');
    }

    /**
     * R29: which saved lines are gone for good, by key. A product of another store or deleted, a variant deleted or (SQLite
     * reuses the id of the last deleted row) an id now naming another variant than the one saved: was mapped onto it.
     * A product only hidden for now (a draft, inactive) is not gone: the line was deleted from every list for good.
     * @param array<string, array> $lines
     * @return array{gone: list<string>, rekey: list<string>, has_variants: array<int, bool>}
     */
    private static function catalogCheck(array $lines): array
    {
        $productIds = array_values(array_unique(array_column($lines, 'product_id')));
        $db = Db::get();
        $catalog = StoreContext::current()->catalogId();
        $products = [];
        $stmt = $db->prepare('SELECT id FROM products WHERE store_id = ? AND id IN (' . implode(',', array_fill(0, count($productIds), '?')) . ')');
        $stmt->execute([$catalog, ...$productIds]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $products[(int)$id] = true;
        $variants = [];
        $hasVariants = [];
        $stmt = $db->prepare('SELECT id, product_id, sku FROM variants WHERE product_id IN (' . implode(',', array_fill(0, count($productIds), '?')) . ')');
        $stmt->execute($productIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $variants[(int)$row['id']] = $row;
            $hasVariants[(int)$row['product_id']] = true;
        }
        $gone = [];
        $rekey = [];
        foreach ($lines as $key => $line) {
            $variant = $line['variant_id'] !== null ? ($variants[$line['variant_id']] ?? null) : null;
            if (!isset($products[$line['product_id']])
                || ($line['variant_id'] !== null && ($variant === null || (int)$variant['product_id'] !== $line['product_id']))) {
                $gone[] = (string)$key;
            } elseif ($variant !== null && $line['variant_sku'] !== null && (string)$variant['sku'] !== $line['variant_sku']) {
                $rekey[] = (string)$key;
            }
        }
        return ['gone' => $gone, 'rekey' => $rekey, 'has_variants' => $hasVariants];
    }

    private static function accountLines(int $customerId): array
    {
        $stmt = Db::get()->prepare("SELECT * FROM saved_for_later_items WHERE customer_id = ? AND store_id = ?");
        $stmt->execute([$customerId, self::store()]);
        $lines = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $lines[(string)$row['item_key']] = self::line($row);
        return $lines;
    }

    /** Store a line as the newest of the list. */
    private static function put(string $key, array $line): void
    {
        $customerId = self::customerId();
        if ($customerId === 0) {
            $store = self::store();
            unset($_SESSION['saved_for_later'][$store][$key]);
            $_SESSION['saved_for_later'][$store][$key] = self::line($line);
            return;
        }
        self::delete($key);
        self::write($customerId, $key, self::line($line));
    }

    private static function write(int $customerId, string $key, array $line): void
    {
        $db = Db::get();
        $columns = ['product_id', 'variant_id', 'qty', 'saved_price', 'saved_currency'];
        $values = [$line['product_id'], $line['variant_id'], $line['qty'], $line['saved_price'], $line['saved_currency']];
        if (self::skuColumn()) {
            $columns[] = 'variant_sku';
            $values[] = $line['variant_sku'] ?? null;
        }
        $update = $db->prepare('UPDATE saved_for_later_items SET ' . implode(' = ?, ', $columns) . ' = ? WHERE customer_id = ? AND store_id = ? AND item_key = ?');
        $update->execute([...$values, $customerId, self::store(), $key]);
        $exists = $db->prepare("SELECT 1 FROM saved_for_later_items WHERE customer_id = ? AND store_id = ? AND item_key = ?");
        $exists->execute([$customerId, self::store(), $key]);
        if ($exists->fetchColumn()) return;
        $db->prepare('INSERT INTO saved_for_later_items (customer_id, store_id, item_key, ' . implode(', ', $columns) . ')
            VALUES (?, ?, ?' . str_repeat(', ?', count($columns)) . ')')->execute([$customerId, self::store(), $key, ...$values]);
    }

    private static function delete(string $key): void
    {
        $customerId = self::customerId();
        if ($customerId === 0) {
            unset($_SESSION['saved_for_later'][self::store()][$key]);
            return;
        }
        if (!self::tableReady()) return;
        Db::get()->prepare("DELETE FROM saved_for_later_items WHERE customer_id = ? AND store_id = ? AND item_key = ?")
            ->execute([$customerId, self::store(), $key]);
    }
}
