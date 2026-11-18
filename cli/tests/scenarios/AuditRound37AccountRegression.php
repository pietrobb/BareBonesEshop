<?php

// R37 / #469: registration text errors, single-line account fields, and the mobile greeting.
// Independent migrated databases; actual HTTP requests exercise CSRF, form traps, cookies and account.php.
echo "\n--- R37: account text validation and greeting wrapping ---\n";
$r37aDir = test_fixture_path('r37-account');
mkdir($r37aDir);
$r37aApp = dirname(TEST_CLI_DIR);
$r37aDrivers = ['sqlite'];
if (getenv('TEST_MYSQL_DSN')) $r37aDrivers[] = 'mysql';
else print "  [SKIP] R37 account MySQL (set TEST_MYSQL_DSN)\n";
foreach ($r37aDrivers as $r37aDriver) {
    $dir = $r37aDir . '/' . $r37aDriver;
    mkdir($dir);
    mkdir($dir . '/sessions');
    $target = $r37aDriver === 'mysql' ? 'bbe_r37a_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test' : $dir . '/account.sqlite';
    file_put_contents($dir . '/fixture.json', json_encode(['root' => $r37aApp, 'driver' => $r37aDriver, 'target' => $target]));
    file_put_contents($dir . '/request.php', <<<'PHP'
<?php
$f = json_decode(file_get_contents(__DIR__ . '/fixture.json'), true);
['root' => $root, 'driver' => $driver, 'target' => $target] = $f;
require $root . '/src/autoload.php';
$db = $driver === 'mysql'
    ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new PDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$setup = PHP_SAPI === 'cli';
if (!$setup && preg_match('~^/themes/default/assets/[a-zA-Z0-9_.-]+\.(?:css|js|woff2|svg)$~D', (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) return false;
if ($driver === 'mysql') {
    if ($setup) $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
    $db->exec("USE `{$target}`");
}
Db::init($db, $driver);
Security::setRateDbForTesting(new PDO('sqlite:' . __DIR__ . (isset($_SERVER['HTTP_X_R37_BROWSER']) ? '/browser-rate.sqlite' : '/rate.sqlite')));
session_save_path(__DIR__ . '/sessions');
Bootstrap::initLocale('/account.php?lang=en');
if ($setup) {
    (new Migrator($db, $root . '/migrations', __DIR__ . '/migrate.lock'))->apply();
    $results = [];
    $base = ['type' => 'billing', 'name' => 'Buyer', 'street' => 'Hlavná 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'];
    $bad = ['long' => str_repeat('ž', 192), 'utf8' => "bad\xC3\x28", 'nul' => "\0Buyer\0", 'crlf' => "Buyer\r\n", 'tab' => "\tBuyer", 'del' => "Buyer\x7f", 'c1' => "Buyer\u{0085}", 'line' => "Buyer\u{2028}"];
    foreach ($bad as $label => $value) {
        $key = $label === 'long' ? 'address_too_long' : 'invalid_text';
        $results["registration {$label} returns field error"] = Customers::register("bad-{$label}@example.test", 'R37-password', $value) === [false, null, $key];
        $results["profile {$label} both fields"] = Customers::profileErrors(['name' => $value, 'phone' => $value]) === ['name' => 'account.err_' . $key, 'phone' => 'account.err_' . $key];
        if ($label !== 'long') foreach (['name', 'company', 'ico', 'dic', 'vat_id', 'street', 'city', 'zip', 'country', 'phone'] as $field) {
            $results["address {$label}/{$field}"] = (Customers::addressErrors([$field => $value] + $base)[$field] ?? '') === 'account.err_invalid_text';
        }
    }
    $results['rejected registrations write no customers or outbox events'] = (int)$db->query('SELECT COUNT(*) FROM customers')->fetchColumn() === 0
        && (int)$db->query('SELECT COUNT(*) FROM event_outbox')->fetchColumn() === 0;
    foreach (['empty' => '', 'unicode' => " Žofia O’Connor 李 ", 'boundary' => str_repeat('ž', 191)] as $label => $name) {
        [$ok, $id] = Customers::register("valid-{$label}@example.test", 'R37-password', $name);
        $results["registration {$label} accepted intact"] = $ok && Customers::byId((int)$id)['name'] === trim($name);
    }
    [$ok, $id] = Customers::register('owner@example.test', 'R37-password', 'Original');
    $db->prepare('UPDATE customers SET email_verified_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $id]);
    $aid = Customers::addAddress((int)$id, $base + ['is_default' => 1]);
    $before = Customers::addresses((int)$id);
    foreach (['nul' => "\0Buyer", 'crlf' => "Buyer\r\n", 'tab' => "\tBuyer", 'utf8' => "\xff"] as $label => $value) {
        $results["checked address {$label} rejects without changing default or text"] = Customers::updateCheckedAddress((int)$id, $aid, ['name' => $value, 'is_default' => 0]) === ['name' => 'account.err_invalid_text']
            && Customers::addresses((int)$id) === $before;
    }
    file_put_contents(__DIR__ . '/results.json', json_encode(['checks' => $results, 'id' => $id, 'aid' => $aid]));
    exit;
}
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/health') { echo 'ready'; exit; }
require $root . '/account.php';
PHP);
    $server = null;
    $db = null;
    try {
        $setup = proc_open([PHP_BINARY, $dir . '/request.php'], [0 => ['pipe', 'r'], 1 => ['file', $dir . '/setup.log', 'a'], 2 => ['file', $dir . '/setup.log', 'a']], $pipes);
        fclose($pipes[0]);
        $exit = proc_close($setup);
        assert_equals(0, $exit, "R37 {$r37aDriver}: isolated migrated service fixture (" . file_get_contents($dir . '/setup.log') . ')');
        if ($exit !== 0) continue;
        $fixture = json_decode(file_get_contents($dir . '/results.json'), true);
        foreach ($fixture['checks'] as $label => $passedCheck) assert_true($passedCheck, "R37 {$r37aDriver} service: {$label}");
        $db = $r37aDriver === 'mysql'
            ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '') : new PDO('sqlite:' . $target);
        if ($r37aDriver === 'mysql') $db->exec("USE `{$target}`");
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $snapshot = static fn(): array => [$db->query('SELECT * FROM customers ORDER BY id')->fetchAll(), $db->query('SELECT * FROM customer_addresses ORDER BY id')->fetchAll(), $db->query('SELECT * FROM event_outbox ORDER BY id')->fetchAll()];
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $server = test_spawn_server([PHP_BINARY, '-S', $address, '-t', $r37aApp, $dir . '/request.php'], [0 => ['pipe', 'r'], 1 => ['file', $dir . '/http.log', 'a'], 2 => ['file', $dir . '/http.log', 'a']], $pipes);
        fclose($pipes[0]);
        for ($i = 0; $i < 100; $i++) {
            $probe = @stream_socket_client('tcp://' . $address, $errno, $errstr, 0.1);
            if ($probe) { fclose($probe); break; }
            usleep(50000);
        }
        $cookies = [];
        $request = static function(string $path, ?array $post = null) use ($address, &$cookies): array {
            $headers = ['Connection: close'];
            if ($cookies) $headers[] = 'Cookie: ' . implode('; ', array_map(static fn($k, $v) => $k . '=' . $v, array_keys($cookies), $cookies));
            if ($post !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $context = stream_context_create(['http' => ['method' => $post === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers), 'content' => $post === null ? '' : http_build_query($post), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20]]);
            $html = file_get_contents('http://' . $address . $path, false, $context);
            $response = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : $http_response_header;
            foreach ($response as $header) if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
            preg_match('/^HTTP\/\S+ (\d+)/', $response[0], $match);
            return [(int)($match[1] ?? 0), (string)$html];
        };
        $input = static fn(string $html, string $name): string => preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $match) ? html_entity_decode($match[1], ENT_QUOTES) : '';
        foreach (['long' => str_repeat('ž', 192), 'utf8' => "bad\xC3\x28", 'nul' => "\0Buyer\0", 'valid' => str_repeat('ž', 191)] as $label => $name) {
            [$code, $html] = $request('/account.php?p=register&lang=en');
            $csrf = $input($html, 'csrf_token');
            $trap = $input($html, 'register_token');
            assert_true($code === 200 && $csrf !== '' && $trap !== '', "R37 {$r37aDriver} HTTP {$label}: real registration form tokens");
            sleep(4); // Exercise the real minimum-age trap, not a bypass.
            $before = $snapshot();
            [$code, $html] = $request('/account.php?lang=en', ['action' => 'register', 'csrf_token' => $csrf, 'register_token' => $trap, 'name' => $name, 'email' => "http-{$label}@example.test", 'password' => 'R37-password']);
            if ($label === 'valid') {
                assert_true($code === 200 && str_contains($html, 'account-login-email') && count($snapshot()[0]) === count($before[0]) + 1, "R37 {$r37aDriver} HTTP: 191-character name registers successfully");
            } else {
                assert_true($code === 400 && str_contains($html, 'aria-describedby="account-register-name-error"') && str_contains($html, 'id="account-register-name-error"') && !str_contains($html, 'Internal error'), "R37 {$r37aDriver} HTTP {$label}: name field error, not Internal error");
                assert_equals($before, $snapshot(), "R37 {$r37aDriver} HTTP {$label}: no customer/address/outbox write");
            }
        }
        [$code, $html] = $request('/account.php?p=login&lang=en');
        [$code] = $request('/account.php?lang=en', ['action' => 'login', 'csrf_token' => $input($html, 'csrf_token'), 'email' => 'owner@example.test', 'password' => 'R37-password']);
        assert_equals(302, $code, "R37 {$r37aDriver} HTTP: actual verified-owner login");
        [$code, $html] = $request('/account.php?p=profile&lang=en');
        $csrf = $input($html, 'csrf_token');
        assert_true($code === 200 && $csrf !== '' && str_contains($html, 'account-profile-name'), "R37 {$r37aDriver} HTTP: authenticated profile");
        foreach (['nul' => "\0Buyer\0", 'crlf' => "Buyer\r\n", 'tab' => "\tBuyer", 'del' => "Buyer\x7f", 'c1' => "Buyer\u{0085}", 'utf8' => "\xff", 'long' => str_repeat('ž', 192)] as $label => $value) {
            $before = $snapshot();
            [$code, $html] = $request('/account.php?lang=en', ['action' => 'update_profile', 'csrf_token' => $csrf, 'name' => $value, 'phone' => $value, 'locale' => 'en']);
            assert_true($code === 400 && str_contains($html, 'id="account-profile-name-error"') && str_contains($html, 'id="account-profile-phone-error"'), "R37 {$r37aDriver} HTTP profile {$label}: both field errors");
            assert_equals($before, $snapshot(), "R37 {$r37aDriver} HTTP profile {$label}: unchanged persistence");
        }
        $base = ['type' => 'billing', 'name' => 'Buyer', 'street' => 'Hlavná 1', 'city' => 'Bratislava', 'zip' => '81101', 'country' => 'SK'];
        foreach (['name', 'company', 'ico', 'dic', 'vat_id', 'street', 'city', 'zip', 'country', 'phone'] as $field) {
            foreach (["\0Buyer\0", "Buyer\r\n"] as $value) {
                $before = $snapshot();
                [$code, $html] = $request('/account.php?lang=en', ['action' => 'address_add', 'csrf_token' => $csrf, $field => $value, 'is_default' => '1'] + $base);
                assert_true($code === 200 && str_contains($html, 'id="account-address-' . str_replace('_', '-', $field) . '-error"'), "R37 {$r37aDriver} HTTP address add {$field}/" . bin2hex($value) . ': field error');
                assert_equals($before, $snapshot(), "R37 {$r37aDriver} HTTP address add {$field}: no insert or default reset");
                [$code] = $request('/account.php?lang=en', ['action' => 'address_update', 'csrf_token' => $csrf, 'id' => $fixture['aid'], $field => $value, 'is_default' => '0']);
                assert_equals(400, $code, "R37 {$r37aDriver} HTTP address update {$field}: rejected");
                assert_equals($before, $snapshot(), "R37 {$r37aDriver} HTTP address update {$field}: unchanged persistence");
            }
        }
        $longName = str_repeat('ž', 191);
        [$code] = $request('/account.php?lang=en', ['action' => 'update_profile', 'csrf_token' => $csrf, 'name' => $longName, 'phone' => '+421 900 123 456', 'locale' => 'en']);
        assert_equals(200, $code, "R37 {$r37aDriver} HTTP: valid boundary profile saved");
        [$code, $html] = $request('/account.php?lang=en');
        assert_true($code === 200 && str_contains($html, $longName . '</h1>'), "R37 {$r37aDriver} HTTP: full long greeting rendered without truncation");
        file_put_contents($dir . '/dashboard.html', $html);
        [$code] = $request('/account.php?lang=en', ['action' => 'address_add', 'csrf_token' => $csrf, 'name' => 'Žofia 李', 'is_default' => '1'] + $base);
        assert_equals(302, $code, "R37 {$r37aDriver} HTTP: valid Unicode address still saves");
        if (getenv('R37_NODE')) {
            $script = 'const fixture = ' . json_encode(['url' => 'http://' . $address, 'playwright' => getenv('R37_PLAYWRIGHT') ?: '@playwright/test']) . ";\n" . file_get_contents($r37aApp . '/tests/e2e/r37-account.cjs');
            $browser = proc_open([getenv('R37_NODE'), '-'], [0 => ['pipe', 'r'], 1 => ['file', $dir . '/browser.log', 'w'], 2 => ['file', $dir . '/browser.log', 'a']], $browserPipes);
            fwrite($browserPipes[0], $script); fclose($browserPipes[0]);
            $browserExit = proc_close($browser);
            echo file_get_contents($dir . '/browser.log');
            assert_equals(0, $browserExit, "R37 {$r37aDriver}: live full-page Chromium account at 390px");
        } else print "  [SKIP] R37 account Chromium: set R37_NODE and optionally R37_PLAYWRIGHT\n";
        $log = file_get_contents($dir . '/http.log');
        assert_true(!preg_match('/PHP (?:Warning|Fatal|Notice|Deprecated)|Uncaught|SQLSTATE/', $log), "R37 {$r37aDriver} HTTP: no PHP or database errors in server log");
    } finally {
        if (is_resource($server)) { proc_terminate($server); proc_close($server); }
        if ($r37aDriver === 'mysql') {
            $cleanup = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '');
            $cleanup->exec("DROP DATABASE IF EXISTS `{$target}`");
        }
    }
}
$r37aCss = (string)file_get_contents($r37aApp . '/themes/default/assets/storefront.css');
assert_true(preg_match('/\.account-page>h1\{[^}]*overflow-wrap:anywhere/', $r37aCss) === 1, 'R37 account: unbroken dashboard greeting wraps at mobile widths');
echo "R37 account artifacts: {$r37aDir}\n";
