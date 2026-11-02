<?php
/**
 * Background jobs on hosting without a command line or cron (operations.web_worker = true, set by the web
 * installer): after a storefront or admin response, at most once per interval, one `cli/worker.php --once`
 * pass runs in the same PHP process. Without it a paid eBook is never granted and no e-mail leaves the queue.
 */
final class WebWorker
{
    public const DEFAULT_INTERVAL = 60;
    private static bool $scheduled = false;
    private static bool $ran = false;

    public static function enabled(array $config): bool
    {
        return ($config['operations']['web_worker'] ?? false) === true;
    }

    /** Registers the pass as the LAST shutdown function, so session writes and other handlers finish first. */
    public static function afterResponse(): void
    {
        if (self::$scheduled || !self::enabled(Security::config())) return;
        self::$scheduled = true;
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
        if (self::$ran || !self::enabled($config)) return false;
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
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
        ignore_user_abort(true);
        self::runWorker($root);
        return true;
    }

    private static function runWorker(string $root): void
    {
        if (!defined('BBE_WEB_WORKER')) define('BBE_WEB_WORKER', true);
        (static function (string $script): void {
            $argv = ['worker.php', '--once'];
            require $script;
        })($root . '/cli/worker.php');
    }
}
