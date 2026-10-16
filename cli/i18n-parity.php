<?php
require_once __DIR__ . '/cli-only.php';

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
$catalogs = [];
foreach ($files as $f) {
    $code = basename($f, '.php');
    $arr = require $f;
    if (!is_array($arr)) {
        fwrite(STDERR, "ERROR: {$f} did not return an array\n");
        exit(1);
    }
    $locales[$code] = array_keys($arr);
    $catalogs[$code] = $arr;
}

// Every catalog is array_replace(en.php, overrides), so key parity alone always passes. A locale is
// only complete when its values are translated: count word-like values still identical to English
// (brand names, "IBAN", "Email" ... legitimately stay, hence the small budget).
const I18N_ENGLISH_FALLBACK_BUDGET = 0.05;
$untranslated = [];
foreach ($catalogs as $code => $catalog) {
    if ($code === 'en' || !isset($catalogs['en'])) continue;
    $same = 0;
    foreach ($catalog as $key => $value) {
        if (is_string($value) && ($catalogs['en'][$key] ?? null) === $value && preg_match('/\p{L}{3}/u', $value)) $same++;
    }
    $untranslated[$code] = $same;
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
foreach ($untranslated as $code => $same) {
    $share = $same / max(1, count($catalogs[$code]));
    $ok = $share <= I18N_ENGLISH_FALLBACK_BUDGET;
    if (!$ok) $failed = true;
    echo sprintf("  [%s] %s: %d values still English (%.1f%%, budget %d%%)\n", $ok ? 'OK' : 'FAIL', $code, $same, $share * 100, I18N_ENGLISH_FALLBACK_BUDGET * 100);
}

if ($failed) {
    echo "\nFAIL: parity broken\n";
    exit(1);
}

echo "\nAll locales are in parity.\n";
exit(0);
