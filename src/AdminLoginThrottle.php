<?php

/**
 * Admin login brute-force throttle that cannot be used to lock out a real admin.
 *
 * Only failed attempts are recorded, and a rejected attempt records nothing, so
 * an attacker cannot extend a block by continuing to send requests.
 *  - pair    (account + IP): strict; a success from that IP clears it.
 *  - ip      (any account):  stops one IP from spraying usernames.
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

    public function __construct(PDO $db, ?Closure $clock = null)
    {
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

    public function recordFailure(string $account, string $ip): void
    {
        $account = self::normalize($account);
        $insert = $this->db->prepare('INSERT INTO hits (key, ts) VALUES (:key, :ts)');
        $now = ($this->clock)();
        foreach ([$this->key('pair', $account, $ip), $this->key('ip', '', $ip), $this->key('account', $account, '')] as $key) {
            $insert->execute([':key' => $key, ':ts' => $now]);
        }
    }

    public function recordSuccess(string $account, string $ip): void
    {
        $account = self::normalize($account);
        $now = ($this->clock)();
        $delete = $this->db->prepare('DELETE FROM hits WHERE key = :key');
        $delete->execute([':key' => $this->key('pair', $account, $ip)]);
        $delete->execute([':key' => $this->key('ip', '', $ip)]);
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
