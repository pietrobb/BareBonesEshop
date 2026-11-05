<?php

// Review round 17, medium: one request could still switch an extension off for good. "Out of memory" (a failed
// allocation, not the memory_limit) was missing from the exceptions, so input that made an extension allocate too
// much quarantined it, payment provider included. And without private_runtime_dir the quarantine file was in the
// shared temp directory under a predictable name, where another account on the host could plant one.
echo "\n--- Audit round 17 (extensions): only broken code quarantines, never a shared temp file ---\n";
$r17qBase = test_fixture_path('r17-quarantine');
$r17qRemove = static function (string $path) use (&$r17qRemove): void {
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
};

// 1. Which fatal errors mean broken extension code.
$r17qBroken = new ReflectionMethod(Extensions::class, 'brokenCode');
$r17qBroken->setAccessible(true);
$r17qCases = [
    'out of memory' => [E_ERROR, 'Out of memory (allocated 2097152 bytes) (tried to allocate 70368744177696 bytes)', false],
    'memory limit' => [E_ERROR, 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)', false],
    'time limit' => [E_ERROR, 'Maximum execution time of 30 seconds exceeded', false],
    'stack' => [E_ERROR, 'Maximum call stack size of 8339456 bytes reached. Infinite recursion?', false],
    'uncaught' => [E_ERROR, 'Uncaught InvalidArgumentException: payment id must be numeric in /x/src/A.php:3', false],
    'missing interface method' => [E_ERROR, 'Class AcmeMail contains 1 abstract method and must therefore be declared abstract or implement the remaining methods (MailAdapter::send)', true],
    'declared twice' => [E_COMPILE_ERROR, 'Cannot declare class AcmeMail, because the name is already in use', true],
    'incompatible signature' => [E_COMPILE_ERROR, 'Declaration of AcmeMail::send(string $m): array must be compatible with MailAdapter::send(array $msg): array', true],
    'parse error' => [E_PARSE, 'syntax error, unexpected token "}"', true],
];
$r17qGot = [];
foreach ($r17qCases as $r17qName => [$r17qType, $r17qMessage]) $r17qGot[$r17qName] = $r17qBroken->invoke(null, $r17qType, $r17qMessage);
assert_equals(array_map(static fn(array $case): bool => $case[2], $r17qCases), $r17qGot,
    'R17 medium: only parse, compile and class-linking errors quarantine; memory, time, stack and exceptions do not');

try {
    mkdir($r17qBase . '/private', 0700, true);
    mkdir($r17qBase . '/extensions/r17-pay/src', 0777, true);
    file_put_contents($r17qBase . '/extensions/r17-pay/extension.php', <<<'PHP'
<?php
return ['code' => 'r17-pay', 'name' => 'R17 Pay', 'version' => '1.0.0', 'register' => static function (ExtensionApi $api): void {
    $api->provider('mail', 'r17-pay-mail', R17PayMail::class, ['send']);
}];
PHP);
    file_put_contents($r17qBase . '/extensions/r17-pay/src/R17PayMail.php', <<<'PHP'
<?php
class R17PayMail implements MailAdapter
{
    public function send(array $msg): array { return ['status' => 'sent']; }
    public function adapterId(): string { return 'r17-pay-mail'; }
    // An amount from the request sizes a buffer: absurd input fails the allocation itself ("Out of memory").
    public static function receipt(int $bytes): string { return str_repeat('x', $bytes); }
}
PHP);
    // One request: boot with private_runtime_dir = argv[4] ('' = none), then in mode "oom" allocate 64 TiB in the
    // extension's file with no memory_limit, which ends the request with "Out of memory".
    file_put_contents($r17qBase . '/request.php', <<<'PHP'
<?php
[, $root, $dir, $mode, $private] = $argv;
require $root . '/src/autoload.php';
Extensions::boot(['extensions' => ['r17-pay'], 'private_runtime_dir' => $private], $dir . '/extensions');
echo "\nR17QSTATE" . json_encode(['loaded' => array_keys(Extensions::loaded()), 'errors' => Extensions::errors()]);
if ($mode === 'oom') { ini_set('memory_limit', '-1'); R17PayMail::receipt(1 << 46); }
PHP);
    $r17qRun = static function (string $mode, string $private) use ($r17qBase): array {
        $process = proc_open([PHP_BINARY, $r17qBase . '/request.php', dirname(TEST_CLI_DIR), $r17qBase, $mode, $private],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $raw = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $state = str_contains($raw, "\nR17QSTATE") ? json_decode(strtok(substr($raw, strrpos($raw, "\nR17QSTATE") + 10), "\n"), true) : null;
        return [$state, $raw];
    };

    // 2. "Out of memory" raised in the extension's file: the request fails, the extension stays.
    [, $r17qRaw] = $r17qRun('oom', $r17qBase . '/private');
    $r17qFile = $r17qBase . '/private/extension-quarantine.json';
    assert_true(str_contains($r17qRaw, 'Out of memory') && !isset(((array)json_decode((string)@file_get_contents($r17qFile), true))['r17-pay']),
        'R17 medium: "Out of memory" in an extension does not quarantine it (' . substr(trim($r17qRaw), 0, 200) . ')');
    [$r17qState, $r17qRaw] = $r17qRun('none', $r17qBase . '/private');
    assert_true(($r17qState['loaded'] ?? null) === ['r17-pay'] && ($r17qState['errors'] ?? null) === [],
        'R17 medium: after the out-of-memory request the extension is still loaded (' . substr(trim($r17qRaw), 0, 200) . ')');

    // 3. Without private_runtime_dir nothing is read from (or written to) the shared temp directory: a quarantine
    // planted under the old predictable name does not switch the extension off.
    $r17qFingerprint = new ReflectionMethod(Extensions::class, 'fingerprint');
    $r17qFingerprint->setAccessible(true);
    $r17qExtensions = $r17qBase . '/extensions';
    $r17qOldPath = rtrim(sys_get_temp_dir(), '/\\') . '/bbeshop-' . substr(hash('sha256', str_replace('\\', '/', (string)realpath($r17qExtensions))), 0, 16) . '-extension-quarantine.json';
    file_put_contents($r17qOldPath, json_encode(['r17-pay' => ['error' => 'planted', 'at' => time(), 'fingerprint' => $r17qFingerprint->invoke(null, $r17qExtensions . '/r17-pay')]]));
    [$r17qState, $r17qRaw] = $r17qRun('none', '');
    assert_true(($r17qState['loaded'] ?? null) === ['r17-pay'] && ($r17qState['errors'] ?? null) === [],
        'R17 medium: a quarantine planted in the shared temp directory is ignored (' . substr(trim($r17qRaw), 0, 200) . ')');
    @unlink($r17qOldPath);
    $r17qTempFiles = glob(rtrim(sys_get_temp_dir(), '/\\') . '/bbeshop-*-extension-quarantine.json*') ?: [];
    [, $r17qRaw] = $r17qRun('oom', '');
    assert_equals($r17qTempFiles, glob(rtrim(sys_get_temp_dir(), '/\\') . '/bbeshop-*-extension-quarantine.json*') ?: [],
        'R17 medium: without private_runtime_dir the shop writes no quarantine file to the temp directory');
} catch (Throwable $e) {
    assert_true(false, 'R17 extension quarantine (' . get_class($e) . ': ' . $e->getMessage() . ')');
} finally {
    $r17qRemove($r17qBase);
}
