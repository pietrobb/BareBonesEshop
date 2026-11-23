<?php

// Review round 21 (R56), lows and UX:
//  - a name of invisible characters alone (U+200B, U+00A0, U+FEFF, U+2028) passed in API v1, the product editor and reviews.
//  - the doctor passed operations.worker_cron "0 0 31 2 *" (never runs), "@daily" and "* * * * * echo worker.php"; the
//    worker runs every minute, so a rarer schedule now warns.
//  - cli/seed.php: --help seeded 1000 products; a second run without --fresh failed on a foreign key; attribute values
//    stayed Slovak.
//  - the web installer in de/EUR or en/USD kept the Slovak sample shipping and payment methods ("Osobný odber",
//    "Domestic SK"), and German orders above 5 kg found no carrier.
//  - the docblock of PaymentService::closeRefundedOrder() sat above another method.
echo "\n--- Audit round 56: invisible names, worker cron, seeder, installer outside Slovakia ---\n";

$pdo ??= test_commerce_fixture();
$r56lCall = static function (string $class, string $method, array $args) {
    $m = new ReflectionMethod($class, $method);
    $m->setAccessible(true);
    return $m->invokeArgs(null, $args);
};
$r56lRepo = dirname(TEST_CLI_DIR);
$r56lInvisible = ["\u{200B}", "\u{00A0}", "\u{FEFF}", "\u{2028}", "\u{200B}\u{00A0}\u{FEFF}\u{2028}"];

// --- Invisible names -------------------------------------------------------------------------------------------------------
assert_equals(array_fill(0, 5, ''), array_map([Security::class, 'typedText'], $r56lInvisible),
    'R56 typed text: invisible characters alone are empty (was: kept, a blank name)');
assert_equals(['Ján', "Ján\u{00A0}Novák"], [Security::typedText("\u{FEFF}\u{00A0}Ján\u{200B}\u{2028}"), Security::typedText("Ján\u{00A0}Novák")],
    'R56 typed text: invisible characters are trimmed at the ends only (a no-break space inside a name stays)');
$r56lApi = static function (string $method, string $name) use ($r56lCall): string {
    try {
        $args = $method === 'patchProduct' ? [1, ['name' => $name], 'r56l-key', [], '/api/v1/products/1']
            : [['sku' => 'R56L-API', 'name' => $name, 'price' => 100], 'r56l-key', [], '/api/v1/products'];
        $r56lResult = $r56lCall(ApiV1::class, $method, $args);
        return 'accepted';
    } catch (InvalidArgumentException $e) {
        return 'refused';
    } catch (Throwable $e) {
        return 'past validation';
    }
};
$r56lApiResults = [];
foreach ($r56lInvisible as $r56lName) $r56lApiResults[] = [$r56lApi('patchProduct', $r56lName), $r56lApi('createProduct', $r56lName)];
assert_equals(array_fill(0, 5, ['refused', 'refused']), $r56lApiResults, 'R56 API: a product name of invisible characters alone is refused on PATCH and create (was: saved blank)');
$r56lEditor = [];
foreach ($r56lInvisible as $r56lName) {
    $r56lEditor[] = is_string(ProductEditor::validate(['name' => $r56lName, 'slug' => 'r56l-x'] + array_fill_keys(['sku', 'price', 'images', 'gtin', 'mpn', 'seo_title', 'meta_description', 'category_id', 'brand_id'], ''), [], 0, null));
}
assert_equals(array_fill(0, 5, true), $r56lEditor, 'R56 product editor: a name of invisible characters alone is refused');
$r56lProduct = (int)$pdo->query("SELECT id FROM products WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
$r56lReviews = [];
foreach ($r56lInvisible as $r56lName) $r56lReviews[] = Reviews::validationError($r56lProduct, ['rating' => 5, 'author_name' => $r56lName, 'body' => 'Výborný produkt, odporúčam.']);
assert_equals(array_fill(0, 5, 'author_required'), $r56lReviews, 'R56 reviews: an author name of invisible characters alone is refused');

// --- Doctor: the worker runs every minute ---------------------------------------------------------------------------------
$r56lCron = static fn(string $cron): array => [(bool)$r56lCall(Setup::class, 'workerCronValid', [$cron]),
    method_exists(Setup::class, 'workerCronEveryMinute') ? (bool)$r56lCall(Setup::class, 'workerCronEveryMinute', [$cron]) : null];
assert_equals([[false, false], [false, false], [false, false], [true, false], [true, false], [true, false], [true, true], [true, true], [true, true], [true, true]], [
    $r56lCron('0 0 31 2 *'),
    $r56lCron('* * * * * echo worker.php'),
    $r56lCron('* * 30 2 * php /srv/shop/cli/worker.php'),
    $r56lCron('@daily'),
    $r56lCron('*/5 * * * * php /srv/shop/cli/worker.php'),
    $r56lCron('* 8-18 * * mon-fri'),
    $r56lCron('* * * * *'),
    $r56lCron('* * * * * php /srv/shop/cli/worker.php'),
    $r56lCron('* * * * * cd /srv/shop && /usr/bin/php8.3 -d memory_limit=256M cli/worker.php --max-jobs=50'),
    $r56lCron('0-59 */1 * * 0-7'),
], 'R56 doctor: a schedule that never runs or a line not running worker.php through PHP is invalid; only every minute is every minute');
$r56lOps = static function (string $cron, bool $web) use ($r56lCall): array {
    $found = [];
    $r56lCall(Setup::class, 'operationsChecks', [['operations' => ['worker_cron' => $cron, 'web_worker' => $web]], dirname(TEST_CLI_DIR),
        static function (string $status, string $id, string $message) use (&$found): void { if ($id === 'operations.cron') $found = [$status, $message]; }]);
    return $found;
};
$r56lDaily = $r56lOps('@daily php /srv/shop/cli/worker.php', false);
assert_true($r56lDaily[0] === 'warn' && str_contains($r56lDaily[1], 'less often'), 'R56 doctor: @daily warns that the worker runs less often than every minute: ' . json_encode($r56lDaily));
assert_equals('pass', $r56lOps('* * * * * php /srv/shop/cli/worker.php', false)[0] ?? null, 'R56 doctor: the every-minute crontab line passes');

// --- Seeder ---------------------------------------------------------------------------------------------------------------
$r56lSeed = (string)file_get_contents($r56lRepo . '/cli/seed.php');
$r56lHelpAt = strpos($r56lSeed, "isset(\$args['help'])");
assert_true($r56lHelpAt !== false && $r56lHelpAt < (int)strpos($r56lSeed, 'Db::get()') && str_contains($r56lSeed, 'Unknown option'),
    'R56 seed: --help (and an unknown option) prints the usage before the database is opened (was: 1000 products seeded)');
assert_true(str_contains($r56lSeed, "SELECT id FROM categories WHERE store_id = 'default' AND path = :path") && str_contains($r56lSeed, '$offset + 1'),
    'R56 seed: a run without --fresh reuses the categories by path and numbers its products after the earlier ones (was: FK failure)');
assert_true(str_contains($r56lSeed, '$colorNames[$color][$seedBase]') && str_contains($r56lSeed, '$materialNames[$material][$seedBase]')
    && str_contains($r56lSeed, 'Import::buildFilterTagsFromAttrs($attrs)'), 'R56 seed: attribute values in the shop\'s language (was: "cierna", "bavlna")');

// --- Installer outside Slovakia ---------------------------------------------------------------------------------------------
$r56lRoot = test_fixture_path('r56-install-abroad');
mkdir($r56lRoot);
file_put_contents($r56lRoot . '/child.php', <<<'PHP'
<?php
[, $root, $locale, $country, $currency] = $argv;
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . __DIR__ . "/shop-{$locale}.sqlite");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
(new Migrator($db, $root . '/migrations', __DIR__ . "/migrate-{$locale}.lock"))->apply();
Bootstrap::initStoreContext('/', '', 'default');
$stores = ['default_store' => 'default', 'stores' => ['default' => ['currency' => $currency, 'provider_scope' => 'default', 'default_locale' => $locale,
    'legal' => ['country' => $country]]]];
WebInstaller::priceInStoreCurrency($db, $stores);
if (method_exists('WebInstaller', 'localizeSampleMethods')) WebInstaller::localizeSampleMethods($db, $stores);
$col = static fn(string $sql): array => $db->query($sql)->fetchAll(PDO::FETCH_COLUMN);
$zone = $col("SELECT zone_code FROM shipping_zone_countries WHERE store_id = 'default' AND country = " . $db->quote($country))[0] ?? null;
$out = [
    'pickup' => $col("SELECT name FROM shipping_methods WHERE store_id = 'default' AND code = 'pickup'")[0] ?? null,
    'cod' => $col("SELECT name FROM payment_methods WHERE store_id = 'default' AND code = 'cod'")[0] ?? null,
    'cod_translations' => json_decode((string)($col("SELECT translations FROM payment_methods WHERE store_id = 'default' AND code = 'cod'")[0] ?? ''), true),
    'domestic' => $col("SELECT country FROM shipping_zone_countries WHERE store_id = 'default' AND zone_code = 'domestic'"),
    'domestic_name' => $col("SELECT name FROM shipping_zones WHERE store_id = 'default' AND code = 'domestic'")[0] ?? null,
    'near_eu_name' => $col("SELECT name FROM shipping_zones WHERE store_id = 'default' AND code = 'near_eu'")[0] ?? null,
    'eu_name' => $col("SELECT name FROM shipping_zones WHERE store_id = 'default' AND code = 'eu'")[0] ?? null,
    'eu_desc' => $col("SELECT description FROM shipping_zones WHERE store_id = 'default' AND code = 'eu'")[0] ?? null,
    'sk_zone' => $col("SELECT zone_code FROM shipping_zone_countries WHERE store_id = 'default' AND country = 'SK'"),
    'heavy' => (int)($col("SELECT COUNT(*) FROM shipping_rates WHERE store_id = 'default' AND active = 1 AND zone_code = " . $db->quote((string)$zone)
        . " AND country IN ('', " . $db->quote($country) . ") AND weight_from_g <= 8000 AND weight_to_g >= 8000")[0] ?? 0),
    'cod_countries' => $col("SELECT country FROM payment_method_countries WHERE store_id = 'default' AND method_code = 'cod' ORDER BY country"),
];
echo 'r56l ' . json_encode($out, JSON_UNESCAPED_UNICODE) . "\n";
PHP);
$r56lInstall = static function (string $locale, string $country, string $currency) use ($r56lRoot, $r56lRepo): array {
    $out = [];
    exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, $r56lRoot . '/child.php', $r56lRepo, $locale, $country, $currency])) . ' 2>&1', $out);
    foreach ($out as $line) {
        if (str_starts_with($line, 'r56l ')) return (array)json_decode(substr($line, 5), true);
    }
    return ['error' => substr(implode(' | ', $out), 0, 400)];
};
$r56lDe = $r56lInstall('de', 'DE', 'eur');
assert_equals(['Selbstabholung', 'Nachnahme', 'Dobierka', 'Cash on delivery'],
    [$r56lDe['pickup'] ?? null, $r56lDe['cod'] ?? null, $r56lDe['cod_translations']['sk']['name'] ?? null, $r56lDe['cod_translations']['en']['name'] ?? null],
    'R56 install de/EUR: the sample methods are named in German, the other languages as translations (was: "Osobný odber", "Dobierka"): ' . json_encode($r56lDe, JSON_UNESCAPED_UNICODE));
// R57 (review round 22, installer): the sample zones are named in the shop language (were English: "Domestic DE", "Nearby EU").
assert_equals([['DE'], 'Inland DE', ['eu']], [$r56lDe['domestic'] ?? null, $r56lDe['domestic_name'] ?? null, $r56lDe['sk_zone'] ?? null],
    'R56 install de/EUR: Germany is the domestic zone ("Inland DE" since R57) and Slovakia moves to the EU zone (was: "Domestic SK")');
assert_equals(['Nahe EU', 'Europäische Union', 'Übrige EU-Länder'], [$r56lDe['near_eu_name'] ?? null, $r56lDe['eu_name'] ?? null, $r56lDe['eu_desc'] ?? null],
    'R57 install de/EUR: the sample zones are named in German (was: "Nearby EU", "European Union", "Fallback EU shipping zone")');
assert_true(($r56lDe['heavy'] ?? 0) > 0 && in_array('DE', (array)($r56lDe['cod_countries'] ?? []), true),
    'R56 install de/EUR: an 8 kg German order finds a carrier and cash on delivery is offered in Germany (was: GLS up to 5 kg only, COD SK/CZ)');
$r56lEn = $r56lInstall('en', 'US', 'usd');
assert_equals(['In-store pickup', 'Cash on delivery', ['US'], 'Dobierka'],
    [$r56lEn['pickup'] ?? null, $r56lEn['cod'] ?? null, $r56lEn['domestic'] ?? null, $r56lEn['cod_translations']['sk']['name'] ?? null],
    'R56 install en/USD: English method names and the seller\'s country as the domestic zone: ' . json_encode($r56lEn, JSON_UNESCAPED_UNICODE));
$r56lSk = $r56lInstall('sk', 'SK', 'eur');
assert_equals(['Osobný odber', ['SK'], 'Tuzemsko SK'], [$r56lSk['pickup'] ?? null, $r56lSk['domestic'] ?? null, $r56lSk['domestic_name'] ?? null],
    'R56 install sk/EUR: the Slovak sample methods stay as seeded (the domestic zone named in Slovak since R57)');
assert_true(str_contains((string)file_get_contents($r56lRepo . '/src/WebInstaller.php'), 'if ($starter === null) self::localizeSampleMethods(Db::get(), $stores);'),
    'R56 install: a plain web install localizes the sample methods (a demo starter keeps its own)');

// --- Docblock ---------------------------------------------------------------------------------------------------------------
$r56lPay = (string)file_get_contents($r56lRepo . '/src/PaymentService.php');
assert_true(preg_match('~stayed paid and could still be shipped\.\s*\*/\s*public static function closeRefundedOrder~', $r56lPay) === 1,
    'R56 docs: the closeRefundedOrder() docblock sits on closeRefundedOrder(), not on closeReleasedOrder()');
