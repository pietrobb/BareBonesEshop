<?php
require_once __DIR__ . '/cli-only.php';
require_once __DIR__ . '/../src/autoload.php';

try {
    $snapshot = OperationalMetrics::report();
    echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit($snapshot['status'] === 'critical' ? 2 : 0);
} catch (Throwable $e) {
    Logger::exception('monitoring.collection_failed', $e);
    fwrite(STDERR, "Monitoring collection failed: {$e->getMessage()}\n");
    exit(2);
}
