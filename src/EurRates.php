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
        self::$complete = [];
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
        // R42 (low 4): a feed only --force let through is stored as today's rates, not in the history: forced rates became
        // for good the week's references the next feeds (forced or not) were checked against, and the rate of their day.
        $forced = false;
        try {
            self::assertPlausible($db, $asOf, $rates, false);
        } catch (RuntimeException $e) {
            if (!$force) throw $e;
            self::assertPlausible($db, $asOf, $rates, true);
            $forced = true;
        }
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $db->exec('DELETE FROM eur_exchange_rates');
            // R44 (low 6): rates only --force let through are marked; rateOn() fixes no document in EUR at them.
            if (Db::hasColumn('eur_exchange_rates', 'forced', $db)) {
                $insert = $db->prepare('INSERT INTO eur_exchange_rates (currency, rate, as_of, forced) VALUES (?, ?, ?, ?)');
                foreach ($rates as $code => $rate) $insert->execute([$code, $rate, $asOf, $forced ? 1 : 0]);
            } else {
                $insert = $db->prepare('INSERT INTO eur_exchange_rates (currency, rate, as_of) VALUES (?, ?, ?)');
                foreach ($rates as $code => $rate) $insert->execute([$code, $rate, $asOf]);
            }
            // R44 (low 6): a day's first feed stays its history (the anchor daysBefore() bounds later feeds of that day by):
            // feeds fetched again the same day replaced it and could move the rate by MAX_CHANGE each.
            if (!$forced) self::remember($db, [$asOf => $rates], false);
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
            // R42 (low 4): from each of them — the stored, the week's history, the configured and the ANCHORS rate. Only the
            // first was checked: forced feeds chained CZK 0.041 -> 0.41 -> 4.03 -> 39.93 EUR, each 10 times the stored one.
            $anchors = array_merge(isset($stored[$code]) ? [$stored[$code]] : [], $before[$code] ?? [],
                isset($configured[$code]) ? [$configured[$code]] : [], isset(self::ANCHORS[$code]) ? [self::ANCHORS[$code]] : []);
            foreach ($anchors as $anchor) {
                if (max($rate / $anchor, $anchor / $rate) > self::FORCE_FACTOR) {
                    throw new RuntimeException("The ECB rate of {$code} ({$rate} EUR) is more than " . self::FORCE_FACTOR . " times away from {$anchor} EUR; it is not stored, not even with --force");
                }
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

    /**
     * @return array<string, list<float>> the history's rates (EUR per unit, by currency) of the ISSUE_WINDOW_DAYS before $asOf,
     * and of $asOf itself (R44: its first feed's)
     */
    private static function daysBefore(PDO $db, string $asOf): array
    {
        if (!Db::tableExists('eur_exchange_rate_history', $db)) return [];
        $from = gmdate('Y-m-d', (int)strtotime($asOf . ' 00:00:00 UTC') - self::ISSUE_WINDOW_DAYS * 86400);
        $stmt = $db->prepare('SELECT currency, rate FROM eur_exchange_rate_history WHERE as_of <= ? AND as_of >= ? ORDER BY as_of DESC');
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
        $code = strtoupper($currency);
        $from = gmdate('Y-m-d', (int)strtotime($date . ' 00:00:00 UTC') - self::ISSUE_WINDOW_DAYS * 86400);
        $best = null;
        foreach (['eur_exchange_rate_history', 'eur_exchange_rates'] as $table) {
            if (!Db::tableExists($table, $db)) continue;
            // R44 (low 6): never at a rate only --force stored (refresh()); the ECB history stands in for it.
            $unforced = $table === 'eur_exchange_rates' && Db::hasColumn($table, 'forced', $db) ? ' AND forced = 0' : '';
            $stmt = $db->prepare("SELECT rate, as_of FROM {$table} WHERE currency = ? AND as_of <= ? AND as_of >= ? AND rate > 0{$unforced} ORDER BY as_of DESC LIMIT 1");
            $stmt->execute([$code, $date, $from]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && is_numeric($row['rate']) && ($best === null || strcmp((string)$row['as_of'], $best['as_of']) > 0)) {
                $best = ['rate' => (float)$row['rate'], 'as_of' => (string)$row['as_of']];
            }
        }
        if ($best === null) return null;
        // R42 (low 4): the rate of the last day the ECB published on (publicationDay()), not just any of the week before:
        // with the cron stopped for five days a document was fixed at the rate of five days before, and daily() converted
        // a Sunday's document at Wednesday's rate before it fetched Thursday's and Friday's. An older one only where the
        // ECB history fetched in this run (fetchHistory()) shows the ECB published nothing in between.
        if (strcmp($best['as_of'], self::publicationDay($date)) >= 0 || self::published($code, $best['as_of'], $date)) return $best['rate'];
        return null;
    }

    /**
     * @var array<string, array{from: string, until: string, refused: array<string, true>}> by upper-case currency: the days
     * the ECB history fetched in this run covered (fetchHistory()), and the days of it whose rate was refused
     */
    private static array $complete = [];

    /** The ECB history fetched in this run shows every rate of $code it published after $asOf up to $date: none. */
    private static function published(string $code, string $asOf, string $date): bool
    {
        $covered = self::$complete[$code] ?? null;
        if ($covered === null || strcmp($asOf, $covered['from']) < 0 || strcmp($date, $covered['until']) > 0) return false;
        foreach ($covered['refused'] as $day => $_) {
            if (strcmp($day, $asOf) > 0 && strcmp($day, $date) <= 0) return false;
        }
        return true;
    }

    /**
     * R42: the last day on or before $date the ECB publishes reference rates: TARGET days, not Saturdays, Sundays, 1 January,
     * Good Friday, Easter Monday, 1 May, 25 and 26 December.
     */
    public static function publicationDay(string $date): string
    {
        $time = (int)strtotime($date . ' 00:00:00 UTC');
        for ($i = 0; $i < 10; $i++, $time -= 86400) {
            if ((int)gmdate('N', $time) >= 6) continue;
            $md = gmdate('m-d', $time);
            if (in_array($md, ['01-01', '05-01', '12-25', '12-26'], true)) continue;
            $easter = self::easterSunday((int)gmdate('Y', $time));
            if ($time === $easter - 2 * 86400 || $time === $easter + 86400) continue;
            return gmdate('Y-m-d', $time);
        }
        return $date;
    }

    /** UTC midnight of Easter Sunday of $year (Gregorian; anonymous algorithm, no calendar extension needed). */
    private static function easterSunday(int $year): int
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $h = (19 * $a + $b - intdiv($b, 4) - intdiv($b - intdiv($b + 8, 25) + 1, 3) + 15) % 30;
        $l = (32 + 2 * ($b % 4) + 2 * intdiv($c, 4) - $h - $c % 4) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = ($h + $l - 7 * $m + 114) % 31 + 1;
        return gmmktime(0, 0, 0, $month, $day, $year);
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
        // R42 (low 4): without LIBXML_PARSEHUGE (the whole history is about 8 MB of short nodes; libxml's limits stay on).
        $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if ($xml === false) throw new RuntimeException('The ECB rates history is not XML');
        $current = self::current()['rates'];
        $from = gmdate('Y-m-d', (int)strtotime($since . ' 00:00:00 UTC') - self::ISSUE_WINDOW_DAYS * 86400);
        $wanted = array_flip(array_map('strtoupper', $currencies));
        $days = [];
        $refused = [];
        $refusedDays = [];
        $latest = '';
        $earliest = '';
        $xml->registerXPathNamespace('e', 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref');
        foreach ($xml->xpath('//e:Cube[@time]') ?: [] as $day) {
            $asOf = (string)$day['time'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) || strcmp($asOf, $today) > 0) continue;
            $latest = max($latest, $asOf);
            $earliest = $earliest === '' ? $asOf : min($earliest, $asOf);
            if (strcmp($asOf, $from) < 0) continue;
            $day->registerXPathNamespace('e', 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref');
            foreach ($day->xpath('e:Cube[@currency]') ?: [] as $cube) {
                $code = strtoupper((string)$cube['currency']);
                $perEur = (float)(string)$cube['rate'];
                if (!isset($wanted[$code]) || $perEur <= 0) continue;
                $rate = 1 / $perEur;
                $reference = $current[strtolower($code)] ?? null;
                if ($reference === null || abs($rate / $reference - 1) > self::MAX_CHANGE) {
                    $refused[] = "{$code} {$asOf}";
                    $refusedDays[$code][$asOf] = true;
                    continue;
                }
                $days[$asOf][$code] = $rate;
            }
        }
        self::remember($db, $days);
        // R42: the days from $from to the feed's last one are all the ECB published (rateOn(): an older rate is the one).
        if ($latest !== '') {
            foreach (array_keys($wanted) as $code) {
                self::$complete[$code] = ['from' => max($from, $earliest), 'until' => $latest, 'refused' => $refusedDays[$code] ?? []];
            }
        }
        return ['days' => count($days), 'refused' => $refused];
    }

    /**
     * cli/eur-rates.php: store today's rates (refresh()), then convert documents waiting for the rate of their day
     * (OssThreshold::convertPending(), from the ECB history). Review round 15 (R41): a refused daily feed (a currency
     * missing, a move of more than 50 %) no longer skips the conversion; it reads the separate history feed. Exit code 1
     * when either step failed.
     * @return array{exit: int, out: list<string>, err: list<string>}
     */
    public static function daily(PDO $db, bool $force = false): array
    {
        $out = [];
        $err = [];
        try {
            $result = self::refresh($db, $force);
            $out[] = "ECB rates of {$result['as_of']}: {$result['count']} currencies stored";
        } catch (Throwable $e) {
            $err[] = 'ECB rates not updated: ' . $e->getMessage();
        }
        try {
            $converted = OssThreshold::convertPending($db);
            if ($converted['pending'] > 0) {
                $history = self::fetchHistory($db, (string)$converted['oldest'], $converted['currencies']);
                if ($history['refused'] !== []) $err[] = 'ECB history rates refused (more than 50 % from today\'s): ' . implode(', ', array_slice($history['refused'], 0, 10));
                $converted = ['converted' => $converted['converted'] + OssThreshold::convertPending($db)['converted']] + OssThreshold::pending($db);
            }
            $out[] = "OSS documents converted to EUR at the rate of their day: {$converted['converted']}; still counted at today's rate: {$converted['pending']}";
        } catch (Throwable $e) {
            $err[] = 'OSS documents not converted: ' . $e->getMessage();
        }
        $failed = array_filter($err, static fn(string $line): bool => !str_starts_with($line, 'ECB history rates refused'));
        return ['exit' => $failed === [] ? 0 : 1, 'out' => $out, 'err' => $err];
    }

    /**
     * @param array<string, array<string, float>> $days date => EUR per unit by upper-case currency
     * @param bool $replace false: a day's rate stored already stays (refresh(): the first feed of the day)
     */
    private static function remember(PDO $db, array $days, bool $replace = true): void
    {
        if ($days === [] || !Db::tableExists('eur_exchange_rate_history', $db)) return;
        $delete = $db->prepare('DELETE FROM eur_exchange_rate_history WHERE currency = ? AND as_of = ?');
        $exists = $db->prepare('SELECT 1 FROM eur_exchange_rate_history WHERE currency = ? AND as_of = ?');
        $insert = $db->prepare('INSERT INTO eur_exchange_rate_history (currency, as_of, rate) VALUES (?, ?, ?)');
        foreach ($days as $asOf => $rates) {
            foreach ($rates as $code => $rate) {
                if ($replace) {
                    $delete->execute([$code, $asOf]);
                } else {
                    $exists->execute([$code, $asOf]);
                    $found = $exists->fetchColumn();
                    $exists->closeCursor();
                    if ($found !== false) continue;
                }
                $insert->execute([$code, $asOf, $rate]);
            }
        }
    }

    private static function download(string $url, int $limit = 1048576): string
    {
        if (!function_exists('curl_init')) throw new RuntimeException('cURL extension is required to fetch the ECB rates');
        $ch = curl_init($url);
        // R42 (low 4): the size is bounded while it downloads (the transfer stops past $limit), not after the whole body.
        $body = '';
        $tooLarge = false;
        curl_setopt_array($ch, [CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'BareBonesEshop/1', CURLOPT_MAXFILESIZE => $limit,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooLarge, $limit): int {
                if (strlen($body) + strlen($chunk) > $limit) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            }]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if ($tooLarge) throw new RuntimeException("The ECB rates feed is larger than {$limit} bytes");
        if ($ok !== true || $status !== 200) throw new RuntimeException("The ECB rates feed answered HTTP {$status} {$error}");
        return $body;
    }
}
