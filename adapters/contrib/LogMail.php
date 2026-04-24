<?php
/**
 * LogMail — writes fully rendered emails to logs/emails.log.
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
    private string $logFile;

    /** In-memory capture for tests. Kept small (last 100 entries). */
    private static array $captured = [];
    private static int $captureLimit = 100;

    public function __construct(array $cfg = []) {
        $this->logFile = $cfg['log_file']
            ?? (__DIR__ . '/../../logs/emails.log');
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
