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

    public static function isKey(string $key): bool
    {
        return preg_match('/^(p|v)_\d{1,10}$/', $key) === 1;
    }

    /**
     * The list of the current shopper in the current store, newest first.
     * @return array<string, array{product_id:int, variant_id:?int, qty:int, saved_price:?int, saved_currency:?string}>
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
        $stmt = Db::get()->prepare("SELECT item_key, product_id, variant_id, qty, saved_price, saved_currency
            FROM saved_for_later_items WHERE customer_id = ? AND store_id = ? ORDER BY id DESC");
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
        if (!self::isKey($cartKey) || !isset($cart[$cartKey])) return ['ok' => false, 'error' => 'cart_error'];
        $lines = self::lines();
        $previous = $lines[$cartKey] ?? null;
        if ($previous === null && count($lines) >= self::MAX_LINES) return ['ok' => false, 'error' => 'saved_for_later_full'];
        $item = $cart[$cartKey];
        $price = null;
        $currency = null;
        foreach (Cart::enriched()['items'] as $priced) {
            if ($priced['key'] !== $cartKey || empty($priced['available'])) continue;
            $price = (int)$priced['price'];
            $currency = (string)$priced['currency'];
        }
        self::put($cartKey, [
            'product_id' => (int)$item['product_id'],
            'variant_id' => !empty($item['variant_id']) ? (int)$item['variant_id'] : null,
            'qty' => min(Cart::MAX_QTY, max(1, (int)$item['qty']) + (int)($previous['qty'] ?? 0)),
            'saved_price' => $price ?? $previous['saved_price'] ?? null,
            'saved_currency' => $currency ?? $previous['saved_currency'] ?? null,
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
        if ($line === null) return ['ok' => false, 'error' => 'cart_error'];
        $result = Cart::addValidated($line['product_id'], $line['variant_id'], $line['qty']);
        if (!$result['ok']) return $result + ['notice' => I18n::t((string)$result['error'])];
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
        } else {
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
            $items[] = $item;
        }
        $listed = array_column($items, 'key');
        foreach (array_keys($lines) as $key) {
            if (!in_array($key, $listed, true)) self::delete($key);
        }
        return $items;
    }

    /** Template data of the "Buy later (N)" section under the cart. */
    public static function view(?array $vatContext, string $cartUrl, string $csrfToken): array
    {
        $items = [];
        foreach (self::priced($vatContext) as $item) {
            $items[] = [
                'key' => (string)$item['key'],
                'url' => Template::storeUrl('/product/' . rawurlencode((string)$item['slug'])),
                'name' => (string)$item['name'], 'variant_name' => (string)($item['variant_name'] ?? ''), 'sku' => (string)$item['sku'],
                'quantity' => (int)$item['qty'], 'price' => (string)$item['price_formatted'],
                'unavailable' => empty($item['available']) ? I18n::t('saved_for_later_unavailable') : '',
                'price_changed' => $item['price_changed']
                    ? I18n::format('saved_for_later_was', ['price' => I18n::price((int)$item['saved_price'], (string)$item['currency'])]) : '',
                'cart_url' => $cartUrl, 'csrf_token' => $csrfToken,
                'quantity_label' => I18n::t('quantity'), 'price_label' => I18n::t('saved_for_later_unit_price'),
                'to_cart_label' => I18n::t('saved_for_later_to_cart'), 'remove_label' => I18n::t('remove'),
            ];
        }
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
        $guest = $_SESSION['saved_for_later'][$store] ?? [];
        unset($_SESSION['saved_for_later'][$store]);
        if ($customerId <= 0 || !is_array($guest) || $guest === []) return 0;
        $account = self::accountLines($customerId);
        $merged = 0;
        foreach ($guest as $key => $line) {
            if (!is_string($key) || !self::isKey($key) || !is_array($line)) continue;
            $line = self::line($line);
            $existing = $account[$key] ?? null;
            if ($existing === null && count($account) >= self::MAX_LINES) break;
            if ($existing !== null) $line['qty'] = max($line['qty'], $existing['qty']);
            self::write($customerId, $key, $line);
            $account[$key] = $line;
            $merged++;
        }
        return $merged;
    }

    private static function customerId(): int
    {
        return max(0, (int)($_SESSION['customer_id'] ?? 0));
    }

    private static function store(): string
    {
        return StoreContext::current()->id();
    }

    /** @return array{product_id:int, variant_id:?int, qty:int, saved_price:?int, saved_currency:?string} */
    private static function line(array $row): array
    {
        $variantId = (int)($row['variant_id'] ?? 0);
        return [
            'product_id' => (int)($row['product_id'] ?? 0),
            'variant_id' => $variantId > 0 ? $variantId : null,
            'qty' => max(1, min(Cart::MAX_QTY, (int)($row['qty'] ?? 1))),
            'saved_price' => isset($row['saved_price']) && $row['saved_price'] !== '' ? (int)$row['saved_price'] : null,
            'saved_currency' => isset($row['saved_currency']) && $row['saved_currency'] !== '' ? strtolower((string)$row['saved_currency']) : null,
        ];
    }

    private static function accountLines(int $customerId): array
    {
        $stmt = Db::get()->prepare("SELECT item_key, product_id, variant_id, qty, saved_price, saved_currency
            FROM saved_for_later_items WHERE customer_id = ? AND store_id = ?");
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
        $update = $db->prepare("UPDATE saved_for_later_items SET product_id = ?, variant_id = ?, qty = ?, saved_price = ?, saved_currency = ?
            WHERE customer_id = ? AND store_id = ? AND item_key = ?");
        $values = [$line['product_id'], $line['variant_id'], $line['qty'], $line['saved_price'], $line['saved_currency']];
        $update->execute([...$values, $customerId, self::store(), $key]);
        $exists = $db->prepare("SELECT 1 FROM saved_for_later_items WHERE customer_id = ? AND store_id = ? AND item_key = ?");
        $exists->execute([$customerId, self::store(), $key]);
        if ($exists->fetchColumn()) return;
        $db->prepare("INSERT INTO saved_for_later_items (customer_id, store_id, item_key, product_id, variant_id, qty, saved_price, saved_currency)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute([$customerId, self::store(), $key, ...$values]);
    }

    private static function delete(string $key): void
    {
        $customerId = self::customerId();
        if ($customerId === 0) {
            unset($_SESSION['saved_for_later'][self::store()][$key]);
            return;
        }
        Db::get()->prepare("DELETE FROM saved_for_later_items WHERE customer_id = ? AND store_id = ? AND item_key = ?")
            ->execute([$customerId, self::store(), $key]);
    }
}
