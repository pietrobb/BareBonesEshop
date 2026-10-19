<?php
/**
 * LogMail — writes fully rendered emails to <private_runtime_dir>/logs/emails.log.
 *
 * Captured emails carry one-time secrets (password reset, verification and
 * download links), so the default file lives in the private runtime directory
 * outside the web root. An explicit 'log_file' config entry overrides it.
 *
 * Zero external dependencies, zero network. Default adapter for
 * APP_ENV=development and for the test suite. In production, emit
 * a clear warning if this is still active (see MailService::adapter()).
 *
 * Capture format (newline-delimited per email):
 *   [<iso8601>] <adapter> | TO=<to> | FROM=<from> | SUBJECT=<subject>
 *   <headers>
 *   ----8<----
 *   <text body>
 *   ----8<----
 *
 * Captures are ALSO mirrored into an in-memory ring buffer so tests
 * can assert on the exact payload without parsing the log file.
 */
class LogMail implements MailAdapter {
    private const DEFAULT_MAX_BYTES = 5_242_880;
    private string $logFile;
    private int $maxBytes;

    /** In-memory capture for tests. Kept small (last 100 entries). */
    private static array $captured = [];
    private static int $captureLimit = 100;

    public function __construct(array $cfg = []) {
        $runtimeDir = rtrim((string)($cfg['private_runtime_dir'] ?? ''), '/\\');
        if ($runtimeDir === '') $runtimeDir = dirname(__DIR__, 3) . '/bareboneseshop-private';
        $this->logFile = (string)($cfg['log_file'] ?? ($runtimeDir . '/logs/emails.log'));
        $this->maxBytes = max(1024, (int)($cfg['log_max_bytes'] ?? self::DEFAULT_MAX_BYTES));
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
    }

    public function adapterId(): string {
        return 'log';
    }

    public function send(array $msg): array {
        $line = sprintf(
            "[%s] log | TO=%s | FROM=%s | SUBJECT=%s",
            date('c'),
            $msg['to'] ?? '',
            $msg['from'] ?? '',
            $msg['subject'] ?? ''
        );
        foreach (($msg['headers'] ?? []) as $k => $v) {
            $line .= "\n  {$k}: {$v}";
        }
        $line .= "\n----8<----\n";
        $line .= $msg['text'] ?? strip_tags($msg['html'] ?? '');
        $line .= "\n----8<----\n";

        // Rotate before the file outgrows the limit: one older generation (.1) is kept, the one before is dropped.
        clearstatcache(true, $this->logFile);
        if (@filesize($this->logFile) >= $this->maxBytes) @rename($this->logFile, $this->logFile . '.1');
        @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);

        // In-memory capture for tests
        self::$captured[] = $msg;
        if (count(self::$captured) > self::$captureLimit) {
            array_shift(self::$captured);
        }

        return ['status' => 'sent', 'message' => '', 'raw' => $this->logFile];
    }

    /** Testing helpers */
    public static function captured(): array { return self::$captured; }
    public static function lastCaptured(): ?array {
        return self::$captured ? end(self::$captured) : null;
    }
    public static function clearCaptured(): void { self::$captured = []; }
}
