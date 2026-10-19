<?php
require_once __DIR__ . '/cli-only.php';

$argv = $_SERVER['argv'] ?? [];
$command = (string)($argv[1] ?? 'doctor');
$json = in_array('--json', $argv, true);
$strict = in_array('--strict', $argv, true);
$root = dirname(__DIR__);

// install runs before anything reads config.php or the bundled BBF forms library.
if ($command === 'install') {
    require_once __DIR__ . '/../src/Setup.php';
    $options = cli_long_options($argv, ['admin-username:', 'app-url:']);
    $adminUsername = trim((string)($options['admin-username'] ?? ''));
    $log = static function (string $line) use ($json): void {
        fwrite($json ? STDERR : STDOUT, $line . "\n");
    };
    // Child output is returned, not echoed: JSON is parsed and the owner's TOTP secret is printed once.
    $run = static function (array $args, string $label, bool $echo = false) use ($root, $log): string {
        $errFile = tempnam(sys_get_temp_dir(), 'bbe-setup');
        $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, $root);
        if (!is_resource($process)) throw new RuntimeException("Cannot start {$label}");
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);
        $err = (string)@file_get_contents($errFile);
        @unlink($errFile);
        foreach (preg_split('/\R/', rtrim(($echo || $code !== 0 ? $out : '') . $err)) ?: [] as $line) if ($line !== '') $log('  ' . $line);
        if ($code !== 0) throw new RuntimeException("{$label} failed (exit {$code})");
        return $out;
    };
    $steps = [];
    try {
        if (!Setup::bundledFormsPresent($root)) throw new RuntimeException('vendor/bbf is missing. ' . Setup::SUBMODULE_ACTION);
        if ($adminUsername !== '' && (string)(getenv('BBE_ADMIN_PASSWORD') ?: '') === '') {
            throw new RuntimeException('--admin-username needs BBE_ADMIN_PASSWORD in the process environment');
        }
        $log('[1/4] config.php');
        $steps['config'] = Setup::createConfig($root, trim((string)($options['app-url'] ?? '')));
        $log('  ' . $steps['config']['action']);
        $log('[2/4] private runtime paths');
        $steps['apply'] = json_decode($run(['cli/setup.php', 'apply', '--json'], 'setup apply'), true)['changes'] ?? [];
        $log('[3/4] database schema, admin forms and import templates');
        $run(['cli/migrate.php'], 'migrate', true);
        $run(['cli/schema-build.php'], 'schema-build', true);
        $steps['schema'] = 'applied';
        // A fresh config.php over an existing database (lost config, new checkout over the old private data)
        // carries a new admin_identity_key that cannot open the existing admins' MFA secrets: undo, do not lock them out.
        if ($steps['config']['action'] === 'created') {
            $keyCheck = array_values(array_filter(json_decode($run(['cli/setup.php', 'doctor', '--json'], 'setup doctor'), true)['checks'] ?? [], static fn(array $c): bool => $c['name'] === 'admin.mfa_key'))[0] ?? null;
            if (($keyCheck['status'] ?? 'pass') === 'fail') {
                @unlink($root . '/config.php');
                $steps['config']['action'] = 'removed';
                throw new RuntimeException('The database already has admin accounts whose MFA secrets need the previous admin_identity_key; the new config.php was removed. Restore the previous config.php (or set BBE_ADMIN_IDENTITY_KEY) and run install again.');
            }
        }
        if ($adminUsername !== '') {
            $log('[4/4] first owner account');
            $stores = json_decode((string)file_get_contents($root . '/stores.json'), true);
            $admin = $run(['cli/admin-user.php', 'create', '--username=' . $adminUsername, '--role=owner', '--store=' . (string)($stores['default_store'] ?? 'default')], 'admin-user create');
            $steps['admin'] = json_decode($admin, true);
        } else {
            $log('[4/4] first owner account: skipped (BBE_ADMIN_PASSWORD=... php cli/setup.php install --admin-username=NAME, or php cli/admin-user.php create)');
            $steps['admin'] = 'skipped';
        }
        $doctor = json_decode($run(['cli/setup.php', 'doctor', '--json'], 'setup doctor'), true);
    } catch (Throwable $e) {
        if ($json) echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'steps' => $steps], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        else fwrite(STDERR, 'Install failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
    if ($json) {
        echo json_encode(['status' => 'installed', 'steps' => $steps, 'doctor' => $doctor], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    } else {
        if (is_array($steps['admin'])) {
            echo "Owner account (store the TOTP secret and recovery codes now; they are not shown again):\n";
            echo json_encode($steps['admin'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        }
        foreach ($doctor['checks'] ?? [] as $check) {
            if ($check['status'] !== 'pass') echo '[' . strtoupper($check['status']) . "] {$check['name']}: {$check['message']}" . ($check['action'] !== '' ? " -> {$check['action']}" : '') . "\n";
        }
        echo "Installed. Doctor: {$doctor['status']} ({$doctor['counts']['pass']} pass, {$doctor['counts']['warn']} warn, {$doctor['counts']['fail']} fail)\n";
        echo "Development server: php -S localhost:8080 router.php\n";
    }
    exit($strict && ($doctor['status'] ?? '') !== 'ready' ? 1 : 0);
}

require_once __DIR__ . '/../src/autoload.php';

try {
    if ($command === 'doctor') {
        $result = Setup::doctor();
    } elseif ($command === 'apply') {
        $result = Setup::apply();
        $result['doctor'] = Setup::doctor();
    } else {
        throw new InvalidArgumentException('Usage: php cli/setup.php install|doctor|apply [--json] [--strict] [--app-url=URL] [--admin-username=NAME]');
    }

    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    } else {
        echo "BareBonesEshop merchant setup — {$command}\n";
        echo str_repeat('=', 42) . "\n";
        $doctor = $command === 'doctor' ? $result : $result['doctor'];
        foreach ($doctor['checks'] as $check) {
            echo '[' . strtoupper($check['status']) . "] {$check['name']}: {$check['message']}\n";
            if ($check['action'] !== '') echo "  -> {$check['action']}\n";
        }
        echo "Result: {$doctor['status']} ({$doctor['counts']['pass']} pass, {$doctor['counts']['warn']} warn, {$doctor['counts']['fail']} fail)\n";
    }
    $doctor = $command === 'doctor' ? $result : $result['doctor'];
    exit($strict && $doctor['status'] !== 'ready' ? 1 : 0);
} catch (Throwable $e) {
    $error = ['status' => 'error', 'message' => $e->getMessage()];
    if ($json) echo json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    else fwrite(STDERR, 'Setup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
