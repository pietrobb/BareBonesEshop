<?php
/**
 * RFC 5322 / MIME message builder shared by the mail adapters that put a raw message on the
 * wire (SmtpMail over SMTP DATA, SesMail via SendRawEmail).
 *
 * Message shape: text/plain (+ text/html as multipart/alternative), wrapped in multipart/mixed
 * when there are attachments. Bodies are quoted-printable with CRLF line ends, the subject is a
 * folded run of RFC 2047 encoded-words and attachment file names are quoted (ASCII) or RFC 2231
 * encoded (non-ASCII), so no header value can break out of its line or parameter.
 */
final class MimeMessage
{
    /** Header values are single lines: CR and LF are removed (header injection). */
    public static function stripCrlf(string $v): string
    {
        return str_replace(["\r", "\n"], '', $v);
    }

    /**
     * Body text for the wire: CRLF line ends only (servers reject bare LF) and quoted-printable,
     * which keeps every line under 76 octets — 8bit HTML on one line broke the 998-octet limit.
     */
    public static function qpBody(string $v): string
    {
        return quoted_printable_encode((string)preg_replace('/\r\n|\r|\n/', "\r\n", $v));
    }

    /**
     * RFC 2047 subject as a run of B encoded-words, one per folded line (≤ 77 octets with "Subject: ").
     * Whitespace between encoded-words is dropped on decode, so the subject round-trips exactly.
     */
    public static function encodeSubject(string $v): string
    {
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

    /**
     * A MIME parameter carrying a file name: name="x.pdf" with '"' and '\' escaped for printable
     * ASCII, otherwise RFC 2231 name*=UTF-8''%C5%BD... CR/LF and other controls never survive.
     */
    public static function filenameParameter(string $parameter, string $filename): string
    {
        $filename = (string)preg_replace('/[\x00-\x1F\x7F]/', '', $filename);
        if ($filename === '') $filename = 'attachment.bin';
        if (preg_match('/^[\x20-\x7E]*$/', $filename)) {
            return $parameter . '="' . addcslashes($filename, '"\\') . '"';
        }
        return $parameter . "*=UTF-8''" . rawurlencode($filename);
    }

    /**
     * The complete message (headers, blank line, body) with CRLF line ends. No SMTP dot-stuffing:
     * the SMTP transport adds it. $msg: to, from, subject, html, text, reply_to, headers, attachments.
     */
    public static function build(array $msg): string
    {
        $to       = self::stripCrlf(trim((string)($msg['to'] ?? '')));
        $from     = self::stripCrlf(trim((string)($msg['from'] ?? '')));
        $subject  = self::stripCrlf((string)($msg['subject'] ?? ''));
        $html     = (string)($msg['html'] ?? '');
        $text     = (string)($msg['text'] ?? strip_tags($html));
        $replyTo  = self::stripCrlf((string)($msg['reply_to'] ?? ''));
        $headers  = (array)($msg['headers'] ?? []);
        $attachments = (array)($msg['attachments'] ?? []);

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
            $name = (string)preg_replace('/[^A-Za-z0-9-]/', '', (string)$k);
            if ($name === '') continue;
            $data .= $name . ": " . self::stripCrlf((string)$v) . "\r\n";
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

        if (empty($attachments)) return $data . $bodyPart;

        $data .= "Content-Type: multipart/mixed; boundary=\"{$mixBoundary}\"\r\n\r\n";
        $data .= "--{$mixBoundary}\r\n" . $bodyPart;
        foreach ($attachments as $att) {
            $fname = (string)($att['filename'] ?? 'attachment.bin');
            $ctype = (string)($att['content_type'] ?? 'application/octet-stream');
            if (!preg_match('#^[A-Za-z0-9!\#$&^_.+-]+/[A-Za-z0-9!\#$&^_.+-]+$#', $ctype)) $ctype = 'application/octet-stream';
            $b64   = chunk_split(base64_encode((string)($att['data'] ?? '')));
            $data .= "--{$mixBoundary}\r\n"
                   . "Content-Type: {$ctype}; " . self::filenameParameter('name', $fname) . "\r\n"
                   . "Content-Transfer-Encoding: base64\r\n"
                   . "Content-Disposition: attachment; " . self::filenameParameter('filename', $fname) . "\r\n\r\n"
                   . $b64 . "\r\n";
        }
        return $data . "--{$mixBoundary}--\r\n";
    }
}
