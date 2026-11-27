<?php
// php perf-dash.php <shopRoot> <dash|rep365|rep30|top365> : one admin "request" of the sales parts, in a fresh process
$root = $argv[1]; chdir($root); require $root . '/src/autoload.php';
Bootstrap::initStoreContext('/', '', 'default');
$what = $argv[2]; $store = 'default';
$b0 = SalesDaily::available() ? (string)(Db::get()->query("SELECT value FROM sales_daily_state WHERE state_key = 'built_until:default'")->fetchColumn() ?: '-') : 'n/a';
$s = hrtime(true);
$once = SalesReport::refreshOnce();
$r1 = microtime(true);
if ($what === 'dash') { $t = SalesReport::trend($store, 14); $tt = SalesReport::totals($store, SalesReport::sinceUtc(1)); }
elseif ($what === 'rep365' || $what === 'rep30') { $d = $what === 'rep365' ? 365 : 30; $t = SalesReport::trend($store, $d); $tot = SalesReport::totalsOf($t); $top = SalesReport::topProducts($store, SalesReport::sinceUtc($d), 10, true); }
elseif ($what === 'top365') { $top = SalesReport::topProducts($store, SalesReport::sinceUtc(365), 10, true); }
unset($once);
$ms = (hrtime(true) - $s) / 1e6;
$b1 = SalesDaily::available() ? (string)(Db::get()->query("SELECT value FROM sales_daily_state WHERE state_key = 'built_until:default'")->fetchColumn() ?: '-') : 'n/a';
$days = SalesDaily::available() ? (int)Db::get()->query("SELECT COUNT(*) FROM sales_daily")->fetchColumn() : 0;
$jobs = is_dir($cfgj = (require $root . '/config.php')['jobs_dir'] ?? '') ? count(glob($cfgj . '/*') ?: []) : -1;
printf("%-7s %7.0f ms  built_until %s -> %s  sales_daily rows=%d jobs=%d peak=%.0fMB\n", $what, $ms, $b0, $b1, $days, $jobs, memory_get_peak_usage() / 1048576);
