<?php
/**
 * Background jobs on hosting without a command line or cron (operations.web_worker = true, set by the web
 * installer): after a storefront or admin response, at most once per interval, one `cli/worker.php --once`
 * pass runs in the same PHP process. Without it a paid eBook is never granted and no e-mail leaves the queue.
 */
final class WebWorker
{
    public const DEFAULT_INTERVAL = 60;
    /** Seconds a pass may run: the web max_execution_time would kill a feed import or backup half-way, every time. */
    public const TIME_LIMIT = 300;
    private static bool $scheduled = false;
    private static bool $ran = false;

    public static function enabled(array $config): bool
    {
        return ($config['operations']['web_worker'] ?? false) === true;
    }

    /**
     * Registers the pass as the LAST shutdown function, after the page's own shutdown handlers. PHP writes and unlocks
     * the session only after all shutdown functions, so the pass closes it itself (finishResponse()). Without
     * fastcgi_finish_request (Apache mod_php, php -S) the response is buffered from here on, so the pass can end it
     * with an exact Content-Length before it starts working.
     */
    public static function afterResponse(): void
    {
        if (self::$scheduled || PHP_SAPI === 'cli' || !self::enabled(Security::config())) return;
        self::$scheduled = true;
        if (!self::finishesNatively() && !headers_sent()) ob_start();
        register_shutdown_function(static function (): void {
            register_shutdown_function(static function (): void {
                try {
                    self::runIfDue(Security::config(), dirname(__DIR__));
                } catch (\Throwable $e) {
                    Logger::exception('web_worker.failed', $e);
                }
            });
        });
    }

    /** Claims the interval slot (non-blocking file lock + timestamp in jobs_dir); true when a pass ran. */
    public static function runIfDue(array $config, string $root, ?int $now = null): bool
    {
        // A response the client cannot see complete (streamed without a length, no FastCGI) would wait for the pass.
        if (self::$ran || !self::enabled($config) || !self::responseCanFinish()) return false;
        $now ??= time();
        $interval = max(10, (int)($config['operations']['web_worker_interval'] ?? self::DEFAULT_INTERVAL));
        $dir = (string)($config['jobs_dir'] ?? dirname($root) . '/bareboneseshop-private/jobs');
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return false;
        $stamp = @fopen($dir . '/web-worker.last', 'c+');
        if ($stamp === false) return false;
        try {
            if (!flock($stamp, LOCK_EX | LOCK_NB)) return false;
            $last = (int)stream_get_contents($stamp);
            if ($last > 0 && $now - $last < $interval) return false;
            ftruncate($stamp, 0);
            rewind($stamp);
            fwrite($stamp, (string)$now);
            fflush($stamp);
        } finally {
            fclose($stamp);
        }
        self::$ran = true;
        ignore_user_abort(true);
        self::finishResponse();
        self::runWorker($root);
        return true;
    }

    private static function finishesNatively(): bool
    {
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
    }

    private static function responseCanFinish(): bool
    {
        if (PHP_SAPI === 'cli' || self::finishesNatively() || !headers_sent()) return true;
        // Already streamed (a download): the client knows it has everything only from the Content-Length it was sent.
        foreach (headers_list() as $header) if (stripos($header, 'content-length:') === 0) return true;
        return false;
    }

    /**
     * Releases the visitor before the pass: the session lock (their next click would wait for the whole pass), the
     * session data (the pass acts as the system, so the audit log never names the admin whose click carried it) and
     * the response (a payment gateway waiting for its webhook answer would time out and retry).
     */
    private static function finishResponse(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $_SESSION = [];
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
            return;
        }
        if (PHP_SAPI === 'cli') return;
        if (!headers_sent()) {
            $body = '';
            while (ob_get_level() > 0) {
                $chunk = (string)ob_get_contents();
                if (!@ob_end_clean()) break;
                $body = $chunk . $body;
            }
            @ini_set('zlib.output_compression', '0');
            // Browsers ask for gzip: Apache mod_deflate would then drop the Content-Length and stream the body chunked,
            // so the visitor's page would end only after the pass. no-gzip keeps this one response uncompressed.
            if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
            $status = http_response_code();
            if (ob_get_level() === 0 && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD'
                && !in_array($status, [204, 304], true)) {
                header('Content-Length: ' . strlen($body));
            }
            header('Connection: close');
            echo $body;
        }
        while (ob_get_level() > 0) {
            if (!@ob_end_flush()) break;
        }
        flush();
    }

    private static function runWorker(string $root): void
    {
        if (function_exists('set_time_limit')) @set_time_limit(self::TIME_LIMIT);
        if (!defined('BBE_WEB_WORKER')) define('BBE_WEB_WORKER', true);
        (static function (string $script): void {
            $argv = ['worker.php', '--once'];
            require $script;
        })($root . '/cli/worker.php');
    }
}
