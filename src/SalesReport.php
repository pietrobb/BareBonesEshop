<?php
/**
 * Store-scoped sales figures for the admin: totals, AOV, daily trend and top products.
 * Money is never summed across currencies — every figure is keyed by lower-case currency code
 * (the key I18n::price() formats with). orders.created_at is UTC; day boundaries follow the
 * PHP display timezone, so "today" means the merchant's calendar day. Revenue is money received:
 * only orders whose payment came in count, less what was refunded of them. Unpaid (pending,
 * failed, awaiting transfer or cash on delivery), cancelled/refunded and synthetic (post-deploy
 * smoke) orders are not revenue.
 *
 * R51 (review round 51, low): a 365-day report took 13 s at 500 000 orders on MySQL, joining every order and order
 * line of the period to its refunds and credits. The reports now sum the orders and lines from covering indexes alone
 * (idx_orders_store_payment_cover, idx_oi_order_cover) and correct them only for the orders that have a refund or a
 * credit note.
 */
final class SalesReport {
    public const PERIODS = [7, 30, 90];
    private const NOT_REVENUE = "('cancelled', 'refunded')";
    public const PAID = "('paid', 'refund_pending', 'partially_refunded', 'disputed')";
    private const REFUNDED = "('partially_refunded', 'refunded', 'refund_succeeded')";

    /**
     * FROM clause of revenue orders `o` with `r.refunded` = what was refunded of each, and its parameters (merge them
     * with :store of revenueCondition()). R50 (review round 20, low): with $sinceUtc only the refunds of orders placed
     * since then are summed (was every refund of the store's history for a 90-day report, ~4.5 s at 500 000 orders), and
     * MySQL reads the store's paid orders of the period through an index starting with store, the PAID states, then
     * the created_at range, instead of a store-wide index.
     * @return array{0: string, 1: array<string, string>}
     */
    public static function revenueSource(string $storeId, ?string $sinceUtc = null): array {
        [$join, $params] = self::refundJoin($storeId, $sinceUtc);
        return ['orders o' . Db::forceIndex('orders', 'idx_orders_store_payment_cover') . $join, $params];
    }

    /**
     * " LEFT JOIN ... r" giving r.refunded of order `o`, and its parameters.
     * @return array{0: string, 1: array<string, string>}
     */
    private static function refundJoin(string $storeId, ?string $sinceUtc): array {
        if (!Db::tableExists('payment_transactions')) return [' LEFT JOIN (SELECT 0 AS order_id, 0 AS refunded) r ON 1 = 0', []];
        $params = [':rstore' => $storeId];
        $bound = '';
        if ($sinceUtc !== null) {
            $bound = ' AND order_id IN (SELECT id FROM orders WHERE store_id = :rostore AND created_at >= :rsince)';
            $params += [':rostore' => $storeId, ':rsince' => $sinceUtc];
        }
        return [" LEFT JOIN (SELECT order_id, SUM(amount) AS refunded FROM payment_transactions
                WHERE store_id = :rstore AND status IN " . self::REFUNDED . "{$bound} GROUP BY order_id) r ON r.order_id = o.id", $params];
    }

    /** WHERE condition of revenue orders `o` of :store. */
    public static function revenueCondition(): string {
        return "o.store_id = :store AND o.is_synthetic = 0 AND o.status NOT IN " . self::NOT_REVENUE . " AND o.payment_status IN " . self::PAID;
    }

    /**
     * revenueCondition() of the orders placed since :since, answerable from idx_orders_store_payment_cover alone.
     * orders.status does not fit into that index on MySQL (key length), so the few cancelled or refunded orders of the
     * period are left out by id (found through idx_orders_store_status_created). status is NOT NULL, so this is the
     * same set of orders.
     * @return array{0: string, 1: array<string, string>}
     */
    private static function revenueWhere(string $storeId, string $sinceUtc): array {
        return ["o.store_id = :store AND o.is_synthetic = 0 AND o.payment_status IN " . self::PAID . " AND o.created_at >= :since
            AND o.id NOT IN (SELECT x.id FROM orders x" . Db::forceIndex('orders', 'idx_orders_store_status_created') . " WHERE x.store_id = :xstore AND x.status IN " . self::NOT_REVENUE . " AND x.created_at >= :xsince)",
            [':store' => $storeId, ':since' => $sinceUtc, ':xstore' => $storeId, ':xsince' => $sinceUtc]];
    }

    /** UTC timestamp of local midnight at the start of the period ending today (1 = today only). */
    public static function sinceUtc(int $days, ?DateTimeImmutable $now = null): string {
        $local = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $start = $local->setTime(0, 0)->modify('-' . max(0, $days - 1) . ' days');
        return $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Revenue orders placed since $sinceUtc and their revenue (total less what was refunded of them), per currency and,
     * with $hourly, per UTC hour ('' otherwise). The orders are counted from the index; the refunds of those orders are
     * summed from the refunds and taken off.
     * @return list<array{currency:string, hour_utc:string, orders:int, revenue:int}>
     */
    private static function revenueBy(string $storeId, string $sinceUtc, bool $hourly): array {
        $db = Db::get();
        $columns = 'LOWER(o.currency) AS currency, ' . ($hourly ? 'SUBSTR(o.created_at, 1, 13)' : "''") . ' AS hour_utc';
        $group = 'LOWER(o.currency)' . ($hourly ? ', SUBSTR(o.created_at, 1, 13)' : '');
        [$where, $params] = self::revenueWhere($storeId, $sinceUtc);
        $stmt = $db->prepare("SELECT {$columns}, COUNT(*) AS orders, COALESCE(SUM(o.total), 0) AS revenue
            FROM orders o" . Db::forceIndex('orders', 'idx_orders_store_payment_cover', $db) . " WHERE {$where} GROUP BY {$group}");
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[$row['currency'] . '|' . $row['hour_utc']] = ['currency' => (string)$row['currency'], 'hour_utc' => (string)$row['hour_utc'],
                'orders' => (int)$row['orders'], 'revenue' => (int)$row['revenue']];
        }
        if ($rows !== [] && Db::tableExists('payment_transactions')) {
            $stmt = $db->prepare("SELECT {$columns}, COALESCE(SUM(pt.amount), 0) AS refunded
                FROM payment_transactions pt JOIN orders o ON o.id = pt.order_id
                WHERE pt.store_id = :rstore AND pt.status IN " . self::REFUNDED . ' AND ' . self::revenueCondition() . " AND o.created_at >= :since
                GROUP BY {$group}");
            $stmt->execute([':rstore' => $storeId, ':store' => $storeId, ':since' => $sinceUtc]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = $row['currency'] . '|' . $row['hour_utc'];
                if (isset($rows[$key])) $rows[$key]['revenue'] -= (int)$row['refunded'];
            }
        }
        return array_values($rows);
    }

    /** @return array<string, array{orders:int, revenue:int, aov:int}> currency => figures, sorted by currency */
    public static function totals(string $storeId, string $sinceUtc): array {
        $out = [];
        foreach (self::revenueBy($storeId, $sinceUtc, false) as $row) {
            $orders = $row['orders'];
            $revenue = $row['revenue'];
            $out[$row['currency']] = ['orders' => $orders, 'revenue' => $revenue, 'aov' => $orders > 0 ? (int)round($revenue / $orders) : 0];
        }
        ksort($out);
        return $out;
    }

    /**
     * totals() of the period of a trend() (the same orders), without reading them again.
     * @param array<string, array<string, array{orders:int, revenue:int}>> $trend
     * @return array<string, array{orders:int, revenue:int, aov:int}>
     */
    public static function totalsOf(array $trend): array {
        $out = [];
        foreach ($trend as $currency => $days) {
            $orders = array_sum(array_column($days, 'orders'));
            $revenue = array_sum(array_column($days, 'revenue'));
            if ($orders > 0) $out[$currency] = ['orders' => $orders, 'revenue' => $revenue, 'aov' => (int)round($revenue / $orders)];
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
        $zone = new DateTimeZone(date_default_timezone_get());
        $utc = new DateTimeZone('UTC');
        $emptyDays = [];
        $day = new DateTimeImmutable($since, $utc);
        for ($i = 0; $i < max(1, $days); $i++) {
            $emptyDays[$day->setTimezone($zone)->modify("+{$i} days")->format('Y-m-d')] = ['orders' => 0, 'revenue' => 0];
        }
        $out = [];
        foreach (self::revenueBy($storeId, $since, true) as $row) {
            $currency = $row['currency'];
            $out[$currency] ??= $emptyDays;
            $localDay = (new DateTimeImmutable($row['hour_utc'] . ':00:00', $utc))->setTimezone($zone)->format('Y-m-d');
            if (!isset($out[$currency][$localDay])) continue;
            $out[$currency][$localDay]['orders'] += $row['orders'];
            $out[$currency][$localDay]['revenue'] += $row['revenue'];
        }
        ksort($out);
        return $out;
    }

    /**
     * Best sellers by revenue, one row per variant (each variant has its own SKU and name). Revenue is money received
     * like totals(): an item refund (a completed credit note line) is taken off the line it refunded; the rest of what
     * was refunded of an order (a full or amount refund, shipping) off its lines in proportion to what is left of them,
     * so a product never shows more than the order brought in and an item refund never lowers another product.
     * SKU and name are those of the product's latest line of the period.
     * @return array<string, list<array{product_id:int, variant_id:?int, sku:string, name:string, quantity:int, revenue:int}>> currency => best sellers by revenue
     */
    public static function topProducts(string $storeId, string $sinceUtc, int $limit = 10): array {
        $db = Db::get();
        $group = 'LOWER(o.currency), oi.product_id, COALESCE(oi.variant_id, 0)';
        // What a line brings in when its order has neither a refund nor a credit note (see refundCorrections()).
        $plain = 'CASE WHEN oi.total <= 0 OR o.total <= 0 THEN 0 ELSE oi.total END';
        [$where, $params] = self::revenueWhere($storeId, $sinceUtc);
        $stmt = $db->prepare("SELECT LOWER(o.currency) AS currency, oi.product_id, COALESCE(oi.variant_id, 0) AS variant_key, MAX(oi.variant_id) AS variant_id,
                MAX(oi.id) AS line_id, COALESCE(SUM(oi.quantity), 0) AS quantity, COALESCE(SUM({$plain}), 0) AS revenue
            FROM orders o" . Db::forceIndex('orders', 'idx_orders_store_payment_cover', $db)
            . ' JOIN order_items oi' . Db::forceIndex('order_items', 'idx_oi_order_cover', $db) . " ON oi.order_id = o.id
            WHERE {$where} GROUP BY {$group}");
        $stmt->execute($params);
        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groups[$row['currency'] . '|' . $row['product_id'] . '|' . $row['variant_key']] = [
                'currency' => (string)$row['currency'],
                'product_id' => (int)$row['product_id'],
                'variant_id' => $row['variant_id'] === null ? null : (int)$row['variant_id'],
                'line_id' => (int)$row['line_id'],
                'quantity' => (int)$row['quantity'],
                'revenue' => (float)$row['revenue'],
            ];
        }
        foreach (self::refundCorrections($db, $storeId, $sinceUtc, $plain, $group) as $row) {
            $key = $row['currency'] . '|' . $row['product_id'] . '|' . $row['variant_key'];
            if (isset($groups[$key])) $groups[$key]['revenue'] += (float)$row['delta'];
        }
        usort($groups, static fn(array $a, array $b): int => [$b['revenue'], $b['quantity'], $a['product_id'], $a['variant_id'] ?? -1]
            <=> [$a['revenue'], $a['quantity'], $b['product_id'], $b['variant_id'] ?? -1]);
        $picked = [];
        foreach ($groups as $group) {
            if (count($picked[$group['currency']] ?? []) < $limit) $picked[$group['currency']][] = $group;
        }
        $lines = array_merge(...array_map(static fn(array $rows): array => array_column($rows, 'line_id'), array_values($picked)));
        $names = [];
        if ($lines !== []) {
            $stmt = $db->prepare('SELECT id, sku, name FROM order_items WHERE id IN (' . implode(',', array_fill(0, count($lines), '?')) . ')');
            $stmt->execute($lines);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) $names[(int)$line['id']] = $line;
        }
        $out = [];
        foreach ($picked as $currency => $rows) {
            foreach ($rows as $row) {
                $out[$currency][] = [
                    'product_id' => $row['product_id'],
                    'variant_id' => $row['variant_id'],
                    'sku' => (string)($names[$row['line_id']]['sku'] ?? ''),
                    'name' => (string)($names[$row['line_id']]['name'] ?? ''),
                    'quantity' => $row['quantity'],
                    'revenue' => (int)round($row['revenue']),
                ];
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * For the revenue orders of the period that have a refund or a credit note: per best-seller group, what their lines
     * really brought in less the $plain amount topProducts() counted for them.
     * @return list<array{currency:string, product_id:int|string, variant_key:int|string, delta:float|int|string}>
     */
    private static function refundCorrections(PDO $db, string $storeId, string $sinceUtc, string $plain, string $group): array {
        $affected = [];
        $params = [];
        if (Db::tableExists('payment_transactions')) {
            $affected[] = 'SELECT order_id FROM payment_transactions WHERE store_id = :astore AND status IN ' . self::REFUNDED;
            $params[':astore'] = $storeId;
        }
        $credits = '';
        if (Db::tableExists('credit_note_items') && Db::tableExists('order_refunds')) {
            $affected[] = "SELECT rf.order_id FROM order_refunds rf JOIN credit_note_items cni ON cni.refund_id = rf.id WHERE rf.store_id = :afstore AND rf.status = 'succeeded'";
            $params[':afstore'] = $storeId;
            $credited = "SELECT %s, SUM(cni.gross) AS credited FROM credit_note_items cni JOIN order_refunds rf ON rf.id = cni.refund_id
                WHERE rf.store_id = :cstore AND rf.status = 'succeeded'
                  AND rf.order_id IN (SELECT id FROM orders WHERE store_id = :cstore_o AND created_at >= :csince) GROUP BY %s";
            $credits = ' LEFT JOIN (' . sprintf($credited, 'cni.order_item_id', 'cni.order_item_id') . ') ci ON ci.order_item_id = oi.id'
                . ' LEFT JOIN (' . sprintf(str_replace([':cstore', ':csince'], [':costore', ':cosince'], $credited), 'rf.order_id', 'rf.order_id') . ') co ON co.order_id = o.id';
            $params += [':cstore' => $storeId, ':cstore_o' => $storeId, ':csince' => $sinceUtc, ':costore' => $storeId, ':costore_o' => $storeId, ':cosince' => $sinceUtc];
        }
        if ($affected === []) return [];
        [$refunds, $refundParams] = self::refundJoin($storeId, $sinceUtc);
        $line = $credits === '' ? 'oi.total' : '(oi.total - COALESCE(ci.credited, 0))';
        $order = $credits === '' ? 'o.total' : '(o.total - COALESCE(co.credited, 0))';
        $rest = $credits === '' ? 'COALESCE(r.refunded, 0)' : '(COALESCE(r.refunded, 0) - COALESCE(co.credited, 0))';
        $kept = "CASE WHEN {$line} <= 0 OR {$order} <= 0 THEN 0 WHEN {$rest} <= 0 THEN {$line}
            WHEN {$rest} >= {$order} THEN 0 ELSE {$line} * 1.0 * ({$order} - {$rest}) / {$order} END";
        $stmt = $db->prepare("SELECT LOWER(o.currency) AS currency, oi.product_id, COALESCE(oi.variant_id, 0) AS variant_key, SUM({$kept}) - SUM({$plain}) AS delta
            FROM (" . implode(' UNION ', $affected) . ") a JOIN orders o ON o.id = a.order_id{$refunds} JOIN order_items oi ON oi.order_id = o.id{$credits}
            WHERE " . self::revenueCondition() . " AND o.created_at >= :since
            GROUP BY {$group}");
        $stmt->execute($params + $refundParams + [':store' => $storeId, ':since' => $sinceUtc]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
