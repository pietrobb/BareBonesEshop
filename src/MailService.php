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
 * Fallback chain for missing template: {locale} -> en -> sk.
 */
class MailService {
    private static ?MailAdapter $adapterOverride = null;
    private static array $fallbackLocales = ['en', 'sk'];

    /** For tests: inject a specific adapter (bypasses env). */
    public static function useAdapter(?MailAdapter $a): void {
        self::$adapterOverride = $a;
    }

    /** Resolve the active MailAdapter. Priority: override → MAIL_ADAPTER env → LogMail. */
    public static function adapter(): MailAdapter {
        if (self::$adapterOverride) return self::$adapterOverride;

        $id = strtolower((string)(getenv('MAIL_ADAPTER') ?: 'log'));
        return match ($id) {
            'smtp' => new SmtpMail(),
            'ses'  => new SesMail(),
            default => new LogMail(),
        };
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
        ?int $orderId = null
    ): array {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'skipped', 'log_id' => 0, 'subject' => '', 'adapter' => '', 'error' => 'invalid recipient'];
        }
        $locale = $locale !== '' ? $locale : self::defaultLocale();

        $rendered = self::render($template, $locale, $vars);

        $adapter = self::adapter();
        $from    = (string)(getenv('MAIL_FROM') ?: ('noreply@' . (($_SERVER['SERVER_NAME'] ?? 'localhost'))));

        // Log row BEFORE send attempt (so failures are visible).
        $logId = self::logInsert($to, $template, $locale, $rendered['subject'], $adapter->adapterId(), $orderId, $vars);

        $msg = [
            'to'       => $to,
            'from'     => $from,
            'subject'  => $rendered['subject'],
            'html'     => $rendered['html'],
            'text'     => $rendered['text'],
            'reply_to' => (string)(getenv('MAIL_REPLY_TO') ?: ''),
            'headers'  => [
                'X-Mailer'   => 'BareBonesShop',
                'X-Template' => $template,
                'X-Locale'   => $locale,
            ],
        ];

        $result = $adapter->send($msg);
        self::logUpdate($logId, $result['status'] ?? 'failed', $result['message'] ?? '');

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
    public static function resend(int $logId): array {
        $stmt = Db::get()->prepare("SELECT * FROM email_log WHERE id = :id");
        $stmt->execute([':id' => $logId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['status' => 'skipped', 'log_id' => 0, 'subject' => '', 'adapter' => '', 'error' => 'log entry not found'];
        }
        $vars = json_decode((string)($row['payload'] ?? '{}'), true) ?: [];
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
        $baseDir = self::templateDir();
        $locales = array_unique(array_merge([$locale], self::$fallbackLocales));

        $htmlFile = $subjectFile = $textFile = null;
        foreach ($locales as $loc) {
            $h = "{$baseDir}/{$loc}/{$template}.html";
            if ($htmlFile === null && file_exists($h)) $htmlFile = $h;
            $s = "{$baseDir}/{$loc}/{$template}.subject.txt";
            if ($subjectFile === null && file_exists($s)) $subjectFile = $s;
            $t = "{$baseDir}/{$loc}/{$template}.txt";
            if ($textFile === null && file_exists($t)) $textFile = $t;
        }

        $html    = $htmlFile    ? (string)file_get_contents($htmlFile)    : "<p>{$template}</p>";
        $subject = $subjectFile ? trim((string)file_get_contents($subjectFile)) : $template;
        $text    = $textFile    ? (string)file_get_contents($textFile)    : '';

        // Render in template-engine order used by worker.php: each → if → vars
        $html    = self::renderString($html, $vars);
        $subject = self::renderString($subject, $vars);
        if ($text !== '') {
            $text = self::renderString($text, $vars);
        } else {
            // Fallback plain text from HTML
            $text = trim(preg_replace('/\s+\n/', "\n", strip_tags($html)));
        }

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    /** Expand {{#each}} / {{#if}} / {{var}} — matches worker.php renderTemplate. */
    public static function renderString(string $tpl, array $vars): string {
        // {{#each key}}...{{/each}}
        $tpl = preg_replace_callback('/\{\{#each\s+(\w+)\}\}(.*?)\{\{\/each\}\}/s', function ($m) use ($vars) {
            $items = $vars[$m[1]] ?? [];
            if (!is_array($items)) return '';
            $out = '';
            foreach ($items as $item) {
                $row = $m[2];
                if (is_array($item)) {
                    foreach ($item as $k => $v) {
                        $row = str_replace('{{' . $k . '}}', htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), $row);
                    }
                } else {
                    $row = str_replace('{{this}}', htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8'), $row);
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
                $tpl = str_replace('{{' . $k . '}}', htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), $tpl);
            }
        }
        return preg_replace('/\{\{[^}]+\}\}/', '', $tpl);
    }

    // ─── Internal helpers ────────────────────────────────────────
    private static function defaultLocale(): string {
        $stores = @json_decode((string)file_get_contents(__DIR__ . '/../stores.json'), true) ?: [];
        return (string)($stores['default_locale'] ?? 'sk');
    }

    private static function templateDir(): string {
        return realpath(__DIR__ . '/..') . '/templates/emails';
    }

    private static function logInsert(string $to, string $tpl, string $locale, string $subject, string $adapterId, ?int $orderId, array $vars): int {
        $stmt = Db::get()->prepare("
            INSERT INTO email_log (order_id, recipient, template, locale, subject, adapter, status, payload)
            VALUES (:order_id, :recipient, :template, :locale, :subject, :adapter, 'pending', :payload)
        ");
        $stmt->execute([
            ':order_id'  => $orderId,
            ':recipient' => $to,
            ':template'  => $tpl,
            ':locale'    => $locale,
            ':subject'   => $subject,
            ':adapter'   => $adapterId,
            ':payload'   => json_encode($vars, JSON_UNESCAPED_UNICODE),
        ]);
        return (int)Db::get()->lastInsertId();
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
