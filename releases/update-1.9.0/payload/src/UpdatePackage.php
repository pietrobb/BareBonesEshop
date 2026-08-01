<?php

final class UpdatePackage
{
    public const FORMAT_VERSION = 1;

    private string $directory;
    private string $manifestRaw;
    private array $manifest;

    public function __construct(string $directory, ?string $publicKey = null, bool $allowUnsigned = false)
    {
        $real = realpath($directory);
        if ($real === false || !is_dir($real) || is_link($real)) {
            throw new InvalidArgumentException("Update package directory not found: {$directory}");
        }
        $this->directory = rtrim($real, DIRECTORY_SEPARATOR);

        $manifestPath = $this->directory . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!is_file($manifestPath) || is_link($manifestPath)) {
            throw new RuntimeException('Update package must contain a regular manifest.json');
        }
        $size = filesize($manifestPath);
        if ($size === false || $size < 2 || $size > 4 * 1024 * 1024) {
            throw new RuntimeException('Update manifest has an invalid size');
        }
        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            throw new RuntimeException('Cannot read update manifest');
        }
        $this->manifestRaw = $raw;
        try {
            $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Invalid update manifest JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($manifest)) {
            throw new RuntimeException('Update manifest must be a JSON object');
        }
        $this->manifest = $manifest;

        $this->validateManifest();
        $this->verifySignature($publicKey, $allowUnsigned);
        $this->verifyPayloads();
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function manifest(): array
    {
        return $this->manifest;
    }

    public function version(): string
    {
        return (string)$this->manifest['version'];
    }

    public function baseVersion(): ?string
    {
        $value = $this->manifest['base_version'] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function runsMigrations(): bool
    {
        return !empty($this->manifest['run_migrations']);
    }

    public function entries(): array
    {
        return $this->manifest['files'];
    }

    public function payloadPath(string $relativePath): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'payload' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    public static function normalizePath(string $path): string
    {
        if ($path === '' || strlen($path) > 1024 || str_contains($path, '\\')
            || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)
            || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw new RuntimeException("Unsafe update path: {$path}");
        }
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException("Unsafe update path: {$path}");
            }
        }
        return implode('/', $parts);
    }

    public static function decodeKey(string $value, int $expectedBytes, string $label): string
    {
        if (is_file($value) && !is_link($value)) {
            $fileValue = file_get_contents($value);
            if ($fileValue === false) {
                throw new RuntimeException("Cannot read {$label} file");
            }
            $value = trim($fileValue);
        }
        if (str_starts_with($value, 'base64:')) {
            $decoded = base64_decode(substr($value, 7), true);
        } elseif (preg_match('/^[0-9a-f]+$/i', $value) && strlen($value) === $expectedBytes * 2) {
            $decoded = hex2bin($value);
        } else {
            $decoded = base64_decode($value, true);
        }
        if (!is_string($decoded) || strlen($decoded) !== $expectedBytes) {
            throw new RuntimeException("{$label} must encode exactly {$expectedBytes} bytes");
        }
        return $decoded;
    }

    private function validateManifest(): void
    {
        if (($this->manifest['format_version'] ?? null) !== self::FORMAT_VERSION) {
            throw new RuntimeException('Unsupported update package format');
        }
        foreach (['version', 'files'] as $required) {
            if (!array_key_exists($required, $this->manifest)) {
                throw new RuntimeException("Update manifest is missing {$required}");
            }
        }
        foreach (['version', 'base_version'] as $field) {
            $value = $this->manifest[$field] ?? null;
            if ($value !== null && (!is_string($value) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._+\-]{0,63}$/', $value))) {
                throw new RuntimeException("Invalid {$field} in update manifest");
            }
        }
        if (!is_array($this->manifest['files']) || count($this->manifest['files']) > 10000) {
            throw new RuntimeException('Update manifest files must be an array with at most 10000 entries');
        }
        if (isset($this->manifest['php_min'])
            && (!is_string($this->manifest['php_min']) || !preg_match('/^\d+\.\d+(?:\.\d+)?$/', $this->manifest['php_min']))) {
            throw new RuntimeException('Invalid php_min in update manifest');
        }
        if (isset($this->manifest['php_min']) && version_compare(PHP_VERSION, $this->manifest['php_min'], '<')) {
            throw new RuntimeException("Update requires PHP {$this->manifest['php_min']} or newer; running " . PHP_VERSION);
        }

        $seen = [];
        foreach ($this->manifest['files'] as $index => &$entry) {
            if (!is_array($entry) || !isset($entry['path'], $entry['action'])
                || !is_string($entry['path']) || !is_string($entry['action'])) {
                throw new RuntimeException("Invalid update entry at index {$index}");
            }
            $entry['path'] = self::normalizePath($entry['path']);
            if (isset($seen[$entry['path']])) {
                throw new RuntimeException("Duplicate update path: {$entry['path']}");
            }
            $seen[$entry['path']] = true;
            if (!in_array($entry['action'], ['write', 'delete'], true)) {
                throw new RuntimeException("Invalid action for {$entry['path']}");
            }
            $previous = $entry['previous_sha256'] ?? null;
            if ($previous !== null && (!is_string($previous) || !preg_match('/^[0-9a-f]{64}$/', $previous))) {
                throw new RuntimeException("Invalid previous_sha256 for {$entry['path']}");
            }
            if ($entry['action'] === 'write') {
                if (!isset($entry['sha256']) || !is_string($entry['sha256'])
                    || !preg_match('/^[0-9a-f]{64}$/', $entry['sha256'])) {
                    throw new RuntimeException("Invalid sha256 for {$entry['path']}");
                }
            } elseif ($previous === null) {
                throw new RuntimeException("Delete entry requires previous_sha256 for {$entry['path']}");
            }
        }
        unset($entry);
    }

    private function verifySignature(?string $publicKey, bool $allowUnsigned): void
    {
        $signaturePath = $this->directory . DIRECTORY_SEPARATOR . 'manifest.sig';
        $hasSignature = is_file($signaturePath) && !is_link($signaturePath);
        if (!$hasSignature) {
            if (!$allowUnsigned) {
                throw new RuntimeException('Unsigned update package refused; configure update_public_key or use --allow-unsigned explicitly');
            }
            return;
        }
        if ($publicKey === null || trim($publicKey) === '') {
            throw new RuntimeException('Signed update package requires an Ed25519 public key');
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('Ed25519 verification requires the PHP sodium extension');
        }
        $encodedSignature = trim((string)file_get_contents($signaturePath));
        $signature = self::decodeKey($encodedSignature, SODIUM_CRYPTO_SIGN_BYTES, 'update signature');
        $key = self::decodeKey(trim($publicKey), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'update public key');
        if (!sodium_crypto_sign_verify_detached($signature, $this->manifestRaw, $key)) {
            throw new RuntimeException('Update package signature verification failed');
        }
    }

    private function verifyPayloads(): void
    {
        $payloadRoot = $this->directory . DIRECTORY_SEPARATOR . 'payload';
        $writeEntries = array_filter($this->entries(), static fn(array $entry): bool => $entry['action'] === 'write');
        if ($writeEntries && (!is_dir($payloadRoot) || is_link($payloadRoot))) {
            throw new RuntimeException('Update payload must be a regular directory');
        }
        $realPayloadRoot = $writeEntries ? realpath($payloadRoot) : false;
        foreach ($writeEntries as $entry) {
            $path = $this->payloadPath($entry['path']);
            if (!is_file($path) || is_link($path)) {
                throw new RuntimeException("Missing regular payload file: {$entry['path']}");
            }
            $this->assertNoSymlinkComponents($payloadRoot, $entry['path']);
            $realPath = realpath($path);
            if ($realPath === false || $realPayloadRoot === false || !$this->isInside($realPath, $realPayloadRoot)) {
                throw new RuntimeException("Payload escapes package directory: {$entry['path']}");
            }
            $actual = hash_file('sha256', $path);
            if (!is_string($actual) || !hash_equals($entry['sha256'], $actual)) {
                throw new RuntimeException("Payload checksum mismatch: {$entry['path']}");
            }
        }
    }

    private function assertNoSymlinkComponents(string $root, string $relativePath): void
    {
        $current = rtrim($root, DIRECTORY_SEPARATOR);
        foreach (explode('/', $relativePath) as $part) {
            $current .= DIRECTORY_SEPARATOR . $part;
            if (is_link($current)) {
                throw new RuntimeException("Symlink is not allowed in update payload: {$relativePath}");
            }
        }
    }

    private function isInside(string $candidate, string $parent): bool
    {
        $candidate = strtolower(rtrim(str_replace('\\', '/', $candidate), '/'));
        $parent = strtolower(rtrim(str_replace('\\', '/', $parent), '/'));
        return $candidate === $parent || str_starts_with($candidate . '/', $parent . '/');
    }
}
