<?php
/**
 * Review round 23, UX currencies and documentation: the README said unpriced products stay in the catalog currency
 * (they are not for sale), admin.promo.hint asked for amounts in cents in every language (they are typed in the
 * currency), and the doctor sent merchants to a non-existent Admin → Pricing section.
 * The seed prices (AuditInstallSmokeRegression) and the publish warning (AuditRound9OnboardingRegression) are tested there.
 */
echo "\n--- R23 UX currencies and docs ---\n";

$r23cRoot = dirname(TEST_CLI_DIR);
$r23cReadme = preg_replace('/\s+/', ' ', (string)file_get_contents($r23cRoot . '/README.md'));
assert_true(!str_contains($r23cReadme, 'unpriced products stay in the catalog currency') && str_contains($r23cReadme, "A product without a price in the store's currency is not for sale in that store"),
    'R23 docs: README says a product without a price in the store currency is not for sale there');

$r23cCents = ['en' => '/\bcents\b/', 'sk' => '/\bcentoch\b/u', 'cs' => '/\bcentech\b/u', 'de' => '/\bCent\b/', 'es' => '/céntimos/u', 'hu' => '/\bcentben\b/u', 'pl' => '/groszach/u'];
foreach ($r23cCents as $r23cLang => $r23cWord) {
    $r23cHint = I18n::tIn('admin.promo.hint', $r23cLang);
    assert_true($r23cHint !== 'admin.promo.hint' && !preg_match($r23cWord, $r23cHint) && preg_match('/4[.,]90/', $r23cHint) === 1,
        "R23 docs: admin.promo.hint ({$r23cLang}) asks for amounts in the currency, not in cents: {$r23cHint}");
    $r23cUnpriced = I18n::tIn('admin.stores.unpriced', $r23cLang);
    assert_true($r23cUnpriced !== 'admin.stores.unpriced' && str_contains($r23cUnpriced, '{currency}') && str_contains($r23cUnpriced, '{count}')
        && str_contains($r23cUnpriced, I18n::tIn('admin.nav.price_lists', $r23cLang)), "R23 UX: the publish warning is translated ({$r23cLang}) and names the Price lists menu");
}

$r23cDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r23cDb->exec("CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, store_id TEXT, status TEXT, currency TEXT)");
$r23cDb->exec("CREATE TABLE variants (id INTEGER PRIMARY KEY, product_id INTEGER, sku TEXT, price_delta INTEGER, price_absolute INTEGER)");
$r23cDb->exec("CREATE TABLE price_lists (id INTEGER PRIMARY KEY, store_id TEXT, currency TEXT, active INTEGER, customer_group_id INTEGER, valid_from TEXT, valid_to TEXT)");
$r23cDb->exec("CREATE TABLE price_list_prices (price_list_id INTEGER, product_id INTEGER, variant_id INTEGER, min_qty INTEGER, price INTEGER)");
$r23cDb->exec("INSERT INTO products VALUES (1, 'R23C-1', 'default', 'active', 'eur')");
$r23cCheck = Setup::storeCurrencyCheck($r23cDb, 'cz', ['currency' => 'czk', 'catalog_scope' => 'default']);
assert_true($r23cCheck['status'] === 'warn' && !str_contains($r23cCheck['action'], 'Pricing →') && str_contains($r23cCheck['action'], 'Admin → Price lists'),
    'R23 docs: the doctor sends the merchant to Admin → Price lists (there is no Admin → Pricing): ' . $r23cCheck['action']);
