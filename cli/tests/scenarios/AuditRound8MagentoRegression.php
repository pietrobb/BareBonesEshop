<?php

// Round 8 review (2026-10-21), unfixed from round 7: Magento 1.9 migration.
// - Categories were created from the path of their URL keys, so each was named after its URL key ("sauny") instead of
//   its name ("Sauny"); only translations were written afterwards.
// - special_price was applied without special_from_date / special_to_date: an expired sale became the permanent price
//   (with the regular price shown as a struck-through "original"), a future one started at once.
echo "\n--- Audit round 8 (rest): Magento migration category names and special price dates ---\n";
$r8mRoot = test_fixture_path('r8-magento');
mkdir($r8mRoot);
try {
    $r8mProducts = ['MG-EXPIRED' => ['2019-01-01 00:00:00', '2020-01-31 00:00:00'], 'MG-ACTIVE' => ['2020-01-01 00:00:00', null],
        'MG-FUTURE' => ['2099-01-01 00:00:00', null], 'MG-NODATE' => [null, null], 'MG-UNTIL' => [null, '2099-12-31 00:00:00']];
    $r8mLines = ['-- Magento 1.9 dump'];
    $r8mId = 0;
    foreach ($r8mProducts as $r8mSku => [$r8mFrom, $r8mTo]) {
        $r8mId++;
        $r8mLines[] = "INSERT INTO `catalog_product_entity` VALUES ({$r8mId},4,4,'simple','{$r8mSku}',0,0,'2020-01-01 00:00:00','2020-01-01 00:00:00');";
        $r8mLines[] = "INSERT INTO `catalog_product_entity_varchar` VALUES (" . ($r8mId * 10) . ",4,71,0,{$r8mId},'Sauna {$r8mSku}'),(" . ($r8mId * 10 + 1) . ",4,97,0,{$r8mId},'" . strtolower($r8mSku) . "');";
        $r8mLines[] = "INSERT INTO `catalog_product_entity_decimal` VALUES (" . ($r8mId * 10) . ",4,75,0,{$r8mId},'100.0000'),(" . ($r8mId * 10 + 1) . ",4,76,0,{$r8mId},'80.0000');";
        $r8mLines[] = "INSERT INTO `catalog_product_entity_int` VALUES ({$r8mId},4,96,0,{$r8mId},1);";
        $r8mDates = [];
        if ($r8mFrom !== null) $r8mDates[] = '(' . ($r8mId * 10) . ",4,77,0,{$r8mId},'{$r8mFrom}')";
        if ($r8mTo !== null) $r8mDates[] = '(' . ($r8mId * 10 + 1) . ",4,78,0,{$r8mId},'{$r8mTo}')";
        if ($r8mDates !== []) $r8mLines[] = 'INSERT INTO `catalog_product_entity_datetime` VALUES ' . implode(',', $r8mDates) . ';';
        $r8mLines[] = "INSERT INTO `catalog_category_product` VALUES (4,{$r8mId},1);";
    }
    $r8mLines[] = "INSERT INTO `catalog_category_entity` VALUES (1,3,0,0,'2020-01-01 00:00:00','2020-01-01 00:00:00','1',0,0,3),"
        . "(2,3,3,1,'2020-01-01 00:00:00','2020-01-01 00:00:00','1/2',1,1,2),(3,3,3,2,'2020-01-01 00:00:00','2020-01-01 00:00:00','1/2/3',1,2,1),"
        . "(4,3,3,3,'2020-01-01 00:00:00','2020-01-01 00:00:00','1/2/3/4',1,3,0);";
    $r8mLines[] = "INSERT INTO `catalog_category_entity_varchar` VALUES (1,3,41,0,3,'Sauny'),(2,3,117,0,3,'sauny'),(3,3,41,2,3,'Saunas'),"
        . "(4,3,41,0,4,'Fínske sauny'),(5,3,117,0,4,'finske-sauny');";
    file_put_contents($r8mRoot . '/dump.sql', implode("\n", $r8mLines) . "\n");
    file_put_contents($r8mRoot . '/run.php', '<?php
const R8M_ROOT = ' . var_export(dirname(TEST_CLI_DIR), true) . ';
const R8M_DB = ' . var_export($r8mRoot . '/shop.sqlite', true) . ';
require R8M_ROOT . "/src/autoload.php";
@unlink(R8M_DB);
$db = new PDO("sqlite:" . R8M_DB, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new Migrator($db, R8M_ROOT . "/migrations", R8M_DB . ".lock"))->apply(static fn($m) => null);
Db::init($db, "sqlite");
register_shutdown_function(static function () use ($db): void {
    $prices = [];
    foreach ($db->query("SELECT sku, price, compare_price FROM products ORDER BY sku") as $p) $prices[$p["sku"]] = [(int)$p["price"], $p["compare_price"] === null ? null : (int)$p["compare_price"]];
    $categories = [];
    foreach ($db->query("SELECT slug, name, translations FROM categories WHERE slug <> \'uncategorized\' ORDER BY depth") as $c) $categories[$c["slug"]] = [$c["name"], json_decode((string)$c["translations"], true)["en"]["name"] ?? null];
    echo "\nRESULT=" . json_encode(["prices" => $prices, "categories" => $categories], JSON_UNESCAPED_UNICODE) . "\n";
});
require R8M_ROOT . "/cli/migrate-magento.php";
');
    $r8mProcess = proc_open([PHP_BINARY, $r8mRoot . '/run.php', '--dump=' . $r8mRoot . '/dump.sql'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r8mPipes);
    fclose($r8mPipes[0]);
    $r8mOut = stream_get_contents($r8mPipes[1]) . stream_get_contents($r8mPipes[2]);
    fclose($r8mPipes[1]);
    fclose($r8mPipes[2]);
    $r8mCode = proc_close($r8mProcess);
    $r8mResult = preg_match('/RESULT=(.*)$/m', $r8mOut, $r8mM) ? json_decode($r8mM[1], true) : null;
    assert_true($r8mCode === 0 && is_array($r8mResult), 'R8 Magento: the migration ran (' . $r8mCode . ': ' . substr($r8mOut, -400) . ')');

    assert_equals(['sauny' => ['Sauny', 'Saunas'], 'finske-sauny' => ['Fínske sauny', null]], $r8mResult['categories'] ?? null,
        'R8 Magento: categories keep their names, not their URL keys (were "sauny", "finske-sauny")');
    assert_equals(['MG-ACTIVE' => [8000, 10000], 'MG-EXPIRED' => [10000, null], 'MG-FUTURE' => [10000, null], 'MG-NODATE' => [8000, 10000], 'MG-UNTIL' => [8000, 10000]],
        $r8mResult['prices'] ?? null,
        'R8 Magento: a special price applies only within its from/to dates - an expired or future one keeps the regular price without a struck-through original');
    assert_true(str_contains($r8mOut, 'MG-FUTURE: special price 80.00 from 2099-01-01 not applied'), 'R8 Magento: a special price that starts later is reported so it can be scheduled as a promotion');
} finally {
    foreach (glob($r8mRoot . '/*') ?: [] as $r8mFile) @unlink($r8mFile);
    @rmdir($r8mRoot);
}
