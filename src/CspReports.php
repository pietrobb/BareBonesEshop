<?php
/**
 * Browser CSP violation reports (report-uri and Reporting API report-to).
 *
 * A violated form-action is exactly how card checkout died silently in 1.9.0
 * (P0 2026-10-13): only the browser knew. Reports are stored minimally — directive,
 * blocked origin (never path/query), document path — and feed OperationalMetrics.
 */
final class CspReports
{
    public const MAX_BODY_BYTES = 16384;
    private const MAX_REPORTS_PER_REQUEST = 20;
    private const RETENTION_DAYS = 14;
    private const IGNORED_SCHEMES = ['chrome-extension', 'moz-extension', 'safari-extension', 'safari-web-extension', 'ms-browser-extension'];

    /** @return int number of stored violations */
    public static function ingest(string $body, ?PDO $db = null, ?int $now = null): int
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
        $insert = $db->prepare('INSERT INTO csp_violations (store_id, directive, blocked, document_path, disposition, created_at)
            VALUES (:store, :directive, :blocked, :path, :disposition, :created)');
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
            ]);
            $stored++;
        }
        if ($stored > 0) {
            $db->prepare('DELETE FROM csp_violations WHERE created_at < :cutoff')
               ->execute([':cutoff' => gmdate('Y-m-d H:i:s', $now - self::RETENTION_DAYS * 86400)]);
        }
        return $stored;
    }

    /** @return array{total:int, form_action:int} enforced violations of one store since $since (unix time). */
    public static function counts(PDO $db, string $storeId, int $since): array
    {
        $stmt = $db->prepare("SELECT directive, COUNT(*) AS n FROM csp_violations
            WHERE store_id = :store AND disposition = 'enforce' AND created_at >= :since GROUP BY directive");
        $stmt->execute([':store' => $storeId, ':since' => gmdate('Y-m-d H:i:s', $since)]);
        $counts = ['total' => 0, 'form_action' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts['total'] += (int)$row['n'];
            if ($row['directive'] === 'form-action') $counts['form_action'] += (int)$row['n'];
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
