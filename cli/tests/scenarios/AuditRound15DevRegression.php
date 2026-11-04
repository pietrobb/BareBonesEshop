<?php

// Round 15 review (2026-11-04) "extensions and developer documentation". Medium: a broken extension class (a provider
// missing one of its interface's methods) ended every request of the shop with a fatal error, although the
// documentation promises that a broken extension is skipped and the shop keeps running.
echo "\n--- Audit round 15 (dev): a fatal error quarantines the extension, the shop keeps running ---\n";
$r15dBase = test_fixture_path('r15-dev');
$r15dRemove = static function (string $path) use (&$r15dRemove): void {
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
};
try {
    $r15dExt = $r15dBase . '/extensions';
    foreach (['bad-iface', 'good-one'] as $r15dCode) mkdir($r15dExt . '/' . $r15dCode . '/src', 0777, true);
    file_put_contents($r15dExt . '/bad-iface/extension.php', <<<'PHP'
<?php
return ['code' => 'bad-iface', 'name' => 'Bad', 'version' => '1.0.0', 'register' => static function (ExtensionApi $api): void {
    $api->provider('mail', 'r15-bad-mail', R15BadMail::class, ['send']);
}];
PHP);
    // Implements the interface but misses send(): PHP stops with a fatal error that no try/catch sees.
    file_put_contents($r15dExt . '/bad-iface/src/R15BadMail.php', "<?php\nclass R15BadMail implements MailAdapter {}\n");
    file_put_contents($r15dExt . '/good-one/extension.php', <<<'PHP'
<?php
return ['code' => 'good-one', 'name' => 'Good', 'version' => '1.0.0', 'register' => static function (ExtensionApi $api): void {
    $api->api('ping', static fn(array $query): array => ['pong' => true]);
}];
PHP);
    $r15dFixture = $r15dBase . '/boot.php';
    file_put_contents($r15dFixture, <<<'PHP'
<?php
[, $root, $dir] = $argv;
require $root . '/src/autoload.php';
Extensions::boot(['extensions' => ['bad-iface', 'good-one'], 'private_runtime_dir' => $dir], $dir . '/extensions');
echo "\nR15DSTATE" . json_encode(['loaded' => array_keys(Extensions::loaded()), 'errors' => Extensions::errors()]);
PHP);
    $r15dRun = static function () use ($r15dFixture, $r15dBase): array {
        $process = proc_open([PHP_BINARY, $r15dFixture, dirname(TEST_CLI_DIR), $r15dBase], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $state = str_contains($raw, "\nR15DSTATE") ? json_decode(substr($raw, strrpos($raw, "\nR15DSTATE") + 10), true) : null;
        return [$exit, $state, $raw];
    };
    $r15dQuarantine = static fn(): array => (array)json_decode((string)@file_get_contents($r15dBase . '/extension-quarantine.json'), true);

    [$r15dExit, $r15dState, $r15dRaw] = $r15dRun();
    $r15dEntry = $r15dQuarantine()['bad-iface'] ?? [];
    assert_true($r15dExit !== 0 && $r15dState === null && str_contains((string)($r15dEntry['error'] ?? ''), 'R15BadMail')
        && str_contains((string)($r15dEntry['error'] ?? ''), 'src/R15BadMail.php:'),
        'R15 dev: the request that hits the fatal error fails, and the extension whose file raised it is quarantined with the message and file ('
        . substr(trim($r15dRaw), 0, 200) . ')');

    [$r15dExit, $r15dState, $r15dRaw] = $r15dRun();
    assert_true($r15dExit === 0 && ($r15dState['loaded'] ?? null) === ['good-one']
        && str_contains((string)($r15dState['errors']['bad-iface'] ?? ''), 'quarantined after a fatal error')
        && str_contains((string)($r15dState['errors']['bad-iface'] ?? ''), 'R15BadMail.php'),
        'R15 dev: the next request runs: the other extension is loaded and the quarantined one is reported with its reason ('
        . substr(trim($r15dRaw), 0, 200) . ')');

    // The developer fixes the class: the changed file lifts the quarantine on the next request.
    file_put_contents($r15dExt . '/bad-iface/src/R15BadMail.php',
        "<?php\nclass R15BadMail implements MailAdapter {\n    public function send(array \$msg): array { return ['status' => 'sent']; }\n"
        . "    public function adapterId(): string { return 'r15-bad-mail'; }\n}\n");
    [$r15dExit, $r15dState, $r15dRaw] = $r15dRun();
    assert_true($r15dExit === 0 && ($r15dState['loaded'] ?? null) === ['bad-iface', 'good-one'] && ($r15dState['errors'] ?? null) === []
        && !isset($r15dQuarantine()['bad-iface']),
        'R15 dev: after a change to the extension\'s PHP files it is loaded again and its quarantine entry is removed ('
        . substr(trim($r15dRaw), 0, 200) . ')');
} catch (Throwable $e) {
    assert_true(false, 'R15 dev extension quarantine (' . get_class($e) . ': ' . $e->getMessage() . ')');
} finally {
    $r15dRemove($r15dBase);
}
// Medium: the tracking webhook read only the core body format: a carrier adapter had no say in it.
$r15tPdo = Db::get();
$r15tCarrier = new class implements TrackingWebhookAdapter {
    public function getRates(array $params): array { return []; }
    public function generateLabel(array $order): array { throw new LogicException('not in this test'); }
    public function trackShipment(string $awb): array { return []; }
    public function supportsCOD(): bool { return false; }
    public function carrierId(): string { return 'r15-carrier'; }
    public function parseTrackingWebhook(array $payload, string $rawPayload): array {
        $state = (string)($payload['parcel']['state'] ?? '');
        return ['awb' => (string)($payload['parcel']['barcode'] ?? ''), 'status' => match ($state) { 'DLV' => 'delivered', 'TRN' => 'in_transit', default => $state }];
    }
};
ShippingService::setAdapters(['r15-carrier' => $r15tCarrier]);
putenv('SHIP_R15_CARRIER_WEBHOOK_SECRET=r15-carrier-secret-0123456789');
try {
    $r15tOrder = (int)$r15tPdo->query("SELECT id FROM orders WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $r15tPdo->prepare("INSERT INTO shipping_labels (order_id, store_id, carrier, awb, status, label_data) VALUES (?, 'default', 'r15-carrier', 'R15-AWB-1', 'created', '')")->execute([$r15tOrder]);
    $r15tHook = static function (array $body): array {
        $raw = json_encode($body);
        $ts = (string)time();
        return ShippingService::handleTrackingWebhook('r15-carrier', json_decode($raw, true), hash_hmac('sha256', $ts . '.' . $raw, 'r15-carrier-secret-0123456789'), $raw, $ts);
    };
    $r15tStatus = static fn(): string => (string)$r15tPdo->query("SELECT status FROM shipping_labels WHERE awb = 'R15-AWB-1'")->fetchColumn();
    assert_true(($r15tHook(['parcel' => ['barcode' => 'R15-AWB-1', 'state' => 'DLV']])['handled'] ?? false) === true && $r15tStatus() === 'delivered',
        'R15 dev: a carrier adapter implementing TrackingWebhookAdapter reads its own notification format (DLV -> delivered)');
    assert_equals('missing_awb', $r15tHook(['awb' => 'R15-AWB-1', 'status' => 'in_transit'])['reason'] ?? null,
        'R15 dev: for such a carrier the body goes through the adapter, not the core {awb, status} format');
} finally {
    $r15tPdo->exec("DELETE FROM shipping_labels WHERE awb = 'R15-AWB-1'");
    putenv('SHIP_R15_CARRIER_WEBHOOK_SECRET');
    ShippingService::setAdapters([]);
}

// Medium: `php cli/test.php` as the README showed it aborted halfway on a fresh clone (no config.php, empty vendor/bbf
// submodule). The runner now names both missing pieces and how to get them before running anything.
$r15cRoot = test_fixture_path('r15-fresh-clone');
try {
    mkdir($r15cRoot . '/cli', 0777, true);
    foreach (['cli-only.php', 'TestSuite.php'] as $r15cFile) copy(TEST_CLI_DIR . '/' . $r15cFile, $r15cRoot . '/cli/' . $r15cFile);
    $r15cProcess = proc_open([PHP_BINARY, $r15cRoot . '/cli/TestSuite.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $r15cPipes);
    fclose($r15cPipes[0]);
    $r15cOut = stream_get_contents($r15cPipes[1]) . stream_get_contents($r15cPipes[2]);
    fclose($r15cPipes[1]); fclose($r15cPipes[2]);
    $r15cExit = proc_close($r15cProcess);
    assert_true($r15cExit === 1 && str_contains($r15cOut, 'cp config.example.php config.php') && str_contains($r15cOut, 'git submodule update --init'),
        'R15 dev: on a fresh clone the test runner stops with how to create config.php and fetch vendor/bbf (' . trim($r15cOut) . ')');
    $r15cReadme = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/README.md');
    assert_true(str_contains($r15cReadme, 'git clone --recurse-submodules') && str_contains($r15cReadme, 'cp config.example.php config.php')
        && is_file(dirname(TEST_CLI_DIR) . '/CONTRIBUTING.md'),
        'R15 dev: the README test section clones with submodules and creates config.php; CONTRIBUTING.md exists');
} finally {
    $r15dRemove($r15cRoot);
}

// Parallel runs (a second checkout next to this one, the SQLite and MySQL suites side by side) share the default
// jobs_dir of config.example.php. The worker took its lock there even when the queue had been pointed elsewhere,
// so a test's in-process worker backed off with exit(0) and took the whole run (or the fixture) down with it.
$r15lJobs = test_fixture_path('r15-dev-jobs');
JobQueue::setDir($r15lJobs);
assert_true(JobQueue::workerLockFile() === $r15lJobs . '/worker.lock' && is_dir($r15lJobs)
    && !str_contains((string)file_get_contents(TEST_CLI_DIR . '/worker.php'), '/worker.lock'),
    'R15 dev: the file worker locks inside the jobs directory the queue actually uses, not the shared default');
