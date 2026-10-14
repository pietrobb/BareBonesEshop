<?php
/**
 * BareBones Shop — MailService (orchestration)
 *
 * Responsibilities:
 *   1. Pick the configured MailAdapter (LogMail / SmtpMail / SesMail).
 *   2. Load a template for the requested locale (with fallback order).
 *   3. Render placeholders (reusing worker.php template syntax:
 *      {{var}}, {{#if var}}...{{/if}}, {{#each items}}...{{/each}}).
 *   4. Build a final message (From, subject, html + text parts).
 *   5. Write an email_log row BEFORE sending, then update status.
 *   6. Return a structured result for worker / admin callers.
 *
 * Template layout on disk:
 *   templates/emails/{locale}/{template}.subject.txt  (one line)
 *   templates/emails/{locale}/{template}.html
 *   templates/emails/{locale}/{template}.txt          (optional; derived from HTML if missing)
 *
 * Fallback chain for missing template: {locale} -> en.
 */
class MailService {
    private static ?MailAdapter $adapterOverride = null;
    private const SECRET_LINK_VARS = ['reset_url', 'verify_url', 'cart_url', 'unsubscribe_url', 'download_links'];

    /** For tests: inject a specific adapter (bypasses env). */
    public static function useAdapter(?MailAdapter $a): void {
        self::$adapterOverride = $a;
    }

    /** Resolve the active MailAdapter. Priority: override → stable registry code → log. */
    public static function adapter(?string $providerCode = null): MailAdapter {
        if (self::$adapterOverride) return self::$adapterOverride;

        $id = $providerCode ?? strtolower((string)(getenv('MAIL_ADAPTER') ?: Adapters::defaultProviderCode('mail')));
        return Adapters::mail($id);
    }

    /**
     * Send an email identified by template + locale + vars.
     *
     * @param string   $template Template name (e.g. "order_confirmation")
     * @param string   $to       Recipient address
     * @param string   $locale   Preferred locale (sk/en/de/…)
     * @param array    $vars     Template variables
     * @param int|null $orderId  Optional order link (for audit + resend)
     *
     * @return array {
     *   status:  'sent' | 'failed' | 'skipped',
     *   log_id:  int,
     *   subject: string,
     *   adapter: string,
     *   error:   string,
     * }
     */
    public static function send(
        string $template,
        string $to,
        string $locale = '',
        array $vars = [],
        ?int $orderId = null,
        array $attachments = [],
        ?string $providerCode = null
    ): array {
        $to = strtolower(trim($to));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'skipped', 'log_id' => 0, 'subject' => '', 'adapter' => '', 'error' => 'invalid recipient'];
        }
        if (!preg_match('/^[a-z0-9_-]{1,80}$/i', $template)) {
            return ['status' => 'skipped', 'log_id' => 0, 'subject' => '', 'adapter' => '', 'error' => 'invalid template'];
        }
        if (!self::recipientAllowed($to, $template)) {
            return ['status' => 'skipped', 'log_id' => 0, 'subject' => '', 'adapter' => '', 'error' => 'recipient rate limit'];
        }
        $locale = $locale !== '' ? $locale : self::defaultLocale();

        $rendered = self::render($template, $locale, $vars);

        $adapter = self::adapter($providerCode);
        $from = strtolower(trim((string)(getenv('MAIL_FROM') ?: 'noreply@localhost.invalid')));
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'skipped', 'log_id' => 0, 'subject' => '', 'adapter' => $adapter->adapterId(), 'error' => 'invalid sender'];
        }
        $replyTo = strtolower(trim((string)(getenv('MAIL_REPLY_TO') ?: '')));
        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $replyTo = '';

        // Log row BEFORE send attempt (so failures are visible).
        $logId = self::logInsert($to, $template, $locale, $rendered['subject'], $adapter->adapterId(), $orderId, $vars);

        $msg = [
            'to'          => $to,
            'from'        => $from,
            'subject'     => $rendered['subject'],
            'html'        => $rendered['html'],
            'text'        => $rendered['text'],
            'reply_to'    => $replyTo,
            'attachments' => self::normalizeAttachments($attachments),
            'headers'  => [
                'X-Mailer'   => 'BareBonesShop',
                'X-Template' => $template,
                'X-Locale'   => $locale,
            ],
        ];

        try {
            $result = $adapter->send($msg);
        } catch (Throwable $e) {
            $result = ['status' => 'failed', 'message' => 'Mail adapter failed'];
        }
        self::logUpdate($logId, $result['status'] ?? 'failed', $result['message'] ?? '');
        if (($result['status'] ?? '') !== 'sent' && $template !== 'merchant_incident') {
            IncidentMonitor::report('mail.delivery_failed', 'mail', 'critical', $orderId !== null ? ['order_id' => $orderId] : []);
        }

        return [
            'status'  => $result['status'] ?? 'failed',
            'log_id'  => $logId,
            'subject' => $rendered['subject'],
            'adapter' => $adapter->adapterId(),
            'error'   => $result['message'] ?? '',
        ];
    }

    /**
     * Re-send an existing email_log row (same template / recipient / vars).
     * Creates a NEW email_log entry — the original row stays immutable.
     */
    public static function resend(int $logId, ?int $orderId = null): array {
        $sql = "SELECT * FROM email_log WHERE id = :id AND store_id = :store";
        $params = [':id' => $logId, ':store' => StoreContext::current()->id()];
        if ($orderId !== null) {
            $sql .= " AND order_id = :order_id";
            $params[':order_id'] = $orderId;
        }
        $stmt = Db::get()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            return ['status' => 'skipped', 'log_id' => 0, 'subject' => '', 'adapter' => '', 'error' => 'log entry not found'];
        }
        $vars = json_decode((string)($row['payload'] ?? '{}'), true) ?: [];
        if (!empty($vars['_redacted'])) {
            return ['status' => 'skipped', 'log_id' => 0, 'subject' => '', 'adapter' => '', 'error' => 'one-time links are not stored; trigger a fresh email instead'];
        }
        return self::send(
            (string)$row['template'],
            (string)$row['recipient'],
            (string)$row['locale'],
            $vars,
            $row['order_id'] !== null ? (int)$row['order_id'] : null
        );
    }

    /**
     * Render a template for the given locale (with fallback).
     * Returns ['subject' => ..., 'html' => ..., 'text' => ...].
     */
    public static function render(string $template, string $locale, array $vars): array {
        // The active store's admin override for this exact locale wins; otherwise the files with their locale fallback.
        $source  = EmailTemplates::source($template, $locale);
        $html    = $source['html'] ?? "<p>{$template}</p>";
        $subject = $source['subject'] ?? $template;
        $text    = $source['text'] ?? '';

        // Render in template-engine order used by worker.php: each → if → vars
        $html    = self::renderString($html, $vars);
        // Subject and plain-text body are not HTML: values go in verbatim (no &amp; in links).
        $subject = trim(str_replace(["\r", "\n"], ' ', self::renderString($subject, $vars, false)));
        if ($text !== '') {
            $text = self::renderString($text, $vars, false);
        } else {
            $text = self::textFromHtml($html);
        }

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    /** Plain-text part derived from rendered HTML; the HTML escaping is decoded so links survive (p=reset&token=..., not &amp;token=). */
    public static function textFromHtml(string $html): string {
        return trim(preg_replace('/\s+\n/', "\n", html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** Expand {{#each}} / {{#if}} / {{var}} — matches worker.php renderTemplate. */
    public static function renderString(string $tpl, array $vars, bool $html = true): string {
        $value = static fn($v): string => $html ? htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') : (string)$v;
        // {{#each key}}...{{/each}}
        $tpl = preg_replace_callback('/\{\{#each\s+(\w+)\}\}(.*?)\{\{\/each\}\}/s', function ($m) use ($vars, $value) {
            $items = $vars[$m[1]] ?? [];
            if (!is_array($items)) return '';
            $out = '';
            foreach ($items as $item) {
                $row = $m[2];
                if (is_array($item)) {
                    foreach ($item as $k => $v) {
                        $row = str_replace('{{' . $k . '}}', $value($v), $row);
                    }
                } else {
                    $row = str_replace('{{this}}', $value($item), $row);
                }
                $out .= $row;
            }
            return $out;
        }, $tpl);

        // {{#if key}}...{{/if}}
        $tpl = preg_replace_callback('/\{\{#if\s+(\w+)\}\}(.*?)\{\{\/if\}\}/s', function ($m) use ($vars) {
            return !empty($vars[$m[1]]) ? $m[2] : '';
        }, $tpl);

        // {{var}}
        foreach ($vars as $k => $v) {
            if (is_scalar($v)) {
                $tpl = str_replace('{{' . $k . '}}', $value($v), $tpl);
            }
        }
        return preg_replace('/\{\{[^}]+\}\}/', '', $tpl);
    }

    // ─── Internal helpers ────────────────────────────────────────
    private static function recipientAllowed(string $recipient, string $template): bool {
        $limits = [
            'account_password_reset' => [3, 3600],
            'account_email_verification' => [3, 3600],
            'account_welcome' => [2, 86400],
            'cart_reminder' => [1, 86400],
            // Configured merchant inbox, deduplicated per order: a busy hour must not drop orders.
            'merchant_order_created' => [1000, 3600],
            'merchant_order_paid' => [1000, 3600],
            // Crossing-only alerts are already deduplicated per SKU; a busy hour must not drop them.
            'merchant_stock_low' => [1000, 3600],
        ];
        [$max, $window] = $limits[$template] ?? [10, 3600];
        $since = gmdate('Y-m-d H:i:s', time() - $window);
        $stmt = Db::get()->prepare(
            "SELECT COUNT(*) FROM email_log
             WHERE recipient = :recipient AND template = :template AND created_at >= :since"
        );
        $stmt->execute([':recipient' => $recipient, ':template' => $template, ':since' => $since]);
        return (int)$stmt->fetchColumn() < $max;
    }

    private static function defaultLocale(): string {
        $stores = @json_decode((string)file_get_contents(__DIR__ . '/../stores.json'), true) ?: [];
        return (string)($stores['default_locale'] ?? 'en');
    }

    /**
     * Normalize attachments into a uniform shape:
     *   [{ 'filename' => 'X.pdf', 'content_type' => 'application/pdf', 'data' => <bytes> }, ...]
     *
     * Accepted input items:
     *   - string  : absolute file path (loaded from disk)
     *   - array   : { 'path' => '...' }  OR  { 'filename' => '...', 'data' => '...', 'content_type' => '...' }
     */
    private static function normalizeAttachments(array $attachments): array {
        $out = [];
        foreach ($attachments as $att) {
            if (is_string($att)) {
                $att = ['path' => $att];
            }
            if (!is_array($att)) continue;
            if (!empty($att['path']) && is_file($att['path'])) {
                $data = (string)file_get_contents($att['path']);
                $out[] = [
                    'filename'     => (string)($att['filename'] ?? basename($att['path'])),
                    'content_type' => (string)($att['content_type'] ?? self::guessContentType($att['path'])),
                    'data'         => $data,
                ];
                continue;
            }
            if (isset($att['data'])) {
                $out[] = [
                    'filename'     => (string)($att['filename'] ?? 'attachment.bin'),
                    'content_type' => (string)($att['content_type'] ?? 'application/octet-stream'),
                    'data'         => (string)$att['data'],
                ];
            }
        }
        return $out;
    }

    private static function guessContentType(string $path): string {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'pdf'  => 'application/pdf',
            'png'  => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'txt'  => 'text/plain',
            'html', 'htm' => 'text/html',
            'csv'  => 'text/csv',
            'xml'  => 'application/xml',
            'zip'  => 'application/zip',
            default => 'application/octet-stream',
        };
    }

    private static function logInsert(string $to, string $tpl, string $locale, string $subject, string $adapterId, ?int $orderId, array $vars): int {
        $stmt = Db::get()->prepare("
            INSERT INTO email_log (order_id, recipient, template, locale, subject, adapter, status, payload, store_id, correlation_id)
            VALUES (:order_id, :recipient, :template, :locale, :subject, :adapter, 'pending', :payload, :store, :correlation)
        ");
        $stmt->execute([
            ':order_id'  => $orderId,
            ':recipient' => $to,
            ':template'  => $tpl,
            ':locale'    => $locale,
            ':subject'   => $subject,
            ':adapter'   => $adapterId,
            ':payload'   => json_encode(self::loggableVars($vars), JSON_UNESCAPED_UNICODE),
            ':store'     => StoreContext::current()->id(),
            ':correlation' => Logger::correlationId(),
        ]);
        return (int)Db::get()->lastInsertId();
    }

    /**
     * Bearer links (password reset, cart recovery/opt-out, downloads) must not outlive the
     * mail: their tokens are stored hashed, so the log keeps only a redaction marker.
     */
    private static function loggableVars(array $vars): array {
        $redacted = false;
        foreach (self::SECRET_LINK_VARS as $key) {
            if (!array_key_exists($key, $vars)) continue;
            $redacted = true;
            $vars[$key] = is_array($vars[$key])
                ? array_map(static fn($item) => is_array($item) && array_key_exists('url', $item) ? ['url' => '[redacted]'] + $item : '[redacted]', $vars[$key])
                : '[redacted]';
        }
        if ($redacted) $vars['_redacted'] = true;
        return $vars;
    }

    private static function logUpdate(int $id, string $status, string $error): void {
        $sentAt = $status === 'sent' ? (Db::isSqlite() ? "datetime('now')" : 'NOW()') : 'NULL';
        $stmt = Db::get()->prepare("
            UPDATE email_log
               SET status = :status,
                   error  = :error,
                   sent_at = {$sentAt}
             WHERE id = :id
        ");
        $stmt->execute([
            ':status' => $status,
            ':error'  => $error,
            ':id'     => $id,
        ]);
    }
}
