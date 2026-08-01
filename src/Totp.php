<?php
/** RFC 6238 TOTP without external dependencies. */
class Totp {
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string {
        if ($bytes < 16 || $bytes > 64) throw new InvalidArgumentException('Invalid TOTP secret size');
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $secret, ?int $timestamp = null, int $digits = 6, int $period = 30): string {
        if ($digits < 6 || $digits > 8 || $period < 15 || $period > 120) {
            throw new InvalidArgumentException('Invalid TOTP parameters');
        }
        $key = self::base32Decode($secret);
        $counter = intdiv($timestamp ?? time(), $period);
        $binaryCounter = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string)($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $candidate, int $window = 1, ?int $timestamp = null): bool {
        $candidate = preg_replace('/\s+/', '', $candidate) ?? '';
        if (!preg_match('/^[0-9]{6}$/', $candidate) || $window < 0 || $window > 5) return false;
        $timestamp ??= time();
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::code($secret, $timestamp + ($offset * 30)), $candidate)) return true;
        }
        return false;
    }

    public static function provisioningUri(string $secret, string $account, string $issuer = 'BareBonesShop'): string {
        $label = rawurlencode($issuer . ':' . $account);
        return 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    private static function base32Encode(string $bytes): string {
        $bits = '';
        foreach (str_split($bytes) as $byte) $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $encoded;
    }

    private static function base32Decode(string $encoded): string {
        $encoded = strtoupper(preg_replace('/[\s=-]+/', '', $encoded) ?? '');
        if ($encoded === '' || preg_match('/[^A-Z2-7]/', $encoded)) throw new InvalidArgumentException('Invalid TOTP secret');
        $bits = '';
        foreach (str_split($encoded) as $char) {
            $position = strpos(self::ALPHABET, $char);
            if ($position === false) throw new InvalidArgumentException('Invalid TOTP secret');
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) $decoded .= chr(bindec($chunk));
        }
        if (strlen($decoded) < 10) throw new InvalidArgumentException('Invalid TOTP secret');
        return $decoded;
    }
}
