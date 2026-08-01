<?php
require_once __DIR__ . '/cli-only.php';

require_once __DIR__ . '/../src/autoload.php';

$command = (string)($argv[1] ?? 'doctor');
$json = in_array('--json', $argv ?? [], true);
$strict = in_array('--strict', $argv ?? [], true);

try {
    if ($command === 'doctor') {
        $result = Setup::doctor();
    } elseif ($command === 'apply') {
        $result = Setup::apply();
        $result['doctor'] = Setup::doctor();
    } else {
        throw new InvalidArgumentException('Usage: php cli/setup.php doctor|apply [--json] [--strict]');
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
