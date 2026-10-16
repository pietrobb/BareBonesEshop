<?php

/**
 * Admin login brute-force throttle that cannot be used to lock out a real admin.
 *
 * Only failed attempts are recorded, and a rejected attempt records nothing, so
 * an attacker cannot extend a block by continuing to send requests.
 *  - pair    (account + IP): strict; a success from that IP clears it.
 *  - ip      (any account):  stops one IP from spraying usernames; a success
 *    does not clear it.
 *  - account (all IPs):      ceiling for distributed guessing; it only blocks
 *    IPs that have not logged into this account successfully in the last
 *    TRUST_SECONDS, so the owner from a known IP is never locked out.
 */
final class AdminLoginThrottle
{
    public const WINDOW_SECONDS = 900;
    public const PAIR_LIMIT = 5;
    public const IP_LIMIT = 20;
    public const ACCOUNT_LIMIT = 100;
    public const TRUST_SECONDS = 30 * 86400;

    private PDO $db;
    /** @var Closure(): int */
    private Closure $clock;
    /** @var list<int> */
    private array $reserved = [];

    public function __construct(PDO $db, ?Closure $clock = null)
    {
        $db->exec('PRAGMA busy_timeout = 5000');
        $db->exec('CREATE TABLE IF NOT EXISTS hits (key TEXT NOT NULL, ts INTEGER NOT NULL)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_hits ON hits(key, ts)');
        $db->exec('CREATE TABLE IF NOT EXISTS admin_login_trust (key TEXT PRIMARY KEY, ts INTEGER NOT NULL)');
        $this->db = $db;
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** Seconds until the next attempt is allowed; 0 = allowed. Never writes. */
    public function retryAfter(string $account, string $ip): int
    {
        $account = self::normalize($account);
        $wait = max(
            $this->bucketWait($this->key('pair', $account, $ip), self::PAIR_LIMIT),
            $this->bucketWait($this->key('ip', '', $ip), self::IP_LIMIT)
        );
        if (!$this->isTrusted($account, $ip)) {
            $wait = max($wait, $this->bucketWait($this->key('account', $account, ''), self::ACCOUNT_LIMIT));
        }
        return $wait;
    }

    /**
     * Atomically checks the limits and, when allowed, records this attempt as a
     * failure before the password is verified, so parallel requests cannot all
     * pass the check. Returns seconds to wait; 0 = attempt reserved. A rejected
     * attempt still writes nothing. recordSuccess() releases the reservation.
     */
    public function reserve(string $account, string $ip): int
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $wait = $this->retryAfter($account, $ip);
            if ($wait === 0) $this->reserved = $this->recordFailure($account, $ip);
            $this->db->exec('COMMIT');
        } catch (Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
        return $wait;
    }

    /** @return list<int> rowids of the inserted ip/account hits */
    public function recordFailure(string $account, string $ip): array
    {
        $account = self::normalize($account);
        $insert = $this->db->prepare('INSERT INTO hits (key, ts) VALUES (:key, :ts)');
        $now = ($this->clock)();
        $insert->execute([':key' => $this->key('pair', $account, $ip), ':ts' => $now]);
        $rowIds = [];
        foreach ([$this->key('ip', '', $ip), $this->key('account', $account, '')] as $key) {
            $insert->execute([':key' => $key, ':ts' => $now]);
            $rowIds[] = (int)$this->db->lastInsertId();
        }
        return $rowIds;
    }

    /**
     * Clears the account+IP pair bucket and this attempt's own reservation.
     * The IP and account buckets keep earlier failures: a valid login on one
     * account must not reset the spray limit for the other accounts.
     */
    public function recordSuccess(string $account, string $ip): void
    {
        $account = self::normalize($account);
        $now = ($this->clock)();
        $this->db->prepare('DELETE FROM hits WHERE key = :key')
            ->execute([':key' => $this->key('pair', $account, $ip)]);
        if ($this->reserved !== []) {
            $this->db->exec('DELETE FROM hits WHERE rowid IN (' . implode(',', array_map('intval', $this->reserved)) . ')');
            $this->reserved = [];
        }
        $this->db->prepare('DELETE FROM admin_login_trust WHERE ts < :cutoff')
            ->execute([':cutoff' => $now - self::TRUST_SECONDS]);
        $this->db->prepare('INSERT OR REPLACE INTO admin_login_trust (key, ts) VALUES (:key, :ts)')
            ->execute([':key' => $this->key('trust', $account, $ip), ':ts' => $now]);
    }

    private function bucketWait(string $key, int $limit): int
    {
        $now = ($this->clock)();
        $stmt = $this->db->prepare('SELECT ts FROM hits WHERE key = :key AND ts > :since ORDER BY ts DESC LIMIT 1 OFFSET ' . ($limit - 1));
        $stmt->execute([':key' => $key, ':since' => $now - self::WINDOW_SECONDS]);
        $limitingHit = $stmt->fetchColumn();
        return $limitingHit === false ? 0 : max(1, (int)$limitingHit + self::WINDOW_SECONDS - $now);
    }

    private function isTrusted(string $account, string $ip): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM admin_login_trust WHERE key = :key AND ts > :since');
        $stmt->execute([':key' => $this->key('trust', $account, $ip), ':since' => ($this->clock)() - self::TRUST_SECONDS]);
        return $stmt->fetchColumn() !== false;
    }

    private function key(string $scope, string $account, string $ip): string
    {
        return hash('sha256', 'admin_login_' . $scope . '|' . $account . '|' . $ip);
    }

    private static function normalize(string $account): string
    {
        return strtolower(trim($account));
    }
}
