<?php

// Bookshop demo (#239): the first-visit web installer (install.php) for hosting without a shell, and the starter data
// of the "upload and sell" package. Form checks, the seller and payment set-up, the starter copy, and a clean
// clone-equivalent tree installed over HTTP through the development router.
echo "\n--- Bookshop installer: install.php on first visit, starter data, doctor passes ---\n";

$wiBase = test_fixture_path('web-installer');
$wiFail = static function (string $what, Throwable $e): void {
    assert_true(false, "Web installer {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$wiRemoveTree = static function (string $path): void {
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
};
$wiValid = [
    'shop_name' => 'WI Books', 'app_url' => 'https://books.wi.test', 'timezone' => 'Europe/Bratislava', 'company' => 'WI Books Ltd', 'street' => 'Main Street 1',
    'city' => 'Bratislava', 'zip' => '811 01', 'country' => 'sk', 'email' => 'owner@wi-books.test', 'vat_id' => '', 'pay_cod' => '1', 'pay_bank' => '1',
    'iban' => 'SK31 1200 0000 1987 4263 7541', 'admin_user' => 'Owner', 'admin_password' => 'correct horse battery', 'admin_password_repeat' => 'correct horse battery',
];

try {
    // Form checks, against an application root inside public_html: its private folder must live above it.
    $wiRoot = $wiBase . '/home/public_html/shop';
    mkdir($wiRoot, 0777, true);
    mkdir($wiBase . '/home/data', 0777, true);
    $wiInput = $wiValid + ['private_parent' => $wiBase . '/home/data'];
    [$wiValues, $wiErrors] = WebInstaller::validate($wiInput, $wiRoot);
    assert_equals([], $wiErrors, 'Web installer: a complete form with a private folder above the web root is accepted');
    assert_equals(['SK', 'SK3112000000198742637541', 'owner', true, true], [$wiValues['country'], $wiValues['iban'], $wiValues['admin_user'], $wiValues['pay_cod'], $wiValues['pay_bank']],
        'Web installer: country, IBAN and user name are normalised, the payment boxes become booleans');
    $wiRefused = static function (array $change) use ($wiInput, $wiRoot): array {
        return array_keys(WebInstaller::validate(array_merge($wiInput, $change), $wiRoot)[1]);
    };
    assert_equals(['admin_password'], $wiRefused(['admin_password' => 'short', 'admin_password_repeat' => 'short']), 'Web installer: a password under 12 characters is refused');
    assert_equals(['admin_password'], $wiRefused(['admin_password_repeat' => 'correct horse batterY']), 'Web installer: two different passwords are refused');
    assert_equals(['iban'], $wiRefused(['iban' => 'SK00 1100 0000 0012 3456 7890']), 'Web installer: bank transfer needs an IBAN with valid check digits');
    assert_equals(['payments'], $wiRefused(['pay_cod' => '', 'pay_bank' => '', 'iban' => '']), 'Web installer: a shop without any payment method is refused');
    assert_equals(['stripe'], $wiRefused(['stripe_secret_key' => 'sk_test_' . str_repeat('a', 24)]), 'Web installer: Stripe needs all three values or none');
    assert_equals(['stripe'], $wiRefused(['stripe_secret_key' => 'sk_live_' . str_repeat('a', 24), 'stripe_publishable_key' => 'pk_live_' . str_repeat('b', 24), 'stripe_webhook_secret' => 'whsec_' . str_repeat('c', 24)]),
        'Web installer: live Stripe keys are refused (a demo must not take real money)');
    assert_equals([], $wiRefused(['stripe_secret_key' => 'sk_test_' . str_repeat('a', 24), 'stripe_publishable_key' => 'pk_test_' . str_repeat('b', 24), 'stripe_webhook_secret' => 'whsec_' . str_repeat('c', 24)]),
        'Web installer: Stripe test keys are accepted');
    foreach (['inside the shop' => $wiRoot . '/data', 'the web root' => $wiBase . '/home/public_html', 'inside the web root' => $wiBase . '/home/public_html/private', 'a relative path' => 'data',
              'a path with ..' => $wiBase . '/home/data/../public_html', 'below a file' => $wiBase . '/home/a-file/data'] as $wiWhat => $wiParent) {
        file_put_contents($wiBase . '/home/a-file', 'x');
        assert_equals(['private_parent'], $wiRefused(['private_parent' => $wiParent]), "Web installer: a private folder {$wiWhat} is refused");
    }
    assert_equals([], $wiRefused(['private_parent' => $wiBase . '/home/bareboneseshop-data/shop']), 'Web installer: a missing private folder below a writable one is accepted (created on install)');
    mkdir($wiBase . '/home/data/bareboneseshop-private/db', 0777, true);
    file_put_contents($wiBase . '/home/data/bareboneseshop-private/db/shop.sqlite', 'SQLite format 3');
    assert_equals(['private_parent'], $wiRefused([]), 'Web installer: a private folder holding another shop\'s database is refused');
    unlink($wiBase . '/home/data/bareboneseshop-private/db/shop.sqlite');

    // Seller: the example seller is replaced (doctor accepts it); other stores with example sellers go offline.
    $wiExample = json_decode((string)file_get_contents(dirname(TEST_CLI_DIR) . '/stores.example.json'), true);
    $wiStores = WebInstaller::withIdentity($wiExample, $wiValues);
    $wiDefault = $wiStores['stores'][$wiExample['default_store']];
    assert_equals([[], 'WI Books', 'WI Books Ltd', 'SK3112000000198742637541', false], [Setup::legalIdentityProblems($wiDefault['legal']), $wiDefault['name'], $wiDefault['legal']['name'], $wiDefault['legal']['iban'], $wiDefault['legal']['vat_registered']],
        'Web installer: the default store gets the form\'s shop name and seller, not a merge with the example company');
    assert_true(!isset($wiDefault['legal']['ico']) && !isset($wiDefault['legal']['register_note']), 'Web installer: no example company number or register note survives');
    $wiStillPublic = array_keys(array_filter($wiStores['stores'], static fn(array $s): bool => StoreContext::isPublicConfig($s)));
    assert_equals([$wiExample['default_store']], $wiStillPublic, 'Web installer: only the store with the entered seller stays public');

    // Payments: chosen offline methods in the default store; card only with Stripe keys, in every store.
    $wiDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $wiDb->exec('CREATE TABLE payment_methods (store_id TEXT, code TEXT, active INTEGER)');
    foreach (['default', 'cz'] as $wiScope) foreach (['card', 'cod', 'bank_transfer', 'invoice'] as $wiCode) $wiDb->exec("INSERT INTO payment_methods VALUES ('{$wiScope}', '{$wiCode}', 1)");
    $wiActive = static fn(): array => $wiDb->query("SELECT store_id || ':' || code FROM payment_methods WHERE active = 1 ORDER BY store_id, code")->fetchAll(PDO::FETCH_COLUMN);
    WebInstaller::configurePayments($wiDb, $wiStores, array_merge($wiValues, ['pay_bank' => false, 'stripe_secret_key' => '']));
    assert_equals(['cz:bank_transfer', 'cz:cod', 'cz:invoice', 'default:cod'], $wiActive(), 'Web installer: without Stripe keys no store offers card; the default store offers just what was ticked');
    WebInstaller::configurePayments($wiDb, $wiStores, array_merge($wiValues, ['stripe_secret_key' => 'sk_test_' . str_repeat('a', 24)]));
    assert_equals(['cz:bank_transfer', 'cz:card', 'cz:cod', 'cz:invoice', 'default:bank_transfer', 'default:card', 'default:cod'], $wiActive(), 'Web installer: with Stripe test keys card comes on');

    // config.php: the chosen private folder and the Stripe values; secrets are generated, never taken from the form.
    copy(dirname(TEST_CLI_DIR) . '/config.example.php', $wiRoot . '/config.example.php');
    $wiStripe = ['stripe_secret_key' => 'sk_test_' . str_repeat('a', 24), 'stripe_publishable_key' => 'pk_test_' . str_repeat('b', 24), 'stripe_webhook_secret' => 'whsec_' . str_repeat('c', 24)];
    Setup::createConfig($wiRoot, 'https://books.wi.test', 'owner@wi-books.test', ['driver' => 'sqlite'], $wiBase . '/home/data', $wiStripe);
    $wiConfig = require $wiRoot . '/config.php';
    assert_equals([$wiBase . '/home/data/bareboneseshop-private/db/shop.sqlite', $wiBase . '/home/data/bareboneseshop-private/downloads', $wiStripe['stripe_secret_key'], $wiStripe['stripe_webhook_secret'], 32],
        [$wiConfig['db_sqlite_path'], $wiConfig['digital_assets_base_dir'], $wiConfig['stripe_secret_key'], $wiConfig['stripe_webhook_secret'], strlen((string)base64_decode((string)$wiConfig['admin_identity_key'], true))],
        'Web installer: config.php puts every private path in the chosen folder, carries the Stripe values and a fresh 32-byte identity key');
    assert_true(WebInstaller::installed($wiRoot), 'Web installer: an existing config.php means installed (install.php then answers 404)');

    // Starter data of a package: found in demo/<name>/dist, copied into the private paths once.
    $wiDist = $wiRoot . '/demo/wi/dist';
    mkdir($wiDist . '/downloads/default/bookshop', 0777, true);
    file_put_contents($wiDist . '/shop.sqlite', 'SQLite format 3 starter');
    file_put_contents($wiDist . '/downloads/default/bookshop/wi.epub', 'EPUB');
    file_put_contents($wiDist . '/stores.json', '{}');
    assert_equals(null, WebInstaller::starter($wiRoot), 'Web installer: no starter.json, no starter (a plain install)');
    file_put_contents($wiDist . '/starter.json', json_encode(['name' => 'WI Starter', 'database' => '../../../config.php', 'downloads' => 'downloads', 'stores' => 'stores.json']));
    assert_equals(null, WebInstaller::starter($wiRoot), 'Web installer: a starter file name cannot leave the dist folder');
    file_put_contents($wiDist . '/starter.json', json_encode(['name' => 'WI Starter', 'database' => 'shop.sqlite', 'downloads' => 'downloads', 'stores' => 'stores.json']));
    $wiStarter = WebInstaller::starter($wiRoot);
    assert_equals('WI Starter', $wiStarter['name'] ?? null, 'Web installer: the package starter is found');
    assert_true(WebInstaller::seed((array)$wiStarter, $wiConfig), 'Web installer: the starter is copied');
    assert_equals(['SQLite format 3 starter', 'EPUB'], [(string)@file_get_contents($wiConfig['db_sqlite_path']), (string)@file_get_contents($wiConfig['digital_assets_base_dir'] . '/default/bookshop/wi.epub')],
        'Web installer: the starter database and its eBook files land in the private paths');
    $wiAgain = 'accepted';
    try {
        WebInstaller::seed((array)$wiStarter, $wiConfig);
    } catch (RuntimeException $e) {
        $wiAgain = $e->getMessage();
    }
    assert_true(str_contains($wiAgain, 'already exists'), "Web installer: an existing database is never replaced by the starter ({$wiAgain})");

    // R15: a package built from a release records it; the installer baselines the signed updater with it, in the
    // folder cli/update.php reads (else the updater refuses the shop as of unknown version).
    $wiState = $wiConfig['private_runtime_dir'] . '/updates/installed.json';
    $wiStarterWith = static function (string $version) use ($wiDist, $wiRoot): array {
        file_put_contents($wiDist . '/starter.json', json_encode(['name' => 'WI Starter', 'database' => 'shop.sqlite', 'downloads' => 'downloads', 'stores' => 'stores.json', 'version' => $version]));
        return (array)WebInstaller::starter($wiRoot);
    };
    assert_equals(['', ''], [$wiStarter['version'] ?? null, $wiStarterWith('../../config')['version'] ?? null], 'R15 web installer: a starter without a release, or with an invalid one, has no version');
    WebInstaller::baselineRelease((array)$wiStarter, $wiConfig, $wiRoot);
    assert_true(!is_file($wiState), 'R15 web installer: a starter without a release records no installed version');
    WebInstaller::baselineRelease($wiStarterWith('2.1.1'), $wiConfig, $wiRoot);
    assert_equals('2.1.1', json_decode((string)@file_get_contents($wiState), true)['version'] ?? null, 'R15 web installer: the package release becomes the updater\'s installed version');
    WebInstaller::baselineRelease($wiStarterWith('2.2.0'), $wiConfig, $wiRoot);
    assert_equals('2.1.1', json_decode((string)@file_get_contents($wiState), true)['version'] ?? null, 'R15 web installer: an existing installed version is never overwritten');
} catch (Throwable $e) { $wiFail('form, seller, payments and starter', $e); }

// A clean clone-equivalent tree over HTTP: / and admin.php lead to install.php, the form installs, then install.php is gone.
$wiShop = $wiBase . '/http/shop';
$wiCopy = static function (string $from, string $to, string $relative = '') use (&$wiCopy): void {
    $skip = ['.git', 'node_modules', 'releases', 'tests', 'docs', 'demo', 'parity-report', 'playwright-report', 'test-results', '.lighthouseci',
        'config.php', 'stores.json', 'admin-roles.json', '.env', 'cache', 'db', 'logs', 'jobs', 'storage', 'uploads', 'import'];
    if (!is_dir($to)) mkdir($to, 0777, true);
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || ($relative === '' && (in_array($entry, $skip, true) || preg_match('/\.(?:png|log|md|exe)$/i', $entry)))) continue;
        is_dir($from . '/' . $entry) ? $wiCopy($from . '/' . $entry, $to . '/' . $entry, $relative . $entry . '/') : copy($from . '/' . $entry, $to . '/' . $entry);
    }
};
$wiEnv = array_filter(getenv(), static fn(string $name): bool => !str_starts_with($name, 'TEST_') && !str_starts_with($name, 'BBE_'), ARRAY_FILTER_USE_KEY);
$wiServer = null;
try {
    $wiCopy(dirname(TEST_CLI_DIR), $wiShop);
    mkdir($wiBase . '/http/data', 0777, true);
    $wiProbe = stream_socket_server('tcp://127.0.0.1:0');
    $wiPort = (int)substr(strrchr((string)stream_socket_get_name($wiProbe, false), ':'), 1);
    fclose($wiProbe);
    $wiServer = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$wiPort}", 'router.php'], [0 => ['pipe', 'r'], 1 => ['file', $wiBase . '/server.log', 'a'], 2 => ['file', $wiBase . '/server.log', 'a']], $wiPipes, $wiShop, $wiEnv);
    $wiCookie = '';
    $wiHttp = static function (string $path, ?array $post = null) use ($wiPort, &$wiCookie): array {
        $headers = ($wiCookie !== '' ? "Cookie: {$wiCookie}\r\n" : '') . ($post !== null ? "Content-Type: application/x-www-form-urlencoded\r\n" : '');
        $context = stream_context_create(['http' => ['method' => $post === null ? 'GET' : 'POST', 'header' => $headers, 'content' => $post === null ? '' : http_build_query($post),
            'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 60]]);
        $body = @file_get_contents("http://127.0.0.1:{$wiPort}{$path}", false, $context);
        $response = HttpResponseHeaders::last($http_response_header ?? null);
        $location = '';
        foreach ($response as $line) {
            if (preg_match('/^Set-Cookie:\s*(bbe_install=[^;]+)/i', $line, $m)) $wiCookie = $m[1];
            if (preg_match('/^Location:\s*(\S+)/i', $line, $m)) $location = $m[1];
        }
        return ['status' => (int)(explode(' ', (string)($response[0] ?? ''))[1] ?? 0), 'location' => $location, 'body' => (string)$body];
    };
    for ($i = 0; $i < 100 && $wiHttp('/install.php')['status'] === 0; $i++) usleep(100000);

    assert_equals([302, 'install.php', 302, 'install.php'], [$wiHttp('/')['status'], $wiHttp('/')['location'], $wiHttp('/admin.php')['status'], $wiHttp('/admin.php')['location']],
        'Web installer: without config.php the shop and the administration send the first visitor to install.php');
    $wiForm = $wiHttp('/install.php');
    preg_match('/name="token" value="([0-9a-f]{64})"/', $wiForm['body'], $wiToken);
    assert_true($wiForm['status'] === 200 && isset($wiToken[1]) && str_contains($wiForm['body'], 'name="private_parent"'), 'Web installer: install.php shows the form with a CSRF token');
    $wiPost = $wiValid + ['private_parent' => $wiBase . '/http/data', 'app_url' => "http://127.0.0.1:{$wiPort}"];
    $wiForged = $wiHttp('/install.php', $wiPost + ['token' => str_repeat('0', 64)]);
    assert_true(str_contains($wiForged['body'], 'The form expired') && !is_file($wiShop . '/config.php'), 'Web installer: a post without the session token installs nothing');
    // R12: the first visitor is not the owner by default — the key from install-key.php (FTP only) is required.
    $wiKey = (string)(static fn(string $file): mixed => require $file)($wiShop . '/' . WebInstaller::KEY_FILE);
    $wiKeyOverHttp = $wiHttp('/' . WebInstaller::KEY_FILE);
    assert_true(preg_match('/^[A-Z2-9]{5}(?:-[A-Z2-9]{5}){3}$/', $wiKey) === 1 && !str_contains($wiKeyOverHttp['body'], $wiKey),
        'R12 web installer: the first visit creates install-key.php; over HTTP it runs as PHP and never shows the key');
    foreach (['without the key' => '', 'with a wrong key' => 'AAAAA-BBBBB-CCCCC-DDDDD'] as $wiWhat => $wiTyped) {
        $wiNoKey = $wiHttp('/install.php', $wiPost + ['token' => $wiToken[1] ?? '', 'install_key' => $wiTyped]);
        assert_true(str_contains($wiNoKey['body'], 'This is not the key in install-key.php') && !is_file($wiShop . '/config.php'), "R12 web installer: a post {$wiWhat} installs nothing");
    }
    $wiDone = $wiHttp('/install.php', $wiPost + ['token' => $wiToken[1] ?? '', 'install_key' => strtolower(str_replace('-', ' ', $wiKey))]);
    assert_true(!is_file($wiShop . '/' . WebInstaller::KEY_FILE), 'R12 web installer: the key (typed in any case and spacing) installs, and install-key.php is deleted');
    preg_match('/(\d+) failures/', $wiDone['body'], $wiFailures);
    assert_true(str_contains($wiDone['body'], 'Your shop is ready') && str_contains($wiDone['body'], '<svg') && preg_match('/[0-9A-F]{5}-[0-9A-F]{5}/', $wiDone['body']) === 1,
        'Web installer: the result page shows the owner\'s TOTP QR code and recovery codes' . (str_contains($wiDone['body'], 'Your shop is ready') ? '' : ': ' . implode(' | ', preg_match_all('/<p class="error"[^>]*>(.*?)<\/p>/s', $wiDone['body'], $wiShown) ? $wiShown[1] : [$wiDone['status']])));
    assert_equals('0', $wiFailures[1] ?? null, 'Web installer: setup doctor has no failing check right after the web install');
    $wiPrivate = $wiBase . '/http/data/bareboneseshop-private';
    $wiDb = new PDO('sqlite:' . $wiPrivate . '/db/shop.sqlite');
    assert_equals(1, (int)$wiDb->query("SELECT COUNT(*) FROM admin_accounts WHERE username = 'owner' AND role = 'owner' AND mfa_required = 1")->fetchColumn(), 'Web installer: the owner account exists with MFA');
    $wiDb = null;
    $wiInstalledStores = json_decode((string)file_get_contents($wiPrivate . '/stores.json'), true);
    assert_equals('WI Books Ltd', $wiInstalledStores['stores'][$wiInstalledStores['default_store']]['legal']['name'] ?? null, 'Web installer: the seller lands in the install\'s own stores.json');
    assert_equals([404, 200], [$wiHttp('/install.php')['status'], $wiHttp('/')['status']], 'Web installer: once installed, install.php answers 404 and the shop opens');
    assert_true(!str_contains($wiHttp('/install.php', $wiPost + ['token' => $wiToken[1] ?? ''])['body'], 'Your shop is ready'), 'Web installer: a second install post changes nothing');

    // No cron on such hosting: page requests run the worker (paid eBooks are granted, mail leaves the queue).
    $wiInstalledConfig = (static fn(string $file): array => require $file)($wiShop . '/config.php');
    assert_true(WebWorker::enabled($wiInstalledConfig), 'Web installer: config.php turns on operations.web_worker');
    $wiJobs = (string)$wiInstalledConfig['jobs_dir'];
    assert_true(is_file($wiJobs . '/web-worker.last') && is_file($wiJobs . '/retention-cleanup.last'), 'Web worker: a storefront request ran one cli/worker.php pass (it scheduled the daily cleanup)');
    assert_equals([], glob($wiJobs . '/pending/*.json') ?: [], 'Web worker: the pass also processed the queued cleanup job');
    $wiStamp = (string)file_get_contents($wiJobs . '/web-worker.last');
    $wiHttp('/admin.php');
    assert_equals($wiStamp, (string)file_get_contents($wiJobs . '/web-worker.last'), 'Web worker: within the interval a further request runs no second pass');
    // A customer waiting for an eBook only reloads account pages: every web entrypoint must drive the worker.
    foreach (['/account.php?p=login', '/wishlist.php'] as $wiPath) {
        file_put_contents($wiJobs . '/web-worker.last', (string)(time() - 3600));
        $wiHttp($wiPath);
        assert_true((int)file_get_contents($wiJobs . '/web-worker.last') >= time() - 60, "Web worker: a request to {$wiPath} after the interval runs a pass");
    }
    assert_true(str_contains($wiDone['body'], 'checks passed') && !str_contains($wiDone['body'], 'Worker cron declaration not recorded'), 'Web worker: doctor accepts page-driven jobs instead of a cron declaration');
} catch (Throwable $e) { $wiFail('over HTTP', $e); }
finally {
    if (is_resource($wiServer)) { proc_terminate($wiServer); proc_close($wiServer); }
    $wiRemoveTree($wiBase);
}
