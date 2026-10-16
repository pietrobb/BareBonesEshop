<?php
/**
 * SmtpMail — minimal SMTP client with AUTH LOGIN over fsockopen.
 *
 * Zero external library dependency. Supports:
 *   - plain SMTP (port 25)
 *   - STARTTLS on submission (port 587)
 *   - implicit TLS (port 465) via tls:// stream wrapper
 *   - AUTH LOGIN (base64) for host/username/password
 *
 * Non-goals: mass-mail features, DKIM, S/MIME. Those belong to a real
 * provider (SES / Mailgun) — use those adapters instead. This adapter
 * covers 99% of small-eshop scenarios: one message at a time, transactional.
 */
class SmtpMail implements MailAdapter {
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $encryption;   // '', 'tls', 'ssl'
    private int $timeout;
    private bool $verifyPeer;

    public function __construct(array $cfg = []) {
        $env = fn(string $k, $def = '') => getenv($k) !== false ? getenv($k) : ($cfg[strtolower($k)] ?? $def);
        $this->host       = (string)$env('SMTP_HOST', 'localhost');
        $this->port       = (int)$env('SMTP_PORT', 587);
        $this->username   = (string)$env('SMTP_USER', '');
        $this->password   = (string)$env('SMTP_PASS', '');
        $this->encryption = strtolower((string)$env('SMTP_ENCRYPTION', 'tls'));
        $this->timeout    = (int)$env('SMTP_TIMEOUT', 10);
        $this->verifyPeer = filter_var($env('SMTP_VERIFY_PEER', '1'), FILTER_VALIDATE_BOOLEAN);
    }

    public function adapterId(): string { return 'smtp'; }

    /**
     * Strip CR/LF (and bare CR/LF) from a header value to prevent SMTP header
     * injection. Any newline in a header field lets an attacker inject extra
     * headers or a body. Header values must be a single line.
     */
    private static function stripCrlf(string $v): string {
        return str_replace(["\r", "\n"], '', $v);
    }

    /**
     * Body text for the wire: CRLF line ends only (servers reject bare LF) and quoted-printable,
     * which keeps every line under 76 octets — 8bit HTML on one line broke the 998-octet limit.
     */
    private static function qpBody(string $v): string {
        return quoted_printable_encode((string)preg_replace('/\r\n|\r|\n/', "\r\n", $v));
    }

    /**
     * RFC 2047 subject as a run of B encoded-words, one per folded line (≤ 77 octets with "Subject: ").
     * Whitespace between encoded-words is dropped on decode, so the subject round-trips exactly.
     */
    private static function encodeSubject(string $v): string {
        if ($v === '') return '';
        $words = [];
        $chunk = '';
        foreach (mb_str_split($v, 1, 'UTF-8') as $ch) {
            if ($chunk !== '' && strlen(base64_encode($chunk . $ch)) > 56) {
                $words[] = $chunk;
                $chunk = '';
            }
            $chunk .= $ch;
        }
        $words[] = $chunk;
        return implode("\r\n ", array_map(static fn(string $w): string => '=?UTF-8?B?' . base64_encode($w) . '?=', $words));
    }

    public function send(array $msg): array {
        $to       = self::stripCrlf(trim((string)($msg['to'] ?? '')));
        $from     = self::stripCrlf(trim((string)($msg['from'] ?? '')));
        $subject  = self::stripCrlf((string)($msg['subject'] ?? ''));
        $html     = (string)($msg['html'] ?? '');
        $text     = (string)($msg['text'] ?? strip_tags($html));
        $replyTo  = self::stripCrlf((string)($msg['reply_to'] ?? ''));
        $headers  = (array)($msg['headers'] ?? []);
        $attachments = (array)($msg['attachments'] ?? []);

        if (!filter_var($to, FILTER_VALIDATE_EMAIL))  return ['status' => 'failed', 'message' => 'invalid recipient', 'raw' => null];
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) return ['status' => 'failed', 'message' => 'invalid sender',    'raw' => null];

        // Connect
        $scheme = $this->encryption === 'ssl' ? 'ssl://' : '';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $this->verifyPeer, 'verify_peer_name' => $this->verifyPeer]]);
        $fp = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx
        );
        if (!$fp) return ['status' => 'failed', 'message' => "connect failed: {$errstr} ({$errno})", 'raw' => null];

        $transcript = [];
        $expect = function (int $code) use ($fp, &$transcript): bool {
            $line = '';
            while (!feof($fp)) {
                $chunk = fgets($fp, 512);
                if ($chunk === false) break;
                $line .= $chunk;
                $transcript[] = trim($chunk);
                if (preg_match('/^\d{3} /', $chunk)) break;
            }
            return (int)substr(ltrim($line), 0, 3) === $code;
        };
        $cmd = function (string $line) use ($fp, &$transcript) {
            fwrite($fp, $line . "\r\n");
            $transcript[] = '>> ' . $line;
        };

        try {
            if (!$expect(220)) throw new \RuntimeException('no 220 greeting');
            $cmd('EHLO ' . ($this->host ?: 'localhost'));
            if (!$expect(250)) throw new \RuntimeException('EHLO failed');

            if ($this->encryption === 'tls') {
                $cmd('STARTTLS');
                if (!$expect(220)) throw new \RuntimeException('STARTTLS failed');
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('TLS handshake failed');
                }
                $cmd('EHLO ' . ($this->host ?: 'localhost'));
                if (!$expect(250)) throw new \RuntimeException('EHLO (post-TLS) failed');
            }

            if ($this->username !== '') {
                $cmd('AUTH LOGIN');
                if (!$expect(334)) throw new \RuntimeException('AUTH LOGIN not accepted');
                $cmd(base64_encode($this->username));
                if (!$expect(334)) throw new \RuntimeException('username not accepted');
                $cmd(base64_encode($this->password));
                if (!$expect(235)) throw new \RuntimeException('password not accepted');
            }

            $cmd('MAIL FROM:<' . $from . '>');
            if (!$expect(250)) throw new \RuntimeException('MAIL FROM rejected');
            $cmd('RCPT TO:<' . $to . '>');
            if (!$expect(250)) throw new \RuntimeException('RCPT TO rejected');
            $cmd('DATA');
            if (!$expect(354)) throw new \RuntimeException('DATA not accepted');

            $altBoundary = 'bbe-alt-' . bin2hex(random_bytes(8));
            $mixBoundary = 'bbe-mix-' . bin2hex(random_bytes(8));
            // RFC 2047 encoded-words are folded (75 chars each) instead of one unbounded line.
            $data = "From: {$from}\r\n"
                  . "To: {$to}\r\n"
                  . "Subject: " . self::encodeSubject($subject) . "\r\n"
                  . "Date: " . date('r') . "\r\n"
                  . "MIME-Version: 1.0\r\n";
            $hasMessageId = false;
            foreach (array_keys($headers) as $k) $hasMessageId = $hasMessageId || strcasecmp(trim((string)$k), 'Message-ID') === 0;
            if (!$hasMessageId) {
                // Without a Message-ID spam filters score the mail down and threading breaks.
                $data .= 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . substr((string)strrchr($from, '@'), 1) . ">\r\n";
            }
            if ($replyTo) $data .= "Reply-To: {$replyTo}\r\n";
            foreach ($headers as $k => $v) {
                $data .= self::stripCrlf((string)$k) . ": " . self::stripCrlf((string)$v) . "\r\n";
            }

            // Build the message body part (text-only or multipart/alternative).
            $bodyPart = '';
            $qp = "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            if ($html !== '') {
                $bodyPart .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";
                $bodyPart .= "--{$altBoundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n{$qp}" . self::qpBody($text) . "\r\n";
                $bodyPart .= "--{$altBoundary}\r\nContent-Type: text/html; charset=UTF-8\r\n{$qp}" . self::qpBody($html) . "\r\n";
                $bodyPart .= "--{$altBoundary}--\r\n";
            } else {
                $bodyPart .= "Content-Type: text/plain; charset=UTF-8\r\n{$qp}" . self::qpBody($text) . "\r\n";
            }

            if (!empty($attachments)) {
                $data .= "Content-Type: multipart/mixed; boundary=\"{$mixBoundary}\"\r\n\r\n";
                $data .= "--{$mixBoundary}\r\n" . $bodyPart;
                foreach ($attachments as $att) {
                    $fname = self::stripCrlf((string)($att['filename'] ?? 'attachment.bin'));
                    $ctype = self::stripCrlf((string)($att['content_type'] ?? 'application/octet-stream'));
                    $b64   = chunk_split(base64_encode((string)($att['data'] ?? '')));
                    $data .= "--{$mixBoundary}\r\n"
                           . "Content-Type: {$ctype}; name=\"{$fname}\"\r\n"
                           . "Content-Transfer-Encoding: base64\r\n"
                           . "Content-Disposition: attachment; filename=\"{$fname}\"\r\n\r\n"
                           . $b64 . "\r\n";
                }
                $data .= "--{$mixBoundary}--\r\n";
            } else {
                $data .= $bodyPart;
            }
            // Dot-stuffing for lines starting with "."
            $data = preg_replace('/^\./m', '..', $data);
            $cmd($data . "\r\n.");
            if (!$expect(250)) throw new \RuntimeException('message body rejected');

            $cmd('QUIT');
            fclose($fp);
            return ['status' => 'sent', 'message' => '', 'raw' => $transcript];
        } catch (\Throwable $e) {
            @fclose($fp);
            return ['status' => 'failed', 'message' => $e->getMessage(), 'raw' => $transcript];
        }
    }
}
