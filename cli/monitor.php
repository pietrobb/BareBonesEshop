<?php
require_once __DIR__ . '/cli-only.php';
require_once __DIR__ . '/../src/autoload.php';

try {
    if (in_array('--probe-mail', $argv ?? [], true)) IncidentMonitor::report('monitoring.delivery_probe', 'monitoring', 'critical');
    IncidentMonitor::probeLog();
    IncidentMonitor::sweep();
    IncidentMonitor::heartbeat('monitor');
    $snapshot = OperationalMetrics::report();
    echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit($snapshot['status'] === 'critical' ? 2 : 0);
} catch (Throwable $e) {
    Logger::exception('monitoring.collection_failed', $e);
    IncidentMonitor::heartbeat('monitor', 'failed');
    fwrite(STDERR, "monitoring.collection_failed\n");
    exit(2);
}
