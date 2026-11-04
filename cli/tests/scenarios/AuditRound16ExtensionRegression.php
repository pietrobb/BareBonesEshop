<?php

// Round 16 review (2026-11-04) "extension quarantine". Medium: one anonymous request disabled an extension for good. An
// extension page that throws on bad input (an ordinary, catchable exception) ended the request as "PHP Fatal error:
// Uncaught ...", the R15 shutdown guard took that for a broken extension and quarantined it, payment provider included,
// until a developer changed one of its files. Quarantine is for errors PHP cannot catch; a throwing page is a 500.
echo "\n--- Audit round 16 (extensions): a throwing page is a 500, never a quarantine ---\n";
$r16eBase = test_fixture_path('r16-ext');
$r16eRemove = static function (string $path) use (&$r16eRemove): void {
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
};
try {
    mkdir($r16eBase . '/extensions/r16-pay/src', 0777, true);
    file_put_contents($r16eBase . '/extensions/r16-pay/extension.php', <<<'PHP'
<?php
return ['code' => 'r16-pay', 'name' => 'R16 Pay', 'version' => '1.0.0', 'register' => static function (ExtensionApi $api): void {
    $api->provider('mail', 'r16-pay-mail', R16PayMail::class, ['send']);
    $api->page('status', static fn(array $query): array => ['title' => 'Payment status', 'content' => R16PayMail::status((string)($query['id'] ?? ''))]);
}];
PHP);
    file_put_contents($r16eBase . '/extensions/r16-pay/src/R16PayMail.php', <<<'PHP'
<?php
class R16PayMail implements MailAdapter
{
    public function send(array $msg): array { return ['status' => 'sent']; }
    public function adapterId(): string { return 'r16-pay-mail'; }
    public static function status(string $id): string
    {
        if (!ctype_digit($id)) throw new InvalidArgumentException('payment id must be numeric');
        return 'Payment ' . $id;
    }
}
PHP);
    // One request of the shop: boot the extensions, then (mode "page") render the page with ?id=<bad>, or (mode "raw")
    // let the same exception escape uncaught from the extension's file, as any extension hook outside a page could.
    file_put_contents($r16eBase . '/request.php', <<<'PHP'
<?php
[, $root, $dir, $mode] = $argv;
require $root . '/src/autoload.php';
Extensions::boot(['extensions' => ['r16-pay'], 'private_runtime_dir' => $dir], $dir . '/extensions');
$state = ['loaded' => array_keys(Extensions::loaded()), 'errors' => Extensions::errors(), 'mail' => array_keys(Extensions::providers()['mail'] ?? [])];
if ($mode === 'page') {
    $_GET = ['page' => 'r16-pay.status', 'id' => "1' OR 1=1"];
    $page = Extensions::page('r16-pay.status');
    $state['page'] = $page;
    $state['status'] = http_response_code();
}
echo "\nR16ESTATE" . json_encode($state);
if ($mode === 'raw') R16PayMail::status('x');
PHP);
    $r16eRun = static function (string $mode) use ($r16eBase): array {
        $process = proc_open([PHP_BINARY, $r16eBase . '/request.php', dirname(TEST_CLI_DIR), $r16eBase, $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $state = str_contains($raw, "\nR16ESTATE") ? json_decode(strtok(substr($raw, strrpos($raw, "\nR16ESTATE") + 10), "\n"), true) : null;
        return [$state, $raw];
    };
    $r16eQuarantined = static fn(): bool => isset(((array)json_decode((string)@file_get_contents($r16eBase . '/extension-quarantine.json'), true))['r16-pay']);

    [$r16eState, $r16eRaw] = $r16eRun('page');
    assert_true(is_array($r16eState) && ($r16eState['status'] ?? null) === 500 && is_array($r16eState['page'] ?? null)
        && !str_contains((string)($r16eState['page']['content'] ?? ''), 'payment id must be numeric') && !str_contains($r16eRaw, 'Fatal error'),
        'R16 medium: an extension page throwing on bad input answers 500 with a neutral page, not a fatal error (' . substr(trim($r16eRaw), 0, 200) . ')');
    [$r16eState, $r16eRaw] = $r16eRun('none');
    assert_true(!$r16eQuarantined() && ($r16eState['loaded'] ?? null) === ['r16-pay'] && ($r16eState['mail'] ?? null) === ['r16-pay-mail'],
        'R16 medium: after the bad request the extension and its provider are still loaded (' . substr(trim($r16eRaw), 0, 200) . ')');

    // An exception escaping from extension code outside a page is catchable too: the request fails, the extension stays.
    [$r16eState, $r16eRaw] = $r16eRun('raw');
    assert_true(str_contains($r16eRaw, 'Uncaught InvalidArgumentException') && !$r16eQuarantined(),
        'R16 medium: an uncaught exception from an extension file is not a reason to quarantine it (' . substr(trim($r16eRaw), 0, 200) . ')');
    [$r16eState, $r16eRaw] = $r16eRun('none');
    assert_true(($r16eState['loaded'] ?? null) === ['r16-pay'] && ($r16eState['errors'] ?? null) === [],
        'R16 medium: the next request loads the extension normally (' . substr(trim($r16eRaw), 0, 200) . ')');
} catch (Throwable $e) {
    assert_true(false, 'R16 extension quarantine (' . get_class($e) . ': ' . $e->getMessage() . ')');
} finally {
    $r16eRemove($r16eBase);
}
