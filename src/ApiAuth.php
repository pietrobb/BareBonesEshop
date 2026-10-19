<?php
/** Machine API bearer credentials, scopes and idempotency records. */
class ApiAuthException extends RuntimeException {}

class ApiAuth {
    /** Scopes ApiV1 checks; anything else would be a silently useless grant (a typo) and is refused. */
    public const KNOWN_SCOPES = [
        '*', 'products:read', 'products:write', 'orders:read', 'orders:write', 'categories:read',
        'customers:read', 'inventory:read', 'inventory:write', 'refunds:write',
    ];
    /** Requests per minute and API client, across all its source addresses (config api_client_rate_limit). */
    public const DEFAULT_CLIENT_RATE_LIMIT = 600;

    /**
     * Creates a credential and returns the plaintext token exactly once.
     * Callers must display/store it securely; only its SHA-256 hash enters the DB.
     */
    public static function issueClient(string $name, string $storeId, array $scopes, ?string $expiresAt = null): array {
        $name = trim($name);
        if ($name === '' || strlen($name) > 191) throw new InvalidArgumentException('Invalid API client name');
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $storeId)
            || !isset((Bootstrap::stores()['stores'] ?? [])[$storeId])) {
            throw new InvalidArgumentException('Invalid API client store');
        }
        $scopes = self::normalizeScopes($scopes);
        if ($scopes === []) throw new InvalidArgumentException('API client needs at least one scope');
        if ($expiresAt !== null) {
            $expiry = trim($expiresAt) === '' ? false : strtotime($expiresAt . ' UTC');
            if ($expiry === false) throw new InvalidArgumentException('Invalid API client expiry');
            $expiresAt = gmdate('Y-m-d H:i:s', $expiry);
        }

        $keyId = bin2hex(random_bytes(6));
        $token = 'bbe_' . $keyId . '_' . bin2hex(random_bytes(32));
        $stmt = Db::get()->prepare("INSERT INTO api_clients
            (key_id, token_hash, store_id, name, scopes, active, expires_at)
            VALUES (:key, :hash, :store, :name, :scopes, 1, :expires)");
        $stmt->execute([
            ':key' => $keyId,
            ':hash' => hash('sha256', $token),
            ':store' => $storeId,
            ':name' => $name,
            ':scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            ':expires' => $expiresAt,
        ]);
        return ['id' => (int)Db::get()->lastInsertId(), 'key_id' => $keyId, 'token' => $token, 'scopes' => $scopes];
    }

    public static function authenticate(string $authorization): array {
        if (!preg_match('/^Bearer (bbe_([a-f0-9]{12})_[a-f0-9]{64})$/D', trim($authorization), $matches)) {
            throw new ApiAuthException('Invalid bearer credential');
        }
        $token = $matches[1];
        $keyId = $matches[2];
        $stmt = Db::get()->prepare('SELECT * FROM api_clients WHERE key_id=:key AND active=1 LIMIT 1');
        $stmt->execute([':key' => $keyId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        // An unfinished SELECT keeps the SQLite read snapshot open; the UPDATE below would then
        // fail with "database is locked" instead of waiting when another request is writing.
        $stmt->closeCursor();
        $actualHash = hash('sha256', $token);
        if (!$client || !hash_equals((string)$client['token_hash'], $actualHash)) {
            throw new ApiAuthException('Invalid bearer credential');
        }
        if (!empty($client['expires_at']) && strtotime((string)$client['expires_at'] . ' UTC') <= time()) {
            throw new ApiAuthException('Expired bearer credential');
        }
        // A stored scope this version no longer knows grants nothing; it must not lock the client out.
        $client['scopes'] = self::normalizeScopes(json_decode((string)$client['scopes'], true) ?: [], false);
        Db::get()->prepare('UPDATE api_clients SET last_used_at=:used WHERE id=:id')
            ->execute([':used' => gmdate('Y-m-d H:i:s'), ':id' => $client['id']]);
        unset($client['token_hash']);
        return $client;
    }

    public static function requireScope(array $client, string $scope): void {
        if (!in_array($scope, $client['scopes'] ?? [], true) && !in_array('*', $client['scopes'] ?? [], true)) {
            throw new DomainException('Missing API scope: ' . $scope);
        }
    }

    public static function requestHash(string $method, string $path, array $body): string {
        $body = self::sortRecursive($body);
        return hash('sha256', strtoupper($method) . "\n" . $path . "\n" . json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return array{status:int,body:array}|null */
    public static function replay(array $client, string $key, string $requestHash): ?array {
        self::assertIdempotencyKey($key);
        $stmt = Db::get()->prepare("SELECT request_hash, response_status, response_body
            FROM api_idempotency WHERE store_id=:store AND client_id=:client AND idempotency_key=:key");
        $stmt->execute([':store' => $client['store_id'], ':client' => $client['id'], ':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if (!hash_equals((string)$row['request_hash'], $requestHash)) {
            throw new UnexpectedValueException('Idempotency key was already used for a different request');
        }
        return [
            'status' => (int)$row['response_status'],
            'body' => json_decode((string)$row['response_body'], true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Claims the key inside the caller's transaction before any mutation. A concurrent request with
     * the same key waits on the unique row and gets false once the holder commits (then replay it).
     * Must be the first statement of the transaction so SQLite takes the write lock with busy-wait.
     */
    public static function claim(array $client, string $key, string $requestHash): bool {
        self::assertIdempotencyKey($key);
        $stmt = Db::get()->prepare(Db::insertIgnore() . " INTO api_idempotency
            (store_id, client_id, idempotency_key, request_hash, response_status, response_body)
            VALUES (:store, :client, :key, :hash, 0, '{}')");
        $stmt->execute([':store' => $client['store_id'], ':client' => $client['id'], ':key' => $key, ':hash' => $requestHash]);
        return $stmt->rowCount() === 1;
    }

    /** Records the final response on the row claimed by claim() in the same transaction. */
    public static function storeResponse(array $client, string $key, string $requestHash, int $status, array $body): void {
        self::assertIdempotencyKey($key);
        $stmt = Db::get()->prepare("UPDATE api_idempotency SET response_status = :status, response_body = :body
            WHERE store_id = :store AND client_id = :client AND idempotency_key = :key AND request_hash = :hash");
        $stmt->execute([
            ':store' => $client['store_id'],
            ':client' => $client['id'],
            ':key' => $key,
            ':hash' => $requestHash,
            ':status' => $status,
            ':body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        if ($stmt->rowCount() !== 1) throw new LogicException('Idempotency key was not claimed');
    }

    public static function cleanupIdempotency(int $olderThanDays = 30): int {
        $olderThanDays = max(1, $olderThanDays);
        $db = Db::get();
        if (Db::isSqlite()) {
            $stmt = $db->prepare("DELETE FROM api_idempotency WHERE created_at < datetime('now', :age)");
            $stmt->execute([':age' => '-' . $olderThanDays . ' days']);
        } else {
            $stmt = $db->query("DELETE FROM api_idempotency WHERE created_at < DATE_SUB(NOW(), INTERVAL {$olderThanDays} DAY)");
        }
        return $stmt->rowCount();
    }

    private static function assertIdempotencyKey(string $key): void {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,190}$/D', $key)) {
            throw new InvalidArgumentException('Invalid Idempotency-Key');
        }
    }

    private static function normalizeScopes(array $scopes, bool $strict = true): array {
        $normalized = [];
        foreach ($scopes as $scope) {
            $scope = strtolower(trim((string)$scope));
            if (!in_array($scope, self::KNOWN_SCOPES, true)) {
                if (!$strict) continue;
                throw new InvalidArgumentException('Unknown API scope ' . substr(preg_replace('/[^a-z0-9_.:*-]/', '', $scope) ?? '', 0, 40)
                    . ' (allowed: ' . implode(', ', self::KNOWN_SCOPES) . ')');
            }
            $normalized[$scope] = true;
        }
        return array_keys($normalized);
    }

    /** Per-client request budget, so a leaked key used from many addresses is still bounded. */
    public static function withinClientRateLimit(array $client, ?array $config = null): bool {
        $limit = (int)(($config ?? Security::config())['api_client_rate_limit'] ?? self::DEFAULT_CLIENT_RATE_LIMIT);
        return Security::rateLimit('external_api_v1_client_' . (int)$client['id'], max(1, $limit), 60, false);
    }

    private static function sortRecursive(array $value): array {
        foreach ($value as &$item) {
            if (is_array($item)) $item = self::sortRecursive($item);
        }
        unset($item);
        if (!array_is_list($value)) ksort($value);
        return $value;
    }
}
