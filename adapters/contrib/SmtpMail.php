<?php
/**
 * SmtpMail — minimal SMTP client with AUTH LOGIN over fsockopen.
 *
 * Zero external library dependency. Supports:
 *   - plain SMTP (port 25)
 *   - STARTTLS on submission (port 587)
 *   - implicit TLS (port 465) via tls:// stream wrapper
 *   - AUTH LOGIN (base64) for host/username/password — only over tls/ssl unless
 *     SMTP_ALLOW_PLAINTEXT_AUTH=1 (trusted local relay)
 *   - EHLO with this host's name (SMTP_HELO, default gethostname()); socket reads and
 *     writes time out after SMTP_TIMEOUT seconds
 *   - message built by MimeMessage (RFC 2231 attachment names, folded subject)
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
    private string $encryption;   // '', 'none', 'tls', 'ssl'
    private int $timeout;
    private bool $verifyPeer;
    private string $helo;
    private bool $allowPlaintextAuth;

    public function __construct(array $cfg = []) {
        $env = fn(string $k, $def = '') => getenv($k) !== false ? getenv($k) : ($cfg[strtolower($k)] ?? $def);
        $this->host       = (string)$env('SMTP_HOST', 'localhost');
        $this->port       = (int)$env('SMTP_PORT', 587);
        $this->username   = (string)$env('SMTP_USER', '');
        $this->password   = (string)$env('SMTP_PASS', '');
        $this->encryption = strtolower((string)$env('SMTP_ENCRYPTION', 'tls'));
        $this->timeout    = max(1, (int)$env('SMTP_TIMEOUT', 10));
        $this->verifyPeer = filter_var($env('SMTP_VERIFY_PEER', '1'), FILTER_VALIDATE_BOOLEAN);
        // EHLO names this client (RFC 5321 4.1.1.1), never the server it connects to.
        $helo = trim((string)$env('SMTP_HELO', ''));
        if ($helo === '') $helo = (string)gethostname();
        $this->helo = filter_var($helo, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false ? $helo : 'localhost';
        // Credentials only travel over TLS unless explicitly allowed (local relay on a trusted network).
        $this->allowPlaintextAuth = filter_var($env('SMTP_ALLOW_PLAINTEXT_AUTH', '0'), FILTER_VALIDATE_BOOLEAN);
    }

    public function adapterId(): string { return 'smtp'; }

    /**
     * Strip CR/LF (and bare CR/LF) from a header value to prevent SMTP header
     * injection. Any newline in a header field lets an attacker inject extra
     * headers or a body. Header values must be a single line.
     */
    private static function stripCrlf(string $v): string {
        return MimeMessage::stripCrlf($v);
    }

    public function send(array $msg): array {
        $to       = self::stripCrlf(trim((string)($msg['to'] ?? '')));
        $from     = self::stripCrlf(trim((string)($msg['from'] ?? '')));

        if (!filter_var($to, FILTER_VALIDATE_EMAIL))  return ['status' => 'failed', 'message' => 'invalid recipient', 'raw' => null];
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) return ['status' => 'failed', 'message' => 'invalid sender',    'raw' => null];
        if ($this->username !== '' && !in_array($this->encryption, ['tls', 'ssl'], true) && !$this->allowPlaintextAuth) {
            return ['status' => 'failed', 'message' => 'refusing SMTP AUTH over an unencrypted connection (use tls/ssl or set SMTP_ALLOW_PLAINTEXT_AUTH)', 'raw' => null];
        }

        // Connect
        $scheme = $this->encryption === 'ssl' ? 'ssl://' : '';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $this->verifyPeer, 'verify_peer_name' => $this->verifyPeer]]);
        $fp = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx
        );
        if (!$fp) return ['status' => 'failed', 'message' => "connect failed: {$errstr} ({$errno})", 'raw' => null];
        // Every read/write is bounded too: a server that stops answering cannot hang the worker.
        stream_set_timeout($fp, $this->timeout);

        $transcript = [];
        $expect = function (int $code) use ($fp, &$transcript): bool {
            $line = '';
            while (!feof($fp)) {
                $chunk = fgets($fp, 512);
                if ($chunk === false) {
                    if (!empty(stream_get_meta_data($fp)['timed_out'])) throw new \RuntimeException('SMTP server timed out');
                    break;
                }
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
            $cmd('EHLO ' . $this->helo);
            if (!$expect(250)) throw new \RuntimeException('EHLO failed');

            if ($this->encryption === 'tls') {
                $cmd('STARTTLS');
                if (!$expect(220)) throw new \RuntimeException('STARTTLS failed');
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('TLS handshake failed');
                }
                $cmd('EHLO ' . $this->helo);
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

            $data = MimeMessage::build(['to' => $to, 'from' => $from] + $msg);
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
