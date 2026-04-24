<?php
/**
 * BareBones Shop — Mail Adapter Interface
 *
 * Pluggable mail transport. Implementations live in adapters/contrib/:
 *   - LogMail     — captures to logs/emails.log (dev / test default)
 *   - SmtpMail    — real SMTP over fsockopen with AUTH LOGIN
 *   - SesMail     — AWS SES via SendEmail HTTP API (signed)
 *
 * The orchestration layer (MailService) picks the adapter based on
 * the MAIL_ADAPTER env variable and passes a fully rendered message.
 */
interface MailAdapter {
    /**
     * Send a rendered email message.
     *
     * @param array $msg Associative array with keys:
     *                   - to       (string)          recipient address
     *                   - from     (string)          sender address
     *                   - subject  (string)          subject line
     *                   - html     (string)          HTML body (may be empty)
     *                   - text     (string)          plain-text fallback
     *                   - reply_to (string|null)     optional Reply-To
     *                   - headers  (array<string,string>) extra headers
     *
     * @return array {
     *   status:  'sent' | 'failed',
     *   message: string  (adapter-specific detail or empty on success),
     *   raw:     mixed   (provider response / debug info)
     * }
     */
    public function send(array $msg): array;

    /** Short adapter id used in email_log.adapter column (e.g. "log", "smtp", "ses"). */
    public function adapterId(): string;
}
