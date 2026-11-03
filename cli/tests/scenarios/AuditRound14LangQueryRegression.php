<?php
/**
 * Round 14 review run 250: Template::storeUrl() adds ?lang=<further language>, so a caller that appended its own
 * "?p=…" after it built /account.php?lang=es?p=reset — PHP read lang="es?p=reset" and no p, so the mailed reset and
 * verification links opened the login page in the store language, and specification-value links the home page. The
 * caller's query now goes inside storeUrl(). (Account-page links and the category redirect: AuditRound4AccountAdminSeo.)
 */

echo "\n--- Audit R14: further language on URLs that carry their own query ---\n";

$r14qFail = static fn(string $what, Throwable $e) => assert_true(false, "R14 UX2 query {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
$r14qQuery = static function (string $url): array {
    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
    return $query;
};
$r14qSavedLocale = I18n::locale();
$r14qSavedStore = StoreContext::current();
[$r14qSavedDb, $r14qSavedDriver] = [Db::get(), Db::driver()];
$r14qDir = test_fixture_path('r14-lang-query');
@mkdir($r14qDir, 0700, true);
MailService::useAdapter(new LogMail(['log_file' => $r14qDir . '/emails.log']));
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
try {
    $r14qDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    (new Migrator($r14qDb, TEST_CLI_DIR . '/../migrations', $r14qDir . '/migrate.lock'))->apply(static fn($m) => null);
    Db::init($r14qDb, 'sqlite');
    Bootstrap::initStoreContext('/en/', '', 'us');
    I18n::setLocale('es');
    assert_equals('es', Bootstrap::secondaryLocale(), 'R14 UX2 query fixture: Spanish is a further language of the /en/ store');

    // --- mailed account links ---
    [, $r14qCustomer] = Customers::register('r14q-links@example.test', 'R14q-pass-word', 'R14q');
    $r14qCfg = ['app_url' => 'https://r14q.example'];
    LogMail::clearCaptured();
    $r14qOutcomes = [
        'reset' => Customers::requestPasswordReset('r14q-links@example.test', 'es', $r14qCfg),
        'verify_email' => Customers::requestEmailVerification((int)$r14qCustomer, 'es', $r14qCfg),
    ];
    $r14qText = html_entity_decode(implode("\n", array_map(static fn(array $m): string => (string)$m['text'], LogMail::captured())), ENT_QUOTES);
    foreach ($r14qOutcomes as $r14qPage => $r14qOutcome) {
        preg_match('~https://r14q\.example/en/account\.php\?[^\s"<>()]*p=' . $r14qPage . '\b[^\s"<>()]*~', $r14qText, $r14qMatch);
        $r14qUrl = (string)($r14qMatch[0] ?? '');
        $r14qParams = $r14qQuery($r14qUrl);
        assert_true($r14qOutcome === 'sent' && substr_count($r14qUrl, '?') === 1 && ($r14qParams['p'] ?? null) === $r14qPage
            && ($r14qParams['lang'] ?? null) === 'es' && preg_match('/^[a-f0-9]{64}$/', (string)($r14qParams['token'] ?? '')) === 1,
            "R14 UX2: the mailed {$r14qPage} link of a Spanish page opens p={$r14qPage} with its token, in Spanish ({$r14qOutcome}: {$r14qUrl})");
        if ($r14qPage === 'reset') {
            assert_equals((int)$r14qCustomer, Customers::verifyResetToken((string)($r14qParams['token'] ?? '')), 'R14 UX2: the mailed reset token works');
        }
    }
    LogMail::clearCaptured();

    // --- specification-value links (core.specification-values) ---
    StoreContext::activate(StoreContext::resolve(Bootstrap::stores(), null, null, 'us')); // sending the mails switched the store context
    I18n::setLocale('es');
    $r14qCatalog = StoreContext::current()->catalogId(); // the /en/ store shares the default catalog
    Db::get()->exec("INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES ('{$r14qCatalog}', 'r14q-libros', 'R14Q Libros', NULL, 'r14q-libros', 0)");
    $r14qCategory = (int)Db::get()->lastInsertId();
    Db::get()->exec("INSERT INTO products (id, store_id, category_id, sku, slug, product_type, name, price, currency, stock, status, inventory_policy, tags, attrs, created_at)
        VALUES (98961, '{$r14qCatalog}', {$r14qCategory}, 'R14Q-A', 'r14q-a', 'simple', 'R14Q Libro', 900, 'eur', 3, 'active', 'deny', '[]', '{}', '2026-01-01 00:00:00')");
    Db::get()->exec("INSERT INTO product_specifications (product_id, lang, criteria_name, criteria_value) VALUES (98961, 'es', 'R14Q Autor', 'R14Q Escritora Uno')");
    $r14qSpec = StorefrontSectionRegistry::resolve('core.specification-values', ['name' => 'R14Q Autor']);
    $r14qSpecUrl = (string)($r14qSpec['items'][0]['url'] ?? '');
    $r14qSpecParams = $r14qQuery($r14qSpecUrl);
    assert_true(substr_count($r14qSpecUrl, '?') === 1 && ($r14qSpecParams['page'] ?? null) === 'search'
        && ($r14qSpecParams['q'] ?? null) === 'R14Q Escritora Uno' && ($r14qSpecParams['lang'] ?? null) === 'es',
        'R14 UX2: a specification value of a Spanish page links to the Spanish search for it (' . json_encode($r14qSpec) . ')');

    // --- the store's own language adds nothing ---
    I18n::setLocale('en');
    assert_true(str_ends_with(Template::storeUrl('/?page=search&q=x'), '/en/?page=search&q=x'), 'R14 UX2: the store language keeps the plain query');
} catch (Throwable $e) { $r14qFail('links', $e); } finally {
    Db::init($r14qSavedDb, $r14qSavedDriver);
    MailService::useAdapter(null);
    StoreContext::activate($r14qSavedStore);
    I18n::setLocale($r14qSavedLocale);
}
