<?php
/**
 * i18n Parity Check
 * Verifies that all lang/{locale}.php files have identical keys.
 * Exits non-zero on any missing key, so it can gate CI / pre-commit.
 *
 * Usage:  php cli/i18n-parity.php
 */

$langDir = __DIR__ . '/../lang';
$files = glob($langDir . '/*.php');
if (!$files) {
    fwrite(STDERR, "ERROR: no lang files found in {$langDir}\n");
    exit(1);
}

$locales = [];
foreach ($files as $f) {
    $code = basename($f, '.php');
    $arr = require $f;
    if (!is_array($arr)) {
        fwrite(STDERR, "ERROR: {$f} did not return an array\n");
        exit(1);
    }
    $locales[$code] = array_keys($arr);
}

if (empty($locales)) {
    fwrite(STDERR, "ERROR: no parseable locale files\n");
    exit(1);
}

// Union of all keys across locales
$all = [];
foreach ($locales as $keys) {
    foreach ($keys as $k) $all[$k] = true;
}
$universe = array_keys($all);
sort($universe);

// Per-locale missing keys
$failed = false;
$report = [];
foreach ($locales as $code => $keys) {
    $missing = array_values(array_diff($universe, $keys));
    $extra   = array_values(array_diff($keys, $universe));
    $report[$code] = ['count' => count($keys), 'missing' => $missing, 'extra' => $extra];
    if ($missing) $failed = true;
}

// Output
echo "i18n parity report\n";
echo str_repeat('=', 40) . "\n";
echo "Universe: " . count($universe) . " unique keys across " . count($locales) . " locales\n\n";
foreach ($report as $code => $r) {
    $status = empty($r['missing']) ? 'OK' : 'FAIL';
    echo sprintf("  [%s] %s: %d keys", $status, $code, $r['count']);
    if ($r['missing']) echo ", missing: " . implode(', ', $r['missing']);
    echo "\n";
}

if ($failed) {
    echo "\nFAIL: parity broken\n";
    exit(1);
}

echo "\nAll locales are in parity.\n";
exit(0);
