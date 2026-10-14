<?php

final class UpdatePackageBuilder
{
    private const EXCLUDED_PREFIXES = [
        '.git/', '.worktrees/', '.claude/', '.playwright-mcp/',
        'cache/', 'db/', 'extensions/', 'jobs/', 'logs/', 'releases/', 'storage/', 'uploads/',
    ];

    private const EXCLUDED_FILES = [
        '.env', 'config.php', '.barebones-version.json',
    ];

    public static function build(
        string $fromRoot,
        string $toRoot,
        string $outputDirectory,
        string $version,
        ?string $baseVersion = null,
        ?string $secretKey = null
    ): array {
        $from = self::existingDirectory($fromRoot, 'base source');
        $to = self::existingDirectory($toRoot, 'target source');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._+\-]{0,63}$/', $version)) {
            throw new InvalidArgumentException('Invalid target version');
        }
        if ($baseVersion !== null && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._+\-]{0,63}$/', $baseVersion)) {
            throw new InvalidArgumentException('Invalid base version');
        }

        $output = self::absoluteOutputPath($outputDirectory);
        if (file_exists($output) || is_link($output)) {
            throw new RuntimeException("Output path already exists: {$output}");
        }
        self::assertOutsideSource($output, $from);
        self::assertOutsideSource($output, $to);
        if (!mkdir($output, 0750, true) && !is_dir($output)) {
            throw new RuntimeException("Cannot create output directory: {$output}");
        }

        try {
            $realOutput = realpath($output);
            if ($realOutput === false || is_link($output)) {
                throw new RuntimeException('Update output directory cannot be resolved safely');
            }
            $output = rtrim($realOutput, DIRECTORY_SEPARATOR);
            self::assertOutsideSource($output, $from);
            self::assertOutsideSource($output, $to);

            $before = self::scan($from);
            $after = self::scan($to);
            $paths = array_unique(array_merge(array_keys($before), array_keys($after)));
            sort($paths, SORT_STRING);
            $entries = [];
            $runMigrations = false;

            foreach ($paths as $path) {
                $oldHash = $before[$path] ?? null;
                $newHash = $after[$path] ?? null;
                if ($oldHash === $newHash) {
                    continue;
                }
                if ($newHash === null) {
                    $entries[] = [
                        'path' => $path,
                        'action' => 'delete',
                        'previous_sha256' => $oldHash,
                    ];
                } else {
                    $entries[] = [
                        'path' => $path,
                        'action' => 'write',
                        'sha256' => $newHash,
                        'previous_sha256' => $oldHash,
                    ];
                    self::copyPayload($to, $output, $path);
                }
                if (str_starts_with($path, 'migrations/')) {
                    $runMigrations = true;
                }
            }

            $manifest = [
                'format_version' => UpdatePackage::FORMAT_VERSION,
                'version' => $version,
                'base_version' => $baseVersion,
                'created_at' => gmdate('c'),
                'php_min' => '8.1.0',
                'run_migrations' => $runMigrations,
                'files' => $entries,
            ];
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($output . DIRECTORY_SEPARATOR . 'manifest.json', $json, LOCK_EX) === false) {
                throw new RuntimeException('Cannot write update manifest');
            }

            $signed = false;
            if ($secretKey !== null && trim($secretKey) !== '') {
                if (!function_exists('sodium_crypto_sign_detached')) {
                    throw new RuntimeException('Signing requires the PHP sodium extension');
                }
                $key = UpdatePackage::decodeKey(trim($secretKey), SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'update secret key');
                $signature = sodium_crypto_sign_detached($json, $key);
                $encoded = 'base64:' . base64_encode($signature) . "\n";
                if (file_put_contents($output . DIRECTORY_SEPARATOR . 'manifest.sig', $encoded, LOCK_EX) === false) {
                    throw new RuntimeException('Cannot write update signature');
                }
                $signed = true;
            }

            return [
                'directory' => $output,
                'version' => $version,
                'base_version' => $baseVersion,
                'files' => count($entries),
                'run_migrations' => $runMigrations,
                'signed' => $signed,
            ];
        } catch (Throwable $e) {
            self::removeTree($output);
            throw $e;
        }
    }

    private static function scan(string $root): array
    {
        $result = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            $absolute = $file->getPathname();
            $relative = str_replace('\\', '/', substr($absolute, strlen($root) + 1));
            $relative = UpdatePackage::normalizePath($relative);
            if (self::excluded($relative)) {
                continue;
            }
            if ($file->isLink()) {
                throw new RuntimeException("Symlink cannot be packaged: {$relative}");
            }
            if (!$file->isFile()) {
                continue;
            }
            $hash = hash_file('sha256', $absolute);
            if (!is_string($hash)) {
                throw new RuntimeException("Cannot hash source file: {$relative}");
            }
            $result[$relative] = $hash;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    private static function excluded(string $path): bool
    {
        $comparable = strtolower(str_replace('\\', '/', $path));
        if (in_array($comparable, self::EXCLUDED_FILES, true)) {
            return true;
        }
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($comparable, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private static function copyPayload(string $sourceRoot, string $output, string $path): void
    {
        $source = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $target = $output . DIRECTORY_SEPARATOR . 'payload' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0750, true) && !is_dir($parent)) {
            throw new RuntimeException("Cannot create payload directory for {$path}");
        }
        if (!copy($source, $target)) {
            throw new RuntimeException("Cannot copy payload file: {$path}");
        }
        @chmod($target, fileperms($source) & 0777);
    }

    private static function existingDirectory(string $path, string $label): string
    {
        $real = realpath($path);
        if ($real === false || !is_dir($real) || is_link($real)) {
            throw new InvalidArgumentException("{$label} directory not found: {$path}");
        }
        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    private static function absoluteOutputPath(string $path): string
    {
        if ($path === '' || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw new InvalidArgumentException('Output directory is invalid');
        }
        $isAbsolute = str_starts_with($path, '/') || str_starts_with($path, '\\\\')
            || preg_match('~^[A-Za-z]:[\\\\/]~', $path);
        if (!$isAbsolute) {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new RuntimeException('Cannot resolve current directory');
            }
            $path = $cwd . DIRECTORY_SEPARATOR . $path;
        }
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    private static function assertOutsideSource(string $output, string $source): void
    {
        $outputComparable = strtolower(str_replace('\\', '/', $output));
        $sourceComparable = strtolower(rtrim(str_replace('\\', '/', $source), '/'));
        if ($outputComparable === $sourceComparable || str_starts_with($outputComparable . '/', $sourceComparable . '/')) {
            throw new RuntimeException('Update output must be outside both source trees');
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
