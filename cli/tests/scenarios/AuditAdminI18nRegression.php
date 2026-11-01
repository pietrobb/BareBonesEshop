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

// Ratchet: budgets may only go down. Every admin file is at 0 since A15.7.
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
    'src/Entrypoints/Admin/B2b.php' => 0,
    'src/Entrypoints/Admin/Catalog.php' => 0,
    'src/Entrypoints/Admin/CatalogEditor.php' => 0,
    'src/Entrypoints/Admin/Customers.php' => 0,
    'src/Entrypoints/Admin/Orders.php' => 0,
    'src/Entrypoints/Admin/PricingRules.php' => 0,
    'src/Entrypoints/Admin/Recovery.php' => 0,
    'src/Entrypoints/Admin/Request.php' => 0,
    'src/Entrypoints/Admin/Suppliers.php' => 0,
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
    // The random base64 CSP nonce can contain a leftover word such as "Kedy"; leftover checks only look at page text.
    $out = (string)preg_replace('/ nonce="[^"]*"/', '', $out);
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
    // R9: saving a purchase-on-invoice method (or an extension's type) in the admin keeps its type; it used to become
    // 'offline', which offers the B2B invoice method to every consumer without the company approval and credit limit.
    $r9pId = (int)$a154Pdo->query("SELECT id FROM payment_methods WHERE code = 'a154-pay'")->fetchColumn();
    $r9pType = static fn(): string => (string)$a154Pdo->query("SELECT service_type FROM payment_methods WHERE id = {$r9pId}")->fetchColumn();
    $r9pSave = static fn(string $type) => $a154Run(['p' => 'payments', 'action' => 'method_save'],
        ['id' => (string)$r9pId, 'code' => 'a154-pay', 'provider' => 'a154', 'name' => 'A154 Pay', 'service_type' => $type, 'active' => '1']);
    $r9pSave('invoice');
    $r9pPage = $a154Run(['p' => 'payments']);
    assert_true($r9pType() === 'invoice' && str_contains((string)$r9pPage['out'], '<option value="invoice" selected>Kauf auf Rechnung</option>'),
        'R9 admin payments: a method saved as purchase on invoice stays invoice and the form shows it selected (type=' . $r9pType() . ')');
    $a154Pdo->exec("UPDATE payment_methods SET service_type = 'acme_bnpl' WHERE id = {$r9pId}");
    $r9pPage = $a154Run(['p' => 'payments']);
    $r9pSave('acme_bnpl');
    $r9pKept = $r9pType();
    $r9pSave('forged_type');
    assert_true(str_contains((string)$r9pPage['out'], '<option value="acme_bnpl" selected>acme_bnpl</option>') && $r9pKept === 'acme_bnpl' && $r9pType() === 'offline',
        "R9 admin payments: an extension's type is shown and kept on save; an unknown posted type still becomes offline (kept={$r9pKept}, forged=" . $r9pType() . ')');
    unset($r9pType); // the arrow fn holds a copy of $a154Pdo; drop it so the cleanup can close and delete the SQLite file (Windows)
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
        ['Eine andere Kategorie des Katalogs hat bereits den Slug "a155-kategorie" (die Adresse /category/a155-kategorie muss eindeutig sein).']);
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
        ['Ungültiger Bestellstatus.', 'Zahlungsart: A156 Bank', 'Überweisungsreferenz: ', 'Zahlungsreferenz: ', 'Zahlungsstatus: ', 'Status ändern',
            'Zahlung ändern', 'Betrag (EUR)', 'Notiz...', 'Für den aktuellen Zahlungsstatus ist keine Erstattung möglich.', 'Positionen', 'Zwischensumme: ',
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
    $a156Check('A15.6 de: returns page is German', $a154Run(['p' => 'rma']), ['Rücksendungen', 'Alle', 'Genehmigen', 'Ablehnen', 'Grund', 'A156 Grund']);
    $a154Run(['p' => 'rma', 'action' => 'status'], ['rma_id' => '0', 'status' => 'approved']);
    $a156Check('A15.6 de: return status flash is German', $a154Run(['p' => 'rma']), ['Der Rücksendestatus konnte nicht geändert werden: Ungültige Rücksendung.']);
    $a156Check('A15.6 de: customers list is German', $a154Run(['p' => 'customers', 'q' => 'a156']),
        ['E-Mail, Name oder ID', 'Filter zurücksetzen', 'Registriert', 'A156 Kunde', 'Details']);
    $a154Run(['p' => 'customer', 'id' => $a156Customer, 'action' => 'status'], ['customer_id' => $a156Customer, 'status' => 'blocked']);
    $a156Check('A15.6 de: customer detail and its status flash are German', $a154Run(['p' => 'customer', 'id' => $a156Customer]),
        ["Kunde #{$a156Customer} → blocked", 'Profil', 'Letzte Anmeldung', 'Telefon', 'Konto entsperren', 'DSGVO-Löschung…', 'Adressen (0)', 'Keine Adressen.',
            'Bestellungen (2)', 'Rücksendungen (', '← Liste'], ['Uložiť']);
    $a156Check('A15.6 de: unknown customer is German', $a154Run(['p' => 'customer', 'id' => '999999']), ['Kunde nicht gefunden.', 'Zurück zur Liste']);
    $a156Check('A15.6 de: awaiting payment page is German', $a154Run(['p' => 'awaiting_payment']),
        ['Überweisungsreferenz', 'Als bezahlt markieren', 'Bestellung A156-1 als bezahlt markieren?', 'Notiz (z. B. Kontoauszug)', 'A156 Bank', 'Alter']);
    $a156Check('A15.6 de: abandoned carts page is German', $a154Run(['p' => 'abandoned_carts', 'filter' => 'all']),
        ['Verlassen', 'Konvertiert', 'Alle', 'Erinnerung', 'Letzte Änderung', 'Wert', 'wartet', 'a156cart@example.com']);
    $a156Check('A15.6 de: CSRF refusal flash is German', $a154Run(['p' => 'customers', 'action' => 'a156'], ['csrf_token' => 'a156-wrong']), ['Ungültiges CSRF-Token.']);
    $a154Run(['p' => 'dashboard', 'lang' => 'en']);
    $a156Check('A15.6 en: order detail is English', $a154Run($a156Order),
        ['Payment method: A156 Bank', 'Bank transfer reference number: ', 'Payment reference: ', 'Change status', 'Subtotal: ', 'Payment timeline', 'No invoice issued yet.'], $a156English);
    $a156Check('A15.6 en: customers and returns are English', $a154Run(['p' => 'customer', 'id' => $a156Customer]), ['Profile', 'Last sign-in', 'Unblock account', 'Addresses (0)'], ['Uložiť']);
    $a156Check('A15.6 en: returns page is English', $a154Run(['p' => 'rma']), ['Returns and complaints', 'Approve', 'Reject', 'Reason'], $a156English);
    $a154Run(['p' => 'dashboard', 'lang' => 'de']);
    $a154Run(['p' => 'awaiting_payment', 'action' => 'confirm'], ['id' => $a156Pending, 'note' => 'A156']);
    $a156Check('A15.6 de: confirmed bank transfer flash is German', $a154Run(['p' => 'awaiting_payment']),
        ['Zahlung erfasst; die Bestellung ist bezahlt und die Zustellung wurde gestartet.', 'Keine unbezahlten Überweisungen.']);

    // A15.7: customer groups, B2B companies (and the B2B card on the customer detail), promotions, price lists,
    // suppliers and purchase orders in German, rows written through the real handlers; then a few in English.
    $a157Leftovers = array_merge($a156Leftovers, ['Zľava', 'Obchodné', 'Schválenie', 'Kreditný', 'Splatnosť', 'faktúr', 'Rozsah', 'Kombinov',
        'Podmienky', 'Kupón', 'Platí ', 'Platnosť', 'Cenník', 'cenník', 'všetci', 'Dodávateľ', 'Ponuka', 'Nákupn', 'Prijať', 'Prijaté', 'Zaúčtovať',
        'aktívn', 'žiadna', 'schválen', 'objednan', 'Darček', 'Katalóg', 'Košík', 'Skupina', 'Priorita', 'Telefón', 'Poznámka', 'očakávané', 'sklad ']);
    $a157Check = static function(string $label, array $r, array $expect, array $allowed = []) use ($a157Leftovers): void {
        $left = array_values(array_filter(array_diff($a157Leftovers, $allowed), static fn(string $t): bool => str_contains($r['out'], $t)));
        $missing = array_values(array_filter($expect, static fn(string $t): bool => !str_contains(html_entity_decode($r['out'], ENT_QUOTES, 'UTF-8'), $t)));
        assert_true($r['code'] === 200 && $left === [] && $missing === [],
            "{$label} (code=" . var_export($r['code'], true) . ' leftovers=' . json_encode($left, JSON_UNESCAPED_UNICODE)
            . ' missing=' . json_encode($missing, JSON_UNESCAPED_UNICODE) . ($r['code'] === null ? ' raw=' . $r['out'] : '') . ')');
    };
    // Each write is followed by one page view that consumes its flash; core flash messages are checked separately.
    $a157Write = static function(array $get, array $post) use ($a154Run): void {
        $a154Run($get, $post);
        $a154Run(['p' => 'dashboard']);
    };
    $a154Run(['p' => 'dashboard', 'lang' => 'de']);
    $a157Write(['p' => 'customer_groups', 'action' => 'save'], ['name' => 'A157 Gruppe', 'code' => '', 'discount' => '5', 'active' => '1']);
    $a157Group = (string)$a154Pdo->query("SELECT id FROM customer_groups WHERE name = 'A157 Gruppe'")->fetchColumn();
    $a157Write(['p' => 'companies', 'action' => 'save'], ['legal_name' => 'A157 GmbH', 'code' => '', 'registration_number' => '', 'vat_id' => '',
        'approval_status' => 'pending', 'credit_limit' => '0', 'payment_terms_days' => '14', 'invoice_purchase' => '1', 'active' => '1']);
    $a157Company = (string)$a154Pdo->query("SELECT id FROM companies WHERE legal_name = 'A157 GmbH'")->fetchColumn();
    $a157Write(['p' => 'promotions', 'action' => 'save'], ['name' => 'A157 Aktion', 'code' => '', 'scope' => 'catalog', 'coupon_code' => '', 'priority' => '0',
        'stacking' => 'stack', 'valid_from' => '', 'valid_to' => '', 'action_type' => 'discount_percent', 'action_value' => '10', 'active' => '1']);
    $a157Write(['p' => 'price_lists', 'action' => 'save'], ['name' => 'A157 Liste', 'code' => 'a157-liste', 'currency' => 'EUR', 'customer_group_id' => '',
        'priority' => '0', 'valid_from' => '', 'valid_to' => '', 'active' => '1']);
    $a157List = (string)$a154Pdo->query("SELECT id FROM price_lists WHERE code = 'a157-liste'")->fetchColumn();
    $a157Write(['p' => 'suppliers', 'action' => 'save'], ['name' => 'A157 Lieferant', 'code' => 'a157-sup', 'email' => '', 'phone' => '', 'note' => '', 'active' => '1']);
    $a157Supplier = (string)$a154Pdo->query("SELECT id FROM suppliers WHERE code = 'a157-sup'")->fetchColumn();
    $a157Write(['p' => 'suppliers', 'action' => 'products', 'id' => $a157Supplier], ['products' => 'A155-P | A157-X | 400 | 1 | 1 | 5']);
    $a157Warehouse = (string)$a154Pdo->query("SELECT id FROM warehouses WHERE code = 'a154-wh'")->fetchColumn();
    $a157Write(['p' => 'purchase_orders', 'action' => 'create'], ['supplier_id' => $a157Supplier, 'warehouse_id' => $a157Warehouse, 'number' => 'A157-PO',
        'expected_at' => '2030-01-15', 'items' => 'A155-P | 3 | 500']);
    $a157Po = (string)$a154Pdo->query("SELECT id FROM purchase_orders WHERE number = 'A157-PO'")->fetchColumn();
    assert_true($a157Group !== '' && $a157Company !== '' && $a157List !== '' && $a157Supplier !== '' && $a157Po !== '',
        "A15.7 fixture: group/company/price list/supplier/purchase order created ({$a157Group}/{$a157Company}/{$a157List}/{$a157Supplier}/{$a157Po})");
    $a157Check('A15.7 de: customer groups page is German', $a154Run(['p' => 'customer_groups']),
        ['Neue Gruppe', 'Rabatt in % (z. B. 7,5)', 'Code (leer = aus dem Namen)', 'Gruppe löschen?', 'Rabatt', 'aktiv', 'A157 Gruppe', 'Speichern']);
    $a157Check('A15.7 de: customer group edit is German', $a154Run(['p' => 'customer_groups', 'id' => $a157Group]), ['Bearbeiten: A157 Gruppe', 'Neue Gruppe']);
    $a157Check('A15.7 de: companies page is German', $a154Run(['p' => 'companies']),
        ['Neue Firma', 'Firmenname', 'Freigabe', 'wartet auf Freigabe', 'Kreditlimit (Cent, 0 = ohne Limit)', 'Zahlungsziel (Tage)', 'Kauf auf Rechnung',
            'ja, 14 Tage', 'ohne Limit', 'Limit / unbezahlt', 'Firma löschen?', 'A157 GmbH']);
    $a157Check('A15.7 de: promotions page is German', $a154Run(['p' => 'promotions']),
        ['Neue Aktion', 'Bereich', 'Katalog (Produktpreis)', 'Warenkorb (Bestellrabatt)', 'Kombinierbarkeit', 'Exklusiv (keine weiteren gelten)',
            'Bedingungen', 'Rabatt in %', 'Kaufe X, erhalte Y gratis', 'Gültig ab (UTC)', 'Nur für Kundengruppen', 'Gutscheincode (nur Warenkorb)', 'Aktion löschen?', 'A157 Aktion', 'Katalog']);
    $a157Check('A15.7 de: price lists page is German', $a154Run(['p' => 'price_lists']),
        ['Neue Preisliste', '— alle Kunden —', 'Priorität (höhere gewinnt)', 'Gültig bis (UTC)', 'Gültigkeit (UTC)', 'Preisliste samt Preisen löschen?', 'A157 Liste', 'alle']);
    $a157Check('A15.7 de: price list edit is German', $a154Run(['p' => 'price_lists', 'id' => $a157List]),
        ['Bearbeiten: A157 Liste', 'Preise der Preisliste a157-liste', 'Eine Zeile pro Preis', 'Preise speichern']);
    $a157Check('A15.7 de: suppliers page is German', $a154Run(['p' => 'suppliers', 'id' => $a157Supplier]),
        ['Bearbeiten: A157 Lieferant', 'Neuer Lieferant', 'Telefon', 'Angebot des Lieferanten a157-sup', 'Lieferzeit (Tage)', 'Angebot speichern',
            'Lieferanten samt Angebot löschen?', 'Kontakt']);
    $a157Check('A15.7 de: purchase orders page is German', $a154Run(['p' => 'purchase_orders']),
        ['Neue Lieferantenbestellung', 'Lieferant', 'Erwartete Lieferung', 'Nummer (leer = automatisch)', 'Bestellung anlegen', 'Erhalten / bestellt', 'A157-PO', 'bestellt']);
    $a157Check('A15.7 de: purchase order detail is German', $a154Run(['p' => 'purchase_orders', 'id' => $a157Po]),
        ['Bestellung beim Lieferanten A157-PO', '→ Lager A154 Warehouse', 'erwartet 2030-01-15', 'Jetzt annehmen', 'Einkaufspreis', 'A155-P annehmen', 'Wareneingang buchen', '← Liste']);
    $a157Check('A15.7 de: B2B card on the customer detail is German', $a154Run(['p' => 'customer', 'id' => $a156Customer]),
        ['Kundengruppe', '— keine —', 'Zuordnung speichern', 'A157 Gruppe', 'A157 GmbH']);
    $a154Run(['p' => 'dashboard', 'lang' => 'en']);
    $a157Check('A15.7 en: companies page is English', $a154Run(['p' => 'companies']), ['New company', 'Legal name', 'awaiting approval', 'yes, 14 days', 'no limit', 'Delete company?']);
    $a157Check('A15.7 en: promotions page is English', $a154Run(['p' => 'promotions']), ['New promotion', 'Scope', 'Stacking', 'Conditions', 'Delete promotion?']);
    $a157Check('A15.7 en: purchase order detail is English', $a154Run(['p' => 'purchase_orders', 'id' => $a157Po]),
        ['Purchase order A157-PO', 'warehouse ', 'ordered', 'Receive now', 'Post the receipt to stock']);

    // A15.7 step 2: messages of the core services (B2bAdmin, PricingAdmin, SupplierAdmin) follow the admin language.
    $a157CoreLeftovers = ['nemožno', 'neexistuje', 'musí', 'zadajte', 'Riadok', 'uložen', 'vytvoren', 'zmazan', 'nepatrí', 'Prijaté', 'zaúčtovan',
        'povolen', 'Bez zmeny', 'Kupón', 'Zľava', 'Splatnosť', 'Nákup na faktúru', 'ks)', ' ks.'];
    $a157Flash = static function(string $label, array $get, array $post, array $page, array $expect) use ($a154Run, $a157Check, $a157CoreLeftovers): void {
        $a154Run($get, $post);
        $r = $a154Run($page);
        $left = array_values(array_filter($a157CoreLeftovers, static fn(string $t): bool => str_contains($r['out'], $t)));
        assert_true($left === [], "{$label}: no Slovak core message (leftovers=" . json_encode($left, JSON_UNESCAPED_UNICODE) . ')');
        $a157Check($label, $r, $expect);
    };
    $a154Run(['p' => 'dashboard', 'lang' => 'de']);
    $a157Groups = ['p' => 'customer_groups'];
    $a157Flash('A15.7 de: group discount validation flash is German', $a157Groups + ['action' => 'save'], ['name' => 'A157 Zwei', 'code' => '', 'discount' => 'x', 'active' => '1'],
        $a157Groups, ['Der Gruppenrabatt in % muss eine Zahl von 0 bis 100 sein (höchstens zwei Dezimalstellen).']);
    $a157Flash('A15.7 de: group created flash is German', $a157Groups + ['action' => 'save'], ['name' => 'A157 Zwei', 'code' => '', 'discount' => '2,5', 'active' => '1'],
        $a157Groups, ['Gruppe erstellt.']);
    $a157Flash('A15.7 de: B2B assignment flash is German', ['p' => 'customer', 'action' => 'b2b', 'id' => $a156Customer], ['customer_group_id' => $a157Group, 'company_id' => ''],
        ['p' => 'customer', 'id' => $a156Customer], ['Zuordnung zu Gruppe und Firma gespeichert.']);
    $a157Flash('A15.7 de: blocked group delete flash is German', $a157Groups + ['action' => 'delete'], ['customer_group_id' => $a157Group],
        $a157Groups, ['Gruppe a157-gruppe kann nicht gelöscht werden, sie wird verwendet von: Kunden. Deaktivieren Sie sie.']);
    $a157CompanyPost = ['legal_name' => 'A157 AG', 'code' => '', 'registration_number' => '', 'vat_id' => '', 'approval_status' => 'pending', 'credit_limit' => '0',
        'payment_terms_days' => '0', 'invoice_purchase' => '1', 'active' => '1'];
    $a157Flash('A15.7 de: company invoice terms flash is German', ['p' => 'companies', 'action' => 'save'], $a157CompanyPost,
        ['p' => 'companies'], ['Kauf auf Rechnung erfordert ein Zahlungsziel von mindestens 1 Tag.']);
    $a157Flash('A15.7 de: company created flash is German', ['p' => 'companies', 'action' => 'save'], ['payment_terms_days' => '30'] + $a157CompanyPost,
        ['p' => 'companies'], ['Firma erstellt. Kauf auf Rechnung wird erst nach der Freigabe angeboten.']);
    $a157Promo = ['name' => 'A157 Korb', 'code' => '', 'scope' => 'catalog', 'coupon_code' => 'A157KORB', 'priority' => '0', 'stacking' => 'stack', 'valid_from' => '',
        'valid_to' => '', 'action_type' => 'discount_percent', 'action_value' => '10', 'active' => '1'];
    $a157Flash('A15.7 de: coupon on a catalog promotion flash is German', ['p' => 'promotions', 'action' => 'save'], $a157Promo,
        ['p' => 'promotions'], ['Nur eine Warenkorbaktion kann einen Gutscheincode haben.']);
    $a157Flash('A15.7 de: refused cart action flash is German', ['p' => 'promotions', 'action' => 'save'], ['scope' => 'cart', 'action_type' => 'fixed_price'] + $a157Promo,
        ['p' => 'promotions'], ['Die Aktion Festpreis (Cent) ist für einen Gutschein nicht erlaubt (erlaubt: Rabatt in %, Rabatt um Betrag (Cent), Kostenloser Versand (Warenkorb oder Gutschein)).']);
    $a157Flash('A15.7 de: promotion date flash is German', ['p' => 'promotions', 'action' => 'save'], ['coupon_code' => '', 'valid_from' => 'gestern'] + $a157Promo,
        ['p' => 'promotions'], ['Gültig ab: ungültiges Datum (JJJJ-MM-TT HH:MM, UTC).']);
    $a157Lists = ['p' => 'price_lists', 'id' => $a157List];
    $a157Flash('A15.7 de: price line format flash is German', $a157Lists + ['action' => 'prices'], ['prices' => 'A155-P | 1'],
        $a157Lists, ['Zeile 1: geben Sie SKU | Mindestmenge | Preis in Cent [| ursprünglicher Preis] ein.']);
    $a157Flash('A15.7 de: prices saved flash is German', $a157Lists + ['action' => 'prices'], ['prices' => 'A155-P | 1 | 900'],
        $a157Lists, ['Preise der Preisliste gespeichert (1).']);
    $a157Suppliers = ['p' => 'suppliers', 'id' => $a157Supplier];
    $a157Flash('A15.7 de: unknown offer SKU flash is German', $a157Suppliers + ['action' => 'products'], ['products' => 'A157-NONE | X | 100'],
        $a157Suppliers, ['Zeile 1: SKU "A157-NONE" ist weder ein Produkt noch eine Variante des Katalogs dieses Shops.']);
    $a157Flash('A15.7 de: blocked supplier delete flash is German', ['p' => 'suppliers', 'action' => 'delete'], ['supplier_id' => $a157Supplier],
        ['p' => 'suppliers'], ['Lieferant a157-sup kann nicht gelöscht werden: er hat Bestellungen (1); deaktivieren Sie ihn.']);
    $a157Orders = ['p' => 'purchase_orders'];
    $a157Flash('A15.7 de: purchase order quantity flash is German', $a157Orders + ['action' => 'create'],
        ['supplier_id' => $a157Supplier, 'warehouse_id' => $a157Warehouse, 'number' => '', 'expected_at' => '', 'items' => 'A155-P | 0'],
        $a157Orders, ['Zeile 1: die Menge muss 1 bis 1000000 sein.']);
    $a157Item = (string)$a154Pdo->query("SELECT id FROM purchase_order_items WHERE purchase_order_id = " . (int)$a157Po)->fetchColumn();
    $a157Detail = ['p' => 'purchase_orders', 'id' => $a157Po];
    $a157Flash('A15.7 de: receipt range flash is German', $a157Detail + ['action' => 'receive'], ['receipt_key' => str_repeat('a', 32), 'receive' => [$a157Item => '9']],
        $a157Detail, ['A155-P: annehmbar sind 1 bis 3 Stk. (bestellt 3, erhalten 0).']);
    $a157Flash('A15.7 de: receipt booked flash is German', $a157Detail + ['action' => 'receive'], ['receipt_key' => str_repeat('b', 32), 'receive' => [$a157Item => '1']],
        $a157Detail, ['Erhalten: A155-P: 1 Stück.']);
    $a157Flash('A15.7 de: repeated receipt flash is German', $a157Detail + ['action' => 'receive'], ['receipt_key' => str_repeat('b', 32), 'receive' => [$a157Item => '1']],
        $a157Detail, ['Dieser Wareneingang wurde bereits gebucht; nichts wurde geändert.']);

    // R7 (low, axe "label"): every visible form field of the admin screens has an accessible name — a <label for>,
    // a wrapping <label>, aria-label, aria-labelledby or title (a placeholder alone is no label).
    $r7aUnlabelled = static function(string $html): array {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);
        $labelled = [];
        foreach ($xpath->query('//label[@for]') as $label) $labelled[$label->getAttribute('for')] = trim($label->textContent) !== '';
        $found = [];
        foreach ($xpath->query('//main//input | //main//select | //main//textarea') as $field) {
            if ($field->nodeName === 'input' && in_array(strtolower($field->getAttribute('type')), ['hidden', 'submit', 'button', 'reset', 'image'], true)) continue;
            $named = trim($field->getAttribute('aria-label')) !== '' || trim($field->getAttribute('aria-labelledby')) !== '' || trim($field->getAttribute('title')) !== ''
                || ($field->getAttribute('id') !== '' && ($labelled[$field->getAttribute('id')] ?? false));
            for ($parent = $field->parentNode; !$named && $parent instanceof DOMElement; $parent = $parent->parentNode) {
                if ($parent->nodeName === 'label') $named = trim($parent->textContent) !== '';
            }
            if (!$named) $found[] = $field->nodeName . '[name=' . $field->getAttribute('name') . ']';
        }
        return array_values(array_unique($found));
    };
    $a154Run(['p' => 'dashboard', 'lang' => 'en']);
    foreach ([['p' => 'dashboard'], ['p' => 'orders'], ['p' => 'sales'], $a156Order, ['p' => 'rma'], ['p' => 'customers'], ['p' => 'customer', 'id' => $a156Customer],
        ['p' => 'awaiting_payment'], ['p' => 'abandoned_carts'], ['p' => 'customer_groups'], ['p' => 'companies'], ['p' => 'promotions'], ['p' => 'price_lists'],
        ['p' => 'price_lists', 'id' => $a157List], ['p' => 'suppliers', 'id' => $a157Supplier], ['p' => 'purchase_orders'], ['p' => 'purchase_orders', 'id' => $a157Po],
        ['p' => 'shipping'], ['p' => 'payments'], ['p' => 'warehouses'], ['p' => 'feeds'], ['p' => 'feed'], ['p' => 'import'], ['p' => 'cache'], ['p' => 'audit'],
        ['p' => 'categories'], ['p' => 'brands'], ['p' => 'attributes'], ['p' => 'attribute'], ['p' => 'products'], ['p' => 'product'], ['p' => 'product', 'id' => (string)$a155Product],
        ['p' => 'pages'], ['p' => 'page_edit'], ['p' => 'content'], ['p' => 'digital_assets'], ['p' => 'reviews']] as $r7aGet) {
        $r = $a154Run($r7aGet);
        $r7aMissing = $r['code'] === 200 ? $r7aUnlabelled((string)$r['out']) : ['code ' . var_export($r['code'], true)];
        assert_equals([], $r7aMissing, 'R7 admin a11y: every form field of ' . http_build_query($r7aGet) . ' has an accessible name');
    }
} finally {
    putenv('BBE_ADMIN_IDENTITY_KEY');
    $a154Pdo = null;
    $a154Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a154Dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a154Tree as $a154Path) $a154Path->isDir() ? rmdir($a154Path->getPathname()) : unlink($a154Path->getPathname());
    rmdir($a154Dir);
}
