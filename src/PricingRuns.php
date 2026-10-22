<?php
/**
 * Omnibus record of past catalog promotion and price-list runs (migration 20261022150000_pricing_runs).
 * The prior price counts what promotions and price lists charged in the 30 days before a reduction. Before an admin
 * change rewrites such a run (re-dating, switching off, deleting, repricing), PricingAdmin copies it here, ended at
 * the time of the change; Promotions::catalogRulesWithEnded() and Pricing::priceListRowsWithEnded() read the copies
 * as ended runs. A change that leaves a running run charging the same prices from the same start is not copied.
 */
final class PricingRuns {
    private const PROMOTION_PRICING = ['scope', 'priority', 'stacking', 'conditions', 'actions'];
    private const LIST_PRICING = ['currency', 'customer_group_id'];

    /** Copy the stored promotion $old before it becomes $new (null: deleted). */
    public static function promotion(PDO $db, array $old, ?array $new): void {
        if ((string)$old['scope'] !== 'catalog' || ($end = self::recentEnd($old)) === null || !Db::tableExists('promotion_runs', $db)) return;
        if ($new !== null && self::continues($old, $new, self::PROMOTION_PRICING)) return;
        $db->prepare('INSERT INTO promotion_runs (store_id, promotion_id, code, scope, priority, stacking, conditions, actions, valid_from, valid_to)
            VALUES (:store, :id, :code, :scope, :priority, :stacking, :conditions, :actions, :valid_from, :valid_to)')
            ->execute([':store' => $old['store_id'], ':id' => $old['id'], ':code' => $old['code'], ':scope' => $old['scope'], ':priority' => $old['priority'],
                ':stacking' => $old['stacking'], ':conditions' => $old['conditions'], ':actions' => $old['actions'], ':valid_from' => $old['valid_from'], ':valid_to' => $end]);
    }

    /**
     * Copy the product-level prices of the stored price list $old before the list becomes $new (null: deleted, or
     * $prices are the ones being replaced). $prices: rows with product_id, variant_id, min_qty, price.
     */
    public static function priceList(PDO $db, array $old, array $prices, ?array $new): void {
        if (($end = self::recentEnd($old)) === null || !Db::tableExists('price_list_runs', $db)) return;
        if ($new !== null && self::continues($old, $new, self::LIST_PRICING)) return;
        $insert = $db->prepare('INSERT INTO price_list_runs (store_id, price_list_id, currency, customer_group_id, product_id, price, valid_from, valid_to)
            VALUES (:store, :list, :currency, :group_id, :product, :price, :valid_from, :valid_to)');
        foreach ($prices as $row) {
            if ($row['variant_id'] !== null || (int)$row['min_qty'] > 1) continue;
            $insert->execute([':store' => $old['store_id'], ':list' => $old['id'], ':currency' => $old['currency'], ':group_id' => $old['customer_group_id'],
                ':product' => $row['product_id'], ':price' => $row['price'], ':valid_from' => $old['valid_from'], ':valid_to' => $end]);
        }
    }

    /** Product-level rows of $before whose price $after no longer has. */
    public static function changedPrices(array $before, array $after): array {
        $key = static fn(array $r): string => $r['variant_id'] === null && (int)$r['min_qty'] <= 1 ? $r['product_id'] . ':' . $r['price'] : '';
        $kept = array_flip(array_map($key, $after));
        return array_values(array_filter($before, static fn(array $r): bool => $key($r) !== '' && !isset($kept[$key($r)])));
    }

    /** End of an active run that started and charged within the last 30 days (now for a running one), else null. */
    private static function recentEnd(array $run): ?string {
        $now = gmdate('Y-m-d H:i:s');
        if ((int)$run['active'] !== 1 || ($run['valid_from'] !== null && (string)$run['valid_from'] > $now)) return null;
        $end = $run['valid_to'] === null ? $now : min($now, (string)$run['valid_to']);
        return $end >= gmdate('Y-m-d H:i:s', time() - PriceHistory::WINDOW_DAYS * 86400) ? $end : null;
    }

    /** $new keeps the running $old going: still active, same start and pricing fields, neither ended. */
    private static function continues(array $old, array $new, array $fields): bool {
        $now = gmdate('Y-m-d H:i:s');
        if ((int)$new['active'] !== 1 || $old['valid_from'] !== $new['valid_from']) return false;
        if (($old['valid_to'] !== null && (string)$old['valid_to'] < $now) || ($new['valid_to'] !== null && (string)$new['valid_to'] < $now)) return false;
        foreach ($fields as $field) if ((string)$old[$field] !== (string)$new[$field]) return false;
        return true;
    }
}
