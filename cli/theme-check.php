<?php
require_once __DIR__ . '/cli-only.php';

/** Read-only theme qualification against this candidate's real checkout presenter. */
function runThemeCompatibility(array $arguments = []): array {
    $command = [PHP_BINARY, __DIR__ . '/tests/ThemeContractProbe.php'];
    foreach ($arguments as $argument) {
        if (preg_match('/^--(?:theme|themes-dir|blocks-dir|stores-file|store)=/', $argument)) $command[] = $argument;
    }
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) return ['status' => 'NO-GO', 'checks' => 0, 'failures' => ['Theme probe could not start']];
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $report = json_decode($output, true);
    if (!is_array($report) || !isset($report['status'], $report['checks'], $report['failures']) || ($exit !== 0 && $report['status'] === 'GO')) {
        return ['status' => 'NO-GO', 'checks' => 0, 'failures' => ['Theme probe failed: ' . trim($error . ' ' . $output)]];
    }
    return $report;
}

/**
 * The updater's own gate, runnable from a trusted NEW release tree: installed site + signed package,
 * assembled privately, nothing installed. Needed when the installed updater predates the gate.
 */
function runPackageThemeGate(array $arguments): array {
    require_once dirname(__DIR__) . '/src/autoload.php';
    $options = [];
    foreach ($arguments as $argument) {
        if (preg_match('/^--(installed|package|public-key)=(.+)$/s', $argument, $match)) $options[$match[1]] = $match[2];
    }
    $publicKey = trim((string)($options['public-key'] ?? getenv('BAREBONES_UPDATE_PUBLIC_KEY') ?: ''));
    try {
        $package = new UpdatePackage((string)$options['package'], $publicKey !== '' ? $publicKey : null, in_array('--allow-unsigned', $arguments, true));
    } catch (Throwable $e) {
        return ['status' => 'NO-GO', 'checks' => 0, 'targets' => [], 'failures' => ['Update package rejected: ' . $e->getMessage()]];
    }
    return (new ThemeUpgradeGate(sys_get_temp_dir()))->evaluate((string)($options['installed'] ?? dirname(__DIR__)), $package);
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $arguments = array_slice($argv, 1);
    $packageMode = (bool)preg_grep('/^--package=/', $arguments);
    $result = $packageMode ? runPackageThemeGate($arguments) : runThemeCompatibility($arguments);
    if (in_array('--json', $argv, true)) echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    else {
        echo "Theme checkout compatibility: {$result['status']} ({$result['checks']} checks)\n";
        foreach ($result['failures'] as $failure) echo "[FAIL] {$failure}\n";
        echo "Read-only: in-memory test database; no orders, emails or payment requests. Not a full browser/payment test.\n";
    }
    exit($result['status'] === 'GO' ? 0 : 1);
}
