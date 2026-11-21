<?php
/**
 * R52 (idea 61): daily sales totals for the sales reports. A 365-day report summed every order and order line of the
 * year (3.8 s at 500 000 orders on MySQL); it now reads one row per store, local day and currency (sales_daily) and one
 * per product/variant (sales_daily_products), computed by SalesReport's own queries for that day, so a day reads the
 * same from here as from the orders: money received, refunds and credit notes taken off.
 *
 * Days are local days of the store's wall clock (PHP's default timezone while the store is active, as SalesReport
 * counts them). A day is built once it has ended and SETTLE_SECONDS have passed, so the checkouts started before
 * midnight have committed; reports sum the days still open from the orders. An order that changes later (a payment, a
 * refund, a cancellation, a credit note, an edit) is logged in sales_daily_changes by the code that writes it
 * (touchOrders(), in the same transaction) and its day is built again at the next refresh(). Rows inserted without it
 * (an import, SQL by hand) are found by their ids above the ones last seen. Orders deleted or changed by hand need
 * `php cli/sales-daily.php --rebuild`. A store whose timezone changed is rebuilt.
 */
final class SalesDaily {
    private const SETTLE_SECONDS = 900;
    /** Tables whose new rows change the day of their order: SQL of (store_id, created_at) of the orders of rows id in (:from, :to]. */
    private const WATCHED = [
        'orders' => 'SELECT o.store_id, o.created_at FROM orders o WHERE o.id > :from AND o.id <= :to',
        'payment_transactions' => 'SELECT o.store_id, o.created_at FROM payment_transactions x JOIN orders o ON o.id = x.order_id WHERE x.id > :from AND x.id <= :to',
        'order_refunds' => 'SELECT o.store_id, o.created_at FROM order_refunds x JOIN orders o ON o.id = x.order_id WHERE x.id > :from AND x.id <= :to',
        'credit_note_items' => 'SELECT o.store_id, o.created_at FROM credit_note_items x JOIN order_refunds rf ON rf.id = x.refund_id JOIN orders o ON o.id = rf.order_id WHERE x.id > :from AND x.id <= :to',
    ];

    public static function available(?PDO $db = null): bool {
        return Db::tableExists('sales_daily_state', $db);
    }

    /** Local calendar day (PHP's default timezone) of a UTC timestamp. */
    public static function dayOf(string $utc): string {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(self::zone())->format('Y-m-d');
    }

    /** UTC timestamp ('Y-m-d H:i:s') at which local $day begins. */
    public static function dayStartUtc(string $day): string {
        return (new DateTimeImmutable($day . ' 00:00:00', self::zone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function nextDay(string $day): string {
        return (new DateTimeImmutable($day . ' 12:00:00', new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
    }

    /** The first local day not closed yet at $now: it and the days after it are summed from the orders. */
    public static function openDay(?DateTimeImmutable $now = null): string {
        return self::dayOf(self::settledUtc($now));
    }

    /** The first day of $storeId not built: days before it are in the tables (once refresh() has run). */
    public static function builtUntil(string $storeId): string {
        return (string)(self::state(Db::get())['built_until:' . $storeId] ?? '');
    }

    /**
     * Log a change of these orders (their status, payment state, total, lines, refunds, credit notes): call it in the
     * transaction that writes it. An order placed less than SETTLE_SECONDS ago is in a day no store has built yet.
     * @param list<int|string> $orderIds
     */
    public static function touchOrders(array $orderIds, ?PDO $db = null): void {
        $db ??= Db::get();
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn(int $id): bool => $id > 0)));
        if ($ids === [] || !self::available($db)) return;
        $stmt = $db->prepare('SELECT store_id, created_at FROM orders WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
        self::log($db, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Bring $storeId's days up to date for PHP's current timezone: rebuild them all after a timezone change, log the
     * rows inserted without touchOrders(), build the days closed since the last run and the days of changed orders.
     * False when the tables do not exist (the reports then sum everything from the orders).
     */
    public static function refresh(string $storeId): bool {
        $db = Db::get();
        if (!self::available($db)) return false;
        $state = self::state($db);
        if (($state['timezone:' . $storeId] ?? '') !== date_default_timezone_get()) {
            self::reset($db, $storeId);
            $state = self::state($db);
        }
        self::logInserted($db, $state);
        $open = self::openDay();
        $builtUntil = (string)($state['built_until:' . $storeId] ?? '');
        if ($builtUntil < $open) {
            self::buildRange($db, $storeId, $builtUntil, $open);
            self::transaction($db, static fn() => self::setState($db, 'built_until:' . $storeId, $open));
            $builtUntil = $open;
        }
        $changes = $db->prepare('SELECT id, created_at FROM sales_daily_changes WHERE store_id = ? ORDER BY id');
        $changes->execute([$storeId]);
        $days = [];
        $seen = [];
        foreach ($changes->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $day = self::dayOf((string)$row['created_at']);
            // A day not built yet is built from the orders when it closes.
            if ($day < $builtUntil) $days[$day] = true;
            $seen[] = (int)$row['id'];
        }
        ksort($days);
        foreach (array_chunk(array_map('strval', array_keys($days)), 31) as $chunk) {
            self::transaction($db, static function () use ($db, $storeId, $chunk): void {
                foreach ($chunk as $day) self::build($db, $storeId, $day);
            });
        }
        // By id: a change committed while these days were built (its id not read) stays for the next refresh.
        foreach (array_chunk($seen, 500) as $chunk) {
            $db->prepare('DELETE FROM sales_daily_changes WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')')->execute($chunk);
        }
        return true;
    }

    /**
     * refresh() of every store that has orders, each in its own wall clock (the migration, cli/sales-daily.php);
     * $rebuild forgets their built days first.
     */
    public static function refreshAll(bool $rebuild = false): bool {
        $db = Db::get();
        if (!self::available($db)) return false;
        $stores = class_exists('Bootstrap') ? Bootstrap::stores() : [];
        $configs = is_array($stores['stores'] ?? null) ? $stores['stores'] : [];
        $default = $configs[$stores['default_store'] ?? ''] ?? (reset($configs) ?: []);
        if ($rebuild) {
            self::transaction($db, static function () use ($db): void {
                foreach (['sales_daily', 'sales_daily_products', 'sales_daily_changes'] as $table) $db->exec("DELETE FROM {$table}");
                $db->exec("DELETE FROM sales_daily_state WHERE name LIKE 'timezone:%' OR name LIKE 'built_until:%'");
            });
        }
        $previous = date_default_timezone_get();
        try {
            foreach ($db->query('SELECT DISTINCT store_id FROM orders')->fetchAll(PDO::FETCH_COLUMN) as $storeId) {
                $storeId = (string)$storeId;
                $config = is_array($configs[$storeId] ?? null) ? $configs[$storeId] : $default;
                date_default_timezone_set(StoreContext::timezoneFor(is_array($config) ? $config : []));
                self::refresh($storeId);
            }
        } finally {
            date_default_timezone_set($previous);
        }
        return true;
    }

    /**
     * Built figures of $storeId in [$fromDay, $toDay) per currency and day.
     * @return array<string, array<string, array{orders:int, revenue:int}>>
     */
    public static function days(string $storeId, string $fromDay, string $toDay): array {
        $stmt = Db::get()->prepare('SELECT currency, day, orders, revenue FROM sales_daily WHERE store_id = ? AND day >= ? AND day < ? ORDER BY day');
        $stmt->execute([$storeId, $fromDay, $toDay]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(string)$row['currency']][(string)$row['day']] = ['orders' => (int)$row['orders'], 'revenue' => (int)$row['revenue']];
        return $out;
    }

    /**
     * SalesReport::productGroups() of [$fromDay, $toDay), from the built days.
     * @return array<string, array{currency:string, product_id:int, variant_id:?int, line_id:int, quantity:int, revenue:float}>
     */
    public static function productGroups(string $storeId, string $fromDay, string $toDay): array {
        $stmt = Db::get()->prepare('SELECT currency, product_id, variant_key, MAX(variant_id) AS variant_id, MAX(line_id) AS line_id, SUM(quantity) AS quantity, SUM(revenue) AS revenue
            FROM sales_daily_products WHERE store_id = ? AND day >= ? AND day < ? GROUP BY currency, product_id, variant_key');
        $stmt->execute([$storeId, $fromDay, $toDay]);
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
        return $groups;
    }

    private static function zone(): DateTimeZone {
        return new DateTimeZone(date_default_timezone_get());
    }

    private static function settledUtc(?DateTimeImmutable $now = null): string {
        return ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'))->modify('-' . self::SETTLE_SECONDS . ' seconds')->format('Y-m-d H:i:s');
    }

    /** @return array<string, string> */
    private static function state(PDO $db): array {
        if (!self::available($db)) return [];
        return array_map('strval', $db->query('SELECT name, value FROM sales_daily_state')->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    private static function setState(PDO $db, string $name, string $value): void {
        $db->prepare('INSERT INTO sales_daily_state (name, value) VALUES (?, ?) ' . Db::upsertClause('name', 'value = excluded.value', 'value = VALUES(value)'))->execute([$name, $value]);
    }

    /** Forget $storeId's built days (for PHP's current timezone): the next refresh() builds them all again. */
    private static function reset(PDO $db, string $storeId): void {
        self::transaction($db, static function () use ($db, $storeId): void {
            foreach (['sales_daily', 'sales_daily_products', 'sales_daily_changes'] as $table) $db->prepare("DELETE FROM {$table} WHERE store_id = ?")->execute([$storeId]);
            self::setState($db, 'timezone:' . $storeId, date_default_timezone_get());
            self::setState($db, 'built_until:' . $storeId, '');
        });
    }

    /** Log the orders of the rows inserted since the last refresh (without touchOrders()). */
    private static function logInserted(PDO $db, array $state): void {
        foreach (self::WATCHED as $table => $sql) {
            if (!Db::tableExists($table, $db)) continue;
            $max = (int)$db->query("SELECT MAX(id) FROM {$table}")->fetchColumn();
            if (!isset($state['max_' . $table])) {
                // First run: every row so far is in the days the first build reads.
                self::transaction($db, static fn() => self::setState($db, 'max_' . $table, (string)$max));
                continue;
            }
            $seen = (int)$state['max_' . $table];
            if ($max === $seen) continue;
            $stmt = $db->prepare($sql);
            $stmt->execute([':from' => min($seen, $max), ':to' => $max]);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            self::transaction($db, static function () use ($db, $orders, $max, $table): void {
                self::log($db, $orders);
                self::setState($db, 'max_' . $table, (string)$max);
            });
        }
    }

    /**
     * One change row per order (insert only: concurrent checkouts do not wait on a shared row).
     * @param list<array{store_id: mixed, created_at: mixed}> $orders
     */
    private static function log(PDO $db, array $orders): void {
        $settled = self::settledUtc();
        $stmt = null;
        foreach ($orders as $order) {
            if ((string)$order['created_at'] > $settled) continue;
            $stmt ??= $db->prepare('INSERT INTO sales_daily_changes (store_id, created_at) VALUES (?, ?)');
            $stmt->execute([(string)$order['store_id'], (string)$order['created_at']]);
        }
    }

    /** Build every day of $storeId in [$fromDay, $toDay) that has orders ('' = from the first order). */
    private static function buildRange(PDO $db, string $storeId, string $fromDay, string $toDay): void {
        $hours = $db->prepare('SELECT DISTINCT SUBSTR(created_at, 1, 13) FROM orders WHERE store_id = ? AND created_at >= ? AND created_at < ?');
        $hours->execute([$storeId, $fromDay !== '' ? self::dayStartUtc($fromDay) : '0000-00-00 00:00:00', self::dayStartUtc($toDay)]);
        $days = [];
        foreach ($hours->fetchAll(PDO::FETCH_COLUMN) as $hour) {
            // An hour of orders may straddle two local days (a zone off by a half hour).
            $days[self::dayOf($hour . ':00:00')] = true;
            $days[self::dayOf($hour . ':59:59')] = true;
        }
        ksort($days);
        $days = array_values(array_filter(array_map('strval', array_keys($days)), static fn(string $day): bool => $day < $toDay && ($fromDay === '' || $day >= $fromDay)));
        // A month of days per transaction: one commit per day (a log flush each) took most of a two-year build.
        foreach (array_chunk($days, 31) as $chunk) {
            self::transaction($db, static function () use ($db, $storeId, $chunk): void {
                foreach ($chunk as $day) self::build($db, $storeId, $day);
            });
        }
    }

    /** Replace the rows of $storeId's local $day with SalesReport's figures of its orders. */
    private static function build(PDO $db, string $storeId, string $day): void {
        $since = self::dayStartUtc($day);
        $until = self::dayStartUtc(self::nextDay($day));
        $db->prepare('DELETE FROM sales_daily WHERE store_id = ? AND day = ?')->execute([$storeId, $day]);
        $db->prepare('DELETE FROM sales_daily_products WHERE store_id = ? AND day = ?')->execute([$storeId, $day]);
        $insert = $db->prepare('INSERT INTO sales_daily (store_id, day, currency, orders, revenue) VALUES (?, ?, ?, ?, ?)');
        foreach (SalesReport::revenueRows($storeId, $since, $until) as $currency => $figures) {
            $insert->execute([$storeId, $day, $currency, $figures['orders'], $figures['revenue']]);
        }
        // Many rows per statement: a day of a large catalogue has hundreds of products.
        foreach (array_chunk(array_values(SalesReport::productGroups($storeId, $since, $until)), 100) as $groups) {
            $values = [];
            foreach ($groups as $group) {
                array_push($values, $storeId, $day, $group['currency'], $group['product_id'], (int)($group['variant_id'] ?? 0), $group['variant_id'], $group['line_id'], $group['quantity'], $group['revenue']);
            }
            $db->prepare('INSERT INTO sales_daily_products (store_id, day, currency, product_id, variant_key, variant_id, line_id, quantity, revenue) VALUES '
                . implode(',', array_fill(0, count($groups), '(?, ?, ?, ?, ?, ?, ?, ?, ?)')))->execute($values);
        }
    }

    /**
     * Run $work in a transaction of its own, or in the caller's when one is open. On MySQL the writers take the 'lock'
     * state row first, so two admin requests do not rebuild the same day at once (their DELETE gap locks and INSERTs
     * would deadlock); SQLite has one writer anyway.
     */
    private static function transaction(PDO $db, callable $work): void {
        if (Db::transactionOpen($db)) {
            $work();
            return;
        }
        $db->beginTransaction();
        try {
            if (!Db::isSqlite()) $db->query("SELECT value FROM sales_daily_state WHERE name = 'lock' FOR UPDATE")->fetchAll();
            $work();
            $db->commit();
        } catch (Throwable $e) {
            if (Db::transactionOpen($db)) Db::rollBackOpen($db);
            throw $e;
        }
    }
}
