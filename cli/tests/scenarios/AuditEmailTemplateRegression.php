<?php

// A15.8: admin e-mail template editor. Per store + locale overrides of templates/emails are what MailService renders;
// only the template's own variables, no active HTML, reset to the file default, permission and store scope.
echo "\n--- Audit A15.8: admin e-mail template overrides (allowlist, unsafe HTML, reset, permission, store scope) ---\n";

foreach (['owner' => true, 'manager' => true, 'editor' => false, 'fulfillment' => false, 'viewer' => false] as $a158Role => $a158Allowed) {
    $a158Session = $_SESSION ?? [];
    $_SESSION = ['admin_role' => $a158Role];
    assert_equals($a158Allowed, Security::adminCan('emails.manage'), "A15.8 role: {$a158Role} " . ($a158Allowed ? 'may' : 'may not') . ' edit e-mail templates');
    $_SESSION = $a158Session;
}
$a158Permissions = require dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Permissions.php';
assert_equals('emails.manage', $a158Permissions['email_templates'] ?? null, 'A15.8 role: the e-mail templates page requires emails.manage');

// R8: an order-confirmation override must keep the consumer information (EmailTemplates::REQUIRED_VARIABLES).
$a158Consumer = '<p>{{seller_name}}, {{seller_address}} <a href="{{withdrawal_url}}">withdraw</a> <a href="{{terms_url}}">terms</a></p>';
$a158PreviousStore = StoreContext::current();
$a158PreviousLocale = I18n::locale();
$a158Stores = Bootstrap::stores();
$a158Activate = static function(string $store) use ($a158Stores): void {
    StoreContext::activate(StoreContext::resolve($a158Stores, '/', '', $store));
};
try {
    I18n::setLocale('en');
    $a158Activate('default');
    Db::get()->prepare("DELETE FROM email_template_overrides WHERE template IN ('order_confirmation', 'order_shipped')")->execute();

    // Every shipped default passes the same validation an override gets: the editor never rejects the defaults.
    $a158Rejected = [];
    foreach (EmailTemplates::templates() as $a158Template) {
        foreach (['sk', 'cs', 'en', 'de', 'hu', 'pl', 'es'] as $a158Locale) {
            $a158Src = EmailTemplates::fileSource($a158Template, $a158Locale);
            if (EmailTemplates::validate($a158Template, (string)$a158Src['subject'], (string)$a158Src['html'], (string)$a158Src['text'])) $a158Rejected[] = "{$a158Template}/{$a158Locale}";
        }
    }
    assert_true(count(EmailTemplates::templates()) >= 15 && $a158Rejected === [], 'A15.8 defaults: all ' . count(EmailTemplates::templates()) . ' file templates in every locale pass the override validation (rejected=' . json_encode($a158Rejected) . ')');
    $a158Vars = EmailTemplates::allowedVariables('order_confirmation');
    assert_true(in_array('order_number', $a158Vars, true) && in_array('items', $a158Vars, true) && in_array('sku', $a158Vars, true) && in_array('bank_iban', $a158Vars, true)
        && !in_array('reset_url', $a158Vars, true), 'A15.8 allowlist: order_confirmation allows its own variables (incl. {{#each items}} fields) and not reset_url');
    assert_equals(['cs', 'en', 'sk'], (static function(array $l): array { sort($l); return $l; })(EmailTemplates::locales()), 'A15.8 locales: overrides exist for the active store\'s languages');

    // Store override is what MailService renders, values stay escaped, other locales/stores keep the files.
    $a158Fields = ['subject' => 'A158 Objednávka {{order_number}}', 'html' => "<p>A158 {{customer_name}}: {{order_number}}</p>\r\n{{#each items}}<li>{{name}} × {{quantity}}</li>{{/each}}" . $a158Consumer, 'text' => ''];
    $a158Saved = EmailTemplates::save('order_confirmation', 'sk', $a158Fields);
    assert_true($a158Saved['ok'], 'A15.8 save: a valid override is stored (' . $a158Saved['message'] . ')');
    $a158Vars = ['order_number' => 'A158-N1', 'customer_name' => '<b>Eva</b>', 'items' => [['name' => 'Tričko', 'quantity' => 2]]];
    $a158Mail = MailService::render('order_confirmation', 'sk', $a158Vars);
    assert_equals('A158 Objednávka A158-N1', $a158Mail['subject'], 'A15.8 render: MailService uses the store override subject');
    assert_true(str_contains($a158Mail['html'], '<p>A158 &lt;b&gt;Eva&lt;/b&gt;: A158-N1</p>') && str_contains($a158Mail['html'], '<li>Tričko × 2</li>') && !str_contains($a158Mail['html'], "\r"),
        'A15.8 render: override HTML is rendered with escaped values and {{#each}} rows (' . substr($a158Mail['html'], 0, 160) . ')');
    assert_true(str_contains($a158Mail['text'], 'A158 <b>Eva</b>: A158-N1'), 'A15.8 render: an empty text part is derived from the override HTML');
    $a158FileSk = MailService::renderString((string)EmailTemplates::fileSource('order_confirmation', 'sk')['subject'], $a158Vars, false);
    assert_true(!str_contains(MailService::render('order_confirmation', 'en', $a158Vars)['html'], '<p>A158 '), 'A15.8 render: the English message of the same store keeps the file template');
    $a158Activate('cz');
    assert_true(!str_contains(MailService::render('order_confirmation', 'sk', $a158Vars)['html'], '<p>A158 ')
        && trim($a158FileSk) === MailService::render('order_confirmation', 'sk', $a158Vars)['subject'], 'A15.8 store scope: another store renders the file template for the same locale');
    assert_true(EmailTemplates::override('order_confirmation', 'sk') === null, 'A15.8 store scope: another store does not see the override');
    EmailTemplates::reset('order_confirmation', 'sk');
    $a158Activate('default');
    assert_true(EmailTemplates::override('order_confirmation', 'sk') !== null, 'A15.8 store scope: a reset in another store leaves this store\'s override');

    // Rejected drafts leave the stored override untouched.
    $a158Before = EmailTemplates::override('order_confirmation', 'sk');
    $a158Reject = static function(string $label, array $fields, string $expect) use ($a158Fields, $a158Before): void {
        $r = EmailTemplates::save('order_confirmation', 'sk', $fields + $a158Fields);
        assert_true(!$r['ok'] && str_contains($r['message'], $expect) && EmailTemplates::override('order_confirmation', 'sk') == $a158Before,
            "A15.8 validation: {$label} is refused (" . $r['message'] . ')');
    };
    $a158Reject('a variable of another template', ['html' => '<a href="{{reset_url}}">x</a>'], 'reset_url');
    $a158Reject('a <script> element', ['html' => '<p>x</p><script>alert(1)</script>'], '<script>');
    $a158Reject('an event handler attribute', ['html' => '<img src="x.png" onerror="alert(1)">'], 'on*=');
    $a158Reject('a javascript: link', ['html' => '<a href="javascript:alert(1)">x</a>'], 'javascript:');
    $a158Reject('an entity-encoded javascript: link', ['html' => '<a href="jav&#x61;script&colon;alert(1)">x</a>'], 'javascript:');
    $a158Reject('an embedded frame', ['html' => '<iframe src="https://evil.example"></iframe>'], '<iframe>');
    $a158Reject('a form', ['html' => '<form action="https://evil.example"><input name="card"></form>'], '<form>');
    $a158Reject('an unclosed block', ['html' => '{{#if tracking_url}}<p>x</p>'], '#if');
    $a158Reject('a triple-brace tag', ['html' => '<p>{{{order_number}}}</p>'], '{{{order_number}}');
    $a158Reject('an empty subject', ['subject' => '  '], 'subject');
    $a158Reject('a subject with a header break', ['subject' => "A158\r\nBcc: x@example.com"], 'subject');
    $a158Reject('an unknown variable in the subject', ['subject' => 'A158 {{password}}'], 'password');
    $a158Reject('a non-scalar field', ['html' => ['x']], 'form');
    assert_true(!EmailTemplates::save('order_confirmation', 'de', $a158Fields)['ok'] && !EmailTemplates::save('../order_confirmation', 'sk', $a158Fields)['ok']
        && !EmailTemplates::save('order_confirmation', 'sk/../de', $a158Fields)['ok'], 'A15.8 validation: a language the store does not offer or an unknown template name is refused');

    // Reset brings back the file default; both writes are audited in the store.
    $a158Reset = EmailTemplates::reset('order_confirmation', 'sk');
    assert_true($a158Reset['ok'] && EmailTemplates::override('order_confirmation', 'sk') === null && !str_contains(MailService::render('order_confirmation', 'sk', $a158Vars)['html'], '<p>A158 '),
        'A15.8 reset: the override is deleted and MailService renders the file template again');
    $a158Audit = Db::get()->prepare("SELECT action FROM audit_log WHERE entity = 'email_template' AND store_id = 'default' AND new_value LIKE :tpl OR (entity = 'email_template' AND store_id = 'default' AND old_value LIKE :tpl2) ORDER BY id");
    $a158Audit->execute([':tpl' => '%order_confirmation%', ':tpl2' => '%order_confirmation%']);
    $a158Actions = array_column($a158Audit->fetchAll(PDO::FETCH_ASSOC), 'action');
    assert_true(in_array('create', $a158Actions, true) && in_array('delete', $a158Actions, true), 'A15.8 audit: save and reset are in the store audit log (' . implode(',', $a158Actions) . ')');
    $a158Preview = EmailTemplates::preview('order_shipped', 'sk');
    assert_true(str_contains($a158Preview['html'], 'SK-2026-000123') && str_contains($a158Preview['html'], 'Packeta') && $a158Preview['subject'] !== '',
        'A15.8 preview: the stored template renders with the sample order');
} finally {
    StoreContext::activate($a158PreviousStore);
    I18n::setLocale($a158PreviousLocale);
}

// Native MySQL/MariaDB: the migration, an unchanged re-save (0 affected rows) and reset on a fresh database with the
// typical utf8mb4_unicode_ci default; the table inherits the database collation.
if (($a158Dsn = getenv('TEST_MYSQL_DSN')) !== false && $a158Dsn !== '') {
    $a158SuiteDb = Db::get();
    $a158SuiteDriver = Db::driver();
    $a158My = new PDO($a158Dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $a158MyDb = 'bbe_a158_' . bin2hex(random_bytes(4));
    $a158My->exec("CREATE DATABASE `{$a158MyDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        $a158My->exec("USE `{$a158MyDb}`");
        Db::init($a158My, 'mysql');
        (new Migrator($a158My, dirname(TEST_CLI_DIR) . '/migrations', test_fixture_path('a158-mysql') . '.lock'))->apply();
        $a158Activate('default');
        $a158Native = ['subject' => 'A158 Natív {{order_number}}', 'html' => '<p>A158 natív {{customer_name}} – {{order_number}}</p>' . $a158Consumer, 'text' => 'A158 natív {{order_number}}'];
        $a158First = EmailTemplates::save('order_confirmation', 'sk', $a158Native);
        $a158Again = EmailTemplates::save('order_confirmation', 'sk', $a158Native);
        $a158NativeMail = MailService::render('order_confirmation', 'sk', ['order_number' => 'N-1', 'customer_name' => 'Ľubo']);
        $a158Count = (int)$a158My->query('SELECT COUNT(*) FROM email_template_overrides')->fetchColumn();
        assert_true($a158First['ok'] && $a158Again['ok'] && $a158Count === 1 && $a158NativeMail['subject'] === 'A158 Natív N-1'
            && str_starts_with($a158NativeMail['html'], '<p>A158 natív Ľubo – N-1</p><p>') && str_contains($a158NativeMail['html'], '>withdraw</a>')
            && $a158NativeMail['text'] === 'A158 natív N-1',
            "A15.8 native {$a158SuiteDriver}->mysql: save, unchanged re-save and render keep one UTF-8 override (rows={$a158Count})");
        $a158Collation = $a158My->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_template_overrides'")->fetchColumn();
        assert_equals('utf8mb4_unicode_ci', $a158Collation, 'A15.8 native mysql: the overrides table inherits the database collation');
        assert_true(EmailTemplates::reset('order_confirmation', 'sk')['ok'] && (int)$a158My->query('SELECT COUNT(*) FROM email_template_overrides')->fetchColumn() === 0,
            'A15.8 native mysql: reset deletes the override');
    } finally {
        StoreContext::activate($a158PreviousStore);
        I18n::setLocale($a158PreviousLocale);
        Db::init($a158SuiteDb, $a158SuiteDriver);
        $a158My->exec("DROP DATABASE IF EXISTS `{$a158MyDb}`");
        $a158My = null;
    }
}

// Rendered admin (own SQLite database): German screens, editor, draft preview, save, reset, 403 without the permission.
foreach ($GLOBALS as $a158Value) if ($a158Value instanceof PDOStatement) $a158Value->closeCursor();
$a158Root = dirname(TEST_CLI_DIR);
$a158Dir = test_fixture_path('admin-email-templates');
mkdir($a158Dir);
mkdir($a158Dir . '/sessions');
$a158DbPath = $a158Dir . '/admin.sqlite';
$a158SuiteDb = Db::get();
$a158SuiteDriver = Db::driver();
$a158Pdo = new PDO('sqlite:' . $a158DbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$a158Key = base64_encode(str_repeat('M', 32));
putenv('BBE_ADMIN_IDENTITY_KEY=' . $a158Key);
try {
    Db::init($a158Pdo, 'sqlite');
    (new Migrator($a158Pdo, $a158Root . '/migrations', $a158Dir . '/migrate.lock'))->apply();
    AdminIdentity::grantStore(AdminIdentity::create('a158-owner', 'A158-password-owner', 'owner', false), 'default', ['*']);
    AdminIdentity::grantStore(AdminIdentity::create('a158-viewer', 'A158-password-viewer', 'viewer', false), 'default', ['*']);
    $a158Tokens = [];
    foreach (['owner', 'viewer'] as $a158Who) {
        $a158Tokens[$a158Who] = (string)(AdminIdentity::authenticate("a158-{$a158Who}", 'A158-password-' . $a158Who, '', '127.0.15.80', 'A158')['token'] ?? '');
    }
} finally {
    Db::init($a158SuiteDb, $a158SuiteDriver);
}
file_put_contents($a158Dir . '/admin.php', <<<'PHP'
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
session_id('a158' . substr(hash('sha256', $token), 0, 16));
session_start();
$_SESSION['csrf_token'] = 'a158';
$_SESSION['admin_identity_token'] = $token;
$_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.15.81';
$_SERVER['HTTP_USER_AGENT'] = 'A158';
$_SERVER['REQUEST_URI'] = '/admin.php';
$_GET = json_decode($getJson, true);
$_POST = $post === [] ? [] : $post + ['csrf_token' => 'a158'];
ob_start();
register_shutdown_function(static function(): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nA158RESULT" . json_encode(['code' => http_response_code() ?: 200, 'out' => $out], JSON_INVALID_UTF8_SUBSTITUTE);
});
require $root . '/src/Entrypoints/Admin.php';
PHP);
$a158Run = static function(string $who, array $get, array $post = []) use ($a158Dir, $a158Root, $a158DbPath, $a158Key, &$a158Tokens): array {
    $process = proc_open([PHP_BINARY, $a158Dir . '/admin.php', $a158Root, $a158DbPath, $a158Dir, $a158Key, $a158Tokens[$who], json_encode($get), json_encode($post)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $at = strrpos($stdout, 'A158RESULT');
    $result = $at === false ? null : json_decode(substr($stdout, $at + 10), true);
    return is_array($result) ? $result : ['code' => null, 'out' => substr($stderr . ' | ' . substr($stdout, -300), 0, 900)];
};
$a158Rows = static function() use ($a158Pdo): array {
    return $a158Pdo->query("SELECT store_id, template, locale, subject FROM email_template_overrides ORDER BY template, locale")->fetchAll(PDO::FETCH_ASSOC);
};
$a158Check = static function(string $label, array $r, array $expect, array $absent = []): void {
    $missing = array_values(array_filter($expect, static fn(string $t): bool => !str_contains($r['out'], $t)));
    $present = array_values(array_filter($absent, static fn(string $t): bool => str_contains($r['out'], $t)));
    assert_true($r['code'] === 200 && $missing === [] && $present === [], "{$label} (code=" . var_export($r['code'], true)
        . ' missing=' . json_encode($missing, JSON_UNESCAPED_UNICODE) . ' present=' . json_encode($present, JSON_UNESCAPED_UNICODE)
        . ($missing || $r['code'] !== 200 ? ' main=' . substr((string)(strstr($r['out'], '<main>') ?: $r['out']), 0, 600) : '') . ')');
};
$a158Editor = ['p' => 'email_templates', 'template' => 'order_confirmation', 'locale' => 'sk'];
$a158Draft = ['subject' => 'A158 Bestellung {{order_number}}', 'html' => '<p>A158 Entwurf {{order_number}} für {{customer_name}}</p>' . $a158Consumer, 'text' => ''];
try {
    $a158Check('A15.8 de: e-mail template list is German with every template and store language', $a158Run('owner', ['p' => 'email_templates', 'lang' => 'de']),
        ['E-Mail-Vorlagen', 'Bestellbestätigung', 'Passwort zurücksetzen', 'Standard', '>SK<', '>CS<', '>EN<', 'order_shipped', 'merchant_stock_low']);
    $a158Check('A15.8 de: editor shows the file template, allowed variables and a sandboxed sample-order preview', $a158Run('owner', $a158Editor),
        ['Vorlage order_confirmation (SK)', 'Erlaubte Variablen', '{{bank_iban}}', 'Vorschau mit einer Beispielbestellung', 'sandbox=""', 'SK-2026-000123', 'Jana Nováková',
            'die Standardvorlage wird verwendet', 'Vorschau ohne Speichern'], ['Standardvorlage wiederherstellen<']);
    $a158Check('A15.8 de: draft preview renders the unsaved draft with the sample order', $a158Run('owner', $a158Editor + ['action' => 'preview'], $a158Draft),
        ['Vorschau des ungespeicherten Entwurfs', 'A158 Entwurf SK-2026-000123 für Jana Nováková', 'A158 Bestellung SK-2026-000123']);
    assert_equals([], $a158Rows(), 'A15.8 de: a preview stores nothing');
    $a158Run('owner', $a158Editor + ['action' => 'save'], ['html' => '<p>A158</p><script>alert(1)</script>'] + $a158Draft);
    assert_equals([], $a158Rows(), 'A15.8 de: an unsafe draft is not stored through the admin');
    $a158Check('A15.8 de: refused save keeps the draft in the form and explains why in German',
        $a158Run('owner', $a158Editor + ['action' => 'save'], ['html' => '<p>A158 {{reset_url}}</p><img src="x" onerror="alert(1)">'] + $a158Draft),
        ['Diese Vorlage kennt die Variablen nicht: reset_url.', 'Das HTML enthält nicht erlaubte Inhalte: on*=.', '&lt;p&gt;A158 {{reset_url}}&lt;/p&gt;', 'Vorschau mit einer Beispielbestellung'],
        ['onerror="alert(1)"']);
    $a158Run('owner', $a158Editor + ['action' => 'save'], $a158Draft);
    $a158Stored = $a158Rows();
    assert_true(count($a158Stored) === 1 && $a158Stored[0]['store_id'] === 'default' && $a158Stored[0]['subject'] === 'A158 Bestellung {{order_number}}',
        'A15.8 de: a valid save stores the override for the active store and locale (' . json_encode($a158Stored, JSON_UNESCAPED_UNICODE) . ')');
    $a158Check('A15.8 de: saved flash, customised state and reset button are German', $a158Run('owner', $a158Editor),
        ['Vorlage order_confirmation (sk) gespeichert.', 'angepasst am', 'A158 Entwurf SK-2026-000123', 'Standardvorlage wiederherstellen', 'Die Anpassung für diesen Shop und diese Sprache wird gelöscht.']);
    $a158Check('A15.8 de: the list marks the customised locale', $a158Run('owner', ['p' => 'email_templates']), ['angepasst']);

    $a158Viewer = $a158Run('viewer', ['p' => 'email_templates']);
    assert_true($a158Viewer['code'] === 403 && !str_contains($a158Viewer['out'], 'A158'), 'A15.8 permission: without emails.manage the editor answers 403 (code=' . var_export($a158Viewer['code'], true) . ')');
    $a158Run('viewer', $a158Editor + ['action' => 'reset'], ['confirm' => '1']);
    assert_equals(1, count($a158Rows()), 'A15.8 permission: without emails.manage a reset changes nothing');
    $a158Check('A15.8 permission: the menu hides the editor without emails.manage', $a158Run('viewer', ['p' => 'orders']), [], ['p=email_templates']);

    $a158Run('owner', $a158Editor + ['action' => 'reset'], ['confirm' => '1']);
    assert_equals([], $a158Rows(), 'A15.8 reset: the admin reset deletes the override');
    $a158Check('A15.8 de: reset flash is German and the editor is back on the file template', $a158Run('owner', $a158Editor),
        ['verwendet den Standardtext', 'die Standardvorlage wird verwendet'], ['A158 Entwurf']);
    $a158Run('owner', ['p' => 'dashboard', 'lang' => 'en']);
    $a158Check('A15.8 en: list and menu are English', $a158Run('owner', ['p' => 'email_templates']), ['E-mail templates', 'order confirmation', 'default'], ['angepasst']);
} finally {
    putenv('BBE_ADMIN_IDENTITY_KEY');
    $a158Rows = null;
    $a158Pdo = null;
    $a158Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a158Dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a158Tree as $a158Path) $a158Path->isDir() ? rmdir($a158Path->getPathname()) : unlink($a158Path->getPathname());
    rmdir($a158Dir);
}
