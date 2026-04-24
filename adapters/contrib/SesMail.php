<?php
/**
 * SesMail — AWS SES SendEmail via HTTPS (SigV4 signed, no SDK required).
 *
 * Uses the classic v2 SES `SendEmail` HTTPS endpoint with AWS
 * Signature Version 4 auth (derived signing key). One POST per email.
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

    public function __construct(array $cfg = []) {
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

        // SES v1 Query API SendEmail — simple and avoids JSON body signing.
        $params = [
            'Action'                         => 'SendEmail',
            'Source'                         => (string)($msg['from'] ?? ''),
            'Destination.ToAddresses.member.1' => (string)($msg['to'] ?? ''),
            'Message.Subject.Data'           => (string)($msg['subject'] ?? ''),
            'Message.Subject.Charset'        => 'UTF-8',
        ];
        if (!empty($msg['html'])) {
            $params['Message.Body.Html.Data']    = (string)$msg['html'];
            $params['Message.Body.Html.Charset'] = 'UTF-8';
        }
        if (!empty($msg['text']) || empty($msg['html'])) {
            $params['Message.Body.Text.Data']    = (string)($msg['text'] ?? strip_tags($msg['html'] ?? ''));
            $params['Message.Body.Text.Charset'] = 'UTF-8';
        }
        if (!empty($msg['reply_to'])) {
            $params['ReplyToAddresses.member.1'] = $msg['reply_to'];
        }

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

        $ctx = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => "Content-Type: application/x-www-form-urlencoded; charset=utf-8\r\n"
                                 . "X-Amz-Date: {$amzDate}\r\n"
                                 . "Authorization: {$authHeader}\r\n",
                'content'       => $body,
                'ignore_errors' => true,
                'timeout'       => 15,
            ],
        ]);

        $response = @file_get_contents("https://{$host}{$uri}", false, $ctx);
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
