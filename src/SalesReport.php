<?php
/**
 * Store-scoped sales figures for the admin: totals, AOV, daily trend and top products.
 * Money is never summed across currencies — every figure is keyed by lower-case currency code
 * (the key I18n::price() formats with). orders.created_at is UTC; day boundaries follow the
 * PHP display timezone, so "today" means the merchant's calendar day. Revenue is money received:
 * only orders whose payment came in count, less what was refunded of them. Unpaid (pending,
 * failed, awaiting transfer or cash on delivery), cancelled/refunded and synthetic (post-deploy
 * smoke) orders are not revenue.
 */
final class SalesReport {
    public const PERIODS = [7, 30, 90];
    private const NOT_REVENUE = "('cancelled', 'refunded')";
    public const PAID = "('paid', 'refund_pending', 'partially_refunded', 'disputed')";
    private const REFUNDED = "('partially_refunded', 'refunded', 'refund_succeeded')";

    /**
     * FROM clause of revenue orders `o` with `r.refunded` = what was refunded of each, and its parameters (merge them
     * with :store of revenueCondition()).
     * @return array{0: string, 1: array<string, string>}
     */
    public static function revenueSource(string $storeId): array {
        if (!Db::tableExists('payment_transactions')) return ['orders o LEFT JOIN (SELECT 0 AS order_id, 0 AS refunded) r ON 1 = 0', []];
        return ["orders o LEFT JOIN (SELECT order_id, SUM(amount) AS refunded FROM payment_transactions
                WHERE store_id = :rstore AND status IN " . self::REFUNDED . " GROUP BY order_id) r ON r.order_id = o.id", [':rstore' => $storeId]];
    }

    /** WHERE condition of revenue orders `o` of :store. */
    public static function revenueCondition(): string {
        return "o.store_id = :store AND o.is_synthetic = 0 AND o.status NOT IN " . self::NOT_REVENUE . " AND o.payment_status IN " . self::PAID;
    }

    /** UTC timestamp of local midnight at the start of the period ending today (1 = today only). */
    public static function sinceUtc(int $days, ?DateTimeImmutable $now = null): string {
        $local = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $start = $local->setTime(0, 0)->modify('-' . max(0, $days - 1) . ' days');
        return $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @return array<string, array{orders:int, revenue:int, aov:int}> currency => figures, sorted by currency */
    public static function totals(string $storeId, string $sinceUtc): array {
        [$source, $params] = self::revenueSource($storeId);
        $stmt = Db::get()->prepare("SELECT LOWER(o.currency) AS currency, COUNT(*) AS orders, COALESCE(SUM(o.total - COALESCE(r.refunded, 0)), 0) AS revenue
            FROM {$source} WHERE " . self::revenueCondition() . " AND o.created_at >= :since
            GROUP BY LOWER(o.currency)");
        $stmt->execute($params + [':store' => $storeId, ':since' => $sinceUtc]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $orders = (int)$row['orders'];
            $revenue = (int)$row['revenue'];
            $out[(string)$row['currency']] = ['orders' => $orders, 'revenue' => $revenue, 'aov' => $orders > 0 ? (int)round($revenue / $orders) : 0];
        }
        ksort($out);
        return $out;
    }

    /**
     * Daily revenue per currency for the last $days local days, oldest first, zero-filled.
     * @return array<string, array<string, array{orders:int, revenue:int}>> currency => Y-m-d => figures
     */
    public static function trend(string $storeId, int $days, ?DateTimeImmutable $now = null): array {
        $since = self::sinceUtc($days, $now);
        // Hour buckets keep the SQL portable (SQLite/MySQL) and let PHP shift them into local days.
        [$source, $params] = self::revenueSource($storeId);
        $stmt = Db::get()->prepare("SELECT LOWER(o.currency) AS currency, SUBSTR(o.created_at, 1, 13) AS hour_utc, COUNT(*) AS orders, COALESCE(SUM(o.total - COALESCE(r.refunded, 0)), 0) AS revenue
            FROM {$source} WHERE " . self::revenueCondition() . " AND o.created_at >= :since
            GROUP BY LOWER(o.currency), SUBSTR(o.created_at, 1, 13)");
        $stmt->execute($params + [':store' => $storeId, ':since' => $since]);
        $zone = new DateTimeZone(date_default_timezone_get());
        $utc = new DateTimeZone('UTC');
        $emptyDays = [];
        $day = new DateTimeImmutable($since, $utc);
        for ($i = 0; $i < max(1, $days); $i++) {
            $emptyDays[$day->setTimezone($zone)->modify("+{$i} days")->format('Y-m-d')] = ['orders' => 0, 'revenue' => 0];
        }
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $currency = (string)$row['currency'];
            $out[$currency] ??= $emptyDays;
            $localDay = (new DateTimeImmutable($row['hour_utc'] . ':00:00', $utc))->setTimezone($zone)->format('Y-m-d');
            if (!isset($out[$currency][$localDay])) continue;
            $out[$currency][$localDay]['orders'] += (int)$row['orders'];
            $out[$currency][$localDay]['revenue'] += (int)$row['revenue'];
        }
        ksort($out);
        return $out;
    }

    /** @return array<string, list<array{product_id:int, sku:string, name:string, quantity:int, revenue:int}>> currency => best sellers by revenue */
    public static function topProducts(string $storeId, string $sinceUtc, int $limit = 10): array {
        $stmt = Db::get()->prepare("SELECT LOWER(o.currency) AS currency, oi.product_id, MAX(oi.sku) AS sku, MAX(oi.name) AS name,
                COALESCE(SUM(oi.quantity), 0) AS quantity, COALESCE(SUM(oi.total), 0) AS revenue
            FROM order_items oi JOIN orders o ON o.id = oi.order_id
            WHERE " . self::revenueCondition() . " AND o.created_at >= :since
            GROUP BY LOWER(o.currency), oi.product_id
            ORDER BY revenue DESC, quantity DESC, oi.product_id ASC");
        $stmt->execute([':store' => $storeId, ':since' => $sinceUtc]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $currency = (string)$row['currency'];
            if (count($out[$currency] ?? []) >= $limit) continue;
            $out[$currency][] = [
                'product_id' => (int)$row['product_id'],
                'sku' => (string)$row['sku'],
                'name' => (string)$row['name'],
                'quantity' => (int)$row['quantity'],
                'revenue' => (int)$row['revenue'],
            ];
        }
        ksort($out);
        return $out;
    }
}
