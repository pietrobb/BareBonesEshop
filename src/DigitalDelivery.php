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

    /**
     * Lease of a fulfilment claim: longer than grant() plus a slow delivery mail (SMTP/API timeouts), short enough to
     * recover a crashed worker. The worker also renews the claim right before mailing (renewFulfilment()).
     */
    public const FULFIL_CLAIM_SECONDS = 600;
    /** claimed_at values from this value up are claim tokens (microseconds + random), below it Unix seconds. */
    private const TOKEN_FLOOR = 100000000000;

    /**
     * Claim the fulfilment of one order (grant + delivery mail) so two workers never run it at
     * the same time: grant() rotates undelivered tokens, so a concurrent run would revoke the links
     * the other one is mailing. Returns the claim ("<key>#<token>"), or null while another worker holds it.
     * The token (processed_events.claimed_at: microseconds plus a random part) is unique per claim, so a worker
     * whose lease ran out can never renew or release the claim another worker has taken over in the same second.
     */
    public static function claimFulfilment(int $orderId): ?string {
        $key = 'digital_fulfill_' . $orderId;
        $db = Db::get();
        if (!Db::hasColumn('processed_events', 'claimed_at', $db) || !Db::hasColumn('processed_events', 'state', $db)) {
            return Idempotency::claim($key, self::FULFIL_CLAIM_SECONDS) === 'claimed' ? $key : null;
        }
        $now = time();
        $token = self::claimToken($now);
        try {
            $db->prepare("INSERT INTO processed_events (event_id, state, claimed_at) VALUES (:id, 'processing', :token)")
               ->execute([':id' => $key, ':token' => $token]);
            return $key . '#' . $token;
        } catch (\PDOException $e) {
            if (!in_array($e->getCode(), ['23000', '23505'], true)) throw $e;
        }
        // Take over a lapsed claim (claimed_at in seconds was written by Idempotency::claim() before this format).
        $cutoff = $now - self::FULFIL_CLAIM_SECONDS;
        $takeover = $db->prepare("UPDATE processed_events SET claimed_at = :token
            WHERE event_id = :id AND state = 'processing'
              AND (claimed_at IS NULL OR claimed_at < :cutoff_s OR (claimed_at >= :floor AND claimed_at < :cutoff_token))");
        $takeover->execute([':token' => $token, ':id' => $key, ':cutoff_s' => min($cutoff, self::TOKEN_FLOOR), ':floor' => self::TOKEN_FLOOR, ':cutoff_token' => $cutoff * 1000000]);
        return $takeover->rowCount() === 1 ? $key . '#' . $token : null;
    }

    /**
     * Renew a fulfilment claim for another full lease (call before the slow delivery mail). Returns the renewed claim,
     * or null when it was lost (lapsed and taken over by another worker): then the caller must not mail its links.
     */
    public static function renewFulfilment(string $claim): ?string {
        [$key, $token] = array_pad(explode('#', $claim, 2), 2, '');
        if ($token === '') return $claim; // legacy claim (migration pending): nothing to renew
        $next = self::claimToken(time());
        $stmt = Db::get()->prepare("UPDATE processed_events SET claimed_at = :next WHERE event_id = :id AND state = 'processing' AND claimed_at = :token");
        $stmt->execute([':next' => $next, ':id' => $key, ':token' => (int)$token]);
        return $stmt->rowCount() === 1 ? $key . '#' . $next : null;
    }

    /** Release a fulfilment claim (also after a failure, so the job's retry can claim it again); never another worker's. */
    public static function releaseFulfilment(string $claim): void {
        [$key, $token] = array_pad(explode('#', $claim, 2), 2, '');
        if ($token === '') { Idempotency::unmark($key); return; }
        Db::get()->prepare("DELETE FROM processed_events WHERE event_id = :id AND state = 'processing' AND claimed_at = :token")
            ->execute([':id' => $key, ':token' => (int)$token]);
    }

    private static function claimToken(int $now): int {
        return $now * 1000000 + random_int(0, 999999);
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
        // A partial refund keeps paid downloads, except of the line it credited (digital_revoke handles whole reversals).
        $paymentStatus = (string)($row['payment_status'] ?? '');
        if (!in_array($paymentStatus, ['paid', 'partially_refunded', 'refund_pending'], true)) return null;
        if ($paymentStatus !== 'paid' && self::lineCredited((int)$row['order_item_id'])) return null;
        if (in_array($row['order_status'] ?? '', ['cancelled', 'refunded'], true)) return null;
        if ($row['expires_at'] !== null && time() > (int)$row['expires_at']) return null;
        // A spent limit still lets the last counted download resume inside its window (see authorizeTransfer()).
        if ($row['download_limit'] !== null && (int)$row['download_count'] >= (int)$row['download_limit'] && !self::canResume($row)) return null;
        return $row;
    }

    /** Free resumes of one counted download (inside its window, never past the bytes it already served). */
    public const MAX_FREE_RESUMES = 5;

    /**
     * A resume continues where the interrupted transfer stopped: the client has received a little less than the
     * server sent (buffers in flight), so a free resume must start within this many bytes below the recorded offset.
     * Without the lower bound "Range: bytes=1-" after a long partial transfer would be a free near-complete copy.
     */
    public const RESUME_SLACK_BYTES = 16 * 1024 * 1024;

    /** Seconds after a counted download in which Range requests resume it without counting again. */
    public static function resumeWindowSeconds(): int {
        return max(0, (int)(self::config()['digital_download_resume_minutes'] ?? 30)) * 60;
    }

    /**
     * Whether this transfer may start (see beginTransfer()).
     */
    public static function authorizeTransfer(array $entitlement, int $rangeStart, ?int $now = null): bool {
        return self::beginTransfer($entitlement, $rangeStart, $now) !== null;
    }

    /**
     * Start a transfer: the time of the counted download it belongs to (pass it to recordProgress()), or null when it
     * is refused. A download is counted once: a request from the first byte (no Range, or a range starting at 0)
     * consumes one download. A request resuming later in the file is free only when it continues the last counted
     * download: inside the resume window, starting at most RESUME_SLACK_BYTES below the bytes that download already
     * served and no later than them (last_download_offset, see recordProgress()), at most MAX_FREE_RESUMES times. Any other request counts as a new
     * download, so once the limit is spent it is refused ("Range: bytes=1-" is no free copy). HEAD never counts.
     */
    public static function beginTransfer(array $entitlement, int $rangeStart, ?int $now = null): ?int {
        $now ??= time();
        if ($rangeStart > 0 && self::claimResume($entitlement, $rangeStart, $now)) return (int)$entitlement['last_download_at'];
        return self::consume((int)$entitlement['id'], $now) ? $now : null;
    }

    /**
     * The counted download started at $countedAt has served the file up to byte $offset (exclusive): later free
     * resumes may start up to there. Only ever grows, and only while that download is still the last counted one.
     */
    public static function recordProgress(int $entitlementId, int $countedAt, int $offset): void {
        if ($offset <= 0 || !self::resumeTracked()) return;
        Db::get()->prepare("UPDATE digital_entitlements
            SET last_download_offset = CASE WHEN last_download_offset IS NULL OR last_download_offset < :grow THEN :offset ELSE last_download_offset END
            WHERE id = :id AND last_download_at = :counted_at")
            ->execute([':grow' => $offset, ':offset' => $offset, ':id' => $entitlementId, ':counted_at' => $countedAt]);
    }

    /** Take one free resume of the last counted download (atomic: the limit of free resumes holds under concurrency). */
    private static function claimResume(array $entitlement, int $rangeStart, int $now): bool {
        $served = (int)($entitlement['last_download_offset'] ?? 0);
        if (!self::canResume($entitlement, $now) || $rangeStart > $served || $rangeStart < $served - self::RESUME_SLACK_BYTES) return false;
        $stmt = Db::get()->prepare("UPDATE digital_entitlements
            SET resume_count = resume_count + 1, updated_at = " . Db::now() . "
            WHERE id = :id AND status = 'active' AND last_download_at = :counted_at AND last_download_at >= :since
              AND resume_count < :max_resumes AND last_download_offset >= :start AND last_download_offset <= :start_plus_slack
              AND (expires_at IS NULL OR expires_at >= :now)
              AND EXISTS (
                  SELECT 1 FROM orders o
                  WHERE o.id = digital_entitlements.order_id
                    AND o.payment_status IN ('paid', 'partially_refunded', 'refund_pending')
                    AND o.status NOT IN ('cancelled', 'refunded')
              )");
        $stmt->execute([
            ':id' => (int)$entitlement['id'], ':counted_at' => (int)$entitlement['last_download_at'], ':since' => $now - self::resumeWindowSeconds(),
            ':max_resumes' => self::MAX_FREE_RESUMES, ':start' => $rangeStart, ':start_plus_slack' => $rangeStart + self::RESUME_SLACK_BYTES, ':now' => $now,
        ]);
        return $stmt->rowCount() === 1;
    }

    /** Resume tracking needs the columns of migrations 20261016142000 and 20261016161000 (before them every transfer counts). */
    private static function resumeTracked(): bool {
        return Db::hasColumn('digital_entitlements', 'last_download_at')
            && Db::hasColumn('digital_entitlements', 'last_download_offset')
            && Db::hasColumn('digital_entitlements', 'resume_count');
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

    /** The last counted download may still be resumed for free: inside its window, with bytes served and resumes left. */
    private static function canResume(array $entitlement, ?int $now = null): bool {
        $last = $entitlement['last_download_at'] ?? null;
        return $last !== null && (int)$last >= ($now ?? time()) - self::resumeWindowSeconds()
            && (int)($entitlement['last_download_offset'] ?? 0) > 0
            && array_key_exists('resume_count', $entitlement) && (int)$entitlement['resume_count'] < self::MAX_FREE_RESUMES;
    }

    public static function consume(int $entitlementId, ?int $now = null): bool {
        // Before the resume migrations ran the columns are missing: count without resume tracking instead of failing.
        $tracking = !Db::hasColumn('digital_entitlements', 'last_download_at') ? ''
            : (self::resumeTracked() ? 'last_download_at = :counted_at, last_download_offset = 0, resume_count = 0, ' : 'last_download_at = :counted_at, ');
        $stmt = Db::get()->prepare("UPDATE digital_entitlements
            SET download_count = download_count + 1, {$tracking}updated_at = " . Db::now() . "
            WHERE id = :id AND status = 'active'
              AND (expires_at IS NULL OR expires_at >= :now)
              AND (download_limit IS NULL OR download_count < download_limit)
              AND EXISTS (
                  SELECT 1 FROM orders o
                  WHERE o.id = digital_entitlements.order_id
                    AND o.payment_status IN ('paid', 'partially_refunded', 'refund_pending')
                    AND o.status NOT IN ('cancelled', 'refunded')
              )");
        $now ??= time();
        $params = [':id' => $entitlementId, ':now' => $now];
        if ($tracking !== '') $params[':counted_at'] = $now;
        $stmt->execute($params);
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
        // A "<catalog_id>/..." row must stay inside that store's directory: a symlink there must not reach another
        // store's files (or other files below the base). Legacy base-relative rows only need to stay below the base.
        $segment = explode('/', $relative, 2)[0];
        if (str_contains($relative, '/') && in_array($segment, self::catalogIds(), true)) {
            $storeDir = realpath($baseReal . DIRECTORY_SEPARATOR . $segment);
            if ($storeDir === false || !str_starts_with($real, $storeDir . DIRECTORY_SEPARATOR)) return null;
        }
        return $real;
    }

    /**
     * storage_path to save for the path an admin entered for an asset of the active catalog, or [null, lang key] with
     * the reason it is refused. The file must exist in the store directory <base>/<catalog_id>/. An asset saved before
     * per-store directories keeps its base-relative path when it is re-saved unchanged and its file still exists (it is
     * never rewritten to a path where no file is); a path whose file is only found at the old base-relative location
     * is refused with a message to move the file into the store directory.
     * @return array{0:?string,1:?string}
     */
    public static function assetPathForSave(string $input, ?string $currentPath, ?string $catalogId = null): array {
        $catalogId ??= StoreContext::current()->catalogId();
        $input = str_replace('\\', '/', trim($input));
        $base = rtrim((string)(self::config()['digital_assets_base_dir'] ?? ''), '/\\');
        if ($currentPath !== null && $input === $currentPath && !str_starts_with($currentPath, $catalogId . '/')) {
            return self::resolvedPath(['storage_path' => $currentPath]) !== null ? [$currentPath, null] : [null, 'admin.digital.file_missing'];
        }
        $stored = self::storePath($input, $catalogId);
        if ($stored === null) return [null, 'admin.digital.invalid'];
        $storeDir = $base === '' ? false : realpath($base . DIRECTORY_SEPARATOR . $catalogId);
        $file = $storeDir === false ? false : realpath($base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $stored));
        if ($file !== false && is_file($file) && str_starts_with($file, $storeDir . DIRECTORY_SEPARATOR)) return [$stored, null];
        if (!str_starts_with($input, $catalogId . '/') && self::resolvedPath(['storage_path' => $input]) !== null) return [null, 'admin.digital.legacy_location'];
        return [null, 'admin.digital.file_missing'];
    }

    /** Catalog ids (and store codes) of the configured stores: the store directories below digital_assets_base_dir. */
    private static function catalogIds(): array {
        $ids = [StoreContext::current()->catalogId()];
        foreach ((array)(Bootstrap::stores()['stores'] ?? []) as $code => $store) {
            $ids[] = (string)$code;
            if (is_array($store) && isset($store['catalog_scope'])) $ids[] = (string)$store['catalog_scope'];
        }
        return array_values(array_unique($ids));
    }

    /**
     * storage_path (relative to digital_assets_base_dir) for a path an admin entered for the active catalog: every
     * new or edited asset lives under <base>/<catalog_id>/, so one store can never point a download at another
     * store's files. The input is relative to that store directory (a leading "<catalog_id>/" is accepted as well).
     * Null for an absolute or traversing path, an unsafe catalog id, or an existing file that resolves (symlink)
     * outside the store directory. Rows saved before this rule keep their base-relative path and keep working.
     */
    public static function storePath(string $input, ?string $catalogId = null): ?string {
        $catalogId ??= StoreContext::current()->catalogId();
        $path = str_replace('\\', '/', trim($input));
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $catalogId) || $path === '' || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:\//', $path) || str_contains($path, "\0")) return null;
        if (str_starts_with($path, $catalogId . '/')) $path = substr($path, strlen($catalogId) + 1);
        $segments = explode('/', $path);
        if ($path === '' || in_array('..', $segments, true) || in_array('', $segments, true)) return null;
        $stored = $catalogId . '/' . $path;
        $base = rtrim((string)(self::config()['digital_assets_base_dir'] ?? ''), '/\\');
        $storeDir = $base === '' ? false : realpath($base . DIRECTORY_SEPARATOR . $catalogId);
        $file = $storeDir === false ? false : realpath($storeDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
        if ($file !== false && !str_starts_with($file, $storeDir . DIRECTORY_SEPARATOR)) return null;
        return $stored;
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

    /** The order line is on a credit note whose refund is pending or done. */
    private static function lineCredited(int $orderItemId): bool {
        $stmt = Db::get()->prepare("SELECT 1 FROM credit_note_items cni JOIN order_refunds r ON r.id = cni.refund_id
            WHERE cni.order_item_id = :item AND r.status IN ('pending', 'succeeded') LIMIT 1");
        $stmt->execute([':item' => $orderItemId]);
        return (bool)$stmt->fetchColumn();
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
