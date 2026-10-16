<?php

define('TEST_CLI_DIR', dirname(__DIR__));

require_once TEST_CLI_DIR . '/../src/autoload.php';

function entrypointSource(string $entrypoint): string {
    $root = dirname(TEST_CLI_DIR);
    $implementations = [
        'admin.php' => [
            'src/Entrypoints/Admin.php', 'src/Entrypoints/Admin/Shared.php',
            'src/Entrypoints/Admin/Catalog.php', 'src/Entrypoints/Admin/Orders.php',
            'src/Entrypoints/Admin/Customers.php', 'src/Entrypoints/Admin/Operations.php',
            'src/Entrypoints/Admin/Request.php',
        ],
        'api.php' => ['src/Entrypoints/Api.php', 'src/Http/ApiHandlers.php'],
        'account.php' => ['src/Entrypoints/Storefront/Layout.php'],
        'wishlist.php' => ['src/Entrypoints/Storefront/Layout.php'],
        'index.php' => [
            'src/Entrypoints/Storefront.php', 'src/Entrypoints/Storefront/Layout.php',
            'src/Entrypoints/Storefront/CatalogPages.php', 'src/Entrypoints/Storefront/CheckoutPages.php',
        ],
    ];
    $source = (string)file_get_contents($root . '/' . $entrypoint);
    foreach ($implementations[$entrypoint] ?? [] as $implementation) {
        $source .= "\n" . (string)file_get_contents($root . '/' . $implementation);
    }
    return $source;
}

$tests = 0;
$passed = 0;
$failed = 0;
$errors = [];

function assert_true(bool $condition, string $message): void {
    global $tests, $passed, $failed, $errors;
    $tests++;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$message}\n";
        return;
    }
    $failed++;
    $errors[] = $message;
    echo "  [FAIL] {$message}\n";
}

function assert_equals($expected, $actual, string $message): void {
    global $tests, $passed, $failed, $errors;
    $tests++;
    if ($expected === $actual) {
        $passed++;
        echo "  [PASS] {$message}\n";
        return;
    }
    $failed++;
    $expStr = var_export($expected, true);
    $actStr = var_export($actual, true);
    $errors[] = "{$message} (expected: {$expStr}, got: {$actStr})";
    echo "  [FAIL] {$message} (expected: {$expStr}, got: {$actStr})\n";
}

function assert_greater(int $actual, int $than, string $message): void {
    assert_true($actual > $than, "{$message} ({$actual} > {$than})");
}

function fail_on_php_error(int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
}

function expect_php_error(int $severity, string $message, callable $callback): bool {
    $captured = false;
    set_error_handler(static function (int $actualSeverity, string $actualMessage, string $file, int $line) use ($severity, $message, &$captured): bool {
        if ($actualSeverity === $severity && str_contains($actualMessage, $message)) {
            $captured = true;
            return true;
        }
        throw new ErrorException($actualMessage, 0, $actualSeverity, $file, $line);
    });
    try {
        $callback();
    } finally {
        restore_error_handler();
    }
    return $captured;
}

function with_expected_structured_log(string $event, callable $callback): mixed {
    $path = test_fixture_path('expected-log') . '.jsonl';
    @unlink($path);
    $config = new ReflectionProperty(Logger::class, 'config');
    $previous = $config->getValue();
    $config->setValue(null, ['structured_log_file' => $path]);
    try {
        $result = $callback();
    } finally {
        $config->setValue(null, $previous);
    }
    $log = is_file($path) ? (string)file_get_contents($path) : '';
    @unlink($path);
    if (!str_contains($log, '"event":"' . $event . '"')) {
        throw new RuntimeException("Expected structured log event was not emitted: {$event}");
    }
    return $result;
}

function with_expected_error_log(string $message, callable $callback): mixed {
    $path = test_fixture_path('expected-error') . '.log';
    @unlink($path);
    $previous = (string)ini_get('error_log');
    ini_set('error_log', $path);
    try {
        $result = $callback();
    } finally {
        ini_set('error_log', $previous);
    }
    $log = is_file($path) ? (string)file_get_contents($path) : '';
    @unlink($path);
    if (!str_contains($log, $message)) {
        throw new RuntimeException("Expected error log was not emitted: {$message}");
    }
    return $result;
}

function test_fixture_path(string $name): string {
    return sys_get_temp_dir() . '/barebones-' . $name . '-' . getmypid() . '-' . bin2hex(random_bytes(4));
}
