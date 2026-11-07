<?php

// Review round 12 (M3): the page-driven worker (hosting without cron) ran inside the visitor's request without letting
// go of it. PHP unlocks the session only after every shutdown function, so the admin whose click carried the pass
// waited for the whole pass on the next click; without fastcgi_finish_request (Apache mod_php, php -S) the response
// itself — possibly a payment webhook — stayed open until the pass ended; the pass was audited as that admin and ran
// under the web max_execution_time. Real HTTP through `php -S` (no fastcgi_finish_request, like mod_php).
echo "\n--- Audit round 12: web worker releases the request (session, response, actor, time limit) ---\n";

if (PHP_OS_FAMILY === 'Windows') {
    echo "  [SKIP] R12 web worker: php -S runs one request at a time on Windows (PHP_CLI_SERVER_WORKERS)\n";
    return;
}

$r12wBase = test_fixture_path('r12-web-worker');
$r12wRoot = $r12wBase . '/root';
$r12wServer = null;
try {
    foreach (['/src', '/cli', '/jobs', '/sessions'] as $dir) mkdir($r12wRoot . $dir, 0777, true);
    // The pass runs <root>/cli/worker.php next to WebWorker.php: a copy of the class gets a worker that reports what it sees.
    copy(dirname(TEST_CLI_DIR) . '/src/WebWorker.php', $r12wRoot . '/src/WebWorker.php');
    file_put_contents($r12wRoot . '/cli/worker.php', <<<'PHP'
<?php
file_put_contents(dirname(__DIR__) . '/worker-started.json', json_encode([
    'session_status' => session_status(),
    'session' => $_SESSION ?? null,
    'actor' => (new ReflectionMethod(AuditLog::class, 'currentActor'))->invoke(null),
    'time_limit' => (int)ini_get('max_execution_time'),
    'web_worker' => defined('BBE_WEB_WORKER'),
]));
sleep(3);
file_put_contents(dirname(__DIR__) . '/worker-done', '1');
PHP);
    file_put_contents($r12wRoot . '/router.php', <<<'PHP'
<?php
if (($_GET['p'] ?? '') === 'ready') { echo 'ready'; return; }
// php -S has no mod_deflate: record what Apache mod_php would be told about compressing this response.
if (!function_exists('apache_setenv')) {
    function apache_setenv(string $name, string $value, bool $walkToTop = false): bool {
        file_put_contents(getenv('R12W_ROOT') . '/apache-env.json', json_encode([$name => $value]), FILE_APPEND);
        return true;
    }
}
require getenv('R12W_ROOT') . '/src/WebWorker.php';
require getenv('R12W_APP') . '/src/autoload.php';
file_put_contents(getenv('R12W_ROOT') . '/config-path.txt', AppConfig::path(getenv('R12W_APP')));
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'http://127.0.0.1', 'jobs_dir' => getenv('R12W_ROOT') . '/jobs',
    'operations' => ['web_worker' => true, 'web_worker_interval' => 3600]]);
set_time_limit(20);
Security::sessionStart();
if (($_GET['p'] ?? '') === 'login') { $_SESSION['admin_authenticated'] = true; $_SESSION['admin_user'] = 'r12w-admin'; }
Security::headers();
echo '<!doctype html><p>page ' . htmlspecialchars((string)($_GET['p'] ?? ''), ENT_QUOTES) . ' admin=' . htmlspecialchars((string)($_SESSION['admin_user'] ?? '-'), ENT_QUOTES) . '</p>' . str_repeat('.', 20000);
PHP);
    $r12wProbe = stream_socket_server('tcp://127.0.0.1:0');
    $r12wPort = (int)substr(strrchr((string)stream_socket_get_name($r12wProbe, false), ':'), 1);
    fclose($r12wProbe);
    // R20: the server inherits the suite's environment (BBE_SUITE_*), or the app it loads opens the developer's config.php.
    $r12wEnv = array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '3', 'R12W_ROOT' => $r12wRoot, 'R12W_APP' => dirname(TEST_CLI_DIR)]);
    $r12wServer = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $r12wRoot . '/sessions', '-d', 'display_errors=0', '-d', 'log_errors=1',
        '-d', 'error_log=' . $r12wBase . '/php-error.log', '-S', "127.0.0.1:{$r12wPort}", $r12wRoot . '/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $r12wBase . '/server.log', 'a'], 2 => ['file', $r12wBase . '/server.log', 'a']], $r12wPipes, $r12wRoot, $r12wEnv);
    // A client that, like a browser or a payment gateway, treats the response as complete once Content-Length bytes arrived.
    $r12wGet = static function (string $path, string $cookie = '') use ($r12wPort): array {
        $start = microtime(true);
        $socket = @stream_socket_client("tcp://127.0.0.1:{$r12wPort}", $errno, $error, 5);
        if ($socket === false) return ['head' => '', 'body' => '', 'elapsed' => 0.0, 'cookie' => '', 'length' => null];
        stream_set_timeout($socket, 15);
        fwrite($socket, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept-Encoding: gzip, deflate\r\n" . ($cookie !== '' ? "Cookie: {$cookie}\r\n" : '') . "\r\n");
        $head = '';
        while (!str_contains($head, "\r\n\r\n") && ($line = fgets($socket)) !== false) $head .= $line;
        $length = preg_match('/^Content-Length:\s*(\d+)/mi', $head, $m) ? (int)$m[1] : null;
        $body = '';
        while (($length === null || strlen($body) < $length) && !feof($socket)) {
            $chunk = fread($socket, $length === null ? 8192 : $length - strlen($body));
            if ($chunk === false || $chunk === '') break;
            $body .= $chunk;
        }
        $elapsed = microtime(true) - $start;
        fclose($socket);
        return ['head' => $head, 'body' => $body, 'elapsed' => $elapsed, 'length' => $length,
            'cookie' => preg_match('/^Set-Cookie:\s*([^=;\s]+=[^;\r\n]+)/mi', $head, $c) ? $c[1] : ''];
    };
    for ($i = 0; $i < 100 && $r12wGet('/?p=ready')['body'] !== 'ready'; $i++) usleep(100000);

    $r12wFirst = $r12wGet('/?p=login');
    $r12wConfig = (string)@file_get_contents($r12wRoot . '/config-path.txt');
    assert_true(str_ends_with($r12wConfig, '/' . AppConfig::SUITE_FILE) && $r12wConfig === AppConfig::path(dirname(TEST_CLI_DIR)),
        'R20 web worker: the php -S server reads the suite\'s config, not the developer\'s config.php (' . $r12wConfig . ')');
    assert_true(str_contains($r12wFirst['body'], 'page login') && $r12wFirst['length'] === strlen($r12wFirst['body']),
        'R12 web worker: the response that triggers the pass carries its exact Content-Length (complete without FastCGI)');
    assert_true($r12wFirst['elapsed'] < 2.0 && !is_file($r12wRoot . '/worker-done'),
        sprintf('R12 web worker: the visitor (or a webhook) gets the whole response before the 3 s pass ends (took %.2f s)', $r12wFirst['elapsed']));
    assert_true(str_contains((string)@file_get_contents($r12wRoot . '/apache-env.json'), '{"no-gzip":"1"}'),
        'R12 web worker: a browser asking for gzip still gets the early end on Apache (mod_deflate off for that response, it would drop the Content-Length)');
    for ($i = 0; $i < 50 && !is_file($r12wRoot . '/worker-started.json'); $i++) usleep(100000);
    $r12wSecond = $r12wGet('/?p=again', $r12wFirst['cookie']);
    assert_true($r12wSecond['elapsed'] < 2.0 && !is_file($r12wRoot . '/worker-done'),
        sprintf('R12 web worker: the same visitor\'s next click is not blocked by the session lock while the pass runs (took %.2f s)', $r12wSecond['elapsed']));
    assert_true(str_contains($r12wSecond['body'], 'admin=r12w-admin'), 'R12 web worker: the session written before the pass keeps the login');
    for ($i = 0; $i < 80 && !is_file($r12wRoot . '/worker-done'); $i++) usleep(100000);
    $r12wSeen = json_decode((string)@file_get_contents($r12wRoot . '/worker-started.json'), true) ?: [];
    assert_true(($r12wSeen['web_worker'] ?? false) === true && ($r12wSeen['session_status'] ?? null) !== PHP_SESSION_ACTIVE && ($r12wSeen['session'] ?? null) === [],
        'R12 web worker: the pass runs with the session closed and no visitor data');
    assert_equals('system', $r12wSeen['actor'] ?? null, 'R12 web worker: changes made by the pass are audited as the system, not as the admin whose click carried it');
    assert_true(($r12wSeen['time_limit'] ?? 0) >= 300, 'R12 web worker: the pass is not cut off by the web max_execution_time');
} catch (Throwable $e) {
    assert_true(false, 'R12 web worker (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    if (is_resource($r12wServer)) { proc_terminate($r12wServer); proc_close($r12wServer); }
    if (is_dir($r12wBase)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r12wBase, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($r12wBase);
    }
}
