<?php
/**
 * Core drift guard: a hash manifest of the installed core files, recorded after every
 * update/rollback, and a check that reports any hand edit made directly on the server.
 *
 * Shop-specific code belongs in themes/<shop>, config or extensions — those paths are
 * outside the default scope, so only real core edits are reported.
 */
final class CoreIntegrity
{
    public const MANIFEST_FILE = 'core-manifest.json';
    public const DEFAULT_PATHS = [
        '*.php', '.htaccess', 'src/', 'adapters/', 'cli/', 'migrations/', 'lang/', 'forms/',
        'templates/', 'themes/default/', 'download/.htaccess', 'private_downloads/.htaccess',
    ];
    public const DEFAULT_EXCLUDE = ['config.php', 'templates/legal/'];

    private string $root;
    private string $stateDirectory;
    private array $paths;
    private array $exclude;

    public function __construct(string $root, string $stateDirectory, array $options = [])
    {
        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot)) {
            throw new InvalidArgumentException("Application root not found: {$root}");
        }
        $this->root = rtrim(str_replace('\\', '/', $realRoot), '/');
        $this->stateDirectory = rtrim($stateDirectory, '/\\');
        $this->paths = array_values(array_filter((array)($options['paths'] ?? self::DEFAULT_PATHS), 'is_string'));
        $this->exclude = array_values(array_filter(array_merge(self::DEFAULT_EXCLUDE, (array)($options['exclude'] ?? [])), 'is_string'));
    }

    /** Hash the current core files and make them the accepted baseline. */
    public function record(string $reason, ?string $version = null): array
    {
        $manifest = [
            'version' => $version,
            'reason' => $reason,
            'recorded_at' => gmdate('c'),
            'paths' => $this->paths,
            'exclude' => $this->exclude,
            'files' => $this->hashes(),
        ];
        if (!is_dir($this->stateDirectory) && !mkdir($this->stateDirectory, 0750, true) && !is_dir($this->stateDirectory)) {
            throw new RuntimeException('Cannot create drift guard state directory');
        }
        $target = $this->manifestPath();
        $temporary = $target . '.tmp' . bin2hex(random_bytes(4));
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false || !rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot write core manifest');
        }
        return ['files' => count($manifest['files']), 'manifest' => $target];
    }

    /** @return array{status:string, modified:list<string>, added:list<string>, missing:list<string>, recorded_at:?string, version:?string} */
    public function check(): array
    {
        $raw = is_file($this->manifestPath()) ? file_get_contents($this->manifestPath()) : false;
        $manifest = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
            return ['status' => 'no_manifest', 'modified' => [], 'added' => [], 'missing' => [], 'recorded_at' => null, 'version' => null];
        }
        $expected = $manifest['files'];
        $actual = $this->hashes();
        $modified = $added = $missing = [];
        foreach ($actual as $path => $hash) {
            if (!array_key_exists($path, $expected)) $added[] = $path;
            elseif (!hash_equals((string)$expected[$path], $hash)) $modified[] = $path;
        }
        foreach (array_keys($expected) as $path) {
            if (!array_key_exists($path, $actual)) $missing[] = (string)$path;
        }
        return [
            'status' => ($modified || $added || $missing) ? 'drift' : 'clean',
            'modified' => $modified,
            'added' => $added,
            'missing' => $missing,
            'recorded_at' => $manifest['recorded_at'] ?? null,
            'version' => $manifest['version'] ?? null,
        ];
    }

    public function manifestPath(): string
    {
        return $this->stateDirectory . '/' . self::MANIFEST_FILE;
    }

    /** @return array<string,string> relative path => sha256, sorted */
    private function hashes(): array
    {
        $files = [];
        foreach ($this->paths as $pattern) {
            $pattern = ltrim(str_replace('\\', '/', $pattern), '/');
            if (str_ends_with($pattern, '/')) {
                $directory = $this->root . '/' . rtrim($pattern, '/');
                if (!is_dir($directory) || is_link($directory)) continue;
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->isFile() && !$file->isLink()) $this->add($files, $file->getPathname());
                }
                continue;
            }
            foreach (glob($this->root . '/' . $pattern, GLOB_NOSORT) ?: [] as $path) {
                if (is_file($path) && !is_link($path)) $this->add($files, $path);
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    private function add(array &$files, string $absolute): void
    {
        $relative = ltrim(substr(str_replace('\\', '/', $absolute), strlen($this->root)), '/');
        if ($relative === '' || Updater::isProtectedPath($relative)) return;
        foreach ($this->exclude as $excluded) {
            $excluded = ltrim(str_replace('\\', '/', $excluded), '/');
            if ($relative === $excluded || (str_ends_with($excluded, '/') && str_starts_with($relative, $excluded))) return;
        }
        $hash = hash_file('sha256', $absolute);
        if ($hash !== false) $files[$relative] = $hash;
    }
}
