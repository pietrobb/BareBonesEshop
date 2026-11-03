<?php
declare(strict_types=1);

/**
 * Rotation and revocation of the Ed25519 update signing key (review round 13, T6).
 *
 * Anchor: the new public key is accepted only with an endorsement — the rotation statement for the new key signed
 * by the secret key the shop trusts now (php cli/build-update.php endorse). That chain does not depend on the
 * repository or the release notes, which whoever controls the publishing account could change together with the key.
 * Revocation: once a shop rotates, the old key is revoked in update_storage_dir/revoked-update-keys.json; keys known
 * lost or compromised ship in SHIPPED_REVOKED. A package signed with a revoked key is refused even while that key is
 * still configured.
 */
final class UpdateKeyRotation
{
    /** R14 #4: v2 names the old key too, so an endorsement only ever replaces the key that signed it. */
    private const STATEMENT = "barebones-update-key-rotation-v2\n";
    public const LOCAL_FILE = 'revoked-update-keys.json';
    /** Keys lost or compromised, by fingerprint; part of the signed code because packages never ship releases/. */
    public const SHIPPED_REVOKED = [
        'da5d3491d22a87d7f4fb90069d628f44dc46e780b8942cfafa12778c3784f3ff' => 'v1.9.0 key (base64:l1tEO6ag…); secret key lost, replaced in v2.1.0',
    ];

    /** SHA-256 (hex) of the raw 32-byte public key: what release notes and the revocation lists name. */
    public static function fingerprint(string $publicKey): string
    {
        return hash('sha256', self::raw($publicKey));
    }

    /** The bytes the old secret key signs to endorse replacing $oldPublicKey with $newPublicKey. */
    public static function statement(string $oldPublicKey, string $newPublicKey): string
    {
        return self::STATEMENT . 'old ' . self::fingerprint($oldPublicKey) . "\nnew " . base64_encode(self::raw($newPublicKey)) . "\n";
    }

    /** Endorsement (base64 detached signature) of $newPublicKey by the current secret key. */
    public static function endorse(string $currentSecretKey, string $newPublicKey): string
    {
        self::requireSodium();
        $secret = UpdatePackage::decodeKey(trim($currentSecretKey), SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'update secret key');
        $currentPublic = 'base64:' . base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
        return 'base64:' . base64_encode(sodium_crypto_sign_detached(self::statement($currentPublic, $newPublicKey), $secret));
    }

    /** True when $endorsement is $trustedPublicKey's signature of the statement replacing it with $newPublicKey. */
    public static function verifyEndorsement(string $trustedPublicKey, string $newPublicKey, string $endorsement): bool
    {
        self::requireSodium();
        $signature = UpdatePackage::decodeKey(trim($endorsement), SODIUM_CRYPTO_SIGN_BYTES, 'key endorsement');
        return sodium_crypto_sign_verify_detached($signature, self::statement($trustedPublicKey, $newPublicKey), self::raw($trustedPublicKey));
    }

    /**
     * Accept $newPublicKey in place of $trustedPublicKey: the endorsement must verify with the trusted key, neither
     * key may be revoked, and the trusted key is revoked locally from now on. The caller installs the new key.
     * @return array{old: string, new: string} fingerprints
     */
    public static function rotate(string $trustedPublicKey, string $newPublicKey, string $endorsement, string $stateDirectory): array
    {
        self::assertNotRevoked($trustedPublicKey, $stateDirectory);
        self::assertNotRevoked($newPublicKey, $stateDirectory);
        $old = self::fingerprint($trustedPublicKey);
        $new = self::fingerprint($newPublicKey);
        if ($old === $new) throw new RuntimeException('The new update key is the key already trusted');
        if (!self::verifyEndorsement($trustedPublicKey, $newPublicKey, $endorsement)) {
            throw new RuntimeException('The new update key is not endorsed by the key this shop trusts; refusing the rotation');
        }
        self::revokeLocally($old, $stateDirectory);
        return ['old' => $old, 'new' => $new];
    }

    /** @return list<string> fingerprints revoked by this release and by this shop */
    public static function revoked(?string $stateDirectory): array
    {
        $revoked = array_keys(self::SHIPPED_REVOKED);
        if ($stateDirectory !== null) {
            $local = json_decode((string)@file_get_contents(rtrim($stateDirectory, '/\\') . '/' . self::LOCAL_FILE), true);
            foreach ((array)($local['revoked'] ?? []) as $row) {
                $fingerprint = (string)(is_array($row) ? ($row['fingerprint'] ?? '') : $row);
                if (preg_match('/^[0-9a-f]{64}$/', $fingerprint)) $revoked[] = $fingerprint;
            }
        }
        return array_values(array_unique($revoked));
    }

    public static function isRevoked(string $publicKey, ?string $stateDirectory): bool
    {
        return in_array(self::fingerprint($publicKey), self::revoked($stateDirectory), true);
    }

    public static function assertNotRevoked(string $publicKey, ?string $stateDirectory): void
    {
        if (self::isRevoked($publicKey, $stateDirectory)) {
            throw new RuntimeException('Update public key ' . self::fingerprint($publicKey) . ' is revoked; rotate to the current key (php cli/update.php rotate-key)');
        }
    }

    /** "base64:<key>", the form keygen writes and update_public_key accepts. */
    public static function encoded(string $publicKey): string
    {
        return 'base64:' . base64_encode(self::raw($publicKey));
    }

    /**
     * Replace the key file update_public_key points to with $newPublicKey; null when it holds the key inline (or is
     * unset), and the operator has to edit config.php.
     */
    public static function installKey(string $configuredValue, string $newPublicKey): ?string
    {
        $path = trim($configuredValue);
        if ($path === '' || str_starts_with($path, 'base64:') || str_contains($path, '://') || !is_file($path) || is_link($path)) {
            return null;
        }
        if (!DataOverrides::atomicWrite($path, self::encoded($newPublicKey) . "\n")) {
            throw new RuntimeException("Cannot write the new update public key to {$path}");
        }
        return $path;
    }

    private static function revokeLocally(string $fingerprint, string $stateDirectory): void
    {
        $file = rtrim($stateDirectory, '/\\') . '/' . self::LOCAL_FILE;
        $data = json_decode((string)@file_get_contents($file), true);
        $rows = is_array($data['revoked'] ?? null) ? $data['revoked'] : [];
        if (!in_array($fingerprint, array_column($rows, 'fingerprint'), true)) {
            $rows[] = ['fingerprint' => $fingerprint, 'revoked_at' => gmdate('c')];
        }
        $json = json_encode(['revoked' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (!is_dir($stateDirectory) || !DataOverrides::atomicWrite($file, $json)) {
            throw new RuntimeException("Cannot record the revoked update key in {$file}");
        }
    }

    private static function raw(string $publicKey): string
    {
        self::requireSodium();
        return UpdatePackage::decodeKey(trim($publicKey), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'update public key');
    }

    private static function requireSodium(): void
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('Update key rotation requires the PHP sodium extension');
        }
    }
}
