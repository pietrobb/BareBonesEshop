<?php
/**
 * R39 (low): the EUR rates the OSS threshold counts sales in other currencies with (OssThreshold). They were fixed
 * values of config/vat-rates.json the merchant had to copy by hand. cli/eur-rates.php (a daily cron line) now stores the
 * ECB reference rates (art. 91(2) VAT Directive: the latest rate the ECB published) in eur_exchange_rates; they replace
 * the configured ones when at least as recent. The configured rates stay the fallback (no network, no cron yet, a
 * currency the ECB does not publish).
 */
final class EurRates
{
    public const FEED = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    /** @var null|callable(string): string test transport: URL => response body */
    private static $fixtureTransport = null;
    /** @var null|list<array{currency: string, rate: string|float, as_of: string}> the stored rows, for this request */
    private static ?array $stored = null;

    public static function setFixtureTransport(?callable $transport): void
    {
        self::$fixtureTransport = $transport;
    }

    public static function reset(): void
    {
        self::$stored = null;
    }

    /** @return array{rates: array<string, float>, as_of: string} EUR per unit by lower-case currency, and their date */
    public static function current(): array
    {
        $config = VatService::config();
        $rates = [];
        foreach ((array)($config['eur_exchange_rates'] ?? []) as $code => $rate) {
            if (is_numeric($rate) && (float)$rate > 0) $rates[strtolower((string)$code)] = (float)$rate;
        }
        $asOf = (string)($config['eur_exchange_rates_as_of'] ?? '');
        if (self::$stored === null) {
            $db = Db::get();
            self::$stored = Db::tableExists('eur_exchange_rates', $db) ? $db->query('SELECT currency, rate, as_of FROM eur_exchange_rates')->fetchAll(PDO::FETCH_ASSOC) : [];
        }
        $storedAsOf = self::$stored === [] ? '' : (string)max(array_column(self::$stored, 'as_of'));
        if ($storedAsOf !== '' && strcmp($storedAsOf, $asOf) >= 0) {
            foreach (self::$stored as $row) $rates[strtolower((string)$row['currency'])] = (float)$row['rate'];
            $asOf = $storedAsOf;
        }
        return ['rates' => $rates, 'as_of' => $asOf];
    }

    /**
     * Fetch the ECB daily reference rates and store them (all at once). Returns their date and count.
     * @return array{as_of: string, count: int}
     */
    public static function refresh(PDO $db): array
    {
        $body = self::$fixtureTransport !== null ? (string)(self::$fixtureTransport)(self::FEED) : self::download(self::FEED);
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if ($xml === false) throw new RuntimeException('The ECB rates feed is not XML');
        $xml->registerXPathNamespace('e', 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref');
        $day = $xml->xpath('//e:Cube[@time]')[0] ?? null;
        $asOf = $day === null ? '' : (string)$day['time'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) throw new RuntimeException('The ECB rates feed has no date');
        $day->registerXPathNamespace('e', 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref'); // not inherited from $xml
        $rates = [];
        foreach ($day->xpath('e:Cube[@currency]') ?: [] as $cube) {
            $code = strtoupper((string)$cube['currency']);
            $perEur = (float)(string)$cube['rate'];
            if (preg_match('/^[A-Z]{3}$/', $code) && $perEur > 0) $rates[$code] = 1 / $perEur;
        }
        if ($rates === []) throw new RuntimeException('The ECB rates feed has no rates');
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $db->exec('DELETE FROM eur_exchange_rates');
            $insert = $db->prepare('INSERT INTO eur_exchange_rates (currency, rate, as_of) VALUES (?, ?, ?)');
            foreach ($rates as $code => $rate) $insert->execute([$code, $rate, $asOf]);
        } catch (Throwable $e) {
            if ($own) $db->rollBack();
            throw $e;
        }
        if ($own) $db->commit();
        OssThreshold::reset();
        return ['as_of' => $asOf, 'count' => count($rates)];
    }

    private static function download(string $url): string
    {
        if (!function_exists('curl_init')) throw new RuntimeException('cURL extension is required to fetch the ECB rates');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'BareBonesEshop/1']);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if (!is_string($body) || $status !== 200 || strlen($body) > 1048576) throw new RuntimeException("The ECB rates feed answered HTTP {$status} {$error}");
        return $body;
    }
}
