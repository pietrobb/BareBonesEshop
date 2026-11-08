<?php
declare(strict_types=1);

/**
 * R23 UX: the e-mail delivery settings the owner saves in Admin → E-mail delivery or in the web installer (before,
 * SMTP could only come from environment variables, which most shared hosting cannot set).
 *
 * Stored in mail.json in the private data directory (DataOverrides::dir(), mode 0600). The SMTP password is
 * encrypted there with a key derived from admin_identity_key and never shown again: the form only replaces it.
 * An environment variable always wins over the saved value (SMTP_HOST, SMTP_PASS, MAIL_FROM, MAIL_ADAPTER …), and
 * the saved value wins over the smtp_* keys of config.php.
 */
final class MailSettings
{
    public const FILE = 'mail.json';
    /** Saved field => the environment variable that overrides it. */
    public const ENV = [
        'host' => 'SMTP_HOST', 'port' => 'SMTP_PORT', 'encryption' => 'SMTP_ENCRYPTION',
        'username' => 'SMTP_USER', 'password' => 'SMTP_PASS', 'from' => 'MAIL_FROM',
    ];
    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];
    public const MODES = ['smtp', 'log'];

    public static function path(?array $config = null): ?string
    {
        $dir = DataOverrides::dir($config);
        return $dir === null ? null : $dir . '/' . self::FILE;
    }

    /** The saved file as stored (password still encrypted); [] when nothing was saved. */
    public static function raw(?array $config = null): array
    {
        $path = self::path($config);
        if ($path === null || !is_file($path)) return [];
        $data = json_decode((string)@file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /** The saved mode: 'smtp', 'log', or null when the owner never chose one here. */
    public static function mode(?array $config = null): ?string
    {
        $mode = (string)(self::raw($config)['mode'] ?? '');
        return in_array($mode, self::MODES, true) ? $mode : null;
    }

    /** The mail provider in use: MAIL_ADAPTER, else the saved mode, else config provider_defaults.mail, else log. */
    public static function providerCode(?array $config = null): string
    {
        $environment = strtolower(trim((string)(getenv('MAIL_ADAPTER') ?: '')));
        if ($environment !== '') return $environment;
        $mode = self::mode($config);
        if ($mode !== null) return $mode;
        $config ??= Security::config();
        return strtolower((string)($config['provider_defaults']['mail'] ?? '')) ?: 'log';
    }

    /**
     * The saved SMTP values with the password decrypted, keyed like SmtpMail's config (smtp_host, smtp_pass …), only
     * the fields that were filled in. A password the current admin_identity_key cannot open is left out.
     * @return array<string, string|int>
     */
    public static function smtpConfig(?array $config = null): array
    {
        $raw = self::raw($config);
        $out = [];
        foreach (['host' => 'smtp_host', 'port' => 'smtp_port', 'encryption' => 'smtp_encryption', 'username' => 'smtp_user'] as $field => $key) {
            if (isset($raw[$field]) && (string)$raw[$field] !== '') $out[$key] = $field === 'port' ? (int)$raw[$field] : (string)$raw[$field];
        }
        $password = self::password($raw, $config);
        if ($password !== null && $password !== '') $out['smtp_pass'] = $password;
        return $out;
    }

    /** The saved sender address, or '' when none was saved. */
    public static function from(?array $config = null): string
    {
        $from = strtolower(trim((string)(self::raw($config)['from'] ?? '')));
        return filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : '';
    }

    /** True when a password is saved but this admin_identity_key cannot decrypt it (config.php regenerated). */
    public static function passwordUnreadable(?array $config = null): bool
    {
        $raw = self::raw($config);
        return (string)($raw['password'] ?? '') !== '' && self::password($raw, $config) === null;
    }

    /** @return array<string, string> saved field => environment variable that currently overrides it */
    public static function environmentOverrides(): array
    {
        $out = [];
        foreach (self::ENV as $field => $name) {
            if (getenv($name) !== false && getenv($name) !== '') $out[$field] = $name;
        }
        return $out;
    }

    /**
     * Checks a form (admin or installer). An empty password keeps the saved one unless clear_password is ticked.
     * @return array{0: array<string, mixed>, 1: array<string, string>} [values to save, field => error key]
     */
    public static function validate(array $input): array
    {
        $text = static fn(string $key): string => trim((string)(is_scalar($input[$key] ?? null) ? $input[$key] : ''));
        $v = [
            'mode' => $text('mode') === 'log' ? 'log' : 'smtp',
            'host' => strtolower($text('host')),
            'port' => $text('port'),
            'encryption' => strtolower($text('encryption')) ?: 'tls',
            'username' => $text('username'),
            'password' => (string)(is_scalar($input['password'] ?? null) ? $input['password'] : ''),
            'clear_password' => !empty($input['clear_password']),
            'from' => strtolower($text('from')),
        ];
        $errors = [];
        if ($v['mode'] === 'smtp' || $v['host'] !== '') {
            $hostOk = filter_var($v['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false && str_contains($v['host'], '.')
                || filter_var($v['host'], FILTER_VALIDATE_IP) !== false || $v['host'] === 'localhost';
            if (!$hostOk || strlen($v['host']) > 253) $errors['host'] = 'host';
        }
        if ($v['port'] === '') $v['port'] = match ($v['encryption']) { 'ssl' => '465', 'none' => '25', default => '587' };
        if (!ctype_digit($v['port']) || (int)$v['port'] < 1 || (int)$v['port'] > 65535) $errors['port'] = 'port';
        $v['port'] = (int)$v['port'];
        if (!in_array($v['encryption'], self::ENCRYPTIONS, true)) $errors['encryption'] = 'encryption';
        if (strlen($v['username']) > 254 || preg_match('/[\x00-\x1F\x7F]/', $v['username'])) $errors['username'] = 'username';
        if (strlen($v['password']) > 1024 || preg_match('/[\r\n\x00]/', $v['password'])) $errors['password'] = 'password';
        // Credentials never cross the network in clear text (SmtpMail refuses AUTH without TLS as well).
        if ($v['mode'] === 'smtp' && $v['username'] !== '' && $v['encryption'] === 'none') $errors['encryption'] = 'plaintext_auth';
        if ($v['from'] !== '' && (!filter_var($v['from'], FILTER_VALIDATE_EMAIL) || preg_match(MailService::RESERVED_DOMAIN, (string)substr(strrchr($v['from'], '@') ?: '', 1)))) {
            $errors['from'] = 'from';
        }
        return [$v, $errors];
    }

    /**
     * Saves validated values. The password is encrypted (a new one, or the saved one kept as it is). Returns null, or
     * the error key when the file cannot be written or no admin_identity_key is configured to encrypt a password with.
     */
    public static function save(array $v, ?array $config = null, string $actor = ''): ?string
    {
        $path = self::path($config);
        if ($path === null) return 'no_data_dir';
        $old = self::raw($config);
        $encrypted = (string)($old['password'] ?? '');
        if ($v['clear_password']) $encrypted = '';
        if ($v['password'] !== '') {
            $key = self::key($config);
            if ($key === null) return 'no_key';
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $encrypted = 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($v['password'], $nonce, $key));
        }
        $data = [
            'mode' => $v['mode'], 'host' => $v['host'], 'port' => $v['port'], 'encryption' => $v['encryption'],
            'username' => $v['username'], 'password' => $encrypted, 'from' => $v['from'],
            'updated_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => $actor,
        ];
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return 'no_data_dir';
        if (!is_file($path)) {
            // Created 0600 before the first byte is written: the encrypted password is never world-readable either.
            if (@touch($path) === false) return 'write';
            @chmod($path, 0600);
        }
        if (!DataOverrides::atomicWrite($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n")) return 'write';
        @chmod($path, 0600);
        if (class_exists('AuditLog') && Db::tableExists('audit_log')) {
            $public = static fn(array $d): array => ['password' => (string)($d['password'] ?? '') !== '' ? 'set' : ''] + array_diff_key($d, ['password' => 1, 'updated_at' => 1, 'updated_by' => 1]);
            AuditLog::log('mail_settings', 0, $old === [] ? null : $public($old), $public($data), 'E-mail delivery settings changed');
        }
        return null;
    }

    private static function password(array $raw, ?array $config): ?string
    {
        $encoded = (string)($raw['password'] ?? '');
        if ($encoded === '') return '';
        $key = self::key($config);
        if ($key === null || !str_starts_with($encoded, 'v1:')) return null;
        $bytes = base64_decode(substr($encoded, 3), true);
        if ($bytes === false || strlen($bytes) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
        $plain = sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
        return $plain === false ? null : $plain;
    }

    /** A key of its own, derived from the deployment secret admin_identity_key (BBE_ADMIN_IDENTITY_KEY wins), or null. */
    private static function key(?array $config): ?string
    {
        if (!function_exists('sodium_crypto_secretbox')) return null;
        $config ??= Security::config();
        $value = trim((string)(getenv('BBE_ADMIN_IDENTITY_KEY') ?: ($config['admin_identity_key'] ?? '')));
        $secret = preg_match('/^[a-f0-9]{64}$/i', $value) ? hex2bin($value) : base64_decode($value, true);
        if (!is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) return null;
        return hash_hmac('sha256', 'bareboneseshop mail settings v1', $secret, true);
    }
}
