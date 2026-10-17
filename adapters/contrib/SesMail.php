<?php
/**
 * SesMail — AWS SES SendRawEmail via HTTPS (SigV4 signed, no SDK required).
 *
 * Uses the classic SES Query API `SendRawEmail` action with AWS Signature Version 4 auth
 * (derived signing key). One POST per email. The raw MIME message comes from MimeMessage (the
 * same builder SmtpMail uses), so attachments (invoice PDFs) and custom headers
 * (List-Unsubscribe) are delivered; plain `SendEmail` silently dropped both.
 *
 * Config (env or ctor args):
 *   MAIL_SES_REGION      e.g. "eu-central-1"
 *   MAIL_SES_KEY         AWS_ACCESS_KEY_ID
 *   MAIL_SES_SECRET      AWS_SECRET_ACCESS_KEY
 *
 * IMPORTANT: this adapter requires internet + configured SES identity.
 * For dev/test use LogMail. For production without AWS, use SmtpMail
 * pointed at whatever SMTP relay you prefer (SES SMTP, Mailgun, Postmark).
 */
class SesMail implements MailAdapter {
    private string $region;
    private string $accessKey;
    private string $secretKey;
    /** @var null|callable(string $url, string $headers, string $body): (string|false) test transport */
    private $transport;

    public function __construct(array $cfg = [], ?callable $transport = null) {
        $this->transport = $transport;
        $env = fn(string $k, $d = '') => getenv($k) !== false ? getenv($k) : ($cfg[strtolower($k)] ?? $d);
        $this->region    = (string)$env('MAIL_SES_REGION', 'eu-central-1');
        $this->accessKey = (string)$env('MAIL_SES_KEY', '');
        $this->secretKey = (string)$env('MAIL_SES_SECRET', '');
    }

    public function adapterId(): string { return 'ses'; }

    public function send(array $msg): array {
        if ($this->accessKey === '' || $this->secretKey === '') {
            return ['status' => 'failed', 'message' => 'SES credentials not configured', 'raw' => null];
        }

        $host    = 'email.' . $this->region . '.amazonaws.com';
        $service = 'ses';
        $method  = 'POST';
        $uri     = '/';

        // SES Query API SendRawEmail: the whole RFC 5322 message, base64 encoded.
        $raw = MimeMessage::build($msg);
        $params = [
            'Action'                  => 'SendRawEmail',
            'Source'                  => MimeMessage::stripCrlf((string)($msg['from'] ?? '')),
            'Destinations.member.1'   => MimeMessage::stripCrlf((string)($msg['to'] ?? '')),
            'RawMessage.Data'         => base64_encode($raw),
        ];

        ksort($params);
        $body = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        // SigV4
        $amzDate    = gmdate('Ymd\THis\Z');
        $dateStamp  = gmdate('Ymd');
        $payloadHash = hash('sha256', $body);

        $canonicalHeaders = "content-type:application/x-www-form-urlencoded; charset=utf-8\n"
                          . "host:{$host}\n"
                          . "x-amz-date:{$amzDate}\n";
        $signedHeaders    = 'content-type;host;x-amz-date';
        $canonicalRequest = "{$method}\n{$uri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

        $credentialScope  = "{$dateStamp}/{$this->region}/{$service}/aws4_request";
        $stringToSign     = "AWS4-HMAC-SHA256\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kDate    = hash_hmac('sha256', $dateStamp,     'AWS4' . $this->secretKey, true);
        $kRegion  = hash_hmac('sha256', $this->region,  $kDate,   true);
        $kService = hash_hmac('sha256', $service,       $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authHeader = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$credentialScope}, "
                    . "SignedHeaders={$signedHeaders}, Signature={$signature}";

        $requestHeaders = "Content-Type: application/x-www-form-urlencoded; charset=utf-8\r\n"
                        . "X-Amz-Date: {$amzDate}\r\n"
                        . "Authorization: {$authHeader}\r\n";
        if ($this->transport !== null) {
            $response = ($this->transport)("https://{$host}{$uri}", $requestHeaders, $body);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method'        => $method,
                    'header'        => $requestHeaders,
                    'content'       => $body,
                    'ignore_errors' => true,
                    'timeout'       => 15,
                ],
            ]);
            $response = @file_get_contents("https://{$host}{$uri}", false, $ctx);
        }
        $status   = 'failed';
        $message  = '';
        if ($response !== false) {
            if (preg_match('#<MessageId>(.+?)</MessageId>#', $response, $m)) {
                $status = 'sent';
                $message = $m[1];
            } elseif (preg_match('#<Message>(.+?)</Message>#', $response, $m)) {
                $message = $m[1];
            } else {
                $message = 'unexpected response';
            }
        } else {
            $message = 'transport failure';
        }

        return ['status' => $status, 'message' => $message, 'raw' => $response];
    }
}
