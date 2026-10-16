<?php
/**
 * Secure delivery of files mapped to purchased SKUs.
 * Raw download tokens are returned once; only SHA-256 hashes are persisted.
 */
class DigitalDelivery {
    private static ?array $configOverride = null;

    public static function setConfigForTesting(?array $config): void {
        self::$configOverride = $config;
    }

    public static function orderHasAssets(int $orderId): bool {
        $stmt = Db::get()->prepare("SELECT 1
            FROM order_items oi
            JOIN digital_assets da ON da.sku = oi.sku AND da.active = 1
            WHERE oi.order_id = :order_id LIMIT 1");
        $stmt->execute([':order_id' => $orderId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Create fresh entitlements for all active assets in a paid order.
     * Existing delivered entitlements are left unchanged. Undelivered tokens are
     * rotated so a retried worker never needs a raw token from the database.
     *
     * @return array<int,array{id:int,label:string,url:string,expires_at:?int,download_limit:?int}>
     */
    public static function grant(int $orderId): array {
        $order = Orders::byId($orderId);
        if (!$order || ($order['payment_status'] ?? '') !== 'paid') {
            throw new RuntimeException("Digital delivery requires paid order #{$orderId}");
        }

        $stmt = Db::get()->prepare("SELECT oi.id AS order_item_id, da.*
            FROM order_items oi
            JOIN digital_assets da ON da.sku = oi.sku AND da.active = 1
            WHERE oi.order_id = :order_id
            ORDER BY oi.id, da.id");
        $stmt->execute([':order_id' => $orderId]);
        $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $links = [];

        foreach ($assets as $asset) {
            $existing = self::activeForItemAsset((int)$asset['order_item_id'], (int)$asset['id']);
            if ($existing && !empty($existing['delivered_at'])) continue;
            if ($existing) self::revokeIds([(int)$existing['id']], 'delivery_retry');

            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expirySeconds = $asset['expiry_seconds'] !== null ? max(0, (int)$asset['expiry_seconds']) : 0;
            $expiresAt = $expirySeconds > 0 ? time() + $expirySeconds : null;
            $limit = $asset['download_limit'] !== null ? max(1, (int)$asset['download_limit']) : null;

            $insert = Db::get()->prepare("INSERT INTO digital_entitlements
                (order_id, order_item_id, asset_id, token_hash, status, download_limit, expires_at)
                VALUES (:order_id, :order_item_id, :asset_id, :token_hash, 'active', :download_limit, :expires_at)");
            $insert->execute([
                ':order_id' => $orderId,
                ':order_item_id' => (int)$asset['order_item_id'],
                ':asset_id' => (int)$asset['id'],
                ':token_hash' => $tokenHash,
                ':download_limit' => $limit,
                ':expires_at' => $expiresAt,
            ]);
            $id = (int)Db::get()->lastInsertId();
            $links[] = [
                'id' => $id,
                'label' => (string)($asset['label'] ?: $asset['download_name'] ?: $asset['asset_key']),
                'url' => self::downloadUrl($rawToken),
                'expires_at' => $expiresAt,
                'download_limit' => $limit,
            ];
        }

        return $links;
    }

    /** Lease of a fulfilment claim: longer than grant() + the delivery mail, short enough to recover a crash. */
    public const FULFIL_CLAIM_SECONDS = 120;

    /**
     * Claim the fulfilment of one order (grant + delivery mail) so two workers never run it at
     * the same time: grant() rotates undelivered tokens, so a concurrent run would revoke the links
     * the other one is mailing. Returns the claim key, or null while another worker holds it.
     */
    public static function claimFulfilment(int $orderId): ?string {
        $key = 'digital_fulfill_' . $orderId;
        return Idempotency::claim($key, self::FULFIL_CLAIM_SECONDS) === 'claimed' ? $key : null;
    }

    /** Release a fulfilment claim (also after a failure, so the job's retry can claim it again). */
    public static function releaseFulfilment(string $claim): void {
        Idempotency::unmark($claim);
    }

    public static function markDelivered(array $ids): void {
        $ids = array_values(array_filter(array_map('intval', $ids), fn(int $id) => $id > 0));
        if (!$ids) return;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        Db::get()->prepare("UPDATE digital_entitlements
            SET delivered_at = " . Db::now() . ", updated_at = " . Db::now() . "
            WHERE id IN ({$placeholders}) AND status = 'active'")->execute($ids);
    }

    public static function revokeIssued(array $ids, string $reason): int {
        return self::revokeIds($ids, $reason);
    }

    public static function revokeOrder(int $orderId, string $reason): int {
        $stmt = Db::get()->prepare("UPDATE digital_entitlements
            SET status = 'revoked', revoked_at = " . Db::now() . ", revoked_reason = :reason, updated_at = " . Db::now() . "
            WHERE order_id = :order_id AND status = 'active'");
        $stmt->execute([':order_id' => $orderId, ':reason' => substr($reason, 0, 255)]);
        return $stmt->rowCount();
    }

    public static function forOrder(int $orderId): array {
        $stmt = Db::get()->prepare("SELECT de.id, de.order_item_id, de.status, de.download_count,
                de.download_limit, de.expires_at, de.delivered_at, de.revoked_at,
                de.revoked_reason, da.sku, da.asset_key, da.label, da.download_name
            FROM digital_entitlements de
            JOIN digital_assets da ON da.id = de.asset_id
            WHERE de.order_id = :order_id ORDER BY de.id");
        $stmt->execute([':order_id' => $orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function resolve(string $rawToken): ?array {
        if (!preg_match('/^[0-9a-f]{64}$/', $rawToken)) return null;
        $stmt = Db::get()->prepare("SELECT de.*, da.storage_path, da.download_name, da.content_type,
                da.active AS asset_active, o.status AS order_status, o.payment_status
            FROM digital_entitlements de
            JOIN digital_assets da ON da.id = de.asset_id
            JOIN orders o ON o.id = de.order_id
            WHERE de.token_hash = :token_hash LIMIT 1");
        $stmt->execute([':token_hash' => hash('sha256', $rawToken)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== 'active' || !(int)$row['asset_active']) return null;
        if (($row['payment_status'] ?? '') !== 'paid') return null;
        if (in_array($row['order_status'] ?? '', ['cancelled', 'refunded'], true)) return null;
        if ($row['expires_at'] !== null && time() > (int)$row['expires_at']) return null;
        // A spent limit still lets the last counted download resume inside its window (see authorizeTransfer()).
        if ($row['download_limit'] !== null && (int)$row['download_count'] >= (int)$row['download_limit'] && !self::inResumeWindow($row)) return null;
        return $row;
    }

    /** Seconds after a counted download in which Range requests resume it without counting again. */
    public static function resumeWindowSeconds(): int {
        return max(0, (int)(self::config()['digital_download_resume_minutes'] ?? 30)) * 60;
    }

    /**
     * Whether this transfer may start. A download is counted once: a request from the first byte
     * (no Range, or a range starting at 0) consumes one download; a request resuming later in the
     * file is free while the last counted download is younger than the resume window, and counts as
     * a new download otherwise. HEAD never counts.
     */
    public static function authorizeTransfer(array $entitlement, int $rangeStart, ?int $now = null): bool {
        if ($rangeStart > 0 && self::inResumeWindow($entitlement, $now)) return true;
        return self::consume((int)$entitlement['id'], $now);
    }

    /**
     * One "bytes=" range of a file of $size bytes: null = serve the whole file (no header, several
     * ranges, or a syntax this endpoint does not support), false = unsatisfiable (HTTP 416),
     * otherwise [start, end] inclusive.
     * @return array{0:int,1:int}|false|null
     */
    public static function parseRange(string $header, int $size): array|false|null {
        $header = trim($header);
        if ($header === '' || !preg_match('/^bytes=(\d*)-(\d*)$/i', $header, $m) || ($m[1] === '' && $m[2] === '')) return null;
        if ($m[1] === '') {
            // Suffix range: the last N bytes.
            $length = (int)$m[2];
            if ($length <= 0 || $size <= 0) return false;
            return [max(0, $size - $length), $size - 1];
        }
        $start = (int)$m[1];
        $end = $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1);
        if ($start >= $size || $end < $start) return false;
        return [$start, $end];
    }

    private static function inResumeWindow(array $entitlement, ?int $now = null): bool {
        $last = $entitlement['last_download_at'] ?? null;
        return $last !== null && (int)$last >= ($now ?? time()) - self::resumeWindowSeconds();
    }

    public static function consume(int $entitlementId, ?int $now = null): bool {
        $stmt = Db::get()->prepare("UPDATE digital_entitlements
            SET download_count = download_count + 1, last_download_at = :counted_at, updated_at = " . Db::now() . "
            WHERE id = :id AND status = 'active'
              AND (expires_at IS NULL OR expires_at >= :now)
              AND (download_limit IS NULL OR download_count < download_limit)
              AND EXISTS (
                  SELECT 1 FROM orders o
                  WHERE o.id = digital_entitlements.order_id
                    AND o.payment_status = 'paid'
                    AND o.status NOT IN ('cancelled', 'refunded')
              )");
        $now ??= time();
        $stmt->execute([':id' => $entitlementId, ':now' => $now, ':counted_at' => $now]);
        return $stmt->rowCount() === 1;
    }

    public static function resolvedPath(array $entitlement): ?string {
        $base = rtrim((string)(self::config()['digital_assets_base_dir'] ?? ''), '/\\');
        if ($base === '' || !is_dir($base)) return null;
        $baseReal = realpath($base);
        if ($baseReal === false) return null;

        $relative = str_replace('\\', '/', ltrim((string)($entitlement['storage_path'] ?? ''), '/\\'));
        if ($relative === '' || str_contains($relative, "\0")) return null;
        $real = realpath($baseReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($real === false || !is_file($real) || !is_readable($real)) return null;
        if ($real !== $baseReal && !str_starts_with($real, $baseReal . DIRECTORY_SEPARATOR)) return null;
        return $real;
    }

    public static function safeDownloadName(array $entitlement): string {
        $name = basename(str_replace('\\', '/', (string)($entitlement['download_name'] ?? 'download.bin')));
        $name = preg_replace('/[^A-Za-z0-9._ -]/u', '_', $name) ?: 'download.bin';
        return substr($name, 0, 180);
    }

    public static function downloadUrl(string $rawToken): string {
        $path = '/download.php?token=' . rawurlencode($rawToken);
        $baseUrl = rtrim((string)(self::config()['app_url'] ?? ''), '/');
        return $baseUrl !== '' ? $baseUrl . $path : $path;
    }

    private static function activeForItemAsset(int $orderItemId, int $assetId): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM digital_entitlements
            WHERE order_item_id = :order_item_id AND asset_id = :asset_id AND status = 'active'
            ORDER BY id DESC LIMIT 1");
        $stmt->execute([':order_item_id' => $orderItemId, ':asset_id' => $assetId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function revokeIds(array $ids, string $reason): int {
        $ids = array_values(array_filter(array_map('intval', $ids), fn(int $id) => $id > 0));
        if (!$ids) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE digital_entitlements SET status = 'revoked', revoked_at = " . Db::now()
            . ", revoked_reason = ?, updated_at = " . Db::now()
            . " WHERE id IN ({$placeholders}) AND status = 'active'";
        $stmt = Db::get()->prepare($sql);
        $stmt->execute([substr($reason, 0, 255), ...$ids]);
        return $stmt->rowCount();
    }

    private static function config(): array {
        return self::$configOverride ?? require __DIR__ . '/../config.php';
    }
}
