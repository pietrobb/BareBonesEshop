<?php
/**
 * Product price history for the EU Omnibus rule (98/6/EC art. 6a): an announced price reduction shows as the
 * prior price the lowest price the shop charged in the 30 days before the reduction.
 *
 * Every write of products.price calls record(). Each history row stores, besides the new price, the lowest
 * price in force during the 30 days before it (lowest_prior), so the storefront needs only the latest row of
 * each product (latestMany(), one query per listing) to cap the struck-through price (capCompare()).
 */
final class PriceHistory {
    public const WINDOW_DAYS = 30;

    /** Append the current price of each product whose last recorded price differs (or that has none). */
    public static function record(array $productIds, ?PDO $db = null): void {
        $db ??= Db::get();
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) return;
        $now = gmdate('Y-m-d H:i:s');
        $windowStart = gmdate('Y-m-d H:i:s', time() - self::WINDOW_DAYS * 86400);
        $last = $db->prepare('SELECT price FROM product_price_history WHERE product_id = :id ORDER BY changed_at DESC, id DESC LIMIT 1');
        // Prices in force during [now − 30 days, now): rows that started in the window plus the one in force at its start.
        $lowest = $db->prepare('SELECT MIN(price) FROM (
                SELECT price FROM product_price_history WHERE product_id = :id AND changed_at >= :since
                UNION ALL
                SELECT price FROM (SELECT price FROM product_price_history WHERE product_id = :id2 AND changed_at < :since2
                    ORDER BY changed_at DESC, id DESC LIMIT 1) before_window
            ) in_force');
        $insert = $db->prepare('INSERT INTO product_price_history (product_id, price, lowest_prior, changed_at) VALUES (:id, :price, :lowest, :now)');
        foreach (array_chunk($ids, 500) as $chunk) {
            $prices = $db->query('SELECT id, price FROM products WHERE price IS NOT NULL AND id IN (' . implode(',', $chunk) . ')')->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($prices as $id => $price) {
                $last->execute([':id' => $id]);
                $previous = $last->fetchColumn();
                $last->closeCursor();
                if ($previous !== false && (int)$previous === (int)$price) continue;
                $lowest->execute([':id' => $id, ':since' => $windowStart, ':id2' => $id, ':since2' => $windowStart]);
                $prior = $lowest->fetchColumn();
                $lowest->closeCursor();
                $insert->execute([':id' => $id, ':price' => (int)$price, ':lowest' => $prior === false || $prior === null ? null : (int)$prior, ':now' => $now]);
            }
        }
    }

    /**
     * Latest history row of each product. A store whose database has no history table (yet) gets no rows,
     * and products without rows show no compare price (capCompare()).
     * @param list<int> $productIds
     * @return array<int, array{price: int, lowest_prior: ?int, changed_at: string}>
     */
    public static function latestMany(array $productIds): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn(int $id): bool => $id > 0)));
        $result = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            try {
                $rows = Db::get()->query('SELECT h.product_id, h.price, h.lowest_prior, h.changed_at FROM product_price_history h
                    WHERE h.id IN (SELECT MAX(id) FROM product_price_history WHERE product_id IN (' . implode(',', $chunk) . ') GROUP BY product_id)')
                    ->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException) {
                return [];
            }
            foreach ($rows as $row) {
                $result[(int)$row['product_id']] = [
                    'price' => (int)$row['price'],
                    'lowest_prior' => $row['lowest_prior'] === null ? null : (int)$row['lowest_prior'],
                    'changed_at' => (string)$row['changed_at'],
                ];
            }
        }
        return $result;
    }

    /**
     * The struck-through price a product may show: at most the lowest price of the 30 days before the reduction.
     * $basePrice is products.price before price lists and promotions; $product['price'] is what the customer pays.
     * - A price list or promotion below the base price reduces it now: the prior price is the base price, or a
     *   lower price in force in the last 30 days.
     * - Otherwise the reduction is the last change of the base price: the prior price is the lowest of the 30 days
     *   before that change, and a product that had no earlier price shows none.
     */
    public static function capCompare(array $product, int $basePrice, ?array $latest, ?int $now = null): array {
        $price = (int)($product['price'] ?? 0);
        $compare = (int)($product['compare_price'] ?? 0);
        if ($compare <= $price) return $product;
        // R8: no recorded price, no proof of the prior price — a reduction is never announced without history.
        if ($latest === null) {
            $product['compare_price'] = null;
            return $product;
        }
        $now ??= time();
        $recent = static fn(string $at): bool => (strtotime($at . ' UTC') ?: 0) > $now - self::WINDOW_DAYS * 86400;
        if ($latest['price'] === $basePrice) {
            $changedRecently = $recent($latest['changed_at']);
            $prior = $latest['lowest_prior'];
        } else {
            // The base price changed without a history row: treat the change as happening now.
            $changedRecently = true;
            $prior = $recent($latest['changed_at']) && $latest['lowest_prior'] !== null
                ? min($latest['price'], $latest['lowest_prior']) : $latest['price'];
        }
        if ($price < $basePrice) {
            $reference = $changedRecently && $prior !== null ? min($basePrice, $prior) : $basePrice;
        } else {
            $reference = $prior;
        }
        $capped = $reference === null ? 0 : min($compare, $reference);
        $product['compare_price'] = $capped > $price ? $capped : null;
        return $product;
    }
}
