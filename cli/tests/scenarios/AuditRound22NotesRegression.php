<?php

// Review round 22 (2026-11-07), medium (goal #358): the 2.1.3 upgrade notes said that 2.1.2 read "199.000" in a CZK
// file as 199 000 Kč. 2.1.2 read one separator always as the decimal mark ("199.000" = 199 Kč); digit grouping came
// with round 19, which was never released. A CZK/HUF export with three decimals worked in 2.1.2 and has its prices
// below 1 000 refused from 2.1.3; an export that meant thousands was stored 1 000 times too low. The notes must say
// so, and name the ERP sync change (integer cents only) and the new store_links header variable.

echo "\n--- R22: 2.1.3 upgrade notes describe 2.1.2 price reading truthfully ---\n";

$r22nRoot = dirname(TEST_CLI_DIR);
$r22nLog = (string)file_get_contents($r22nRoot . '/CHANGELOG.md');
$r22nStart = strpos($r22nLog, '### Upgrade notes (2.1.2 → 2.1.3)');
$r22nEnd = $r22nStart === false ? false : strpos($r22nLog, "\n### ", $r22nStart + 1);
$r22nNotes = $r22nStart === false ? '' : substr($r22nLog, $r22nStart, ($r22nEnd === false ? strlen($r22nLog) : $r22nEnd) - $r22nStart);
assert_true($r22nNotes !== '', 'R22: CHANGELOG has the 2.1.3 upgrade notes');

// 1. The 2.1.2 price reading, frozen from v2.1.2 src/Import.php (parsePriceToCents): with one kind of separator, one
//    occurrence is the decimal mark and a repeated one is digit grouping. The notes' claims are checked against it.
$r22nParse212 = static function (string $s): int {
    $lastComma = strrpos($s, ',');
    $lastDot = strrpos($s, '.');
    if ($lastComma !== false && $lastDot !== false) {
        $decimal = $lastComma > $lastDot ? ',' : '.';
        $s = str_replace($decimal === ',' ? '.' : ',', '', $s);
    } else {
        $decimal = $lastComma !== false ? ',' : '.';
        if (substr_count($s, $decimal) > 1) $s = str_replace($decimal, '', $s);
    }
    return (int)round((float)str_replace($decimal, '.', $s) * 100);
};
foreach (['199.000' => 19900, '12.990' => 1299, '2.000' => 200, '1299.000' => 129900, '1.299.000' => 129900000] as $r22nIn => $r22nCents) {
    assert_equals($r22nCents, $r22nParse212((string)$r22nIn), "R22: 2.1.2 read \"{$r22nIn}\" as {$r22nCents} cents");
}
assert_true(!str_contains($r22nNotes, 'became 199 000 Kč'), 'R22: the notes no longer say 2.1.2 read "199.000" as 199 000 Kč');
foreach ([
    '2.1.2 read a single separator always as the decimal mark', '"199.000" was 199,00', '"12.990" was 12,99',
    'An export with three decimals', 'prices below 1 000 are refused and stay frozen', '`"decimal": "."`',
    'a CSV\n    import has no such setting', 'An export that meant a separator as thousands', 'stored 1 000 times too low',
    '`"decimal": ","`',
] as $r22nNeedle) {
    $r22nNeedle = str_replace('\n', "\n", $r22nNeedle);
    assert_true(str_contains($r22nNotes, $r22nNeedle), 'R22: the 2.1.3 upgrade notes say ' . str_replace("\n", ' ', $r22nNeedle));
}

// 2. What the notes promise about 2.1.3 holds: the ambiguous value is refused, "decimal" reads it, and a value that
//    meant thousands is read as such with ",".
$r22nRefused = static function (callable $fn): bool {
    try { $fn(); return false; } catch (\InvalidArgumentException) { return true; }
};
foreach (['199.000', '12.990', '2.000'] as $r22nIn) {
    assert_true($r22nRefused(static fn() => Import::parsePriceToCents($r22nIn)), "R22: 2.1.3 refuses the ambiguous \"{$r22nIn}\"");
}
assert_equals(129900, Import::parsePriceToCents('1299.000'), 'R22: 2.1.3 still reads "1299.000" (prices from 1 000 up keep updating)');
assert_equals(19900, Import::parsePriceToCents('199.000', '.'), 'R22: "decimal": "." reads "199.000" as 199,00');
assert_equals(19900, Import::parsePriceToCents('199,000', ','), 'R22: "decimal": "," reads "199,000" as 199,00');
assert_equals(1299000, Import::parsePriceToCents('12.990', ','), 'R22: "decimal": "," reads "12.990" as 12 990');

// 3. ERP sync: only integer cents; the notes name the decimal numbers and signs that are now refused.
$r22nCents = new ReflectionMethod(Import::class, 'centsField');
$r22nCents->setAccessible(true);
assert_equals(1990, $r22nCents->invoke(null, 1990, 'price'), 'R22: ERP sync takes integer cents');
assert_equals(1990, $r22nCents->invoke(null, '1990', 'price'), 'R22: ERP sync takes a string of digits');
foreach ([1990.0, 19.90, '+1250', '19.90'] as $r22nIn) {
    assert_true($r22nRefused(static fn() => $r22nCents->invoke(null, $r22nIn, 'price')),
        'R22: ERP sync refuses ' . var_export($r22nIn, true));
}
foreach (['ERP product sync takes only integer cents', '`1990.0`', '`"+1250"`', 'updates no product after the update'] as $r22nNeedle) {
    assert_true(str_contains($r22nNotes, $r22nNeedle), "R22: the 2.1.3 upgrade notes cover {$r22nNeedle}");
}

// 4. Custom themes: the notes name store_links, and the shipped headers render it.
foreach (['`store_links`', 'loses the navigation between stores', 'themes/default/blocks/storefront/header.html'] as $r22nNeedle) {
    assert_true(str_contains($r22nNotes, $r22nNeedle), "R22: the 2.1.3 upgrade notes cover {$r22nNeedle}");
}
foreach (['default', 'bookshop'] as $r22nTheme) {
    $r22nHeader = (string)file_get_contents($r22nRoot . "/themes/{$r22nTheme}/blocks/storefront/header.html");
    assert_true(str_contains($r22nHeader, '{{#each store_links}}'), "R22: the {$r22nTheme} header renders store_links");
}
