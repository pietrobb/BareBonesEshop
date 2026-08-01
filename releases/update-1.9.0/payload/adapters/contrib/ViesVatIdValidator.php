<?php
/**
 * ViesVatIdValidator — calls EU VIES service via plain SOAP-over-HTTP POST.
 *
 * Endpoint: https://ec.europa.eu/taxation_customs/vies/services/checkVatService
 * Timeout : 5 s (graceful fallback to status='unverified' on failure).
 *
 * Designed for production. Tests should use MockVatIdValidator.
 */
final class ViesVatIdValidator implements VatIdValidator
{
    private const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/services/checkVatService';
    private const TIMEOUT_SEC = 5;

    public function check(string $vatId): array
    {
        $vatId = strtoupper(preg_replace('/\s+/', '', $vatId));
        if (!preg_match('/^([A-Z]{2})([0-9A-Z]{6,14})$/', $vatId, $m)) {
            return $this->result('invalid', null, null, null, null);
        }
        [$_, $country, $number] = $m;

        $body = $this->buildSoapEnvelope($country, $number);
        $ch   = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: ""',
            ],
            CURLOPT_POSTFIELDS     => $body,
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($raw === false || $err !== '' || $code < 200 || $code >= 300) {
            return $this->result('unverified', $country, null, null, $err ?: $raw);
        }

        $isValid = (bool)preg_match('#<valid[^>]*>true</valid>#', (string)$raw);
        $name    = $this->soapField((string)$raw, 'name');
        $addr    = $this->soapField((string)$raw, 'address');

        return $this->result($isValid ? 'valid' : 'invalid', $country, $name, $addr, (string)$raw);
    }

    private function buildSoapEnvelope(string $country, string $number): string
    {
        $countrySafe = htmlspecialchars($country, ENT_XML1, 'UTF-8');
        $numberSafe  = htmlspecialchars($number,  ENT_XML1, 'UTF-8');
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:urn="urn:ec.europa.eu:taxud:vies:services:checkVat:types">
  <soapenv:Body>
    <urn:checkVat>
      <urn:countryCode>{$countrySafe}</urn:countryCode>
      <urn:vatNumber>{$numberSafe}</urn:vatNumber>
    </urn:checkVat>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function soapField(string $xml, string $tag): ?string
    {
        if (preg_match('#<' . preg_quote($tag, '#') . '[^>]*>(.*?)</' . preg_quote($tag, '#') . '>#s', $xml, $m)) {
            $val = trim(html_entity_decode($m[1], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            return $val !== '---' && $val !== '' ? $val : null;
        }
        return null;
    }

    private function result(string $status, ?string $country, ?string $name, ?string $addr, ?string $raw): array
    {
        return [
            'status'      => $status,
            'country'     => $country,
            'trader_name' => $name,
            'trader_addr' => $addr,
            'raw'         => $raw,
        ];
    }
}
