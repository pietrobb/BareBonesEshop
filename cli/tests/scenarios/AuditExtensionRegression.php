<?php

// A16.1 / usability 10: an extension in extensions/<code>/ adds a provider, a domain event listener and storefront
// view-model data without editing a core file; the real worker runs its listeners with per-listener retry; an update
// never overwrites extensions/ and the drift guard does not report it; a broken extension is skipped as a whole with a
// readable reason while the shop and the other extensions keep working.
echo "\n--- Audit A16.1: extensions without core edits (providers, event listeners, storefront, update, drift) ---\n";

$a161Root = test_fixture_path('extensions');
$a161Write = static function(string $base, array $files): void {
    foreach ($files as $path => $content) {
        $absolute = $base . '/' . $path;
        if (!is_dir(dirname($absolute))) mkdir(dirname($absolute), 0750, true);
        file_put_contents($absolute, $content);
    }
};
$a161Manifest = static fn(string $code, string $register): string => "<?php\nreturn ['code' => '{$code}', 'name' => '{$code} test', 'version' => '1.0.0',\n"
    . "    'register' => static function (ExtensionApi \$api): void {\n{$register}\n    },\n];\n";
$a161Ext = $a161Root . '/extensions';
$a161Write($a161Ext, [
    'acme-loyalty/extension.php' => $a161Manifest('acme-loyalty', <<<'PHP'
        $api->provider('mail', 'acme-mail', AcmeLoyaltyMail::class, ['send']);
        $api->on('order.created', 'points', static function (array $event): void {
            file_put_contents(__DIR__ . '/events.log', 'points:' . $event['id'] . ':' . $event['data']['order_number'] . ':' . $event['store_id'] . "\n", FILE_APPEND);
        });
        $api->on('order.*', 'flaky', static function (array $event): void {
            if (!is_file(__DIR__ . '/flaky.ok')) throw new RuntimeException('acme flaky listener not ready');
            file_put_contents(__DIR__ . '/events.log', 'flaky:' . $event['id'] . "\n", FILE_APPEND);
        });
        $api->storefront('product', 'acme-loyalty.points', static fn(array $context): array => ['earn' => intdiv((int)$context['source']['price'], 100)]);
PHP),
    'acme-loyalty/src/AcmeLoyaltyMail.php' => "<?php\nfinal class AcmeLoyaltyMail implements MailAdapter {\n"
        . "    public function send(array \$msg): array { file_put_contents(dirname(__DIR__) . '/mail.log', \$msg['to'] . '|' . \$msg['subject'] . \"\\n\", FILE_APPEND); return ['status' => 'sent', 'message' => '', 'raw' => null]; }\n"
        . "    public function adapterId(): string { return 'acme-mail'; }\n}\n",
    'broken-throws/extension.php' => $a161Manifest('broken-throws', <<<'PHP'
        $api->provider('mail', 'thrown-mail', BrokenThrowsMail::class);
        $api->on('order.created', 'never', static function (array $event): void {});
        $api->storefront('product', 'broken-throws', static fn(array $context): array => ['leaked' => true]);
        throw new RuntimeException('boom from register');
PHP),
    'broken-throws/src/BrokenThrowsMail.php' => "<?php\nfinal class BrokenThrowsMail implements MailAdapter {\n    public function send(array \$msg): array { return ['status' => 'sent', 'message' => '', 'raw' => null]; }\n    public function adapterId(): string { return 'x'; }\n}\n",
    'broken-class/extension.php' => $a161Manifest('broken-class', "        \$api->provider('payment', 'broken-pay', 'A161MissingPay');"),
    'broken-iface/extension.php' => $a161Manifest('broken-iface', "        \$api->provider('payment', 'iface-pay', A161NotAPayment::class);"),
    'broken-iface/src/A161NotAPayment.php' => "<?php\nfinal class A161NotAPayment {}\n",
    'broken-collide/extension.php' => $a161Manifest('broken-collide', "        \$api->provider('mail', 'smtp', AcmeLoyaltyMail::class);"),
    'broken-ns/extension.php' => $a161Manifest('broken-ns', "        \$api->storefront('product', 'reviews.product', static fn(array \$c): array => ['fake' => 5]);"),
    'broken-event/extension.php' => $a161Manifest('broken-event', "        \$api->on('order.deleted', 'gone', static function (array \$e): void {});"),
    'wrong-code/extension.php' => $a161Manifest('other-code', ''),
    'disabled-one/extension.php' => $a161Manifest('disabled-one', "        \$api->storefront('product', 'disabled-one', static fn(array \$c): array => ['on' => true]);"),
]);
$a161Config = ['extensions' => ['acme-loyalty', 'broken-throws', 'broken-class', 'broken-iface', 'broken-collide', 'broken-ns', 'broken-event', 'wrong-code', 'missing-one', 'Bad_Code']];

try {
    // An update never writes extensions/: the builder leaves it out and the updater treats it as a deployment path.
    $a161Upd = $a161Root . '/update';
    $a161Write($a161Upd . '/base', ['src/A.php' => "old a\n"]);
    $a161Write($a161Upd . '/target', ['src/A.php' => "new a\n", 'extensions/acme-loyalty/extension.php' => "<?php // vendor copy\n"]);
    $a161Write($a161Upd . '/installed', ['src/A.php' => "old a\n", 'extensions/acme-loyalty/extension.php' => "<?php // the shop's own code\n"]);
    $a161Applied = null;
    $a161Paths = [];
    try {
        UpdatePackageBuilder::build($a161Upd . '/base', $a161Upd . '/target', $a161Upd . '/package', '9.16.1', '9.16.0');
        $a161Package = new UpdatePackage($a161Upd . '/package', null, true);
        $a161Paths = array_column($a161Package->entries(), 'path');
        $a161Updater = new Updater($a161Upd . '/installed', $a161Upd . '/state');
        $a161Updater->baseline('9.16.0');
        $a161Applied = $a161Updater->apply($a161Package);
    } catch (Throwable $e) {
        $a161Applied = ['error' => $e->getMessage()];
    }
    assert_equals(['src/A.php'], $a161Paths, 'A16.1 update: the package builder never packages extensions/ (' . json_encode($a161Paths) . ')');
    assert_true(($a161Applied['version'] ?? null) === '9.16.1' && file_get_contents($a161Upd . '/installed/src/A.php') === "new a\n"
        && file_get_contents($a161Upd . '/installed/extensions/acme-loyalty/extension.php') === "<?php // the shop's own code\n",
        'A16.1 update: the core file is updated and the installed extension survives unchanged (' . json_encode($a161Applied) . ')');
    assert_true(Updater::isProtectedPath('extensions/acme-loyalty/extension.php') && Updater::isProtectedPath('Extensions/x/src/Y.php'),
        'A16.1 update: a package entry under extensions/ is a protected deployment path the updater refuses to write');

    // The drift guard records core files only: adding an extension later is not drift.
    $a161Write($a161Root . '/drift', ['src/A.php' => "a\n", 'index.php' => "<?php\n"]);
    $a161Integrity = new CoreIntegrity($a161Root . '/drift', $a161Root . '/drift-state');
    $a161Integrity->record('test');
    $a161Write($a161Root . '/drift', ['extensions/acme-loyalty/extension.php' => "<?php\n", 'extensions/acme-loyalty/src/AcmeLoyaltyMail.php' => "<?php\n"]);
    $a161Drift = $a161Integrity->check();
    assert_true($a161Drift['status'] === 'clean' && $a161Drift['added'] === [], 'A16.1 drift guard: an added extension is not reported (' . json_encode($a161Drift) . ')');

    // Not served over HTTP: Apache (.htaccess, also used by router.php) and nginx deny the directory.
    $a161Htaccess = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/.htaccess');
    $a161Denied = preg_match('/^RewriteRule (\^\(\?:storage\S+) - \[F,L,NC\]\r?$/m', $a161Htaccess, $a161Rule) === 1
        && preg_match('#' . $a161Rule[1] . '#i', 'extensions/acme-loyalty/extension.php') === 1;
    assert_true($a161Denied, 'A16.1 web: .htaccess denies extensions/ like src/ and adapters/');
    assert_true(str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/deploy/nginx.conf'), '|adapters|extensions|'), 'A16.1 web: deploy/nginx.conf denies extensions/');

    if (!class_exists('Extensions')) {
        assert_true(false, 'A16.1 boot: core has an extension loader (class Extensions)');
    } else {
        Extensions::reset();
        Extensions::boot($a161Config, $a161Ext);
        assert_equals(['acme-loyalty'], array_keys(Extensions::loaded()), 'A16.1 boot: only the valid enabled extension is loaded; a present but not enabled one is not');
        $a161Errors = Extensions::errors();
        $a161Expect = [
            'broken-throws' => 'boom from register',
            'broken-class' => 'class A161MissingPay not found',
            'broken-iface' => 'must implement PaymentAdapter',
            'broken-collide' => 'mail provider "smtp" already exists',
            'broken-ns' => 'must be "broken-ns" or start with "broken-ns."',
            'broken-event' => 'unknown domain event "order.deleted"',
            'wrong-code' => 'manifest code must be "wrong-code"',
            'missing-one' => 'extension.php not found in extensions/missing-one/',
        ];
        foreach ($a161Expect as $a161Code => $a161Reason) {
            assert_true(str_contains((string)($a161Errors[$a161Code] ?? ''), $a161Reason), "A16.1 broken: {$a161Code} is skipped with a readable reason ({$a161Reason})");
        }
        assert_true(count(array_filter($a161Errors, static fn(string $e): bool => str_contains($e, 'invalid extension code'))) === 1 && count($a161Errors) === 9,
            'A16.1 broken: an invalid code in config is reported too (' . json_encode($a161Errors) . ')');

        // Provider: selected through the core registry like a built-in one; broken extensions leave nothing behind.
        $a161Mail = Adapters::registry('mail');
        assert_true(($a161Mail['acme-mail']['extension'] ?? '') === 'acme-loyalty' && !isset($a161Mail['thrown-mail']) && $a161Mail['smtp']['class'] === 'SmtpMail'
            && !isset(Adapters::registry('payment')['broken-pay']) && !isset(Adapters::registry('payment')['iface-pay']),
            'A16.1 provider: the extension mail provider is registered; core smtp is untouched; nothing from failed extensions');
        $a161Sent = Adapters::mail('acme-mail')->send(['to' => 'a161@example.test', 'subject' => 'A161 hello', 'html' => '', 'text' => 'x']);
        assert_true(($a161Sent['status'] ?? '') === 'sent' && Adapters::providerCode('mail', 'AcmeLoyaltyMail') === 'acme-mail'
            && trim((string)@file_get_contents($a161Ext . '/acme-loyalty/mail.log')) === 'a161@example.test|A161 hello',
            'A16.1 provider: Adapters::mail("acme-mail") resolves the class from the extension src/ and sends');

        // Storefront: the composition root adds extension data next to the core providers.
        StorefrontViewModelRegistry::reset();
        StorefrontComposition::boot();
        $a161Db = Db::get();
        $a161Db->prepare('INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES (?, ?, ?, NULL, ?, 0)')->execute(['default', 'a161cat', 'A161 Cat', 'a161cat']);
        $a161Db->prepare('INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['default', (int)$a161Db->lastInsertId(), 'A161-SKU', 'a161-product', 'A161 Product', 1299, 5, 'active']);
        $a161View = StorefrontViewModel::product(Products::byId((int)$a161Db->lastInsertId()))['extensions'];
        assert_true(($a161View['acme-loyalty']['points'] ?? null) === ['earn' => 12] && StorefrontViewModelRegistry::has('product', 'reviews.product')
            && !isset($a161View['broken-throws']) && !isset($a161View['disabled-one']) && !StorefrontViewModelRegistry::has('product', 'broken-throws'),
            'A16.1 storefront: the real product view model gets extensions.acme-loyalty.points; core reviews stay; failed/disabled extensions add nothing');

        $a161Doctor = Setup::doctor(['extensions' => $a161Config['extensions']] + (require dirname(TEST_CLI_DIR) . '/config.php'), dirname(TEST_CLI_DIR), Db::get());
        $a161Check = array_values(array_filter($a161Doctor['checks'] ?? [], static fn(array $c): bool => $c['name'] === 'extensions'))[0] ?? [];
        assert_true(($a161Check['status'] ?? '') === 'fail' && str_contains((string)$a161Check['message'], 'broken-class: ') && str_contains((string)$a161Check['message'], 'boom from register'),
            'A16.1 doctor: setup doctor fails the extensions check and names each broken extension (' . substr((string)($a161Check['message'] ?? 'no check'), 0, 120) . ')');
    }

    // Domain event listener through the real worker (cli/worker.php --once) on a migrated SQLite shop.
    $a161Fixture = $a161Root . '/worker.php';
    file_put_contents($a161Fixture, <<<'PHP'
<?php
[, $root, $dir, $action] = $argv;
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . $dir . '/shop.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
if ($action === 'setup') { (new Migrator($db, $root . '/migrations', $dir . '/migrate.lock'))->apply(); exit(0); }
Extensions::boot(['extensions' => ['acme-loyalty']], $dir . '/extensions');
Bootstrap::initStoreContext('/', '', 'default');
if ($action === 'record') {
    $db->beginTransaction();
    Outbox::record(DomainEvents::ORDER_CREATED, 'order', 4161, ['order_id' => 4161, 'order_number' => 'A161-1']);
    $db->commit();
} elseif ($action === 'due') {
    $db->exec("UPDATE event_outbox SET available_at = '2000-01-01 00:00:00'");
} elseif ($action === 'worker') {
    JobQueue::useBackend(new DatabaseQueue($db, 30));
    $argv = ['worker.php', '--once'];
    require $root . '/cli/worker.php';
}
echo "\nA161STATE" . json_encode([
    'events' => $db->query('SELECT id, status, last_error FROM event_outbox ORDER BY occurred_at')->fetchAll(),
    'deliveries' => $db->query("SELECT endpoint_code, status, error FROM webhook_deliveries ORDER BY id")->fetchAll(),
]);
PHP);
    $a161Run = static function(string $action) use ($a161Fixture, $a161Root): array {
        $process = proc_open([PHP_BINARY, $a161Fixture, dirname(TEST_CLI_DIR), $a161Root, $action], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $state = str_contains($raw, "\nA161STATE") ? json_decode(substr($raw, strrpos($raw, "\nA161STATE") + 10), true) : null;
        return [$exit, $state, $raw];
    };
    $a161Log = static fn(): array => array_values(array_filter(explode("\n", (string)@file_get_contents($a161Ext . '/acme-loyalty/events.log'))));
    @unlink($a161Ext . '/acme-loyalty/events.log');
    [$a161Exit, , $a161Raw] = $a161Run('setup');
    assert_equals(0, $a161Exit, 'A16.1 worker: migrated SQLite fixture (' . trim(substr($a161Raw, 0, 300)) . ')');
    [, $a161State, $a161Raw] = $a161Run('record');
    $a161Event = (string)($a161State['events'][0]['id'] ?? '');
    assert_true($a161Event !== '', 'A16.1 worker: an order.created event is in the outbox (' . trim(substr($a161Raw, 0, 300)) . ')');

    [, $a161State, $a161Raw] = $a161Run('worker');
    $a161Deliveries = array_map(static fn(array $d): string => $d['endpoint_code'] . '=' . $d['status'], $a161State['deliveries'] ?? []);
    assert_equals(["points:{$a161Event}:A161-1:default"], $a161Log(), 'A16.1 worker: the real worker runs the extension listener with the event envelope (' . trim(substr($a161Raw, 0, 300)) . ')');
    assert_true(($a161State['events'][0]['status'] ?? '') === 'retry' && str_contains((string)$a161State['events'][0]['last_error'], 'acme flaky listener not ready')
        && $a161Deliveries === ['ext-acme-loyalty-points=delivered', 'ext-acme-loyalty-flaky=failed'],
        'A16.1 worker: a throwing listener puts the event into retry with a readable error; the other listener is recorded as delivered (' . json_encode($a161State) . ')');

    touch($a161Ext . '/acme-loyalty/flaky.ok');
    $a161Run('due');
    [, $a161State] = $a161Run('worker');
    [, $a161State] = $a161Run('worker');
    assert_equals(["points:{$a161Event}:A161-1:default", "flaky:{$a161Event}"], $a161Log(),
        'A16.1 worker: the retry runs only the failed listener, exactly once, and a later run does nothing');
    assert_equals('delivered', $a161State['events'][0]['status'] ?? null, 'A16.1 worker: the event is delivered once every listener succeeded');
} finally {
    Db::get()->exec("DELETE FROM products WHERE sku = 'A161-SKU'");
    Db::get()->exec("DELETE FROM categories WHERE slug = 'a161cat'");
    if (class_exists('Extensions')) {
        Extensions::reset();
        StorefrontViewModelRegistry::reset();
        StorefrontComposition::boot();
    }
    $a161Tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a161Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a161Tree as $a161Path) $a161Path->isDir() ? @rmdir($a161Path->getPathname()) : @unlink($a161Path->getPathname());
    @rmdir($a161Root);
}
