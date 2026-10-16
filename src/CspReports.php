<?php
/**
 * Browser CSP violation reports (report-uri and Reporting API report-to).
 *
 * A violated form-action is exactly how card checkout died silently in 1.9.0
 * (P0 2026-10-13): only the browser knew. Reports are stored minimally — directive,
 * blocked origin (never path/query), document path — and feed OperationalMetrics.
 *
 * The endpoint is unauthenticated, so anyone can POST a forged form-action report.
 * Each row therefore carries a salted hash of the reporting client IP, and the
 * form-action metric counts distinct reporters: one source alone cannot raise it
 * past the default critical threshold of 2. Behind a proxy/CDN without trusted_proxies
 * every report has the same client IP, so the raw report count alarms too, at a higher
 * threshold (monitoring.csp_form_action_reports_critical, default 5), but only while
 * trusted_proxies is empty and only when the reports arrived in at least two separate
 * requests (each ingest stores a random request id). That fallback CAN be raised by one
 * client sending a few requests; set trusted_proxies to rely on distinct reporters only.
 *
 * reporter_hash and request_id come from later migrations: until they run, ingest and counts
 * degrade (no reporter/request distinction) instead of failing the endpoint.
 */
final class CspReports
{
    public const MAX_BODY_BYTES = 16384;
    private const MAX_REPORTS_PER_REQUEST = 20;
    private const RETENTION_DAYS = 14;
    private const IGNORED_SCHEMES = ['chrome-extension', 'moz-extension', 'safari-extension', 'safari-web-extension', 'ms-browser-extension'];

    /**
     * @param string|null $reporter reporting client identity (default: the request's client IP), stored only hashed
     * @return int number of stored violations
     */
    public static function ingest(string $body, ?PDO $db = null, ?int $now = null, ?string $reporter = null): int
    {
        if ($body === '' || strlen($body) > self::MAX_BODY_BYTES) return 0;
        $payload = json_decode($body, true);
        if (!is_array($payload)) return 0;

        $reports = [];
        if (isset($payload['csp-report']) && is_array($payload['csp-report'])) {
            $report = $payload['csp-report'];
            $reports[] = [
                'directive' => $report['effective-directive'] ?? $report['violated-directive'] ?? '',
                'blocked' => $report['blocked-uri'] ?? '',
                'document' => $report['document-uri'] ?? '',
                'disposition' => $report['disposition'] ?? 'enforce',
            ];
        } elseif (array_is_list($payload)) {
            foreach (array_slice($payload, 0, self::MAX_REPORTS_PER_REQUEST) as $entry) {
                if (!is_array($entry) || ($entry['type'] ?? '') !== 'csp-violation' || !is_array($entry['body'] ?? null)) continue;
                $reports[] = [
                    'directive' => $entry['body']['effectiveDirective'] ?? '',
                    'blocked' => $entry['body']['blockedURL'] ?? '',
                    'document' => $entry['body']['documentURL'] ?? ($entry['url'] ?? ''),
                    'disposition' => $entry['body']['disposition'] ?? 'enforce',
                ];
            }
        }

        $db ??= Db::get();
        $now ??= time();
        $stored = 0;
        if ($reports === []) return 0;
        $optional = [];
        if (Db::hasColumn('csp_violations', 'reporter_hash', $db)) {
            $optional['reporter_hash'] = self::reporterHash($reporter ?? Security::clientIp());
        }
        if (Db::hasColumn('csp_violations', 'request_id', $db)) $optional['request_id'] = bin2hex(random_bytes(8));
        $optionalColumns = $optional === [] ? '' : ', ' . implode(', ', array_keys($optional));
        $optionalParams = $optional === [] ? '' : ', :' . implode(', :', array_keys($optional));
        $insert = $db->prepare("INSERT INTO csp_violations (store_id, directive, blocked, document_path, disposition, created_at{$optionalColumns})
            VALUES (:store, :directive, :blocked, :path, :disposition, :created{$optionalParams})");
        foreach ($reports as $report) {
            $directive = strtolower(trim((string)$report['directive']));
            // "script-src-elem" etc. are reported per sub-directive; keep the name, drop anything odd.
            if (!preg_match('/^[a-z-]{3,40}$/', $directive)) continue;
            $blocked = self::blockedSource((string)$report['blocked']);
            if ($blocked === null) continue;
            $insert->execute([
                ':store' => StoreContext::current()->id(),
                ':directive' => $directive,
                ':blocked' => $blocked,
                ':path' => substr((string)(parse_url((string)$report['document'], PHP_URL_PATH) ?: ''), 0, 255),
                ':disposition' => strtolower((string)$report['disposition']) === 'report' ? 'report' : 'enforce',
                ':created' => gmdate('Y-m-d H:i:s', $now),
            ] + self::prefixKeys($optional));
            $stored++;
        }
        if ($stored > 0) self::purge($db, $now);
        return $stored;
    }

    /** Drop reports older than the retention window (also run daily by RetentionCleanup). */
    public static function purge(PDO $db, ?int $now = null): int
    {
        $stmt = $db->prepare('DELETE FROM csp_violations WHERE created_at < :cutoff');
        $stmt->execute([':cutoff' => gmdate('Y-m-d H:i:s', ($now ?? time()) - self::RETENTION_DAYS * 86400)]);
        return $stmt->rowCount();
    }

    /** @param array<string, string> $values */
    private static function prefixKeys(array $values): array
    {
        $prefixed = [];
        foreach ($values as $key => $value) $prefixed[':' . $key] = $value;
        return $prefixed;
    }

    /**
     * Salted hash of the reporting client. The endpoint must never fail on it: when the GDPR salt is
     * unusable the reporter is stored empty (all such reports count as one reporter; the raw
     * form-action report count still alarms) rather than as an unsalted, reversible IP hash.
     */
    private static function reporterHash(string $reporter): string
    {
        try {
            return GdprService::hashPii($reporter);
        } catch (Throwable $e) {
            if (class_exists('Logger')) Logger::warning('csp_report.reporter_hash_unavailable', ['error' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * Enforced violations of one store since $since (unix time): total reports, the number of
     * distinct reporters of blocked form-action (repeated or forged reports from one source count once),
     * the raw number of blocked form-action reports and the number of requests that carried them.
     *
     * @return array{total:int, form_action:int, form_action_reports:int, form_action_requests:int}
     */
    public static function counts(PDO $db, string $storeId, int $since): array
    {
        // Before the reporter_hash migration every row counts as one reporter; before request_id,
        // distinct report timestamps approximate separate requests.
        $reporters = Db::hasColumn('csp_violations', 'reporter_hash', $db) ? 'COUNT(DISTINCT reporter_hash)' : '1';
        $requests = Db::hasColumn('csp_violations', 'request_id', $db) ? 'COUNT(DISTINCT request_id)' : 'COUNT(DISTINCT created_at)';
        $stmt = $db->prepare("SELECT directive, COUNT(*) AS n, {$reporters} AS reporters, {$requests} AS requests FROM csp_violations
            WHERE store_id = :store AND disposition = 'enforce' AND created_at >= :since GROUP BY directive");
        $stmt->execute([':store' => $storeId, ':since' => gmdate('Y-m-d H:i:s', $since)]);
        $counts = ['total' => 0, 'form_action' => 0, 'form_action_reports' => 0, 'form_action_requests' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts['total'] += (int)$row['n'];
            if ($row['directive'] === 'form-action') {
                $counts['form_action'] += (int)$row['reporters'];
                $counts['form_action_reports'] += (int)$row['n'];
                $counts['form_action_requests'] += (int)$row['requests'];
            }
        }
        return $counts;
    }

    /** Origin or CSP keyword of the blocked resource; null for browser-extension noise. */
    private static function blockedSource(string $blocked): ?string
    {
        $blocked = trim($blocked);
        if ($blocked === '' || in_array(strtolower($blocked), ['inline', 'eval', 'self', 'data', 'blob', 'wasm-eval', 'trusted-types-policy', 'trusted-types-sink'], true)) {
            return strtolower($blocked);
        }
        $parts = parse_url($blocked);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme === '' || in_array($scheme, self::IGNORED_SCHEMES, true)) return null;
        if (!isset($parts['host'])) return $scheme;
        return substr($scheme . '://' . strtolower((string)$parts['host']) . (isset($parts['port']) ? ':' . (int)$parts['port'] : ''), 0, 255);
    }
}
