<?php

// Review round 13 (reviewer "kolo 5"). N1: the page-request worker runs cli/worker.php inside an admin request, and
// both declared a global handleCacheRebuild() — an uncatchable "Cannot redeclare" fatal on every dashboard view, with
// the interval slot already taken. N2: the installer's protection self-check took "cannot tell" (no connection, a
// timeout, any redirect) for "protected". Lows: the check ran before the key and from the client's SERVER_PORT, a
// failure trace could put the owner's password into error_log (which .htaccess did not deny), install-key.php was
// world-readable even where PHP runs as the uploader, mod_php buffered whole feeds, php-cgi behind mod_deflate never
// ended the response early.
echo "\n--- Audit round 13: web worker in admin requests, installer self-check and logs ---\n";

$r13wRoot = dirname(TEST_CLI_DIR);
$r13wBase = test_fixture_path('r13-worker-installer');
$r13wServers = [];
$r13wTree = static function (string $path) use (&$r13wTree): void {
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
};
$r13wFunctions = static function (string $file): array {
    preg_match_all('/^function\s+(\w+)\s*\(/mi', (string)file_get_contents($file), $m);
    return array_map('strtolower', $m[1]);
};
$r13wServe = static function (string $docroot, array $args) use ($r13wBase, &$r13wServers): int {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr((string)stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
    $r13wServers[] = proc_open(array_merge([PHP_BINARY, '-S', "127.0.0.1:{$port}"], $args),
        [0 => ['pipe', 'r'], 1 => ['file', $r13wBase . '/server.log', 'a'], 2 => ['file', $r13wBase . '/server.log', 'a']], $pipes, $docroot);
    for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) usleep(100000);
    return $port;
};
$r13wServer = static fn(int $port): array => ['HTTP_HOST' => "127.0.0.1:{$port}", 'SERVER_ADDR' => '127.0.0.1', 'SERVER_PORT' => (string)$port, 'SCRIPT_NAME' => '/install.php'];

try {
    mkdir($r13wBase, 0777, true);

    // N1: cli/worker.php declares no global function, and no function of a web entrypoint module clashes with it.
    assert_equals([], $r13wFunctions($r13wRoot . '/cli/worker.php'), 'R13 N1: cli/worker.php declares no global function (its job handlers live in WorkerJobs)');
    $r13wPageFunctions = [];
    foreach (array_merge(glob($r13wRoot . '/src/Entrypoints/*.php') ?: [], glob($r13wRoot . '/src/Entrypoints/*/*.php') ?: []) as $module) {
        $r13wPageFunctions = array_merge($r13wPageFunctions, $r13wFunctions($module));
    }
    assert_true(in_array('handlecacherebuild', $r13wPageFunctions, true) && method_exists('WorkerJobs', 'handleCacheRebuild'),
        'R13 N1 fixture: the admin and the worker each still have a cache-rebuild handler');

    // N1 for real: an admin request (its page module loaded) runs the web worker pass. The isolated worker copy
    // finds its lock held and stops at once; before the fix the require itself died with "Cannot redeclare".
    foreach (['/worker/cli', '/worker/src', '/worker/jobs'] as $dir) mkdir($r13wBase . $dir, 0777, true);
    file_put_contents($r13wBase . '/worker/config.php', '<?php return ' . var_export(['jobs_dir' => $r13wBase . '/worker/jobs', 'queue' => ['backend' => 'file']], true) . ';');
    file_put_contents($r13wBase . '/worker/src/autoload.php', '<?php require_once ' . var_export($r13wRoot . '/src/autoload.php', true) . ';'
        . ' (new ReflectionProperty(JobQueue::class, "forceFile"))->setValue(null, true);');
    copy($r13wRoot . '/cli/cli-only.php', $r13wBase . '/worker/cli/cli-only.php');
    copy($r13wRoot . '/cli/worker.php', $r13wBase . '/worker/cli/worker.php');
    $r13wHeld = fopen($r13wBase . '/worker/jobs/worker.lock', 'c+');
    flock($r13wHeld, LOCK_EX);
    file_put_contents($r13wBase . '/admin-request.php', '<?php require ' . var_export($r13wRoot . '/src/autoload.php', true) . ";\n"
        . 'require ' . var_export($r13wRoot . '/src/Entrypoints/Admin/Operations.php', true) . ";\n"
        . 'register_shutdown_function(static function (): void { echo "pass-ended\n"; });' . "\n"
        . 'WebWorker::runIfDue(["operations" => ["web_worker" => true], "jobs_dir" => ' . var_export($r13wBase . '/worker/jobs', true) . '], '
        . var_export($r13wBase . '/worker', true) . ");\n");
    $r13wProc = proc_open([PHP_BINARY, '-d', 'display_errors=1', $r13wBase . '/admin-request.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r13wPipes, $r13wBase);
    $r13wOut = stream_get_contents($r13wPipes[1]) . stream_get_contents($r13wPipes[2]);
    $r13wExit = proc_close($r13wProc);
    flock($r13wHeld, LOCK_UN);
    fclose($r13wHeld);
    assert_true($r13wExit === 0 && !str_contains($r13wOut, 'redeclare') && str_contains($r13wOut, 'pass-ended'),
        'R13 N1: the web worker pass runs inside an admin request without "Cannot redeclare" (exit ' . $r13wExit . ': ' . trim(substr($r13wOut, 0, 160)) . ')');

    // N2: "cannot tell" refuses the install until the key holder checked the probe file by hand.
    assert_true(WebInstaller::protectionError(null, false) !== null && WebInstaller::protectionError(null, true) === null
        && WebInstaller::protectionError([], false) === null && WebInstaller::protectionError([WebInstaller::PROBE_FILE], true) !== null,
        'R13 N2: an undecided self-check blocks the install unless confirmed by hand; served files always block');
    $r13wInstall = (string)file_get_contents($r13wRoot . '/src/Entrypoints/Install.php');
    $r13wKeyAt = strpos($r13wInstall, 'WebInstaller::keyMatches(');
    $r13wCheckAt = strpos($r13wInstall, 'WebInstaller::exposedPaths(');
    assert_true($r13wKeyAt !== false && $r13wCheckAt !== false && $r13wCheckAt > $r13wKeyAt && substr_count($r13wInstall, 'WebInstaller::exposedPaths(') === 1
        && str_contains($r13wInstall, 'WebInstaller::protectionError('),
        'R13 low: the self-check (it connects to this server) runs only after the installation key matched');

    // N2: the check asks for a static file (no PHP worker needed), counts a path only when the file itself comes back,
    // follows one redirect to the same host and gives up on anything else.
    $r13wWeb = $r13wBase . '/web';
    foreach (['/src', '/demo/wi/dist', '/api/v1'] as $dir) mkdir($r13wWeb . $dir, 0777, true);
    copy($r13wRoot . '/' . WebInstaller::PROBE_FILE, $r13wWeb . '/' . WebInstaller::PROBE_FILE);
    file_put_contents($r13wWeb . '/demo/wi/dist/starter.json', '{"name":"WI"}');
    copy($r13wRoot . '/router.php', $r13wWeb . '/router.php');
    copy($r13wRoot . '/.htaccess', $r13wWeb . '/.htaccess');
    $r13wPlain = $r13wServe($r13wWeb, ['-t', $r13wWeb]);
    assert_equals([WebInstaller::PROBE_FILE, 'demo/wi/dist/starter.json'], WebInstaller::exposedPaths($r13wWeb, $r13wServer($r13wPlain)),
        'R13 N2: a server that ignores .htaccess is caught serving the static probe file and the starter data');
    assert_equals([], WebInstaller::exposedPaths($r13wWeb, $r13wServer($r13wServe($r13wWeb, ['router.php']))), 'R13 N2: a server that applies the rules passes');
    file_put_contents($r13wBase . '/catchall.php', '<?php echo "<!doctype html><title>Shop</title>";');
    assert_equals([], WebInstaller::exposedPaths($r13wWeb, $r13wServer($r13wServe($r13wBase, [$r13wBase . '/catchall.php']))),
        'R13 N2: a front controller answering 200 with its own page for every path is not taken for exposed files');
    file_put_contents($r13wBase . '/same-host.php', '<?php header("Location: http://127.0.0.1:' . $r13wPlain . '" . $_SERVER["REQUEST_URI"], true, 301);');
    assert_equals([WebInstaller::PROBE_FILE, 'demo/wi/dist/starter.json'], WebInstaller::exposedPaths($r13wWeb, $r13wServer($r13wServe($r13wBase, [$r13wBase . '/same-host.php']))),
        'R13 N2: a redirect to the same host (http -> https, another port) is followed once');
    file_put_contents($r13wBase . '/elsewhere.php', '<?php header("Location: https://login.example.net/", true, 302);');
    assert_equals(null, WebInstaller::exposedPaths($r13wWeb, $r13wServer($r13wServe($r13wBase, [$r13wBase . '/elsewhere.php']))),
        'R13 N2: a redirect elsewhere means "cannot tell"');

    // Low: cPanel's error_log next to the scripts is denied like the other logs (router.php applies .htaccess).
    file_put_contents($r13wWeb . '/error_log', 'PHP Fatal error: secret');
    file_put_contents($r13wWeb . '/api/v1/error_log', 'PHP Fatal error: secret');
    $r13wRouter = $r13wServe($r13wWeb, ['router.php']);
    foreach (['/error_log', '/api/v1/error_log'] as $path) {
        $headers = @get_headers("http://127.0.0.1:{$r13wRouter}{$path}");
        assert_true(is_array($headers) && str_contains((string)$headers[0], '403'), "R13 low: {$path} answers 403");
    }
    assert_true(str_contains((string)file_get_contents($r13wRoot . '/deploy/nginx.conf'), 'error_log$ { return 403; }'), 'R13 low: nginx denies error_log too');

    // Low: a failure is logged without call arguments (the owner's password was one of them).
    $r13wSecret = 'Owner-Passw0rd-r13-' . bin2hex(random_bytes(4));
    try {
        (static function (string $user, string $password): void { throw new RuntimeException('admin user could not be created'); })('owner', $r13wSecret);
    } catch (RuntimeException $e) {
        $r13wLogged = WebInstaller::describe($e);
        assert_true(str_contains($r13wLogged, 'admin user could not be created') && !str_contains($r13wLogged, substr($r13wSecret, 0, 10)),
            'R13 low: the installer failure log names the error but no argument (no password)');
    }
    assert_true(str_contains((string)file_get_contents($r13wRoot . '/src/WebInstaller.php'), 'self::describe($e)'), 'R13 low: installLocked logs through describe()');

    // Low: install-key.php is private to the uploader when PHP runs as that account.
    assert_equals([0600, 0644], [WebInstaller::keyFileMode(1000, 1000), WebInstaller::keyFileMode(33, 1000)], 'R13 low: key file 0600 for the uploader as PHP user, 0644 otherwise');
    if (PHP_OS_FAMILY !== 'Windows') {
        mkdir($r13wBase . '/key');
        touch($r13wBase . '/key/install.php');
        WebInstaller::installKey($r13wBase . '/key');
        assert_equals(0, fileperms($r13wBase . '/key/' . WebInstaller::KEY_FILE) & 0077, 'R13 low: written by the uploading account, the key is unreadable for other accounts');
    }

    // Lows: the web worker buffers a page only up to BUFFER_LIMIT; outside mod_php the body it ends early is gzip-encoded
    // by itself (mod_deflate leaves an encoded body alone, but chunks a plain one until the pass is over).
    assert_true(str_contains((string)file_get_contents($r13wRoot . '/src/WebWorker.php'), 'ob_start(null, self::BUFFER_LIMIT)') && WebWorker::BUFFER_LIMIT <= 4194304,
        'R13 low: the web worker holds back at most BUFFER_LIMIT bytes of a response');
    $r13wPage = str_repeat('<p>Page</p>', 200);
    $r13wGzip = WebWorker::encodeForClient($r13wPage, [], 'br, gzip;q=0.8');
    assert_true($r13wGzip !== $r13wPage && gzdecode($r13wGzip) === $r13wPage, 'R13 low: a client accepting gzip gets the early-ended page gzip-encoded');
    assert_equals([$r13wPage, $r13wPage, $r13wPage], [WebWorker::encodeForClient($r13wPage, [], 'gzip;q=0'), WebWorker::encodeForClient($r13wPage, [], ''),
        WebWorker::encodeForClient($r13wPage, ['Content-Encoding: br'], 'gzip')], 'R13 low: no gzip when refused, not asked for, or already encoded');
} catch (Throwable $e) {
    assert_true(false, 'R13 worker/installer (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    foreach ($r13wServers as $server) if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $r13wTree($r13wBase);
}
