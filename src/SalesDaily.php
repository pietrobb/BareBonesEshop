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
 * (touchOrders(), in the same transaction; every payment_transactions row by PaymentService::logTransaction()) and its
 * day is built again at the next refresh(). Rows inserted without it (an import, SQL by hand) are found by their ids
 * above the ones last seen; on MySQL a row whose transaction commits after a higher id was seen is missed, so the
 * shop's own writers never rely on it. Orders deleted or changed by hand need
 * `php cli/sales-daily.php --rebuild`. A store whose timezone changed is rebuilt.
 *
 * R53: a report builds for at most $requestSeconds and commits its progress per batch; the worker job JOB builds the
 * rest, and until then the report sums the days not built from the orders.
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

    /** R53: seconds a report request spends building days; the worker (JOB) and the next requests build the rest. */
    public static float $requestSeconds = 2.0;
    /** R53: seconds one worker job builds before it queues the next (a page-request worker runs inside a request too). */
    public const JOB_SECONDS = 20.0;
    public const JOB = 'sales_daily_build';
    private const TABLES = ['sales_daily', 'sales_daily_products', 'sales_daily_changes', 'sales_daily_state'];
    /** @var array<string, string> per store: the first day whose logged change this request did not build yet */
    private static array $staleFrom = [];

    /** R53: all four tables (one of them missing failed the reports with a 500); else the reports sum the orders. */
    public static function available(?PDO $db = null): bool {
        foreach (self::TABLES as $table) {
            if (!Db::tableExists($table, $db)) return false;
        }
        return true;
    }

    /** R53: the monthly product sums (the 20261121120000 migration builds the days before their table exists). */
    private static function monthly(PDO $db): bool {
        return Db::tableExists('sales_monthly_products', $db);
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

    /**
     * The first day of $storeId not built: days before it are in the tables (once refresh() has run). A day whose
     * change refresh() left for later ends the built days early, so a report sums it from the orders.
     */
    public static function builtUntil(string $storeId): string {
        $until = (string)(self::state(Db::get())['built_until:' . $storeId] ?? '');
        $stale = self::$staleFrom[$storeId] ?? null;
        return $stale !== null && $stale < $until ? $stale : $until;
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
     * rows inserted without touchOrders(), build the days of changed orders and the days closed since the last run.
     * R53: with $seconds it stops after about that long (a first build of a large shop took 42 s in one admin request
     * and, cut off by a timeout, started over at every visit): every batch of days is committed with how far it got,
     * the report sums the days not built from the orders, and the worker job JOB builds the rest.
     * False when the tables do not exist (the reports then sum everything from the orders).
     */
    public static function refresh(string $storeId, ?float $seconds = null): bool {
        $db = Db::get();
        if (!self::available($db)) return false;
        if (!self::refreshStore($db, $storeId, $seconds === null ? null : microtime(true) + $seconds)) self::queueRest($db);
        return true;
    }

    /** refresh() of $storeId until $deadline (null: all of it); whether everything was built. */
    private static function refreshStore(PDO $db, string $storeId, ?float $deadline): bool {
        unset(self::$staleFrom[$storeId]);
        $state = self::state($db);
        if (($state['timezone:' . $storeId] ?? '') !== date_default_timezone_get()) {
            self::reset($db, $storeId);
            $state = self::state($db);
        }
        self::logInserted($db, $state);
        $builtUntil = (string)($state['built_until:' . $storeId] ?? '');
        // The changed days first: the reports read the built days, a stale one would be wrong rather than slow.
        if (!self::buildChanged($db, $storeId, $builtUntil, $deadline)) return false;
        $open = self::openDay();
        return $builtUntil >= $open || self::buildRange($db, $storeId, $builtUntil, $open, $deadline);
    }

    /** Queue the worker job that builds the rest, at most once in five minutes (it queues itself again until done). */
    private static function queueRest(PDO $db): void {
        if (!class_exists('JobQueue') || time() - (int)(self::state($db)['build_queued_at'] ?? 0) < 300) return;
        try {
            JobQueue::push(self::JOB, []);
            self::transaction($db, static fn() => self::setState($db, 'build_queued_at', (string)time()));
        } catch (Throwable $e) {
            // The report still answers; the next request builds further.
            Logger::warning('sales_daily.queue_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The worker job JOB: refreshAll() for JOB_SECONDS, then queued again until every store is built.
     */
    public static function handleJob(): void {
        self::refreshAll(false, self::JOB_SECONDS, $finished);
        if (!$finished) JobQueue::push(self::JOB, []);
    }

    /**
     * refresh() of every store that has orders, each in its own wall clock (the migration, cli/sales-daily.php, the
     * worker job); $rebuild forgets their built days first. $finished: whether all of it was built within $seconds.
     * @param-out bool $finished
     */
    public static function refreshAll(bool $rebuild = false, ?float $seconds = null, ?bool &$finished = null): bool {
        $finished = true;
        $db = Db::get();
        if (!self::available($db)) return false;
        $deadline = $seconds === null ? null : microtime(true) + $seconds;
        $stores = class_exists('Bootstrap') ? Bootstrap::stores() : [];
        $configs = is_array($stores['stores'] ?? null) ? $stores['stores'] : [];
        $default = $configs[$stores['default_store'] ?? ''] ?? (reset($configs) ?: []);
        if ($rebuild) {
            self::transaction($db, static function () use ($db): void {
                foreach (self::builtTables($db) as $table) $db->exec("DELETE FROM {$table}");
                $db->exec("DELETE FROM sales_daily_state WHERE state_key LIKE 'timezone:%' OR state_key LIKE 'built_until:%'");
            });
        }
        $previous = date_default_timezone_get();
        $first = true;
        try {
            foreach ($db->query('SELECT DISTINCT store_id FROM orders')->fetchAll(PDO::FETCH_COLUMN) as $storeId) {
                $storeId = (string)$storeId;
                $config = is_array($configs[$storeId] ?? null) ? $configs[$storeId] : $default;
                date_default_timezone_set(StoreContext::timezoneFor(is_array($config) ? $config : []));
                // At least one store per call, so every call gets further.
                if ($deadline !== null && !$first && microtime(true) >= $deadline) { $finished = false; break; }
                $first = false;
                if (!self::refreshStore($db, $storeId, $deadline)) $finished = false;
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
     * SalesReport::productGroups() of [$fromDay, $toDay), from the built days: whole months from sales_monthly_products,
     * the days at both ends from sales_daily_products. With $limit, per currency, every group of the $alsoProducts and
     * the $limit best sellers of the others (by revenue, quantity, product, variant, as topProducts() ranks them),
     * ranked in SQL: a large catalogue has tens of thousands of groups a year.
     * @param list<int> $alsoProducts
     * @return array<string, array{currency:string, product_id:int, variant_id:?int, line_id:int, quantity:int, revenue:float}>
     */
    public static function productGroups(string $storeId, string $fromDay, string $toDay, ?int $limit = null, array $alsoProducts = []): array {
        $db = Db::get();
        // Whole months [$firstMonth, $endMonth): every one of their days is before $toDay, so built.
        $firstMonth = substr($fromDay, 8, 2) === '01' ? substr($fromDay, 0, 7) : self::nextMonth(substr($fromDay, 0, 7));
        $endMonth = substr($toDay, 0, 7);
        if (!self::monthly($db) || $firstMonth >= $endMonth) {
            $firstMonth = $endMonth = '';
            $days = [$fromDay, $toDay, '', ''];
        } else {
            $days = [$fromDay, $firstMonth . '-01', $endMonth . '-01', $toDay];
        }
        // Grouped per part: the union then holds groups, not every product row of the year.
        $sum = 'SELECT currency, product_id, variant_key, MAX(variant_id) AS variant_id, MAX(line_id) AS line_id, SUM(quantity) AS quantity, SUM(revenue) AS revenue';
        $sql = "{$sum} FROM sales_daily_products WHERE store_id = :ds AND ((day >= :d1 AND day < :d2) OR (day >= :d3 AND day < :d4)) GROUP BY currency, product_id, variant_key";
        $params = [':ds' => $storeId, ':d1' => $days[0], ':d2' => $days[1], ':d3' => $days[2], ':d4' => $days[3]];
        if ($firstMonth !== '') {
            // Named placeholders: MySQL's native prepares bind each one once, so the month part gets its own.
            $sql .= " UNION ALL {$sum} FROM sales_monthly_products WHERE store_id = :ms AND month >= :m1 AND month < :m2 GROUP BY currency, product_id, variant_key";
            $params += [':ms' => $storeId, ':m1' => $firstMonth, ':m2' => $endMonth];
        }
        $sql = "{$sum} FROM ({$sql}) x GROUP BY currency, product_id, variant_key";
        $also = array_fill_keys(array_map('intval', $alsoProducts), true);
        if ($limit !== null) {
            // The $alsoProducts first (integers, inlined), then the best sellers; read until $limit others per currency.
            $sql .= ' ORDER BY currency, ' . ($also !== [] ? 'CASE WHEN product_id IN (' . implode(',', array_keys($also)) . ') THEN 0 ELSE 1 END, ' : '')
                . 'SUM(revenue) DESC, SUM(quantity) DESC, product_id, variant_key';
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        $others = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($limit !== null && !isset($also[(int)$row['product_id']])) {
                $currency = (string)$row['currency'];
                if (($others[$currency] ?? 0) >= $limit) continue;
                $others[$currency] = ($others[$currency] ?? 0) + 1;
            }
            $rows[] = $row;
        }
        $groups = [];
        foreach ($rows as $row) {
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
        return array_map('strval', $db->query('SELECT state_key, value FROM sales_daily_state')->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    private static function setState(PDO $db, string $name, string $value): void {
        $db->prepare('INSERT INTO sales_daily_state (state_key, value) VALUES (?, ?) ' . Db::upsertClause('state_key', 'value = excluded.value', 'value = VALUES(value)'))->execute([$name, $value]);
    }

    /** @return list<string> the tables of built figures and logged changes */
    private static function builtTables(PDO $db): array {
        return array_merge(['sales_daily', 'sales_daily_products', 'sales_daily_changes'], self::monthly($db) ? ['sales_monthly_products'] : []);
    }

    /**
     * Sum the built days of $storeId's $months ('Y-m') into sales_monthly_products (in the caller's transaction).
     * @param list<string> $months
     */
    private static function rollup(PDO $db, string $storeId, array $months): void {
        if (!self::monthly($db)) return;
        $delete = $db->prepare('DELETE FROM sales_monthly_products WHERE store_id = ? AND month = ?');
        $insert = $db->prepare('INSERT INTO sales_monthly_products (store_id, month, currency, product_id, variant_key, variant_id, line_id, quantity, revenue)
            SELECT store_id, ?, currency, product_id, variant_key, MAX(variant_id), MAX(line_id), SUM(quantity), SUM(revenue)
            FROM sales_daily_products WHERE store_id = ? AND day >= ? AND day < ? GROUP BY store_id, currency, product_id, variant_key');
        foreach (array_unique($months) as $month) {
            $delete->execute([$storeId, $month]);
            $insert->execute([$month, $storeId, $month . '-01', self::nextMonth($month) . '-01']);
        }
    }

    private static function nextMonth(string $month): string {
        return (new DateTimeImmutable($month . '-01'))->modify('+1 month')->format('Y-m');
    }

    /** Forget $storeId's built days (for PHP's current timezone): the next refresh() builds them all again. */
    private static function reset(PDO $db, string $storeId): void {
        self::transaction($db, static function () use ($db, $storeId): void {
            foreach (self::builtTables($db) as $table) $db->prepare("DELETE FROM {$table} WHERE store_id = ?")->execute([$storeId]);
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

    /**
     * Build every day of $storeId in [$fromDay, $toDay) that has orders ('' = from the first order), oldest first,
     * recording after each batch how far it got (built_until); whether it reached $toDay before $deadline.
     */
    private static function buildRange(PDO $db, string $storeId, string $fromDay, string $toDay, ?float $deadline): bool {
        $hours = $db->prepare('SELECT DISTINCT SUBSTR(created_at, 1, 13) FROM orders WHERE store_id = ? AND created_at >= ? AND created_at < ?');
        $hours->execute([$storeId, $fromDay !== '' ? self::dayStartUtc($fromDay) : '0000-00-00 00:00:00', self::dayStartUtc($toDay)]);
        $days = array_values(array_filter(self::daysOfHours($hours->fetchAll(PDO::FETCH_COLUMN)), static fn(string $day): bool => $day < $toDay && ($fromDay === '' || $day >= $fromDay)));
        if ($days === []) {
            self::transaction($db, static fn() => self::setState($db, 'built_until:' . $storeId, $toDay));
            return true;
        }
        return self::buildDays($db, $storeId, $days, $deadline, $toDay) === count($days);
    }

    /**
     * Build the built days (before $builtUntil) of $storeId's logged changes, oldest first, and delete the changes
     * they cover; whether all of them were built before $deadline. The changes are read per hour, not per row (400 000
     * changes after a bulk edit exhausted the memory), and only those logged before the build started are deleted (a
     * change committed meanwhile stays for the next refresh). A change of a day not built yet is dropped: that day is
     * built from its orders when its turn comes.
     */
    private static function buildChanged(PDO $db, string $storeId, string $builtUntil, ?float $deadline): bool {
        $max = $db->prepare('SELECT MAX(id) FROM sales_daily_changes WHERE store_id = ?');
        $max->execute([$storeId]);
        $maxId = (int)$max->fetchColumn();
        if ($maxId === 0) return true;
        $hours = $db->prepare('SELECT DISTINCT SUBSTR(created_at, 1, 13) FROM sales_daily_changes WHERE store_id = ? AND id <= ?');
        $hours->execute([$storeId, $maxId]);
        $days = array_values(array_filter(self::daysOfHours($hours->fetchAll(PDO::FETCH_COLUMN)), static fn(string $day): bool => $day < $builtUntil));
        $built = self::buildDays($db, $storeId, $days, $deadline, null);
        $delete = 'DELETE FROM sales_daily_changes WHERE store_id = ? AND id <= ?';
        if ($built === count($days)) {
            $db->prepare($delete)->execute([$storeId, $maxId]);
            return true;
        }
        // Built: the days before $days[$built]; not built yet at all: the days from $builtUntil.
        self::$staleFrom[$storeId] = $days[$built];
        $db->prepare($delete . ' AND (created_at < ? OR created_at >= ?)')->execute([$storeId, $maxId, self::dayStartUtc($days[$built]), self::dayStartUtc($builtUntil)]);
        return false;
    }

    /**
     * The local days of UTC hours ('Y-m-d H'), sorted.
     * @param list<mixed> $hours
     * @return list<string>
     */
    private static function daysOfHours(array $hours): array {
        $days = [];
        foreach ($hours as $hour) {
            // An hour may straddle two local days (a zone off by a half hour).
            $days[self::dayOf($hour . ':00:00')] = true;
            $days[self::dayOf($hour . ':59:59')] = true;
        }
        ksort($days);
        return array_map('strval', array_keys($days));
    }

    /**
     * Build $days in order, a month of them per transaction (one commit per day, a log flush each, took most of a
     * two-year build), until $deadline has passed (at least one day, so every call gets further). With $until (a
     * range build) each transaction also records built_until: the next day to build, or $until after the last.
     * Returns how many were built.
     * @param list<string> $days
     */
    private static function buildDays(PDO $db, string $storeId, array $days, ?float $deadline, ?string $until): int {
        $built = 0;
        $count = count($days);
        while ($built < $count && ($built === 0 || $deadline === null || microtime(true) < $deadline)) {
            self::transaction($db, static function () use ($db, $storeId, $days, $count, $deadline, $until, &$built): void {
                $months = [];
                $start = $built;
                do {
                    self::build($db, $storeId, $days[$built]);
                    $months[] = substr($days[$built], 0, 7);
                    $built++;
                } while ($built < $count && $built - $start < 31 && ($deadline === null || microtime(true) < $deadline));
                self::rollup($db, $storeId, $months);
                // A day without orders between two built ones has no rows: every day before the next one is built.
                if ($until !== null) self::setState($db, 'built_until:' . $storeId, $built < $count ? $days[$built] : $until);
            });
        }
        return $built;
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
            if (!Db::isSqlite()) $db->query("SELECT value FROM sales_daily_state WHERE state_key = 'lock' FOR UPDATE")->fetchAll();
            $work();
            $db->commit();
        } catch (Throwable $e) {
            if (Db::transactionOpen($db)) Db::rollBackOpen($db);
            throw $e;
        }
    }
}
