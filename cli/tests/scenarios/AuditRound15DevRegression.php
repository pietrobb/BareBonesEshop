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
