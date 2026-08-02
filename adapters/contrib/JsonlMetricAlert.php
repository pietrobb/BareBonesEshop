<?php
/** Dependency-free metric/alert sink for private JSON-lines ingestion. */
class JsonlMetricAlert implements MetricAlertAdapter
{
    private string $path;

    public function __construct(array $config)
    {
        $this->path = trim((string)($config['metrics_log_file'] ?? ''));
        if (!self::isPrivateAbsolutePath($this->path)) {
            throw new RuntimeException('metrics_log_file must be an absolute private path outside the application root');
        }
    }

    public function publish(array $metrics, array $alerts, array $context = []): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create metrics log directory: {$dir}");
        }
        $record = [
            'timestamp' => gmdate('c'),
            'metrics' => $metrics,
            'alerts' => $alerts,
            'context' => Logger::redact($context),
        ];
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Cannot write metrics log: {$this->path}");
        }
    }

    private static function isPrivateAbsolutePath(string $path): bool
    {
        if ($path === '' || preg_match('/[\x00-\x1F\x7F]/', $path)) return false;
        $absolute = str_starts_with($path, '/') || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        if (!$absolute) return false;
        $normalized = strtolower(rtrim(str_replace('\\', '/', $path), '/'));
        $root = strtolower(rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/'));
        return $normalized !== $root && !str_starts_with($normalized, $root . '/');
    }
}
