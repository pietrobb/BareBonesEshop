<?php
/** JSON-lines operational logger with correlation IDs and recursive secret redaction. */
class Logger
{
    private static ?string $correlationId = null;
    private static ?array $config = null;

    public static function correlationId(): string
    {
        if (self::$correlationId !== null) return self::$correlationId;
        self::$correlationId = bin2hex(random_bytes(12));
        return self::$correlationId;
    }

    public static function setCorrelationId(?string $id): void
    {
        if ($id !== null && !preg_match('/^[A-Za-z0-9._-]{8,80}$/', $id)) {
            throw new InvalidArgumentException('Invalid correlation ID');
        }
        self::$correlationId = $id;
    }

    public static function info(string $event, array $context = []): void { self::safeWrite('info', $event, $context); }
    public static function warning(string $event, array $context = []): void { self::safeWrite('warning', $event, $context); }
    public static function error(string $event, array $context = []): void { self::safeWrite('error', $event, $context); }

    public static function exception(string $event, Throwable $exception, array $context = []): void
    {
        $context['exception'] = get_debug_type($exception);
        // Exception messages may contain customer input, SQL values or provider secrets.
        self::error($event, $context);
    }

    public static function write(string $level, string $event, array $context = []): bool
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,79}$/', $event)) {
            throw new InvalidArgumentException('Invalid structured log event');
        }
        $record = [
            'timestamp' => gmdate('c'),
            'level' => $level,
            'event' => $event,
            'correlation_id' => self::correlationId(),
            'store_id' => class_exists('StoreContext', false) && StoreContext::hasCurrent() ? StoreContext::current()->id() : null,
            'channel_id' => class_exists('StoreContext', false) && StoreContext::hasCurrent() ? StoreContext::current()->channel() : null,
            'context' => self::redact($context),
        ];
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $cfg = self::config();
        $runtimeDir = rtrim((string)($cfg['private_runtime_dir'] ?? ''), '/\\');
        $path = trim((string)($cfg['structured_log_file'] ?? ($runtimeDir !== '' ? $runtimeDir . '/logs/application.jsonl' : '')));
        if ($path === '' || !self::isPrivateAbsolutePath($path)) {
            error_log(rtrim($line));
            return false;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            error_log(rtrim($line));
            return false;
        }
        self::rotate($path, (int)($cfg['structured_log_max_bytes'] ?? 10485760), (int)($cfg['structured_log_max_files'] ?? 5));
        if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            error_log(rtrim($line));
            return false;
        }
        return true;
    }

    public static function safeWrite(string $level, string $event, array $context = []): bool
    {
        try {
            return self::write($level, $event, $context);
        } catch (Throwable $e) {
            // Do not call the logger recursively when its own storage is unavailable.
            try { @error_log('BareBones operational log unavailable'); } catch (Throwable $ignored) {}
            return false;
        }
    }

    /** Size-based rotation (file → file.1 → … → file.N); a lock file keeps concurrent writers from rotating twice. */
    private static function rotate(string $path, int $maxBytes, int $maxFiles): void
    {
        clearstatcache(true, $path);
        if ($maxBytes <= 0 || !is_file($path) || (int)filesize($path) < $maxBytes) return;
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false) return;
        try {
            if (!flock($lock, LOCK_EX)) return;
            clearstatcache(true, $path);
            if (!is_file($path) || (int)filesize($path) < $maxBytes) return;
            $maxFiles = max(1, $maxFiles);
            @unlink($path . '.' . $maxFiles);
            for ($i = $maxFiles - 1; $i >= 1; $i--) {
                if (is_file($path . '.' . $i)) @rename($path . '.' . $i, $path . '.' . ($i + 1));
            }
            @rename($path, $path . '.1');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * A secret's value after its key: `token=x`, `password: x`, and in JSON or quoted form `"client_secret":"x"`
     * (the quote between key and colon used to hide it), also as part of a longer key (`stripe_secret_key`,
     * `access_token`). The value stops at a quote, `&`, `,`, `;` or `}`.
     */
    private const SECRET_VALUE = '/(Bearer\s+)[A-Za-z0-9._~+\/=-]+|((?:secret|token|password|passwd|api[_-]?key)[\w-]*["\']?\s*[=:]\s*["\']?)[^\s"\'&,;}]+/i';

    /**
     * PHP error log line with e-mail addresses and secret-looking values masked (exception messages carry customer
     * input), on one line: CR/LF and other control characters are escaped so input cannot forge further log lines.
     */
    public static function phpLog(string $line): void
    {
        $masked = (string)preg_replace(['/[^\s@<>"\'(),;:]+@[^\s@<>"\'(),;:]+\.[a-z]{2,}/i', self::SECRET_VALUE], ['[email]', '$1$2[REDACTED]'], $line);
        error_log((string)preg_replace_callback('/[\x00-\x1F\x7F]/', static fn(array $m): string => match ($m[0]) {
            "\n" => '\n', "\r" => '\r', "\t" => '\t', default => sprintf('\x%02X', ord($m[0])),
        }, $masked));
    }

    public static function redact(array $value): array
    {
        foreach ($value as $key => &$item) {
            if (preg_match('/(?:secret|token|password|authorization|api[_-]?key|credential|cookie|payload|post|message|errors|(?:^|_)(?:name|email|address|ip|session|card|pan|cvv)(?:$|_))/i', (string)$key)) {
                $item = '[REDACTED]';
            } elseif (is_array($item)) {
                $item = self::redact($item);
            } elseif (is_string($item)) {
                $item = preg_replace(self::SECRET_VALUE, '$1$2[REDACTED]', $item);
            } elseif (is_object($item)) {
                $item = '[OBJECT]';
            }
        }
        unset($item);
        return $value;
    }

    private static function isPrivateAbsolutePath(string $path): bool
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $path)) return false;
        $absolute = str_starts_with($path, '/') || str_starts_with($path, '\\\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
        if (!$absolute) return false;
        $normalized = strtolower(rtrim(str_replace('\\', '/', $path), '/'));
        $root = strtolower(rtrim(str_replace('\\', '/', dirname(__DIR__)), '/'));
        return $normalized !== $root && !str_starts_with($normalized, $root . '/');
    }

    private static function config(): array
    {
        if (self::$config === null) self::$config = require __DIR__ . '/../config.php';
        return self::$config;
    }
}
