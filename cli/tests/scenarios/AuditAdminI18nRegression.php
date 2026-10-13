<?php

// A15.4 / usability 7: admin UI text comes from lang/*.php. A ratchet keeps the number of
// hardcoded strings per admin file from growing, and rendered pages show the chosen admin language.
echo "\n--- Audit A15.4: admin UI strings are translated (layout, nav, operations) ---\n";

/** Hardcoded UI strings in one admin source file: literal flash messages, literal element text and literal text attributes. */
function a154HardcodedStrings(string $source): array {
    $found = [];
    preg_match_all('~setFlash\(\'(?:ok|err|warn|info)\',\s*([\'"])((?:(?!\1).)*)\1~', $source, $m);
    foreach ($m[2] as $text) $found[] = 'flash: ' . $text;
    preg_match_all('~>\s*([^<>{}\n?]*?)\s*</(?:h1|h2|h3|h4|th|label|button|a|p|option|span|td|legend|small|strong|summary|caption|div)>~u', $source, $m);
    foreach ($m[1] as $text) {
        // Inline-script concatenations such as "' + escHtml(a.name) + '" are markup built from translated values, not prose.
        if (preg_match('/\p{Ll}{3,}/u', $text) && !str_contains($text, 'I18n::') && !str_contains($text, 'esc(') && !str_contains($text, 'escHtml(')) $found[] = 'text: ' . $text;
    }
    preg_match_all('~\bdata-confirm="([^"<]*)"~u', $source, $m);
    foreach ($m[1] as $text) {
        if (preg_match('/\p{Ll}{3,}/u', $text)) $found[] = 'confirm: ' . $text;
    }
    // Placeholders and titles count as prose when they hold words or non-ASCII letters; codes such as "main" or "max g"
    // and example paths such as "/assets/img/banner.jpg" do not.
    preg_match_all('~\b(?:placeholder|title)="([^"<]*)"~u', $source, $m);
    foreach ($m[1] as $text) {
        if (preg_match('/\p{L}{3,}[\s,]+\p{L}{3,}|[^\x00-\x7F]/u', $text)) $found[] = 'attr: ' . $text;
    }
    return $found;
}

// Ratchet: budgets may only go down. Files finished in A15.4–A15.6 are at 0; the rest are converted in A15.7.
$a154Budgets = [
    'src/Entrypoints/Admin.php' => 0,
    'src/Entrypoints/Admin/Operations.php' => 0,
    'src/Entrypoints/Admin/Navigation.php' => 0,
    'src/Entrypoints/Admin/StoreScope.php' => 0,
    'src/Entrypoints/Admin/Reports.php' => 0,
    'src/Entrypoints/Admin/Shared.php' => 0,
    'src/Entrypoints/Admin/OrderOperations.php' => 0,
    'src/Entrypoints/Admin/Permissions.php' => 0,
    'src/Entrypoints/Admin/AdminUsers.php' => 0,
    'src/Entrypoints/Admin/B2b.php' => 31,
    'src/Entrypoints/Admin/Catalog.php' => 0,
    'src/Entrypoints/Admin/CatalogEditor.php' => 0,
    'src/Entrypoints/Admin/Customers.php' => 0,
    'src/Entrypoints/Admin/Orders.php' => 0,
    'src/Entrypoints/Admin/PricingRules.php' => 45,
    'src/Entrypoints/Admin/Recovery.php' => 0,
    'src/Entrypoints/Admin/Request.php' => 0,
    'src/Entrypoints/Admin/Suppliers.php' => 39,
    'src/Entrypoints/Admin/Taxonomy.php' => 0,
];
$a154Root = dirname(TEST_CLI_DIR);
$a154Files = array_merge(['src/Entrypoints/Admin.php'], array_map(
    static fn(string $path): string => 'src/Entrypoints/Admin/' . basename($path),
    glob($a154Root . '/src/Entrypoints/Admin/*.php') ?: []
));
foreach ($a154Files as $a154File) {
    $a154Found = a154HardcodedStrings((string)file_get_contents($a154Root . '/' . $a154File));
    $a154Budget = $a154Budgets[$a154File] ?? 0;
    assert_true(count($a154Found) <= $a154Budget,
        "A15.4 ratchet: {$a154File} has " . count($a154Found) . " hardcoded UI strings (budget {$a154Budget})"
        . ($a154Budget === 0 && $a154Found ? ': ' . implode(' | ', array_slice($a154Found, 0, 5)) : ''));
}

// Rendered admin in English and German: no Slovak leftovers, no mojibake, translated flash messages.
// Own freshly migrated SQLite database: earlier scenarios drop or corrupt tables (feeds, names) in the shared suite DB.
foreach ($GLOBALS as $a154Value) if ($a154Value instanceof PDOStatement) $a154Value->closeCursor();
$a154Dir = test_fixture_path('admin-i18n');
mkdir($a154Dir);
mkdir($a154Dir . '/sessions');
$a154DbPath = $a154Dir . '/admin.sqlite';
$a154SuiteDb = Db::get();
$a154SuiteDriver = Db::driver();
$a154Pdo = new PDO('sqlite:' . $a154DbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$a154Key = base64_encode(str_repeat('L', 32));
putenv('BBE_ADMIN_IDENTITY_KEY=' . $a154Key);
try {
    Db::init($a154Pdo, 'sqlite');
    (new Migrator($a154Pdo, $a154Root . '/migrations', $a154Dir . '/migrate.lock'))->apply();
    $a154Admin = AdminIdentity::create('a154-owner', 'A154-password-owner', 'owner', false);
    AdminIdentity::grantStore($a154Admin, 'default', ['*']);
    $a154Pdo->exec("INSERT INTO categories (store_id, name, slug, path) VALUES ('default', 'A155 Basis', 'a155-basis', 'a155-basis')");
    $a154Pdo->exec("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', " . (int)$a154Pdo->lastInsertId() . ", 'A155-P', 'a155-produkt', 'A155 Produkt', 1000, 5, 'active')");
    // A15.6: a customer, a pending bank-transfer order, a delivered order with a return request and an abandoned cart.
    $a155ProductId = (int)$a154Pdo->lastInsertId();
    $a154Pdo->exec("INSERT INTO customers (store_id, email, password_hash, name, status) VALUES ('default', 'a156@example.com', 'x', 'A156 Kunde', 'active')");
    $a156Customer = (string)$a154Pdo->lastInsertId();
    $a156Order = $a154Pdo->prepare('INSERT INTO orders (order_number, status, payment_status, customer_name, customer_email, payment_method, payment_method_snapshot, subtotal, total, currency, store_id, customer_id)
        VALUES (:number, :status, :payment, :name, :email, :method, :snapshot, 1000, 1000, :currency, :store, :customer)');
    $a156Order->execute([':number' => 'A156-1', ':status' => 'pending', ':payment' => 'pending', ':name' => 'A156 Kunde', ':email' => 'a156@example.com', ':method' => 'bank_transfer',
        ':snapshot' => '{"code":"bank_transfer","service_type":"bank_transfer","label":"A156 Bank"}', ':currency' => 'EUR', ':store' => 'default', ':customer' => $a156Customer]);
    $a156Pending = (string)$a154Pdo->lastInsertId();
    $a156Order->execute([':number' => 'A156-2', ':status' => 'delivered', ':payment' => 'paid', ':name' => 'A156 Kunde', ':email' => 'a156@example.com', ':method' => 'card',
        ':snapshot' => '{"code":"card","service_type":"card","label":"A156 Karte"}', ':currency' => 'EUR', ':store' => 'default', ':customer' => $a156Customer]);
    $a156Delivered = (int)$a154Pdo->lastInsertId();
    $a154Pdo->exec("INSERT INTO order_items (order_id, product_id, sku, name, quantity, unit_price, total) VALUES ({$a156Delivered}, {$a155ProductId}, 'A155-P', 'A155 Produkt', 1, 1000, 1000)");
    $a156Rma = Rma::create($a156Delivered, null, 'A156 Grund', [(int)$a154Pdo->lastInsertId() => 1]);
    $a154Pdo->exec("INSERT INTO abandoned_carts (session_id, customer_email, created_at) VALUES ('a156-session', 'a156cart@example.com', '2026-01-01 00:00:00')");
    $a154Token = (string)(AdminIdentity::authenticate('a154-owner', 'A154-password-owner', '', '127.0.15.40', 'A154')['token'] ?? '');
} finally {
    Db::init($a154SuiteDb, $a154SuiteDriver);
}
file_put_contents($a154Dir . '/admin.php', <<<'PHP'
<?php
[, $root, $dbPath, $dir, $key, $token, $getJson, $postJson] = $argv;
require $root . '/src/autoload.php';
putenv('BBE_ADMIN_IDENTITY_KEY=' . $key);
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
JobQueue::setDir($dir . '/jobs');
Security::setRateDbForTesting(new PDO('sqlite::memory:'));
$post = json_decode($postJson, true);
ini_set('session.use_cookies', '0');
ini_set('session.use_strict_mode', '0');
session_save_path($dir . '/sessions');
session_id('a154sess');
session_start();
$_SESSION['csrf_token'] = 'a154';
$_SESSION['admin_identity_token'] = $token;
$_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.15.41';
$_SERVER['HTTP_USER_AGENT'] = 'A154';
$_SERVER['REQUEST_URI'] = '/admin.php';
$_GET = json_decode($getJson, true);
$_POST = $post === [] ? [] : $post + ['csrf_token' => 'a154'];
ob_start();
register_shutdown_function(static function(): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    // Earlier scenarios store deliberately broken UTF-8 in names; substitute it instead of losing the whole page.
    echo "\nA154RESULT" . json_encode(['code' => http_response_code() ?: 200, 'out' => $out], JSON_INVALID_UTF8_SUBSTITUTE);
});
require $root . '/src/Entrypoints/Admin.php';
PHP);
$a154Run = static function(array $get, array $post = []) use ($a154Dir, $a154Root, $a154DbPath, $a154Key, $a154Token): array {
    $process = proc_open([PHP_BINARY, $a154Dir . '/admin.php', $a154Root, $a154DbPath, $a154Dir, $a154Key, $a154Token, json_encode($get), json_encode($post)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $at = strrpos($stdout, 'A154RESULT');
    $result = $at === false ? null : json_decode(substr($stdout, $at + 10), true);
    return is_array($result) ? $result : ['code' => null, 'out' => substr($stderr . ' | ' . json_last_error_msg() . ' | ' . substr($stdout, -300), 0, 900)];
};
// Text of the old hardcoded admin UI (Slovak, ASCII Slovak and broken UTF-8) that must not reach an en/de page.
$a154Leftovers = ['Ulozit', 'Zmazat', 'Pridat', 'aktivna', 'Metody dopravy', 'Platobne metody', 'Zony a krajiny', 'Sadzby',
    'Krajiny a doprava', 'Nový sklad', 'Novy feed', 'Čaká na platbu', 'Opustené košíky', 'Kategórie', 'Doprava', 'Šablóny',
    'Cache súborov', 'Joby čakajúce', 'Kod (unikatny)', 'Posledny sync', 'Sync teraz', 'Entita', 'Kedy', 'Dodávatelia',
    'Zákazníci', 'Reklamácie', 'ulozena', 'povinne', 'Â·', 'â€'];
$a154Check = static function(string $label, array $r, array $expect) use ($a154Leftovers): void {
    $left = array_values(array_filter($a154Leftovers, static fn(string $t): bool => str_contains($r['out'], $t)));
    $missing = array_values(array_filter($expect, static fn(string $t): bool => !str_contains($r['out'], $t)));
    assert_true($r['code'] === 200 && $left === [] && $missing === [],
        "{$label} (code=" . var_export($r['code'], true) . ' leftovers=' . json_encode($left, JSON_UNESCAPED_UNICODE)
        . ' missing=' . json_encode($missing, JSON_UNESCAPED_UNICODE) . ($r['code'] === null ? ' raw=' . $r['out'] : '') . ($missing ? ' main=' . substr((string)strstr($r['out'], '<main>'), 0, 500) : '') . ')');
};

try {
    // German: write through the real handlers so rows and flash messages render too.
    $a154Run(['p' => 'shipping', 'action' => 'method_save', 'lang' => 'de'], ['code' => 'a154-ship', 'carrier' => 'a154', 'name' => 'A154 Ship', 'active' => '1']);
    $a154Run(['p' => 'shipping', 'action' => 'zone_save'], ['code' => 'a154-zone', 'name' => 'A154 Zone', 'active' => '1']);
    $a154Run(['p' => 'shipping', 'action' => 'rate_save'], ['method_code' => 'a154-ship', 'zone_code' => 'a154-zone', 'weight_from_g' => '0', 'weight_to_g' => '1000', 'price' => '300', 'active' => '1']);
    $a154Run(['p' => 'shipping', 'action' => 'method_save'], ['code' => '', 'carrier' => '', 'name' => '']);
    $a154Check('A15.4 de: shipping page and its validation flash are German', $a154Run(['p' => 'shipping']),
        ['Versandarten', 'Zonen und Länder', 'Tarife', 'Speichern', 'Hinzufügen', 'Code, Versanddienst und Name der Methode sind erforderlich.', 'Abmelden', 'Kategorien', 'Lieferanten']);
    $a154Run(['p' => 'payments', 'action' => 'method_save'], ['code' => 'a154-pay', 'provider' => 'a154', 'name' => 'A154 Pay', 'service_type' => 'bank_transfer', 'active' => '1']);
    $a154Check('A15.4 de: payments page and its saved flash are German', $a154Run(['p' => 'payments']),
        ['Zahlungsarten', 'Zahlungsart gespeichert.', 'Banküberweisung', 'Versandeinschränkungen', 'Zahlungsart löschen?']);
    $a154Run(['p' => 'warehouse', 'action' => 'save'], ['code' => 'a154-wh', 'name' => 'A154 Warehouse', 'priority' => '1']);
    $a154Check('A15.4 de: warehouses list is German', $a154Run(['p' => 'warehouses']), ['Lager löschen?', 'A154 Warehouse']);
    $a154Run(['p' => 'feed', 'action' => 'save'], ['code' => 'a154-feed', 'name' => 'A154 Feed', 'field_map' => '{broken']);
    $a154Check('A15.4 de: feed form and its JSON error flash are German', $a154Run(['p' => 'feed']),
        ['Ungültiges JSON in der Feldzuordnung:', 'Lager wählen', 'Feldzuordnung (JSON)', 'Zeitplan (Cron-Ausdruck)']);
    $a154Check('A15.4 de: cache page is German', $a154Run(['p' => 'cache']), ['Cache-Verwaltung', 'Gesamten Cache leeren?']);
    $a154Check('A15.4 de: dashboard operations block is German', $a154Run(['p' => 'dashboard']), ['Betrieb', 'Fehlgeschlagene Jobs', 'Korrelation']);
    $a154Check('A15.4 de: audit log is German', $a154Run(['p' => 'audit']), ['Entität', 'Wann']);

    // English, including pages that had no title of their own.
    $a154Run(['p' => 'dashboard', 'lang' => 'en'], []);
    foreach ([
        'shipping' => ['Shipping methods', 'Zones and countries', 'Sign out'],
        'payments' => ['Payment methods', 'Shipping restrictions'],
        'warehouses' => ['Delete warehouse?', 'New warehouse'],
        'feeds' => ['New feed', 'Last sync'],
        'import' => ['Templates', 'A sample CSV is in'],
        'cache' => ['Cache management', 'Pending jobs'],
        'audit' => ['Entity', 'When'],
        'dashboard' => ['Awaiting payment', 'Abandoned carts', 'Digital files', 'Purchase orders', 'Customer groups', 'Returns'],
    ] as $a154Page => $a154Expect) {
        $a154Check("A15.4 en: {$a154Page} page is English", $a154Run(['p' => $a154Page]), $a154Expect);
    }
    // Page bodies of these screens are converted in A15.5–A15.6; their titles already follow the admin language.
    foreach (['awaiting_payment' => 'Awaiting payment', 'customers' => 'Customers', 'reviews' => 'Reviews', 'categories' => 'Categories'] as $a154Page => $a154Title) {
        $r = $a154Run(['p' => $a154Page]);
        assert_true($r['code'] === 200 && str_contains($r['out'], '<title>' . $a154Title . ' — '), "A15.4 en: {$a154Page} title is '{$a154Title}' (code=" . var_export($r['code'], true) . ')');
    }

    // A15.5: catalog screens (products, product editor and its BBF form, variants, categories, brands, attributes, CMS,
    // content & marketing, digital files, reviews) in German, written through the real handlers; then a few in English.
    $a155Leftovers = array_merge($a154Leftovers, ['Uložiť', 'Zmazať', 'Nová kategória', 'Nadradená', 'Preklady', 'nevybrané',
        'Súvisiace', 'Hodnoty', 'Spravovať', 'Hlavná kategória', 'Krátky popis', 'Save menu item', 'No records.', 'Recenzie',
        'Žiadne', 'Mapovania', 'Názov', 'Výrobca', 'Ďalšie kategórie', 'Upraviť', 'Späť', 'Ukladám']);
    $a155Check = static function(string $label, array $r, array $expect, array $allowed = []) use ($a155Leftovers): void {
        $left = array_values(array_filter(array_diff($a155Leftovers, $allowed), static fn(string $t): bool => str_contains($r['out'], $t)));
        $missing = array_values(array_filter($expect, static fn(string $t): bool => !str_contains(html_entity_decode($r['out'], ENT_QUOTES, 'UTF-8'), $t)));
        assert_true($r['code'] === 200 && $left === [] && $missing === [],
            "{$label} (code=" . var_export($r['code'], true) . ' leftovers=' . json_encode($left, JSON_UNESCAPED_UNICODE)
            . ' missing=' . json_encode($missing, JSON_UNESCAPED_UNICODE) . ($r['code'] === null ? ' raw=' . $r['out'] : '') . ')');
    };
    $a154Run(['p' => 'dashboard', 'lang' => 'de']);
    $a154Run(['p' => 'categories', 'action' => 'save'], ['name' => 'A155 Kategorie', 'slug' => '', 'parent_id' => '', 'sort_order' => '0']);
    $a155Check('A15.5 de: categories page is German', $a154Run(['p' => 'categories']),
        ['Neue Kategorie', 'Übergeordnete Kategorie', 'oberste Ebene', 'Unterkategorien', 'Kategorie löschen?', 'A155 Kategorie']);
    $a155Check('A15.5 de: brands page is German', $a154Run(['p' => 'brands']), ['Neue Marke', 'Hersteller', 'Code (leer = aus dem Namen)']);
    $a154Run(['p' => 'attribute', 'action' => 'save'], ['name' => 'A155 Leistung', 'slug' => 'a155-leistung', 'type' => 'number', 'unit' => 'kW', 'sort_order' => '0']);
    $a155Check('A15.5 de: attributes list and its saved flash are German', $a154Run(['p' => 'attributes']),
        ['Neues Attribut', 'Einheit', 'Wirklich löschen?', 'Attribut gespeichert.', 'Zahl']);
    $a155Check('A15.5 de: attribute form (BBF labels, values, category picker) is German', $a154Run(['p' => 'attribute']),
        ['Werte (für Enum)', 'Kategorien zuordnen', 'Alle sichtbaren auswählen', 'z. B. kW, kg, cm', 'Speichern']);
    $a155Check('A15.5 de: new product editor (BBF form, extras, attributes) is German', $a154Run(['p' => 'product']),
        ['Neues Produkt', 'Weitere Kategorien', 'Übersetzungen', 'Hauptkategorie', 'Kurzbeschreibung', 'Speichern', 'Für diese Kategorie sind keine Attribute definiert.']);
    $a155Product = $a154Pdo->query("SELECT id FROM products WHERE sku = 'A155-P'")->fetchColumn();
    $a155Check('A15.5 de: product edit (variants, related products) is German', $a154Run(['p' => 'product', 'id' => (string)$a155Product]),
        ['Varianten', 'Varianten speichern', 'Ähnliche Produkte', 'neue Variante', 'Zurück zur Liste', 'Bearbeiten: A155 Produkt']);
    $a154Run(['p' => 'products', 'action' => 'bulk'], ['product_ids' => [(string)$a155Product], 'bulk_action' => 'archive']);
    $a155Check('A15.5 de: products list, bulk actions and bulk flash are German', $a154Run(['p' => 'products']),
        ['Archivieren', 'Preis +5%', 'Entwurf', 'Archivierte Produkte: 1.', 'Massenaktion auf die ausgewählten Produkte anwenden?']);
    $a155Check('A15.5 de: CMS pages list is German', $a154Run(['p' => 'pages']), ['Neue Seite']);
    $a155Check('A15.5 de: CMS page form (BBF labels) is German', $a154Run(['p' => 'page_edit']), ['Neue Seite', 'Inhalt (HTML)', 'Seitentyp', 'Speichern']);
    $a154Run(['p' => 'content', 'action' => 'save'], ['content_type' => 'menu', 'menu_code' => 'primary', 'locale' => '*', 'label' => 'A155 Menu', 'url' => '/', 'sort_order' => '0', 'active' => '1']);
    $a155Check('A15.5 de: content & marketing page and its saved flash are German', $a154Run(['p' => 'content']),
        ['Menüpunkt', 'Weiterleitung', 'Menüpunkte', 'Keine Einträge.', 'Inhalt gespeichert.', 'Diesen Inhaltseintrag löschen?', 'A155 Menu']);
    $a155Check('A15.5 de: digital files page is German', $a154Run(['p' => 'digital_assets']),
        ['SKU-Zuordnungen', 'Noch keine digitale Datei hinzugefügt.', 'Dateischlüssel', 'Datei hinzufügen oder bearbeiten']);
    $a155Check('A15.5 de: reviews page is German', $a154Run(['p' => 'reviews']), ['Keine Bewertungen warten auf Freigabe.', 'Bewertungen — Moderation']);

    // A15.5 step 2: messages of the core services (CatalogTaxonomy, ProductEditor, ProductVariants) follow the admin language.
    $a155CoreLeftovers = ['Kategóri', 'Značk', 'už má iná', 'nemožno', 'neboli uložené', 'už používa', 'musí mať', 'Názov je povinný', 'presunuté'];
    $a155Core = static function(string $label, array $r, array $expect) use ($a155Check, $a155CoreLeftovers): void {
        $left = array_values(array_filter($a155CoreLeftovers, static fn(string $t): bool => str_contains($r['out'], $t)));
        assert_true($left === [], "{$label}: no Slovak core message (leftovers=" . json_encode($left, JSON_UNESCAPED_UNICODE) . ')');
        $a155Check($label, $r, $expect);
    };
    $a155Basis = (string)$a154Pdo->query("SELECT id FROM categories WHERE slug = 'a155-basis'")->fetchColumn();
    $a154Run(['p' => 'categories', 'action' => 'save'], ['name' => 'A155 Kategorie', 'slug' => '', 'parent_id' => '', 'sort_order' => '0']);
    $a155Core('A15.5 de: duplicate category slug flash is German', $a154Run(['p' => 'categories']),
        ['Eine andere Kategorie auf dieser Ebene hat bereits den Slug "a155-kategorie".']);
    $a154Run(['p' => 'categories', 'action' => 'delete'], ['category_id' => $a155Basis]);
    $a155Core('A15.5 de: blocked category delete flash is German', $a154Run(['p' => 'categories']),
        ['Kategorie A155 Basis kann nicht gelöscht werden: sie enthält Produkte; verschieben Sie diese zuerst.']);
    $a154Run(['p' => 'categories', 'action' => 'save', 'id' => $a155Basis], ['name' => 'A155 Basis', 'slug' => 'a155-basis', 'parent_id' => '', 'sort_order' => 'x']);
    $a155Core('A15.5 de: category validation flash is German', $a154Run(['p' => 'categories']), ['Die Reihenfolge muss eine ganze Zahl sein.']);
    $a154Run(['p' => 'brands', 'action' => 'save'], ['name' => '', 'code' => '', 'manufacturer_name' => '']);
    $a155Core('A15.5 de: brand validation flash is German', $a154Run(['p' => 'brands']),
        ['Der Name ist erforderlich; Name und Hersteller dürfen höchstens 191 Zeichen haben.']);
    $a154Run(['p' => 'brands', 'action' => 'save'], ['name' => 'A155 Marke', 'code' => '', 'manufacturer_name' => '', 'active' => '1']);
    $a155Core('A15.5 de: brand created flash is German', $a154Run(['p' => 'brands']), ['Marke erstellt.']);
    $a154Run(['p' => 'product', 'id' => (string)$a155Product, 'action' => 'variants_save'],
        ['variants' => ['new' => ['sku' => 'A155-P', 'name' => '', 'attrs' => '', 'price_absolute' => '', 'price_delta' => '0', 'stock' => '0', 'sort_order' => '0']]]);
    $a155Core('A15.5 de: variant validation flash is German', $a154Run(['p' => 'product', 'id' => (string)$a155Product]),
        ['Die Varianten wurden nicht gespeichert: SKU A155-P wird bereits von einem anderen Produkt oder einer Variante verwendet.']);
    $a154Run(['p' => 'product', 'id' => (string)$a155Product, 'action' => 'variants_save'],
        ['variants' => ['new' => ['sku' => 'A155-V', 'name' => '', 'attrs' => 'farbe', 'price_absolute' => '', 'price_delta' => '0', 'stock' => '0', 'sort_order' => '0']]]);
    $a155Core('A15.5 de: variant row flash is German', $a154Run(['p' => 'product', 'id' => (string)$a155Product]),
        ['Variante A155-V: geben Sie die Attribute als Zeilen Schlüssel=Wert ein.']);
    $a154Run(['p' => 'product', 'action' => 'save'], ['sku' => 'A155-N', 'name' => 'A155 Neu', 'slug' => '', 'category_id' => $a155Basis, 'product_type' => 'simple',
        'price' => '100', 'compare_price' => '', 'stock' => '0', 'stock_low_threshold' => '', 'stock_critical_threshold' => '', 'status' => 'active', 'summary' => '',
        'description' => '', 'images' => '', 'weight_g' => '0', 'tags' => '', 'brand_id' => '', 'gtin' => '4006381333932', 'mpn' => '', 'seo_title' => '', 'meta_description' => '']);
    $a155Core('A15.5 de: product editor validation flash is German', $a154Run(['p' => 'product', 'id' => '0']),
        ['GTIN/EAN muss 8, 12, 13 oder 14 Ziffern und eine gültige Prüfziffer haben.']);
    $a154Run(['p' => 'dashboard', 'lang' => 'en']);
    $a155Check('A15.5 en: new product editor is English', $a154Run(['p' => 'product']), ['New product', 'Main category', 'Additional categories', 'Translations', 'Save']);
    $a155Check('A15.5 en: categories page is English', $a154Run(['p' => 'categories']), ['New category', 'Parent category', 'Delete category?']);
    $a155Check('A15.5 en: content page is English', $a154Run(['p' => 'content']), ['Menu item', 'Save menu item', 'Landing pages', 'Redirects'], ['Save menu item', 'No records.']);

    // A15.6: orders, sales overview, order detail and its handlers, returns, customers, revenue recovery and the
    // CSRF refusal in German (rows written through the real handlers), then the main pages in English.
    assert_true(($a156Rma[0] ?? false) === true, 'A15.6 fixture: return request created (' . json_encode($a156Rma) . ')');
    $a156English = ['Payment method', 'Payment reference', 'Payment timeline', 'Last event', '— Payment —', 'Refund unavailable', 'Payment status', 'Order status', 'Live data', 'Items'];
    $a156Leftovers = array_merge($a155Leftovers, $a156English, ['Zmeniť', 'Požiadať', 'Medzisúčet', 'Faktúr', 'Súhlasy', 'História', 'Odoslané',
        'Vystaviť', 'Poslať', 'Hľadať', 'Meno', 'Zablokovať', 'Odblokovať', 'Označiť', 'Opustené', 'Hodnota', 'Pripomienka', 'čaká', 'Neplatn',
        'Variabilný', 'Objednávk', 'Zákazník', 'Platba', 'Schváliť', 'Zamietnuť', 'Dôvod', 'vrátenia', 'Adresy', 'fronta']);
    $a156Check = static function(string $label, array $r, array $expect, array $allowed = []) use ($a156Leftovers): void {
        $left = array_values(array_filter(array_diff($a156Leftovers, $allowed), static fn(string $t): bool => str_contains($r['out'], $t)));
        $missing = array_values(array_filter($expect, static fn(string $t): bool => !str_contains(html_entity_decode($r['out'], ENT_QUOTES, 'UTF-8'), $t)));
        assert_true($r['code'] === 200 && $left === [] && $missing === [],
            "{$label} (code=" . var_export($r['code'], true) . ' leftovers=' . json_encode($left, JSON_UNESCAPED_UNICODE)
            . ' missing=' . json_encode($missing, JSON_UNESCAPED_UNICODE) . ($r['code'] === null ? ' raw=' . $r['out'] : '') . ')');
    };
    $a156Order = ['p' => 'order', 'id' => $a156Pending];
    $a154Run(['p' => 'dashboard', 'lang' => 'de']);
    $a156Check('A15.6 de: orders list is German', $a154Run(['p' => 'orders']), ['— Zahlung —', 'A156-1', 'Details']);
    $a156Check('A15.6 de: sales overview is German', $a154Run(['p' => 'sales']),
        ['Verkaufsübersicht', 'Bestellstatus', 'Zahlungsstatus', 'Gesamter Zeitraum', 'Positionen', 'Nummer, Name oder E-Mail']);
    $a154Run($a156Order + ['action' => 'status'], ['new_status' => 'a156-unknown', 'note' => '']);
    $a156Check('A15.6 de: order detail and its invalid-status flash are German', $a154Run($a156Order),
        ['Ungültiger Bestellstatus.', 'Zahlungsart: A156 Bank', 'Variabelsymbol der Überweisung: ', 'Zahlungsreferenz: ', 'Zahlungsstatus: ', 'Status ändern',
            'Zahlung ändern', 'Betrag in Cent', 'Notiz...', 'Für den aktuellen Zahlungsstatus ist keine Erstattung möglich.', 'Positionen', 'Zwischensumme: ',
            'Gesamt: ', 'Zahlungsverlauf', 'Noch keine Zahlungsereignisse erfasst.', 'Rechnungen', 'Noch keine Rechnung ausgestellt.', '← Zurück zur Liste']);
    foreach ([
        'payment_transition' => [['new_payment_status' => 'refunded'], 'Ungültiger Zahlungsübergang.'],
        'refund_request' => [['amount' => '0', 'note' => 'A156'], 'Der Erstattungsbetrag muss im verfügbaren Restbetrag liegen.'],
        'email_resend' => [['log_id' => '0'], 'Die E-Mail kann nicht erneut gesendet werden.'],
        'invoice_resend' => [['invoice_id' => '0'], 'Die Rechnung kann jetzt nicht erneut gesendet werden.'],
    ] as $a156Action => [$a156Post, $a156Flash]) {
        $a154Run($a156Order + ['action' => $a156Action], $a156Post);
        $a156Check("A15.6 de: order {$a156Action} flash is German", $a154Run($a156Order), [$a156Flash]);
    }
    $a156Check('A15.6 de: returns page is German', $a154Run(['p' => 'rma']), ['Reklamationen / Rücksendungen', 'Alle', 'Genehmigen', 'Ablehnen', 'Grund', 'A156 Grund']);
    $a154Run(['p' => 'rma', 'action' => 'status'], ['rma_id' => '0', 'status' => 'approved']);
    $a156Check('A15.6 de: return status flash is German', $a154Run(['p' => 'rma']), ['Der Reklamationsstatus konnte nicht geändert werden: Ungültige Reklamation.']);
    $a156Check('A15.6 de: customers list is German', $a154Run(['p' => 'customers', 'q' => 'a156']),
        ['E-Mail, Name oder ID', 'Filter zurücksetzen', 'Registriert', 'A156 Kunde', 'Details']);
    $a154Run(['p' => 'customer', 'id' => $a156Customer, 'action' => 'status'], ['customer_id' => $a156Customer, 'status' => 'blocked']);
    $a156Check('A15.6 de: customer detail and its status flash are German', $a154Run(['p' => 'customer', 'id' => $a156Customer]),
        ["Kunde #{$a156Customer} → blocked", 'Profil', 'Letzte Anmeldung', 'Telefon', 'Konto entsperren', 'DSGVO-Löschung…', 'Adressen (0)', 'Keine Adressen.',
            'Bestellungen (2)', 'Reklamationen (', '← Liste'], ['Uložiť']);
    $a156Check('A15.6 de: unknown customer is German', $a154Run(['p' => 'customer', 'id' => '999999']), ['Kunde nicht gefunden.', 'Zurück zur Liste']);
    $a156Check('A15.6 de: awaiting payment page is German', $a154Run(['p' => 'awaiting_payment']),
        ['Variabelsymbol', 'Als bezahlt markieren', 'Bestellung A156-1 als bezahlt markieren?', 'Notiz (z. B. Kontoauszug)', 'A156 Bank', 'Alter']);
    $a156Check('A15.6 de: abandoned carts page is German', $a154Run(['p' => 'abandoned_carts', 'filter' => 'all']),
        ['Verlassen', 'Konvertiert', 'Alle', 'Erinnerung', 'Letzte Änderung', 'Wert', 'wartet', 'a156cart@example.com']);
    $a156Check('A15.6 de: CSRF refusal flash is German', $a154Run(['p' => 'customers', 'action' => 'a156'], ['csrf_token' => 'a156-wrong']), ['Ungültiges CSRF-Token.']);
    $a154Run(['p' => 'dashboard', 'lang' => 'en']);
    $a156Check('A15.6 en: order detail is English', $a154Run($a156Order),
        ['Payment method: A156 Bank', 'Bank transfer variable symbol: ', 'Payment reference: ', 'Change status', 'Subtotal: ', 'Payment timeline', 'No invoice issued yet.'], $a156English);
    $a156Check('A15.6 en: customers and returns are English', $a154Run(['p' => 'customer', 'id' => $a156Customer]), ['Profile', 'Last sign-in', 'Unblock account', 'Addresses (0)'], ['Uložiť']);
    $a156Check('A15.6 en: returns page is English', $a154Run(['p' => 'rma']), ['Returns and complaints', 'Approve', 'Reject', 'Reason'], $a156English);
    $a154Run(['p' => 'dashboard', 'lang' => 'de']);
    $a154Run(['p' => 'awaiting_payment', 'action' => 'confirm'], ['id' => $a156Pending, 'note' => 'A156']);
    $a156Check('A15.6 de: confirmed bank transfer flash is German', $a154Run(['p' => 'awaiting_payment']),
        ['Zahlung erfasst; die Bestellung ist bezahlt und die Zustellung wurde gestartet.', 'Keine unbezahlten Überweisungen.']);
} finally {
    putenv('BBE_ADMIN_IDENTITY_KEY');
    $a154Pdo = null;
    $a154Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a154Dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a154Tree as $a154Path) $a154Path->isDir() ? rmdir($a154Path->getPathname()) : unlink($a154Path->getPathname());
    rmdir($a154Dir);
}
