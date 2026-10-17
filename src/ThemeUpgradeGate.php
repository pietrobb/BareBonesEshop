<?php

/**
 * Proves, before an update touches the installation, that the installed themes, stores.json and
 * store block overrides still satisfy the NEW core's checkout contract.
 *
 * The candidate is assembled in a private scratch directory exactly as `apply` would leave it:
 * installed code and presentation files, overlaid with the package writes and minus its deletes.
 * The candidate's own probe (cli/tests/ThemeContractProbe.php) then renders checkout with the new
 * presenter against an in-memory database. Secrets, databases, uploads and other protected paths
 * are never copied, so the probe cannot reach production data. Any doubt is a NO-GO.
 */
final class ThemeUpgradeGate
{
    public const PROBE = 'cli/tests/ThemeContractProbe.php';
    private const CANDIDATE_DIRECTORIES = [
        'src/', 'adapters/', 'cli/', 'migrations/', 'lang/', 'forms/', 'templates/', 'themes/', 'blocks/', 'vendor/',
    ];

    private string $scratchDirectory;
    private string $phpBinary;

    public function __construct(string $scratchDirectory, ?string $phpBinary = null)
    {
        $this->scratchDirectory = rtrim($scratchDirectory, '/\\');
        $this->phpBinary = $phpBinary ?? PHP_BINARY;
    }

    /** @return array{status: string, checks: int, targets: array, failures: array} */
    public function evaluate(string $installedRoot, UpdatePackage $package): array
    {
        $root = realpath($installedRoot);
        if ($root === false || !is_dir($root)) {
            return self::noGo("Installed application root not found: {$installedRoot}");
        }
        $candidate = $this->scratchDirectory . DIRECTORY_SEPARATOR . 'theme-candidate-' . bin2hex(random_bytes(6));
        try {
            if (!mkdir($candidate, 0700, true) && !is_dir($candidate)) {
                return self::noGo("Cannot create the private theme candidate directory under {$this->scratchDirectory}");
            }
            $this->copyInstalled(rtrim($root, DIRECTORY_SEPARATOR), $candidate);
            $this->overlayPackage($package, $candidate);
            if (!is_file($candidate . DIRECTORY_SEPARATOR . self::PROBE)) {
                return self::noGo('The updated core would not contain ' . self::PROBE
                    . '; without the new core\'s checkout probe no theme verdict is possible. Use a package built from a release that ships the theme gate.');
            }
            return $this->runProbe($candidate);
        } catch (Throwable $e) {
            return self::noGo('Theme candidate could not be assembled: ' . $e->getMessage());
        } finally {
            self::removeTree($candidate);
        }
    }

    private function copyInstalled(string $root, string $candidate): void
    {
        $relativeOf = static fn(SplFileInfo $item): string => str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
        // Out-of-scope directories (uploads, storage, databases, media) are pruned, never walked.
        $inScope = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            fn(SplFileInfo $item): bool => $this->inCandidateScope($relativeOf($item), $item->isDir())
        );
        foreach (new RecursiveIteratorIterator($inScope, RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $relative = $relativeOf($item);
            if ($item->isLink()) {
                throw new RuntimeException("Installed path {$relative} is a symlink; the candidate cannot reproduce it faithfully");
            }
            if ($item->isFile()) {
                $this->copyFile($item->getPathname(), $candidate, $relative);
            }
        }
    }

    private function inCandidateScope(string $relative, bool $isDirectory): bool
    {
        if (Updater::isProtectedPath($relative) || Updater::isProtectedPath($relative . '/')) {
            return false;
        }
        $comparable = strtolower($relative);
        if (!str_contains($comparable, '/')) {
            if ($isDirectory) {
                return in_array($comparable . '/', self::CANDIDATE_DIRECTORIES, true);
            }
            return str_ends_with($comparable, '.php') || str_ends_with($comparable, '.json');
        }
        foreach (self::CANDIDATE_DIRECTORIES as $directory) {
            if (str_starts_with($comparable, $directory)) {
                return true;
            }
        }
        return false;
    }

    private function overlayPackage(UpdatePackage $package, string $candidate): void
    {
        foreach ($package->entries() as $entry) {
            $path = UpdatePackage::normalizePath((string)$entry['path']);
            if (Updater::isProtectedPath($path)) {
                continue; // check() reports it as a conflict; apply never installs it
            }
            $target = $candidate . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if ($entry['action'] === 'write') {
                $this->copyFile($package->payloadPath($path), $candidate, $path);
            } elseif (is_file($target) && !unlink($target)) {
                throw new RuntimeException("Cannot remove {$path} from the theme candidate");
            }
        }
    }

    private function copyFile(string $source, string $candidate, string $relative): void
    {
        $target = $candidate . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new RuntimeException("Cannot create candidate directory for {$relative}");
        }
        if (!copy($source, $target)) {
            throw new RuntimeException("Cannot copy {$relative} into the theme candidate");
        }
    }

    private function runProbe(string $candidate): array
    {
        $pipes = [];
        $process = proc_open(
            [$this->phpBinary, $candidate . DIRECTORY_SEPARATOR . self::PROBE],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $candidate
        );
        if (!is_resource($process)) {
            return self::noGo('The theme contract probe could not be started');
        }
        fclose($pipes[0]);
        $output = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = (string)stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $report = json_decode($output, true);
        if (!is_array($report) || !isset($report['status'], $report['checks'], $report['failures'])
            || !is_array($report['failures']) || ($report['status'] === 'GO') !== ($exit === 0 && $report['failures'] === [])) {
            return self::noGo('The theme contract probe did not return a verdict (exit ' . $exit . '): '
                . substr(trim($error . ' ' . $output), 0, 2000));
        }
        return [
            'status' => $report['status'] === 'GO' ? 'GO' : 'NO-GO',
            'checks' => (int)$report['checks'],
            'targets' => array_values((array)($report['targets'] ?? [])),
            'failures' => array_values(array_map('strval', $report['failures'])),
        ];
    }

    private static function noGo(string $reason): array
    {
        return ['status' => 'NO-GO', 'checks' => 0, 'targets' => [], 'failures' => [$reason]];
    }

    private static function removeTree(string $path): void
    {
        FileSystem::removeTree($path);
    }
}
