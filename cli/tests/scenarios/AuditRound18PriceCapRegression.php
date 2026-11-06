<?php

// Review round 18 (2026-11-06), medium "price overflow": 184467440737095516.16 from a feed or CSV became 0 and the
// product was imported active for free; the same for admin amounts. Low: admin amounts had no upper bound and
// "5..0" was accepted as 50.00.
echo "\n--- R18: prices are bounded and thousands separators group three digits ---\n";
$r18pParse = static function (mixed $value): string {
    try {
        return (string)Import::parsePriceToCents($value);
    } catch (InvalidArgumentException $e) {
        return 'refused';
    }
};
assert_equals(['refused', 'refused', 'refused', 'refused', (string)Import::MAX_CENTS, 'refused'], [
    $r18pParse('184467440737095516.16'), $r18pParse('99999999999999999999'), $r18pParse('-184467440737095516.16'),
    $r18pParse('20000000.01'), $r18pParse('20000000.00'), $r18pParse(Import::MAX_CENTS + 1),
], 'R18 price: an amount beyond Import::MAX_CENTS (or one that overflows a float to int cast) is refused, never 0');
assert_equals(['refused', 'refused', 'refused', 'refused', '1234567800', '129900', '129900', '129900', '1990', '5000'], [
    $r18pParse('5..0'), $r18pParse('1.29,00'), $r18pParse('1234.567,89'), $r18pParse('12,34,567'),
    $r18pParse('12,345,678'), $r18pParse('1.299,00'), $r18pParse('1,299.00'), $r18pParse('1 299,- Kč'), $r18pParse('19.90'), $r18pParse('50'),
], 'R18 price: a thousands separator must group three digits ("5..0" is not 50.00); valid groupings still parse');
assert_equals([null, null, null, 5000, -1], [
    Money::adminInput('5..0', 'eur'), Money::adminInput('184467440737095516.16', 'eur'), Money::adminInput('-184467440737095516.16', 'eur', 0, true),
    Money::adminInput('50', 'eur'), Money::parseInput('99999999999', 'eur'),
], 'R18 price: admin money fields refuse "5..0" and amounts beyond the bound (were 50.00 and 0)');

// The CSV import refuses the row instead of creating an active product at 0.
$r18pDb = Db::get();
$r18pRoot = test_fixture_path('r18-price');
mkdir($r18pRoot);
try {
    file_put_contents($r18pRoot . '/overflow.csv', "sku,name,price,status\nR18P-OVER,Overflow,184467440737095516.16,active\nR18P-OK,Fine,12.50,active\n");
    $r18pResult = Import::csv($r18pRoot . '/overflow.csv');
    $r18pPrice = static function (string $sku) use ($r18pDb): ?int {
        $stmt = $r18pDb->prepare('SELECT price FROM products WHERE sku = ?');
        $stmt->execute([$sku]);
        $price = $stmt->fetchColumn();
        return $price === false ? null : (int)$price;
    };
    assert_equals([null, 1250, 1], [$r18pPrice('R18P-OVER'), $r18pPrice('R18P-OK'),
        count(array_filter($r18pResult['errors'], static fn(string $e): bool => str_contains($e, 'R18P-OVER')))],
        'R18 price: a CSV row with an overflowing price is refused with an error (was an active product at 0)');
    // An existing product keeps its price when a later row overflows.
    file_put_contents($r18pRoot . '/update.csv', "sku,name,price\nR18P-OK,Fine,184467440737095516.16\n");
    Import::csv($r18pRoot . '/update.csv');
    assert_equals(1250, $r18pPrice('R18P-OK'), 'R18 price: an overflowing price does not overwrite an existing product\'s price');
} finally {
    $r18pDb->exec("DELETE FROM products WHERE sku LIKE 'R18P-%'");
    foreach (glob($r18pRoot . '/*') ?: [] as $r18pFile) @unlink($r18pFile);
    @rmdir($r18pRoot);
}
