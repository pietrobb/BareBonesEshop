<?php
// R60: buildRange() a 31-day window at a time under a deadline: every refresh with a 0 s budget builds one window; after
// each one the report figures (totals 7..730 d, trend 400 d per day) are compared with the live SQL (revenueRows).
// Kolkata (+5:30) / Santiago (DST at midnight) stores; orders at local midnight edges, gaps of > 31 days without orders.
// php sd60_window.php driver target tz
require __DIR__ . '/lib50.php';
[, $driver, $target, $tz] = $argv; $phase = $argv[4] ?? 'gen';
$db = r50_boot($driver, $target, $phase === 'gen', ['stores.default.timezone' => $tz]);
r36_store('default');
date_default_timezone_set($tz);
if ($phase === 'gen') {
$cat = (int)$db->query("SELECT id FROM categories LIMIT 1")->fetchColumn();
if (!$cat) { $db->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'b', 'B', 'b', 0)"); $cat = (int)$db->lastInsertId(); }
$db->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', ?, 'WP', 'wp', 'WP', 1000, 0, 'active')")->execute([$cat]);
$pid = (int)$db->lastInsertId();
mt_srand(5); $now = time(); $ov = []; $iv = [];
$id = 0; $mk = function (string $created, string $st, string $ps, int $total) use (&$ov, &$iv, &$id, $pid) { $id++; array_push($ov, $id, "W-$id", $st, $ps, $total, $total, $created); array_push($iv, $id, $pid, 'WP', 'WP', 1, $total, $total); };
$statuses = [['delivered', 'paid'], ['paid', 'paid'], ['pending', 'pending'], ['cancelled', 'cancelled'], ['delivered', 'paid']];
for ($d = 730; $d >= 1; $d--) {
    if ($d > 300 && $d < 380) continue;                 // a gap of ~80 days without orders
    if ($d % 7 === 3) continue;                        // days without orders
    $day = date('Y-m-d', $now - $d * 86400);
    foreach (['00:00:00', '00:29:59', '00:30:00', '23:59:59', '12:00:00'] as $hm) {
        if (mt_rand(0, 2) === 0) continue;
        $utc = (new DateTimeImmutable("$day $hm", new DateTimeZone($tz)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        [$st, $ps] = $statuses[mt_rand(0, 4)]; $mk($utc, $st, $ps, mt_rand(100, 9000));
    }
}
foreach (array_chunk($ov, 7 * 500) as $c) $db->prepare("INSERT INTO orders (id, order_number, status, payment_status, customer_name, customer_email, shipping_addr, payment_method, subtotal, total, currency, store_id, locale, is_synthetic, created_at) VALUES "
    . implode(',', array_fill(0, intdiv(count($c), 7), "(?, ?, ?, ?, 'B', 'b@example.test', '{}', 'card', ?, ?, 'EUR', 'default', 'sk', 0, ?)")))->execute($c);
foreach (array_chunk($iv, 7 * 500) as $c) $db->prepare("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES " . implode(',', array_fill(0, intdiv(count($c), 7), '(?, ?, ?, ?, ?, ?, ?)')))->execute($c);
$db->exec("DELETE FROM sales_daily_state WHERE state_key LIKE 'built_until:%' OR state_key LIKE 'timezone:%'"); $db->exec('DELETE FROM sales_daily'); $db->exec('DELETE FROM sales_daily_products'); $db->exec('DELETE FROM sales_daily_changes');
if (Db::tableExists('sales_monthly_products')) $db->exec('DELETE FROM sales_monthly_products');
echo "orders: $id ($tz)\n"; exit; }
$now = time(); SalesDaily::$requestSeconds = 0.0;
echo "state before: ", json_encode($db->query("SELECT state_key, value FROM sales_daily_state")->fetchAll(PDO::FETCH_KEY_PAIR)), " changes=", $db->query('SELECT COUNT(*) FROM sales_daily_changes')->fetchColumn(), "\n";
$far = '9999-12-31 00:00:00'; $bad = 0; $calls = 0; $prev = '';
$compare = function (string $label) use (&$bad, $far) {
    foreach ([7, 30, 90, 365, 730] as $p) {
        $since = SalesReport::sinceUtc($p);
        $live = SalesReport::revenueRows('default', $since, $far)['eur'] ?? ['orders' => 0, 'revenue' => 0];
        $got = SalesReport::totals('default', $since)['eur'] ?? ['orders' => 0, 'revenue' => 0];
        if ((int)$live['orders'] !== (int)($got['orders'] ?? 0) || (int)$live['revenue'] !== (int)($got['revenue'] ?? 0)) { $bad++; if ($bad < 8) echo "  MISMATCH $label {$p}d report=", json_encode($got), " live=", json_encode($live), "\n"; }
    }
};
while (true) {
    // no worker job (the harness may run it inline): only report requests build
    $db->prepare(Db::isSqlite() ? "INSERT OR REPLACE INTO sales_daily_state (state_key, value) VALUES ('build_queued_at', ?)" : "REPLACE INTO sales_daily_state (state_key, value) VALUES ('build_queued_at', ?)")->execute([(string)time()]);
    SalesDaily::refresh('default', 0.0); $calls++;
    $bu = SalesDaily::builtUntil('default');
    $compare("call $calls built_until=$bu"); if (getenv("SDW_V")) echo "  call $calls built_until=$bu state=", json_encode(Db::get()->query("SELECT state_key, value FROM sales_daily_state")->fetchAll(PDO::FETCH_KEY_PAIR)), "\n";
    if ($bu === $prev && $bu >= SalesDaily::openDay()) break;
    if ($calls > 2000) { echo "  NO PROGRESS\n"; break; }
    $prev = $bu;
}
// per-day comparison of what was built
$nd = 0; $days = SalesDaily::days('default', '2000-01-01', SalesDaily::builtUntil('default'))['eur'] ?? [];
$liveDays = 0;
for ($d = 740; $d >= 0; $d--) {
    $day = date('Y-m-d', $now - $d * 86400); if ($day >= SalesDaily::builtUntil('default')) break;
    $l = SalesReport::revenueRows('default', SalesDaily::dayStartUtc($day), SalesDaily::dayStartUtc(SalesDaily::nextDay($day)))['eur'] ?? ['orders' => 0, 'revenue' => 0];
    $b = $days[$day] ?? ['orders' => 0, 'revenue' => 0];
    if ((int)$l['orders'] !== $b['orders'] || (int)$l['revenue'] !== $b['revenue']) { $nd++; if ($nd < 5) echo "  DAY $day built=", json_encode($b), " live=", json_encode($l), "\n"; }
}
echo "calls=$calls report mismatches=$bad built-day mismatches=$nd builtUntil=", SalesDaily::builtUntil('default'), " openDay=", SalesDaily::openDay(), "\n";
