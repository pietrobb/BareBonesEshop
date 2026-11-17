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
        // R40 (low): rates dated after today (stored by an earlier version, or configured) no longer outrank the others for
        // good; the setup doctor reports them (futureDated()).
        $today = gmdate('Y-m-d', ShopTime::now());
        $storedAsOf = self::$stored === [] ? '' : (string)max(array_column(self::$stored, 'as_of'));
        if ($storedAsOf !== '' && strcmp($storedAsOf, $today) <= 0 && (strcmp($storedAsOf, $asOf) >= 0 || strcmp($asOf, $today) > 0)) {
            foreach (self::$stored as $row) $rates[strtolower((string)$row['currency'])] = (float)$row['rate'];
            $asOf = $storedAsOf;
        }
        return ['rates' => $rates, 'as_of' => $asOf];
    }

    /** R40: the date of stored or configured rates that is after today (they are not trusted), or null. */
    public static function futureDated(): ?string
    {
        self::current();
        $dates = array_merge(array_column(self::$stored ?? [], 'as_of'), [(string)(VatService::config()['eur_exchange_rates_as_of'] ?? '')]);
        $latest = (string)max($dates);
        return strcmp($latest, gmdate('Y-m-d', ShopTime::now())) > 0 ? $latest : null;
    }

    /** R40: a first feed (nothing stored yet) names at least this many currencies; the ECB publishes about 30. */
    public const MIN_CURRENCIES = 20;
    /** R40: a rate more than 50 % away from the one it replaces is refused unless the merchant forces it. */
    public const MAX_CHANGE = 0.5;

    /**
     * Fetch the ECB daily reference rates and store them (all at once). Returns their date and count.
     * R40 (low): the feed was trusted as it came: a rate of 1e-9 inflated the threshold (an OSS activation that stays), a
     * date of 2099-12-31 outranked the configured rates for good, and a feed of one currency deleted all others. Refused
     * now, the stored rates kept: a date after today or before the stored one, a currency stored before missing (or fewer
     * than MIN_CURRENCIES at first), a rate more than MAX_CHANGE away from the stored or configured one. $force (checked
     * by the merchant: php cli/eur-rates.php --force) accepts a missing currency and a large change, never a future date.
     * R41 (low): a first feed's currencies the configuration lacks are checked too (ANCHORS), a rate is bounded by the week
     * before its day as well (feeds fetched again the same day moved it by 50 % each), and --force refuses a rate outside
     * MIN_RATE..MAX_RATE or FORCE_FACTOR times away from what it was.
     * @return array{as_of: string, count: int}
     */
    public static function refresh(PDO $db, bool $force = false): array
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
        self::assertPlausible($db, $asOf, $rates, $force);
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $db->exec('DELETE FROM eur_exchange_rates');
            $insert = $db->prepare('INSERT INTO eur_exchange_rates (currency, rate, as_of) VALUES (?, ?, ?)');
            foreach ($rates as $code => $rate) $insert->execute([$code, $rate, $asOf]);
            self::remember($db, [$asOf => $rates]);
        } catch (Throwable $e) {
            if ($own) $db->rollBack();
            throw $e;
        }
        if ($own) $db->commit();
        OssThreshold::reset();
        return ['as_of' => $asOf, 'count' => count($rates)];
    }

    /** @param array<string, float> $rates EUR per unit by upper-case currency */
    private static function assertPlausible(PDO $db, string $asOf, array $rates, bool $force): void
    {
        $today = gmdate('Y-m-d', ShopTime::now());
        if (strcmp($asOf, $today) > 0) throw new RuntimeException("The ECB rates feed is dated {$asOf}, after today ({$today})");
        $stored = [];
        $storedAsOf = '';
        foreach ($db->query('SELECT currency, rate, as_of FROM eur_exchange_rates')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (strcmp((string)$row['as_of'], $today) > 0) continue; // dated after today: not trusted (current()), replaced
            $stored[strtoupper((string)$row['currency'])] = (float)$row['rate'];
            $storedAsOf = max($storedAsOf, (string)$row['as_of']);
        }
        if (strcmp($asOf, $storedAsOf) < 0) throw new RuntimeException("The ECB rates feed is dated {$asOf}, before the stored rates ({$storedAsOf})");
        $configured = [];
        foreach ((array)(VatService::config()['eur_exchange_rates'] ?? []) as $code => $rate) {
            if (is_numeric($rate) && (float)$rate > 0) $configured[strtoupper((string)$code)] = (float)$rate;
        }
        $before = self::daysBefore($db, $asOf);
        foreach ($rates as $code => $rate) {
            // R41 (low): no rate, even forced, outside what any currency is worth, nor FORCE_FACTOR away from what it was.
            if ($rate < self::MIN_RATE || $rate > self::MAX_RATE) throw new RuntimeException("The ECB rate of {$code} ({$rate} EUR) is not a plausible rate");
            $anchor = $stored[$code] ?? $before[$code][0] ?? $configured[$code] ?? self::ANCHORS[$code] ?? null;
            if ($anchor !== null && max($rate / $anchor, $anchor / $rate) > self::FORCE_FACTOR) {
                throw new RuntimeException("The ECB rate of {$code} ({$rate} EUR) is more than " . self::FORCE_FACTOR . " times away from {$anchor} EUR; it is not stored, not even with --force");
            }
        }
        if ($force) return;
        $missing = array_diff(array_keys($stored), array_keys($rates));
        if ($missing !== []) throw new RuntimeException('The ECB rates feed lacks ' . implode(', ', $missing) . '; check it and run php cli/eur-rates.php --force to store it anyway');
        if ($stored === [] && count($rates) < self::MIN_CURRENCIES) throw new RuntimeException('The ECB rates feed has only ' . count($rates) . ' currencies');
        foreach ($rates as $code => $rate) {
            // R41 (low): every rate the ECB published in the week before this feed's day bounds it too, so feeds fetched
            // again the same day cannot move a rate by MAX_CHANGE each; the stored one may be such a feed's.
            $references = array_merge(isset($stored[$code]) ? [$stored[$code]] : [], $before[$code] ?? []);
            if ($references === [] && isset($configured[$code])) $references = [$configured[$code]];
            foreach ($references as $reference) {
                if (abs($rate / $reference - 1) > self::MAX_CHANGE) {
                    throw new RuntimeException("The ECB rate of {$code} ({$rate} EUR) is more than 50 % away from {$reference} EUR it had; check it and run php cli/eur-rates.php --force to store it anyway");
                }
            }
            // R41 (low): a first feed's currency the configuration lacks is checked against ANCHORS (wider: they age).
            if ($references === [] && isset(self::ANCHORS[$code]) && max($rate / self::ANCHORS[$code], self::ANCHORS[$code] / $rate) > self::ANCHOR_FACTOR) {
                throw new RuntimeException("The ECB rate of {$code} ({$rate} EUR) is more than " . self::ANCHOR_FACTOR . ' times away from about ' . self::ANCHORS[$code] . ' EUR; check it and run php cli/eur-rates.php --force to store it anyway');
            }
        }
    }

    /** @return array<string, list<float>> the history's rates (EUR per unit, by currency) of the ISSUE_WINDOW_DAYS before $asOf */
    private static function daysBefore(PDO $db, string $asOf): array
    {
        if (!Db::tableExists('eur_exchange_rate_history', $db)) return [];
        $from = gmdate('Y-m-d', (int)strtotime($asOf . ' 00:00:00 UTC') - self::ISSUE_WINDOW_DAYS * 86400);
        $stmt = $db->prepare('SELECT currency, rate FROM eur_exchange_rate_history WHERE as_of < ? AND as_of >= ? ORDER BY as_of DESC');
        $stmt->execute([$asOf, $from]);
        $before = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((float)$row['rate'] > 0) $before[strtoupper((string)$row['currency'])][] = (float)$row['rate'];
        }
        return $before;
    }

    /** R41 (low): EUR per unit no currency is outside of (the ECB's range is about 0.00005 IDR to 1.2 GBP). */
    public const MIN_RATE = 1e-6;
    public const MAX_RATE = 1e3;
    /** R41 (low): --force accepts a move up to this factor, never more (a decimal point lost, a rate inverted). */
    public const FORCE_FACTOR = 10;
    /** R41 (low): a first feed's rate may be this factor away from ANCHORS (approximate, they age). */
    public const ANCHOR_FACTOR = 3;
    /** R41 (low): approximate EUR per unit (2026) of the currencies the ECB publishes, for a first feed's unconfigured ones. */
    public const ANCHORS = [
        'USD' => 0.86, 'JPY' => 0.0058, 'BGN' => 0.511, 'CZK' => 0.041, 'DKK' => 0.134, 'GBP' => 1.15, 'HUF' => 0.0026, 'PLN' => 0.235,
        'RON' => 0.197, 'SEK' => 0.091, 'CHF' => 1.07, 'ISK' => 0.0070, 'NOK' => 0.085, 'TRY' => 0.021, 'AUD' => 0.56, 'BRL' => 0.16,
        'CAD' => 0.62, 'CNY' => 0.12, 'HKD' => 0.11, 'IDR' => 0.000052, 'ILS' => 0.26, 'INR' => 0.0098, 'KRW' => 0.00060, 'MXN' => 0.047,
        'MYR' => 0.205, 'NZD' => 0.49, 'PHP' => 0.0148, 'SGD' => 0.66, 'THB' => 0.026, 'ZAR' => 0.049,
    ];

    /** R41: the ECB's last 90 days, and its whole history (since 1999, several MB) for documents issued before them. */
    public const HISTORY_90D = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml';
    public const HISTORY_ALL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist.xml';
    /**
     * R41 (medium 3): a document counts in EUR at the ECB rate of its day (art. 91(2): the latest one published then) — a
     * rate dated at most this many days before it (weekends and TARGET holidays have none). Nothing older, and never a
     * configured rate, is fixed into invoices.oss_net_eur.
     */
    public const ISSUE_WINDOW_DAYS = 7;

    /**
     * R41 (medium 3): EUR per unit of $currency the ECB published on $date or within ISSUE_WINDOW_DAYS before (the
     * history cli/eur-rates.php keeps, or the stored daily rates), else null.
     */
    public static function rateOn(PDO $db, string $currency, string $date): ?float
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
        $from = gmdate('Y-m-d', (int)strtotime($date . ' 00:00:00 UTC') - self::ISSUE_WINDOW_DAYS * 86400);
        foreach (['eur_exchange_rate_history', 'eur_exchange_rates'] as $table) {
            if (!Db::tableExists($table, $db)) continue;
            $stmt = $db->prepare("SELECT rate FROM {$table} WHERE currency = ? AND as_of <= ? AND as_of >= ? ORDER BY as_of DESC LIMIT 1");
            $stmt->execute([strtoupper($currency), $date, $from]);
            $rate = $stmt->fetchColumn();
            if (is_numeric($rate) && (float)$rate > 0) return (float)$rate;
        }
        return null;
    }

    /**
     * R41 (medium 3): store the ECB's rates of the days since $since (YYYY-MM-DD) of $currencies (upper case) in the history
     * rateOn() reads: the 90-day feed, or the whole history when $since is older. A rate more than MAX_CHANGE away from the
     * current one is not stored (its documents stay counted at the current rate). Returns [days stored, rates refused].
     * @param list<string> $currencies
     * @return array{days: int, refused: list<string>}
     */
    public static function fetchHistory(PDO $db, string $since, array $currencies): array
    {
        $today = gmdate('Y-m-d', ShopTime::now());
        $url = strcmp($since, gmdate('Y-m-d', ShopTime::now() - 85 * 86400)) >= 0 ? self::HISTORY_90D : self::HISTORY_ALL;
        $body = self::$fixtureTransport !== null ? (string)(self::$fixtureTransport)($url) : self::download($url, 32 * 1048576);
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_PARSEHUGE);
        libxml_use_internal_errors($previous);
        if ($xml === false) throw new RuntimeException('The ECB rates history is not XML');
        $current = self::current()['rates'];
        $from = gmdate('Y-m-d', (int)strtotime($since . ' 00:00:00 UTC') - self::ISSUE_WINDOW_DAYS * 86400);
        $wanted = array_flip(array_map('strtoupper', $currencies));
        $days = [];
        $refused = [];
        $xml->registerXPathNamespace('e', 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref');
        foreach ($xml->xpath('//e:Cube[@time]') ?: [] as $day) {
            $asOf = (string)$day['time'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) || strcmp($asOf, $from) < 0 || strcmp($asOf, $today) > 0) continue;
            $day->registerXPathNamespace('e', 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref');
            foreach ($day->xpath('e:Cube[@currency]') ?: [] as $cube) {
                $code = strtoupper((string)$cube['currency']);
                $perEur = (float)(string)$cube['rate'];
                if (!isset($wanted[$code]) || $perEur <= 0) continue;
                $rate = 1 / $perEur;
                $reference = $current[strtolower($code)] ?? null;
                if ($reference === null || abs($rate / $reference - 1) > self::MAX_CHANGE) {
                    $refused[] = "{$code} {$asOf}";
                    continue;
                }
                $days[$asOf][$code] = $rate;
            }
        }
        self::remember($db, $days);
        return ['days' => count($days), 'refused' => $refused];
    }

    /** @param array<string, array<string, float>> $days date => EUR per unit by upper-case currency */
    private static function remember(PDO $db, array $days): void
    {
        if ($days === [] || !Db::tableExists('eur_exchange_rate_history', $db)) return;
        $delete = $db->prepare('DELETE FROM eur_exchange_rate_history WHERE currency = ? AND as_of = ?');
        $insert = $db->prepare('INSERT INTO eur_exchange_rate_history (currency, as_of, rate) VALUES (?, ?, ?)');
        foreach ($days as $asOf => $rates) {
            foreach ($rates as $code => $rate) {
                $delete->execute([$code, $asOf]);
                $insert->execute([$code, $asOf, $rate]);
            }
        }
    }

    private static function download(string $url, int $limit = 1048576): string
    {
        if (!function_exists('curl_init')) throw new RuntimeException('cURL extension is required to fetch the ECB rates');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'BareBonesEshop/1']);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if (!is_string($body) || $status !== 200 || strlen($body) > $limit) throw new RuntimeException("The ECB rates feed answered HTTP {$status} {$error}");
        return $body;
    }
}
