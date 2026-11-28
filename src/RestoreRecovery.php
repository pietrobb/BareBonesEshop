<?php
declare(strict_types=1);

/**
 * R62 (review round 27, findings 9 and 11): what restores set aside in the private recovery directory, for the code that
 * must not act as if it were not there (GdprService::salt()) and for the doctor.
 */
final class RestoreRecovery
{
    /** One-file sources a restore sets aside where the live shop lacks them (BackupService::defaultFileSources()). */
    public const FILE_SOURCES = ['gdpr_salt', 'stores', 'shipping', 'mail'];

    /** backup_recovery_dir, else <private_runtime_dir>/restore-recovery, as BackupService::init() uses it. */
    public static function directory(array $appCfg, string $rootDir): string
    {
        $dir = (string)($appCfg['backup_recovery_dir'] ?? '');
        if ($dir !== '') return $dir;
        $private = (string)($appCfg['private_runtime_dir'] ?? '') ?: dirname($rootDir) . '/bareboneseshop-private';
        return rtrim($private, '/\\') . '/restore-recovery';
    }

    /**
     * The copies of a one-file source (by the live file's name: gdpr_salt, stores.json, ...) the restores set aside in
     * <recovery>/from-backup/<time>-<random>/, newest first. Only real directories and regular files, never through a link.
     * @return list<string>
     */
    public static function asideCopies(string $recoveryDir, string $basename): array
    {
        if ($recoveryDir === '' || $basename === '') return [];
        $root = rtrim(str_replace('\\', '/', $recoveryDir), '/') . '/from-backup';
        if (is_link($root) || !is_dir($root)) return [];
        $copies = [];
        foreach (@scandir($root) ?: [] as $entry) {
            if (preg_match('/^\d{8}-\d{6}-[0-9a-f]{12}$/D', $entry) !== 1) continue;
            $run = $root . '/' . $entry;
            $copy = $run . '/' . $basename;
            if (is_link($run) || !is_dir($run) || is_link($copy) || !is_file($copy)) continue;
            $copies[$entry] = $copy;
        }
        krsort($copies, SORT_STRING);
        return array_values($copies);
    }

    /**
     * The one-file sources the live shop lacks while a restore set the backup's copy aside (a restore onto a new server):
     * name => ['copy' => the newest copy, 'live' => where the shop reads it].
     * @param array<string, string> $sources BackupService::defaultFileSources()
     * @return array<string, array{copy: string, live: string}>
     */
    public static function missingLive(string $recoveryDir, array $sources): array
    {
        $missing = [];
        foreach (self::FILE_SOURCES as $name) {
            $live = (string)($sources[$name] ?? '');
            if ($live === '' || file_exists($live) || is_link($live)) continue;
            $copy = self::asideCopies($recoveryDir, basename($live))[0] ?? null;
            if ($copy !== null) $missing[$name] = ['copy' => $copy, 'live' => $live];
        }
        return $missing;
    }
}
