<?php
/**
 * What a store cannot sell because it has no price in the store's currency (Pricing::soldInStore): active products of
 * its catalog in another currency without a public price-list row in the store's currency, and variants of priced
 * products that carry a surcharge or an absolute price without a row of their own (Pricing::applyPriceList never
 * adds a catalog-currency surcharge to another currency). The storefront shows these as unavailable; doctor lists them.
 */
final class StoreCurrencyCoverage
{
    /** @return array{count: int, skus: list<string>} up to $sample SKUs */
    public static function unpriced(PDO $db, string $storeId, array $store, int $sample = 5): array
    {
        $currency = strtolower((string)($store['currency'] ?? ''));
        if ($currency === '') return ['count' => 0, 'skus' => []];
        $now = gmdate('Y-m-d H:i:s');
        $params = [':catalog_p' => (string)($store['catalog_scope'] ?? $storeId), ':currency_p' => $currency];
        $priced = static function (string $suffix, string $variantCondition) use (&$params, $storeId, $currency, $now): string {
            $params += [":store_{$suffix}" => $storeId, ":list_currency_{$suffix}" => $currency, ":from_{$suffix}" => $now, ":to_{$suffix}" => $now];
            return "SELECT pp.%s FROM price_list_prices pp JOIN price_lists pl ON pl.id = pp.price_list_id
                WHERE pl.store_id = :store_{$suffix} AND pl.active = 1 AND LOWER(pl.currency) = :list_currency_{$suffix}
                  AND pl.customer_group_id IS NULL AND pp.min_qty <= 1 AND pp.{$variantCondition}
                  AND (pl.valid_from IS NULL OR pl.valid_from <= :from_{$suffix}) AND (pl.valid_to IS NULL OR pl.valid_to >= :to_{$suffix})";
        };
        $productRows = sprintf($priced('a', 'variant_id IS NULL'), 'product_id');
        $productRowsForVariants = sprintf($priced('b', 'variant_id IS NULL'), 'product_id');
        $variantRows = sprintf($priced('c', 'variant_id IS NOT NULL'), 'variant_id');
        $params += [':catalog_v' => $params[':catalog_p'], ':currency_v' => $currency];
        $sql = "SELECT p.sku FROM products p
                WHERE p.store_id = :catalog_p AND p.status = 'active' AND LOWER(p.currency) <> :currency_p AND p.id NOT IN ({$productRows})
            UNION ALL
            SELECT v.sku FROM variants v JOIN products p ON p.id = v.product_id
                WHERE p.store_id = :catalog_v AND p.status = 'active' AND LOWER(p.currency) <> :currency_v
                  AND (v.price_delta <> 0 OR v.price_absolute IS NOT NULL)
                  AND p.id IN ({$productRowsForVariants}) AND v.id NOT IN ({$variantRows})";
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM ({$sql}) unpriced");
            $stmt->execute($params);
            $count = (int)$stmt->fetchColumn();
            $stmt = $db->prepare($sql . ' ORDER BY 1 LIMIT ' . max(1, $sample));
            $stmt->execute($params);
            return ['count' => $count, 'skus' => array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))];
        } catch (PDOException) {
            return ['count' => 0, 'skus' => []]; // pricing tables missing until the migrations ran (doctor reports the schema)
        }
    }
}
