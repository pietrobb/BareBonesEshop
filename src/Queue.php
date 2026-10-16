<?php
/** Durable queue backend contract used by JobQueue's compatibility facade. */
interface QueueBackend {
    public function name(): string;
    public function push(string $type, array $payload, int $priority = 5): string;
    public function pull(int $limit = 10): array;
    public function markProcessing(array $job): string;
    public function complete(string $claim): void;
    public function fail(string $claim, array $job, string $error): void;
    /** Renew the lease of a claim still owned by this worker; false when it has been taken over. */
    public function extendLease(string $claim): bool;
    public function stats(): array;
    public function failures(int $limit = 20): array;
}

/** Database queue with conditional-update claims safe across concurrent workers. */
class DatabaseQueue implements QueueBackend {
    private PDO $db;
    private int $leaseSeconds;

    public function __construct(?PDO $db = null, int $leaseSeconds = 300) {
        $this->db = $db ?? Db::get();
        $this->leaseSeconds = max(30, min(3600, $leaseSeconds));
    }

    public function name(): string { return 'database'; }

    public function push(string $type, array $payload, int $priority = 5): string {
        self::assertType($type);
        $priority = max(0, min(999, $priority));
        $context = StoreContext::current();
        $payload['_store_id'] ??= $context->id();
        $payload['_channel_id'] ??= $context->channel();
        $id = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d H:i:s');
        $this->db->prepare("INSERT INTO queue_jobs
            (id, store_id, channel_id, type, priority, payload, state, attempts, max_attempts,
             available_at, correlation_id, created_at, updated_at)
            VALUES (:id, :store, :channel, :type, :priority, :payload, 'pending', 0, 3,
                    :available, :correlation, :created, :updated)")
            ->execute([
                ':id' => $id, ':store' => $payload['_store_id'], ':channel' => $payload['_channel_id'],
                ':type' => $type, ':priority' => $priority,
                ':payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ':available' => $now, ':correlation' => Logger::correlationId(),
                ':created' => $now, ':updated' => $now,
            ]);
        return $id;
    }

    public function pull(int $limit = 10): array {
        $this->releaseExpiredClaims();
        $limit = max(0, min(100, $limit));
        if ($limit === 0) return [];
        $stmt = $this->db->prepare("SELECT * FROM queue_jobs
            WHERE state IN ('pending','retry') AND available_at <= :now
            ORDER BY priority, available_at, created_at, id LIMIT :limit");
        $stmt->bindValue(':now', gmdate('Y-m-d H:i:s'));
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function markProcessing(array $job): string {
        $id = (string)($job['id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) throw new RuntimeException('DatabaseQueue: invalid job ID');
        $token = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->db->prepare("UPDATE queue_jobs SET state='processing', claim_token=:token,
            claimed_at=:claimed, updated_at=:updated
            WHERE id=:id AND state IN ('pending','retry') AND available_at <= :available");
        $stmt->execute([':token' => $token, ':claimed' => $now, ':updated' => $now, ':id' => $id, ':available' => $now]);
        return $stmt->rowCount() === 1 ? "db:{$id}:{$token}" : '';
    }

    /**
     * An expired lease means the worker died mid-job (fatal error, OOM, kill). That run counts as
     * a failed attempt, so a job that keeps killing workers is dead-lettered after max_attempts
     * instead of being re-claimed forever. state is assigned before attempts: MySQL evaluates
     * SET left to right with updated values, SQLite with the old ones.
     */
    private function releaseExpiredClaims(): void {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->prepare("UPDATE queue_jobs
            SET state = CASE WHEN attempts + 1 >= max_attempts THEN 'failed' ELSE 'retry' END,
                attempts = attempts + 1, available_at = :available, claim_token = NULL, claimed_at = NULL,
                last_error = :error, updated_at = :updated
            WHERE state = 'processing' AND claimed_at <= :stale")
            ->execute([
                ':available' => $now, ':updated' => $now,
                ':error' => 'Worker stopped while processing the job (lease expired)',
                ':stale' => gmdate('Y-m-d H:i:s', time() - $this->leaseSeconds),
            ]);
    }

    public function complete(string $claim): void {
        [$id, $token] = $this->parseClaim($claim);
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->db->prepare("UPDATE queue_jobs SET state='completed', claim_token=NULL,
            completed_at=:completed, updated_at=:updated WHERE id=:id AND state='processing' AND claim_token=:token");
        $stmt->execute([':completed' => $now, ':updated' => $now, ':id' => $id, ':token' => $token]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('DatabaseQueue: claim is no longer owned');
    }

    public function fail(string $claim, array $job, string $error): void {
        [$id, $token] = $this->parseClaim($claim);
        $read = $this->db->prepare("SELECT attempts, max_attempts FROM queue_jobs WHERE id=:id AND state='processing' AND claim_token=:token");
        $read->execute([':id' => $id, ':token' => $token]);
        $current = $read->fetch(PDO::FETCH_ASSOC);
        if (!$current) throw new RuntimeException('DatabaseQueue: claim is no longer owned');
        $attempts = (int)$current['attempts'] + 1;
        $failed = $attempts >= (int)$current['max_attempts'];
        $available = gmdate('Y-m-d H:i:s', time() + (int)pow(4, $attempts));
        $stmt = $this->db->prepare("UPDATE queue_jobs SET state=:state, attempts=:attempts,
            available_at=:available, claim_token=NULL, claimed_at=NULL, last_error=:error, updated_at=:updated
            WHERE id=:id AND state='processing' AND claim_token=:token");
        $stmt->execute([
            ':state' => $failed ? 'failed' : 'retry', ':attempts' => $attempts,
            ':available' => $available, ':error' => substr($error, 0, 2000),
            ':updated' => gmdate('Y-m-d H:i:s'), ':id' => $id, ':token' => $token,
        ]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('DatabaseQueue: claim is no longer owned');
    }

    public function extendLease(string $claim): bool {
        [$id, $token] = $this->parseClaim($claim);
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->db->prepare("UPDATE queue_jobs SET claimed_at=:claimed, updated_at=:updated
            WHERE id=:id AND state='processing' AND claim_token=:token");
        $stmt->execute([':claimed' => $now, ':updated' => $now, ':id' => $id, ':token' => $token]);
        if ($stmt->rowCount() === 1) return true;
        // MySQL reports 0 affected rows when the renewal lands in the same second as the claim.
        $owned = $this->db->prepare("SELECT 1 FROM queue_jobs WHERE id=:id AND state='processing' AND claim_token=:token");
        $owned->execute([':id' => $id, ':token' => $token]);
        return $owned->fetchColumn() !== false;
    }

    public function stats(): array {
        $stats = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($this->db->query('SELECT state, COUNT(*) AS aggregate FROM queue_jobs GROUP BY state')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $state = (string)$row['state'];
            if ($state === 'retry') $state = 'pending';
            if (array_key_exists($state, $stats)) $stats[$state] += (int)$row['aggregate'];
        }
        return $stats;
    }

    public function failures(int $limit = 20): array {
        $stmt = $this->db->prepare("SELECT id, store_id, type, attempts, last_error, correlation_id, updated_at
            FROM queue_jobs WHERE state='failed' ORDER BY updated_at DESC LIMIT :limit");
        $stmt->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function hydrate(array $row): array {
        $payload = json_decode((string)$row['payload'], true, 512, JSON_THROW_ON_ERROR);
        return [
            'id' => (string)$row['id'], 'type' => (string)$row['type'],
            'priority' => (int)$row['priority'], 'payload' => $payload,
            'created_at' => (string)$row['created_at'], 'attempts' => (int)$row['attempts'],
            'max_attempts' => (int)$row['max_attempts'], 'retry_after' => (string)$row['available_at'],
            'correlation_id' => (string)$row['correlation_id'], '_queue_backend' => 'database',
        ];
    }

    private function parseClaim(string $claim): array {
        if (!preg_match('/^db:([a-f0-9]{32}):([a-f0-9]{32})$/', $claim, $match)) {
            throw new RuntimeException('DatabaseQueue: invalid claim');
        }
        return [$match[1], $match[2]];
    }

    private static function assertType(string $type): void {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,79}$/', $type)) throw new InvalidArgumentException('Invalid queue job type');
    }
}

/**
 * Redis queue using Lua claims so multiple workers cannot own the same job.
 * GDPR: jobs held here are not reached by GdprService's export or erasure (QueueBackend has no way
 * to enumerate jobs); see the queue section of ESHOP-DESIGN.md.
 */
class RedisQueue implements QueueBackend {
    private object $redis;
    private string $prefix;
    private int $leaseSeconds;

    public function __construct(array $config) {
        if (!class_exists('Redis')) throw new RuntimeException('Redis queue requires ext-redis');
        $this->prefix = (string)($config['prefix'] ?? 'bbe:queue:');
        if (!preg_match('/^[A-Za-z0-9:_-]{1,80}$/', $this->prefix)) throw new InvalidArgumentException('Invalid Redis queue prefix');
        $this->leaseSeconds = max(30, min(3600, (int)($config['lease_seconds'] ?? 300)));
        $redis = new Redis();
        $redis->connect((string)($config['host'] ?? '127.0.0.1'), (int)($config['port'] ?? 6379), (float)($config['timeout'] ?? 2.0));
        if ((string)($config['password'] ?? '') !== '') $redis->auth((string)$config['password']);
        $redis->select((int)($config['database'] ?? 0));
        $this->redis = $redis;
    }

    public function name(): string { return 'redis'; }

    public function push(string $type, array $payload, int $priority = 5): string {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,79}$/', $type)) throw new InvalidArgumentException('Invalid queue job type');
        $context = StoreContext::current();
        $payload['_store_id'] ??= $context->id();
        $payload['_channel_id'] ??= $context->channel();
        $id = bin2hex(random_bytes(16));
        $now = time();
        $job = [
            'id' => $id, 'store_id' => (string)$payload['_store_id'], 'channel_id' => (string)$payload['_channel_id'],
            'type' => $type, 'priority' => (string)max(0, min(99, $priority)),
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'state' => 'pending', 'attempts' => '0', 'max_attempts' => '3',
            'available_at' => (string)$now, 'correlation_id' => Logger::correlationId(),
            'created_at' => gmdate('c', $now), 'updated_at' => gmdate('c', $now),
        ];
        $this->redis->multi();
        $this->redis->hMSet($this->jobKey($id), $job);
        $this->redis->zAdd($this->key('pending'), ($now * 100) + (int)$job['priority'], $id);
        $this->redis->exec();
        return $id;
    }

    public function pull(int $limit = 10): array {
        $limit = max(0, min(100, $limit));
        if ($limit === 0) return [];
        $this->requeueStale();
        $ids = $this->redis->zRangeByScore($this->key('pending'), '-inf', (time() * 100) + 99, ['limit' => [0, $limit]]);
        $jobs = [];
        foreach ($ids ?: [] as $id) {
            $row = $this->redis->hGetAll($this->jobKey((string)$id));
            if ($row) $jobs[] = $this->hydrate($row);
        }
        return $jobs;
    }

    public function markProcessing(array $job): string {
        $id = (string)($job['id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) throw new RuntimeException('RedisQueue: invalid job ID');
        $token = bin2hex(random_bytes(16));
        $script = "if redis.call('zrem', KEYS[1], ARGV[1]) == 0 then return 0 end "
            . "redis.call('hmset', KEYS[2], 'state','processing','claim_token',ARGV[2],'claimed_at',ARGV[3],'updated_at',ARGV[4]) "
            . "redis.call('zadd', KEYS[3], ARGV[5], ARGV[1]) return 1";
        $claimed = $this->redis->eval($script, [
            $this->key('pending'), $this->jobKey($id), $this->key('processing'),
            $id, $token, (string)time(), gmdate('c'), (string)(time() + $this->leaseSeconds),
        ], 3);
        return (int)$claimed === 1 ? "redis:{$id}:{$token}" : '';
    }

    public function complete(string $claim): void {
        [$id, $token] = $this->parseClaim($claim);
        $script = "if redis.call('hget', KEYS[1], 'claim_token') ~= ARGV[1] then return 0 end "
            . "redis.call('zrem', KEYS[2], ARGV[2]) redis.call('hmset', KEYS[1], 'state','completed','claim_token','','updated_at',ARGV[3]) "
            . "redis.call('zadd', KEYS[3], ARGV[4], ARGV[2]) return 1";
        $ok = $this->redis->eval($script, [$this->jobKey($id), $this->key('processing'), $this->key('completed'), $token, $id, gmdate('c'), (string)time()], 3);
        if ((int)$ok !== 1) throw new RuntimeException('RedisQueue: claim is no longer owned');
    }

    public function fail(string $claim, array $job, string $error): void {
        [$id, $token] = $this->parseClaim($claim);
        $row = $this->redis->hGetAll($this->jobKey($id));
        if (!$row || ($row['claim_token'] ?? '') !== $token) throw new RuntimeException('RedisQueue: claim is no longer owned');
        $attempts = (int)($row['attempts'] ?? 0) + 1;
        $failed = $attempts >= (int)($row['max_attempts'] ?? 3);
        $available = time() + (int)pow(4, $attempts);
        $target = $failed ? 'failed' : 'pending';
        $score = $failed ? time() : ($available * 100) + (int)($row['priority'] ?? 5);
        $script = "if redis.call('hget', KEYS[1], 'claim_token') ~= ARGV[1] then return 0 end "
            . "redis.call('zrem', KEYS[2], ARGV[2]) redis.call('hmset', KEYS[1], 'state',ARGV[3],'attempts',ARGV[4],"
            . "'available_at',ARGV[5],'claim_token','','last_error',ARGV[6],'updated_at',ARGV[7]) "
            . "redis.call('zadd', KEYS[3], ARGV[8], ARGV[2]) return 1";
        $ok = $this->redis->eval($script, [
            $this->jobKey($id), $this->key('processing'), $this->key($target),
            $token, $id, $target === 'failed' ? 'failed' : 'retry', (string)$attempts,
            (string)$available, substr($error, 0, 2000), gmdate('c'), (string)$score,
        ], 3);
        if ((int)$ok !== 1) throw new RuntimeException('RedisQueue: claim is no longer owned');
    }

    public function extendLease(string $claim): bool {
        [$id, $token] = $this->parseClaim($claim);
        $script = "if redis.call('hget', KEYS[1], 'claim_token') ~= ARGV[1] then return 0 end "
            . "if redis.call('zscore', KEYS[2], ARGV[2]) == false then return 0 end "
            . "redis.call('zadd', KEYS[2], ARGV[3], ARGV[2]) redis.call('hset', KEYS[1], 'updated_at', ARGV[4]) return 1";
        return (int)$this->redis->eval($script, [
            $this->jobKey($id), $this->key('processing'), $token, $id, (string)(time() + $this->leaseSeconds), gmdate('c'),
        ], 2) === 1;
    }

    public function stats(): array {
        return [
            'pending' => (int)$this->redis->zCard($this->key('pending')),
            'processing' => (int)$this->redis->zCard($this->key('processing')),
            'completed' => (int)$this->redis->zCard($this->key('completed')),
            'failed' => (int)$this->redis->zCard($this->key('failed')),
        ];
    }

    public function failures(int $limit = 20): array {
        $ids = $this->redis->zRevRange($this->key('failed'), 0, max(0, min(99, $limit - 1)));
        $failures = [];
        foreach ($ids ?: [] as $id) {
            $row = $this->redis->hGetAll($this->jobKey((string)$id));
            if (!$row) continue;
            $failures[] = [
                'id' => (string)$id, 'store_id' => (string)($row['store_id'] ?? ''),
                'type' => (string)($row['type'] ?? ''), 'attempts' => (int)($row['attempts'] ?? 0),
                'last_error' => (string)($row['last_error'] ?? ''),
                'correlation_id' => (string)($row['correlation_id'] ?? ''), 'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }
        return $failures;
    }

    /** Expired lease = the worker died mid-job; count it as an attempt and dead-letter at max_attempts. */
    private function requeueStale(): void {
        $ids = $this->redis->zRangeByScore($this->key('processing'), '-inf', (string)time());
        foreach ($ids ?: [] as $id) {
            $row = $this->redis->hGetAll($this->jobKey((string)$id));
            if (!$row) continue;
            $script = "if redis.call('zrem', KEYS[1], ARGV[1]) == 0 then return 0 end "
                . "local attempts = redis.call('hincrby', KEYS[2], 'attempts', 1) "
                . "local dead = attempts >= tonumber(redis.call('hget', KEYS[2], 'max_attempts') or '3') "
                . "redis.call('hmset', KEYS[2], 'state', dead and 'failed' or 'retry', 'claim_token','','available_at',ARGV[2],'updated_at',ARGV[3],'last_error',ARGV[6]) "
                . "if dead then redis.call('zadd', KEYS[4], ARGV[5], ARGV[1]) else redis.call('zadd', KEYS[3], ARGV[4], ARGV[1]) end return 1";
            $priority = (int)($row['priority'] ?? 5);
            $this->redis->eval($script, [
                $this->key('processing'), $this->jobKey((string)$id), $this->key('pending'), $this->key('failed'),
                (string)$id, (string)time(), gmdate('c'), (string)((time() * 100) + $priority), (string)time(),
                'Worker stopped while processing the job (lease expired)',
            ], 4);
        }
    }

    private function hydrate(array $row): array {
        return [
            'id' => (string)$row['id'], 'type' => (string)$row['type'], 'priority' => (int)$row['priority'],
            'payload' => json_decode((string)$row['payload'], true, 512, JSON_THROW_ON_ERROR),
            'created_at' => (string)$row['created_at'], 'attempts' => (int)$row['attempts'],
            'max_attempts' => (int)$row['max_attempts'],
            'retry_after' => gmdate('c', (int)$row['available_at']),
            'correlation_id' => (string)$row['correlation_id'], '_queue_backend' => 'redis',
        ];
    }

    private function parseClaim(string $claim): array {
        if (!preg_match('/^redis:([a-f0-9]{32}):([a-f0-9]{32})$/', $claim, $match)) throw new RuntimeException('RedisQueue: invalid claim');
        return [$match[1], $match[2]];
    }

    private function key(string $name): string { return $this->prefix . $name; }
    private function jobKey(string $id): string { return $this->prefix . 'job:' . $id; }
}
