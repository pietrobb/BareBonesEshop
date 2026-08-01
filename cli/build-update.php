<?php
require_once __DIR__ . '/cli-only.php';

/**
 * Build a deterministic delta update directory from two release trees.
 *
 * Usage:
 *   php cli/build-update.php --from=/release/1.0 --to=/release/1.1 \
 *       --base-version=1.0.0 --version=1.1.0 --output=/releases/1.1.0 \
 *       --secret-key=/secure/update-secret.key
 */
require_once __DIR__ . '/../src/autoload.php';

$rawArguments = array_slice($argv ?? [], 1);
$keygen = ($rawArguments[0] ?? null) === 'keygen';
if ($keygen) {
    array_shift($rawArguments);
}
$options = [];
foreach ($rawArguments as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        buildUpdateHelp();
        exit(0);
    }
    if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
    [$name, $value] = explode('=', substr($argument, 2), 2);
    $options[$name] = $value;
}

if ($keygen) {
    try {
        generateUpdateKeypair((string)($options['public'] ?? ''), (string)($options['secret'] ?? ''));
        echo "Ed25519 update keypair created. Keep the secret key offline.\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

foreach (['from', 'to', 'version', 'output'] as $required) {
    if (!isset($options[$required]) || $options[$required] === '') {
        fwrite(STDERR, "Missing --{$required}\n");
        buildUpdateHelp();
        exit(2);
    }
}

$secretKey = isset($options['secret-key'])
    ? (string)$options['secret-key']
    : (string)(getenv('BAREBONES_UPDATE_SECRET_KEY') ?: '');

try {
    $result = UpdatePackageBuilder::build(
        (string)$options['from'],
        (string)$options['to'],
        (string)$options['output'],
        (string)$options['version'],
        isset($options['base-version']) ? (string)$options['base-version'] : null,
        $secretKey !== '' ? $secretKey : null
    );
    echo "Package created: {$result['directory']}\n";
    echo "Version: {$result['version']} (base " . ($result['base_version'] ?? 'unversioned') . ")\n";
    echo "Changed paths: {$result['files']}\n";
    echo 'Migrations: ' . ($result['run_migrations'] ? 'yes' : 'no') . "\n";
    echo 'Signature: ' . ($result['signed'] ? 'Ed25519' : 'UNSIGNED — apply requires --allow-unsigned') . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

function generateUpdateKeypair(string $publicPath, string $secretPath): void
{
    if ($publicPath === '' || $secretPath === '' || $publicPath === $secretPath) {
        throw new InvalidArgumentException('keygen requires distinct --public=FILE and --secret=FILE paths');
    }
    if (!function_exists('sodium_crypto_sign_keypair')) {
        throw new RuntimeException('Key generation requires the PHP sodium extension');
    }
    if (file_exists($publicPath) || is_link($publicPath) || file_exists($secretPath) || is_link($secretPath)) {
        throw new RuntimeException('Key generation refuses to overwrite an existing path');
    }
    foreach ([dirname($publicPath), dirname($secretPath)] as $parent) {
        if (!is_dir($parent) || !is_writable($parent)) {
            throw new RuntimeException("Key directory must already exist and be writable: {$parent}");
        }
    }

    $pair = sodium_crypto_sign_keypair();
    $public = 'base64:' . base64_encode(sodium_crypto_sign_publickey($pair)) . "\n";
    $secret = 'base64:' . base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n";
    $publicHandle = @fopen($publicPath, 'x');
    if (!is_resource($publicHandle)) {
        throw new RuntimeException('Cannot create public key file');
    }
    try {
        if (fwrite($publicHandle, $public) !== strlen($public)) {
            throw new RuntimeException('Cannot write public key file');
        }
    } catch (Throwable $e) {
        fclose($publicHandle);
        @unlink($publicPath);
        throw $e;
    }
    fclose($publicHandle);
    @chmod($publicPath, 0644);

    $secretHandle = @fopen($secretPath, 'x');
    if (!is_resource($secretHandle)) {
        @unlink($publicPath);
        throw new RuntimeException('Cannot create secret key file');
    }
    try {
        if (fwrite($secretHandle, $secret) !== strlen($secret)) {
            throw new RuntimeException('Cannot write secret key file');
        }
    } catch (Throwable $e) {
        fclose($secretHandle);
        @unlink($secretPath);
        @unlink($publicPath);
        throw $e;
    }
    fclose($secretHandle);
    @chmod($secretPath, 0600);
}

function buildUpdateHelp(): void
{
    echo "Usage:\n";
    echo "  php cli/build-update.php --from=DIR --to=DIR --version=VERSION --output=DIR [options]\n";
    echo "  php cli/build-update.php keygen --public=FILE --secret=FILE\n";
    echo "Options:\n";
    echo "  --base-version=VERSION  Required installed version for this delta\n";
    echo "  --secret-key=KEY        Ed25519 secret key as base64, hex, or a file path\n";
    echo "                          (or BAREBONES_UPDATE_SECRET_KEY environment variable)\n";
}
